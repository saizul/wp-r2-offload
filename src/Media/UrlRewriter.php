<?php
/**
 * Local to bucket URL mapping.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Media;

/**
 * Rewrites upload URLs to the public bucket URL (custom domain or r2.dev).
 *
 * Only files listed in the attachment's {@see OffloadRecord} are rewritten,
 * so sizes that were never uploaded keep working from the server.
 */
final class UrlRewriter {

	/**
	 * Local uploads base URL without trailing slash.
	 *
	 * @var string
	 */
	private $local_base;

	/**
	 * Public base URL without trailing slash.
	 *
	 * @var string
	 */
	private $remote_base;

	/**
	 * Constructor.
	 *
	 * @param string $local_base Uploads base URL (wp_upload_dir()['baseurl']).
	 * @param string $remote_base Public bucket URL, such as https://media.example.com.
	 */
	public function __construct( string $local_base, string $remote_base ) {
		$this->local_base  = rtrim( $local_base, '/' );
		$this->remote_base = rtrim( $remote_base, '/' );
	}

	/**
	 * Public URL of an object key.
	 *
	 * @param string $key Key.
	 */
	public function url_for_key( string $key ): string {
		return $this->remote_base . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	}

	/**
	 * Relative upload path of a local URL, or null when it is not an upload URL.
	 * Scheme differences (http/https, protocol-relative) are ignored; query
	 * strings and fragments are dropped.
	 *
	 * @param string $url URL.
	 */
	public function relative_path( string $url ): ?string {
		$local = self::strip_scheme( $this->local_base ) . '/';
		$bare  = self::strip_scheme( $url );
		if ( 0 !== strpos( $bare, $local ) ) {
			return null;
		}
		$path = (string) substr( $bare, strlen( $local ) );
		$path = (string) preg_replace( '/[?#].*$/', '', $path );

		return '' === $path ? null : rawurldecode( $path );
	}

	/**
	 * Rewrite one URL if its file is offloaded.
	 *
	 * @param string        $url URL.
	 * @param OffloadRecord $record Record.
	 */
	public function rewrite( string $url, OffloadRecord $record ): string {
		$relative = $this->relative_path( $url );
		if ( null === $relative ) {
			return $url;
		}
		$key = $record->key( $relative );

		return null === $key ? $url : $this->url_for_key( $key );
	}

	/**
	 * Rewrite the sources of wp_calculate_image_srcset().
	 *
	 * @param array<int|string,array<string,mixed>> $sources Sources.
	 * @param OffloadRecord                         $record Record.
	 * @return array<int|string,array<string,mixed>>
	 */
	public function rewrite_srcset( array $sources, OffloadRecord $record ): array {
		foreach ( $sources as $width => $source ) {
			if ( is_array( $source ) && isset( $source['url'] ) && is_string( $source['url'] ) ) {
				$sources[ $width ]['url'] = $this->rewrite( $source['url'], $record );
			}
		}

		return $sources;
	}

	/**
	 * Rewrite upload URLs in HTML.
	 *
	 * @param string                          $html HTML.
	 * @param callable(string):?OffloadRecord $record_for Returns the record of the attachment owning a relative path, or null.
	 */
	public function rewrite_html( string $html, callable $record_for ): string {
		$local   = preg_quote( self::strip_scheme( $this->local_base ), '#' );
		$pattern = '#(?:https?:)?//' . $local . '/([^\s"\'<>()?\#,]+)#i';

		return (string) preg_replace_callback(
			$pattern,
			function ( array $m ) use ( $record_for ): string {
				$relative = rawurldecode( $m[1] );
				$record   = $record_for( $relative );
				if ( null === $record ) {
					return $m[0];
				}
				$key = $record->key( $relative );

				return null === $key ? $m[0] : $this->url_for_key( $key );
			},
			$html
		);
	}

	/**
	 * "https://example.com/x" becomes "example.com/x".
	 *
	 * @param string $url URL.
	 */
	private static function strip_scheme( string $url ): string {
		return (string) preg_replace( '#^(?:https?:)?//#i', '', $url );
	}
}
