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
foreach ( array( 'handle_save_settings', 'handle_add_alias', 'handle_remove_alias', 'handle_health_check', 'handle_health_continue', 'handle_dismiss_canonical_notice' ) as $h ) {
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
ok( false === $s['enabled'] && true === $s['diagnostics'], 'saves checkboxes' );
ok( ! array_key_exists( 'canonical_mode', $s ) && ! array_key_exists( 'canonical_mode', $GLOBALS['wp_options']['wp_domain_alias_settings'] ), 'a posted canonical mode is ignored and not stored' );
same( count( $s['aliases'] ), 3, 'saving settings keeps aliases' );
same( $GLOBALS['redirects'][ count( $GLOBALS['redirects'] ) - 1 ], 'https://primary.example/wp-admin/options-general.php?page=wp-domain-alias', 'redirects back to the settings page' );

group( 'Canonical policy migration (0.1.0-beta alias mode)' );
$c = wpda_components( array( 'canonical_mode' => 'alias' ) );
ok( ! array_key_exists( 'canonical_mode', $c->domains->get_settings() ), 'legacy key is ignored on read, before any migration' );
ok( $c->domains->migrate_legacy_settings(), 'migration runs once' );
ok( ! array_key_exists( 'canonical_mode', $GLOBALS['wp_options']['wp_domain_alias_settings'] ), 'legacy key removed from the stored option' );
same( $GLOBALS['wp_options']['wp_domain_alias_settings']['aliases'], array( 'alias-one.example', 'alias-two.example', 'www.alias-two.example' ), 'aliases kept by the migration' );
same( get_option( 'wp_domain_alias_canonical_notice' ), 1, 'site that used alias canonicals gets a notice' );
ok( ! $c->domains->migrate_legacy_settings(), 'second run does nothing' );
$c = wpda_components( array( 'canonical_mode' => 'primary' ) );
$c->domains->migrate_legacy_settings();
ok( false === get_option( 'wp_domain_alias_canonical_notice' ) && ! array_key_exists( 'canonical_mode', $GLOBALS['wp_options']['wp_domain_alias_settings'] ), 'primary-mode site migrates silently' );
$c = wpda_components( array( 'canonical_mode' => 'alias' ) );
$c->domains->migrate_legacy_settings();
$admin = new WPDA_Test_Admin( $c->domains, $c->health );
ob_start();
$admin->canonical_notice();
$notice = ob_get_clean();
ok( false !== strpos( $notice, 'was removed in 0.1.1' ) && false !== strpos( $notice, 'nonce-wpda_dismiss_canonical_notice' ), 'notice shown with a dismiss form' );
$GLOBALS['user_caps'] = array( 'edit_posts' => true );
ob_start();
$admin->canonical_notice();
same( ob_get_clean(), '', 'notice hidden from non-admins' );
throws( function () use ( $c ) { wpda_post( $c, 'handle_dismiss_canonical_notice', array( '_wpnonce' => 'nonce-wpda_dismiss_canonical_notice' ) ); }, 'WPDA_Test_Die', 'dismiss requires manage_options', '403' );
$GLOBALS['user_caps'] = array( 'manage_options' => true );
throws( function () use ( $c ) { wpda_post( $c, 'handle_dismiss_canonical_notice', array() ); }, 'WPDA_Test_Die', 'dismiss requires a nonce', '403' );
wpda_post( $c, 'handle_dismiss_canonical_notice', array( '_wpnonce' => 'nonce-wpda_dismiss_canonical_notice' ) );
same( get_option( 'wp_domain_alias_canonical_notice' ), false, 'dismiss removes the flag' );

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
ok( false === strpos( $html, 'wpda_canonical_mode' ) && false !== strpos( $html, 'Always the primary domain.' ), 'no canonical mode choice; primary policy stated' );
ok( false === strpos( $html, '<img src=x' ) && false !== strpos( $html, '&lt;img src=x' ), 'notices are escaped' );
same( count( $GLOBALS['http_requests'] ), 0, 'viewing the page makes no outbound requests' );
ok( false !== strpos( $html, 'Version 6.4 or newer' ) && false !== strpos( $html, WP_DOMAIN_ALIAS_VERSION ), 'shows version and compatibility' );

// Runs a whole health check through the step API, BATCH_SIZE hosts at a time.
function wpda_run_all( $c, $per_step = Health_Check::BATCH_SIZE ) {
	delete_transient( 'wpda_hc_lock_7' );
	$run   = $c->health->start_run( 7 );
	$steps = 0;
	while ( ! Health_Check::is_complete( $run ) ) {
		$run = $c->health->run_step( 7, $per_step );
		if ( is_wp_error( $run ) || ++$steps > 50 ) {
			break;
		}
	}
	return array( $run, $steps );
}
function wpda_by_host( array $results ) {
	$by = array();
	foreach ( $results as $r ) {
		foreach ( $r['checks'] as $chk ) {
			$by[ $r['host'] ][ $chk['label'] ] = $chk;
		}
	}
	return $by;
}
function wpda_aliases( $n ) {
	$a = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$a[] = "alias-$i.example";
	}
	return $a;
}

