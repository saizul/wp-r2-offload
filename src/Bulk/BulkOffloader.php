<?php
/**
 * Resumable bulk offload.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Bulk;

use Throwable;

/**
 * Walks through attachments in id order, offloads each and saves progress
 * after every item through the $save callback.
 */
final class BulkOffloader {

	/**
	 * Source.
	 *
	 * @var AttachmentSource
	 */
	private $source;

	/**
	 * Offloader.
	 *
	 * @var Offloader
	 */
	private $offloader;

	/**
	 * Clock.
	 *
	 * @var callable():int
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param AttachmentSource    $source Source.
	 * @param Offloader           $offloader Offloader.
	 * @param callable():int|null $clock Clock.
	 */
	public function __construct( AttachmentSource $source, Offloader $offloader, ?callable $clock = null ) {
		$this->source    = $source;
		$this->offloader = $offloader;
		$this->clock     = $clock ?? 'time';
	}

	/**
	 * Process one batch.
	 *
	 * @param BulkState                             $state State (modified).
	 * @param int                                   $batch_size Attachments in the batch.
	 * @param callable(BulkState):void|null         $save Persist the state.
	 * @param callable(int,OffloadResult):void|null $on_item Progress callback.
	 * @param bool                                  $dry_run Report only.
	 * @return int Attachments processed in this batch (0 when finished).
	 */
	public function run_batch( BulkState $state, int $batch_size, ?callable $save = null, ?callable $on_item = null, bool $dry_run = false ): int {
		if ( 0 === $state->started_at ) {
			$state->started_at = ( $this->clock )();
		}
		$ids = $this->source->ids_after( $state->cursor, max( 1, $batch_size ) );
		if ( array() === $ids ) {
			$state->finished   = true;
			$state->updated_at = ( $this->clock )();
			if ( null !== $save && ! $dry_run ) {
				$save( $state );
			}
			return 0;
		}

		foreach ( $ids as $id ) {
			try {
				$result = $this->offloader->offload( (int) $id, $dry_run );
			} catch ( Throwable $e ) {
				$result = new OffloadResult( OffloadResult::FAILED, $e->getMessage() );
			}
			$state->record( (int) $id, $result, ( $this->clock )() );
			if ( null !== $on_item ) {
				$on_item( (int) $id, $result );
			}
			if ( null !== $save && ! $dry_run ) {
				$save( $state );
			}
		}

		return count( $ids );
	}
}
