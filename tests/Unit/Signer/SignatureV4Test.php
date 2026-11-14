<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\Signer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\Http\Request;
use Saizul\R2Offload\Signer\Credentials;
use Saizul\R2Offload\Signer\SignatureV4;

final class SignatureV4Test extends TestCase {

	private function signer( string $token = '' ): SignatureV4 {
		return new SignatureV4( new Credentials( 'AKID', 'SECRET', $token ), 'auto', 's3' );
	}

	/**
	 * @return array<string,array{0:string,1:bool,2:string}>
	 */
	public function provide_uris(): array {
		return array(
			'empty'               => array( '', false, '/' ),
			'root'                => array( '/', true, '/' ),
			'encoded key'         => array( '/media/2026/11/a%20b%2Bc.jpg', false, '/media/2026/11/a%20b%2Bc.jpg' ),
			'raw unicode'         => array( '/media/ছবি.jpg', false, '/media/%E0%A6%9B%E0%A6%AC%E0%A6%BF.jpg' ),
			'reserved characters' => array( "/b/k=v&x;y,z:'!()*", false, '/b/k%3Dv%26x%3By%2Cz%3A%27%21%28%29%2A' ),
			'tilde is unreserved' => array( '/b/~user', false, '/b/~user' ),
			'S3 keeps dots'       => array( '/b/./x/../y', false, '/b/./x/../y' ),
			'S3 keeps slashes'    => array( '/b//double', false, '/b//double' ),
			'normalised'          => array( '/a/./b/../c//d/', true, '/a/c/d/' ),
			'lower-case escapes'  => array( '/b/%e2%82%ac', false, '/b/%E2%82%AC' ),
		);
	}

	/**
	 * @dataProvider provide_uris
	 */
	public function test_canonical_uri( string $path, bool $normalize, string $expected ): void {
		self::assertSame( $expected, SignatureV4::canonical_uri( $path, $normalize ) );
	}

	public function test_canonical_query(): void {
		self::assertSame( '', SignatureV4::canonical_query( '' ) );
		self::assertSame( 'a=1&a=2&b=', SignatureV4::canonical_query( 'b&a=2&a=1' ) );
		self::assertSame( 'partNumber=1&uploadId=a%2Fb%2Bc', SignatureV4::canonical_query( 'uploadId=a%2Fb%2Bc&partNumber=1' ) );
		self::assertSame( 'k=a%20b', SignatureV4::canonical_query( 'k=a%20b' ) );
		self::assertSame( 'k=a%2Bb', SignatureV4::canonical_query( 'k=a+b' ), '"+" is a literal plus, not a space.' );
		self::assertSame( 'delete=', SignatureV4::canonical_query( 'delete=' ) );
	}

	public function test_split_url_and_host(): void {
		$parts = SignatureV4::split_url( 'https://ACC.r2.cloudflarestorage.com:8443/bucket/a%20b?x=1#frag' );

		self::assertSame( 'https', $parts['scheme'] );
		self::assertSame( 'acc.r2.cloudflarestorage.com', $parts['host'] );
		self::assertSame( 8443, $parts['port'] );
		self::assertSame( '/bucket/a%20b', $parts['path'] );
		self::assertSame( 'x=1', $parts['query'] );
		self::assertSame( 'acc.r2.cloudflarestorage.com:8443', SignatureV4::host_header( 'https://acc.r2.cloudflarestorage.com:8443/x' ) );
		self::assertSame( 'example.com', SignatureV4::host_header( 'https://example.com:443/x' ) );
		self::assertSame( 'example.com', SignatureV4::host_header( 'http://example.com:80' ) );
		self::assertSame( '/', SignatureV4::split_url( 'https://example.com' )['path'] );
	}

