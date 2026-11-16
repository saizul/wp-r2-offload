<?php
/**
 * WP-CLI commands.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\WP\Cli;

use Saizul\R2Offload\Bulk\AttachmentSource;
use Saizul\R2Offload\Bulk\BulkOffloader;
use Saizul\R2Offload\Bulk\BulkState;
use Saizul\R2Offload\Bulk\OffloadResult;
use Saizul\R2Offload\S3\ObjectMeta;
use Saizul\R2Offload\S3\S3Exception;
use Saizul\R2Offload\Verify\OrphanScanner;
use Saizul\R2Offload\Verify\Verifier;
use Saizul\R2Offload\WP\Bulk\WpAttachmentSource;
use Saizul\R2Offload\WP\Media\AttachmentOffloader;
use Saizul\R2Offload\WP\Services;
use Throwable;
use WP_CLI;

/**
 * Bulk offload, status, verification, orphan clean-up and restore of the
 * media library.
 */
final class R2Command {

	public const STATE_OPTION = 'wp_r2_offload_bulk_state';

	/**
	 * Services.
	 *
	 * @var Services
	 */
	private $services;

	/**
	 * Attachments.
	 *
	 * @var AttachmentSource
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param Services              $services Services.
	 * @param AttachmentSource|null $source Attachments (tests).
	 */
	public function __construct( Services $services, ?AttachmentSource $source = null ) {
		$this->services = $services;
		$this->source   = $source ?? new WpAttachmentSource();
	}

