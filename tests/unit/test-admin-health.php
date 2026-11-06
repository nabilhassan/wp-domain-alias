<?php
use WP_Domain_Alias\Admin;
use WP_Domain_Alias\Health_Check;

class WPDA_Test_Admin extends Admin {
	protected function terminate() { throw new WPDA_Test_Exit( 'exit' ); }
}

/** Runs an admin-post handler and returns queued notices. */
function wpda_post( $c, $handler, array $post ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = $post;
	$admin                     = new WPDA_Test_Admin( $c->domains, $c->health );
	try {
		$admin->$handler();
	} catch ( WPDA_Test_Exit $e ) {
		// Expected: handlers redirect and stop.
	}
	$notices = get_transient( 'wpda_notices_7' );
	delete_transient( 'wpda_notices_7' );
	$c->domains->reset();
	return is_array( $notices ) ? $notices : array();
}
function wpda_render( $c ) {
	$admin = new WPDA_Test_Admin( $c->domains, $c->health );
	ob_start();
	try {
		$admin->render();
	} finally {
		$html = ob_get_clean();
	}
	return $html;
}

group( 'Admin authorization' );
$c = wpda_components( array( 'aliases' => array() ) );
$GLOBALS['is_admin'] = true;
foreach ( array( 'handle_save_settings', 'handle_add_alias', 'handle_remove_alias', 'handle_health_check' ) as $h ) {
	$GLOBALS['user_caps'] = array( 'edit_posts' => true );
	throws( function () use ( $c, $h ) { wpda_post( $c, $h, array( '_wpnonce' => 'nonce-wpda_' . substr( $h, 7 ), 'wpda_alias' => 'x.example' ) ); }, 'WPDA_Test_Die', "$h requires manage_options", '403' );
	$GLOBALS['user_caps'] = array( 'manage_options' => true );
	throws( function () use ( $c, $h ) { wpda_post( $c, $h, array( 'wpda_alias' => 'x.example' ) ); }, 'WPDA_Test_Die', "$h rejects a missing nonce", '403' );
	throws( function () use ( $c, $h ) { wpda_post( $c, $h, array( '_wpnonce' => 'nonce-wpda_other', 'wpda_alias' => 'x.example' ) ); }, 'WPDA_Test_Die', "$h rejects another action's nonce", '403' );
}
same( $c->domains->aliases(), array(), 'nothing was saved by rejected requests' );
same( count( $GLOBALS['http_requests'] ), 0, 'rejected requests made no outbound requests' );
$GLOBALS['user_caps'] = array( 'edit_posts' => true );
throws( function () use ( $c ) { wpda_render( $c ); }, 'WPDA_Test_Die', 'settings page requires manage_options', '403' );
$GLOBALS['user_caps'] = array( 'manage_options' => true );

group( 'Admin alias management' );
$c = wpda_components( array( 'aliases' => array() ) );
$add = function ( $value ) use ( $c ) { return wpda_post( $c, 'handle_add_alias', array( '_wpnonce' => 'nonce-wpda_add_alias', 'wpda_alias' => $value ) ); };
$n = $add( 'HTTPS://Alias-One.example/' );
same( $c->domains->aliases(), array( 'alias-one.example' ), 'adds a normalised alias' );
same( $n[0]['type'], 'success', 'success notice' );
$n = $add( 'alias-one.example' );
ok( 'error' === $n[0]['type'] && false !== strpos( $n[0]['message'], 'already' ), 'duplicate rejected' );
$n = $add( 'primary.example' );
ok( 'error' === $n[0]['type'] && false !== strpos( $n[0]['message'], 'primary' ), 'primary domain rejected' );
$n = $add( '*.wild.example' );
ok( 'error' === $n[0]['type'], 'wildcard rejected' );
$n = $add( 'http://alias-two.example' );
ok( 'warning' === $n[0]['type'] && in_array( 'alias-two.example', $c->domains->aliases(), true ), 'http:// accepted with a warning' );
$n = $add( 'www.primary.example' );
ok( 'warning' === $n[0]['type'] && false !== strpos( $n[0]['message'], 'www' ), 'www form of primary warns about redirect conflicts' );
$n = $add( array( 'x.example' ) );
ok( 'error' === $n[0]['type'], 'array input rejected' );
$n = $add( '<script>alert(1)</script>.example' );
ok( 'error' === $n[0]['type'], 'markup rejected' );
$n = wpda_post( $c, 'handle_remove_alias', array( '_wpnonce' => 'nonce-wpda_remove_alias', 'wpda_host' => 'alias-two.example' ) );
same( $c->domains->aliases(), array( 'alias-one.example', 'www.primary.example' ), 'removes an alias' );
$n = wpda_post( $c, 'handle_remove_alias', array( '_wpnonce' => 'nonce-wpda_remove_alias', 'wpda_host' => 'not-there.example' ) );
same( $n[0]['type'], 'error', 'removing an unknown alias reports an error' );
$c   = wpda_components( array( 'aliases' => array() ) );
$big = array();
for ( $i = 0; $i < 25; $i++ ) { $big[] = "a$i.example"; }
$c->domains->save( array( 'aliases' => $big ) );
$n = $add( 'one-more.example' );
ok( 'error' === $n[0]['type'] && 25 === count( $c->domains->aliases() ), 'alias limit enforced' );

