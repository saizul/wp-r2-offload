<?php
/**
 * Attachment ids for bulk runs.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Bulk;

/**
 * Pages through attachments in ascending id order.
 */
interface AttachmentSource {

	/**
	 * Ids greater than $after_id, ascending.
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit Page size.
	 * @return int[]
	 */
	public function ids_after( int $after_id, int $limit ): array;

	/**
	 * Ids of attachments with an offload record greater than $after_id, ascending.
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit Page size.
	 * @return int[]
	 */
	public function offloaded_ids_after( int $after_id, int $limit ): array;

	/**
	 * Number of attachments.
	 */
	public function total(): int;

	/**
	 * Number of attachments with an offload record.
	 */
	public function offloaded(): int;
}
