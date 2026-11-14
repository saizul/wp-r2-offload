<?php
/**
 * Encryption of stored secrets.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Config;

use InvalidArgumentException;
use RuntimeException;

/**
 * Encrypts credentials entered on the settings screen before they are
 * stored in the options table, with a key derived from the site salts.
 *
 * Uses libsodium (crypto_secretbox: XSalsa20-Poly1305) when available,
 * otherwise OpenSSL AES-256-GCM. Constants in wp-config.php remain the
 * recommended place for credentials: the database then holds nothing.
 */
final class SecretBox {

	private const PREFIX_SODIUM  = 'r2s1:';
	private const PREFIX_OPENSSL = 'r2o1:';

	/**
	 * 32-byte key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Force OpenSSL even when sodium is available (tests).
	 *
	 * @var bool
	 */
	private $force_openssl;

	/**
	 * Constructor.
	 *
	 * @param string $material Key material (for example AUTH_KEY . SECURE_AUTH_SALT), at least 16 bytes.
	 * @param bool   $force_openssl Use OpenSSL.
	 * @throws InvalidArgumentException When the material is too short.
	 */
	public function __construct( string $material, bool $force_openssl = false ) {
		if ( strlen( $material ) < 16 ) {
			throw new InvalidArgumentException( 'Key material must be at least 16 bytes.' );
		}
		$this->key           = hash_hmac( 'sha256', 'wp-r2-offload|secret-box|v1', $material, true );
		$this->force_openssl = $force_openssl;
	}

	/**
	 * Encrypt.
	 *
	 * @param string $plain Plain text.
	 * @throws RuntimeException When no cipher is available.
	 */
	public function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! $this->force_openssl && function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( 24 );

			return self::PREFIX_SODIUM . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text.
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $cipher ) {
				return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text.
			}
		}

		throw new RuntimeException( 'Neither sodium nor OpenSSL is available to encrypt the credentials.' );
	}

	/**
	 * Decrypt; returns "" for empty, foreign or tampered values.
	 *
	 * @param string $stored Stored value.
	 */
	public function decrypt( string $stored ): string {
		if ( 0 === strpos( $stored, self::PREFIX_SODIUM ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See encrypt().
			if ( false === $raw || strlen( $raw ) < 24 + 16 ) {
				return '';
			}
			$plain = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), $this->key );

			return false === $plain ? '' : $plain;
		}
		if ( 0 === strpos( $stored, self::PREFIX_OPENSSL ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See encrypt().
			if ( false === $raw || strlen( $raw ) < 12 + 16 ) {
				return '';
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );

			return false === $plain ? '' : $plain;
		}

		return '';
	}

	/**
	 * Whether a value was produced by this class.
	 *
	 * @param string $value Value.
	 */
	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX_SODIUM ) || 0 === strpos( $value, self::PREFIX_OPENSSL );
	}
}
