<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\S3;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\Http\FakeHttpClient;
use Saizul\R2Offload\Http\Request;
use Saizul\R2Offload\S3\R2Endpoint;
use Saizul\R2Offload\S3\S3Client;
use Saizul\R2Offload\S3\S3Exception;
use Saizul\R2Offload\Signer\Credentials;
use Saizul\R2Offload\Signer\SignatureV4;

final class S3ClientTest extends TestCase {

	private const BASE = 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com/media/';

	/** @var FakeHttpClient */
	private $http;

	/** @var int[] */
	private $sleeps = array();

	protected function setUp(): void {
		$this->http   = new FakeHttpClient();
		$this->sleeps = array();
	}

	private function client( int $attempts = 3 ): S3Client {
		return new S3Client(
			$this->http,
			new R2Endpoint( '0123456789abcdef0123456789abcdef', 'media' ),
			new SignatureV4( new Credentials( 'AKID', 'SECRET' ), 'auto', 's3' ),
			static function (): DateTimeImmutable {
				return new DateTimeImmutable( '2026-11-14T10:00:00Z' );
			},
			$attempts,
			function ( int $microseconds ): void {
				$this->sleeps[] = $microseconds;
			}
		);
	}

	private function assert_signed( Request $request, string $body ): void {
		self::assertSame( '20261114T100000Z', $request->header( 'x-amz-date' ) );
		self::assertSame( hash( 'sha256', $body ), $request->header( 'x-amz-content-sha256' ) );
		self::assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=AKID/20261114/auto/s3/aws4_request, SignedHeaders=', (string) $request->header( 'authorization' ) );
		self::assertNull( $request->header( 'x-amz-acl' ), 'R2 does not implement ACL headers.' );
	}

	public function test_put_object(): void {
		$this->http->queue( 200, '', array( 'ETag' => '"5d41402abc4b2a76b9719d911017c592"' ) );
		$etag    = $this->client()->put_object( 'uploads/2026/11/a b.jpg', 'hello', 'image/jpeg', 'public, max-age=60', array( 'x-amz-meta-source' => 'wp' ) );
		$request = $this->http->last();

		self::assertSame( '5d41402abc4b2a76b9719d911017c592', $etag );
		self::assertSame( 'PUT', $request->method() );
		self::assertSame( self::BASE . 'uploads/2026/11/a%20b.jpg', $request->url() );
		self::assertSame( 'image/jpeg', $request->header( 'content-type' ) );
		self::assertSame( 'public, max-age=60', $request->header( 'cache-control' ) );
		self::assertSame( base64_encode( md5( 'hello', true ) ), $request->header( 'content-md5' ) );
		self::assertSame( 'wp', $request->header( 'x-amz-meta-source' ) );
		self::assertSame( 'hello', $request->body() );
		self::assertNull( $request->header( 'content-length' ), 'Content-Length is left to the transport.' );
		$this->assert_signed( $request, 'hello' );
		self::assertStringContainsString( 'SignedHeaders=cache-control;content-md5;content-type;host;x-amz-content-sha256;x-amz-date;x-amz-meta-source,', (string) $request->header( 'authorization' ) );
	}

	public function test_head_object(): void {
		$this->http->queue(
			200,
			'',
			array(
				'Content-Length' => '1234',
				'ETag'           => '"abc"',
				'Content-Type'   => 'image/png',
				'Last-Modified'  => 'Sat, 14 Nov 2026 10:00:00 GMT',
			)
		)->queue( 404 );

		$meta = $this->client()->head_object( 'a.png' );
		self::assertSame( 1234, $meta->size );
		self::assertSame( 'abc', $meta->etag );
		self::assertSame( 'image/png', $meta->content_type );
		self::assertSame( 'HEAD', $this->http->last()->method() );
		$this->assert_signed( $this->http->last(), '' );

		self::assertNull( $this->client()->head_object( 'missing.png' ) );
	}

	public function test_delete_object_ignores_missing(): void {
		$this->http->queue( 204 )->queue( 404 );
		$this->client()->delete_object( 'a.png' );
		$this->client()->delete_object( 'a.png' );

		self::assertSame( 'DELETE', $this->http->last()->method() );
		self::assertCount( 2, $this->http->requests );
	}

