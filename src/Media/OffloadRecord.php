<?php
/**
 * Where an attachment lives in the bucket.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Media;

/**
 * Stored as the `_wp_r2_offload` attachment meta.
 */
final class OffloadRecord {

	public const VERSION = 1;

	/**
	 * Bucket.
	 *
	 * @var string
	 */
	public $bucket;

	/**
	 * Relative path => object key.
	 *
	 * @var array<string,string>
	 */
	public $files = array();

	/**
	 * Relative path => size in bytes when uploaded.
	 *
	 * @var array<string,int>
	 */
	public $sizes = array();

	/**
	 * Whether local copies were deleted.
	 *
	 * @var bool
	 */
	public $local_removed = false;

	/**
	 * Time of the last upload.
	 *
	 * @var int
	 */
	public $offloaded_at = 0;

	/**
	 * Constructor.
	 *
	 * @param string $bucket Bucket.
	 */
	public function __construct( string $bucket ) {
		$this->bucket = $bucket;
	}

	/**
	 * Record an uploaded file.
	 *
	 * @param string $relative Relative path.
	 * @param string $key Object key.
	 * @param int    $size Size in bytes.
	 */
	public function add( string $relative, string $key, int $size ): void {
		$this->files[ $relative ] = $key;
		$this->sizes[ $relative ] = $size;
	}

	/**
	 * Forget a file.
	 *
	 * @param string $relative Relative path.
	 */
	public function remove( string $relative ): void {
		unset( $this->files[ $relative ], $this->sizes[ $relative ] );
	}

	/**
	 * Whether a file is in the bucket.
	 *
	 * @param string $relative Relative path.
	 */
	public function has( string $relative ): bool {
		return isset( $this->files[ $relative ] );
	}

	/**
	 * Key of a file, or null.
	 *
	 * @param string $relative Relative path.
	 */
	public function key( string $relative ): ?string {
		return $this->files[ $relative ] ?? null;
	}

	/**
	 * All keys.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_values( $this->files );
	}

	/**
	 * Files of the list that are not in the bucket yet.
	 *
	 * @param string[] $relatives Relative paths.
	 * @return string[]
	 */
	public function missing( array $relatives ): array {
		return array_values(
			array_filter(
				$relatives,
				function ( string $relative ): bool {
					return ! $this->has( $relative );
				}
			)
		);
	}

	/**
	 * Array form for post meta.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'v'             => self::VERSION,
			'provider'      => 'r2',
			'bucket'        => $this->bucket,
			'files'         => $this->files,
			'sizes'         => $this->sizes,
			'local_removed' => $this->local_removed,
			'offloaded_at'  => $this->offloaded_at,
		);
	}

	/**
	 * Restore from post meta.
	 *
	 * @param mixed $data Meta value.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || empty( $data['bucket'] ) || ! isset( $data['files'] ) || ! is_array( $data['files'] ) ) {
			return null;
		}
		$record = new self( (string) $data['bucket'] );
		foreach ( $data['files'] as $relative => $key ) {
			if ( is_string( $relative ) && is_string( $key ) && '' !== $key ) {
				$record->files[ $relative ] = $key;
				$record->sizes[ $relative ] = (int) ( $data['sizes'][ $relative ] ?? 0 );
			}
		}
		$record->local_removed = ! empty( $data['local_removed'] );
		$record->offloaded_at  = (int) ( $data['offloaded_at'] ?? 0 );

		return $record;
	}
}
