<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\Bulk;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Saizul\R2Offload\Bulk\AttachmentSource;
use Saizul\R2Offload\Bulk\BulkOffloader;
use Saizul\R2Offload\Bulk\BulkState;
use Saizul\R2Offload\Bulk\Offloader;
use Saizul\R2Offload\Bulk\OffloadResult;

final class BulkOffloaderTest extends TestCase {

	/**
	 * @param int[] $ids Ids.
	 */
	private function source( array $ids ): AttachmentSource {
		return new class( $ids ) implements AttachmentSource {
			/** @var int[] */
			private $ids;

			public function __construct( array $ids ) {
				$this->ids = $ids;
			}

			public function ids_after( int $after_id, int $limit ): array {
				return array_slice(
					array_values(
						array_filter(
							$this->ids,
							static function ( int $id ) use ( $after_id ): bool {
								return $id > $after_id;
							}
						)
					),
					0,
					$limit
				);
			}

			public function offloaded_ids_after( int $after_id, int $limit ): array {
				return array();
			}

			public function total(): int {
				return count( $this->ids );
			}

			public function offloaded(): int {
				return 0;
			}
		};
	}

	private function offloader(): Offloader {
		return new class() implements Offloader {
			/** @var int[] */
			public $calls = array();

			public function offload( int $attachment_id, bool $dry_run = false ): OffloadResult {
				$this->calls[] = $attachment_id;
				if ( 7 === $attachment_id ) {
					throw new RuntimeException( 'disk error' );
				}
				if ( 0 === $attachment_id % 5 ) {
					return new OffloadResult( OffloadResult::FAILED, 'boom' );
				}
				if ( 0 === $attachment_id % 2 ) {
					return new OffloadResult( OffloadResult::SKIPPED, 'already' );
				}
				return new OffloadResult( OffloadResult::OFFLOADED, 'ok', 3, 100 );
			}
		};
	}

	public function test_batches_resume_and_finish(): void {
		$ids       = array( 1, 2, 3, 5, 7, 9, 11 );
		$offloader = $this->offloader();
		$saved     = array();
		$runner    = new BulkOffloader(
			$this->source( $ids ),
			$offloader,
			static function (): int {
				return 1000;
			}
		);
		$save      = static function ( BulkState $state ) use ( &$saved ): void {
			$saved[] = $state->to_array();
		};

		$state = new BulkState();
		self::assertSame( 3, $runner->run_batch( $state, 3, $save ) );
		self::assertSame( 3, $state->cursor );
		self::assertCount( 3, $saved );

		// Simulate a new process: restore from the last saved state.
		$state = BulkState::from_array( end( $saved ) );
		self::assertSame( 3, $runner->run_batch( $state, 3, $save ) );
		self::assertSame( 1, $runner->run_batch( $state, 3, $save ) );
		self::assertSame( 0, $runner->run_batch( $state, 3, $save ) );

		self::assertTrue( $state->finished );
		self::assertSame( $ids, $offloader->calls, 'Every attachment exactly once.' );
		self::assertSame( 7, $state->processed );
		self::assertSame( 4, $state->offloaded );
		self::assertSame( 1, $state->skipped );
		self::assertSame( 2, $state->failed );
		self::assertSame( 400, $state->bytes );
		self::assertSame( array( 5 => 'boom', 7 => 'disk error' ), $state->failures );
		self::assertSame( 1000, $state->started_at );
	}

	public function test_dry_run_does_not_save(): void {
		$saved  = 0;
		$items  = array();
		$runner = new BulkOffloader( $this->source( array( 1, 3 ) ), $this->offloader() );
		$runner->run_batch(
			new BulkState(),
			10,
			static function () use ( &$saved ): void {
				++$saved;
			},
			static function ( int $id, OffloadResult $result ) use ( &$items ): void {
				$items[ $id ] = $result->status;
			},
			true
		);

		self::assertSame( 0, $saved );
		self::assertSame( array( 1 => 'offloaded', 3 => 'offloaded' ), $items );
	}

	public function test_state_failures_are_capped_and_cleared(): void {
		$state = new BulkState();
		for ( $i = 1; $i <= 150; $i++ ) {
			$state->record( $i, new OffloadResult( OffloadResult::FAILED, 'x' ), 1 );
		}
		self::assertCount( 100, $state->failures );
		self::assertArrayHasKey( 150, $state->failures );
		self::assertArrayNotHasKey( 50, $state->failures );

		$state->record( 150, new OffloadResult( OffloadResult::OFFLOADED ), 2 );
		self::assertArrayNotHasKey( 150, $state->failures );

		$restored = BulkState::from_array( $state->to_array() );
		self::assertSame( $state->failures, $restored->failures );
		self::assertSame( 150, $restored->cursor );
		self::assertSame( 0, BulkState::from_array( 'garbage' )->cursor );
	}
}
