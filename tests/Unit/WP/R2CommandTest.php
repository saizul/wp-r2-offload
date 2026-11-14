<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\WP;

use Saizul\R2Offload\Bulk\AttachmentSource;
use Saizul\R2Offload\Tests\WpTestCase;
use Saizul\R2Offload\WP\Cli\R2Command;
use Saizul\R2Offload\WP\Media\AttachmentOffloader;
use WP_CLI;
use WpCliExit;
use WpStubs;

final class R2CommandTest extends WpTestCase {

	/**
	 * Source over the stub meta.
	 */
	private function source(): AttachmentSource {
		return new class() implements AttachmentSource {
			public function ids_after( int $after_id, int $limit ): array {
				$ids = array_keys( WpStubs::$meta );
				sort( $ids );
				return array_slice(
					array_values(
						array_filter(
							$ids,
							static function ( int $id ) use ( $after_id ): bool {
								return $id > $after_id;
							}
						)
					),
					0,
					$limit
				);
			}

			public function offloaded_ids_after( int $after_id, int $limit ): array {
				return array_values(
					array_filter(
						$this->ids_after( $after_id, 1000 ),
						static function ( int $id ): bool {
							return isset( WpStubs::$meta[ $id ][ AttachmentOffloader::META ] );
						}
					)
				);
			}

			public function total(): int {
				return count( WpStubs::$meta );
			}

			public function offloaded(): int {
				return count( $this->offloaded_ids_after( 0, 1000 ) );
			}
		};
	}

	private function command(): R2Command {
		return new R2Command( $this->services(), $this->source() );
	}

	/**
	 * @return string[]
	 */
	private function lines(): array {
		return array_map(
			static function ( array $entry ): string {
				return $entry[0] . ': ' . $entry[1];
			},
			WP_CLI::$log
		);
	}

	public function test_offload_with_limit_resumes(): void {
		$this->attachment( 10, 'a' );
		$this->attachment( 11, 'b' );
		$this->attachment( 12, 'c' );

		$this->command()->offload( array(), array( 'batch' => '1', 'limit' => '2' ) );
		self::assertContains( 'success: Stopped after attachment 11. 2 processed: 2 offloaded, 0 skipped, 0 failed, 940 B uploaded. Run the command again to continue.', $this->lines() );
		self::assertSame( 11, WpStubs::$options[ R2Command::STATE_OPTION ]['cursor'] );

		WP_CLI::reset();
		$this->command()->offload( array(), array() );
		self::assertContains( 'log: Resuming after attachment 11 (2 processed so far).', $this->lines() );
		self::assertContains( 'log: #12 offloaded Uploaded 3 file(s).', $this->lines() );
		self::assertContains( 'success: Finished. 3 processed: 3 offloaded, 0 skipped, 0 failed, 1.4 KB uploaded.', $this->lines() );
		self::assertCount( 9, $this->bucket );

		WP_CLI::reset();
		$this->command()->offload( array(), array( 'reset' => true ) );
		self::assertContains( 'success: Finished. 3 processed: 0 offloaded, 3 skipped, 0 failed, 0 B uploaded.', $this->lines() );
	}

	public function test_dry_run(): void {
		$this->attachment();
		$this->command()->offload( array(), array( 'dry-run' => true ) );

		self::assertContains( 'success: Dry run. 1 processed: 1 offloaded, 0 skipped, 0 failed, 470 B uploaded.', $this->lines() );
		self::assertSame( array(), $this->bucket );
		self::assertArrayNotHasKey( R2Command::STATE_OPTION, WpStubs::$options );
	}

	public function test_offload_requires_config(): void {
		unset( $this->constants['WP_R2_OFFLOAD_SECRET_ACCESS_KEY'] );

		$this->expectException( WpCliExit::class );
		$this->command()->offload( array(), array() );
	}

	public function test_status(): void {
		$this->attachment( 10, 'a' );
		$this->attachment( 11, 'b' );
		$this->services()->offloader()->offload( 10 );
		$this->command()->status( array(), array( 'format' => 'json' ) );

		list( $format, $rows ) = WP_CLI::$items[0];
		self::assertSame( 'json', $format );
		$by_key = array_column( $rows, null, 'setting' );
		self::assertSame( '********', $by_key['secret_access_key']['value'] );
		self::assertSame( 'constant', $by_key['secret_access_key']['source'] );
		self::assertSame( 'default', $by_key['key_prefix']['source'] );
		self::assertSame( '1 of 2 offloaded (1 pending)', $by_key['attachments']['value'] );
		self::assertSame( 'never run', $by_key['bulk_run']['value'] );
	}

	public function test_verify_and_fix(): void {
		$this->attachment();
		$this->services()->offloader()->offload( 10 );
		$this->command()->verify( array(), array() );
		self::assertSame( 'success: 3 object(s) verified.', end( WP_CLI::$log )[0] . ': ' . end( WP_CLI::$log )[1] );

		unset( $this->bucket['wp-content/uploads/2026/11/photo-150x150.jpg'] );
		$this->bucket['wp-content/uploads/2026/11/photo.jpg']['body'] = 'truncated';
		WP_CLI::reset();
		try {
			$this->command()->verify( array( '10' ), array( 'fix' => true ) );
			self::fail( 'Expected an error.' );
		} catch ( WpCliExit $e ) {
			self::assertSame( '2 problem(s) in 3 object(s).', $e->getMessage() );
		}
		self::assertContains( 'warning: #10 missing: wp-content/uploads/2026/11/photo-150x150.jpg', $this->lines() );
		self::assertContains( 'warning: #10 size differs: 2026/11/photo.jpg (expected 300, found 9)', $this->lines() );
		self::assertContains( 'log: #10 offloaded Uploaded 2 file(s).', $this->lines() );
		self::assertSame( str_repeat( 'F', 300 ), $this->bucket['wp-content/uploads/2026/11/photo.jpg']['body'] );

		WP_CLI::reset();
		$this->command()->verify( array(), array() );
		self::assertSame( 'success', end( WP_CLI::$log )[0] );
	}

	public function test_restore(): void {
		$this->constants['WP_R2_OFFLOAD_REMOVE_LOCAL'] = true;
		$this->attachment();
		$this->services()->offloader()->offload( 10 );
		self::assertFileDoesNotExist( $this->uploads . '/2026/11/photo.jpg' );

		$this->command()->restore( array(), array( 'all' => true ) );
		self::assertContains( 'success: 3 file(s) downloaded.', $this->lines() );
		self::assertFileExists( $this->uploads . '/2026/11/photo-300x200.jpg' );

		$this->expectException( WpCliExit::class );
		$this->command()->restore( array(), array() );
	}
}
