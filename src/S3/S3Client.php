<?php
/**
 * Minimal S3 client for R2.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\S3;

use DateTimeImmutable;
use DateTimeZone;
use Saizul\R2Offload\Http\HttpClient;
use Saizul\R2Offload\Http\HttpException;
use Saizul\R2Offload\Http\Request;
use Saizul\R2Offload\Http\Response;
use Saizul\R2Offload\Signer\SignatureV4;

/**
 * The S3 operations the plugin needs, signed with {@see SignatureV4}.
 *
 * All of them are listed as implemented on Cloudflare's R2 S3 API
 * compatibility page: PutObject, HeadObject, GetObject, DeleteObject,
 * DeleteObjects, ListObjectsV2, CreateMultipartUpload, UploadPart,
 * CompleteMultipartUpload and AbortMultipartUpload. No x-amz-acl header is
 * sent (R2 does not implement ACLs); public access is configured on the
 * bucket or a custom domain.
 *
 * Throttling (429), server errors (5xx) and transport errors are retried
 * with exponential back-off; every attempt is signed again. Content-Length
 * is left to the transport and not signed.
 */
final class S3Client {

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Endpoint.
	 *
	 * @var R2Endpoint
	 */
	private $endpoint;

	/**
	 * Signer.
	 *
	 * @var SignatureV4
	 */
	private $signer;

	/**
	 * Returns the signing time.
	 *
	 * @var callable():DateTimeImmutable
	 */
	private $clock;

	/**
	 * Waits between retries (microseconds).
	 *
	 * @var callable(int):void
	 */
	private $sleeper;

	/**
	 * Attempts per request.
	 *
	 * @var int
	 */
	private $max_attempts;

	/**
	 * Constructor.
	 *
	 * @param HttpClient                        $http HTTP client.
	 * @param R2Endpoint                        $endpoint Endpoint.
	 * @param SignatureV4                       $signer Signer.
	 * @param callable():DateTimeImmutable|null $clock Signing time source.
	 * @param int                               $max_attempts Attempts per request (1 to 6).
	 * @param callable(int):void|null           $sleeper Back-off sleeper.
	 */
	public function __construct( HttpClient $http, R2Endpoint $endpoint, SignatureV4 $signer, ?callable $clock = null, int $max_attempts = 3, ?callable $sleeper = null ) {
		$this->http         = $http;
		$this->endpoint     = $endpoint;
		$this->signer       = $signer;
		$this->clock        = $clock ?? static function (): DateTimeImmutable {
			return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		};
		$this->max_attempts = max( 1, min( 6, $max_attempts ) );
		$this->sleeper      = $sleeper ?? static function ( int $microseconds ): void {
			usleep( $microseconds );
		};
	}

	/**
	 * Endpoint.
	 */
	public function endpoint(): R2Endpoint {
		return $this->endpoint;
	}

