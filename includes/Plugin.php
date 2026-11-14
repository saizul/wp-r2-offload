<?php
/**
 * Plugin bootstrap.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\WP;

use Saizul\R2Offload\WP\Admin\SettingsPage;
use Saizul\R2Offload\WP\Cli\R2Command;
use Saizul\R2Offload\WP\Media\MediaHooks;

/**
 * Wires the offload services into WordPress.
 */
final class Plugin {

	public const VERSION = '0.1.0';

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Main plugin file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Services after init.
	 *
	 * @var Services|null
	 */
	private $services = null;

	/**
	 * Constructor.
	 *
	 * @param string $file Main plugin file.
	 */
	private function __construct( string $file ) {
		$this->file = $file;
	}

	/**
	 * Create the singleton.
	 *
	 * @param string $file Main plugin file.
	 */
	public static function boot( string $file ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $file );
			add_action( 'plugins_loaded', array( self::$instance, 'init' ) );
			add_action( 'init', array( self::$instance, 'load_textdomain' ) );
		}

		return self::$instance;
	}

	/**
	 * Capability for the settings screen.
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to manage R2 Offload.
		 *
		 * @param string $capability Default manage_options.
		 */
		return (string) apply_filters( 'wp_r2_offload_capability', 'manage_options' );
	}

	/**
	 * Initialise.
	 */
	public function init(): void {
		$this->services = new Services( new Settings() );
		( new MediaHooks( $this->services ) )->register();

		if ( is_admin() ) {
			( new SettingsPage( $this->services ) )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'r2', new R2Command( $this->services ) );
		}

		/**
		 * Fires when the plugin is ready.
		 *
		 * @param Services $services Services.
		 */
		do_action( 'wp_r2_offload_loaded', $this->services );
	}

	/**
	 * Services (null before init).
	 */
	public function services(): ?Services {
		return $this->services;
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wp-r2-offload', false, dirname( plugin_basename( $this->file ) ) . '/languages' );
	}
}
