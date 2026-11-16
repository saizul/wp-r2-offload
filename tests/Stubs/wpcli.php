<?php
/**
 * WP-CLI stubs for unit tests.
 *
 * @package Saizul\R2Offload
 */

// phpcs:ignoreFile

namespace {
	final class WpCliExit extends RuntimeException {
	}

	class WP_CLI {
		/** @var array<int,array{0:string,1:string}> */
		public static $log = array();
		/** @var array<int,mixed> */
		public static $items = array();
		/** @var bool Answer to confirm(). */
		public static $confirm = true;

		public static function reset(): void {
			self::$log     = array();
			self::$items   = array();
			self::$confirm = true;
		}

		public static function confirm( $question, $assoc_args = array() ) {
			self::$log[] = array( 'confirm', $question );
			if ( empty( $assoc_args['yes'] ) && ! self::$confirm ) {
				throw new WpCliExit( 'Not confirmed.' );
			}
		}

		public static function error( $message ) {
			self::$log[] = array( 'error', $message );
			throw new WpCliExit( $message );
		}

		public static function warning( $message ) {
			self::$log[] = array( 'warning', $message );
		}

		public static function success( $message ) {
			self::$log[] = array( 'success', $message );
		}

		public static function log( $message ) {
			self::$log[] = array( 'log', $message );
		}

		public static function line( $message = '' ) {
			self::$log[] = array( 'line', $message );
		}

		public static function add_command( $name, $callable ) {
			self::$log[] = array( 'command', $name );
		}
	}
}

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {
		\WP_CLI::$items[] = array( $format, $items, $fields );
	}

	function get_flag_value( $assoc_args, $flag, $default = null ) {
		return $assoc_args[ $flag ] ?? $default;
	}
}
