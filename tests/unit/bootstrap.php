<?php
/**
 * Dependency-free unit test harness with minimal WordPress stubs.
 *
 * No database, network or WordPress install is used. The real
 * WP_HTML_Tag_Processor is loaded from a WordPress copy (WP_CORE_DIR, or the
 * local .wp-test install) because the HTML pass relies on its behaviour.
 *
 * @package WP_Domain_Alias
 */

// phpcs:ignoreFile -- Test stubs intentionally mirror WordPress function names.

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['wp_version'] = '7.1.4';

// ---------------------------------------------------------------------------
// Real HTML API from WordPress core.
// ---------------------------------------------------------------------------
$core = getenv( 'WP_CORE_DIR' ) ?: dirname( __DIR__, 2 ) . '/.wp-test/wordpress';
$inc  = rtrim( $core, '/' ) . '/wp-includes';
if ( ! is_dir( $inc . '/html-api' ) ) {
	fwrite( STDERR, "WordPress core not found at $core. Set WP_CORE_DIR or run tests/e2e/setup-wp.sh.\n" );
	exit( 2 );
}
foreach ( array( 'compat-utf8.php', 'compat.php', 'utf8.php', 'class-wp-token-map.php', 'html-api/html5-named-character-references.php' ) as $f ) {
	if ( is_file( "$inc/$f" ) ) {
		require_once "$inc/$f";
	}
}
foreach ( array( 'class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $f ) {
	if ( is_file( "$inc/html-api/$f.php" ) ) {
		require_once "$inc/html-api/$f.php";
	}
}

// ---------------------------------------------------------------------------
// State.
// ---------------------------------------------------------------------------
function wpda_test_reset() {
	$GLOBALS['wp_filters']     = array();
	$GLOBALS['wp_options']     = array(
		'home'    => 'https://primary.example',
		'siteurl' => 'https://primary.example',
	);
	$GLOBALS['wp_transients']  = array();
	$GLOBALS['is_admin']       = false;
	$GLOBALS['doing_ajax']     = false;
	$GLOBALS['is_ssl']         = true;
	$GLOBALS['user_caps']      = array( 'manage_options' => true, 'activate_plugins' => true );
	$GLOBALS['http_requests']  = array();
	$GLOBALS['http_responder'] = null;
	$GLOBALS['redirects']      = array();
	$GLOBALS['multisite']      = false;
	$_SERVER['HTTP_HOST']      = 'primary.example';
	$_SERVER['REQUEST_URI']    = '/';
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_POST                     = array();
}
wpda_test_reset();

class WPDA_Test_Die extends Exception {}
class WPDA_Test_Exit extends Exception {}

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

// ---------------------------------------------------------------------------
// Hooks.
// ---------------------------------------------------------------------------
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['wp_filters'][ $tag ][ $priority ][] = array( $cb, $args );
	return true;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) { return add_filter( $tag, $cb, $priority, $args ); }
function has_filter( $tag ) { return ! empty( $GLOBALS['wp_filters'][ $tag ] ); }
function apply_filters( $tag, $value, ...$rest ) {
	if ( empty( $GLOBALS['wp_filters'][ $tag ] ) ) {
		return $value;
	}
	ksort( $GLOBALS['wp_filters'][ $tag ] );
	foreach ( $GLOBALS['wp_filters'][ $tag ] as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $rest ), 0, $entry[1] ) );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) { apply_filters( $tag, ...( $args ?: array( null ) ) ); }
function register_activation_hook( $file, $cb ) { $GLOBALS['activation'] = $cb; }
function register_deactivation_hook( $file, $cb ) { $GLOBALS['deactivation'] = $cb; }