	public function test_sign_adds_headers_and_replaces_old_signature(): void {
		$time    = new DateTimeImmutable( '2026-11-14 18:30:00', new DateTimeZone( 'Asia/Dhaka' ) );
		$request = new Request( 'PUT', 'https://acc.r2.cloudflarestorage.com/media/a.txt', array( 'Authorization' => 'old', 'User-Agent' => 'ua' ), 'hello' );
		$signed  = $this->signer()->sign( $request, $time, array( 'content_sha256_header' => true, 'normalize_path' => false ) );

		self::assertSame( '20261114T123000Z', $signed->header( 'x-amz-date' ), 'Signing time is converted to UTC.' );
		self::assertSame( 'acc.r2.cloudflarestorage.com', $signed->header( 'host' ) );
		self::assertSame( hash( 'sha256', 'hello' ), $signed->header( 'x-amz-content-sha256' ) );
		self::assertStringContainsString( 'Credential=AKID/20261114/auto/s3/aws4_request', (string) $signed->header( 'authorization' ) );
		self::assertStringContainsString( 'SignedHeaders=host;x-amz-content-sha256;x-amz-date,', (string) $signed->header( 'authorization' ), 'User-Agent is not signed.' );

		// Signing twice gives the same result.
		self::assertSame( $signed->header( 'authorization' ), $this->signer()->sign( $signed, $time, array( 'content_sha256_header' => true, 'normalize_path' => false ) )->header( 'authorization' ) );
	}

	public function test_unsigned_payload_and_session_token(): void {
		$time   = new DateTimeImmutable( '2026-01-01T00:00:00Z' );
		$signed = $this->signer( 'TOKEN' )->sign(
			new Request( 'GET', 'https://h.example/b/k' ),
			$time,
			array(
				'content_sha256_header' => true,
				'payload_hash'          => SignatureV4::UNSIGNED_PAYLOAD,
			)
		);

		self::assertSame( 'UNSIGNED-PAYLOAD', $signed->header( 'x-amz-content-sha256' ) );
		self::assertSame( 'TOKEN', $signed->header( 'x-amz-security-token' ) );
		self::assertStringContainsString( 'x-amz-security-token', (string) $signed->header( 'authorization' ) );
	}

	public function test_presign_validates_lifetime(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->signer()->presign( 'GET', 'https://h.example/b/k', 604801, new DateTimeImmutable() );
	}

	public function test_presign_with_token(): void {
		$url = $this->signer( 'T/1' )->presign( 'GET', 'https://h.example/b/k?versionId=2', 60, new DateTimeImmutable( '2026-01-01T00:00:00Z' ) );

		self::assertStringStartsWith( 'https://h.example/b/k?versionId=2&X-Amz-Algorithm=AWS4-HMAC-SHA256&', $url );
		self::assertStringContainsString( '&X-Amz-Security-Token=T%2F1&X-Amz-Signature=', $url );
	}

	public function test_signing_key_and_scope(): void {
		$signer = $this->signer();
		$time   = new DateTimeImmutable( '2026-01-01T23:59:59Z' );

		self::assertSame( 32, strlen( $signer->signing_key( $time ) ) );
		self::assertSame( $signer->signing_key( $time ), $signer->signing_key( new DateTimeImmutable( '2026-01-01T00:00:00Z' ) ) );
		self::assertNotSame( $signer->signing_key( $time ), $signer->signing_key( new DateTimeImmutable( '2026-01-02T00:00:00Z' ) ) );
		self::assertSame( '20260101/auto/s3/aws4_request', $signer->scope( $time ) );
	}

	public function test_requires_region_and_credentials(): void {
		$this->expectException( InvalidArgumentException::class );
		new SignatureV4( new Credentials( 'a', 'b' ), '', 's3' );
	}

	public function test_credentials(): void {
		$credentials = new Credentials( ' AKID ', ' SECRET ', 'TOKEN' );

		self::assertSame( 'AKID', $credentials->access_key_id() );
		self::assertStringNotContainsString( 'SECRET', print_r( $credentials, true ) ); // phpcs:ignore
		self::assertStringNotContainsString( 'TOKEN', print_r( $credentials, true ) ); // phpcs:ignore

		$this->expectException( InvalidArgumentException::class );
		new Credentials( 'AKID', '' );
	}

	public function test_request_headers_are_case_insensitive(): void {
		$request = new Request( 'get', 'https://h.example/', array( 'X-Multi' => array( 'a', 'b' ) ) );

		self::assertSame( 'GET', $request->method() );
		self::assertSame( 'a,b', $request->header( 'x-multi' ) );
		self::assertNull( $request->without_header( 'X-MULTI' )->header( 'x-multi' ) );
		self::assertSame( array( 'X-Multi' => 'a,b' ), $request->headers() );
	}
}
