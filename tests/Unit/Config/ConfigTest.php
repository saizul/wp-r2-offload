<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\Config\Config;
use Saizul\R2Offload\Config\ConfigResolver;
use Saizul\R2Offload\Config\SecretBox;

final class ConfigTest extends TestCase {

	private const ACCOUNT = '0123456789abcdef0123456789abcdef';

	/** @var array<string,mixed> */
	private $constants = array();

	private function resolver(): ConfigResolver {
		$constants = &$this->constants;

		return new ConfigResolver(
			static function ( string $name ) use ( &$constants ) {
				return $constants[ $name ] ?? null;
			},
			new SecretBox( str_repeat( 'x', 32 ) )
		);
	}

	public function test_secret_box_round_trip(): void {
		$box    = new SecretBox( str_repeat( 'x', 32 ) );
		$cipher = $box->encrypt( 'my-secret' );

		self::assertTrue( SecretBox::is_encrypted( $cipher ) );
		self::assertStringNotContainsString( 'my-secret', $cipher );
		self::assertNotSame( $cipher, $box->encrypt( 'my-secret' ), 'Random nonce.' );
		self::assertSame( 'my-secret', $box->decrypt( $cipher ) );
		self::assertSame( '', $box->encrypt( '' ) );
		self::assertSame( '', ( new SecretBox( str_repeat( 'y', 32 ) ) )->decrypt( $cipher ), 'Another key cannot decrypt.' );
		self::assertSame( '', $box->decrypt( 'plain-text' ) );
		self::assertSame( '', $box->decrypt( substr( $cipher, 0, -4 ) . 'AAAA' ), 'Tampering is detected.' );
	}

	public function test_secret_box_openssl(): void {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			self::markTestSkipped( 'OpenSSL is not available.' );
		}
		$box    = new SecretBox( str_repeat( 'x', 32 ), true );
		$cipher = $box->encrypt( 'my-secret' );

