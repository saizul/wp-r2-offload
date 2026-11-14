<?php
/**
 * Uploads, restores and deletes the files of an attachment.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\WP\Media;

use RuntimeException;
use Saizul\R2Offload\Bulk\OffloadResult;
use Saizul\R2Offload\Bulk\Offloader;
use Saizul\R2Offload\Media\AttachmentFiles;
use Saizul\R2Offload\Media\FileRef;
use Saizul\R2Offload\Media\MimeTypes;
use Saizul\R2Offload\Media\OffloadRecord;
use Saizul\R2Offload\S3\MultipartException;
use Saizul\R2Offload\S3\MultipartState;
use Saizul\R2Offload\S3\S3Exception;
use Saizul\R2Offload\WP\Services;
use Throwable;

/**
 * Attachment-level operations used by the media hooks and WP-CLI.
 */
final class AttachmentOffloader implements Offloader {

	public const META           = '_wp_r2_offload';
	public const META_ERROR     = '_wp_r2_offload_error';
	public const META_MULTIPART = '_wp_r2_offload_multipart';

	/**
	 * Services.
	 *
	 * @var Services
	 */
	private $services;

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( Services $services ) {
		$this->services = $services;
	}

	/**
	 * Offload record of an attachment, or null.
	 *
	 * @param int $attachment_id Attachment.
	 */
	public static function record( int $attachment_id ): ?OffloadRecord {
		return OffloadRecord::from_array( get_post_meta( $attachment_id, self::META, true ) );
	}

