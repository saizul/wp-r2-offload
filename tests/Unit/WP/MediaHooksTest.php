<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\WP;

use Saizul\R2Offload\Tests\WpTestCase;
use Saizul\R2Offload\WP\Media\AttachmentOffloader;
use Saizul\R2Offload\WP\Media\MediaHooks;
use WpStubs;

final class MediaHooksTest extends WpTestCase {

	private function hooks(): MediaHooks {
		$hooks = new MediaHooks( $this->services() );
		$hooks->register();

		return $hooks;
	}

	public function test_registers_hooks_from_config(): void {
		$this->hooks();
		foreach ( array( 'wp_generate_attachment_metadata', 'wp_update_attachment_metadata', 'delete_attachment', 'wp_get_attachment_url', 'wp_calculate_image_srcset', 'the_content', 'load_image_to_edit_path' ) as $hook ) {
			self::assertArrayHasKey( $hook, WpStubs::$filters, $hook );
		}

		WpStubs::$filters                               = array();
		$this->constants['WP_R2_OFFLOAD_ENABLED']       = false;
		$this->constants['WP_R2_OFFLOAD_REWRITE_URLS']  = false;
		$this->hooks();
		self::assertArrayNotHasKey( 'wp_generate_attachment_metadata', WpStubs::$filters );
		self::assertArrayNotHasKey( 'wp_get_attachment_url', WpStubs::$filters );
		self::assertArrayHasKey( 'delete_attachment', WpStubs::$filters );
	}

	public function test_upload_flow_keeps_files_until_metadata_is_saved(): void {
		$this->constants['WP_R2_OFFLOAD_REMOVE_LOCAL'] = true;
		$this->attachment();
		$meta = WpStubs::$meta[10]['_wp_attachment_metadata'];
		unset( WpStubs::$meta[10]['_wp_attachment_metadata'] );
		$this->hooks();

		// WordPress calls this before the metadata is stored.
		self::assertSame( $meta, apply_filters( 'wp_generate_attachment_metadata', $meta, 10, 'create' ) );
		self::assertCount( 3, $this->bucket );
		self::assertFileExists( $this->uploads . '/2026/11/photo.jpg' );

		WpStubs::$meta[10]['_wp_attachment_metadata'] = $meta;
		apply_filters( 'wp_update_attachment_metadata', $meta, 10 );
		self::assertFileDoesNotExist( $this->uploads . '/2026/11/photo.jpg' );
		self::assertTrue( AttachmentOffloader::record( 10 )->local_removed );
	}

	public function test_update_ignores_attachments_that_were_never_offloaded(): void {
		$this->attachment();
		$this->hooks();
		apply_filters( 'wp_update_attachment_metadata', WpStubs::$meta[10]['_wp_attachment_metadata'], 10 );

		self::assertSame( array(), $this->bucket );
	}

	public function test_urls_srcset_and_content(): void {
		$this->attachment();
		$this->services()->offloader()->offload( 10 );
		$this->hooks();

		self::assertSame( 'https://media.example.com/wp-content/uploads/2026/11/photo.jpg', apply_filters( 'wp_get_attachment_url', 'https://shop.test/wp-content/uploads/2026/11/photo.jpg', 10 ) );
		self::assertSame( 'https://shop.test/wp-content/uploads/2026/11/x.jpg', apply_filters( 'wp_get_attachment_url', 'https://shop.test/wp-content/uploads/2026/11/x.jpg', 11 ) );

		$src = apply_filters( 'wp_get_attachment_image_src', array( 'https://shop.test/wp-content/uploads/2026/11/photo-300x200.jpg', 300, 200, true ), 10, 'medium', false );
		self::assertSame( 'https://media.example.com/wp-content/uploads/2026/11/photo-300x200.jpg', $src[0] );
		self::assertFalse( apply_filters( 'wp_get_attachment_image_src', false, 10, 'medium', false ) );

		$sources = apply_filters(
			'wp_calculate_image_srcset',
			array(
				150 => array(
					'url'        => 'https://shop.test/wp-content/uploads/2026/11/photo-150x150.jpg',
					'descriptor' => 'w',
					'value'      => 150,
				),
			),
			array( 300, 200 ),
			'',
			array(),
			10
		);
		self::assertSame( 'https://media.example.com/wp-content/uploads/2026/11/photo-150x150.jpg', $sources[150]['url'] );

		$html = '<p><img class="wp-image-10 size-medium" src="https://shop.test/wp-content/uploads/2026/11/photo-300x200.jpg"> <img src="https://shop.test/wp-content/uploads/2026/11/other.jpg"></p>';
		self::assertSame(
			'<p><img class="wp-image-10 size-medium" src="https://media.example.com/wp-content/uploads/2026/11/photo-300x200.jpg"> <img src="https://shop.test/wp-content/uploads/2026/11/other.jpg"></p>',
			apply_filters( 'the_content', $html )
		);
		self::assertSame( '<p>No images</p>', apply_filters( 'the_content', '<p>No images</p>' ) );
	}

	public function test_delete_attachment_removes_objects(): void {
		$this->attachment();
		$this->services()->offloader()->offload( 10 );
		$this->hooks();
		do_action( 'delete_attachment', 10 );

		self::assertSame( array(), $this->bucket );
	}

	public function test_edit_path_restores_the_original(): void {
		$this->constants['WP_R2_OFFLOAD_REMOVE_LOCAL'] = true;
		$this->attachment();
		$this->services()->offloader()->offload( 10 );
		$hooks = $this->hooks();
		$local = $this->uploads . '/2026/11/photo.jpg';

		self::assertFileDoesNotExist( $local );
		self::assertSame( $local, $hooks->edit_path( 'https://media.example.com/x.jpg', 10 ) );
		self::assertSame( str_repeat( 'F', 300 ), file_get_contents( $local ) );
		self::assertSame( $local, $hooks->edit_path( $local, 10 ) );
		self::assertFalse( $hooks->edit_path( false, 99 ) );
	}

	public function test_unique_filename_avoids_offloaded_names(): void {
		$this->constants['WP_R2_OFFLOAD_REMOVE_LOCAL'] = true;
		$this->attachment();
		WpStubs::$meta[11] = array( '_wp_attached_file' => '2026/11/photo-1.jpg' );
		$hooks             = $this->hooks();

		self::assertSame( 'photo-2.jpg', $hooks->unique_filename( 'photo.jpg', '.jpg', $this->uploads . '/2026/11' ) );
		self::assertSame( 'new.jpg', $hooks->unique_filename( 'new.jpg', '.jpg', $this->uploads . '/2026/11' ) );

		$this->constants['WP_R2_OFFLOAD_REMOVE_LOCAL'] = false;
		self::assertSame( 'photo.jpg', ( new MediaHooks( $this->services() ) )->unique_filename( 'photo.jpg', '.jpg', $this->uploads . '/2026/11' ) );
	}
}
