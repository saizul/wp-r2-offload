<?php
/**
 * HTTP transport.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Http;

/**
 * Sends requests. The plugin uses the WordPress HTTP API; tests use a fake.
 */
interface HttpClient {

	/**
	 * Send a request.
	 *
	 * @param Request $request Request.
	 * @param string  $save_to Optional file path to stream the response body to.
	 * @throws HttpException When no response was received.
	 */
	public function send( Request $request, string $save_to = '' ): Response;
}
