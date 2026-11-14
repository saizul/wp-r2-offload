<?php
/**
 * AWS Signature Version 4.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Signer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Saizul\R2Offload\Http\Request;

/**
 * Signs requests with AWS Signature Version 4 (header and query-string
 * authentication), as used by Amazon S3 and S3-compatible stores such as
 * Cloudflare R2.
 *
 * Implemented from the AWS "Signature Version 4 signing process" and checked
 * against the AWS SigV4 test suite (see tests/Fixtures/aws-sigv4).
 *
 * Options:
 * - normalize_path (bool, default true): remove "." and ".." segments and
 *   duplicate slashes from the canonical URI. S3 must use false: object keys
 *   are taken literally.
 * - content_sha256_header (bool, default false): add x-amz-content-sha256
 *   (required by S3).
 * - payload_hash (string|null): use this payload hash instead of hashing the
 *   body, for example "UNSIGNED-PAYLOAD" or a precomputed SHA-256.
 * - sign_session_token (bool, default true): include x-amz-security-token in
 *   the signature; when false it is added after signing (some services).
 */
final class SignatureV4 {

	public const ALGORITHM        = 'AWS4-HMAC-SHA256';
	public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';
	public const EMPTY_SHA256     = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Region, such as "us-east-1" or "auto" for R2.
	 *
	 * @var string
	 */
	private $region;

	/**
	 * Service, such as "s3".
	 *
	 * @var string
	 */
	private $service;

	/**
	 * Signing keys by date (Ymd).
	 *
	 * @var array<string,string>
	 */
	private $key_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials Credentials.
	 * @param string      $region Region.
	 * @param string      $service Service.
	 * @throws InvalidArgumentException When region or service is empty.
	 */
	public function __construct( Credentials $credentials, string $region, string $service ) {
		if ( '' === $region || '' === $service ) {
			throw new InvalidArgumentException( 'Region and service are required.' );
		}
		$this->credentials = $credentials;
		$this->region      = $region;
		$this->service     = $service;
	}

	/**
	 * Sign a request with an Authorization header.
	 *
	 * @param Request             $request Request (URL already encoded as it is sent).
	 * @param DateTimeImmutable   $time Signing time.
	 * @param array<string,mixed> $options Options (see class comment).
	 */
	public function sign( Request $request, DateTimeImmutable $time, array $options = array() ): Request {
		$options = $this->options( $options );
		$amzdate = self::amz_date( $time );
		$payload = $this->payload_hash( $request, $options );

		$request = $request->without_header( 'Authorization' )->without_header( 'X-Amz-Date' );
		if ( null === $request->header( 'Host' ) ) {
			$request = $request->with_header( 'Host', self::host_header( $request->url() ) );
		}
		$request = $request->with_header( 'X-Amz-Date', $amzdate );
		if ( $options['content_sha256_header'] ) {
			$request = $request->with_header( 'X-Amz-Content-Sha256', $payload );
		}
		$token = $this->credentials->session_token();
		if ( '' !== $token && $options['sign_session_token'] ) {
			$request = $request->with_header( 'X-Amz-Security-Token', $token );
		}

		$canonical      = $this->canonical_request( $request, $payload, $options );
		$signed_headers = $this->signed_headers( $request );
		$signature      = $this->signature( $time, $this->string_to_sign( $time, $canonical ) );

		$request = $request->with_header(
			'Authorization',
			sprintf(
				'%1$s Credential=%2$s/%3$s, SignedHeaders=%4$s, Signature=%5$s',
				self::ALGORITHM,
				$this->credentials->access_key_id(),
				$this->scope( $time ),
				$signed_headers,
				$signature
			)
		);
		if ( '' !== $token && ! $options['sign_session_token'] ) {
			$request = $request->with_header( 'X-Amz-Security-Token', $token );
		}

		return $request;
	}

	/**
	 * Create a presigned URL (query-string authentication).
	 *
	 * @param string            $method Method.
	 * @param string            $url URL (encoded).
	 * @param int               $expires Lifetime in seconds (1 to 604800).
	 * @param DateTimeImmutable $time Signing time.
	 * @param bool              $normalize_path Normalise the path (false for S3).
	 * @throws InvalidArgumentException For an invalid lifetime.
	 */
	public function presign( string $method, string $url, int $expires, DateTimeImmutable $time, bool $normalize_path = false ): string {
		if ( $expires < 1 || $expires > 604800 ) {
			throw new InvalidArgumentException( 'Presigned URLs expire after 1 second to 7 days.' );
		}
		$params = array(
			'X-Amz-Algorithm'     => self::ALGORITHM,
			'X-Amz-Credential'    => $this->credentials->access_key_id() . '/' . $this->scope( $time ),
			'X-Amz-Date'          => self::amz_date( $time ),
			'X-Amz-Expires'       => (string) $expires,
			'X-Amz-SignedHeaders' => 'host',
		);
		if ( '' !== $this->credentials->session_token() ) {
			$params['X-Amz-Security-Token'] = $this->credentials->session_token();
		}
		$query = '';
		foreach ( $params as $name => $value ) {
			$query .= ( '' === $query ? '' : '&' ) . rawurlencode( $name ) . '=' . rawurlencode( $value );
		}
		$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;

		$request   = new Request( $method, $url, array( 'Host' => self::host_header( $url ) ) );
		$canonical = $this->canonical_request(
			$request,
			self::UNSIGNED_PAYLOAD,
			$this->options( array( 'normalize_path' => $normalize_path ) )
		);

		return $url . '&X-Amz-Signature=' . $this->signature( $time, $this->string_to_sign( $time, $canonical ) );
	}

