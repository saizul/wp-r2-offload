<?php
/**
 * Cloudflare R2 S3 endpoint.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\S3;

use InvalidArgumentException;

/**
 * Builds R2 S3 API URLs.
 *
 * Per developers.cloudflare.com/r2/api/s3/: the endpoint is
 * https://<ACCOUNT_ID>.r2.cloudflarestorage.com (EU jurisdiction:
 * <ACCOUNT_ID>.eu.r2..., FedRAMP: <ACCOUNT_ID>.fedramp.r2...) and the region
 * is "auto". Requests use path-style addressing: endpoint/bucket/key.
 */
final class R2Endpoint {

	public const REGION  = 'auto';
	public const SERVICE = 's3';

	public const JURISDICTIONS = array( '', 'eu', 'fedramp' );

	/**
	 * Base URL without trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Bucket.
	 *
	 * @var string
	 */
	private $bucket;

	/**
	 * Constructor.
	 *
	 * @param string $account_id Cloudflare account id (32 hex characters).
	 * @param string $bucket Bucket name.
	 * @param string $jurisdiction "", "eu" or "fedramp".
	 * @param string $custom_endpoint Full endpoint URL overriding the account (S3-compatible test servers).
	 * @throws InvalidArgumentException For invalid values.
	 */
	public function __construct( string $account_id, string $bucket, string $jurisdiction = '', string $custom_endpoint = '' ) {
		if ( ! self::is_valid_bucket( $bucket ) ) {
			throw new InvalidArgumentException( 'Bucket names use 3 to 63 lowercase letters, digits and hyphens, and start and end with a letter or digit.' );
		}
		$this->bucket = $bucket;

		if ( '' !== $custom_endpoint ) {
			if ( ! preg_match( '#^https?://[a-z0-9.\-]+(:\d+)?/?$#i', $custom_endpoint ) ) {
				throw new InvalidArgumentException( 'The custom endpoint must be a URL such as https://s3.example.com.' );
			}
			$this->base = rtrim( $custom_endpoint, '/' );
			return;
		}
		if ( ! self::is_valid_account_id( $account_id ) ) {
			throw new InvalidArgumentException( 'The Cloudflare account ID is 32 hexadecimal characters.' );
		}
		if ( ! in_array( $jurisdiction, self::JURISDICTIONS, true ) ) {
			throw new InvalidArgumentException( 'The jurisdiction must be empty, "eu" or "fedramp".' );
		}
		$this->base = 'https://' . strtolower( $account_id ) . ( '' === $jurisdiction ? '' : '.' . $jurisdiction ) . '.r2.cloudflarestorage.com';
	}

	/**
	 * Whether an account id looks valid.
	 *
	 * @param string $account_id Account id.
	 */
	public static function is_valid_account_id( string $account_id ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/i', $account_id );
	}

	/**
	 * Whether a bucket name follows the R2 naming rules.
	 *
	 * @param string $bucket Bucket.
	 */
	public static function is_valid_bucket( string $bucket ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $bucket );
	}

	/**
	 * Endpoint URL.
	 */
	public function endpoint(): string {
		return $this->base;
	}

	/**
	 * Bucket.
	 */
	public function bucket(): string {
		return $this->bucket;
	}

	/**
	 * Bucket URL (path style).
	 *
	 * @param string $query Encoded query string without "?".
	 */
	public function bucket_url( string $query = '' ): string {
		return $this->base . '/' . $this->bucket . '/' . ( '' === $query ? '' : '?' . $query );
	}

	/**
	 * Object URL (path style) with the key encoded per segment.
	 *
	 * @param string $key Object key.
	 * @param string $query Encoded query string without "?".
	 */
	public function object_url( string $key, string $query = '' ): string {
		return $this->base . '/' . $this->bucket . '/' . self::encode_key( $key ) . ( '' === $query ? '' : '?' . $query );
	}

	/**
	 * Encode an object key for a URL path, keeping "/" separators.
	 *
	 * @param string $key Key.
	 */
	public static function encode_key( string $key ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $key, '/' ) ) ) );
	}
}
