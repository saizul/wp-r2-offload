<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\Verify;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\Http\FakeHttpClient;
use Saizul\R2Offload\S3\ObjectMeta;
use Saizul\R2Offload\S3\R2Endpoint;
use Saizul\R2Offload\S3\S3Client;
use Saizul\R2Offload\S3\S3Exception;
use Saizul\R2Offload\Signer\Credentials;
use Saizul\R2Offload\Signer\SignatureV4;
use Saizul\R2Offload\Verify\OrphanScanner;

final class OrphanScannerTest extends TestCase {

	private const NOW = 1794009600; // 2026-11-07T00:00:00Z.

	/** @var FakeHttpClient */
	private $http;

	protected function setUp(): void {
		$this->http = new FakeHttpClient();
	}

	private function client(): S3Client {
		return new S3Client(
			$this->http,
			new R2Endpoint( '0123456789abcdef0123456789abcdef', 'media' ),
			new SignatureV4( new Credentials( 'a', 'b' ), 'auto', 's3' ),
			static function (): DateTimeImmutable {
				return new DateTimeImmutable( '2026-11-07T00:00:00Z' );
			},
			1
		);
	}

	/**
	 * @param array<int,array{0:string,1:int,2:string}> $objects Key, size, LastModified.
	 */
	private static function page( array $objects, string $next = '' ): string {
		$xml = '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><IsTruncated>' . ( '' === $next ? 'false' : 'true' ) . '</IsTruncated>';
		if ( '' !== $next ) {
			$xml .= '<NextContinuationToken>' . $next . '</NextContinuationToken>';
		}
		foreach ( $objects as $object ) {
			$xml .= '<Contents><Key>' . $object[0] . '</Key><LastModified>' . $object[2] . '</LastModified><Size>' . $object[1] . '</Size></Contents>';
		}

		return $xml . '</ListBucketResult>';
	}

	/**
	 * @param string[] $keys Referenced keys.
	 * @return callable(string):bool
	 */
	private static function referenced( array $keys ): callable {
		return static function ( string $key ) use ( $keys ): bool {
			return in_array( $key, $keys, true );
		};
	}

	public function test_scan_pages_and_classifies_objects(): void {
		$old = '2026-10-01T00:00:00.000Z';
		$new = '2026-11-06T23:30:00.000Z';
		$this->http
			->queue(
				200,
				self::page(
					array(
						array( 'up/a.jpg', 10, $old ),
						array( 'up/b.jpg', 20, $old ),
						array( 'up/folder/', 0, $old ),
					),
					'tok'
				)
			)
			->queue(
				200,
				self::page(
					array(
						array( 'up/c.jpg', 30, $new ),
						array( 'up/d.jpg', 40, '' ),
						array( 'up/e.jpg', 50, $old ),
						array( 'up/keep.txt', 5, $old ),
					)
				)
			);

		$keep   = static function ( ObjectMeta $meta ): bool {
			return 'up/keep.txt' === $meta->key;
		};
		$report = ( new OrphanScanner( $this->client(), 3 ) )->scan( 'up/', self::referenced( array( 'up/a.jpg' ) ), 3600, self::NOW, 0, $keep );

		self::assertSame( 'up/', $report->prefix );
		self::assertSame( 7, $report->scanned );
		self::assertSame( 1, $report->referenced );
		self::assertSame( 2, $report->kept, 'The folder marker and the kept key.' );
		self::assertSame( 2, $report->recent, 'Newer than an hour, and no LastModified.' );
		self::assertSame( array( 'up/b.jpg', 'up/e.jpg' ), array_keys( $report->orphans ) );
		self::assertSame( 70, $report->orphan_bytes() );
		self::assertFalse( $report->truncated );

		self::assertCount( 2, $this->http->requests );
		self::assertStringContainsString( 'max-keys=3&prefix=up%2F', $this->http->requests[0]->url() );
		self::assertStringContainsString( '&continuation-token=tok', $this->http->requests[1]->url() );
	}

	public function test_min_age_zero_reports_new_objects(): void {
		$this->http->queue( 200, self::page( array( array( 'k/new.jpg', 1, '2026-11-07T00:00:00.000Z' ) ) ) );
		$report = ( new OrphanScanner( $this->client() ) )->scan( 'k/', self::referenced( array() ), 0, self::NOW );

		self::assertSame( array( 'k/new.jpg' ), array_keys( $report->orphans ) );
	}

	public function test_limit_stops_the_scan(): void {
		$old = '2026-01-01T00:00:00.000Z';
		$this->http->queue(
			200,
			self::page(
				array(
					array( 'k/1', 1, $old ),
					array( 'k/2', 1, $old ),
					array( 'k/3', 1, $old ),
				),
				'more'
			)
		);
		$report = ( new OrphanScanner( $this->client() ) )->scan( 'k/', self::referenced( array() ), 0, self::NOW, 2 );

		self::assertSame( array( 'k/1', 'k/2' ), array_keys( $report->orphans ) );
		self::assertTrue( $report->truncated );
		self::assertCount( 1, $this->http->requests, 'No further page is requested.' );
	}

	public function test_listing_errors_are_thrown(): void {
		$this->http->queue( 403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>' );

		$this->expectException( S3Exception::class );
		( new OrphanScanner( $this->client() ) )->scan( '', self::referenced( array() ), 0, self::NOW );
	}

	public function test_delete_rechecks_references_and_reports_failures(): void {
		$old = '2026-01-01T00:00:00.000Z';
		$this->http->queue(
			200,
			self::page(
				array(
					array( 'k/a', 1, $old ),
					array( 'k/b', 2, $old ),
					array( 'k/c', 3, $old ),
				)
			)
		);
		$scanner = new OrphanScanner( $this->client() );
		$report  = $scanner->scan( 'k/', self::referenced( array() ), 0, self::NOW );
		self::assertCount( 3, $report->orphans );

		$this->http->queue( 200, '<DeleteResult><Error><Key>k/c</Key><Code>AccessDenied</Code><Message>Denied</Message></Error></DeleteResult>' );
		$result = $scanner->delete( $report, self::referenced( array( 'k/b' ) ) );

		self::assertSame( array( 'k/a' ), $result['deleted'] );
		self::assertSame( array( 'k/b' ), $result['skipped'] );
		self::assertSame( array( 'k/c' => 'AccessDenied Denied' ), $result['failed'] );
		$body = $this->http->last()->body();
		self::assertStringContainsString( '<Key>k/a</Key>', $body );
		self::assertStringNotContainsString( '<Key>k/b</Key>', $body );
	}

	public function test_delete_without_candidates_sends_nothing(): void {
		$this->http->queue( 200, self::page( array( array( 'k/a', 1, '2026-01-01T00:00:00.000Z' ) ) ) );
		$scanner = new OrphanScanner( $this->client() );
		$report  = $scanner->scan( 'k/', self::referenced( array() ), 0, self::NOW );

		$result = $scanner->delete( $report, self::referenced( array( 'k/a' ) ) );
		self::assertSame( array(), $result['deleted'] );
		self::assertCount( 1, $this->http->requests );
	}

	public function test_timestamp(): void {
		self::assertSame( self::NOW, OrphanScanner::timestamp( '2026-11-07T00:00:00.000Z' ) );
		self::assertSame( self::NOW, OrphanScanner::timestamp( 'Sat, 07 Nov 2026 00:00:00 GMT' ) );
		self::assertNull( OrphanScanner::timestamp( '' ) );
		self::assertNull( OrphanScanner::timestamp( 'not a date' ) );
	}
}
