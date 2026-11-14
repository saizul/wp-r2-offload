<?php
/**
 * HEAD result.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\S3;

/**
 * Size, ETag and type of a stored object.
 */
final class ObjectMeta {

	/**
	 * Key.
	 *
	 * @var string
	 */
	public $key;

	/**
	 * Size in bytes.
	 *
	 * @var int
	 */
	public $size;

	/**
	 * ETag without quotes.
	 *
	 * @var string
	 */
	public $etag;

	/**
	 * Content type.
	 *
	 * @var string
	 */
	public $content_type;

	/**
	 * Last-Modified header.
	 *
	 * @var string
	 */
	public $last_modified;

	/**
	 * Constructor.
	 *
	 * @param string $key Key.
	 * @param int    $size Size.
	 * @param string $etag ETag.
	 * @param string $content_type Type.
	 * @param string $last_modified Last-Modified.
	 */
	public function __construct( string $key, int $size, string $etag = '', string $content_type = '', string $last_modified = '' ) {
		$this->key           = $key;
		$this->size          = $size;
		$this->etag          = trim( $etag, '"' );
		$this->content_type  = $content_type;
		$this->last_modified = $last_modified;
	}
}