	/**
	 * Canonical request.
	 *
	 * @param Request             $request Request including the X-Amz-Date header.
	 * @param string              $payload_hash Hex SHA-256 of the payload or UNSIGNED-PAYLOAD.
	 * @param array<string,mixed> $options Options.
	 */
	public function canonical_request( Request $request, string $payload_hash, array $options = array() ): string {
		$options = $this->options( $options );
		$parts   = self::split_url( $request->url() );

		return implode(
			"\n",
			array(
				$request->method(),
				self::canonical_uri( $parts['path'], (bool) $options['normalize_path'] ),
				self::canonical_query( $parts['query'] ),
				$this->canonical_headers( $request ),
				$this->signed_headers( $request ),
				$payload_hash,
			)
		);
	}

	/**
	 * String to sign.
	 *
	 * @param DateTimeImmutable $time Signing time.
	 * @param string            $canonical_request Canonical request.
	 */
	public function string_to_sign( DateTimeImmutable $time, string $canonical_request ): string {
		return implode(
			"\n",
			array(
				self::ALGORITHM,
				self::amz_date( $time ),
				$this->scope( $time ),
				hash( 'sha256', $canonical_request ),
			)
		);
	}

	/**
	 * Derived signing key (binary).
	 *
	 * @param DateTimeImmutable $time Signing time.
	 */
	public function signing_key( DateTimeImmutable $time ): string {
		$date = self::utc( $time )->format( 'Ymd' );
		if ( ! isset( $this->key_cache[ $date ] ) ) {
			$key                      = hash_hmac( 'sha256', $date, 'AWS4' . $this->credentials->secret_access_key(), true );
			$key                      = hash_hmac( 'sha256', $this->region, $key, true );
			$key                      = hash_hmac( 'sha256', $this->service, $key, true );
			$this->key_cache[ $date ] = hash_hmac( 'sha256', 'aws4_request', $key, true );
		}

		return $this->key_cache[ $date ];
	}