group( 'Health check results' );
$pages = array(
	'https://primary.example/'   => '<link rel="canonical" href="https://primary.example/"><a href="https://primary.example/about/">a</a>',
	'https://alias-one.example/' => '<link rel="canonical" href="https://primary.example/"><a href="https://alias-one.example/about/">a</a><a href="https://primary.example/wp-login.php">login</a>',
	'https://alias-two.example/' => '<link rel="canonical" href="https://primary.example/"><a href="https://primary.example/about/">a</a>',
	'https://slow.example/'      => '',
	'https://alias-canon.example/' => '<link rel="canonical" href="https://alias-canon.example/"><a href="https://alias-canon.example/x/">a</a>',
);
$c = wpda_components( array( 'aliases' => array( 'alias-one.example', 'alias-two.example', 'www.alias-two.example', 'internal.example', 'slow.example', 'badtls.example', 'gone.example', 'alias-canon.example' ) ) );
$GLOBALS['dns']['gone.example'] = array();
$GLOBALS['http_responder'] = function ( $url ) use ( $pages ) {
	if ( 'https://www.alias-two.example/' === $url ) {
		return array( 'response' => array( 'code' => 301 ), 'headers' => array( 'location' => 'https://primary.example/' ), 'body' => '' );
	}
	if ( 'https://internal.example/' === $url ) {
		return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to 10.0.0.5 port 443' );
	}
	if ( 'https://slow.example/' === $url ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received' );
	}
	if ( 'https://badtls.example/' === $url ) {
		return new WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: self-signed certificate' );
	}
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => $pages[ $url ] );
};
$_POST = array( 'url' => 'http://127.0.0.1/private', 'host' => '169.254.169.254' );
list( $run ) = wpda_run_all( $c );
same( array_column( $GLOBALS['http_requests'], 0 ), array( 'https://primary.example/', 'https://alias-one.example/', 'https://alias-two.example/', 'https://www.alias-two.example/', 'https://internal.example/', 'https://slow.example/', 'https://badtls.example/', 'https://alias-canon.example/' ), 'requests only the saved hosts that resolve, ignoring request input' );
foreach ( $GLOBALS['http_requests'] as $req ) {
	$a = $req[1];
	ok( $a['timeout'] <= 5 && 0 === $a['redirection'] && true === $a['sslverify'] && true === $a['reject_unsafe_urls'] && $a['limit_response_size'] <= 1048576 && array() === $a['cookies'], 'bounded, no redirects, TLS verified, unsafe URLs rejected, no cookies: ' . $req[0] );
}
$by = wpda_by_host( $run['results'] );
same( count( $run['results'] ), 9, 'every host gets a result, including failures' );
same( $by['alias-one.example']['Hostname retained']['status'], 'pass', 'alias keeping its hostname passes' );
same( $by['alias-two.example']['Hostname retained']['status'], 'fail', 'alias showing primary links fails (cache or disabled)' );
same( $by['www.alias-two.example']['Redirects']['status'], 'fail', 'cross-domain redirect fails' );
ok( false !== strpos( $by['www.alias-two.example']['Redirects']['message'], 'https://primary.example/' ), 'redirect target reported, not followed' );
same( $by['alias-one.example']['Canonical']['status'], 'pass', 'primary canonical expected' );
same( $by['alias-canon.example']['Canonical']['status'], 'fail', 'an alias canonical is reported as a failure (primary policy)' );
same( $by['internal.example']['Reachable']['status'], 'fail', 'failed destination fails' );
ok( false !== strpos( $by['slow.example']['Reachable']['message'], 'timed out' ), 'slow response reported as a timeout' );
ok( false !== strpos( $by['badtls.example']['Reachable']['message'], 'certificate' ), 'TLS failure reported as a certificate problem' );
ok( false !== strpos( $by['gone.example']['Reachable']['message'], 'does not resolve' ), 'DNS failure reported without a request' );
ok( false === strpos( wp_json_encode( $run ), '10.0.0.5' ) && false === strpos( wp_json_encode( $run ), 'self-signed' ), 'transport error details are not disclosed' );
$responder                 = $GLOBALS['http_responder'];
$GLOBALS['http_responder'] = function () { return new WP_Error( 'http_request_failed', 'A valid URL was not provided.' ); };
list( $blocked ) = wpda_run_all( $c );
$GLOBALS['http_responder'] = $responder;
ok( false !== strpos( $blocked['results'][1]['checks'][0]['message'], 'private or reserved' ), 'core unsafe-URL refusal is explained' );

