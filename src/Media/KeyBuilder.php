<?php
/**
 * Object keys for uploads.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Media;

use InvalidArgumentException;

/**
 * Maps "2026/11/photo.jpg" to an object key such as
 * "wp-content/uploads/2026/11/photo.jpg".
 *
 * The prefix may contain {site_id} (multisite).
 */
final class KeyBuilder {

	/**
	 * Normalised prefix ("" or ending with "/").
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $prefix Key prefix.
	 * @param int    $site_id Current site id for {site_id}.
	 */
	public function __construct( string $prefix = 'wp-content/uploads/', int $site_id = 1 ) {
		$this->prefix = self::normalize_prefix( str_replace( '{site_id}', (string) $site_id, $prefix ) );
	}

	/**
	 * Clean a prefix: no leading slash, single slashes, no dot segments, trailing slash.
	 *
	 * @param string $prefix Prefix.
	 */
	public static function normalize_prefix( string $prefix ): string {
		$segments = array_filter(
			explode( '/', str_replace( '\\', '/', $prefix ) ),
			static function ( string $segment ): bool {
				return '' !== $segment && '.' !== $segment && '..' !== $segment;
			}
		);
		$segments = array_map(
			static function ( string $segment ): string {
				return (string) preg_replace( '/[^A-Za-z0-9._\-{}]/', '-', $segment );
			},
			$segments
		);

		return array() === $segments ? '' : implode( '/', $segments ) . '/';
	}

	/**
	 * Prefix.
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Key for a relative upload path.
	 *
	 * @param string $relative Relative path.
	 * @throws InvalidArgumentException For empty paths or paths leaving the uploads directory.
	 */
	public function key( string $relative ): string {
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative || preg_match( '#(^|/)\.\.?(/|$)#', $relative ) ) {
			throw new InvalidArgumentException( 'Invalid upload path.' );
		}
		$key = $this->prefix . (string) preg_replace( '#/+#', '/', $relative );
		if ( strlen( $key ) > 1024 ) {
			throw new InvalidArgumentException( 'Object keys are limited to 1024 bytes.' );
		}

		return $key;
	}
}
