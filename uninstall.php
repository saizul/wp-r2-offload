<?php
/**
 * Uninstall routine.
 *
 * Removes the settings and the bulk progress. Objects in the bucket and the
 * `_wp_r2_offload` attachment meta are kept on purpose: deleting them could
 * break media whose local copies were removed. Run `wp r2 restore --all`
 * before uninstalling if local copies were removed.
 *
 * @package Saizul\R2Offload
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_r2_offload_settings' );
delete_option( 'wp_r2_offload_bulk_state' );