	public function test_delete_objects_batches_and_reports_errors(): void {
		$this->http->queue( 200, '<?xml version="1.0"?><DeleteResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Error><Key>b&amp;c.png</Key><Code>AccessDenied</Code><Message>Access Denied</Message></Error></DeleteResult>' );
		$failed  = $this->client()->delete_objects( array( 'a.png', 'b&c.png', 'a.png' ) );
		$request = $this->http->last();

		self::assertSame( array( 'b&c.png' => 'AccessDenied Access Denied' ), $failed );
		self::assertSame( 'POST', $request->method() );
		self::assertSame( self::BASE . '?delete=', $request->url() );
		self::assertSame( '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet><Object><Key>a.png</Key></Object><Object><Key>b&amp;c.png</Key></Object></Delete>', $request->body() );
		self::assertSame( base64_encode( md5( $request->body(), true ) ), $request->header( 'content-md5' ) );
		$this->assert_signed( $request, $request->body() );

		$this->http = new FakeHttpClient();
		$keys       = array();
		for ( $i = 0; $i < 2500; $i++ ) {
			$keys[] = 'k' . $i;
		}
		$this->client()->delete_objects( $keys );
		self::assertCount( 3, $this->http->requests );
	}

	public function test_list_objects(): void {
		$this->http->queue( 200, '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>true</IsTruncated><NextContinuationToken>tok/1</NextContinuationToken><Contents><Key>p/a.jpg</Key><Size>10</Size></Contents><Contents><Key>p/b.jpg</Key><Size>20</Size></Contents></ListBucketResult>' );
		$page = $this->client()->list_objects( 'p/', 2, 'prev+token' );

		self::assertSame( array( 'p/a.jpg' => 10, 'p/b.jpg' => 20 ), $page['keys'] );
		self::assertSame( 'tok/1', $page['next'] );
		self::assertSame( self::BASE . '?list-type=2&max-keys=2&prefix=p%2F&continuation-token=prev%2Btoken', $this->http->last()->url() );

		$this->http->queue( 200, '<ListBucketResult><IsTruncated>false</IsTruncated></ListBucketResult>' );
		self::assertSame( '', $this->client()->list_objects()['next'] );
	}

	public function test_list_object_metas(): void {
		$this->http->queue( 200, '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>true</IsTruncated><NextContinuationToken>t2</NextContinuationToken><Contents><Key>p/a &amp; b.jpg</Key><LastModified>2026-11-01T10:00:00.000Z</LastModified><ETag>&quot;abc&quot;</ETag><Size>42</Size></Contents></ListBucketResult>' );
		$page = $this->client()->list_object_metas( 'p/' );

		self::assertSame( 't2', $page['next'] );
		self::assertCount( 1, $page['objects'] );
		self::assertSame( 'p/a & b.jpg', $page['objects'][0]->key );
		self::assertSame( 42, $page['objects'][0]->size );
		self::assertSame( 'abc', $page['objects'][0]->etag );
		self::assertSame( '2026-11-01T10:00:00.000Z', $page['objects'][0]->last_modified );
	}

	public function test_list_object_metas_rejects_a_repeated_continuation_token(): void {
		$this->http->queue( 200, '<ListBucketResult><IsTruncated>true</IsTruncated><NextContinuationToken>same</NextContinuationToken></ListBucketResult>' );

		$this->expectException( S3Exception::class );
		$this->expectExceptionMessage( 'same continuation token twice' );
		$this->client()->list_object_metas( '', 1000, 'same' );
	}

	public function test_multipart_operations(): void {
		$this->http
			->queue( 200, '<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Bucket>media</Bucket><Key>big.mp4</Key><UploadId>UP/1+x</UploadId></InitiateMultipartUploadResult>' )
			->queue( 200, '', array( 'ETag' => '"p1"' ) )
			->queue( 200, '<CompleteMultipartUploadResult><ETag>"final-2"</ETag></CompleteMultipartUploadResult>' )
			->queue( 204 );
		$client = $this->client();

		$id = $client->create_multipart_upload( 'big.mp4', 'video/mp4', 'public' );
		self::assertSame( 'UP/1+x', $id );
		self::assertSame( self::BASE . 'big.mp4?uploads=', $this->http->last()->url() );
		self::assertSame( 'video/mp4', $this->http->last()->header( 'content-type' ) );

		self::assertSame( 'p1', $client->upload_part( 'big.mp4', $id, 1, 'part-one' ) );
		self::assertSame( self::BASE . 'big.mp4?partNumber=1&uploadId=UP%2F1%2Bx', $this->http->last()->url() );
		$this->assert_signed( $this->http->last(), 'part-one' );

		self::assertSame( 'final-2', $client->complete_multipart_upload( 'big.mp4', $id, array( 2 => 'p2', 1 => '"p1"' ) ) );
		self::assertSame( '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"p1"</ETag></Part><Part><PartNumber>2</PartNumber><ETag>"p2"</ETag></Part></CompleteMultipartUpload>', $this->http->last()->body() );

		$client->abort_multipart_upload( 'big.mp4', $id );
		self::assertSame( 'DELETE', $this->http->last()->method() );
		self::assertSame( self::BASE . 'big.mp4?uploadId=UP%2F1%2Bx', $this->http->last()->url() );
	}

