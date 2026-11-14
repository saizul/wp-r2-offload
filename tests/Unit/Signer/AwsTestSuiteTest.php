<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\Signer;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\Http\Request;
use Saizul\R2Offload\Signer\Credentials;
use Saizul\R2Offload\Signer\SignatureV4;

/**
 * Runs every case of the AWS SigV4 test suite (header signing) shipped in
 * tests/Fixtures/aws-sigv4: canonical request, string to sign and signature
 * must match byte for byte.
 */
final class AwsTestSuiteTest extends TestCase {

	private const DIR = __DIR__ . '/../../Fixtures/aws-sigv4';

	/**
	 * @return array<string,array{0:string}>
	 */
	public function provide_cases(): array {
		$cases = array();
		foreach ( (array) glob( self::DIR . '/*', GLOB_ONLYDIR ) as $dir ) {
			$cases[ basename( (string) $dir ) ] = array( (string) $dir );
		}
		ksort( $cases );

		return $cases;
	}

	public function test_suite_is_present(): void {
		self::assertGreaterThanOrEqual( 30, count( $this->provide_cases() ) );
	}

	/**
	 * @dataProvider provide_cases
	 */
	public function test_case( string $dir ): void {
		$context = json_decode( (string) file_get_contents( $dir . '/context.json' ), true );
		$request = self::parse_request( (string) file_get_contents( $dir . '/request.txt' ) );
		$time    = new DateTimeImmutable( $context['timestamp'] );
		$signer  = new SignatureV4(
			new Credentials(
				$context['credentials']['access_key_id'],
				$context['credentials']['secret_access_key'],
				$context['credentials']['token'] ?? ''
			),
			$context['region'],
			$context['service']
		);
		$options = array(
			'normalize_path'        => $context['normalize'],
			'content_sha256_header' => $context['sign_body'],
			'sign_session_token'    => empty( $context['omit_session_token'] ),
		);

		$signed  = $signer->sign( $request, $time, $options );
		$payload = '' === $request->body() ? SignatureV4::EMPTY_SHA256 : hash( 'sha256', $request->body() );

		$canonical = $signer->canonical_request(
			$options['sign_session_token'] ? $signed->without_header( 'Authorization' ) : $signed->without_header( 'Authorization' )->without_header( 'X-Amz-Security-Token' ),
			$payload,
			$options
		);
		self::assertSame( self::read( $dir . '/header-canonical-request.txt' ), $canonical, 'canonical request' );
		self::assertSame( self::read( $dir . '/header-string-to-sign.txt' ), $signer->string_to_sign( $time, $canonical ), 'string to sign' );

		$signature = self::read( $dir . '/header-signature.txt' );
		self::assertStringEndsWith( 'Signature=' . $signature, (string) $signed->header( 'Authorization' ) );
		self::assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=', (string) $signed->header( 'Authorization' ) );
		self::assertSame( '20150830T123600Z', $signed->header( 'X-Amz-Date' ) );
	}

	private static function read( string $file ): string {
		return rtrim( (string) file_get_contents( $file ), "\n" );
	}

	/**
	 * Parse request.txt: request line, "Name:value" headers (continuation
	 * lines start with whitespace), blank line, body.
	 */
	private static function parse_request( string $raw ): Request {
		$split = strpos( $raw, "\n\n" );
		$head  = false === $split ? rtrim( $raw, "\n" ) : substr( $raw, 0, $split );
		$body  = false === $split ? '' : (string) substr( $raw, $split + 2 );
		$lines = explode( "\n", $head );

		$line = (string) array_shift( $lines );
		preg_match( '#^(\S+) (.*) HTTP/1\.1$#', $line, $m );
		$method = $m[1];
		$target = $m[2];

		$headers = array();
		$last    = null;
		foreach ( $lines as $header ) {
			if ( null !== $last && preg_match( '/^\s+/', $header ) ) {
				$index                       = count( $headers[ $last ] ) - 1;
				$headers[ $last ][ $index ] .= ' ' . trim( $header );
				continue;
			}
			$pos                = (int) strpos( $header, ':' );
			$last               = substr( $header, 0, $pos );
			$headers[ $last ][] = substr( $header, $pos + 1 );
		}

		// The suite gives raw (unencoded) paths and queries; encode them as a client would send them.
		$query = '';
		$path  = $target;
		$q     = strpos( $target, '?' );
		if ( false !== $q ) {
			$path  = substr( $target, 0, $q );
			$query = substr( $target, $q + 1 );
		}
		$path = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
		if ( '' !== $query ) {
			$query = implode(
				'&',
				array_map(
					static function ( string $pair ): string {
						$parts = explode( '=', $pair, 2 );
						return rawurlencode( rawurldecode( $parts[0] ) ) . ( isset( $parts[1] ) ? '=' . rawurlencode( rawurldecode( $parts[1] ) ) : '' );
					},
					explode( '&', $query )
				)
			);
		}
		$host = trim( $headers['Host'][0] );

		return new Request( $method, 'https://' . $host . $path . ( '' === $query ? '' : '?' . $query ), $headers, $body );
	}
}
