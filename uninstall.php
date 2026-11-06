<?php
/**
 * Removes the plugin's data when it is deleted from the Plugins screen.
 *
 * @package WP_Domain_Alias
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_domain_alias_settings' );
delete_option( 'wp_domain_alias_canonical_notice' );

// Short-lived per-user notices, health results and rate-limit flags.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup of this plugin's own transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wpda_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wpda_' ) . '%'
	)
);
