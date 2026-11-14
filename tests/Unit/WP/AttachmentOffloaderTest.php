<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\WP;

use Saizul\R2Offload\Bulk\OffloadResult;
use Saizul\R2Offload\Tests\WpTestCase;
use Saizul\R2Offload\WP\Media\AttachmentOffloader;
use WpStubs;

final class AttachmentOffloaderTest extends WpTestCase {

	public function test_offloads_original_and_every_size(): void {
		$this->attachment();
		$result = $this->services()->offloader()->offload( 10 );

		self::assertSame( OffloadResult::OFFLOADED, $result->status );
		self::assertSame( 3, $result->files );
		self::assertSame( 470, $result->bytes );
		self::assertSame(
			array( 'wp-content/uploads/2026/11/photo.jpg', 'wp-content/uploads/2026/11/photo-300x200.jpg', 'wp-content/uploads/2026/11/photo-150x150.jpg' ),
			array_keys( $this->bucket )
		);
		self::assertSame( 'image/jpeg', $this->bucket['wp-content/uploads/2026/11/photo.jpg']['type'] );
		self::assertSame( 'public, max-age=31536000, immutable', $this->bucket['wp-content/uploads/2026/11/photo.jpg']['cache'] );
		self::assertSame( str_repeat( 'F', 300 ), $this->bucket['wp-content/uploads/2026/11/photo.jpg']['body'] );

		$record = AttachmentOffloader::record( 10 );
		self::assertSame( 'media', $record->bucket );
		self::assertSame( 'wp-content/uploads/2026/11/photo-150x150.jpg', $record->key( '2026/11/photo-150x150.jpg' ) );
		self::assertFalse( $record->local_removed );
		self::assertFileExists( $this->uploads . '/2026/11/photo.jpg' );

		$again = $this->services()->offloader()->offload( 10 );
		self::assertSame( OffloadResult::SKIPPED, $again->status );
		self::assertSame( 'Already offloaded.', $again->message );
	}

	public function test_dry_run_uploads_nothing(): void {
		$this->attachment();
		$result = $this->services()->offloader()->offload( 10, true );

		self::assertSame( 'Would upload 3 file(s).', $result->message );
		self::assertSame( 470, $result->bytes );
		self::assertSame( array(), $this->bucket );
		self::assertNull( AttachmentOffloader::record( 10 ) );
	}

	public function test_remove_local_and_restore(): void {
		$this->constants['WP_R2_OFFLOAD_REMOVE_LOCAL'] = true;
		$this->attachment();
		$services = $this->services();
		$services->offloader()->offload( 10 );

		self::assertFileDoesNotExist( $this->uploads . '/2026/11/photo.jpg' );
		self::assertFileDoesNotExist( $this->uploads . '/2026/11/photo-150x150.jpg' );
		self::assertTrue( AttachmentOffloader::record( 10 )->local_removed );

		self::assertSame( 1, $services->offloader()->restore_local( 10, true ) );
		self::assertFileExists( $this->uploads . '/2026/11/photo.jpg' );
		self::assertFileDoesNotExist( $this->uploads . '/2026/11/photo-150x150.jpg' );
		self::assertTrue( AttachmentOffloader::record( 10 )->local_removed, 'Partial restore keeps the flag.' );

		self::assertSame( 2, $services->offloader()->restore_local( 10 ) );
		self::assertSame( str_repeat( 'T', 50 ), file_get_contents( $this->uploads . '/2026/11/photo-150x150.jpg' ) );
		self::assertFalse( AttachmentOffloader::record( 10 )->local_removed );
	}

