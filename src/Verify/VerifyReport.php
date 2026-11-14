<?php
/**
 * Verification result.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Verify;

/**
 * Objects checked, missing and with a different size.
 */
final class VerifyReport {

	/**
	 * Keys found with the expected size.
	 *
	 * @var string[]
	 */
	public $ok = array();

	/**
	 * Relative path => key of objects missing in the bucket.
	 *
	 * @var array<string,string>
	 */
	public $missing = array();

	/**
	 * Relative path => [expected, actual] sizes.
	 *
	 * @var array<string,array{0:int,1:int}>
	 */
	public $mismatched = array();

	/**
	 * Relative path => error for objects that could not be checked.
	 *
	 * @var array<string,string>
	 */
	public $errors = array();

	/**
	 * Whether everything is in place.
	 */
	public function is_ok(): bool {
		return array() === $this->missing && array() === $this->mismatched && array() === $this->errors;
	}
}
