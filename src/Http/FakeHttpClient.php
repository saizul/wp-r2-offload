<?php
/**
 * Scripted HTTP client.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Http;

/**
 * Returns queued responses (or a callback's answer) and records requests.
 */
final class FakeHttpClient implements HttpClient {

	/**
	 * Queue.
	 *
	 * @var array<int,Response|HttpException>
	 */
	private $queue = array();

	/**
	 * Requests received.
	 *
	 * @var Request[]
	 */
	public $requests = array();

	/**
	 * Responder used when the queue is empty.
	 *
	 * @var callable(Request):Response|null
	 */
	private $responder;

	/**
	 * Constructor.
	 *
	 * @param callable(Request):Response|null $responder Fallback responder.
	 */
	public function __construct( ?callable $responder = null ) {
		$this->responder = $responder;
	}

	/**
	 * Queue a response.
	 *
	 * @param int                  $status Status.
	 * @param string               $body Body.
	 * @param array<string,string> $headers Headers.
	 */
	public function queue( int $status, string $body = '', array $headers = array() ): self {
		$this->queue[] = new Response( $status, $body, $headers );
		return $this;
	}

	/**
	 * Queue a transport error.
	 *
	 * @param string $message Message.
	 */
	public function queue_error( string $message ): self {
		$this->queue[] = new HttpException( $message );
		return $this;
	}

	/**
	 * Send.
	 *
	 * @param Request $request Request.
	 * @param string  $save_to File to write the body to.
	 * @throws HttpException When queued.
	 */
	public function send( Request $request, string $save_to = '' ): Response {
		$this->requests[] = $request;
		$next             = array_shift( $this->queue );
		if ( $next instanceof HttpException ) {
			throw $next;
		}
		if ( null === $next ) {
			$next = null !== $this->responder ? ( $this->responder )( $request ) : new Response( 200 );
		}
		if ( '' !== $save_to && $next->is_success() ) {
			file_put_contents( $save_to, $next->body() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test double.
		}

		return $next;
	}

	/**
	 * Last request.
	 */
	public function last(): ?Request {
		$last = end( $this->requests );

		return false === $last ? null : $last;
	}
}