group( 'Health check batching' );
foreach ( array( 1 => 1, 5 => 2, 25 => 9 ) as $n => $expected_steps ) {
	$c = wpda_components( array( 'aliases' => wpda_aliases( $n ) ) );
	list( $run, $steps ) = wpda_run_all( $c );
	same( array( count( $run['results'] ), $steps ), array( $n + 1, $expected_steps ), "$n alias(es): " . ( $n + 1 ) . " hosts in $expected_steps step(s) of at most " . Health_Check::BATCH_SIZE );
	same( count( $GLOBALS['http_requests'] ), $n + 1, "$n alias(es): one request per host, none repeated" );
}
$c = wpda_components( array( 'aliases' => wpda_aliases( 25 ) ) );
$run = $c->health->start_run( 7 );
same( count( $GLOBALS['http_requests'] ), 0, 'starting a run makes no request' );
$run = $c->health->run_step( 7, 1000 );
same( count( $GLOBALS['http_requests'] ), Health_Check::BATCH_SIZE, 'a step never exceeds BATCH_SIZE hosts, whatever is asked' );
$run = $c->health->run_step( 7, 0 );
same( count( $GLOBALS['http_requests'] ), Health_Check::BATCH_SIZE + 1, 'a step checks at least one host' );
$c->domains->save( array( 'aliases' => array( 'injected.example' ) ) );
$run = $c->health->run_step( 7, 3 );
ok( ! in_array( 'https://injected.example/', array_column( $GLOBALS['http_requests'], 0 ), true ), 'targets are the snapshot taken at start' );
set_transient( 'wpda_hc_step_7', 1 );
$n_before = count( $GLOBALS['http_requests'] );
$busy     = $c->health->run_step( 7, 3 );
ok( is_wp_error( $busy ) && 'wpda_busy' === $busy->get_error_code() && count( $GLOBALS['http_requests'] ) === $n_before, 'concurrent step refused without requests' );
delete_transient( 'wpda_hc_step_7' );
$wrong = $c->health->run_step( 7, 1, 'not-the-run-id' );
ok( is_wp_error( $wrong ) && 'wpda_no_run' === $wrong->get_error_code(), 'step for another run id refused' );
delete_transient( 'wpda_health_7' );
$expired = $c->health->run_step( 7, 1 );
ok( is_wp_error( $expired ) && 'wpda_no_run' === $expired->get_error_code() && count( $GLOBALS['http_requests'] ) === $n_before, 'expired run refused without requests' );
ok( false !== $c->health->start_run( 8 ) && false === $c->health->start_run( 8 ), 'starting runs is rate-limited per user' );
ok( false === get_transient( 'wpda_hc_step_7' ), 'step lock released after a step' );

