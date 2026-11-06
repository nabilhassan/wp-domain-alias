<?php
use WP_Domain_Alias\Domain_Manager;

group( 'Domain_Manager' );
$c = wpda_components( array(), 'alias-one.example' );
same( $c->domains->primary_host(), 'primary.example', 'primary host comes from the home option' );
same( $c->domains->current_alias(), 'alias-one.example', 'approved alias is recognised' );
foreach ( array( 'ALIAS-ONE.example', 'alias-one.example:443' ) as $h ) {
	wpda_host( $c, $h );
	same( $c->domains->current_alias(), 'alias-one.example', "recognises $h" );
}
foreach ( array( 'evil.example', 'alias-one.example.evil.example', 'alias-one.example:8443', 'alias-one.example.', 'user@alias-one.example', "alias-one.example\r\nX-Test: injected", 'primary.example', '' ) as $h ) {
	wpda_host( $c, $h );
	same( $c->domains->current_alias(), '', 'not an alias: ' . json_encode( $h ) );
}
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'alias-one.example';
wpda_host( $c, 'evil.example' );
same( $c->domains->current_alias(), '', 'X-Forwarded-Host is ignored' );
unset( $_SERVER['HTTP_X_FORWARDED_HOST'] );

$c = wpda_components( array( 'enabled' => false ), 'alias-one.example' );
same( $c->domains->current_alias(), '', 'disabled plugin recognises no alias' );

$c = wpda_components( array( 'aliases' => array( 'ok.example', 'BAD.example', '*.wild.example', 'primary.example', 'ok.example', 'evil.example:80', array( 'x' ), '' ) ), 'ok.example' );
same( $c->domains->aliases(), array( 'ok.example' ), 'stored aliases are re-validated, de-duplicated and exclude the primary' );
$many = array();
for ( $i = 0; $i < 40; $i++ ) { $many[] = "a$i.example"; }
$c = wpda_components( array( 'aliases' => $many ) );
same( count( $c->domains->aliases() ), Domain_Manager::MAX_ALIASES, 'alias count is capped' );
$c = wpda_components( array( 'canonical_mode' => 'something-else' ) );
ok( ! array_key_exists( 'canonical_mode', $c->domains->get_settings() ), 'removed canonical_mode key is dropped on read' );
$GLOBALS['wp_options']['wp_domain_alias_settings'] = 'corrupted';
$c->domains->reset();
same( $c->domains->get_settings(), Domain_Manager::defaults(), 'corrupted option falls back to defaults' );

$c = wpda_components( array(), 'alias-one.example' );
add_filter( 'wp_domain_alias_aliases', function ( $a ) { $a[] = 'Injected.Example'; $a[] = 'filtered.example'; return $a; } );
same( in_array( 'filtered.example', $c->domains->aliases(), true ) && ! in_array( 'Injected.Example', $c->domains->aliases(), true ), true, 'filtered aliases are validated' );
same( $c->domains->own_hosts(), array( 'alias-one.example', 'alias-two.example', 'www.alias-two.example', 'filtered.example', 'primary.example', 'www.primary.example' ), 'own hosts include primary and its www form' );

group( 'Url_Handler::to_alias' );
$c = wpda_components( array(), 'alias-one.example' );
$u = $c->urls;
same( $u->to_alias( 'https://primary.example/contact/?gclid=test#form' ), 'https://alias-one.example/contact/?gclid=test#form', 'keeps path, query and fragment' );
same( $u->to_alias( '//primary.example/contact/' ), 'https://alias-one.example/contact/', 'protocol-relative URL' );
same( $u->to_alias( 'HTTPS://PRIMARY.EXAMPLE/About/' ), 'https://alias-one.example/About/', 'case-insensitive scheme/host, path case kept' );
same( $u->to_alias( 'https://www.primary.example/x' ), 'https://alias-one.example/x', 'www form of primary' );
same( $u->to_alias( 'https://alias-two.example/x' ), 'https://alias-one.example/x', 'other alias moves to the current alias' );
same( $u->to_alias( 'https://primary.example' ), 'https://alias-one.example', 'bare origin' );
foreach ( array( 'https://evil.example/', 'https://primary.example.evil.example/', 'https://user@primary.example/', 'https://primary.example:443/', 'https://primary.example:8080/', 'https://evil.example/https://primary.example/', '/relative/path', 'relative', '#anchor', 'mailto:hi@primary.example', 'tel:123', 'javascript:alert(1)', 'data:text/html,primary.example', 'https:\\\\primary.example', '', ) as $url ) {
	same( $u->to_alias( $url ), $url, 'unchanged: ' . $url );
}
same( $u->to_alias( null ), null, 'non-string input is returned unchanged' );
foreach ( array( '/wp-admin/', '/wp-admin', '/wp-admin/post.php?x=1', '/WP-ADMIN/', '/wp-login.php', '/wp-login.php?action=logout', '/wp-signup.php', '/wp-activate.php', '/wp-cron.php', '/xmlrpc.php', '/wp-admin/admin-post.php' ) as $path ) {
	$url = 'https://primary.example' . $path;
	same( $u->to_alias( $url ), $url, 'protected endpoint ' . $path );
}
same( $u->to_alias( 'https://primary.example/wp-admin/admin-ajax.php' ), 'https://primary.example/wp-admin/admin-ajax.php', 'admin-ajax stays without explicit permission' );
same( $u->to_alias( 'https://primary.example/wp-admin/admin-ajax.php?action=x', true ), 'https://alias-one.example/wp-admin/admin-ajax.php?action=x', 'frontend AJAX exception' );
same( $u->to_alias( 'https://primary.example/wp-admin/admin-ajax.php/../options.php', true ), 'https://primary.example/wp-admin/admin-ajax.php/../options.php', 'AJAX exception is an exact path match' );
same( $u->to_alias( 'https://primary.example/wp%2Dlogin.php' ), 'https://primary.example/wp%2Dlogin.php', 'encoded protected link stays' );
same( $u->to_alias( 'https://primary.example//wp-admin/' ), 'https://primary.example//wp-admin/', 'double-slash protected link stays' );
same( $u->to_alias( 'https://primary.example/wp-loginx/' ), 'https://alias-one.example/wp-loginx/', 'only exact protected names' );
add_filter( 'wp_domain_alias_rewrite_url', function ( $ok, $url ) { return false === strpos( $url, '/checkout/' ); }, 10, 2 );
same( $u->to_alias( 'https://primary.example/checkout/' ), 'https://primary.example/checkout/', 'rewrite_url filter can keep a URL on its host' );

