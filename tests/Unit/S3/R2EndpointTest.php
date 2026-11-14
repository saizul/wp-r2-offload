<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\S3;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\S3\R2Endpoint;

final class R2EndpointTest extends TestCase {

	private const ACCOUNT = '0123456789ABCDEF0123456789abcdef';

	public function test_default_endpoint(): void {
		$endpoint = new R2Endpoint( self::ACCOUNT, 'media' );

		self::assertSame( 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com', $endpoint->endpoint() );
		self::assertSame( 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com/media/', $endpoint->bucket_url() );
		self::assertSame( 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com/media/?delete=', $endpoint->bucket_url( 'delete=' ) );
		self::assertSame( 'auto', R2Endpoint::REGION );
	}

	public function test_jurisdictions(): void {
		self::assertSame( 'https://0123456789abcdef0123456789abcdef.eu.r2.cloudflarestorage.com', ( new R2Endpoint( self::ACCOUNT, 'media', 'eu' ) )->endpoint() );
		self::assertSame( 'https://0123456789abcdef0123456789abcdef.fedramp.r2.cloudflarestorage.com', ( new R2Endpoint( self::ACCOUNT, 'media', 'fedramp' ) )->endpoint() );
	}

	public function test_object_urls_encode_each_segment(): void {
		$endpoint = new R2Endpoint( self::ACCOUNT, 'media' );

		self::assertSame(
			'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com/media/wp-content/uploads/2026/11/my%20photo%2B%23.jpg?uploads=',
			$endpoint->object_url( '/wp-content/uploads/2026/11/my photo+#.jpg', 'uploads=' )
		);
		self::assertSame( 'a/%E0%A6%95.png', R2Endpoint::encode_key( 'a/ক.png' ) );
	}

	public function test_custom_endpoint(): void {
		$endpoint = new R2Endpoint( '', 'media', '', 'http://127.0.0.1:9000/' );

		self::assertSame( 'http://127.0.0.1:9000/media/a.txt', $endpoint->object_url( 'a.txt' ) );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provide_buckets(): array {
		return array(
			'simple'           => array( 'media', true ),
			'digits & hyphens' => array( 'shop-media-2026', true ),
			'too short'        => array( 'ab', false ),
			'too long'         => array( str_repeat( 'a', 64 ), false ),
			'max length'       => array( str_repeat( 'a', 63 ), true ),
			'upper case'       => array( 'Media', false ),
			'underscore'       => array( 'my_media', false ),
			'dots'             => array( 'my.media', false ),
			'leading hyphen'   => array( '-media', false ),
			'trailing hyphen'  => array( 'media-', false ),
		);
	}

	/**
	 * @dataProvider provide_buckets
	 */
	public function test_bucket_names( string $bucket, bool $valid ): void {
		self::assertSame( $valid, R2Endpoint::is_valid_bucket( $bucket ) );
	}

	public function test_rejects_invalid_account(): void {
		$this->expectException( InvalidArgumentException::class );
		new R2Endpoint( 'not-an-account', 'media' );
	}

	public function test_rejects_invalid_jurisdiction(): void {
		$this->expectException( InvalidArgumentException::class );
		new R2Endpoint( self::ACCOUNT, 'media', 'us' );
	}

	public function test_rejects_invalid_bucket(): void {
		$this->expectException( InvalidArgumentException::class );
		new R2Endpoint( self::ACCOUNT, 'Bad_Bucket' );
	}

	public function test_rejects_endpoint_with_path(): void {
		$this->expectException( InvalidArgumentException::class );
		new R2Endpoint( '', 'media', '', 'https://s3.example.com/path' );
	}
}
