<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\S3;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\Http\FakeHttpClient;
use Saizul\R2Offload\Http\Request;
use Saizul\R2Offload\Http\Response;
use Saizul\R2Offload\S3\MultipartException;
use Saizul\R2Offload\S3\MultipartState;
use Saizul\R2Offload\S3\MultipartUploader;
use Saizul\R2Offload\S3\R2Endpoint;
use Saizul\R2Offload\S3\S3Client;
use Saizul\R2Offload\Signer\Credentials;
use Saizul\R2Offload\Signer\SignatureV4;

final class MultipartUploaderTest extends TestCase {

	private const MIB = 1048576;

	/** @var string */
	private $file;

	/** @var array<int,string> Part bodies received. */
	private $parts = array();

	/** @var string[] */
	private $log = array();

	/** @var int|null Part number that fails once. */
	private $fail_part = null;

	protected function setUp(): void {
		$this->file = (string) tempnam( sys_get_temp_dir(), 'mpu' );
		// 12 MiB + 3 bytes of recognisable content.
		$handle = fopen( $this->file, 'wb' );
		for ( $i = 0; $i < 12; $i++ ) {
			fwrite( $handle, str_repeat( chr( 65 + $i ), self::MIB ) );
		}
		fwrite( $handle, 'END' );
		fclose( $handle );
		$this->parts     = array();
		$this->log       = array();
		$this->fail_part = null;
	}

	protected function tearDown(): void {
		unlink( $this->file );
	}

	private function client(): S3Client {
		$http = new FakeHttpClient(
			function ( Request $request ): Response {
				$url = $request->url();
				if ( false !== strpos( $url, '?uploads=' ) ) {
					$this->log[] = 'create';
					return new Response( 200, '<InitiateMultipartUploadResult><UploadId>U' . count( $this->log ) . '</UploadId></InitiateMultipartUploadResult>' );
				}
				if ( preg_match( '/partNumber=(\d+)/', $url, $m ) ) {
					$number = (int) $m[1];
					if ( $number === $this->fail_part ) {
						$this->fail_part = null;
						return new Response( 400, '<Error><Code>EntityTooSmall</Code><Message>nope</Message></Error>' );
					}
					$this->log[]            = 'part' . $number;
					$this->parts[ $number ] = $request->body();
					return new Response( 200, '', array( 'ETag' => '"e' . $number . '"' ) );
				}
				if ( 'DELETE' === $request->method() ) {
					$this->log[] = 'abort';
					return new Response( 204 );
				}
				$this->log[] = 'complete';
				return new Response( 200, '<CompleteMultipartUploadResult><ETag>"done"</ETag></CompleteMultipartUploadResult>' );
			}
		);

		return new S3Client(
			$http,
			new R2Endpoint( '0123456789abcdef0123456789abcdef', 'media' ),
			new SignatureV4( new Credentials( 'AKID', 'SECRET' ), 'auto', 's3' ),
			static function (): DateTimeImmutable {
				return new DateTimeImmutable( '2026-11-14T10:00:00Z' );
			},
			1
		);
	}

	public function test_part_size(): void {
		self::assertSame( 5 * self::MIB, MultipartUploader::part_size( 1, 0 ) );
		self::assertSame( 16 * self::MIB, MultipartUploader::part_size( 100 * self::MIB, 16 * self::MIB ) );
		self::assertSame( 6 * self::MIB, MultipartUploader::part_size( 100 * self::MIB, (int) ( 5.5 * self::MIB ) ), 'Rounded up to whole MiB.' );
		// 200 GiB needs parts of at least 20.48 MiB to stay within 10,000 parts.
		$size = MultipartUploader::part_size( 200 * 1024 * self::MIB, 16 * self::MIB );
		self::assertSame( 21 * self::MIB, $size );
		self::assertLessThanOrEqual( 10000, (int) ceil( 200 * 1024 * self::MIB / $size ) );
		self::assertSame( 5368709120, MultipartUploader::part_size( PHP_INT_MAX >> 10, 0 ) );
	}

	public function test_uploads_uniform_parts_in_order(): void {
		$states = array();
		$etag   = ( new MultipartUploader( $this->client(), 5 * self::MIB ) )->upload(
			'big.bin',
			$this->file,
			'application/octet-stream',
			'',
			null,
			static function ( MultipartState $state ) use ( &$states ): void {
				$states[] = count( $state->parts );
			}
		);

		self::assertSame( 'done', $etag );
		self::assertSame( array( 'create', 'part1', 'part2', 'part3', 'complete' ), $this->log );
		self::assertSame( 5 * self::MIB, strlen( $this->parts[1] ) );
		self::assertSame( 5 * self::MIB, strlen( $this->parts[2] ) );
		self::assertSame( 2 * self::MIB + 3, strlen( $this->parts[3] ) );
		self::assertSame( str_repeat( 'A', self::MIB ) . str_repeat( 'B', self::MIB ), substr( $this->parts[1], 0, 2 * self::MIB ) );
		self::assertStringEndsWith( 'LEND', $this->parts[3] );
		self::assertSame( md5_file( $this->file ), md5( implode( '', $this->parts ) ) );
		self::assertSame( array( 0, 1, 2, 3 ), $states );
	}

	public function test_failure_keeps_state_and_resume_skips_done_parts(): void {
		$uploader        = new MultipartUploader( $this->client(), 5 * self::MIB );
		$this->fail_part = 2;

		try {
			$uploader->upload( 'big.bin', $this->file, 'application/octet-stream' );
			self::fail( 'Expected a failure.' );
		} catch ( MultipartException $e ) {
			$state = $e->state();
			self::assertSame( array( 1 => 'e1' ), $state->parts );
			self::assertSame( 'EntityTooSmall', $e->s3_error()->error_code() );
		}

		// Persist and restore as the plugin does with post meta.
		$restored = MultipartState::from_array( $state->to_array() );
		$this->log = array();
		$uploader->upload( 'big.bin', $this->file, 'application/octet-stream', '', $restored );

		self::assertSame( array( 'part2', 'part3', 'complete' ), $this->log );
	}

	public function test_changed_file_starts_a_new_upload_and_aborts_the_old_one(): void {
		$stale = new MultipartState( 'big.bin', 'OLD', 5 * self::MIB, 999, 1, array( 1 => 'x' ) );
		( new MultipartUploader( $this->client(), 5 * self::MIB ) )->upload( 'big.bin', $this->file, 'application/octet-stream', '', $stale );

		self::assertSame( array( 'abort', 'create', 'part1', 'part2', 'part3', 'complete' ), $this->log );
	}

	public function test_state_helpers(): void {
		$state = new MultipartState( 'k', 'u', 5 * self::MIB, 12 * self::MIB + 3, 100, array( '2' => 'b' ) );

		self::assertSame( 3, $state->part_count() );
		self::assertTrue( $state->matches( 'k', 12 * self::MIB + 3, 100 ) );
		self::assertFalse( $state->matches( 'k', 12 * self::MIB + 3, 101 ) );
		self::assertNull( MultipartState::from_array( array( 'key' => 'k' ) ) );
		self::assertSame( array( 2 => 'b' ), MultipartState::from_array( $state->to_array() )->parts );
	}

	public function test_missing_file(): void {
		$this->expectException( \RuntimeException::class );
		( new MultipartUploader( $this->client() ) )->upload( 'k', '/nonexistent/file.bin', 'x' );
	}
}