	public function test_complete_can_fail_with_status_200(): void {
		$this->http->queue( 200, '<?xml version="1.0"?><Error><Code>InvalidPart</Code><Message>One or more of the specified parts could not be found.</Message></Error>' );

		try {
			$this->client()->complete_multipart_upload( 'k', 'u', array( 1 => 'e' ) );
			self::fail( 'Expected an exception.' );
		} catch ( S3Exception $e ) {
			self::assertSame( 'InvalidPart', $e->error_code() );
			self::assertSame( 200, $e->status() );
			self::assertFalse( $e->is_retryable() );
		}
	}

	public function test_errors_are_parsed(): void {
		$this->http->queue( 403, '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SignatureDoesNotMatch</Code><Message>The request signature we calculated does not match the signature you provided.</Message></Error>' );

		try {
			$this->client()->put_object( 'a', 'b', 'text/plain' );
			self::fail( 'Expected an exception.' );
		} catch ( S3Exception $e ) {
			self::assertSame( 'SignatureDoesNotMatch', $e->error_code() );
			self::assertSame( 403, $e->status() );
			self::assertStringContainsString( 'does not match', $e->getMessage() );
		}
		self::assertSame( array(), $this->sleeps, 'Client errors are not retried.' );
	}

	public function test_server_errors_are_retried_and_resigned(): void {
		$this->http->queue( 503, '<Error><Code>ServiceUnavailable</Code></Error>' )->queue_error( 'cURL error 28' )->queue( 200, '', array( 'ETag' => '"ok"' ) );

		self::assertSame( 'ok', $this->client()->put_object( 'a', 'b', 'text/plain' ) );
		self::assertCount( 3, $this->http->requests );
		self::assertSame( array( 500000, 1000000 ), $this->sleeps );
		foreach ( $this->http->requests as $request ) {
			self::assertNotNull( $request->header( 'authorization' ) );
		}
	}

	public function test_gives_up_after_the_last_attempt(): void {
		$this->http->queue( 500 )->queue( 500 );

		try {
			$this->client( 2 )->head_object( 'a' );
			self::fail( 'Expected an exception.' );
		} catch ( S3Exception $e ) {
			self::assertSame( 'HTTP500', $e->error_code() );
			self::assertTrue( $e->is_retryable() );
			self::assertStringContainsString( 'HEAD /media/a returned HTTP 500.', $e->getMessage() );
		}

		$this->http->queue_error( 'down' );
		try {
			$this->client( 1 )->head_object( 'a' );
			self::fail( 'Expected an exception.' );
		} catch ( S3Exception $e ) {
			self::assertSame( 'NetworkError', $e->error_code() );
			self::assertSame( 0, $e->status() );
		}
	}

	public function test_get_object_to_file(): void {
		$file = tempnam( sys_get_temp_dir(), 'r2' );
		$this->http->queue( 200, 'image-bytes' )->queue( 404, '<Error><Code>NoSuchKey</Code></Error>' );

		self::assertTrue( $this->client()->get_object_to_file( 'a.jpg', $file ) );
		self::assertSame( 'image-bytes', file_get_contents( $file ) );
		self::assertFalse( $this->client()->get_object_to_file( 'b.jpg', $file ) );
		unlink( $file );
	}

	public function test_create_multipart_requires_upload_id(): void {
		$this->http->queue( 200, '<InitiateMultipartUploadResult></InitiateMultipartUploadResult>' );

		$this->expectException( S3Exception::class );
		$this->client()->create_multipart_upload( 'k', '' );
	}

	public function test_upload_part_requires_etag(): void {
		$this->http->queue( 200 );

		$this->expectException( S3Exception::class );
		$this->client()->upload_part( 'k', 'u', 1, 'x' );
	}
}