group( 'Health check DNS validation and pinning' );
foreach ( array( '93.184.215.14' => true, '2606:2800:21f:cb07:6820:80da:af6b:8b2c' => true, '10.0.0.5' => false, '127.0.0.1' => false, '169.254.169.254' => false, '172.16.0.1' => false, '192.168.1.1' => false, '100.64.0.1' => false, '0.0.0.0' => false, '224.0.0.1' => false, '::1' => false, 'fd00::1' => false, 'fe80::1' => false, '::ffff:127.0.0.1' => false, '::ffff:10.0.0.1' => false, 'not-an-ip' => false ) as $ip => $public ) {
	same( Health_Check::is_public_ip( $ip ), $public, "is_public_ip $ip" );
}
$c = wpda_components( array( 'aliases' => array( 'private.example', 'mixed.example', 'mapped.example' ) ) );
$GLOBALS['dns']['private.example'] = array( '10.1.2.3' );
$GLOBALS['dns']['mixed.example']   = array( '93.184.215.14', '127.0.0.1' );
$GLOBALS['dns']['mapped.example']  = array( '::ffff:169.254.169.254' );
$pinned = array();
$GLOBALS['http_responder'] = function ( $url ) use ( &$pinned ) {
	$pinned[ $url ] = has_filter( 'http_api_curl' );
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '' );
};
list( $run ) = wpda_run_all( $c );
$by = wpda_by_host( $run['results'] );
same( array_column( $GLOBALS['http_requests'], 0 ), array( 'https://primary.example/' ), 'hosts resolving to internal addresses are never requested' );
foreach ( array( 'private.example', 'mixed.example', 'mapped.example' ) as $h ) {
	ok( 'fail' === $by[ $h ]['Reachable']['status'] && false !== strpos( $by[ $h ]['Reachable']['message'], 'private or reserved' ), "refused: $h" );
}
same( $pinned, array( 'https://primary.example/' => true ), 'request is pinned while it runs' );
same( has_filter( 'http_api_curl' ), false, 'pin removed after the request' );
$GLOBALS['dns']['primary.example'] = array( '192.168.10.5' );
list( $run ) = wpda_run_all( $c );
ok( in_array( 'https://primary.example/', array_column( $GLOBALS['http_requests'], 0 ), true ), 'the primary host may resolve internally, as in core' );
unset( $GLOBALS['dns']['primary.example'] );
add_filter( 'http_request_host_is_external', function ( $ext, $host ) { return 'private.example' === $host; }, 10, 2 );
list( $run ) = wpda_run_all( $c );
ok( in_array( 'https://private.example/', array_column( $GLOBALS['http_requests'], 0 ), true ), "core's http_request_host_is_external filter can allow an internal host" );
// The pin callback sets CURLOPT_RESOLVE only for the exact URL being checked.
$captured = null;
$c        = wpda_components( array( 'aliases' => array() ) );
$GLOBALS['http_responder'] = function ( $url ) use ( &$captured ) {
	foreach ( $GLOBALS['wp_filters']['http_api_curl'] as $callbacks ) {
		$captured = $callbacks[0][0];
	}
	return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '' );
};
wpda_run_all( $c );
$handle = curl_init();
$captured( $handle, array(), 'https://other.example/' );
$captured( $handle, array(), 'https://primary.example/' );
ok( is_callable( $captured ), 'pin callback is callable with a real cURL handle' );

group( 'Health check admin endpoints' );
function wpda_ajax( $c, $handler, array $post ) {
	$_POST = $post;
	$admin = new WPDA_Test_Admin( $c->domains, $c->health );
	try {
		$admin->$handler();
	} catch ( WPDA_Test_Json $e ) {
		return array( $e->reply, $e->status );
	}
	return array( null, null );
}
$c = wpda_components( array( 'aliases' => wpda_aliases( 25 ) ) );
$GLOBALS['is_admin'] = true;
foreach ( array( 'ajax_health_start' => 'wpda_health_start', 'ajax_health_step' => 'wpda_health_step' ) as $handler => $action ) {
	$GLOBALS['user_caps'] = array( 'edit_posts' => true );
	list( $r, $st ) = wpda_ajax( $c, $handler, array( 'nonce' => 'nonce-' . $action ) );
	ok( false === $r['success'] && 403 === $st, "$handler refuses non-admins" );
	$GLOBALS['user_caps'] = array( 'manage_options' => true );
	list( $r, $st ) = wpda_ajax( $c, $handler, array( 'nonce' => 'nonce-wpda_other' ) );
	ok( false === $r['success'] && 403 === $st, "$handler refuses an invalid or expired nonce" );
	list( $r, $st ) = wpda_ajax( $c, $handler, array() );
	ok( false === $r['success'] && 403 === $st, "$handler refuses a missing nonce" );
}
same( count( $GLOBALS['http_requests'] ), 0, 'refused AJAX calls made no requests' );
list( $r ) = wpda_ajax( $c, 'ajax_health_start', array( 'nonce' => 'nonce-wpda_health_start' ) );
ok( true === $r['success'] && 26 === $r['data']['total'] && 0 === count( $GLOBALS['http_requests'] ), 'start returns the number of domains and requests nothing' );
$run_id = $r['data']['run'];
list( $again, $st ) = wpda_ajax( $c, 'ajax_health_start', array( 'nonce' => 'nonce-wpda_health_start' ) );
ok( false === $again['success'] && 429 === $st, 'repeated start is rate-limited' );
$GLOBALS['http_responder'] = function () { return array( 'response' => array( 'code' => 301 ), 'headers' => array( 'location' => 'https://evil.example/"><script>alert(1)</script>' ), 'body' => '' ); };
list( $r ) = wpda_ajax( $c, 'ajax_health_step', array( 'nonce' => 'nonce-wpda_health_step', 'run' => $run_id ) );
ok( true === $r['success'] && 1 === $r['data']['checked'] && false === $r['data']['done'] && 1 === count( $GLOBALS['http_requests'] ), 'AJAX step checks exactly one host' );
ok( false === strpos( $r['data']['html'], '<script>' ) && false !== strpos( $r['data']['html'], '&lt;script&gt;' ), 'step HTML escapes remote data' );
list( $r, $st ) = wpda_ajax( $c, 'ajax_health_step', array( 'nonce' => 'nonce-wpda_health_step', 'run' => 'stale' ) );
ok( false === $r['success'] && 410 === $st, 'step for a stale run id is refused' );
for ( $i = 0; $i < 30 && ! empty( $r['data'] ) && empty( $r['data']['done'] ); $i++ ) {
	list( $r ) = wpda_ajax( $c, 'ajax_health_step', array( 'nonce' => 'nonce-wpda_health_step', 'run' => $run_id ) );
}
ok( true === $r['data']['done'] && 26 === $r['data']['checked'] && 26 === count( $GLOBALS['http_requests'] ), 'AJAX run completes with one request per host' );
list( $r ) = wpda_ajax( $c, 'ajax_health_step', array( 'nonce' => 'nonce-wpda_health_step', 'run' => $run_id ) );
ok( true === $r['data']['done'] && 26 === count( $GLOBALS['http_requests'] ), 'steps after completion make no requests' );

