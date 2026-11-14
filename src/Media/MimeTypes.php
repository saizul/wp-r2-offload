<?php
/**
 * Content types by extension.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Media;

/**
 * Fallback content types when WordPress cannot tell.
 */
final class MimeTypes {

	/**
	 * Extension => type.
	 *
	 * @var array<string,string>
	 */
	private const TYPES = array(
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'jpe'   => 'image/jpeg',
		'png'   => 'image/png',
		'gif'   => 'image/gif',
		'webp'  => 'image/webp',
		'avif'  => 'image/avif',
		'heic'  => 'image/heic',
		'svg'   => 'image/svg+xml',
		'ico'   => 'image/x-icon',
		'bmp'   => 'image/bmp',
		'tif'   => 'image/tiff',
		'tiff'  => 'image/tiff',
		'pdf'   => 'application/pdf',
		'zip'   => 'application/zip',
		'json'  => 'application/json',
		'txt'   => 'text/plain',
		'csv'   => 'text/csv',
		'css'   => 'text/css',
		'js'    => 'application/javascript',
		'mp4'   => 'video/mp4',
		'm4v'   => 'video/mp4',
		'mov'   => 'video/quicktime',
		'webm'  => 'video/webm',
		'ogv'   => 'video/ogg',
		'mp3'   => 'audio/mpeg',
		'm4a'   => 'audio/mp4',
		'ogg'   => 'audio/ogg',
		'wav'   => 'audio/wav',
		'woff'  => 'font/woff',
		'woff2' => 'font/woff2',
		'doc'   => 'application/msword',
		'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'   => 'application/vnd.ms-excel',
		'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt'   => 'application/vnd.ms-powerpoint',
		'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	);

	/**
	 * Type for a file name.
	 *
	 * @param string $file File name.
	 */
	public static function for_file( string $file ): string {
		$ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );

		return self::TYPES[ $ext ] ?? 'application/octet-stream';
	}
}