	/**
	 * Files of an attachment.
	 *
	 * @param int                      $attachment_id Attachment.
	 * @param array<string,mixed>|null $metadata Metadata to use instead of the stored one.
	 * @return FileRef[]
	 */
	public function files( int $attachment_id, ?array $metadata = null ): array {
		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $attached ) {
			return array();
		}
		$metadata = $metadata ?? wp_get_attachment_metadata( $attachment_id );
		$backups  = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );

		return ( new AttachmentFiles() )->collect( $attached, is_array( $metadata ) ? $metadata : array(), is_array( $backups ) ? $backups : array(), self::basedir() );
	}

	/**
	 * Upload the files that are not in the bucket yet.
	 *
	 * @param int  $attachment_id Attachment.
	 * @param bool $dry_run Only report.
	 */
	public function offload( int $attachment_id, bool $dry_run = false ): OffloadResult {
		return $this->offload_files( $attachment_id, null, $dry_run, true );
	}

	/**
	 * Upload files, optionally with metadata that is not stored yet.
	 *
	 * @param int                      $attachment_id Attachment.
	 * @param array<string,mixed>|null $metadata Metadata override.
	 * @param bool                     $dry_run Only report.
	 * @param bool                     $allow_remove Remove local copies when configured.
	 */
	public function offload_files( int $attachment_id, ?array $metadata, bool $dry_run = false, bool $allow_remove = true ): OffloadResult {
		$config = $this->services->settings->config();
		if ( ! $config->is_ready() ) {
			return new OffloadResult( OffloadResult::FAILED, implode( ' ', $config->errors() ) );
		}

		/**
		 * Filters whether an attachment is offloaded.
		 *
		 * @param bool $offload       Default true.
		 * @param int  $attachment_id Attachment.
		 */
		if ( ! apply_filters( 'wp_r2_offload_should_offload', true, $attachment_id ) ) {
			return new OffloadResult( OffloadResult::SKIPPED, 'Excluded by the wp_r2_offload_should_offload filter.' );
		}

		$files = $this->files( $attachment_id, $metadata );
		if ( array() === $files ) {
			return new OffloadResult( OffloadResult::SKIPPED, 'No file attached.' );
		}

		$bucket = $config->get( 'bucket' );
		$record = self::record( $attachment_id );
		if ( null === $record || $record->bucket !== $bucket ) {
			$record = new OffloadRecord( $bucket );
		}

		$basedir = self::basedir();
		$todo    = array();
		foreach ( $files as $file ) {
			if ( ! $record->has( $file->relative ) && is_file( $file->path( $basedir ) ) ) {
				$todo[] = $file;
			}
		}

		if ( array() === $todo ) {
			if ( array() === $record->files ) {
				return new OffloadResult( OffloadResult::SKIPPED, 'The files are not on this server.' );
			}
			if ( ! $dry_run && $allow_remove && $config->flag( 'remove_local' ) && ! $record->local_removed ) {
				$this->remove_local( $attachment_id, $record, $files );
			}
			return new OffloadResult( OffloadResult::SKIPPED, 'Already offloaded.' );
		}

		if ( $dry_run ) {
			$bytes = 0;
			foreach ( $todo as $file ) {
				$bytes += (int) filesize( $file->path( $basedir ) );
			}
			return new OffloadResult( OffloadResult::OFFLOADED, sprintf( 'Would upload %d file(s).', count( $todo ) ), count( $todo ), $bytes );
		}

		$keys   = $this->services->keys();
		$bytes  = 0;
		$done   = 0;
		$errors = array();
		foreach ( $todo as $file ) {
			$path = $file->path( $basedir );
			try {
				$key  = $keys->key( $file->relative );
				$size = $this->upload( $attachment_id, $key, $path );
				$record->add( $file->relative, $key, $size );
				$bytes += $size;
				++$done;
			} catch ( Throwable $e ) {
				$errors[] = $file->relative . ': ' . $e->getMessage();
			}
		}

		$record->offloaded_at = time();
		update_post_meta( $attachment_id, self::META, $record->to_array() );

		if ( array() !== $errors ) {
			update_post_meta( $attachment_id, self::META_ERROR, implode( "\n", $errors ) );
			return new OffloadResult( OffloadResult::FAILED, implode( '; ', $errors ), $done, $bytes );
		}
		delete_post_meta( $attachment_id, self::META_ERROR );

		if ( $allow_remove && $config->flag( 'remove_local' ) ) {
			$this->remove_local( $attachment_id, $record, $files );
		}

		/**
		 * Fires after files of an attachment were uploaded.
		 *
		 * @param int           $attachment_id Attachment.
		 * @param OffloadRecord $record        Record.
		 */
		do_action( 'wp_r2_offload_attachment_offloaded', $attachment_id, $record );

		return new OffloadResult( OffloadResult::OFFLOADED, sprintf( 'Uploaded %d file(s).', $done ), $done, $bytes );
	}

	/**
	 * Upload one file, using multipart above the threshold. Upload errors
	 * surface as S3Exception (multipart failures are rethrown as their S3 error
	 * after the resume state was saved).
	 *
	 * @param int    $attachment_id Attachment (for multipart resume state).
	 * @param string $key Key.
	 * @param string $path Local file.
	 * @return int Bytes uploaded.
	 * @throws RuntimeException When the file cannot be read.
	 */
	private function upload( int $attachment_id, string $key, string $path ): int {
		$config = $this->services->settings->config();
		$size   = (int) filesize( $path );
		$type   = self::content_type( $path );
		$cache  = $config->get( 'cache_control' );

		if ( $size < $config->multipart_threshold() ) {
			$body = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local upload file.
			if ( false === $body ) {
				throw new RuntimeException( 'Cannot read the file.' );
			}
			$this->services->client()->put_object( $key, $body, $type, $cache );
			return $size;
		}

		$states = get_post_meta( $attachment_id, self::META_MULTIPART, true );
		$states = is_array( $states ) ? $states : array();
		$state  = isset( $states[ $key ] ) && is_array( $states[ $key ] ) ? MultipartState::from_array( $states[ $key ] ) : null;
		$save   = static function ( MultipartState $state ) use ( $attachment_id, $key ): void {
			$all         = get_post_meta( $attachment_id, AttachmentOffloader::META_MULTIPART, true );
			$all         = is_array( $all ) ? $all : array();
			$all[ $key ] = $state->to_array();
			update_post_meta( $attachment_id, AttachmentOffloader::META_MULTIPART, $all );
		};

		try {
			$this->services->uploader()->upload( $key, $path, $type, $cache, $state, $save );
		} catch ( MultipartException $e ) {
			$save( $e->state() );
			$error = $e->s3_error();
			throw $error;
		}

		$all = get_post_meta( $attachment_id, self::META_MULTIPART, true );
		if ( is_array( $all ) ) {
			unset( $all[ $key ] );
			if ( array() === $all ) {
				delete_post_meta( $attachment_id, self::META_MULTIPART );
			} else {
				update_post_meta( $attachment_id, self::META_MULTIPART, $all );
			}
		}

		return $size;
	}

	/**
	 * Delete local copies of files that are in the bucket.
	 *
	 * @param int           $attachment_id Attachment.
	 * @param OffloadRecord $record Record (updated and saved).
	 * @param FileRef[]     $files Files.
	 */
	public function remove_local( int $attachment_id, OffloadRecord $record, array $files ): void {
		$basedir = self::basedir();
		$all     = true;
		foreach ( $files as $file ) {
			if ( ! $record->has( $file->relative ) ) {
				$all = false;
				continue;
			}
			$path = $file->path( $basedir );
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$record->local_removed = $all;
		update_post_meta( $attachment_id, self::META, $record->to_array() );
	}

	/**
	 * Download files that are missing locally.
	 *
	 * @param int  $attachment_id Attachment.
	 * @param bool $main_only Only the main file and the unscaled original (image editing).
	 * @return int Files downloaded.
	 * @throws S3Exception On download errors.
	 */
	public function restore_local( int $attachment_id, bool $main_only = false ): int {
		$record = self::record( $attachment_id );
		if ( null === $record ) {
			return 0;
		}
		$basedir = self::basedir();
		$count   = 0;
		foreach ( $this->files( $attachment_id ) as $file ) {
			if ( $main_only && ! in_array( $file->role, array( 'full', 'original_image' ), true ) ) {
				continue;
			}
			$key  = $record->key( $file->relative );
			$path = $file->path( $basedir );
			if ( null === $key || is_file( $path ) ) {
				continue;
			}
			wp_mkdir_p( dirname( $path ) );
			if ( $this->services->client()->get_object_to_file( $key, $path ) ) {
				++$count;
			}
		}
		if ( ! $main_only ) {
			$record->local_removed = false;
			update_post_meta( $attachment_id, self::META, $record->to_array() );
		}

		return $count;
	}

	/**
	 * Delete the objects of an attachment and forget the record.
	 *
	 * @param int $attachment_id Attachment.
	 * @return array<string,string> Keys that could not be deleted.
	 * @throws S3Exception When the request fails.
	 */
	public function delete_remote( int $attachment_id ): array {
		$record = self::record( $attachment_id );
		if ( null === $record || array() === $record->files ) {
			return array();
		}
		$failed = $this->services->client()->delete_objects( $record->keys() );
		if ( array() === $failed ) {
			delete_post_meta( $attachment_id, self::META );
		}

		return $failed;
	}

	/**
	 * Uploads base directory.
	 */
	public static function basedir(): string {
		$uploads = wp_get_upload_dir();

		return (string) $uploads['basedir'];
	}

	/**
	 * Content type of a file.
	 *
	 * @param string $path Path.
	 */
	private static function content_type( string $path ): string {
		$type = wp_check_filetype( basename( $path ) );

		return ! empty( $type['type'] ) ? (string) $type['type'] : MimeTypes::for_file( $path );
	}
}