// No-JavaScript path.
$c = wpda_components( array( 'aliases' => wpda_aliases( 25 ) ) );
$GLOBALS['is_admin'] = true;
$GLOBALS['http_responder'] = function () { return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '' ); };
wpda_post( $c, 'handle_health_check', array( '_wpnonce' => 'nonce-wpda_health_check' ) );
same( count( $GLOBALS['http_requests'] ), Health_Check::BATCH_SIZE, 'form post checks the first batch only' );
$html = wpda_render( $c );
ok( false !== strpos( $html, 'Checked 3 of 26 domains.' ) && false !== strpos( $html, 'nonce-wpda_health_continue' ) && false !== strpos( $html, '23 remaining' ), 'page shows partial results and a continue button' );
$n = wpda_post( $c, 'handle_health_check', array( '_wpnonce' => 'nonce-wpda_health_check' ) );
ok( 'warning' === $n[0]['type'] && Health_Check::BATCH_SIZE === count( $GLOBALS['http_requests'] ), 'restart within the rate limit makes no requests' );
throws( function () use ( $c ) { wpda_post( $c, 'handle_health_continue', array() ); }, 'WPDA_Test_Die', 'continue requires a nonce', '403' );
for ( $i = 0; $i < 8; $i++ ) {
	wpda_post( $c, 'handle_health_continue', array( '_wpnonce' => 'nonce-wpda_health_continue' ) );
}
$html = wpda_render( $c );
ok( 26 === count( $GLOBALS['http_requests'] ) && false === strpos( $html, 'nonce-wpda_health_continue' ) && 26 === substr_count( $html, '<h3>' ), 'continuing completes the run; no continue button afterwards' );
$before = $GLOBALS['wp_options']['wp_domain_alias_settings'];
wpda_post( $c, 'handle_health_continue', array( '_wpnonce' => 'nonce-wpda_health_continue' ) );
same( count( $GLOBALS['http_requests'] ), 26, 'continuing a finished run makes no requests' );
same( $GLOBALS['wp_options']['wp_domain_alias_settings'], $before, 'health check does not change settings' );
$GLOBALS['user_caps'] = array( 'edit_posts' => true );
throws( function () use ( $c ) { wpda_post( $c, 'handle_health_continue', array( '_wpnonce' => 'nonce-wpda_health_continue' ) ); }, 'WPDA_Test_Die', 'continue requires manage_options', '403' );
$GLOBALS['user_caps'] = array( 'manage_options' => true );

group( 'Configuration checks' );
$c = wpda_components( array( 'aliases' => array( 'www.primary.example', 'alias-one.example' ) ) );
define( 'WP_CACHE', true );
$labels = array_column( $c->health->configuration_checks(), 'status', 'label' );
same( $labels['Redirect conflict'], 'warn', 'www-of-primary alias flagged' );
same( $labels['www variant'], 'info', 'missing www counterpart noted' );
same( $labels['Page cache'], 'warn', 'page cache flagged' );
same( $labels['WordPress version'], 'pass', 'WordPress version ok' );
