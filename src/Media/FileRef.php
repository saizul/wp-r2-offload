<?php
/**
 * One file of an attachment.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Media;

/**
 * A file belonging to an attachment: the original, a size, an edit backup.
 */
final class FileRef {

	/**
	 * Path relative to the uploads directory, such as "2026/11/photo-300x200.jpg".
	 *
	 * @var string
	 */
	public $relative;

	/**
	 * Role: "full", a size name, "original_image", "backup:<size>" or "source:<mime>".
	 *
	 * @var string
	 */
	public $role;

	/**
	 * Constructor.
	 *
	 * @param string $relative Relative path.
	 * @param string $role Role.
	 */
	public function __construct( string $relative, string $role ) {
		$this->relative = $relative;
		$this->role     = $role;
	}

	/**
	 * Absolute path under an uploads base directory.
	 *
	 * @param string $basedir Uploads base directory.
	 */
	public function path( string $basedir ): string {
		return rtrim( $basedir, '/\\' ) . '/' . $this->relative;
	}
}
