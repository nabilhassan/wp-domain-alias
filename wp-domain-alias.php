<?php
/**
 * Plugin Name:       WP Domain Alias
 * Plugin URI:        https://github.com/nabilhassan/wp-domain-alias
 * Description:       Show one WordPress site on additional approved domains while keeping visitors on the domain they entered. Canonical URLs stay on the primary domain.
 * Version:           0.1.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Md Sazzadul Hossen Nabil
 * Author URI:        https://github.com/nabilhassan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-domain-alias
 * Domain Path:       /languages
 *
 * @package WP_Domain_Alias
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_DOMAIN_ALIAS_VERSION' ) ) {
	// Another copy (usually the Must-Use install) is already running.
	if ( ! defined( 'WP_DOMAIN_ALIAS_DUPLICATE' ) ) {
		define( 'WP_DOMAIN_ALIAS_DUPLICATE', true );
	}
	return;
}

define( 'WP_DOMAIN_ALIAS_VERSION', '0.1.1' );
define( 'WP_DOMAIN_ALIAS_FILE', __FILE__ );
define( 'WP_DOMAIN_ALIAS_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-host-validator.php';
require_once __DIR__ . '/includes/class-domain-manager.php';
require_once __DIR__ . '/includes/class-url-handler.php';
require_once __DIR__ . '/includes/class-seo-handler.php';
require_once __DIR__ . '/includes/class-link-handler.php';
require_once __DIR__ . '/includes/class-redirect-handler.php';
require_once __DIR__ . '/includes/class-health-check.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/integrations/class-weglot.php';
require_once __DIR__ . '/includes/integrations/class-cache-enabler.php';
require_once __DIR__ . '/includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'WP_Domain_Alias\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_Domain_Alias\Plugin', 'deactivate' ) );

WP_Domain_Alias\Plugin::instance()->boot();
