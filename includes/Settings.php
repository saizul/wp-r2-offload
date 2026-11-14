<?php
/**
 * Stored settings and the resolved configuration.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\WP;

use Saizul\R2Offload\Config\Config;
use Saizul\R2Offload\Config\ConfigResolver;
use Saizul\R2Offload\Config\SecretBox;

/**
 * Reads the `wp_r2_offload_settings` option and the WP_R2_OFFLOAD_* constants.
 */
final class Settings {

	public const OPTION = 'wp_r2_offload_settings';

	/**
	 * Resolver.
	 *
	 * @var ConfigResolver
	 */
	private $resolver;

	/**
	 * Cached configuration.
	 *
	 * @var Config|null
	 */
	private $config = null;

	/**
	 * Constructor.
	 *
	 * @param callable(string):mixed|null $constants Constant lookup (tests).
	 * @param SecretBox|null              $box Encryption (tests).
	 */
	public function __construct( ?callable $constants = null, ?SecretBox $box = null ) {
		$constants      = $constants ?? static function ( string $name ) {
			return defined( $name ) ? constant( $name ) : null;
		};
		$this->resolver = new ConfigResolver( $constants, $box ?? new SecretBox( self::key_material( $constants ) ) );
	}

	/**
	 * Key material for stored secrets: WP_R2_OFFLOAD_ENCRYPTION_KEY or the site salts.
	 *
	 * @param callable(string):mixed $constants Constant lookup.
	 */
	private static function key_material( callable $constants ): string {
		$custom = $constants( 'WP_R2_OFFLOAD_ENCRYPTION_KEY' );
		if ( is_string( $custom ) && strlen( $custom ) >= 16 ) {
			return $custom;
		}
		$material = (string) $constants( 'AUTH_KEY' ) . (string) $constants( 'SECURE_AUTH_SALT' );
		if ( strlen( $material ) < 16 && function_exists( 'wp_salt' ) ) {
			$material .= wp_salt( 'secure_auth' );
		}

		return str_pad( $material, 16, '#' );
	}

	/**
	 * Resolver.
	 */
	public function resolver(): ConfigResolver {
		return $this->resolver;
	}

	/**
	 * Stored option.
	 *
	 * @return array<string,mixed>
	 */
	public function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Resolved configuration.
	 */
	public function config(): Config {
		if ( null === $this->config ) {
			$this->config = $this->resolver->resolve( $this->stored() );
		}

		return $this->config;
	}

	/**
	 * Save submitted values.
	 *
	 * @param array<string,mixed> $input Submitted values (unslashed).
	 * @return string[] Problems to show.
	 */
	public function save( array $input ): array {
		$result = $this->resolver->sanitize( $input, $this->stored() );
		update_option( self::OPTION, $result['settings'], false );
		$this->config = null;

		return $result['errors'];
	}

	/**
	 * Forget the cached configuration.
	 */
	public function flush(): void {
		$this->config = null;
	}
}
