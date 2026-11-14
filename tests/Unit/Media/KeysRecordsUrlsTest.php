<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\Media;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\Media\KeyBuilder;
use Saizul\R2Offload\Media\MimeTypes;
use Saizul\R2Offload\Media\OffloadRecord;
use Saizul\R2Offload\Media\UrlRewriter;

final class KeysRecordsUrlsTest extends TestCase {

	public function test_key_builder(): void {
		$keys = new KeyBuilder();

		self::assertSame( 'wp-content/uploads/', $keys->prefix() );
		self::assertSame( 'wp-content/uploads/2026/11/a.jpg', $keys->key( '2026/11/a.jpg' ) );
		self::assertSame( 'wp-content/uploads/2026/11/a.jpg', $keys->key( '/2026//11/a.jpg' ) );
		self::assertSame( 'sites/7/media/x.png', ( new KeyBuilder( '/sites/{site_id}/./media//', 7 ) )->key( 'x.png' ) );
		self::assertSame( 'x.png', ( new KeyBuilder( '' ) )->key( 'x.png' ) );
		self::assertSame( 'my-site/', KeyBuilder::normalize_prefix( '../my site' ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function provide_bad_paths(): array {
		return array(
			'empty'     => array( '' ),
			'parent'    => array( '2026/../x.jpg' ),
			'dot'       => array( './x.jpg' ),
			'too long'  => array( str_repeat( 'a', 1100 ) ),
		);
	}

	/**
	 * @dataProvider provide_bad_paths
	 */
	public function test_key_builder_rejects( string $path ): void {
		$this->expectException( InvalidArgumentException::class );
		( new KeyBuilder() )->key( $path );
	}

	public function test_record_round_trip(): void {
		$record = new OffloadRecord( 'media' );
		$record->add( '2026/11/a.jpg', 'p/2026/11/a.jpg', 300 );
		$record->add( '2026/11/a-150x150.jpg', 'p/2026/11/a-150x150.jpg', 50 );
		$record->local_removed = true;
		$record->offloaded_at  = 1763114400;

		$copy = OffloadRecord::from_array( $record->to_array() );
		self::assertSame( $record->files, $copy->files );
		self::assertSame( array( '2026/11/a.jpg' => 300, '2026/11/a-150x150.jpg' => 50 ), $copy->sizes );
		self::assertTrue( $copy->local_removed );
		self::assertSame( 'r2', $record->to_array()['provider'] );
		self::assertSame( array( '2026/11/new.jpg' ), $copy->missing( array( '2026/11/a.jpg', '2026/11/new.jpg' ) ) );

		$copy->remove( '2026/11/a.jpg' );
		self::assertFalse( $copy->has( '2026/11/a.jpg' ) );
		self::assertSame( array( 'p/2026/11/a-150x150.jpg' ), $copy->keys() );

		self::assertNull( OffloadRecord::from_array( '' ) );
		self::assertNull( OffloadRecord::from_array( array( 'bucket' => 'x' ) ) );
	}

	private function rewriter(): UrlRewriter {
		return new UrlRewriter( 'https://shop.test/wp-content/uploads/', 'https://media.example.com/' );
	}

	private function record(): OffloadRecord {
		$record = new OffloadRecord( 'media' );
		$record->add( '2026/11/a b.jpg', 'wp-content/uploads/2026/11/a b.jpg', 1 );
		$record->add( '2026/11/a b-300x200.jpg', 'wp-content/uploads/2026/11/a b-300x200.jpg', 1 );

		return $record;
	}

	public function test_rewrite_single_urls(): void {
		$rewriter = $this->rewriter();
		$record   = $this->record();

		self::assertSame( 'https://media.example.com/wp-content/uploads/2026/11/a%20b.jpg', $rewriter->rewrite( 'https://shop.test/wp-content/uploads/2026/11/a%20b.jpg', $record ) );
		self::assertSame( 'https://media.example.com/wp-content/uploads/2026/11/a%20b.jpg', $rewriter->rewrite( 'http://shop.test/wp-content/uploads/2026/11/a b.jpg?ver=2', $record ), 'Scheme and query string are ignored.' );
		self::assertSame( 'https://shop.test/wp-content/uploads/2026/11/other.jpg', $rewriter->rewrite( 'https://shop.test/wp-content/uploads/2026/11/other.jpg', $record ), 'Files not in the bucket stay local.' );
		self::assertSame( 'https://cdn.other/x.jpg', $rewriter->rewrite( 'https://cdn.other/x.jpg', $record ) );
		self::assertNull( $rewriter->relative_path( 'https://shop.test/wp-content/uploads/' ) );
	}

	public function test_rewrite_srcset(): void {
		$sources = array(
			300  => array(
				'url'        => 'https://shop.test/wp-content/uploads/2026/11/a%20b-300x200.jpg',
				'descriptor' => 'w',
				'value'      => 300,
			),
			1024 => array(
				'url'        => 'https://shop.test/wp-content/uploads/2026/11/a b-1024x683.jpg',
				'descriptor' => 'w',
				'value'      => 1024,
			),
			'x'  => 'not an array',
		);
		$out     = $this->rewriter()->rewrite_srcset( $sources, $this->record() );

		self::assertSame( 'https://media.example.com/wp-content/uploads/2026/11/a%20b-300x200.jpg', $out[300]['url'] );
		self::assertSame( $sources[1024]['url'], $out[1024]['url'] );
		self::assertSame( 'w', $out[300]['descriptor'] );
		self::assertSame( 'not an array', $out['x'] );
	}

	public function test_rewrite_html(): void {
		$record = $this->record();
		$html   = '<img class="wp-image-5" src="https://shop.test/wp-content/uploads/2026/11/a%20b.jpg" srcset="//shop.test/wp-content/uploads/2026/11/a%20b-300x200.jpg 300w, https://shop.test/wp-content/uploads/2026/11/a%20b.jpg 1024w"><a href="https://shop.test/wp-content/uploads/2026/11/doc.pdf">doc</a>';
		$out    = $this->rewriter()->rewrite_html(
			$html,
			static function ( string $relative ) use ( $record ): ?OffloadRecord {
				return $record->has( $relative ) ? $record : null;
			}
		);

		self::assertSame(
			'<img class="wp-image-5" src="https://media.example.com/wp-content/uploads/2026/11/a%20b.jpg" srcset="https://media.example.com/wp-content/uploads/2026/11/a%20b-300x200.jpg 300w, https://media.example.com/wp-content/uploads/2026/11/a%20b.jpg 1024w"><a href="https://shop.test/wp-content/uploads/2026/11/doc.pdf">doc</a>',
			$out
		);
	}

	public function test_url_for_key_encodes_segments(): void {
		self::assertSame( 'https://media.example.com/a/%E0%A6%95%2B1.jpg', $this->rewriter()->url_for_key( 'a/ক+1.jpg' ) );
	}

	public function test_mime_types(): void {
		self::assertSame( 'image/avif', MimeTypes::for_file( 'x/Photo.AVIF' ) );
		self::assertSame( 'video/mp4', MimeTypes::for_file( 'clip.mp4' ) );
		self::assertSame( 'application/octet-stream', MimeTypes::for_file( 'archive.xyz' ) );
		self::assertSame( 'application/octet-stream', MimeTypes::for_file( 'noext' ) );
	}
}
