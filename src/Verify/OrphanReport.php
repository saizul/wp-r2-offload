<?php
/**
 * Result of an orphan scan.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Verify;

use Saizul\R2Offload\S3\ObjectMeta;

/**
 * Objects in the bucket that no attachment refers to.
 */
final class OrphanReport {

	/**
	 * Prefix that was listed.
	 *
	 * @var string
	 */
	public $prefix = '';

	/**
	 * Objects listed.
	 *
	 * @var int
	 */
	public $scanned = 0;

	/**
	 * Objects that belong to an attachment.
	 *
	 * @var int
	 */
	public $referenced = 0;

	/**
	 * Unreferenced objects skipped because they are newer than the minimum
	 * age (or their age is unknown).
	 *
	 * @var int
	 */
	public $recent = 0;

	/**
	 * Unreferenced objects kept by the keep callback or folder markers.
	 *
	 * @var int
	 */
	public $kept = 0;

	/**
	 * Key => metadata of orphaned objects, in key order.
	 *
	 * @var array<string,ObjectMeta>
	 */
	public $orphans = array();

	/**
	 * Whether the scan stopped at the limit before the end of the listing.
	 *
	 * @var bool
	 */
	public $truncated = false;

	/**
	 * Total size of the orphaned objects.
	 */
	public function orphan_bytes(): int {
		$bytes = 0;
		foreach ( $this->orphans as $meta ) {
			$bytes += $meta->size;
		}

		return $bytes;
	}
}
