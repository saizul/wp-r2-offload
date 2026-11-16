<?php
/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Variadic forwarding uses positional arrays only (string keys break
 * argument unpacking on PHP 7.4).
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

// phpcs:ignoreFile

final class WpStubs {
	/** @var array<string,array<int,array<int,callable>>> */
	public static $filters = array();
	/** @var array<string,mixed> */
	public static $options = array();
	/** @var array<string,mixed> */
	public static $transients = array();
	/** @var array<int,array<string,mixed>> */
	public static $meta = array();
	/** @var array<string,string> */
	public static $uploads = array();
	/** @var array<int,array{0:string,1:array<string,mixed>}> */
	public static $http_requests = array();
	/** @var array<int,mixed> */
	public static $http_responses = array();
	/** @var array<int,array{0:string,1:array<int,mixed>}> */
	public static $actions = array();
	/** @var bool */
	public static $multisite = false;

	public static function reset(): void {
		self::$multisite      = false;
		self::$filters        = array();
		self::$options        = array();
		self::$transients     = array();
		self::$meta           = array();
		self::$uploads        = array();
		self::$http_requests  = array();
		self::$http_responses = array();
		self::$actions        = array();
	}
}

class WP_Error {
	/** @var string */
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->message = (string) $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

class WP_Post {
	/** @var int */
	public $ID;

	public function __construct( int $id ) {
		$this->ID = $id;
	}
}

/**
 * Fake $wpdb answering the queries of MediaHooks::attached_file_exists().
 */
class WpdbStub {
	/** @var string */
	public $postmeta = 'wp_postmeta';
	/** @var string */
	public $posts = 'wp_posts';
	/** @var string */
	public $last = '';

	public function prepare( $query, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}

	public function get_var( $query ) {
		$this->last = $query;
		if ( preg_match( "/meta_key = '_wp_attached_file' AND meta_value = '([^']*)'/", $query, $m ) ) {
			foreach ( WpStubs::$meta as $id => $meta ) {
				if ( ( $meta['_wp_attached_file'] ?? null ) === stripslashes( $m[1] ) ) {
					return (string) $id;
				}
			}
		}
		return null;
	}
}

$GLOBALS['wpdb'] = new WpdbStub();

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	WpStubs::$filters[ $name ][ $priority ][] = array( $callback, $accepted_args );
	return true;
}

function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $name, $callback, $priority, $accepted_args );
}

function apply_filters( $name, $value, ...$args ) {
	if ( empty( WpStubs::$filters[ $name ] ) ) {
		return $value;
	}
	ksort( WpStubs::$filters[ $name ] );
	foreach ( WpStubs::$filters[ $name ] as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$all   = array_merge( array( $value ), array_values( $args ) );
			$value = call_user_func_array( $entry[0], array_slice( $all, 0, max( 1, (int) $entry[1] ) ) );
		}
	}
	return $value;
}

function do_action( $name, ...$args ) {
	WpStubs::$actions[] = array( $name, array_values( $args ) );
	if ( empty( WpStubs::$filters[ $name ] ) ) {
		return;
	}
	foreach ( WpStubs::$filters[ $name ] as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			call_user_func_array( $entry[0], array_slice( array_values( $args ), 0, max( 1, (int) $entry[1] ) ) );
		}
	}
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html__( $text, $domain = 'default' ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, WpStubs::$options ) ? WpStubs::$options[ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	WpStubs::$options[ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( WpStubs::$options[ $name ] );
	return true;
}

function get_transient( $name ) {
	return WpStubs::$transients[ $name ] ?? false;
}

function set_transient( $name, $value, $ttl = 0 ) {
	WpStubs::$transients[ $name ] = $value;
	return true;
}

function delete_transient( $name ) {
	unset( WpStubs::$transients[ $name ] );
	return true;
}

function get_post_meta( $id, $key = '', $single = false ) {
	return WpStubs::$meta[ (int) $id ][ $key ] ?? '';
}

function update_post_meta( $id, $key, $value ) {
	WpStubs::$meta[ (int) $id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $id, $key ) {
	unset( WpStubs::$meta[ (int) $id ][ $key ] );
	return true;
}

function wp_get_attachment_metadata( $id ) {
	$meta = WpStubs::$meta[ (int) $id ]['_wp_attachment_metadata'] ?? false;
	return is_array( $meta ) ? $meta : false;
}

function wp_get_upload_dir() {
	return WpStubs::$uploads;
}

function get_attached_file( $id ) {
	$file = WpStubs::$meta[ (int) $id ]['_wp_attached_file'] ?? '';
	return '' === $file ? false : WpStubs::$uploads['basedir'] . '/' . $file;
}

function wp_check_filetype( $filename ) {
	$ext   = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
	$types = array(
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);
	return array(
		'ext'  => isset( $types[ $ext ] ) ? $ext : false,
		'type' => $types[ $ext ] ?? false,
	);
}

function wp_delete_file( $file ) {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}

function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}

function is_multisite() {
	return WpStubs::$multisite;
}

function get_current_blog_id() {
	return 1;
}

function wp_salt( $scheme = 'auth' ) {
	return 'unit-test-salt-' . $scheme;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_request( $url, $args = array() ) {
	WpStubs::$http_requests[] = array( $url, $args );
	$response = array_shift( WpStubs::$http_responses );
	if ( null === $response ) {
		$response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => '',
		);
	}
	if ( is_array( $response ) && ! empty( $args['stream'] ) ) {
		file_put_contents( $args['filename'], $response['body'] );
		$response['body'] = '';
	}
	return $response;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

function wp_remote_retrieve_headers( $response ) {
	return $response['headers'] ?? array();
}

function current_user_can( $capability ) {
	return true;
}

function get_current_user_id() {
	return 1;
}

function is_admin() {
	return false;
}