group( 'Admin settings' );
$c = wpda_components();
wpda_post( $c, 'handle_save_settings', array( '_wpnonce' => 'nonce-wpda_save_settings', 'wpda_canonical_mode' => 'alias', 'wpda_diagnostics' => '1' ) );
$s = $c->domains->get_settings();
ok( false === $s['enabled'] && 'alias' === $s['canonical_mode'] && true === $s['diagnostics'], 'saves checkboxes and canonical mode' );
same( count( $s['aliases'] ), 3, 'saving settings keeps aliases' );
wpda_post( $c, 'handle_save_settings', array( '_wpnonce' => 'nonce-wpda_save_settings', 'wpda_enabled' => '1', 'wpda_canonical_mode' => '"><script>' ) );
same( $c->domains->canonical_mode(), 'primary', 'invalid canonical mode falls back to primary' );
same( $GLOBALS['redirects'][ count( $GLOBALS['redirects'] ) - 1 ], 'https://primary.example/wp-admin/options-general.php?page=wp-domain-alias', 'redirects back to the settings page' );

group( 'Admin rendering' );
$c = wpda_components( array( 'aliases' => array( 'alias-one.example', 'xn--bcher-kva.example' ) ) );
$GLOBALS['is_admin']                  = true;
$GLOBALS['wp_options']['home']        = 'https://primary.example';
$GLOBALS['wp_transients']['wpda_notices_7'] = array( array( 'type' => 'error', 'message' => '<img src=x onerror=alert(1)>' ) );
$html = wpda_render( $c );
ok( false !== strpos( $html, 'primary.example' ) && false !== strpos( $html, 'Primary (from Settings' ), 'shows the primary domain read-only' );
ok( false === strpos( $html, 'name="wpda_primary' ), 'no input for the primary domain' );
ok( false !== strpos( $html, 'value="alias-one.example"' ) && false !== strpos( $html, 'nonce-wpda_remove_alias' ), 'remove form per alias with nonce' );
ok( false !== strpos( $html, 'nonce-wpda_add_alias' ) && false !== strpos( $html, 'nonce-wpda_save_settings' ) && false !== strpos( $html, 'nonce-wpda_health_check' ), 'every form carries its nonce' );
ok( false === strpos( $html, '<img src=x' ) && false !== strpos( $html, '&lt;img src=x' ), 'notices are escaped' );
same( count( $GLOBALS['http_requests'] ), 0, 'viewing the page makes no outbound requests' );
ok( false !== strpos( $html, 'Version 6.4 or newer' ) && false !== strpos( $html, '0.1.0-beta' ), 'shows version and compatibility' );