		self::assertStringStartsWith( 'r2o1:', $cipher );
		self::assertSame( 'my-secret', $box->decrypt( $cipher ) );
		self::assertSame( 'my-secret', ( new SecretBox( str_repeat( 'x', 32 ) ) )->decrypt( $cipher ), 'Readable with sodium installed too.' );
		self::assertSame( '', $box->decrypt( 'r2o1:' . base64_encode( 'short' ) ) );
	}

	public function test_secret_box_needs_material(): void {
		$this->expectException( InvalidArgumentException::class );
		new SecretBox( 'short' );
	}

	public function test_defaults(): void {
		$config = new Config( array() );

		self::assertSame( 'wp-content/uploads/', $config->get( 'key_prefix' ) );
		self::assertTrue( $config->flag( 'enabled' ) );
		self::assertFalse( $config->flag( 'remove_local' ) );
		self::assertSame( 64 * 1048576, $config->multipart_threshold() );
		self::assertSame( 16 * 1048576, $config->part_size() );
		self::assertSame( 'default', $config->source( 'bucket' ) );
		self::assertFalse( $config->is_ready() );
		self::assertSame( 'WP_R2_OFFLOAD_SECRET_ACCESS_KEY', Config::constant_name( 'secret_access_key' ) );
	}

	public function test_limits_and_casts(): void {
		$config = new Config(
			array(
				'multipart_threshold' => 1,
				'part_size'           => 9999,
				'remove_local'        => 'yes',
				'rewrite_urls'        => 'off',
			)
		);

		self::assertSame( 8 * 1048576, $config->multipart_threshold() );
		self::assertSame( 512 * 1048576, $config->part_size() );
		self::assertTrue( $config->flag( 'remove_local' ) );
		self::assertFalse( $config->flag( 'rewrite_urls' ) );
		self::assertContains( 'Local files can only be removed when media URLs are rewritten to the bucket.', $config->errors() );
	}

	public function test_complete_config(): void {
		$config = new Config(
			array(
				'account_id'        => self::ACCOUNT,
				'access_key_id'     => 'AKIDEXAMPLE1234',
				'secret_access_key' => 'secret',
				'bucket'            => 'media',
				'public_url'        => 'https://media.example.com',
			)
		);

		self::assertSame( array(), $config->errors() );
		self::assertSame( 'https://' . self::ACCOUNT . '.r2.cloudflarestorage.com', $config->endpoint()->endpoint() );
		$shown = $config->describe();
		self::assertSame( '********', $shown['secret_access_key'] );
		self::assertSame( 'AKID***********', $shown['access_key_id'] );
		self::assertSame( 'yes', $shown['rewrite_urls'] );
	}

	public function test_public_url_must_be_https_when_rewriting(): void {
		$config = new Config( array( 'public_url' => 'http://media.example.com' ) );

		self::assertContains( 'A public https:// URL (custom domain or r2.dev URL) is required to rewrite media URLs.', $config->errors() );
		self::assertNotContains( 'A public https:// URL (custom domain or r2.dev URL) is required to rewrite media URLs.', ( new Config( array( 'rewrite_urls' => false ) ) )->errors() );
	}

	public function test_constants_win_and_are_never_stored(): void {
		$this->constants = array(
			'WP_R2_OFFLOAD_SECRET_ACCESS_KEY' => 'from-config',
			'WP_R2_OFFLOAD_REMOVE_LOCAL'      => true,
		);
		$resolver        = $this->resolver();
		$result          = $resolver->sanitize(
			array(
				'secret_access_key' => 'typed-in-form',
				'access_key_id'     => ' AKID 123 ',
				'account_id'        => strtoupper( self::ACCOUNT ),
				'bucket'            => 'Media',
				'remove_local'      => '',
			),
			array()
		);
		$stored          = $result['settings'];

		self::assertArrayNotHasKey( 'secret_access_key', $stored );
		self::assertArrayNotHasKey( 'remove_local', $stored );
		self::assertTrue( SecretBox::is_encrypted( $stored['access_key_id'] ) );
		self::assertSame( self::ACCOUNT, $stored['account_id'] );
		self::assertSame( 'media', $stored['bucket'] );

		$config = $resolver->resolve( $stored );
		self::assertSame( 'from-config', $config->get( 'secret_access_key' ) );
		self::assertSame( 'constant', $config->source( 'secret_access_key' ) );
		self::assertSame( 'AKID123', $config->get( 'access_key_id' ) );
		self::assertSame( 'option', $config->source( 'access_key_id' ) );
		self::assertTrue( $config->flag( 'remove_local' ) );
	}

	public function test_secrets_are_kept_or_cleared(): void {
		$resolver = $this->resolver();
		$first    = $resolver->sanitize( array( 'secret_access_key' => 'one' ), array() )['settings'];
		$kept     = $resolver->sanitize( array( 'secret_access_key' => '' ), $first )['settings'];
		$cleared  = $resolver->sanitize( array( 'secret_access_key__clear' => '1' ), $first )['settings'];

		self::assertSame( $first['secret_access_key'], $kept['secret_access_key'] );
		self::assertArrayNotHasKey( 'secret_access_key', $cleared );
		self::assertSame( 'one', $resolver->resolve( $kept )->get( 'secret_access_key' ) );

		// A plain (unencrypted) value in the database is ignored.
		self::assertSame( '', $resolver->resolve( array( 'secret_access_key' => 'plain' ) )->get( 'secret_access_key' ) );
		self::assertArrayNotHasKey( 'secret_access_key', $resolver->sanitize( array(), array( 'secret_access_key' => 'plain' ) )['settings'] );
	}

	public function test_sanitize_values(): void {
		$result = $this->resolver()->sanitize(
			array(
				'public_url'          => 'javascript:alert(1)',
				'endpoint'            => 'https://s3.example.com/',
				'jurisdiction'        => 'EU',
				'key_prefix'          => '/../media//',
				'multipart_threshold' => '-5',
				'cache_control'       => '<b>public</b>',
				'enabled'             => '1',
			),
			array()
		);
		$s      = $result['settings'];

		self::assertSame( '', $s['public_url'] );
		self::assertContains( 'The public url is not a valid URL.', $result['errors'] );
		self::assertSame( 'https://s3.example.com', $s['endpoint'] );
		self::assertSame( 'eu', $s['jurisdiction'] );
		self::assertSame( 'media/', $s['key_prefix'] );
		self::assertSame( 0, $s['multipart_threshold'] );
		self::assertSame( 'public', $s['cache_control'] );
		self::assertTrue( $s['enabled'] );
		self::assertFalse( $s['rewrite_urls'], 'Unchecked boxes are off.' );
	}
}
