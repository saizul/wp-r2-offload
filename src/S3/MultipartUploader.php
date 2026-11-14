<?php
/**
 * Multipart uploads of large files.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\S3;

use RuntimeException;

/**
 * Uploads a file in parts and can resume an interrupted upload.
 *
 * R2 limits (developers.cloudflare.com/r2/objects/multipart-objects/): parts
 * of at least 5 MiB except the last, at most 5 GiB, at most 10,000 parts, and
 * all parts except the last must have the same size. Incomplete uploads are
 * aborted by R2 after 7 days by default.
 */
final class MultipartUploader {

	public const MIN_PART  = 5242880;
	public const MAX_PART  = 5368709120;
	public const MAX_PARTS = 10000;

	/**
	 * Client.
	 *
	 * @var S3Client
	 */
	private $client;

	/**
	 * Preferred part size.
	 *
	 * @var int
	 */
	private $preferred_part_size;

	/**
	 * Constructor.
	 *
	 * @param S3Client $client Client.
	 * @param int      $preferred_part_size Preferred part size in bytes (default 16 MiB).
	 */
	public function __construct( S3Client $client, int $preferred_part_size = 16777216 ) {
		$this->client              = $client;
		$this->preferred_part_size = $preferred_part_size;
	}

	/**
	 * Part size for a file: the preferred size, raised so the file fits in
	 * 10,000 parts, rounded up to whole MiB, and within the 5 MiB - 5 GiB range.
	 *
	 * @param int $file_size File size.
	 * @param int $preferred Preferred part size.
	 */
	public static function part_size( int $file_size, int $preferred ): int {
		$size = max( self::MIN_PART, $preferred, (int) ceil( $file_size / self::MAX_PARTS ) );
		$size = (int) ( ceil( $size / 1048576 ) * 1048576 );

		return min( self::MAX_PART, $size );
	}

	/**
	 * Upload a file.
	 *
	 * @param string                             $key Key.
	 * @param string                             $path Local file.
	 * @param string                             $content_type MIME type.
	 * @param string                             $cache_control Cache-Control or "".
	 * @param MultipartState|null                $state State of an earlier attempt to resume.
	 * @param callable(MultipartState):void|null $on_progress Called after each part (persist the state there).
	 * @return string ETag of the object.
	 * @throws MultipartException When a part or the completion fails; it carries the state for resuming.
	 * @throws RuntimeException When the file cannot be read.
	 */
	public function upload( string $key, string $path, string $content_type, string $cache_control = '', ?MultipartState $state = null, ?callable $on_progress = null ): string {
		$size  = self::file_size( $path );
		$mtime = (int) filemtime( $path );
		if ( null === $state || ! $state->matches( $key, $size, $mtime ) ) {
			if ( null !== $state && '' !== $state->upload_id ) {
				$this->abort( $state );
			}
			$part_size = self::part_size( $size, $this->preferred_part_size );
			$state     = new MultipartState( $key, $this->client->create_multipart_upload( $key, $content_type, $cache_control ), $part_size, $size, $mtime );
			if ( null !== $on_progress ) {
				$on_progress( $state );
			}
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads large files in parts.
		if ( false === $handle ) {
			throw new RuntimeException( sprintf( 'Cannot read %s.', basename( $path ) ) );
		}
		try {
			for ( $number = 1, $count = $state->part_count(); $number <= $count; $number++ ) {
				if ( isset( $state->parts[ $number ] ) ) {
					continue;
				}
				fseek( $handle, ( $number - 1 ) * $state->part_size );
				$body = (string) fread( $handle, $state->part_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Part of a large file.
				try {
					$state->parts[ $number ] = $this->client->upload_part( $key, $state->upload_id, $number, $body );
				} catch ( S3Exception $e ) {
					throw new MultipartException( $e, $state );
				}
				unset( $body );
				if ( null !== $on_progress ) {
					$on_progress( $state );
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with fopen above.
		}

		try {
			return $this->client->complete_multipart_upload( $key, $state->upload_id, $state->parts );
		} catch ( S3Exception $e ) {
			throw new MultipartException( $e, $state );
		}
	}

	/**
	 * Abort an upload and forget its parts.
	 *
	 * @param MultipartState $state State.
	 */
	public function abort( MultipartState $state ): void {
		try {
			$this->client->abort_multipart_upload( $state->key, $state->upload_id );
		} catch ( S3Exception $e ) {
			// R2 aborts stale uploads on its own after 7 days.
			unset( $e );
		}
	}

	/**
	 * File size, also for files above 2 GiB on 32-bit builds where possible.
	 *
	 * @param string $path Path.
	 * @throws RuntimeException When the file is missing.
	 */
	private static function file_size( string $path ): int {
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( sprintf( 'File %s does not exist.', basename( $path ) ) );
		}
		clearstatcache( true, $path );

		return (int) filesize( $path );
	}
}
