<?php
/**
 * Plugin Name:       R2 Offload
 * Plugin URI:        https://github.com/saizul/wp-r2-offload
 * Description:       Offload the media library to Cloudflare R2: uploads every image size, serves media from your custom domain, handles srcset, edits and deletes, and bulk-offloads existing files with WP-CLI.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Saizul Amin
 * Author URI:        https://saizul.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-r2-offload
 * Domain Path:       /languages
 *
 * @package Saizul\R2Offload
 */

defined( 'ABSPATH' ) || exit;

if ( PHP_VERSION_ID < 70400 ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'R2 Offload requires PHP 7.4 or newer.', 'wp-r2-offload' ) . '</p></div>';
			}
		}
	);
	return;
}

define( 'WP_R2_OFFLOAD_FILE', __FILE__ );
define( 'WP_R2_OFFLOAD_VERSION', '0.1.0' );

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	// PSR-4 autoloader for installs without Composer (the release zip).
	spl_autoload_register(
		static function ( $class_name ) {
			$prefixes = array(
				'Saizul\\R2Offload\\WP\\' => __DIR__ . '/includes/',
				'Saizul\\R2Offload\\'     => __DIR__ . '/src/',
			);
			foreach ( $prefixes as $prefix => $dir ) {
				if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
					continue;
				}
				$file = $dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
				return;
			}
		}
	);
}

\Saizul\R2Offload\WP\Plugin::boot( __FILE__ );
