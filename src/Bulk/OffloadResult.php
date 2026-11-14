<?php
/**
 * Outcome of offloading one attachment.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Bulk;

/**
 * Status, message and transferred bytes.
 */
final class OffloadResult {

	public const OFFLOADED = 'offloaded';
	public const SKIPPED   = 'skipped';
	public const FAILED    = 'failed';

	/**
	 * Status constant.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Message.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Files uploaded.
	 *
	 * @var int
	 */
	public $files;

	/**
	 * Bytes uploaded.
	 *
	 * @var int
	 */
	public $bytes;

	/**
	 * Constructor.
	 *
	 * @param string $status Status.
	 * @param string $message Message.
	 * @param int    $files Files.
	 * @param int    $bytes Bytes.
	 */
	public function __construct( string $status, string $message = '', int $files = 0, int $bytes = 0 ) {
		$this->status  = $status;
		$this->message = $message;
		$this->files   = $files;
		$this->bytes   = $bytes;
	}
}