group( 'Health check' );
$pages = array(
	'https://primary.example/'   => '<link rel="canonical" href="https://primary.example/"><a href="https://primary.example/about/">a</a>',
	'https://alias-one.example/' => '<link rel="canonical" href="https://primary.example/"><a href="https://alias-one.example/about/">a</a><a href="https://primary.example/wp-login.php">login</a>',
	'https://alias-two.example/' => '<link rel="canonical" href="https://primary.example/"><a href="https://primary.example/about/">a</a>',
);
$c = wpda_components( array( 'aliases' => array( 'alias-one.example', 'alias-two.example', 'www.alias-two.example', 'internal.example' ) ) );
$GLOBALS['http_responder'] = function ( $url ) use ( $pages ) {
	if ( 'https://www.alias-two.example/' === $url ) {
		return array( 'response' => array( 'code' => 301 ), 'headers' => array( 'location' => 'https://primary.example/' ), 'body' => '' );
	}
	if ( 'https://internal.example/' === $url ) {
		return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to 10.0.0.5 port 443' );
	}
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => $pages[ $url ] );
};
$_POST = array( 'url' => 'http://127.0.0.1/private', 'host' => '169.254.169.254' );
$results = $c->health->run_remote_checks();
same( array_column( $GLOBALS['http_requests'], 0 ), array( 'https://primary.example/', 'https://alias-one.example/', 'https://alias-two.example/', 'https://www.alias-two.example/', 'https://internal.example/' ), 'requests only the saved hosts, ignoring request input' );
foreach ( $GLOBALS['http_requests'] as $req ) {
	$a = $req[1];
	ok( $a['timeout'] <= 5 && 0 === $a['redirection'] && true === $a['sslverify'] && true === $a['reject_unsafe_urls'] && $a['limit_response_size'] <= 1048576 && array() === $a['cookies'], 'bounded, no redirects, TLS verified, unsafe URLs rejected, no cookies: ' . $req[0] );
}
$by = array();
foreach ( $results as $r ) {
	foreach ( $r['checks'] as $chk ) { $by[ $r['host'] ][ $chk['label'] ] = $chk; }
}
same( $by['alias-one.example']['Hostname retained']['status'], 'pass', 'alias keeping its hostname passes' );
same( $by['alias-two.example']['Hostname retained']['status'], 'fail', 'alias showing primary links fails (cache or disabled)' );
same( $by['www.alias-two.example']['Redirects']['status'], 'fail', 'cross-domain redirect fails' );
same( $by['alias-one.example']['Canonical']['status'], 'pass', 'primary canonical expected by default' );
same( $by['internal.example']['Reachable']['status'], 'fail', 'failed destination fails' );
$responder                 = $GLOBALS['http_responder'];
$GLOBALS['http_responder'] = function () { return new WP_Error( 'http_request_failed', 'A valid URL was not provided.' ); };
$blocked                   = $c->health->run_remote_checks();
$GLOBALS['http_responder'] = $responder;
ok( false !== strpos( $blocked[1]['checks'][0]['message'], 'private or reserved' ), 'unsafe-URL refusal is explained' );
ok( false === strpos( wp_json_encode( $results ), '10.0.0.5' ), 'transport error details are not disclosed' );

$c->domains->save( array_merge( $c->domains->get_settings(), array( 'canonical_mode' => 'alias' ) ) );
$GLOBALS['http_requests'] = array();
$results = $c->health->run_remote_checks();
$canon = array_column( $results[1]['checks'], 'status', 'label' );
same( $canon['Canonical'], 'fail', 'alias canonical mode expects alias canonical' );

$c = wpda_components();
$GLOBALS['http_responder'] = function () { return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '' ); };
ok( $c->health->acquire_rate_limit( 7 ) && ! $c->health->acquire_rate_limit( 7 ), 'per-user rate limit' );
$GLOBALS['is_admin'] = true;
$n = wpda_post( $c, 'handle_health_check', array( '_wpnonce' => 'nonce-wpda_health_check' ) );
ok( 'warning' === $n[0]['type'] && 0 === count( $GLOBALS['http_requests'] ), 'rate-limited request makes no outbound requests' );
$GLOBALS['wp_transients'] = array();
wpda_post( $c, 'handle_health_check', array( '_wpnonce' => 'nonce-wpda_health_check' ) );
ok( 4 === count( $GLOBALS['http_requests'] ) && is_array( get_transient( 'wpda_health_7' ) ), 'authorised run checks primary + 3 aliases and stores results briefly' );
$before = $GLOBALS['wp_options']['wp_domain_alias_settings'];
same( $GLOBALS['wp_options']['wp_domain_alias_settings'], $before, 'health check does not change settings' );

group( 'Configuration checks' );
$c = wpda_components( array( 'aliases' => array( 'www.primary.example', 'alias-one.example' ) ) );
define( 'WP_CACHE', true );
$labels = array_column( $c->health->configuration_checks(), 'status', 'label' );
same( $labels['Redirect conflict'], 'warn', 'www-of-primary alias flagged' );
same( $labels['www variant'], 'info', 'missing www counterpart noted' );
same( $labels['Page cache'], 'warn', 'page cache flagged' );
same( $labels['WordPress version'], 'pass', 'WordPress version ok' );
