<?php
/**
 * Media > R2 Offload screen.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\WP\Admin;

use Saizul\R2Offload\Config\Config;
use Saizul\R2Offload\WP\Bulk\WpAttachmentSource;
use Saizul\R2Offload\WP\Media\AttachmentOffloader;
use Saizul\R2Offload\WP\Plugin;
use Saizul\R2Offload\WP\Services;
use Throwable;

/**
 * Settings form, connection test and status.
 */
final class SettingsPage {

	public const SLUG = 'wp-r2-offload';

	/**
	 * Services.
	 *
	 * @var Services
	 */
	private $services;

	/**
	 * Constructor.
	 *
	 * @param Services $services Services.
	 */
	public function __construct( Services $services ) {
		$this->services = $services;
	}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_wp_r2_offload_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_wp_r2_offload_test', array( $this, 'handle_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WP_R2_OFFLOAD_FILE ), array( $this, 'action_links' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_field' ), 10, 2 );
	}

	/**
	 * Menu entry.
	 */
	public function menu(): void {
		add_media_page( __( 'R2 Offload', 'wp-r2-offload' ), __( 'R2 Offload', 'wp-r2-offload' ), Plugin::capability(), self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Settings link.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ): array {
		$links   = (array) $links;
		$links[] = '<a href="' . esc_url( admin_url( 'upload.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'wp-r2-offload' ) . '</a>';

		return $links;
	}

	/**
	 * Read-only R2 status in the attachment details.
	 *
	 * @param array<string,mixed> $fields Fields.
	 * @param \WP_Post            $post Attachment.
	 * @return array<string,mixed>
	 */
	public function attachment_field( $fields, $post ): array {
		$fields = (array) $fields;
		$record = AttachmentOffloader::record( (int) $post->ID );
		$error  = (string) get_post_meta( (int) $post->ID, AttachmentOffloader::META_ERROR, true );
		if ( null === $record && '' === $error ) {
			return $fields;
		}
		$text = null === $record
			? __( 'Not offloaded.', 'wp-r2-offload' )
			/* translators: 1: number of files, 2: bucket */
			: sprintf( _n( '%1$d file in bucket %2$s.', '%1$d files in bucket %2$s.', count( $record->files ), 'wp-r2-offload' ), count( $record->files ), $record->bucket );
		if ( null !== $record && $record->local_removed ) {
			$text .= ' ' . __( 'Local copies removed.', 'wp-r2-offload' );
		}
		if ( '' !== $error ) {
			/* translators: %s: error message */
			$text .= ' ' . sprintf( __( 'Last error: %s', 'wp-r2-offload' ), $error );
		}
		$fields['wp_r2_offload'] = array(
			'label' => __( 'Cloudflare R2', 'wp-r2-offload' ),
			'input' => 'html',
			'html'  => '<p>' . esc_html( $text ) . '</p>',
		);

		return $fields;
	}

	/**
	 * Labels and help of the fields.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private function labels(): array {
		return array(
			'account_id'          => array( __( 'Account ID', 'wp-r2-offload' ), __( '32 characters, shown in the Cloudflare dashboard under R2.', 'wp-r2-offload' ) ),
			'access_key_id'       => array( __( 'Access key ID', 'wp-r2-offload' ), __( 'From an R2 API token with Object Read & Write permission for the bucket.', 'wp-r2-offload' ) ),
			'secret_access_key'   => array( __( 'Secret access key', 'wp-r2-offload' ), __( 'Stored encrypted. Prefer the wp-config.php constant.', 'wp-r2-offload' ) ),
			'bucket'              => array( __( 'Bucket', 'wp-r2-offload' ), '' ),
			'jurisdiction'        => array( __( 'Jurisdiction', 'wp-r2-offload' ), __( 'Only for buckets created in the EU or FedRAMP jurisdiction.', 'wp-r2-offload' ) ),
			'endpoint'            => array( __( 'Custom S3 endpoint', 'wp-r2-offload' ), __( 'Leave empty for R2. Overrides the account endpoint, for example for a local S3-compatible test server.', 'wp-r2-offload' ) ),
			'public_url'          => array( __( 'Public URL', 'wp-r2-offload' ), __( 'Custom domain connected to the bucket (recommended) or its r2.dev URL, for example https://media.example.com', 'wp-r2-offload' ) ),
			'key_prefix'          => array( __( 'Object key prefix', 'wp-r2-offload' ), __( 'Keys mirror the uploads folder below this prefix. {site_id} is replaced on multisite.', 'wp-r2-offload' ) ),
			'enabled'             => array( __( 'Offload new uploads', 'wp-r2-offload' ), '' ),
			'rewrite_urls'        => array( __( 'Serve media from the public URL', 'wp-r2-offload' ), '' ),
			'remove_local'        => array( __( 'Remove local files after upload', 'wp-r2-offload' ), __( 'Saves disk space. Run "wp r2 restore --all" before deactivating the plugin.', 'wp-r2-offload' ) ),
			'cache_control'       => array( __( 'Cache-Control', 'wp-r2-offload' ), '' ),
			'multipart_threshold' => array( __( 'Multipart from (MiB)', 'wp-r2-offload' ), __( 'Files at least this large are uploaded in parts.', 'wp-r2-offload' ) ),
			'part_size'           => array( __( 'Part size (MiB)', 'wp-r2-offload' ), __( 'At least 5 MiB.', 'wp-r2-offload' ) ),
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage R2 Offload.', 'wp-r2-offload' ), '', array( 'response' => 403 ) );
		}
		$config = $this->services->settings->config();
		$stored = $this->services->settings->stored();

		echo '<div class="wrap"><h1>' . esc_html__( 'R2 Offload', 'wp-r2-offload' ) . '</h1>';
		$this->render_notice();

		$source = new WpAttachmentSource();
		$total  = $source->total();
		$done   = $source->offloaded();
		/* translators: 1: offloaded attachments, 2: all attachments */
		echo '<p>' . esc_html( sprintf( __( '%1$d of %2$d attachments are offloaded. Offload the rest with "wp r2 offload".', 'wp-r2-offload' ), $done, $total ) ) . '</p>';
		foreach ( $config->errors() as $error ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $error ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wp_r2_offload_save">';
		wp_nonce_field( 'wp_r2_offload_save' );
		echo '<table class="form-table" role="presentation">';
		foreach ( $this->labels() as $key => $label ) {
			$this->row( $key, $label[0], $label[1], $config, $stored );
		}
		echo '</table>';
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Connection test', 'wp-r2-offload' ) . '</h2>';
		echo '<p>' . esc_html__( 'Uploads, checks and deletes a small test object below the key prefix.', 'wp-r2-offload' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wp_r2_offload_test">';
		wp_nonce_field( 'wp_r2_offload_test' );
		submit_button( __( 'Test connection', 'wp-r2-offload' ), 'secondary' );
		echo '</form></div>';
	}

	/**
	 * One settings row.
	 *
	 * @param string              $key Field.
	 * @param string              $label Label.
	 * @param string              $help Help.
	 * @param Config              $config Configuration.
	 * @param array<string,mixed> $stored Stored option.
	 */
	private function row( string $key, string $label, string $help, Config $config, array $stored ): void {
		list( $type, , $secret ) = Config::FIELDS[ $key ];
		$id                      = 'wp-r2-offload-' . $key;
		$name                    = 'settings[' . $key . ']';
		$locked                  = 'constant' === $config->source( $key );

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( $locked ) {
			$shown = $config->describe()[ $key ];
			printf( '<input type="text" id="%1$s" class="regular-text" value="%2$s" disabled>', esc_attr( $id ), esc_attr( $shown ) );
			/* translators: %s: constant name */
			echo '<p class="description">' . esc_html( sprintf( __( 'Defined by %s in wp-config.php.', 'wp-r2-offload' ), Config::constant_name( $key ) ) ) . '</p></td></tr>';
			return;
		}

		if ( $secret ) {
			$has = isset( $stored[ $key ] ) && '' !== (string) $stored[ $key ];
			printf( '<input type="password" id="%1$s" name="%2$s" class="regular-text" value="" autocomplete="new-password" placeholder="%3$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $has ? __( 'Saved (encrypted)', 'wp-r2-offload' ) : '' ) );
			if ( $has ) {
				printf( ' <label><input type="checkbox" name="settings[%1$s__clear]" value="1"> %2$s</label>', esc_attr( $key ), esc_html__( 'Remove', 'wp-r2-offload' ) );
			}
			/* translators: %s: constant name */
			$help = trim( $help . ' ' . sprintf( __( 'Leave empty to keep the saved value. Constant: %s', 'wp-r2-offload' ), Config::constant_name( $key ) ) );
		} elseif ( 'bool' === $type ) {
			printf( '<label><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s</label>', esc_attr( $id ), esc_attr( $name ), checked( $config->flag( $key ), true, false ), esc_html( $label ) );
		} elseif ( 'select' === $type ) {
			printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
			foreach ( array( '' => __( 'Default', 'wp-r2-offload' ), 'eu' => 'EU', 'fedramp' => 'FedRAMP' ) as $value => $text ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short option list.
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $config->get( $key ), $value, false ), esc_html( $text ) );
			}
			echo '</select>';
		} else {
			printf(
				'<input type="%1$s" id="%2$s" name="%3$s" class="%4$s" value="%5$s">',
				'int' === $type ? 'number' : ( 'url' === $type ? 'url' : 'text' ),
				esc_attr( $id ),
				esc_attr( $name ),
				'int' === $type ? 'small-text' : 'regular-text',
				esc_attr( $config->get( $key ) )
			);
		}
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Save.
	 */
	public function handle_save(): void {
		check_admin_referer( 'wp_r2_offload_save' );
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage R2 Offload.', 'wp-r2-offload' ), '', array( 'response' => 403 ) );
		}
		$input  = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised field by field by ConfigResolver.
		$errors = $this->services->settings->save( (array) $input );
		$this->services->reset();

		$this->notice( array() === $errors ? 'success' : 'warning', array() === $errors ? __( 'Settings saved.', 'wp-r2-offload' ) : implode( ' ', $errors ) );
		wp_safe_redirect( admin_url( 'upload.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Connection test.
	 */
	public function handle_test(): void {
		check_admin_referer( 'wp_r2_offload_test' );
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage R2 Offload.', 'wp-r2-offload' ), '', array( 'response' => 403 ) );
		}
		list( $type, $message ) = self::test_connection( $this->services );
		$this->notice( $type, $message );
		wp_safe_redirect( admin_url( 'upload.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Put, head and delete a test object.
	 *
	 * @param Services $services Services.
	 * @return array{0:string,1:string} Notice type and message.
	 */
	public static function test_connection( Services $services ): array {
		try {
			$client = $services->client();
			$key    = $services->keys()->prefix() . 'wp-r2-offload-test-' . bin2hex( random_bytes( 6 ) ) . '.txt';
			$client->put_object( $key, 'R2 Offload connection test', 'text/plain' );
			$meta = $client->head_object( $key );
			$client->delete_object( $key );
			if ( null === $meta ) {
				return array( 'error', __( 'The test object was uploaded but could not be found.', 'wp-r2-offload' ) );
			}
			/* translators: %s: bucket */
			return array( 'success', sprintf( __( 'Connected: uploaded, read and deleted a test object in bucket %s.', 'wp-r2-offload' ), $client->endpoint()->bucket() ) );
		} catch ( Throwable $e ) {
			/* translators: %s: error message */
			return array( 'error', sprintf( __( 'Connection failed: %s', 'wp-r2-offload' ), $e->getMessage() ) );
		}
	}

	/**
	 * Store a notice for the next page load.
	 *
	 * @param string $type Type.
	 * @param string $message Message.
	 */
	private function notice( string $type, string $message ): void {
		set_transient(
			'wp_r2_offload_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Print the stored notice.
	 */
	private function render_notice(): void {
		$key    = 'wp_r2_offload_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice['type'] ?? '', array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'info';
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( (string) ( $notice['message'] ?? '' ) ) );
	}
}