	/**
	 * Offload existing attachments to R2. Progress is saved after every
	 * attachment; run the command again to continue where it stopped.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<size>]
	 * : Attachments per batch.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--limit=<n>]
	 * : Stop after this many attachments (0 = all).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--reset]
	 * : Start again from the first attachment.
	 *
	 * [--dry-run]
	 * : Show what would be uploaded.
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2 offload --batch=100
	 *     wp r2 offload --limit=20 --dry-run
	 *     wp r2 offload --reset
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function offload( $args, $assoc_args ): void {
		unset( $args );
		$config = $this->services->settings->config();
		if ( ! $config->is_ready() ) {
			WP_CLI::error( implode( ' ', $config->errors() ) );
			return;
		}
		$batch   = max( 1, (int) ( $assoc_args['batch'] ?? 50 ) );
		$limit   = max( 0, (int) ( $assoc_args['limit'] ?? 0 ) );
		$dry_run = ! empty( $assoc_args['dry-run'] );

		$state = ! empty( $assoc_args['reset'] ) || $dry_run ? new BulkState() : BulkState::from_array( get_option( self::STATE_OPTION ) );
		if ( $state->finished ) {
			$state->finished = false;
		}
		if ( $state->cursor > 0 ) {
			WP_CLI::log( sprintf( 'Resuming after attachment %d (%d processed so far).', $state->cursor, $state->processed ) );
		}

		$runner = new BulkOffloader( $this->source, $this->services->offloader() );
		$save   = static function ( BulkState $state ): void {
			update_option( self::STATE_OPTION, $state->to_array(), false );
		};
		$report = static function ( int $id, OffloadResult $result ): void {
			$line = sprintf( '#%1$d %2$s %3$s', $id, $result->status, $result->message );
			if ( OffloadResult::FAILED === $result->status ) {
				WP_CLI::warning( $line );
			} else {
				WP_CLI::log( $line );
			}
		};

		$done = 0;
		while ( 0 === $limit || $done < $limit ) {
			$size  = 0 === $limit ? $batch : min( $batch, $limit - $done );
			$count = $runner->run_batch( $state, $size, $save, $report, $dry_run );
			if ( 0 === $count ) {
				break;
			}
			$done += $count;
		}

		$summary = sprintf(
			'%1$d processed: %2$d offloaded, %3$d skipped, %4$d failed, %5$s uploaded.',
			$state->processed,
			$state->offloaded,
			$state->skipped,
			$state->failed,
			self::bytes( $state->bytes )
		);
		if ( $dry_run ) {
			WP_CLI::success( 'Dry run. ' . $summary );
		} elseif ( $state->finished ) {
			WP_CLI::success( 'Finished. ' . $summary );
		} else {
			WP_CLI::success( sprintf( 'Stopped after attachment %1$d. %2$s Run the command again to continue.', $state->cursor, $summary ) );
		}
	}

	/**
	 * Show the configuration and offload progress.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function status( $args, $assoc_args ): void {
		unset( $args );
		$config = $this->services->settings->config();
		$state  = BulkState::from_array( get_option( self::STATE_OPTION ) );
		$total  = $this->source->total();
		$done   = $this->source->offloaded();

		$rows = array();
		foreach ( $config->describe() as $key => $value ) {
			$rows[] = array(
				'setting' => $key,
				'value'   => $value,
				'source'  => $config->source( $key ),
			);
		}
		$rows[] = array(
			'setting' => 'attachments',
			'value'   => sprintf( '%1$d of %2$d offloaded (%3$d pending)', $done, $total, max( 0, $total - $done ) ),
			'source'  => '',
		);
		$rows[] = array(
			'setting' => 'bulk_run',
			'value'   => 0 === $state->started_at ? 'never run' : sprintf( '%1$s, cursor %2$d, %3$d failed', $state->finished ? 'finished' : 'in progress', $state->cursor, $state->failed ),
			'source'  => '',
		);
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'setting', 'value', 'source' ) );

		foreach ( $config->errors() as $error ) {
			WP_CLI::warning( $error );
		}
	}

	/**
	 * Check that every offloaded file exists in the bucket with the right size.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs; all offloaded attachments when omitted.
	 *
	 * [--fix]
	 * : Upload missing or different objects again from the local files.
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function verify( $args, $assoc_args ): void {
		$fix      = ! empty( $assoc_args['fix'] );
		$verifier = new Verifier( $this->services->client() );
		$problems = 0;
		$checked  = 0;
		$basedir  = AttachmentOffloader::basedir();

		foreach ( $this->offloaded_ids( array_map( 'intval', (array) $args ) ) as $id ) {
			$record = AttachmentOffloader::record( $id );
			if ( null === $record ) {
				WP_CLI::warning( sprintf( '#%d is not offloaded.', $id ) );
				continue;
			}
			$local = array();
			foreach ( array_keys( $record->files ) as $relative ) {
				$path = $basedir . '/' . $relative;
				if ( is_file( $path ) ) {
					$local[ $relative ] = (int) filesize( $path );
				}
			}
			$report   = $verifier->verify( $record, $local );
			$checked += count( $record->files );
			if ( $report->is_ok() ) {
				continue;
			}
			foreach ( $report->missing as $relative => $key ) {
				++$problems;
				WP_CLI::warning( sprintf( '#%1$d missing: %2$s', $id, $key ) );
				$record->remove( $relative );
			}
			foreach ( $report->mismatched as $relative => $sizes ) {
				++$problems;
				WP_CLI::warning( sprintf( '#%1$d size differs: %2$s (expected %3$d, found %4$d)', $id, $relative, $sizes[0], $sizes[1] ) );
				$record->remove( $relative );
			}
			foreach ( $report->errors as $relative => $error ) {
				++$problems;
				WP_CLI::warning( sprintf( '#%1$d %2$s: %3$s', $id, $relative, $error ) );
			}
			if ( $fix && ( array() !== $report->missing || array() !== $report->mismatched ) ) {
				update_post_meta( $id, AttachmentOffloader::META, $record->to_array() );
				$result = $this->services->offloader()->offload( $id );
				WP_CLI::log( sprintf( '#%1$d %2$s %3$s', $id, $result->status, $result->message ) );
			}
		}

		if ( 0 === $problems ) {
			WP_CLI::success( sprintf( '%d object(s) verified.', $checked ) );
		} else {
			WP_CLI::error( sprintf( '%1$d problem(s) in %2$d object(s).%3$s', $problems, $checked, $fix ? '' : ' Run with --fix to upload them again.' ) );
		}
	}

	/**
	 * Find objects in the bucket that no attachment refers to, and optionally
	 * delete them.
	 *
	 * Lists the bucket under the object key prefix and compares every key with
	 * the files recorded for offloaded attachments. Orphans are left behind
	 * when attachments are deleted while the plugin is inactive, when a delete
	 * request fails, or by old image edits. Objects newer than --min-age are
	 * never reported, so uploads in progress are safe. Nothing is deleted
	 * without --delete.
	 *
	 * ## OPTIONS
	 *
	 * [--delete]
	 * : Delete the orphaned objects (asks for confirmation).
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * [--min-age=<hours>]
	 * : Only report objects last modified at least this many hours ago.
	 * ---
	 * default: 24
	 * ---
	 *
	 * [--limit=<n>]
	 * : Stop after this many orphans (0 = all).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2 orphans
	 *     wp r2 orphans --format=json
	 *     wp r2 orphans --delete --min-age=72
	 *     wp r2 orphans --delete --yes --limit=1000
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function orphans( $args, $assoc_args ): void {
		unset( $args );
		$config = $this->services->settings->config();
		if ( ! $config->is_ready() ) {
			WP_CLI::error( implode( ' ', $config->errors() ) );
			return;
		}
		$delete  = ! empty( $assoc_args['delete'] );
		$format  = (string) ( $assoc_args['format'] ?? 'table' );
		$hours   = (float) ( $assoc_args['min-age'] ?? 24 );
		$limit   = max( 0, (int) ( $assoc_args['limit'] ?? 0 ) );
		$prefix  = $this->services->keys()->prefix();
		$shared  = self::shared_prefix_reason( $prefix, $config->get( 'key_prefix' ) );
		$bucket  = $config->get( 'bucket' );
		$scanner = new OrphanScanner( $this->services->client() );

		if ( $hours < 0 ) {
			WP_CLI::error( '--min-age must be 0 or more hours.' );
			return;
		}
		if ( $delete && '' !== $shared ) {
			WP_CLI::error( $shared . ' Deleting is disabled; set a key prefix that only this site uses.' );
			return;
		}
		if ( '' !== $shared ) {
			WP_CLI::warning( $shared . ' Objects of other sites or tools may be listed.' );
		}

		$known  = $this->referenced_keys();
		$keep   = static function ( ObjectMeta $meta ): bool {
			/**
			 * Keep an unreferenced object out of the orphan report.
			 *
			 * @param bool       $keep Whether to keep the object.
			 * @param string     $key  Object key.
			 * @param ObjectMeta $meta Size, ETag and LastModified.
			 */
			return (bool) apply_filters( 'wp_r2_offload_orphan_keep', false, $meta->key, $meta );
		};
		$is_ref = static function ( string $key ) use ( &$known ): bool {
			return isset( $known[ $key ] );
		};

		try {
			$report = $scanner->scan( $prefix, $is_ref, (int) round( $hours * 3600 ), time(), $limit, $keep );
		} catch ( S3Exception $e ) {
			WP_CLI::error( 'Listing the bucket failed: ' . $e->getMessage() );
			return;
		}

		$rows = array();
		foreach ( $report->orphans as $meta ) {
			$rows[] = array(
				'key'           => $meta->key,
				'size'          => $meta->size,
				'last_modified' => $meta->last_modified,
			);
		}
		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $rows ) );
		} elseif ( array() !== $rows ) {
			WP_CLI\Utils\format_items( $format, $rows, array( 'key', 'size', 'last_modified' ) );
		}

		$summary = sprintf(
			'Scanned %1$d object(s) under "%2$s": %3$d referenced, %4$d newer than %5$s hour(s), %6$d kept, %7$d orphaned (%8$s).%9$s',
			$report->scanned,
			$prefix,
			$report->referenced,
			$report->recent,
			(string) $hours,
			$report->kept,
			count( $report->orphans ),
			self::bytes( $report->orphan_bytes() ),
			$report->truncated ? ' Stopped at --limit.' : ''
		);
		if ( array() === $report->orphans ) {
			WP_CLI::success( $summary );
			return;
		}
		if ( ! $delete ) {
			WP_CLI::success( $summary . ' Run with --delete to remove them.' );
			return;
		}

		WP_CLI::log( $summary );
		WP_CLI::confirm( sprintf( 'Delete %1$d object(s) from bucket "%2$s"? This cannot be undone.', count( $report->orphans ), $bucket ), $assoc_args );

		// Read the records again: an attachment may have been offloaded meanwhile.
		$known = $this->referenced_keys();
		try {
			$result = $scanner->delete( $report, $is_ref );
		} catch ( S3Exception $e ) {
			WP_CLI::error( 'Deleting failed: ' . $e->getMessage() );
			return;
		}
		foreach ( $result['skipped'] as $key ) {
			WP_CLI::log( sprintf( 'Kept %s: it belongs to an attachment now.', $key ) );
		}
		foreach ( $result['failed'] as $key => $error ) {
			WP_CLI::warning( sprintf( 'Could not delete %1$s: %2$s', $key, $error ) );
		}
		$bytes = 0;
		foreach ( $result['deleted'] as $key ) {
			$bytes += $report->orphans[ $key ]->size;
		}
		$message = sprintf( 'Deleted %1$d orphaned object(s) (%2$s).', count( $result['deleted'] ), self::bytes( $bytes ) );
		if ( array() !== $result['failed'] ) {
			WP_CLI::error( $message . sprintf( ' %d could not be deleted.', count( $result['failed'] ) ) );
			return;
		}
		WP_CLI::success( $message );
	}

	/**
	 * Why the key prefix may contain objects of others, or "".
	 *
	 * @param string $prefix Resolved prefix.
	 * @param string $configured Configured prefix (may contain {site_id}).
	 */
	private static function shared_prefix_reason( string $prefix, string $configured ): string {
		if ( '' === $prefix ) {
			return 'The object key prefix is empty, so the whole bucket is listed.';
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() && false === strpos( $configured, '{site_id}' ) ) {
			return 'This is a multisite network and the key prefix has no {site_id}, so all sites share it.';
		}

		return '';
	}

	/**
	 * Keys of all offloaded files in a bucket, as a set.
	 *
	 * Records that name another bucket count too: a bucket copied to a new
	 * name keeps the keys, and treating them as orphans would be unsafe.
	 *
	 * @return array<string,true>
	 */
	private function referenced_keys(): array {
		$keys = array();
		foreach ( $this->offloaded_ids( array() ) as $id ) {
			$record = AttachmentOffloader::record( $id );
			if ( null !== $record ) {
				foreach ( $record->files as $key ) {
					$keys[ $key ] = true;
				}
			}
		}

		return $keys;
	}

	/**
	 * Download offloaded files back to the server (before deactivating the
	 * plugin, or for tools that need local files).
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : All offloaded attachments.
	 *
	 * @param string[]             $args Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function restore( $args, $assoc_args ): void {
		$ids = array_map( 'intval', (array) $args );
		if ( array() === $ids && empty( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Pass attachment IDs or --all.' );
			return;
		}
		$files = 0;
		foreach ( $this->offloaded_ids( $ids ) as $id ) {
			try {
				$files += $this->services->offloader()->restore_local( $id );
			} catch ( Throwable $e ) {
				WP_CLI::warning( sprintf( '#%1$d: %2$s', $id, $e->getMessage() ) );
			}
		}
		WP_CLI::success( sprintf( '%d file(s) downloaded.', $files ) );
	}

	/**
	 * Given ids, or all offloaded ids.
	 *
	 * @param int[] $ids Ids.
	 * @return iterable<int>
	 */
	private function offloaded_ids( array $ids ): iterable {
		if ( array() !== $ids ) {
			foreach ( $ids as $id ) {
				yield $id;
			}
			return;
		}
		$cursor = 0;
		do {
			$page = $this->source->offloaded_ids_after( $cursor, 200 );
			foreach ( $page as $id ) {
				$cursor = $id;
				yield $id;
			}
		} while ( array() !== $page );
	}

	/**
	 * Human-readable size.
	 *
	 * @param int $bytes Bytes.
	 */
	private static function bytes( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		$value = (float) $bytes;
		while ( $value >= 1024 && $i < 4 ) {
			$value /= 1024;
			++$i;
		}

		return ( 0 === $i ? (string) $bytes : number_format( $value, 1 ) ) . ' ' . $units[ $i ];
	}
}
