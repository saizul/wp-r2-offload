<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests;

use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\Config\SecretBox;
use Saizul\R2Offload\Http\FakeHttpClient;
use Saizul\R2Offload\Http\Request;
use Saizul\R2Offload\Http\Response;
use Saizul\R2Offload\WP\Services;
use Saizul\R2Offload\WP\Settings;
use WP_CLI;
use WpStubs;

/**
 * Base class for WordPress-layer tests: a temporary uploads directory and an
 * in-memory fake bucket behind the fake HTTP client.
 */
abstract class WpTestCase extends TestCase {

	public const ACCOUNT = '0123456789abcdef0123456789abcdef';

	/** @var string */
	protected $uploads;

	/** @var array<string,mixed> */
	protected $constants = array();

	/** @var array<string,array{body:string,type:string,cache:string}> Fake bucket: key => object. */
	protected $bucket = array();

	/** @var FakeHttpClient */
	protected $http;

	protected function setUp(): void {
		WpStubs::reset();
		WP_CLI::reset();
		$this->uploads = sys_get_temp_dir() . '/wp-r2-offload-tests-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->uploads . '/2026/11', 0777, true );
		WpStubs::$uploads = array(
			'basedir' => $this->uploads,
			'baseurl' => 'https://shop.test/wp-content/uploads',
		);
		$this->constants = array(
			'WP_R2_OFFLOAD_ACCOUNT_ID'        => self::ACCOUNT,
			'WP_R2_OFFLOAD_ACCESS_KEY_ID'     => 'test-access-key',
			'WP_R2_OFFLOAD_SECRET_ACCESS_KEY' => 'test-secret-key',
			'WP_R2_OFFLOAD_BUCKET'            => 'media',
			'WP_R2_OFFLOAD_PUBLIC_URL'        => 'https://media.example.com',
		);
		$this->bucket = array();
		$this->http   = new FakeHttpClient( array( $this, 'fake_r2' ) );
	}

	protected function tearDown(): void {
		self::rmdir( $this->uploads );
	}

	private static function rmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? self::rmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	protected function settings(): Settings {
		$constants = &$this->constants;

		return new Settings(
			static function ( string $name ) use ( &$constants ) {
				return $constants[ $name ] ?? null;
			},
			new SecretBox( str_repeat( 'k', 32 ) )
		);
	}

	protected function services(): Services {
		return new Services(
			$this->settings(),
			$this->http,
			static function (): void {
			}
		);
	}

	/**
	 * A tiny S3 server: PUT, HEAD, GET, DELETE, multi-delete and multipart.
	 */
	public function fake_r2( Request $request ): Response {
		$prefix = 'https://' . self::ACCOUNT . '.r2.cloudflarestorage.com/media/';
		$url    = $request->url();
		if ( 0 !== strpos( $url, $prefix ) ) {
			return new Response( 404, '<Error><Code>NoSuchBucket</Code></Error>' );
		}
		$rest  = substr( $url, strlen( $prefix ) );
		$query = '';
		if ( false !== strpos( $rest, '?' ) ) {
			list( $rest, $query ) = explode( '?', $rest, 2 );
		}
		$key = rawurldecode( $rest );
		parse_str( $query, $params );

		if ( 'POST' === $request->method() && array_key_exists( 'delete', $params ) ) {
			preg_match_all( '#<Key>(.*?)</Key>#', $request->body(), $m );
			foreach ( $m[1] as $deleted ) {
				unset( $this->bucket[ htmlspecialchars_decode( $deleted, ENT_XML1 ) ] );
			}
			return new Response( 200, '<DeleteResult></DeleteResult>' );
		}
		if ( 'POST' === $request->method() && array_key_exists( 'uploads', $params ) ) {
			$this->bucket[ '__mpu__' . $key ] = array( 'body' => '', 'type' => (string) $request->header( 'Content-Type' ), 'cache' => '', 'parts' => array() );
			return new Response( 200, '<InitiateMultipartUploadResult><UploadId>UP-' . md5( $key ) . '</UploadId></InitiateMultipartUploadResult>' );
		}
		if ( 'PUT' === $request->method() && isset( $params['partNumber'] ) ) {
			$this->bucket[ '__mpu__' . $key ]['parts'][ (int) $params['partNumber'] ] = $request->body();
			return new Response( 200, '', array( 'ETag' => '"' . md5( $request->body() ) . '"' ) );
		}
		if ( 'POST' === $request->method() && isset( $params['uploadId'] ) ) {
			$upload = $this->bucket[ '__mpu__' . $key ];
			ksort( $upload['parts'] );
			$this->bucket[ $key ] = array( 'body' => implode( '', $upload['parts'] ), 'type' => $upload['type'], 'cache' => '' );
			unset( $this->bucket[ '__mpu__' . $key ] );
			return new Response( 200, '<CompleteMultipartUploadResult><ETag>"abc-2"</ETag></CompleteMultipartUploadResult>' );
		}
		switch ( $request->method() ) {
			case 'PUT':
				$this->bucket[ $key ] = array( 'body' => $request->body(), 'type' => (string) $request->header( 'Content-Type' ), 'cache' => (string) $request->header( 'Cache-Control' ) );
				return new Response( 200, '', array( 'ETag' => '"' . md5( $request->body() ) . '"' ) );
			case 'HEAD':
				return isset( $this->bucket[ $key ] ) ? new Response( 200, '', array( 'Content-Length' => (string) strlen( $this->bucket[ $key ]['body'] ), 'ETag' => '"x"' ) ) : new Response( 404 );
			case 'GET':
				return isset( $this->bucket[ $key ] ) ? new Response( 200, $this->bucket[ $key ]['body'] ) : new Response( 404, '<Error><Code>NoSuchKey</Code></Error>' );
			case 'DELETE':
				unset( $this->bucket[ $key ] );
				return new Response( 204 );
		}

		return new Response( 400 );
	}

	/**
	 * Create an image attachment with two sizes on disk.
	 */
	protected function attachment( int $id = 10, string $name = 'photo' ): void {
		file_put_contents( $this->uploads . "/2026/11/{$name}.jpg", str_repeat( 'F', 300 ) );
		file_put_contents( $this->uploads . "/2026/11/{$name}-300x200.jpg", str_repeat( 'M', 120 ) );
		file_put_contents( $this->uploads . "/2026/11/{$name}-150x150.jpg", str_repeat( 'T', 50 ) );
		WpStubs::$meta[ $id ] = array(
			'_wp_attached_file'       => "2026/11/{$name}.jpg",
			'_wp_attachment_metadata' => array(
				'file'  => "2026/11/{$name}.jpg",
				'sizes' => array(
					'medium'    => array( 'file' => "{$name}-300x200.jpg", 'width' => 300, 'height' => 200 ),
					'thumbnail' => array( 'file' => "{$name}-150x150.jpg", 'width' => 150, 'height' => 150 ),
				),
			),
		);
	}
}
