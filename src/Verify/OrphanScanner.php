<?php
/**
 * Finds objects that no attachment refers to.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Verify;

use Saizul\R2Offload\S3\ObjectMeta;
use Saizul\R2Offload\S3\S3Client;
use Saizul\R2Offload\S3\S3Exception;

/**
 * Lists the bucket under the key prefix (ListObjectsV2) and reports objects
 * that are not in any offload record: files left behind by attachments
 * deleted while the plugin was inactive, failed deletes, or old image edits.
 *
 * Safety rules:
 *
 * - Objects newer than the minimum age are never reported, so a file that
 *   was uploaded a moment ago and whose record is not saved yet is safe.
 *   Objects without a readable LastModified count as new.
 * - Keys ending in "/" (folder markers created by other tools) are kept.
 * - {@see delete()} asks the reference check again for every key right
 *   before deleting it.
 */
final class OrphanScanner {

	/**
	 * Client.
	 *
	 * @var S3Client
	 */
	private $client;

	/**
	 * ListObjectsV2 page size.
	 *
	 * @var int
	 */
	private $page_size;

	/**
	 * Constructor.
	 *
	 * @param S3Client $client Client.
	 * @param int      $page_size ListObjectsV2 page size (1 to 1000).
	 */
	public function __construct( S3Client $client, int $page_size = 1000 ) {
		$this->client    = $client;
		$this->page_size = max( 1, min( 1000, $page_size ) );
	}

	/**
	 * Scan the bucket.
	 *
	 * @param string                         $prefix Key prefix to list ("" for the whole bucket).
	 * @param callable(string):bool          $is_referenced Whether a key belongs to an attachment.
	 * @param int                            $min_age Minimum age in seconds for an object to be reported.
	 * @param int                            $now Current Unix time.
	 * @param int                            $limit Stop after this many orphans (0 = no limit).
	 * @param callable(ObjectMeta):bool|null $keep Return true to keep an unreferenced object out of the report.
	 * @throws S3Exception When listing fails.
	 */
	public function scan( string $prefix, callable $is_referenced, int $min_age, int $now, int $limit = 0, ?callable $keep = null ): OrphanReport {
		$report         = new OrphanReport();
		$report->prefix = $prefix;
		$continuation   = '';

		do {
			$page = $this->client->list_object_metas( $prefix, $this->page_size, $continuation );
			foreach ( $page['objects'] as $meta ) {
				if ( $limit > 0 && count( $report->orphans ) >= $limit ) {
					$report->truncated = true;
					return $report;
				}
				++$report->scanned;
				if ( $is_referenced( $meta->key ) ) {
					++$report->referenced;
					continue;
				}
				if ( '' === $meta->key || '/' === substr( $meta->key, -1 ) || ( null !== $keep && $keep( $meta ) ) ) {
					++$report->kept;
					continue;
				}
				$modified = self::timestamp( $meta->last_modified );
				if ( null === $modified || $now - $modified < $min_age ) {
					++$report->recent;
					continue;
				}
				$report->orphans[ $meta->key ] = $meta;
			}
			$continuation = $page['next'];
		} while ( '' !== $continuation );

		return $report;
	}

	/**
	 * Delete the orphans of a report.
	 *
	 * @param OrphanReport          $report Report from {@see scan()}.
	 * @param callable(string):bool $is_referenced Fresh reference check; keys that are referenced now are skipped.
	 * @return array{deleted:string[],skipped:string[],failed:array<string,string>} Keys deleted, skipped because they are referenced now, and key => error.
	 * @throws S3Exception When a DeleteObjects request fails as a whole.
	 */
	public function delete( OrphanReport $report, callable $is_referenced ): array {
		$delete  = array();
		$skipped = array();
		foreach ( array_keys( $report->orphans ) as $key ) {
			$key = (string) $key;
			if ( $is_referenced( $key ) ) {
				$skipped[] = $key;
			} else {
				$delete[] = $key;
			}
		}
		$failed = array() === $delete ? array() : $this->client->delete_objects( $delete );

		return array(
			'deleted' => array_values( array_diff( $delete, array_map( 'strval', array_keys( $failed ) ) ) ),
			'skipped' => $skipped,
			'failed'  => $failed,
		);
	}

	/**
	 * Unix time of a LastModified value (ISO 8601 in listings, RFC 7231 in headers).
	 *
	 * @param string $value Value.
	 */
	public static function timestamp( string $value ): ?int {
		if ( '' === trim( $value ) ) {
			return null;
		}
		$time = strtotime( $value );

		return false === $time ? null : $time;
	}
}