// ---------------------------------------------------------------------------
// Options and transients.
// ---------------------------------------------------------------------------
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['wp_options'] ) ? $GLOBALS['wp_options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['wp_options'][ $name ] = $value; return true; }
function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['wp_options'] ) ) { return false; }
	$GLOBALS['wp_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['wp_options'][ $name ] ); return true; }
function get_transient( $k ) { return isset( $GLOBALS['wp_transients'][ $k ] ) ? $GLOBALS['wp_transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['wp_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['wp_transients'][ $k ] ); return true; }

// ---------------------------------------------------------------------------
// Environment.
// ---------------------------------------------------------------------------
function is_admin() { return $GLOBALS['is_admin']; }
function wp_doing_ajax() { return $GLOBALS['doing_ajax']; }
function wp_doing_cron() { return false; }
function is_ssl() { return $GLOBALS['is_ssl']; }
function is_multisite() { return $GLOBALS['multisite']; }
function is_feed() { return false; }
function is_robots() { return false; }
function is_trackback() { return false; }
function headers_sent_stub() { return false; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return rtrim( get_option( 'home' ), '/' ) . '/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) {
	return apply_filters( 'admin_url', rtrim( get_option( 'siteurl' ), '/' ) . '/wp-admin/' . ltrim( $path, '/' ), $path, null );
}
function plugin_basename( $file ) { return 'wp-domain-alias/' . basename( $file ); }
function plugins_url( $path = '', $file = '' ) { return 'https://primary.example/wp-content/plugins/wp-domain-alias/' . $path; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function get_current_user_id() { return 7; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['user_caps'][ $cap ] ); }

// ---------------------------------------------------------------------------
// i18n, escaping and sanitising.
// ---------------------------------------------------------------------------
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function is_utf8_charset( $c = null ) { return true; }
function _canonical_charset( $c ) { return 'UTF-8'; }
function wp_allowed_protocols() { return array( 'http', 'https', 'mailto', 'tel' ); }
function checked( $a, $b = true, $echo = true ) { return (string) $a === (string) $b ? ' checked="checked"' : ''; }
function submit_button( $text = '' ) { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . esc_attr( $action ) . '">'; }
function check_admin_referer( $action ) {
	if ( ! isset( $_POST['_wpnonce'] ) || 'nonce-' . $action !== $_POST['_wpnonce'] ) {
		throw new WPDA_Test_Die( '403' );
	}
	return 1;
}
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new WPDA_Test_Die( (string) ( is_array( $args ) && isset( $args['response'] ) ? $args['response'] : 'die' ) );
}
function wp_safe_redirect( $location ) { $GLOBALS['redirects'][] = $location; return true; }
function deactivate_plugins( ...$a ) { $GLOBALS['deactivated'] = true; }
function load_plugin_textdomain( ...$a ) { return true; }
function wp_enqueue_style( ...$a ) {}

// ---------------------------------------------------------------------------
// HTTP.
// ---------------------------------------------------------------------------
function wp_safe_remote_get( $url, $args = array() ) {
	$GLOBALS['http_requests'][] = array( $url, $args );
	$responder = $GLOBALS['http_responder'];
	return $responder ? $responder( $url, $args ) : array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '' );
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }
function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? $r['body'] : ''; }

// Real escaping and sanitising functions from core.
require_once $inc . '/kses.php';
require_once $inc . '/formatting.php';

// ---------------------------------------------------------------------------
// Plugin under test.
// ---------------------------------------------------------------------------
require dirname( __DIR__, 2 ) . '/wp-domain-alias.php';

/**
 * Builds a fresh, unregistered component graph with the given settings.
 */
function wpda_components( array $settings = array(), $host = 'primary.example', $uri = '/' ) {
	wpda_test_reset();
	$GLOBALS['wp_options']['wp_domain_alias_settings'] = array_merge(
		array(
			'enabled'        => true,
			'aliases'        => array( 'alias-one.example', 'alias-two.example', 'www.alias-two.example' ),
			'canonical_mode' => 'primary',
			'diagnostics'    => false,
		),
		$settings
	);
	$_SERVER['HTTP_HOST']   = $host;
	$_SERVER['REQUEST_URI'] = $uri;
	$c          = new stdClass();
	$c->domains = new WP_Domain_Alias\Domain_Manager();
	$c->urls    = new WP_Domain_Alias\Url_Handler( $c->domains );
	$c->urls->capture_request_path( $uri );
	$c->seo       = new WP_Domain_Alias\Seo_Handler( $c->domains, $c->urls );
	$c->links     = new WP_Domain_Alias\Link_Handler( $c->domains, $c->urls, $c->seo );
	$c->redirects = new WP_Domain_Alias\Redirect_Handler( $c->domains, $c->urls );
	$c->health    = new WP_Domain_Alias\Health_Check( $c->domains );
	$c->links->register();
	return $c;
}

/** Switches the request host on an existing graph. */
function wpda_host( $c, $host ) {
	$_SERVER['HTTP_HOST'] = $host;
	$c->domains->reset();
}

// ---------------------------------------------------------------------------
// Assertions.
// ---------------------------------------------------------------------------
$GLOBALS['wpda_pass']  = 0;
$GLOBALS['wpda_fail']  = array();
$GLOBALS['wpda_group'] = '';

function group( $name ) { $GLOBALS['wpda_group'] = $name; }
function ok( $condition, $label ) {
	if ( $condition ) {
		$GLOBALS['wpda_pass']++;
		return;
	}
	$GLOBALS['wpda_fail'][] = $GLOBALS['wpda_group'] . ': ' . $label;
	fwrite( STDERR, 'FAIL ' . $GLOBALS['wpda_group'] . ': ' . $label . "\n" );
}
function same( $actual, $expected, $label ) {
	ok( $actual === $expected, $label . ( $actual === $expected ? '' : "\n    expected: " . var_export( $expected, true ) . "\n    actual:   " . var_export( $actual, true ) ) );
}
function throws( callable $fn, $class, $label, $message = null ) {
	try {
		$fn();
		ok( false, $label . ' (no exception)' );
	} catch ( Throwable $e ) {
		ok( $e instanceof $class && ( null === $message || $e->getMessage() === $message ), $label . ' (got ' . get_class( $e ) . ': ' . $e->getMessage() . ')' );
	}
}
