<?php
/**
 * WordPress HTTP transport.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\WP\Http;

use Saizul\R2Offload\Http\HttpClient;
use Saizul\R2Offload\Http\HttpException;
use Saizul\R2Offload\Http\Request;
use Saizul\R2Offload\Http\Response;

/**
 * Sends S3 requests with wp_remote_request().
 */
final class WpHttpClient implements HttpClient {

	/**
	 * Timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @param int $timeout Timeout in seconds.
	 */
	public function __construct( int $timeout = 120 ) {
		$this->timeout = $timeout;
	}

	/**
	 * Send.
	 *
	 * @param Request $request Signed request.
	 * @param string  $save_to File for the response body.
	 * @throws HttpException On transport errors.
	 */
	public function send( Request $request, string $save_to = '' ): Response {
		$args = array(
			'method'      => $request->method(),
			'headers'     => $request->headers(),
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'user-agent'  => 'wp-r2-offload/' . \Saizul\R2Offload\WP\Plugin::VERSION,
		);
		if ( '' !== $request->body() ) {
			$args['body'] = $request->body();
		}
		if ( '' !== $save_to ) {
			$args['stream']   = true;
			$args['filename'] = $save_to;
		}

		/**
		 * Filters the arguments passed to wp_remote_request() for R2 calls.
		 *
		 * Do not change headers that are part of the signature.
		 *
		 * @param array   $args    Arguments.
		 * @param Request $request Signed request.
		 */
		$args = apply_filters( 'wp_r2_offload_http_args', $args, $request );

		$result = wp_remote_request( $request->url(), $args );
		if ( is_wp_error( $result ) ) {
			throw new HttpException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught and reported as plain text.
		}

		$headers = wp_remote_retrieve_headers( $result );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		$flat = array();
		foreach ( (array) $headers as $name => $value ) {
			$flat[ (string) $name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		$status = (int) wp_remote_retrieve_response_code( $result );
		$body   = (string) wp_remote_retrieve_body( $result );
		if ( '' !== $save_to && ( $status < 200 || $status >= 300 ) && is_readable( $save_to ) ) {
			// Error bodies were streamed into the target file: read them back and remove it.
			$body = (string) file_get_contents( $save_to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			wp_delete_file( $save_to );
		}

		return new Response( $status, $body, $flat );
	}
}
