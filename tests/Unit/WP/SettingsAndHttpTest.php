<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\WP;

use Saizul\R2Offload\Config\SecretBox;
use Saizul\R2Offload\Http\HttpException;
use Saizul\R2Offload\Http\Request;
use Saizul\R2Offload\Tests\WpTestCase;
use Saizul\R2Offload\WP\Admin\SettingsPage;
use Saizul\R2Offload\WP\Http\WpHttpClient;
use Saizul\R2Offload\WP\Settings;
use WP_Error;
use WpStubs;

final class SettingsAndHttpTest extends WpTestCase {

	public function test_settings_store_encrypted_secrets_only_without_constants(): void {
		$this->constants = array();
		$settings        = $this->settings();
		$errors          = $settings->save(
			array(
				'account_id'        => self::ACCOUNT,
				'access_key_id'     => 'form-key-id',
				'secret_access_key' => 'form-secret',
				'bucket'            => 'media',
				'public_url'        => 'https://media.example.com/',
				'rewrite_urls'      => '1',
				'enabled'           => '1',
			)
		);
		$stored          = WpStubs::$options[ Settings::OPTION ];

		self::assertSame( array(), $errors );
		self::assertTrue( SecretBox::is_encrypted( $stored['secret_access_key'] ) );
		self::assertStringNotContainsString( 'form-secret', serialize( $stored ) );
		self::assertSame( 'form-secret', $settings->config()->get( 'secret_access_key' ) );
		self::assertSame( 'https://media.example.com', $settings->config()->get( 'public_url' ) );

		// Once a constant is defined the value is dropped from the option on the next save.
		$this->constants['WP_R2_OFFLOAD_SECRET_ACCESS_KEY'] = 'from-config';
		$settings = $this->settings();
		$settings->save( array( 'secret_access_key' => 'ignored' ) );
		self::assertArrayNotHasKey( 'secret_access_key', WpStubs::$options[ Settings::OPTION ] );
		self::assertSame( 'from-config', $settings->config()->get( 'secret_access_key' ) );
	}

	public function test_save_reports_problems(): void {
		$this->constants = array();
		$errors          = $this->settings()->save( array( 'bucket' => 'Bad Bucket' ) );

		self::assertContains( 'The bucket name is missing or invalid.', $errors );
		self::assertContains( 'The R2 access key ID and secret access key are required.', $errors );
	}

	public function test_key_material_uses_encryption_constant(): void {
		$a = new Settings(
			static function ( string $name ) {
				return 'WP_R2_OFFLOAD_ENCRYPTION_KEY' === $name ? str_repeat( 'z', 32 ) : null;
			}
		);
		$a->save( array( 'secret_access_key' => 's3cret' ) );
		self::assertSame( 's3cret', $a->config()->get( 'secret_access_key' ) );

		$b = new Settings(
			static function () {
				return null;
			}
		);
		self::assertSame( '', $b->config()->get( 'secret_access_key' ), 'A different key cannot decrypt.' );
	}

	public function test_connection_test(): void {
		list( $type, $message ) = SettingsPage::test_connection( $this->services() );

		self::assertSame( 'success', $type );
		self::assertSame( 'Connected: uploaded, read and deleted a test object in bucket media.', $message );
		self::assertSame( array(), $this->bucket, 'The test object is deleted.' );
		self::assertCount( 3, $this->http->requests );

		unset( $this->constants['WP_R2_OFFLOAD_BUCKET'] );
		list( $type, $message ) = SettingsPage::test_connection( $this->services() );
		self::assertSame( 'error', $type );
		self::assertStringContainsString( 'bucket name', $message );
	}

	public function test_wp_http_client(): void {
		WpStubs::$http_responses[] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'ETag' => '"x"' ),
			'body'     => '',
		);
		$response = ( new WpHttpClient() )->send( new Request( 'PUT', 'https://h.example/b/k', array( 'Authorization' => 'sig' ), 'body' ) );

		list( $url, $args ) = WpStubs::$http_requests[0];
		self::assertSame( 'https://h.example/b/k', $url );
		self::assertSame( 'PUT', $args['method'] );
		self::assertSame( 'body', $args['body'] );
		self::assertSame( 'sig', $args['headers']['Authorization'] );
		self::assertSame( 0, $args['redirection'] );
		self::assertSame( '"x"', $response->header( 'etag' ) );
	}

	public function test_wp_http_client_streams_and_cleans_up_errors(): void {
		$file                      = $this->uploads . '/download.bin';
		WpStubs::$http_responses[] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => 'data',
		);
		( new WpHttpClient() )->send( new Request( 'GET', 'https://h.example/b/k' ), $file );
		self::assertTrue( WpStubs::$http_requests[0][1]['stream'] );
		self::assertSame( 'data', file_get_contents( $file ) );

		WpStubs::$http_responses[] = array(
			'response' => array( 'code' => 404 ),
			'headers'  => array(),
			'body'     => '<Error><Code>NoSuchKey</Code></Error>',
		);
		$response = ( new WpHttpClient() )->send( new Request( 'GET', 'https://h.example/b/missing' ), $file );
		self::assertSame( '<Error><Code>NoSuchKey</Code></Error>', $response->body() );
		self::assertFileDoesNotExist( $file );
	}

	public function test_wp_http_client_errors(): void {
		WpStubs::$http_responses[] = new WP_Error( 'http_request_failed', 'cURL error 7' );

		$this->expectException( HttpException::class );
		( new WpHttpClient() )->send( new Request( 'HEAD', 'https://h.example/b/k' ) );
	}
}
