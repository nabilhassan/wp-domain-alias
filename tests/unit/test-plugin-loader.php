<?php
group( 'Plugin bootstrap' );
wpda_test_reset();
require dirname( __DIR__, 2 ) . '/wp-domain-alias.php';
ok( defined( 'WP_DOMAIN_ALIAS_DUPLICATE' ), 'second copy is detected and does not boot' );
same( WP_DOMAIN_ALIAS_VERSION, '0.1.1-beta', 'version constant' );

wpda_test_reset();
WP_Domain_Alias\Plugin::activate();
same( get_option( 'wp_domain_alias_settings' ), WP_Domain_Alias\Domain_Manager::defaults(), 'activation stores safe defaults' );
$GLOBALS['wp_options']['wp_domain_alias_settings'] = array( 'aliases' => array( 'kept.example' ) );
WP_Domain_Alias\Plugin::activate();
same( get_option( 'wp_domain_alias_settings' ), array( 'aliases' => array( 'kept.example' ) ), 'reactivation keeps existing settings' );
$GLOBALS['multisite'] = true;
throws( function () { WP_Domain_Alias\Plugin::activate( true ); }, 'WPDA_Test_Die', 'Multisite activation refused' );
ok( ! empty( $GLOBALS['deactivated'] ), 'Multisite activation deactivates the plugin' );

group( 'Must-Use loader' );
$tmp = sys_get_temp_dir() . '/wpda-loader-' . bin2hex( random_bytes( 6 ) );
mkdir( $tmp );
copy( dirname( __DIR__, 2 ) . '/mu-loader/wp-domain-alias-loader.php', $tmp . '/wp-domain-alias-loader.php' );
wpda_test_reset();
require $tmp . '/wp-domain-alias-loader.php';
ok( ! empty( $GLOBALS['wp_filters']['admin_notices'] ), 'missing plugin folder shows an admin notice instead of a fatal error' );
ob_start();
foreach ( $GLOBALS['wp_filters']['admin_notices'][10] as $cb ) { call_user_func( $cb[0] ); }
$notice = ob_get_clean();
ok( false !== strpos( $notice, 'could not find' ) && false === strpos( $notice, $tmp ), 'notice does not reveal server paths' );
ok( ! defined( 'WP_DOMAIN_ALIAS_MU' ), 'MU flag only set when the plugin loads' );
mkdir( $tmp . '/wp-domain-alias' );
file_put_contents( $tmp . '/wp-domain-alias/wp-domain-alias.php', "<?php \$GLOBALS['mu_loaded'] = defined( 'WP_DOMAIN_ALIAS_MU' );" );
wpda_test_reset();
require $tmp . '/wp-domain-alias-loader.php';
ok( ! empty( $GLOBALS['mu_loaded'] ), 'loader includes the plugin and marks it as Must-Use' );
array_map( 'unlink', array( $tmp . '/wp-domain-alias/wp-domain-alias.php', $tmp . '/wp-domain-alias-loader.php' ) );
rmdir( $tmp . '/wp-domain-alias' );
rmdir( $tmp );

group( 'Uninstall' );
class WPDA_Test_WPDB {
	public $options = 'wp_options';
	public $queries = array();
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $q, ...$a ) { return vsprintf( str_replace( '%s', "'%s'", $q ), $a ); }
	public function query( $q ) { $this->queries[] = $q; return 1; }
}
wpda_test_reset();
$GLOBALS['wp_options']['wp_domain_alias_settings'] = array( 'aliases' => array( 'a.example' ) );
$GLOBALS['wpdb'] = new WPDA_Test_WPDB();
define( 'WP_UNINSTALL_PLUGIN', 'wp-domain-alias/wp-domain-alias.php' );
require dirname( __DIR__, 2 ) . '/uninstall.php';
ok( false === get_option( 'wp_domain_alias_settings' ), 'uninstall deletes the settings' );
ok( 1 === count( $GLOBALS['wpdb']->queries ) && false !== strpos( $GLOBALS['wpdb']->queries[0], "'\\_transient\\_wpda\\_%'" ), 'uninstall removes only this plugin\'s transients' );
