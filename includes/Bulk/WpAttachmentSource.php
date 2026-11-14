<?php
/**
 * Attachments from the database.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\WP\Bulk;

use Saizul\R2Offload\Bulk\AttachmentSource;
use Saizul\R2Offload\WP\Media\AttachmentOffloader;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk paging over all attachments; results change as the run progresses.

/**
 * Pages through attachments by id.
 */
final class WpAttachmentSource implements AttachmentSource {

	/**
	 * Ids after a cursor.
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit Page size.
	 * @return int[]
	 */
	public function ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$after_id,
				max( 1, $limit )
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Attachments.
	 */
	public function total(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
	}

	/**
	 * Attachments with a record.
	 */
	public function offloaded(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", AttachmentOffloader::META ) );
	}

	/**
	 * Ids of offloaded attachments after a cursor.
	 *
	 * @param int $after_id Cursor.
	 * @param int $limit Page size.
	 * @return int[]
	 */
	public function offloaded_ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d",
				AttachmentOffloader::META,
				$after_id,
				max( 1, $limit )
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
