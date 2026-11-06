<?php
/**
 * Plugin Name:       WP Domain Alias (Must-Use loader)
 * Plugin URI:        https://github.com/nabilhassan/wp-domain-alias
 * Description:       Loads WP Domain Alias from wp-content/mu-plugins/wp-domain-alias/. Must-Use plugins cannot be deactivated from the Plugins screen; see docs/must-use.md to remove it.
 * Version:           0.1.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Md Sazzadul Hossen Nabil
 * License:           GPL-2.0-or-later
 *
 * Copy this file to wp-content/mu-plugins/ and the plugin folder to
 * wp-content/mu-plugins/wp-domain-alias/.
 *
 * @package WP_Domain_Alias
 */

defined( 'ABSPATH' ) || exit;

$wp_domain_alias_main = __DIR__ . '/wp-domain-alias/wp-domain-alias.php';

if ( is_readable( $wp_domain_alias_main ) ) {
	if ( ! defined( 'WP_DOMAIN_ALIAS_MU' ) ) {
		define( 'WP_DOMAIN_ALIAS_MU', true );
	}
	require_once $wp_domain_alias_main;
} else {
	// Keep the site running if the plugin folder is missing.
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Domain Alias (Must-Use loader) could not find wp-content/mu-plugins/wp-domain-alias/. Restore the folder or delete the loader file.', 'wp-domain-alias' ) . '</p></div>';
			}
		}
	);
}
unset( $wp_domain_alias_main );