	/**
	 * Hex signature of a string to sign.
	 *
	 * @param DateTimeImmutable $time Signing time.
	 * @param string            $string_to_sign String to sign.
	 */
	public function signature( DateTimeImmutable $time, string $string_to_sign ): string {
		return hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $time ) );
	}

	/**
	 * Credential scope: date/region/service/aws4_request.
	 *
	 * @param DateTimeImmutable $time Signing time.
	 */
	public function scope( DateTimeImmutable $time ): string {
		return self::utc( $time )->format( 'Ymd' ) . '/' . $this->region . '/' . $this->service . '/aws4_request';
	}

	/**
	 * Canonical URI of an encoded path.
	 *
	 * Each segment is decoded and encoded again with RFC 3986 rules, so the
	 * result does not depend on how the caller escaped it.
	 *
	 * @param string $path Encoded path.
	 * @param bool   $normalize Remove dot segments and duplicate slashes.
	 */
	public static function canonical_uri( string $path, bool $normalize ): string {
		if ( '' === $path ) {
			return '/';
		}
		$segments = explode( '/', $path );
		if ( $normalize ) {
			$output = array();
			$last   = count( $segments ) - 1;
			foreach ( $segments as $i => $segment ) {
				$decoded = rawurldecode( $segment );
				if ( '..' === $decoded ) {
					array_pop( $output );
					if ( $i === $last ) {
						$output[] = '';
					}
					continue;
				}
				if ( '.' === $decoded || '' === $decoded ) {
					if ( $i === $last ) {
						$output[] = '';
					}
					continue;
				}
				$output[] = $segment;
			}
			$segments = array_merge( array( '' ), $output );
			if ( array( '' ) === $segments ) {
				return '/';
			}
		}

		$encoded = implode(
			'/',
			array_map(
				static function ( string $segment ): string {
					return rawurlencode( rawurldecode( $segment ) );
				},
				$segments
			)
		);

		return '' === $encoded ? '/' : $encoded;
	}

	/**
	 * Canonical query string: decoded, re-encoded and sorted by name then value.
	 *
	 * @param string $query Raw query string without "?".
	 */
	public static function canonical_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}
		$pairs = array();
		foreach ( explode( '&', $query ) as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$eq      = strpos( $part, '=' );
			$name    = false === $eq ? $part : substr( $part, 0, $eq );
			$value   = false === $eq ? '' : substr( $part, $eq + 1 );
			$pairs[] = array( rawurlencode( rawurldecode( $name ) ), rawurlencode( rawurldecode( $value ) ) );
		}
		usort(
			$pairs,
			static function ( array $a, array $b ): int {
				$cmp = strcmp( $a[0], $b[0] );
				return 0 !== $cmp ? $cmp : strcmp( $a[1], $b[1] );
			}
		);

		return implode(
			'&',
			array_map(
				static function ( array $pair ): string {
					return $pair[0] . '=' . $pair[1];
				},
				$pairs
			)
		);
	}

	/**
	 * Canonical headers block (each line ends with "\n").
	 *
	 * @param Request $request Request.
	 */
	private function canonical_headers( Request $request ): string {
		$lines = '';
		foreach ( $this->header_map( $request ) as $name => $value ) {
			$lines .= $name . ':' . $value . "\n";
		}

		return $lines;
	}

	/**
	 * Signed header names joined with ";".
	 *
	 * @param Request $request Request.
	 */
	private function signed_headers( Request $request ): string {
		return implode( ';', array_keys( $this->header_map( $request ) ) );
	}

	/**
	 * Lower-case header name => trimmed, space-collapsed values joined with ",".
	 *
	 * @param Request $request Request.
	 * @return array<string,string>
	 */
	private function header_map( Request $request ): array {
		$map = array();
		foreach ( $request->header_lines() as $name => $values ) {
			$name = strtolower( trim( (string) $name ) );
			if ( 'authorization' === $name || 'user-agent' === $name || 'expect' === $name ) {
				continue;
			}
			foreach ( $values as $value ) {
				$value        = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
				$map[ $name ] = isset( $map[ $name ] ) ? $map[ $name ] . ',' . $value : $value;
			}
		}
		ksort( $map, SORT_STRING );

		return $map;
	}

	/**
	 * Payload hash for a request.
	 *
	 * @param Request             $request Request.
	 * @param array<string,mixed> $options Options.
	 */
	private function payload_hash( Request $request, array $options ): string {
		if ( is_string( $options['payload_hash'] ) && '' !== $options['payload_hash'] ) {
			return $options['payload_hash'];
		}

		return '' === $request->body() ? self::EMPTY_SHA256 : hash( 'sha256', $request->body() );
	}

	/**
	 * Options with defaults.
	 *
	 * @param array<string,mixed> $options Options.
	 * @return array{normalize_path:bool,content_sha256_header:bool,payload_hash:string|null,sign_session_token:bool}
	 */
	private function options( array $options ): array {
		return array(
			'normalize_path'        => (bool) ( $options['normalize_path'] ?? true ),
			'content_sha256_header' => (bool) ( $options['content_sha256_header'] ?? false ),
			'payload_hash'          => isset( $options['payload_hash'] ) ? (string) $options['payload_hash'] : null,
			'sign_session_token'    => (bool) ( $options['sign_session_token'] ?? true ),
		);
	}

	/**
	 * X-Amz-Date value.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	public static function amz_date( DateTimeImmutable $time ): string {
		return self::utc( $time )->format( 'Ymd\THis\Z' );
	}

	/**
	 * Time in UTC.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): DateTimeImmutable {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Host header for a URL (port only when not the default).
	 *
	 * @param string $url URL.
	 */
	public static function host_header( string $url ): string {
		$parts = self::split_url( $url );
		$host  = $parts['host'];
		if ( null !== $parts['port'] && ! ( ( 'https' === $parts['scheme'] && 443 === $parts['port'] ) || ( 'http' === $parts['scheme'] && 80 === $parts['port'] ) ) ) {
			$host .= ':' . $parts['port'];
		}

		return $host;
	}

	/**
	 * Split a URL without decoding it.
	 *
	 * The path is not parsed with parse_url(), which rejects some valid
	 * object keys; only scheme and authority are parsed.
	 *
	 * @param string $url URL.
	 * @return array{scheme:string,host:string,port:int|null,path:string,query:string}
	 */
	public static function split_url( string $url ): array {
		$scheme = '';
		$rest   = $url;
		if ( preg_match( '#^([a-z][a-z0-9+.-]*)://#i', $url, $m ) ) {
			$scheme = strtolower( $m[1] );
			$rest   = substr( $url, strlen( $m[0] ) );
		}
		$slash     = strpos( $rest, '/' );
		$question  = strpos( $rest, '?' );
		$end       = min( false === $slash ? strlen( $rest ) : $slash, false === $question ? strlen( $rest ) : $question );
		$authority = substr( $rest, 0, $end );
		$rest      = (string) substr( $rest, $end );

		$host = $authority;
		$port = null;
		if ( preg_match( '/^(.*):(\d+)$/', $authority, $m ) ) {
			$host = $m[1];
			$port = (int) $m[2];
		}

		$query = '';
		$path  = $rest;
		$pos   = strpos( $rest, '?' );
		if ( false !== $pos ) {
			$path  = substr( $rest, 0, $pos );
			$query = (string) substr( $rest, $pos + 1 );
		}
		$hash = strpos( $query, '#' );
		if ( false !== $hash ) {
			$query = substr( $query, 0, $hash );
		}

		return array(
			'scheme' => $scheme,
			'host'   => strtolower( $host ),
			'port'   => $port,
			'path'   => '' === $path ? '/' : $path,
			'query'  => $query,
		);
	}
}
