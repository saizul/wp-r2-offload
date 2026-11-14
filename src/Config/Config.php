<?php
/**
 * Resolved configuration.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Config;

use InvalidArgumentException;
use Saizul\R2Offload\S3\R2Endpoint;

/**
 * Settings after constants, stored options and defaults were merged.
 */
final class Config {

	/**
	 * Field definitions: key => [type, default, secret].
	 * Types: text, url, bool, int, select.
	 *
	 * @var array<string,array{0:string,1:string|int|bool,2:bool}>
	 */
	public const FIELDS = array(
		'account_id'          => array( 'text', '', false ),
		'access_key_id'       => array( 'text', '', true ),
		'secret_access_key'   => array( 'text', '', true ),
		'bucket'              => array( 'text', '', false ),
		'jurisdiction'        => array( 'select', '', false ),
		'endpoint'            => array( 'url', '', false ),
		'public_url'          => array( 'url', '', false ),
		'key_prefix'          => array( 'text', 'wp-content/uploads/', false ),
		'enabled'             => array( 'bool', true, false ),
		'rewrite_urls'        => array( 'bool', true, false ),
		'remove_local'        => array( 'bool', false, false ),
		'cache_control'       => array( 'text', 'public, max-age=31536000, immutable', false ),
		'multipart_threshold' => array( 'int', 64, false ),
		'part_size'           => array( 'int', 16, false ),
	);

	/**
	 * Values.
	 *
	 * @var array<string,string|int|bool>
	 */
	private $values;

	/**
	 * Where each value came from: constant, option or default.
	 *
	 * @var array<string,string>
	 */
	private $sources;

	/**
	 * Constructor.
	 *
	 * @param array<string,string|int|bool> $values Values.
	 * @param array<string,string>          $sources Sources.
	 */
	public function __construct( array $values, array $sources = array() ) {
		$this->values  = array();
		$this->sources = array();
		foreach ( self::FIELDS as $key => $field ) {
			$this->values[ $key ]  = array_key_exists( $key, $values ) ? self::cast( $field[0], $values[ $key ] ) : $field[1];
			$this->sources[ $key ] = $sources[ $key ] ?? ( array_key_exists( $key, $values ) ? 'option' : 'default' );
		}
	}

	/**
	 * Name of the wp-config.php constant for a field.
	 *
	 * @param string $key Field.
	 */
	public static function constant_name( string $key ): string {
		return 'WP_R2_OFFLOAD_' . strtoupper( $key );
	}

	/**
	 * Cast a raw value to a field type.
	 *
	 * @param string $type Type.
	 * @param mixed  $value Value.
	 * @return string|int|bool
	 */
	public static function cast( string $type, $value ) {
		switch ( $type ) {
			case 'bool':
				if ( is_string( $value ) ) {
					return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
				}
				return (bool) $value;
			case 'int':
				return (int) $value;
			default:
				return trim( (string) $value );
		}
	}

	/**
	 * A string value.
	 *
	 * @param string $key Field.
	 */
	public function get( string $key ): string {
		return (string) ( $this->values[ $key ] ?? '' );
	}

	/**
	 * A boolean value.
	 *
	 * @param string $key Field.
	 */
	public function flag( string $key ): bool {
		return (bool) ( $this->values[ $key ] ?? false );
	}

	/**
	 * Source of a value: constant, option or default.
	 *
	 * @param string $key Field.
	 */
	public function source( string $key ): string {
		return $this->sources[ $key ] ?? 'default';
	}

	/**
	 * Multipart threshold in bytes (8 MiB to 5 GiB; default 64 MiB).
	 */
	public function multipart_threshold(): int {
		return max( 8, min( 5120, (int) $this->values['multipart_threshold'] ) ) * 1048576;
	}

	/**
	 * Preferred part size in bytes (5 MiB to 512 MiB; default 16 MiB).
	 */
	public function part_size(): int {
		return max( 5, min( 512, (int) $this->values['part_size'] ) ) * 1048576;
	}

	/**
	 * Problems that prevent offloading.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		$errors = array();
		if ( '' === $this->get( 'endpoint' ) && ! R2Endpoint::is_valid_account_id( $this->get( 'account_id' ) ) ) {
			$errors[] = 'The Cloudflare account ID must be 32 hexadecimal characters.';
		}
		if ( '' === $this->get( 'access_key_id' ) || '' === $this->get( 'secret_access_key' ) ) {
			$errors[] = 'The R2 access key ID and secret access key are required.';
		}
		if ( ! R2Endpoint::is_valid_bucket( $this->get( 'bucket' ) ) ) {
			$errors[] = 'The bucket name is missing or invalid.';
		}
		if ( ! in_array( $this->get( 'jurisdiction' ), R2Endpoint::JURISDICTIONS, true ) ) {
			$errors[] = 'The jurisdiction must be empty, "eu" or "fedramp".';
		}
		if ( $this->flag( 'rewrite_urls' ) && ! preg_match( '#^https://[^\s/]+#i', $this->get( 'public_url' ) ) ) {
			$errors[] = 'A public https:// URL (custom domain or r2.dev URL) is required to rewrite media URLs.';
		}
		if ( $this->flag( 'remove_local' ) && ! $this->flag( 'rewrite_urls' ) ) {
			$errors[] = 'Local files can only be removed when media URLs are rewritten to the bucket.';
		}

		return $errors;
	}

	/**
	 * Whether uploads can be offloaded.
	 */
	public function is_ready(): bool {
		return array() === $this->errors();
	}

	/**
	 * Endpoint for the configured account and bucket.
	 *
	 * @throws InvalidArgumentException For invalid values.
	 */
	public function endpoint(): R2Endpoint {
		return new R2Endpoint( $this->get( 'account_id' ), $this->get( 'bucket' ), $this->get( 'jurisdiction' ), $this->get( 'endpoint' ) );
	}

	/**
	 * Values safe to display (secrets masked).
	 *
	 * @return array<string,string>
	 */
	public function describe(): array {
		$out = array();
		foreach ( self::FIELDS as $key => $field ) {
			$value = $this->values[ $key ];
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}
			$value = (string) $value;
			if ( $field[2] && '' !== $value ) {
				$value = 'secret_access_key' === $key ? '********' : substr( $value, 0, 4 ) . str_repeat( '*', max( 0, strlen( $value ) - 4 ) );
			}
			$out[ $key ] = $value;
		}

		return $out;
	}
}
