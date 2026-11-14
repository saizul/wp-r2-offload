<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\Media\AttachmentFiles;
use Saizul\R2Offload\Media\FileRef;

final class AttachmentFilesTest extends TestCase {

	/**
	 * @param FileRef[] $files Files.
	 * @return array<string,string>
	 */
	private static function map( array $files ): array {
		$map = array();
		foreach ( $files as $file ) {
			$map[ $file->relative ] = $file->role;
		}
		return $map;
	}

	public function test_scaled_image_with_sizes_sources_and_backups(): void {
		$files = ( new AttachmentFiles() )->collect(
			'2026/11/photo-scaled.jpg',
			array(
				'file'           => '2026/11/photo-scaled.jpg',
				'original_image' => 'photo.jpg',
				'sources'        => array( 'image/webp' => array( 'file' => 'photo-scaled.webp' ) ),
				'sizes'          => array(
					'medium'       => array(
						'file'    => 'photo-300x200.jpg',
						'sources' => array( 'image/webp' => array( 'file' => 'photo-300x200.webp' ) ),
					),
					'thumbnail'    => array( 'file' => 'photo-150x150.jpg' ),
					'medium_large' => array( 'file' => 'photo-300x200.jpg' ),
					'broken'       => 'not an array',
					'evil'         => array( 'file' => '../../wp-config.php' ),
				),
			),
			array(
				'full-orig'      => array( 'file' => 'photo-orig.jpg' ),
				'thumbnail-orig' => array( 'file' => 'photo-150x150-orig.jpg' ),
			)
		);

		self::assertSame(
			array(
				'2026/11/photo-scaled.jpg'        => 'full',
				'2026/11/photo.jpg'               => 'original_image',
				'2026/11/photo-scaled.webp'       => 'source:image/webp',
				'2026/11/photo-300x200.jpg'       => 'medium',
				'2026/11/photo-300x200.webp'      => 'medium:image/webp',
				'2026/11/photo-150x150.jpg'       => 'thumbnail',
				'2026/11/photo-orig.jpg'          => 'backup:full-orig',
				'2026/11/photo-150x150-orig.jpg'  => 'backup:thumbnail-orig',
			),
			self::map( $files )
		);
		self::assertSame( '/var/www/uploads/2026/11/photo.jpg', $files[1]->path( '/var/www/uploads/' ) );
	}

	public function test_non_image_and_root_files(): void {
		self::assertSame( array( 'manual.pdf' => 'full' ), self::map( ( new AttachmentFiles() )->collect( 'manual.pdf', array() ) ) );
		self::assertSame( array(), ( new AttachmentFiles() )->collect( '', array() ) );
	}

	public function test_absolute_paths_are_relativised(): void {
		$files = ( new AttachmentFiles() )->collect( '/srv/uploads/2026/11/a.png', array( 'sizes' => array( 'x' => array( 'file' => 'a-1x1.png' ) ) ), array(), '/srv/uploads' );

		self::assertSame( array( '2026/11/a.png', '2026/11/a-1x1.png' ), AttachmentFiles::relatives( $files ) );
	}

	public function test_relative(): void {
		self::assertSame( '2026/11/a.png', AttachmentFiles::relative( 'C:\\uploads\\2026\\11\\a.png', 'C:\\uploads' ) );
		self::assertSame( '', AttachmentFiles::relative( '2026/../../etc/passwd', '' ) );
		self::assertSame( 'a.png', AttachmentFiles::relative( '/a.png', '' ) );
	}
}
