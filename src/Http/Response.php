<?php
/**
 * HTTP response.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Http;

/**
 * Status, headers and body.
 */
final class Response {

	/**
	 * Status code.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Body.
	 *
	 * @var string
	 */
	private $body;

	/**
	 * Lower-case header names => values.
	 *
	 * @var array<string,string>
	 */
	private $headers;

	/**
	 * Constructor.
	 *
	 * @param int                  $status Status.
	 * @param string               $body Body.
	 * @param array<string,string> $headers Headers.
	 */
	public function __construct( int $status, string $body = '', array $headers = array() ) {
		$this->status  = $status;
		$this->body    = $body;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	/**
	 * Status.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Body.
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Header value or null.
	 *
	 * @param string $name Name.
	 */
	public function header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	/**
	 * 2xx.
	 */
	public function is_success(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Whether trying again later could help (throttling or server errors).
	 */
	public function is_retryable(): bool {
		return 429 === $this->status || 408 === $this->status || $this->status >= 500;
	}
}
