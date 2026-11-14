<?php
/**
 * Lists the files of an attachment.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Media;

/**
 * Works out every file WordPress keeps for an attachment from its stored data:
 * - the attached file (`_wp_attached_file`), which may be the "-scaled" copy;
 * - `original_image`, the unscaled upload (WordPress 5.3+);
 * - every entry in `sizes`;
 * - alternative formats in `sources` (WebP/AVIF, also per size);
 * - files kept by the image editor (`_wp_attachment_backup_sizes`).
 */
final class AttachmentFiles {

	/**
	 * Collect files.
	 *
	 * @param string              $attached_file `_wp_attached_file` (relative), or an absolute path inside $basedir.
	 * @param array<string,mixed> $metadata Attachment metadata.
	 * @param array<string,mixed> $backup_sizes `_wp_attachment_backup_sizes`.
	 * @param string              $basedir Uploads base directory (to relativise absolute paths).
	 * @return FileRef[] Unique by relative path, the main file first.
	 */
	public function collect( string $attached_file, array $metadata, array $backup_sizes = array(), string $basedir = '' ): array {
		$main = self::relative( $attached_file, $basedir );
		if ( '' === $main ) {
			return array();
		}
		$dir   = self::dir( $main );
		$files = array( $main => new FileRef( $main, 'full' ) );

		$add = static function ( $file, string $role ) use ( &$files, $dir ): void {
			if ( ! is_string( $file ) || '' === $file || false !== strpos( $file, '..' ) ) {
				return;
			}
			$relative = $dir . basename( str_replace( '\\', '/', $file ) );
			if ( ! isset( $files[ $relative ] ) ) {
				$files[ $relative ] = new FileRef( $relative, $role );
			}
		};

		if ( isset( $metadata['original_image'] ) ) {
			$add( $metadata['original_image'], 'original_image' );
		}
		foreach ( self::sources( $metadata ) as $mime => $file ) {
			$add( $file, 'source:' . $mime );
		}
		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $name => $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}
			$add( $size['file'] ?? null, (string) $name );
			foreach ( self::sources( $size ) as $mime => $file ) {
				$add( $file, (string) $name . ':' . $mime );
			}
		}
		foreach ( $backup_sizes as $name => $size ) {
			if ( is_array( $size ) ) {
				$add( $size['file'] ?? null, 'backup:' . $name );
			}
		}

		return array_values( $files );
	}

	/**
	 * Relative paths only.
	 *
	 * @param FileRef[] $files Files.
	 * @return string[]
	 */
	public static function relatives( array $files ): array {
		return array_map(
			static function ( FileRef $file ): string {
				return $file->relative;
			},
			$files
		);
	}

	/**
	 * Path relative to the uploads directory.
	 *
	 * @param string $file Relative or absolute path.
	 * @param string $basedir Uploads directory.
	 */
	public static function relative( string $file, string $basedir ): string {
		$file    = str_replace( '\\', '/', trim( $file ) );
		$basedir = rtrim( str_replace( '\\', '/', $basedir ), '/' );
		if ( '' !== $basedir && 0 === strpos( $file, $basedir . '/' ) ) {
			$file = substr( $file, strlen( $basedir ) + 1 );
		}
		$file = ltrim( $file, '/' );
		if ( '' === $file || preg_match( '#(^|/)\.\.(/|$)#', $file ) ) {
			return '';
		}

		return $file;
	}

	/**
	 * Directory part with trailing slash ("" for files in the root).
	 *
	 * @param string $relative Relative path.
	 */
	private static function dir( string $relative ): string {
		$pos = strrpos( $relative, '/' );

		return false === $pos ? '' : substr( $relative, 0, $pos + 1 );
	}

	/**
	 * Files listed under "sources".
	 *
	 * @param array<string,mixed> $data Metadata or a size entry.
	 * @return array<string,string> MIME => file.
	 */
	private static function sources( array $data ): array {
		$files = array();
		foreach ( isset( $data['sources'] ) && is_array( $data['sources'] ) ? $data['sources'] : array() as $mime => $source ) {
			if ( is_array( $source ) && isset( $source['file'] ) && is_string( $source['file'] ) ) {
				$files[ (string) $mime ] = $source['file'];
			}
		}

		return $files;
	}
}
