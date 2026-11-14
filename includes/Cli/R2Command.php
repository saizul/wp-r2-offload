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
use Saizul\R2Offload\Verify\Verifier;
use Saizul\R2Offload\WP\Bulk\WpAttachmentSource;
use Saizul\R2Offload\WP\Media\AttachmentOffloader;
use Saizul\R2Offload\WP\Services;
use Throwable;
use WP_CLI;

/**
 * Bulk offload, status, verification and restore of the media library.
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