	/**
	 * Upload an object in one request.
	 *
	 * @param string               $key Key.
	 * @param string               $body Content.
	 * @param string               $content_type MIME type.
	 * @param string               $cache_control Cache-Control header, or "".
	 * @param array<string,string> $extra Extra headers (for example x-amz-meta-*).
	 * @return string ETag.
	 * @throws S3Exception On failure.
	 */
	public function put_object( string $key, string $body, string $content_type, string $cache_control = '', array $extra = array() ): string {
		$headers  = array_merge(
			array(
				'Content-Type' => '' === $content_type ? 'application/octet-stream' : $content_type,
				'Content-MD5'  => base64_encode( md5( $body, true ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Content-MD5 is base64 by definition.
			),
			'' === $cache_control ? array() : array( 'Cache-Control' => $cache_control ),
			$extra
		);
		$response = $this->send( new Request( 'PUT', $this->endpoint->object_url( $key ), $headers, $body ) );

		return trim( (string) $response->header( 'etag' ), '"' );
	}

	/**
	 * Metadata of an object, or null when it does not exist.
	 *
	 * @param string $key Key.
	 * @throws S3Exception On errors other than 404.
	 */
	public function head_object( string $key ): ?ObjectMeta {
		$response = $this->send( new Request( 'HEAD', $this->endpoint->object_url( $key ) ), array( 404 ) );
		if ( 404 === $response->status() ) {
			return null;
		}

		return new ObjectMeta(
			$key,
			(int) $response->header( 'content-length' ),
			(string) $response->header( 'etag' ),
			(string) $response->header( 'content-type' ),
			(string) $response->header( 'last-modified' )
		);
	}

	/**
	 * Download an object into a file.
	 *
	 * @param string $key Key.
	 * @param string $path Target file.
	 * @return bool False when the object does not exist.
	 * @throws S3Exception On other errors.
	 */
	public function get_object_to_file( string $key, string $path ): bool {
		$response = $this->send( new Request( 'GET', $this->endpoint->object_url( $key ) ), array( 404 ), $path );

		return 404 !== $response->status();
	}

	/**
	 * Delete an object (succeeds when it is already gone).
	 *
	 * @param string $key Key.
	 * @throws S3Exception On failure.
	 */
	public function delete_object( string $key ): void {
		$this->send( new Request( 'DELETE', $this->endpoint->object_url( $key ) ), array( 404 ) );
	}

	/**
	 * Delete up to 1000 objects per request.
	 *
	 * @param string[] $keys Keys.
	 * @return array<string,string> Keys that could not be deleted => error.
	 * @throws S3Exception When a request fails as a whole.
	 */
	public function delete_objects( array $keys ): array {
		$failed = array();
		foreach ( array_chunk( array_values( array_unique( $keys ) ), 1000 ) as $chunk ) {
			$xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';
			foreach ( $chunk as $key ) {
				$xml .= '<Object><Key>' . Xml::escape( $key ) . '</Key></Object>';
			}
			$xml .= '</Delete>';

			$response = $this->send(
				new Request(
					'POST',
					$this->endpoint->bucket_url( 'delete=' ),
					array(
						'Content-Type' => 'application/xml',
						'Content-MD5'  => base64_encode( md5( $xml, true ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Content-MD5 is base64 by definition.
					),
					$xml
				)
			);
			$doc      = Xml::parse( $response->body() );
			if ( null === $doc ) {
				continue;
			}
			foreach ( Xml::children( $doc, 'Error' ) as $error ) {
				$failed[ Xml::text( $error, 'Key' ) ] = Xml::text( $error, 'Code' ) . ' ' . Xml::text( $error, 'Message' );
			}
		}

		return $failed;
	}

	/**
	 * List keys under a prefix (ListObjectsV2).
	 *
	 * @param string $prefix Prefix.
	 * @param int    $max_keys Page size (1 to 1000).
	 * @param string $continuation Continuation token from the previous page.
	 * @return array{keys:array<string,int>,next:string} Key => size, and the next continuation token ("" at the end).
	 * @throws S3Exception On failure.
	 */
	public function list_objects( string $prefix = '', int $max_keys = 1000, string $continuation = '' ): array {
		$page = $this->list_object_metas( $prefix, $max_keys, $continuation );
		$keys = array();
		foreach ( $page['objects'] as $meta ) {
			$keys[ $meta->key ] = $meta->size;
		}

		return array(
			'keys' => $keys,
			'next' => $page['next'],
		);
	}

	/**
	 * List objects under a prefix with size, ETag and LastModified (ListObjectsV2).
	 *
	 * @param string $prefix Prefix.
	 * @param int    $max_keys Page size (1 to 1000).
	 * @param string $continuation Continuation token from the previous page.
	 * @return array{objects:ObjectMeta[],next:string} Objects in key order, and the next continuation token ("" at the end).
	 * @throws S3Exception On failure.
	 */
	public function list_object_metas( string $prefix = '', int $max_keys = 1000, string $continuation = '' ): array {
		$query = 'list-type=2&max-keys=' . max( 1, min( 1000, $max_keys ) );
		if ( '' !== $prefix ) {
			$query .= '&prefix=' . rawurlencode( $prefix );
		}
		if ( '' !== $continuation ) {
			$query .= '&continuation-token=' . rawurlencode( $continuation );
		}
		$response = $this->send( new Request( 'GET', $this->endpoint->bucket_url( $query ) ) );
		$doc      = Xml::parse( $response->body() );
		if ( null === $doc ) {
			throw new S3Exception( 'InvalidResponse', 'ListObjectsV2 did not return XML.', $response->status() );
		}
		$objects = array();
		foreach ( Xml::children( $doc, 'Contents' ) as $item ) {
			$objects[] = new ObjectMeta(
				Xml::text( $item, 'Key' ),
				(int) Xml::text( $item, 'Size' ),
				Xml::text( $item, 'ETag' ),
				'',
				Xml::text( $item, 'LastModified' )
			);
		}
		$next = 'true' === Xml::text( $doc, 'IsTruncated' ) ? Xml::text( $doc, 'NextContinuationToken' ) : '';
		if ( '' !== $continuation && $next === $continuation ) {
			throw new S3Exception( 'InvalidResponse', 'ListObjectsV2 returned the same continuation token twice.', $response->status() );
		}

		return array(
			'objects' => $objects,
			'next'    => $next,
		);
	}

	/**
	 * Start a multipart upload.
	 *
	 * @param string $key Key.
	 * @param string $content_type MIME type.
	 * @param string $cache_control Cache-Control or "".
	 * @return string Upload id.
	 * @throws S3Exception On failure.
	 */
	public function create_multipart_upload( string $key, string $content_type, string $cache_control = '' ): string {
		$headers = array( 'Content-Type' => '' === $content_type ? 'application/octet-stream' : $content_type );
		if ( '' !== $cache_control ) {
			$headers['Cache-Control'] = $cache_control;
		}
		$response = $this->send( new Request( 'POST', $this->endpoint->object_url( $key, 'uploads=' ), $headers ) );
		$doc      = Xml::parse( $response->body() );
		$id       = null === $doc ? '' : Xml::text( $doc, 'UploadId' );
		if ( '' === $id ) {
			throw new S3Exception( 'InvalidResponse', 'CreateMultipartUpload did not return an UploadId.', $response->status() );
		}

		return $id;
	}

	/**
	 * Upload one part.
	 *
	 * @param string $key Key.
	 * @param string $upload_id Upload id.
	 * @param int    $part_number Part number (1 to 10000).
	 * @param string $body Part content.
	 * @return string ETag of the part.
	 * @throws S3Exception On failure.
	 */
	public function upload_part( string $key, string $upload_id, int $part_number, string $body ): string {
		$query    = 'partNumber=' . $part_number . '&uploadId=' . rawurlencode( $upload_id );
		$response = $this->send(
			new Request(
				'PUT',
				$this->endpoint->object_url( $key, $query ),
				array( 'Content-MD5' => base64_encode( md5( $body, true ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Content-MD5 is base64 by definition.
				$body
			)
		);
		$etag     = trim( (string) $response->header( 'etag' ), '"' );
		if ( '' === $etag ) {
			throw new S3Exception( 'InvalidResponse', 'UploadPart did not return an ETag.', $response->status() );
		}

		return $etag;
	}

	/**
	 * Complete a multipart upload.
	 *
	 * @param string            $key Key.
	 * @param string            $upload_id Upload id.
	 * @param array<int,string> $parts Part number => ETag.
	 * @return string ETag of the object.
	 * @throws S3Exception On failure, including errors S3 reports with status 200.
	 */
	public function complete_multipart_upload( string $key, string $upload_id, array $parts ): string {
		ksort( $parts );
		$xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload>';
		foreach ( $parts as $number => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>"' . Xml::escape( trim( $etag, '"' ) ) . '"</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		$response = $this->send(
			new Request(
				'POST',
				$this->endpoint->object_url( $key, 'uploadId=' . rawurlencode( $upload_id ) ),
				array( 'Content-Type' => 'application/xml' ),
				$xml
			)
		);
		// CompleteMultipartUpload can fail after a 200 status line.
		$error = Xml::error( $response->body() );
		if ( null !== $error ) {
			throw new S3Exception( $error['code'], $error['message'], $response->status(), 'InternalError' === $error['code'] );
		}
		$doc = Xml::parse( $response->body() );

		return null === $doc ? '' : trim( Xml::text( $doc, 'ETag' ), '"' );
	}

	/**
	 * Abort a multipart upload (ignores uploads that no longer exist).
	 *
	 * @param string $key Key.
	 * @param string $upload_id Upload id.
	 * @throws S3Exception On failure.
	 */
	public function abort_multipart_upload( string $key, string $upload_id ): void {
		$this->send( new Request( 'DELETE', $this->endpoint->object_url( $key, 'uploadId=' . rawurlencode( $upload_id ) ) ), array( 404 ) );
	}

	/**
	 * Sign and send with retries.
	 *
	 * @param Request $request Unsigned request.
	 * @param int[]   $accept Non-2xx statuses that are not errors (such as 404).
	 * @param string  $save_to File to stream the body to.
	 * @throws S3Exception On failure.
	 */
	private function send( Request $request, array $accept = array(), string $save_to = '' ): Response {
		$attempt = 0;
		while ( true ) {
			++$attempt;
			$signed = $this->signer->sign(
				$request,
				( $this->clock )(),
				array(
					'normalize_path'        => false,
					'content_sha256_header' => true,
				)
			);
			try {
				$response = $this->http->send( $signed, $save_to );
			} catch ( HttpException $e ) {
				if ( $attempt < $this->max_attempts ) {
					$this->backoff( $attempt );
					continue;
				}
				throw new S3Exception( 'NetworkError', $e->getMessage(), 0, true );
			}

			if ( $response->is_success() || in_array( $response->status(), $accept, true ) ) {
				return $response;
			}
			if ( $response->is_retryable() && $attempt < $this->max_attempts ) {
				$this->backoff( $attempt );
				continue;
			}

			$error = Xml::error( $response->body() );
			throw new S3Exception(
				null === $error || '' === $error['code'] ? 'HTTP' . $response->status() : $error['code'],
				null === $error ? sprintf( '%1$s %2$s returned HTTP %3$d.', $request->method(), self::describe( $request ), $response->status() ) : $error['message'],
				$response->status(),
				$response->is_retryable()
			);
		}
	}

	/**
	 * Wait before the next attempt: 0.5 s, 1 s, 2 s...
	 *
	 * @param int $attempt Attempt that failed.
	 */
	private function backoff( int $attempt ): void {
		( $this->sleeper )( (int) ( 500000 * ( 2 ** ( $attempt - 1 ) ) ) );
	}

	/**
	 * Path of a request for error messages (no query string, no credentials).
	 *
	 * @param Request $request Request.
	 */
	private static function describe( Request $request ): string {
		return SignatureV4::split_url( $request->url() )['path'];
	}
}
