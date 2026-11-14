<?php
/**
 * Offloads one attachment.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Bulk;

/**
 * Implemented by the WordPress layer.
 */
interface Offloader {

	/**
	 * Upload the attachment's files that are not in the bucket yet.
	 *
	 * @param int  $attachment_id Attachment.
	 * @param bool $dry_run Only report what would happen.
	 */
	public function offload( int $attachment_id, bool $dry_run = false ): OffloadResult;
}
