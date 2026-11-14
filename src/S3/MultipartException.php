<?php
/**
 * Interrupted multipart upload.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\S3;

use RuntimeException;

/**
 * Carries the upload state so the caller can resume or abort.
 */
final class MultipartException extends RuntimeException {

	/**
	 * State.
	 *
	 * @var MultipartState
	 */
	private $state;

	/**
	 * Constructor.
	 *
	 * @param S3Exception    $previous Cause.
	 * @param MultipartState $state State.
	 */
	public function __construct( S3Exception $previous, MultipartState $state ) {
		parent::__construct( $previous->getMessage(), 0, $previous );
		$this->state = $state;
	}

	/**
	 * State to resume from.
	 */
	public function state(): MultipartState {
		return $this->state;
	}

	/**
	 * The S3 error.
	 */
	public function s3_error(): S3Exception {
		$previous = $this->getPrevious();

		return $previous instanceof S3Exception ? $previous : new S3Exception( 'Unknown', $this->getMessage() );
	}
}
