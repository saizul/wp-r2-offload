<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\Verify;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\Http\FakeHttpClient;
use Saizul\R2Offload\Media\OffloadRecord;
use Saizul\R2Offload\S3\R2Endpoint;
use Saizul\R2Offload\S3\S3Client;
use Saizul\R2Offload\Signer\Credentials;
use Saizul\R2Offload\Signer\SignatureV4;
use Saizul\R2Offload\Verify\Verifier;

final class VerifierTest extends TestCase {

	public function test_reports_missing_mismatched_and_errors(): void {
		$http = new FakeHttpClient();
		$http->queue( 200, '', array( 'Content-Length' => '300' ) )
			->queue( 404 )
			->queue( 200, '', array( 'Content-Length' => '10' ) )
			->queue( 403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>' )
			->queue( 200, '', array( 'Content-Length' => '77' ) );
		$client = new S3Client(
			$http,
			new R2Endpoint( '0123456789abcdef0123456789abcdef', 'media' ),
			new SignatureV4( new Credentials( 'a', 'b' ), 'auto', 's3' ),
			static function (): DateTimeImmutable {
				return new DateTimeImmutable( '2026-01-01T00:00:00Z' );
			},
			1
		);
		$record = new OffloadRecord( 'media' );
		$record->add( 'a.jpg', 'k/a.jpg', 300 );
		$record->add( 'b.jpg', 'k/b.jpg', 20 );
		$record->add( 'c.jpg', 'k/c.jpg', 20 );
		$record->add( 'd.jpg', 'k/d.jpg', 20 );
		$record->add( 'e.jpg', 'k/e.jpg', 0 );

		$report = ( new Verifier( $client ) )->verify( $record, array( 'c.jpg' => 11 ) );

		self::assertSame( array( 'k/a.jpg', 'k/e.jpg' ), $report->ok );
		self::assertSame( array( 'b.jpg' => 'k/b.jpg' ), $report->missing );
		self::assertSame( array( 'c.jpg' => array( 11, 10 ) ), $report->mismatched, 'The local size wins over the recorded one.' );
		self::assertSame( array( 'd.jpg' ), array_keys( $report->errors ) );
		self::assertFalse( $report->is_ok() );
	}
}
