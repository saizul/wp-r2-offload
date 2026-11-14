<?php
/**
 * Merges constants, stored options and defaults.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Config;

/**
 * Resolves the configuration and prepares settings for storage.
 *
 * - A constant WP_R2_OFFLOAD_<FIELD> always wins and is never stored.
 * - Secrets (access key id and secret access key) entered on the settings
 *   screen are stored encrypted with {@see SecretBox}; an empty submission
 *   keeps the stored value.
 */
final class ConfigResolver {

	/**
	 * Constant lookup.
	 *
	 * @var callable(string):mixed
	 */
	private $constants;

	/**
	 * Encryption.
	 *
	 * @var SecretBox
	 */
	private $box;

	/**
	 * Constructor.
	 *
	 * @param callable(string):mixed $constants Returns a constant value or null.
	 * @param SecretBox              $box Encryption.
	 */
	public function __construct( callable $constants, SecretBox $box ) {
		$this->constants = $constants;
		$this->box       = $box;
	}

	/**
	 * Value of the constant for a field, or null.
	 *
	 * @param string $key Field.
	 * @return mixed
	 */
	public function constant( string $key ) {
		return ( $this->constants )( Config::constant_name( $key ) );
	}

	/**
	 * Build the configuration.
	 *
	 * @param array<string,mixed> $stored Stored option.
	 */
	public function resolve( array $stored ): Config {
		$values  = array();
		$sources = array();
		foreach ( Config::FIELDS as $key => $field ) {
			$constant = $this->constant( $key );
			if ( null !== $constant ) {
				$values[ $key ]  = $constant;
				$sources[ $key ] = 'constant';
				continue;
			}
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}
			$value = $stored[ $key ];
			if ( $field[2] ) {
				$value = $this->box->decrypt( (string) $value );
				if ( '' === $value ) {
					continue;
				}
			}
			$values[ $key ]  = $value;
			$sources[ $key ] = 'option';
		}

		return new Config( $values, $sources );
	}

	/**
	 * Clean submitted settings for storage.
	 *
	 * @param array<string,mixed> $input Submitted values (unslashed).
	 * @param array<string,mixed> $stored Stored option.
	 * @return array{settings:array<string,mixed>,errors:string[]}
	 */
	public function sanitize( array $input, array $stored ): array {
		$out    = array();
		$errors = array();
		foreach ( Config::FIELDS as $key => $field ) {
			list( $type, $default, $secret ) = $field;
			if ( null !== $this->constant( $key ) ) {
				continue;
			}
			if ( $secret ) {
				if ( ! empty( $input[ $key . '__clear' ] ) ) {
					continue;
				}
				$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
				if ( '' === $value ) {
					if ( isset( $stored[ $key ] ) && SecretBox::is_encrypted( (string) $stored[ $key ] ) ) {
						$out[ $key ] = (string) $stored[ $key ];
					}
					continue;
				}
				$out[ $key ] = $this->box->encrypt( (string) preg_replace( '/\s+/', '', $value ) );
				continue;
			}

			$raw = $input[ $key ] ?? ( 'bool' === $type ? false : $default );
			switch ( $type ) {
				case 'bool':
					$out[ $key ] = Config::cast( 'bool', $raw );
					break;
				case 'int':
					$out[ $key ] = max( 0, (int) $raw );
					break;
				case 'url':
					$url = rtrim( trim( (string) $raw ), '/' );
					if ( '' !== $url && ! preg_match( '#^https?://[^\s/$.?\#][^\s]*$#i', $url ) ) {
						$errors[] = sprintf( 'The %s is not a valid URL.', str_replace( '_', ' ', $key ) );
						$url      = '';
					}
					$out[ $key ] = $url;
					break;
				case 'select':
					$value       = strtolower( trim( (string) $raw ) );
					$out[ $key ] = in_array( $value, \Saizul\R2Offload\S3\R2Endpoint::JURISDICTIONS, true ) ? $value : '';
					break;
				default:
					$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', strip_tags( (string) $raw ) ) );
					if ( 'account_id' === $key ) {
						$value = strtolower( $value );
					}
					if ( 'bucket' === $key ) {
						$value = strtolower( $value );
					}
					if ( 'key_prefix' === $key ) {
						$value = \Saizul\R2Offload\Media\KeyBuilder::normalize_prefix( $value );
					}
					$out[ $key ] = $value;
			}
		}

		$check = $this->resolve( $out )->errors();

		return array(
			'settings' => $out,
			'errors'   => array_merge( $errors, $check ),
		);
	}
}
