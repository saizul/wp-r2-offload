<?php
/**
 * Checks offloaded objects.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Verify;

use Saizul\R2Offload\Media\OffloadRecord;
use Saizul\R2Offload\S3\S3Client;
use Saizul\R2Offload\S3\S3Exception;

/**
 * Sends HEAD for every key of a record and compares sizes with the local
 * file (when it still exists) or the size recorded at upload time.
 */
final class Verifier {

	/**
	 * Client.
	 *
	 * @var S3Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param S3Client $client Client.
	 */
	public function __construct( S3Client $client ) {
		$this->client = $client;
	}

	/**
	 * Verify a record.
	 *
	 * @param OffloadRecord     $record Record.
	 * @param array<string,int> $local_sizes Relative path => current local size (missing when the file is gone).
	 */
	public function verify( OffloadRecord $record, array $local_sizes = array() ): VerifyReport {
		$report = new VerifyReport();
		foreach ( $record->files as $relative => $key ) {
			try {
				$meta = $this->client->head_object( $key );
			} catch ( S3Exception $e ) {
				$report->errors[ $relative ] = $e->getMessage();
				continue;
			}
			if ( null === $meta ) {
				$report->missing[ $relative ] = $key;
				continue;
			}
			$expected = $local_sizes[ $relative ] ?? ( $record->sizes[ $relative ] ?? 0 );
			if ( $expected > 0 && $expected !== $meta->size ) {
				$report->mismatched[ $relative ] = array( $expected, $meta->size );
				continue;
			}
			$report->ok[] = $key;
		}

		return $report;
	}
}