$c = wpda_components( array(), 'alias-one.example' );
$GLOBALS['is_ssl'] = false;
$GLOBALS['wp_options']['home'] = 'http://primary.example';
$c->domains->reset();
same( $c->urls->to_alias( 'http://primary.example/x' ), 'http://alias-one.example/x', 'HTTP site keeps HTTP' );
$GLOBALS['wp_options']['home'] = 'https://primary.example';
same( $c->urls->to_alias( 'http://primary.example/x' ), 'https://alias-one.example/x', 'HTTPS site never downgrades' );

$c = wpda_components( array(), 'alias-one.example', '/' );
$GLOBALS['wp_options']['siteurl'] = 'https://primary.example/wp';
same( $c->urls->to_alias( 'https://primary.example/wp/wp-admin/' ), 'https://primary.example/wp/wp-admin/', 'subdirectory install protects /wp/wp-admin' );
same( $c->urls->to_alias( 'https://primary.example/wp/wp-admin/admin-ajax.php', true ), 'https://alias-one.example/wp/wp-admin/admin-ajax.php', 'subdirectory AJAX exception' );

group( 'Url_Handler::is_alias_frontend' );
$c = wpda_components( array(), 'alias-one.example', '/about/' );
ok( $c->urls->is_alias_frontend(), 'alias page view' );
$GLOBALS['is_admin'] = true;
ok( ! $c->urls->is_alias_frontend(), 'not in admin' );
$GLOBALS['is_admin']   = false;
$GLOBALS['doing_ajax'] = true;
ok( ! $c->urls->is_alias_frontend(), 'not during AJAX' );
$GLOBALS['doing_ajax'] = false;
$c->urls->capture_request_path( '/wp-login.php?action=lostpassword' );
ok( ! $c->urls->is_alias_frontend(), 'not on wp-login.php' );
foreach ( array( '/wp%2Dlogin.php', '//wp-login.php', '/wp%2dadmin/', '//wp-admin//options.php', '/xmlrpc%2Ephp' ) as $odd ) {
	$c->urls->capture_request_path( $odd );
	ok( ! $c->urls->is_alias_frontend(), 'not on an encoded or doubled protected path: ' . $odd );
}
$c = wpda_components( array(), 'primary.example', '/about/' );
ok( ! $c->urls->is_alias_frontend(), 'not on the primary host' );

group( 'Redirect_Handler' );
$c = wpda_components( array(), 'alias-one.example', '/about' );
$r = $c->redirects;
same( $r->redirect_canonical( 'https://primary.example/', 'https://alias-one.example/' ), false, 'cancels a redirect that only switches host' );
same( $r->redirect_canonical( 'https://primary.example/', 'https://ALIAS-ONE.example/' ), false, 'host case differences do not loop' );
same( $r->redirect_canonical( 'https://primary.example/about/', 'https://alias-one.example/about' ), 'https://alias-one.example/about/', 'keeps trailing-slash normalisation on the alias' );
same( $r->redirect_canonical( 'https://primary.example/about/?utm_source=x&gclid=y', 'https://alias-one.example/?page_id=4&utm_source=x&gclid=y' ), 'https://alias-one.example/about/?utm_source=x&gclid=y', 'keeps query parameters' );
same( $r->redirect_canonical( false, 'https://alias-one.example/' ), false, 'no redirect stays no redirect' );
same( $r->redirect_canonical( 'https://evil.example/', 'https://alias-one.example/' ), 'https://evil.example/', 'external target unchanged (no new destinations)' );
same( $r->wp_redirect( 'https://primary.example/new-page/?utm_source=a' ), 'https://alias-one.example/new-page/?utm_source=a', 'legacy redirects stay on alias' );
same( $r->wp_redirect( 'https://evil.example/' ), 'https://evil.example/', 'external redirects unchanged' );
same( $r->wp_redirect( 'https://primary.example/wp-login.php' ), 'https://primary.example/wp-login.php', 'login redirect stays on primary' );
same( $r->allowed_redirect_hosts( array( 'primary.example' ) ), array( 'primary.example', 'alias-one.example', 'alias-two.example', 'www.alias-two.example' ), 'approved aliases are safe redirect hosts' );
$c = wpda_components( array(), 'primary.example' );
same( $c->redirects->redirect_canonical( 'https://primary.example/x/', 'https://primary.example/x' ), 'https://primary.example/x/', 'primary behaviour unchanged' );
same( $c->redirects->wp_redirect( 'https://primary.example/x/' ), 'https://primary.example/x/', 'primary redirects unchanged' );
$c = wpda_components( array( 'aliases' => array( 'www.primary.example' ) ), 'www.primary.example', '/x/' );
same( $c->redirects->redirect_canonical( 'https://primary.example/x/', 'https://www.primary.example/x/' ), false, 'www-of-primary alias is not redirected to primary' );
