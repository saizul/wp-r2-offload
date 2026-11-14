<?php
/**
 * Resumable multipart upload state.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\S3;

/**
 * What has been uploaded so far, so an interrupted upload can continue.
 */
final class MultipartState {

	/**
	 * Key.
	 *
	 * @var string
	 */
	public $key;

	/**
	 * Upload id.
	 *
	 * @var string
	 */
	public $upload_id;

	/**
	 * Part size in bytes.
	 *
	 * @var int
	 */
	public $part_size;

	/**
	 * File size when the upload started.
	 *
	 * @var int
	 */
	public $file_size;

	/**
	 * File modification time when the upload started.
	 *
	 * @var int
	 */
	public $file_mtime;

	/**
	 * Uploaded parts: number => ETag.
	 *
	 * @var array<int,string>
	 */
	public $parts = array();

	/**
	 * Constructor.
	 *
	 * @param string            $key Key.
	 * @param string            $upload_id Upload id.
	 * @param int               $part_size Part size.
	 * @param int               $file_size File size.
	 * @param int               $file_mtime File mtime.
	 * @param array<int,string> $parts Parts.
	 */
	public function __construct( string $key, string $upload_id, int $part_size, int $file_size, int $file_mtime, array $parts = array() ) {
		$this->key        = $key;
		$this->upload_id  = $upload_id;
		$this->part_size  = $part_size;
		$this->file_size  = $file_size;
		$this->file_mtime = $file_mtime;
		$this->parts      = $parts;
	}

	/**
	 * Number of parts for the file.
	 */
	public function part_count(): int {
		return max( 1, (int) ceil( $this->file_size / $this->part_size ) );
	}

	/**
	 * Whether the state belongs to this file and key.
	 *
	 * @param string $key Key.
	 * @param int    $size File size.
	 * @param int    $mtime File mtime.
	 */
	public function matches( string $key, int $size, int $mtime ): bool {
		return $this->key === $key && $this->file_size === $size && $this->file_mtime === $mtime && '' !== $this->upload_id;
	}

	/**
	 * Array form for storage.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'key'        => $this->key,
			'upload_id'  => $this->upload_id,
			'part_size'  => $this->part_size,
			'file_size'  => $this->file_size,
			'file_mtime' => $this->file_mtime,
			'parts'      => $this->parts,
		);
	}

	/**
	 * Restore from storage.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	public static function from_array( array $data ): ?self {
		if ( empty( $data['key'] ) || empty( $data['upload_id'] ) || empty( $data['part_size'] ) ) {
			return null;
		}
		$parts = array();
		foreach ( (array) ( $data['parts'] ?? array() ) as $number => $etag ) {
			$parts[ (int) $number ] = (string) $etag;
		}

		return new self( (string) $data['key'], (string) $data['upload_id'], (int) $data['part_size'], (int) ( $data['file_size'] ?? 0 ), (int) ( $data['file_mtime'] ?? 0 ), $parts );
	}
}
