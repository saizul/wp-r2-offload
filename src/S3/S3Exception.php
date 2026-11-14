<?php
/**
 * S3 error response.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\S3;

use RuntimeException;

/**
 * An error returned by the S3 API (or a transport failure).
 */
final class S3Exception extends RuntimeException {

	/**
	 * S3 error code, such as "NoSuchKey" or "SignatureDoesNotMatch".
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * HTTP status (0 for transport errors).
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Whether a retry could succeed.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * Constructor.
	 *
	 * @param string $error_code Error code.
	 * @param string $message Message.
	 * @param int    $status HTTP status.
	 * @param bool   $retryable Retryable.
	 */
	public function __construct( string $error_code, string $message, int $status = 0, bool $retryable = false ) {
		parent::__construct( '' === $message ? $error_code : $error_code . ': ' . $message );
		$this->error_code = $error_code;
		$this->status     = $status;
		$this->retryable  = $retryable;
	}

	/**
	 * Error code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * HTTP status.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Whether a retry could succeed.
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}
