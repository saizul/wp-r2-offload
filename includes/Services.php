<?php
/**
 * Service wiring.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\WP;

use RuntimeException;
use Saizul\R2Offload\Http\HttpClient;
use Saizul\R2Offload\Media\KeyBuilder;
use Saizul\R2Offload\Media\UrlRewriter;
use Saizul\R2Offload\S3\MultipartUploader;
use Saizul\R2Offload\S3\R2Endpoint;
use Saizul\R2Offload\S3\S3Client;
use Saizul\R2Offload\Signer\Credentials;
use Saizul\R2Offload\Signer\SignatureV4;
use Saizul\R2Offload\WP\Http\WpHttpClient;
use Saizul\R2Offload\WP\Media\AttachmentOffloader;

/**
 * Builds the client, uploader, rewriter and offloader from the configuration.
 */
final class Services {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	public $settings;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	public $http;

	/**
	 * Cached client.
	 *
	 * @var S3Client|null
	 */
	private $client = null;

	/**
	 * Cached offloader.
	 *
	 * @var AttachmentOffloader|null
	 */
	private $offloader = null;

	/**
	 * Back-off sleeper for the client (tests pass a no-op).
	 *
	 * @var callable(int):void|null
	 */
	private $sleeper;

	/**
	 * Constructor.
	 *
	 * @param Settings                $settings Settings.
	 * @param HttpClient|null         $http HTTP client.
	 * @param callable(int):void|null $sleeper Back-off sleeper.
	 */
	public function __construct( Settings $settings, ?HttpClient $http = null, ?callable $sleeper = null ) {
		$this->settings = $settings;
		$this->http     = $http ?? new WpHttpClient();
		$this->sleeper  = $sleeper;
	}

	/**
	 * S3 client for the configured bucket.
	 *
	 * @throws RuntimeException When the configuration is incomplete.
	 */
	public function client(): S3Client {
		if ( null === $this->client ) {
			$config = $this->settings->config();
			$errors = array_filter(
				$config->errors(),
				static function ( string $error ): bool {
					return false === strpos( $error, 'public' ) && false === strpos( $error, 'Local files' );
				}
			);
			if ( array() !== $errors ) {
				throw new RuntimeException( implode( ' ', $errors ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped where it is displayed.
			}
			$this->client = new S3Client(
				$this->http,
				$config->endpoint(),
				new SignatureV4( new Credentials( $config->get( 'access_key_id' ), $config->get( 'secret_access_key' ) ), R2Endpoint::REGION, R2Endpoint::SERVICE ),
				null,
				3,
				$this->sleeper
			);
		}

		return $this->client;
	}

	/**
	 * Multipart uploader.
	 */
	public function uploader(): MultipartUploader {
		return new MultipartUploader( $this->client(), $this->settings->config()->part_size() );
	}

	/**
	 * Key builder.
	 */
	public function keys(): KeyBuilder {
		return new KeyBuilder( $this->settings->config()->get( 'key_prefix' ), function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 );
	}

	/**
	 * URL rewriter.
	 */
	public function rewriter(): UrlRewriter {
		$uploads = wp_get_upload_dir();

		return new UrlRewriter( (string) $uploads['baseurl'], $this->settings->config()->get( 'public_url' ) );
	}

	/**
	 * Attachment offloader.
	 */
	public function offloader(): AttachmentOffloader {
		if ( null === $this->offloader ) {
			$this->offloader = new AttachmentOffloader( $this );
		}

		return $this->offloader;
	}

	/**
	 * Forget cached objects after the settings changed.
	 */
	public function reset(): void {
		$this->client    = null;
		$this->offloader = null;
		$this->settings->flush();
	}
}
