<?php
/**
 * Progress of a bulk offload.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Bulk;

/**
 * Persisted after every attachment, so an interrupted run resumes where it stopped.
 */
final class BulkState {

	public const MAX_FAILURES = 100;

	/**
	 * Last processed attachment id.
	 *
	 * @var int
	 */
	public $cursor = 0;

	/**
	 * Attachments processed.
	 *
	 * @var int
	 */
	public $processed = 0;

	/**
	 * Attachments offloaded.
	 *
	 * @var int
	 */
	public $offloaded = 0;

	/**
	 * Attachments skipped.
	 *
	 * @var int
	 */
	public $skipped = 0;

	/**
	 * Attachments failed.
	 *
	 * @var int
	 */
	public $failed = 0;

	/**
	 * Bytes uploaded.
	 *
	 * @var int
	 */
	public $bytes = 0;

	/**
	 * Latest failures: attachment id => message.
	 *
	 * @var array<int,string>
	 */
	public $failures = array();

	/**
	 * Start time.
	 *
	 * @var int
	 */
	public $started_at = 0;

	/**
	 * Last update.
	 *
	 * @var int
	 */
	public $updated_at = 0;

	/**
	 * Whether the last page was empty.
	 *
	 * @var bool
	 */
	public $finished = false;

	/**
	 * Record one result.
	 *
	 * @param int           $attachment_id Attachment.
	 * @param OffloadResult $result Result.
	 * @param int           $now Time.
	 */
	public function record( int $attachment_id, OffloadResult $result, int $now ): void {
		$this->cursor = max( $this->cursor, $attachment_id );
		++$this->processed;
		$this->bytes     += $result->bytes;
		$this->updated_at = $now;
		switch ( $result->status ) {
			case OffloadResult::OFFLOADED:
				++$this->offloaded;
				unset( $this->failures[ $attachment_id ] );
				break;
			case OffloadResult::FAILED:
				++$this->failed;
				$this->failures[ $attachment_id ] = $result->message;
				if ( count( $this->failures ) > self::MAX_FAILURES ) {
					$this->failures = array_slice( $this->failures, -self::MAX_FAILURES, null, true );
				}
				break;
			default:
				++$this->skipped;
		}
	}

	/**
	 * Array form for the options table.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * Restore.
	 *
	 * @param mixed $data Stored value.
	 */
	public static function from_array( $data ): self {
		$state = new self();
		if ( ! is_array( $data ) ) {
			return $state;
		}
		foreach ( get_object_vars( $state ) as $key => $default ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			if ( is_int( $default ) ) {
				$state->$key = (int) $data[ $key ];
			} elseif ( is_bool( $default ) ) {
				$state->$key = (bool) $data[ $key ];
			} else {
				$failures = array();
				foreach ( (array) $data[ $key ] as $id => $message ) {
					$failures[ (int) $id ] = (string) $message;
				}
				$state->$key = $failures;
			}
		}

		return $state;
	}
}
