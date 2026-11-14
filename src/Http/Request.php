<?php
/**
 * HTTP request.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Http;

/**
 * Immutable HTTP request. Header names keep their case; lookups are
 * case-insensitive. A header may repeat (list of values).
 */
final class Request {

	/**
	 * Method.
	 *
	 * @var string
	 */
	private $method;

	/**
	 * URL as sent on the wire (path and query already encoded).
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Headers: name => list of values.
	 *
	 * @var array<string,string[]>
	 */
	private $headers = array();

	/**
	 * Body.
	 *
	 * @var string
	 */
	private $body;

	/**
	 * Constructor.
	 *
	 * @param string                        $method Method.
	 * @param string                        $url URL.
	 * @param array<string,string|string[]> $headers Headers.
	 * @param string                        $body Body.
	 */
	public function __construct( string $method, string $url, array $headers = array(), string $body = '' ) {
		$this->method = strtoupper( $method );
		$this->url    = $url;
		$this->body   = $body;
		foreach ( $headers as $name => $value ) {
			$this->headers[ (string) $name ] = array_map( 'strval', (array) $value );
		}
	}

	/**
	 * Method.
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * URL.
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Body.
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * All headers as name => list of values.
	 *
	 * @return array<string,string[]>
	 */
	public function header_lines(): array {
		return $this->headers;
	}

	/**
	 * Headers flattened to name => value (repeated values joined with ",").
	 *
	 * @return array<string,string>
	 */
	public function headers(): array {
		$flat = array();
		foreach ( $this->headers as $name => $values ) {
			$flat[ $name ] = implode( ',', $values );
		}

		return $flat;
	}

	/**
	 * A header value (case-insensitive), or null.
	 *
	 * @param string $name Name.
	 */
	public function header( string $name ): ?string {
		foreach ( $this->headers as $key => $values ) {
			if ( 0 === strcasecmp( $key, $name ) ) {
				return implode( ',', $values );
			}
		}

		return null;
	}

	/**
	 * Copy with a header set (replacing any value with the same name).
	 *
	 * @param string $name Name.
	 * @param string $value Value.
	 */
	public function with_header( string $name, string $value ): self {
		$copy = $this->without_header( $name );

		$copy->headers[ $name ] = array( $value );

		return $copy;
	}

	/**
	 * Copy without a header.
	 *
	 * @param string $name Name.
	 */
	public function without_header( string $name ): self {
		$copy = clone $this;
		foreach ( array_keys( $copy->headers ) as $key ) {
			if ( 0 === strcasecmp( $key, $name ) ) {
				unset( $copy->headers[ $key ] );
			}
		}

		return $copy;
	}

	/**
	 * Copy with another URL.
	 *
	 * @param string $url URL.
	 */
	public function with_url( string $url ): self {
		$copy      = clone $this;
		$copy->url = $url;

		return $copy;
	}
}