	public function test_edits_upload_only_new_files(): void {
		$this->attachment();
		$services = $this->services();
		$services->offloader()->offload( 10 );

		file_put_contents( $this->uploads . '/2026/11/photo-e1700000000.jpg', 'EDITED' );
		$meta                                       = WpStubs::$meta[10]['_wp_attachment_metadata'];
		$meta['sizes']['medium']['file']            = 'photo-e1700000000.jpg';
		WpStubs::$meta[10]['_wp_attachment_metadata'] = $meta;
		$this->http->requests                       = array();

		$result = $services->offloader()->offload( 10 );
		self::assertSame( 1, $result->files );
		self::assertCount( 1, $this->http->requests );
		self::assertSame( 'EDITED', $this->bucket['wp-content/uploads/2026/11/photo-e1700000000.jpg']['body'] );
		self::assertCount( 4, AttachmentOffloader::record( 10 )->files, 'Old size stays recorded for existing posts.' );
	}

	public function test_delete_remote(): void {
		$this->attachment();
		$services = $this->services();
		$services->offloader()->offload( 10 );

		self::assertSame( array(), $services->offloader()->delete_remote( 10 ) );
		self::assertSame( array(), $this->bucket );
		self::assertNull( AttachmentOffloader::record( 10 ) );
		self::assertSame( array(), $services->offloader()->delete_remote( 10 ) );
	}

	public function test_multipart_above_threshold(): void {
		$this->constants['WP_R2_OFFLOAD_MULTIPART_THRESHOLD'] = 8;
		$this->constants['WP_R2_OFFLOAD_PART_SIZE']           = 5;
		file_put_contents( $this->uploads . '/2026/11/video.mp4', str_repeat( 'V', 9 * 1048576 ) );
		WpStubs::$meta[20] = array( '_wp_attached_file' => '2026/11/video.mp4' );

		$result = $this->services()->offloader()->offload( 20 );

		self::assertSame( OffloadResult::OFFLOADED, $result->status );
		self::assertSame( 9 * 1048576, strlen( $this->bucket['wp-content/uploads/2026/11/video.mp4']['body'] ) );
		self::assertSame( 'video/mp4', $this->bucket['wp-content/uploads/2026/11/video.mp4']['type'] );
		self::assertArrayNotHasKey( AttachmentOffloader::META_MULTIPART, WpStubs::$meta[20], 'Resume state is removed after completion.' );
		$parts = array_filter(
			$this->http->requests,
			static function ( $request ): bool {
				return false !== strpos( $request->url(), 'partNumber=' );
			}
		);
		self::assertCount( 2, $parts );
	}

	public function test_failures_are_recorded(): void {
		$this->attachment();
		$this->http = new \Saizul\R2Offload\Http\FakeHttpClient(
			static function () {
				return new \Saizul\R2Offload\Http\Response( 403, '<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>' );
			}
		);
		$result = $this->services()->offloader()->offload( 10 );

		self::assertSame( OffloadResult::FAILED, $result->status );
		self::assertStringContainsString( '2026/11/photo.jpg: AccessDenied: Access Denied', $result->message );
		self::assertStringContainsString( 'AccessDenied', WpStubs::$meta[10][ AttachmentOffloader::META_ERROR ] );
		self::assertFileExists( $this->uploads . '/2026/11/photo.jpg' );
	}

	public function test_incomplete_config_and_filters(): void {
		$this->attachment();
		unset( $this->constants['WP_R2_OFFLOAD_BUCKET'] );
		self::assertSame( OffloadResult::FAILED, $this->services()->offloader()->offload( 10 )->status );

		$this->constants['WP_R2_OFFLOAD_BUCKET'] = 'media';
		add_filter( 'wp_r2_offload_should_offload', __NAMESPACE__ . '\__return_false_stub' );
		self::assertSame( OffloadResult::SKIPPED, $this->services()->offloader()->offload( 10 )->status );

		WpStubs::$filters = array();
		self::assertSame( 'No file attached.', $this->services()->offloader()->offload( 99 )->message );
		WpStubs::$meta[30] = array( '_wp_attached_file' => '2026/11/gone.jpg' );
		self::assertSame( 'The files are not on this server.', $this->services()->offloader()->offload( 30 )->message );
	}
}

function __return_false_stub(): bool {
	return false;
}
