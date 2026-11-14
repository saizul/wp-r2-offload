<?php
/**
 * Transport failure.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Http;

use RuntimeException;

/**
 * Raised when no HTTP response was received.
 */
final class HttpException extends RuntimeException {
}
