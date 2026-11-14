<?php
/**
 * WordPress media integration.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\WP\Media;

use Saizul\R2Offload\Media\AttachmentFiles;
use Saizul\R2Offload\Media\OffloadRecord;
use Saizul\R2Offload\WP\Services;
use Throwable;

/**
 * Offloads new uploads and edits, deletes objects with their attachment,
 * rewrites URLs (attachment URLs, image src, srcset and post content) and
 * downloads originals again when an image is edited after its local copy
 * was removed.
 */
final class MediaHooks {

	/**
	 * Services.
	 *
	 * @var Services
	 */
	private $services;

	/**
	 * Attachments whose metadata is being generated right now.
	 *
	 * @var array<int,bool>
	 */
	private $generating = array();

	/**
	 * Records loaded in this request.
	 *
	 * @var array<int,OffloadRecord|null>
	 */
	private $records = array();

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( Services $services ) {
		$this->services = $services;
	}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		$config = $this->services->settings->config();

		if ( $config->flag( 'enabled' ) ) {
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_generate' ), 99, 2 );
			add_filter( 'wp_update_attachment_metadata', array( $this, 'on_update' ), 99, 2 );
			add_filter( 'wp_unique_filename', array( $this, 'unique_filename' ), 10, 3 );
		}
		add_action( 'delete_attachment', array( $this, 'on_delete' ), 10, 1 );
		add_filter( 'load_image_to_edit_path', array( $this, 'edit_path' ), 10, 2 );

		if ( $config->flag( 'rewrite_urls' ) && '' !== $config->get( 'public_url' ) ) {
			add_filter( 'wp_get_attachment_url', array( $this, 'attachment_url' ), 99, 2 );
			add_filter( 'wp_get_attachment_image_src', array( $this, 'image_src' ), 99, 2 );
			add_filter( 'wp_calculate_image_srcset', array( $this, 'srcset' ), 99, 5 );
			add_filter( 'the_content', array( $this, 'content' ), 100 );
		}
	}

	/**
	 * New upload or regeneration: upload every file, but keep local copies
	 * until the metadata is saved.
	 *
	 * @param array<string,mixed>|mixed $metadata Metadata.
	 * @param int                       $attachment_id Attachment.
	 * @return array<string,mixed>|mixed
	 */
	public function on_generate( $metadata, $attachment_id ) {
		$attachment_id                      = (int) $attachment_id;
		$this->generating[ $attachment_id ] = true;
		try {
			$this->services->offloader()->offload_files( $attachment_id, is_array( $metadata ) ? $metadata : array(), false, false );
		} catch ( Throwable $e ) {
			update_post_meta( $attachment_id, AttachmentOffloader::META_ERROR, $e->getMessage() );
		}
		unset( $this->generating[ $attachment_id ], $this->records[ $attachment_id ] );

		return $metadata;
	}

	/**
	 * Saved metadata (after uploads and image edits): upload new files and
	 * remove local copies when configured.
	 *
	 * @param array<string,mixed>|mixed $metadata Metadata.
	 * @param int                       $attachment_id Attachment.
	 * @return array<string,mixed>|mixed
	 */
	public function on_update( $metadata, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( isset( $this->generating[ $attachment_id ] ) || ! is_array( $metadata ) || null === AttachmentOffloader::record( $attachment_id ) ) {
			return $metadata;
		}
		try {
			$this->services->offloader()->offload_files( $attachment_id, $metadata );
		} catch ( Throwable $e ) {
			update_post_meta( $attachment_id, AttachmentOffloader::META_ERROR, $e->getMessage() );
		}
		unset( $this->records[ $attachment_id ] );

		return $metadata;
	}

	/**
	 * Delete objects together with the attachment.
	 *
	 * @param int $attachment_id Attachment.
	 */
	public function on_delete( $attachment_id ): void {
		/**
		 * Filters whether objects are deleted from the bucket with their attachment.
		 *
		 * @param bool $delete        Default true.
		 * @param int  $attachment_id Attachment.
		 */
		if ( ! apply_filters( 'wp_r2_offload_delete_remote', true, (int) $attachment_id ) ) {
			return;
		}
		try {
			$this->services->offloader()->delete_remote( (int) $attachment_id );
		} catch ( Throwable $e ) {
			// The attachment is deleted anyway; leftover objects can be found with `wp r2 verify`.
			unset( $e );
		}
	}

	/**
	 * Download the original before an image is edited when it is not on the server.
	 *
	 * @param string|false $path Path WordPress wants to load.
	 * @param int          $attachment_id Attachment.
	 * @return string|false
	 */
	public function edit_path( $path, $attachment_id ) {
		if ( is_string( $path ) && is_file( $path ) ) {
			return $path;
		}
		$local = get_attached_file( (int) $attachment_id );
		if ( ! is_string( $local ) || '' === $local || null === AttachmentOffloader::record( (int) $attachment_id ) ) {
			return $path;
		}
		try {
			$this->services->offloader()->restore_local( (int) $attachment_id, true );
		} catch ( Throwable $e ) {
			return $path;
		}

		return is_file( $local ) ? $local : $path;
	}

	/**
	 * Avoid reusing the name of an offloaded file whose local copy was removed:
	 * the new upload would overwrite the object in the bucket.
	 *
	 * @param string $filename File name.
	 * @param string $ext Extension with dot.
	 * @param string $dir Target directory.
	 */
	public function unique_filename( $filename, $ext, $dir ): string {
		$filename = (string) $filename;
		if ( ! $this->services->settings->config()->flag( 'remove_local' ) ) {
			return $filename;
		}
		$relative_dir = AttachmentFiles::relative( (string) $dir, AttachmentOffloader::basedir() );
		$prefix       = '' === $relative_dir ? '' : rtrim( $relative_dir, '/' ) . '/';
		$name         = pathinfo( $filename, PATHINFO_FILENAME );
		$extension    = pathinfo( $filename, PATHINFO_EXTENSION );
		$candidate    = $filename;
		$i            = 1;
		while ( $i < 1000 && self::attached_file_exists( $prefix . $candidate ) ) {
			$candidate = $name . '-' . $i . ( '' === $extension ? '' : '.' . $extension );
			++$i;
		}

		return $candidate;
	}

	/**
	 * Whether an attachment already uses a relative path.
	 *
	 * @param string $relative Relative path.
	 */
	private static function attached_file_exists( string $relative ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see uploads made a moment ago.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $relative ) );
	}

	/**
	 * Attachment URL.
	 *
	 * @param string $url URL.
	 * @param int    $attachment_id Attachment.
	 */
	public function attachment_url( $url, $attachment_id ): string {
		$record = $this->cached_record( (int) $attachment_id );

		return null === $record ? (string) $url : $this->services->rewriter()->rewrite( (string) $url, $record );
	}

	/**
	 * Image src array.
	 *
	 * @param array<int,mixed>|false $image [url, width, height, is_intermediate].
	 * @param int                    $attachment_id Attachment.
	 * @return array<int,mixed>|false
	 */
	public function image_src( $image, $attachment_id ) {
		if ( ! is_array( $image ) || ! isset( $image[0] ) ) {
			return $image;
		}
		$record = $this->cached_record( (int) $attachment_id );
		if ( null !== $record ) {
			$image[0] = $this->services->rewriter()->rewrite( (string) $image[0], $record );
		}

		return $image;
	}

	/**
	 * Srcset sources.
	 *
	 * @param array<int|string,array<string,mixed>>|mixed $sources Sources.
	 * @param mixed                                       $size_array Size.
	 * @param mixed                                       $image_src Src.
	 * @param mixed                                       $image_meta Metadata.
	 * @param int                                         $attachment_id Attachment.
	 * @return array<int|string,array<string,mixed>>|mixed
	 */
	public function srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id = 0 ) {
		unset( $size_array, $image_src, $image_meta );
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		$record = $this->cached_record( (int) $attachment_id );

		return null === $record ? $sources : $this->services->rewriter()->rewrite_srcset( $sources, $record );
	}

	/**
	 * Post content: rewrite upload URLs of images marked with wp-image-{id}.
	 *
	 * @param string $content Content.
	 */
	public function content( $content ): string {
		$content = (string) $content;
		if ( false === strpos( $content, 'wp-image-' ) || ! preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			return $content;
		}
		$by_path = array();
		foreach ( array_unique( array_map( 'intval', $matches[1] ) ) as $id ) {
			$record = $this->cached_record( $id );
			if ( null === $record ) {
				continue;
			}
			foreach ( array_keys( $record->files ) as $relative ) {
				$by_path[ $relative ] = $record;
			}
		}
		if ( array() === $by_path ) {
			return $content;
		}

		return $this->services->rewriter()->rewrite_html(
			$content,
			static function ( string $relative ) use ( $by_path ): ?OffloadRecord {
				return $by_path[ $relative ] ?? null;
			}
		);
	}

	/**
	 * Record with per-request cache.
	 *
	 * @param int $attachment_id Attachment.
	 */
	private function cached_record( int $attachment_id ): ?OffloadRecord {
		if ( $attachment_id <= 0 ) {
			return null;
		}
		if ( ! array_key_exists( $attachment_id, $this->records ) ) {
			$this->records[ $attachment_id ] = AttachmentOffloader::record( $attachment_id );
		}

		return $this->records[ $attachment_id ];
	}
}
