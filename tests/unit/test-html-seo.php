<?php
group( 'Link_Handler::rewrite_html' );
$page = <<<'HTML'
<!DOCTYPE html><html><head>
<link rel="canonical" href="https://primary.example/about/">
<link rel="alternate" hreflang="es" href="https://alias-one.example/es/sobre/">
<link rel="alternate" hreflang="en" href="https://primary.example/about/">
<link rel="alternate" type="application/rss+xml" href="https://primary.example/feed/">
<link rel="stylesheet" href="https://primary.example/wp-content/themes/t/style.css?ver=1">
<link rel="preload" as="font" href="https://primary.example/wp-content/fonts/a.woff2" crossorigin>
<link rel="icon" href="https://primary.example/favicon.ico">
<meta property="og:url" content="https://alias-one.example/about/">
<script type="importmap">{"imports":{"@wordpress/interactivity":"https://primary.example/wp-includes/js/i.js"}}</script>
<script src="https://primary.example/wp-includes/js/jquery.js"></script>
<script type="application/ld+json">{"url":"https://primary.example/about/","@id":"https://primary.example/#org"}</script>
<script>var cfg = {"home":"https://primary.example/","link":"<a href=\"https://primary.example/x\">"};</script>
<style>@font-face{src:url('https://primary.example/wp-content/fonts/a.woff2')} .x{background:url(https://cdn.example/bg.png)}</style>
</head><body>
<a id="nav" href="https://primary.example/contact/?utm_source=x&amp;gclid=y#form">Contact</a>
<a id="ext" href="https://example.org/">External</a>
<a id="adm" href="https://primary.example/wp-admin/">Admin</a>
<a id="look" href="https://primary.example.evil.example/">Lookalike</a>
<area href="//primary.example/map/">
<form id="f" action="https://primary.example/wp-comments-post.php"><button formaction="https://primary.example/about/">Go</button><input type="submit" formaction="https://primary.example/wp-admin/admin-ajax.php"></form>
<div data-column-clickable="https://primary.example/services/"></div>
<img src="https://primary.example/wp-content/uploads/a.jpg" srcset="https://primary.example/wp-content/uploads/a-300.jpg 300w">
<!-- https://primary.example/in-a-comment/ -->
<textarea>https://primary.example/typed</textarea>
</body></html>
HTML;

$c   = wpda_components( array(), 'alias-one.example', '/about/' );
$out = $c->links->rewrite_html( $page );
$has = function ( $needle ) use ( &$out ) { return false !== strpos( $out, $needle ); };
ok( $has( 'href="https://alias-one.example/contact/?utm_source=x&#038;gclid=y#form"' ), 'navigation link moves, query and fragment kept (core esc_url encoding)' );
ok( $has( 'href="https://example.org/"' ), 'external link unchanged' );
ok( $has( 'href="https://primary.example/wp-admin/"' ), 'admin link stays on primary' );
ok( $has( 'href="https://primary.example.evil.example/"' ), 'look-alike host unchanged' );
ok( $has( '<area href="https://alias-one.example/map/">' ), 'area href' );
ok( $has( 'action="https://alias-one.example/wp-comments-post.php"' ), 'form action' );
ok( $has( 'formaction="https://alias-one.example/about/"' ), 'button formaction' );
ok( $has( 'formaction="https://alias-one.example/wp-admin/admin-ajax.php"' ), 'formaction to admin-ajax (frontend AJAX)' );
ok( $has( 'data-column-clickable="https://alias-one.example/services/"' ), 'Elementor clickable column' );
ok( $has( '<link rel="canonical" href="https://primary.example/about/">' ), 'canonical stays primary' );
ok( $has( 'hreflang="es" href="https://primary.example/es/sobre/"' ), 'alias hreflang normalised to primary' );
ok( $has( 'hreflang="en" href="https://primary.example/about/"' ), 'primary hreflang unchanged' );
ok( $has( 'content="https://primary.example/about/"' ), 'og:url normalised to primary' );
ok( $has( 'type="application/rss+xml" href="https://primary.example/feed/"' ), 'feed link unchanged' );
ok( $has( 'rel="stylesheet" href="https://alias-one.example/wp-content/themes/t/style.css?ver=1"' ), 'stylesheet same-origin' );
ok( $has( 'rel="preload" as="font" href="https://alias-one.example/wp-content/fonts/a.woff2"' ), 'font preload same-origin' );
ok( $has( 'rel="icon" href="https://primary.example/favicon.ico"' ), 'icon unchanged' );
ok( $has( '<script src="https://alias-one.example/wp-includes/js/jquery.js">' ), 'script src same-origin' );
ok( $has( '"@wordpress/interactivity":"https://alias-one.example/wp-includes/js/i.js"' ), 'import map same-origin' );
ok( $has( "url('https://alias-one.example/wp-content/fonts/a.woff2')" ), 'inline font URL same-origin' );
ok( $has( 'url(https://cdn.example/bg.png)' ), 'CDN asset unchanged' );
ok( $has( '{"url":"https://primary.example/about/","@id":"https://primary.example/#org"}' ), 'JSON-LD untouched' );
ok( $has( 'var cfg = {"home":"https://primary.example/","link":"<a href=\"https://primary.example/x\">"};' ), 'inline script text untouched' );
ok( $has( 'src="https://primary.example/wp-content/uploads/a.jpg" srcset="https://primary.example/wp-content/uploads/a-300.jpg 300w"' ), 'images stay on primary' );
ok( $has( '<!-- https://primary.example/in-a-comment/ -->' ), 'comments untouched' );
ok( $has( '<textarea>https://primary.example/typed</textarea>' ), 'textarea text untouched' );
same( $c->links->last_rewrite_count(), 13, 'rewrite count' );

$evil = '<a href="https://primary.example/&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">x</a>';
$res  = $c->links->rewrite_html( $evil );
ok( false === strpos( $res, '<script>' ) && false !== strpos( $res, 'alias-one.example' ), 'rewritten attribute values stay escaped' );

$many = '<head><link rel="canonical" href="https://primary.example/about/"></head><body>';
for ( $i = 0; $i < 350; $i++ ) {
	$many .= '<a href="https://primary.example/p-' . $i . '/">' . $i . '</a><a href="https://example.org/' . $i . '">x</a>';
}
$many .= '<style>@font-face{src:url(https://primary.example/f.woff2)}</style><a id="last" href="https://primary.example/last/">last</a></body>';
$res   = $c->links->rewrite_html( $many );
same( substr_count( $res, 'href="https://alias-one.example/p-' ), 350, 'all links rewritten across intermediate flushes' );
same( substr_count( $res, 'href="https://example.org/' ), 350, 'external links untouched across flushes' );
ok( false !== strpos( $res, 'id="last" href="https://alias-one.example/last/"' ) && false !== strpos( $res, 'url(https://alias-one.example/f.woff2)' ), 'tags after the last flush are rewritten' );
ok( false !== strpos( $res, 'rel="canonical" href="https://primary.example/about/"' ), 'canonical unchanged in a large document' );
same( $c->links->last_rewrite_count(), 352, 'rewrite count is exact across flushes' );

$plain = '<p>No own hosts here <a href="https://example.org/">x</a></p>';
same( $c->links->rewrite_html( $plain ), $plain, 'documents without own hosts are returned untouched' );
same( $c->links->rewrite_html( 42 ), 42, 'non-string passes through' );

wpda_host( $c, 'primary.example' );
same( $c->links->rewrite_html( $page ), $page, 'primary host output is untouched' );
$c = wpda_components( array( 'enabled' => false ), 'alias-one.example' );
same( $c->links->rewrite_html( $page ), $page, 'disabled plugin leaves output untouched' );

$c = wpda_components( array(), 'alias-one.example' );
add_filter( 'wp_domain_alias_same_origin_assets', '__return_false' );
function __return_false() { return false; }
$out = $c->links->rewrite_html( $page );
ok( false !== strpos( $out, '<script src="https://primary.example/wp-includes/js/jquery.js">' ) && false !== strpos( $out, 'href="https://alias-one.example/contact/' ), 'asset handling can be turned off by filter' );

$c   = wpda_components( array(), 'alias-one.example' );
$out = $c->links->rewrite_html( '<a href="https://primary.example/x">x</a><script type="importmap">not json primary.example</script>' );
ok( false !== strpos( $out, 'not json primary.example' ), 'invalid import map is left alone' );

group( 'Link_Handler endpoints' );
$c = wpda_components( array(), 'alias-one.example', '/about/' );
same( $c->links->rest_url( 'https://primary.example/wp-json/' ), 'https://alias-one.example/wp-json/', 'REST root same-origin' );
same( $c->links->admin_url( 'https://primary.example/wp-admin/admin-ajax.php', 'admin-ajax.php' ), 'https://alias-one.example/wp-admin/admin-ajax.php', 'admin-ajax same-origin' );
same( $c->links->admin_url( 'https://primary.example/wp-admin/admin-ajax.php?action=a', '/admin-ajax.php?action=a' ), 'https://alias-one.example/wp-admin/admin-ajax.php?action=a', 'admin-ajax with query' );
same( $c->links->admin_url( 'https://primary.example/wp-admin/options.php', 'options.php' ), 'https://primary.example/wp-admin/options.php', 'other admin URLs stay' );
same( $c->links->admin_url( 'https://primary.example/wp-admin/admin-post.php', 'admin-post.php' ), 'https://primary.example/wp-admin/admin-post.php', 'admin-post stays on primary' );
same( admin_url( 'admin-ajax.php' ), 'https://alias-one.example/wp-admin/admin-ajax.php', 'registered admin_url filter does not recurse' );
same( $c->links->asset_url( 'https://primary.example/wp-includes/js/m.js' ), 'https://alias-one.example/wp-includes/js/m.js', 'script module src same-origin' );
same( $c->links->font_url( 'https://primary.example/wp-content/themes/t/fonts/a.woff2' ), 'https://alias-one.example/wp-content/themes/t/fonts/a.woff2', 'theme font same-origin' );
same( $c->links->font_url( 'https://primary.example/wp-content/themes/t/img/a.png' ), 'https://primary.example/wp-content/themes/t/img/a.png', 'other theme files unchanged' );
same( $c->links->font_dir( array( 'path' => '/x', 'url' => 'https://primary.example/wp-content/fonts', 'baseurl' => 'https://primary.example/wp-content/fonts' ) ), array( 'path' => '/x', 'url' => 'https://alias-one.example/wp-content/fonts', 'baseurl' => 'https://alias-one.example/wp-content/fonts' ), 'Font Library directory same-origin' );
$GLOBALS['is_admin'] = true;
same( $c->links->font_url( 'https://primary.example/wp-content/themes/t/fonts/a.woff2' ), 'https://primary.example/wp-content/themes/t/fonts/a.woff2', 'admin keeps fonts on primary' );
same( $c->links->rest_url( 'https://primary.example/wp-json/' ), 'https://primary.example/wp-json/', 'admin requests keep REST on primary' );

group( 'Link_Handler output buffer' );
$c = wpda_components( array(), 'alias-one.example', '/about/' );
same( $c->links->buffer_callback( '<a href="https://prim', PHP_OUTPUT_HANDLER_START ), '', 'intermediate chunk held back' );
same( $c->links->buffer_callback( 'ary.example/x">x</a>', PHP_OUTPUT_HANDLER_FINAL ), '<a href="https://alias-one.example/x">x</a>', 'tag split across chunks is rewritten once' );
$c->links->buffer_callback( '<a href="https://primary.example/kept">', PHP_OUTPUT_HANDLER_FLUSH );
$c->links->buffer_callback( 'discarded', PHP_OUTPUT_HANDLER_CLEAN );
same( $c->links->buffer_callback( '</a>', PHP_OUTPUT_HANDLER_FINAL ), '<a href="https://alias-one.example/kept"></a>', 'ob_clean discards only the cleaned chunk' );

$c = wpda_components( array( 'diagnostics' => true ), 'alias-one.example' );
$out = $c->links->rewrite_html( '<a href="https://primary.example/">x</a>' );
ok( false !== strpos( $out, '<!-- WP Domain Alias: 1 URL(s) adjusted for alias-one.example -->' ), 'diagnostics comment' );

group( 'Seo_Handler' );
$c = wpda_components( array(), 'alias-one.example', '/about/' );
same( $c->seo->filter_url( 'https://alias-one.example/about/' ), 'https://primary.example/about/', 'primary mode: canonical filters return primary' );
same( $c->seo->filter_url( 'https://other.example/x/' ), 'https://other.example/x/', 'deliberate cross-domain canonical kept' );
// A 0.1.0-beta site that stored "alias" canonicals gets the primary policy, before and after migration.
$c = wpda_components( array( 'canonical_mode' => 'alias' ), 'alias-one.example', '/about/' );
same( $c->seo->filter_url( 'https://primary.example/about/' ), 'https://primary.example/about/', 'legacy alias setting: canonical filters return primary' );
same( $c->seo->filter_url( 'https://www.alias-two.example/about/' ), 'https://primary.example/about/', 'canonical on another alias moves to primary' );
$out = $c->links->rewrite_html( $page );
ok( false !== strpos( $out, '<link rel="canonical" href="https://primary.example/about/">' ), 'legacy alias setting: canonical tag on primary' );
ok( false !== strpos( $out, 'hreflang="es" href="https://primary.example/es/sobre/"' ) && false !== strpos( $out, 'hreflang="en" href="https://primary.example/about/"' ), 'legacy alias setting: hreflang on primary' );
ok( false !== strpos( $out, 'property="og:url" content="https://primary.example/about/"' ), 'legacy alias setting: og:url on primary' );
ok( false !== strpos( $out, '{"url":"https://primary.example/about/"' ), 'JSON-LD untouched' );
same( substr_count( $out, 'rel="canonical"' ), 1, 'exactly one canonical tag' );
$c = wpda_components( array(), 'primary.example', '/about/' );
same( $c->seo->filter_url( 'https://alias-one.example/about/' ), 'https://alias-one.example/about/', 'primary host requests are not filtered' );
$dup = '<link rel="canonical" href="https://primary.example/a/"><link rel="canonical" href="https://primary.example/a/">';
$c   = wpda_components( array(), 'alias-one.example' );
same( substr_count( $c->links->rewrite_html( $dup . '<a href="https://primary.example/">' ), 'rel="canonical"' ), 2, 'never adds canonical tags' );

group( 'Weglot integration (stubbed)' );
$c = wpda_components( array(), 'alias-one.example', '/es/sobre-nosotros/' );
WP_Domain_Alias\Integrations\Weglot::register( $c->links, $c->seo, $c->urls );
same( WP_Domain_Alias\Integrations\Weglot::language_path( '/es/x/' ), '', 'inactive without Weglot functions' );
if ( ! function_exists( 'weglot_get_current_language' ) ) { // Conditional, so it is not hoisted above the "inactive" assertion.
	function weglot_get_current_language() { return $GLOBALS['weglot_lang']; }
	function weglot_get_original_language() { return 'en'; }
}
$GLOBALS['weglot_lang'] = 'es';
$out = apply_filters( 'weglot_render_dom', '<link rel="canonical" href="https://primary.example/about/"><a href="https://primary.example/es/contacto/">c</a>' );
ok( false !== strpos( $out, 'rel="canonical" href="https://primary.example/es/sobre-nosotros/"' ), 'translated canonical keeps the language path on primary' );
ok( false !== strpos( $out, 'href="https://alias-one.example/es/contacto/"' ), 'Weglot output links stay on alias' );
same( WP_Domain_Alias\Integrations\Weglot::language_path( '/es' ), '/es', 'bare language root' );
same( WP_Domain_Alias\Integrations\Weglot::language_path( '/estate/' ), '', 'prefix must be a full path segment' );
$GLOBALS['weglot_lang'] = 'en';
same( WP_Domain_Alias\Integrations\Weglot::language_path( '/es/x/' ), '', 'original language is not changed' );
$GLOBALS['weglot_lang'] = 'es';
$out = apply_filters( 'weglot_render_dom', '<link rel="canonical" href="https://other.example/page/"><a href="https://primary.example/">x</a>' );
ok( false !== strpos( $out, 'href="https://other.example/page/"' ), 'external canonical is not overridden' );

group( 'admin-post.php forms (opt-in)' );
$forms = '<form id="a" action="https://primary.example/wp-admin/admin-post.php" method="post"><input type="hidden" name="action" value="newsletter_signup"></form>'
	. '<form id="b" action="https://primary.example/wp-admin/admin-post.php"><input type="hidden" name="action" value="other_action"></form>'
	. '<form id="c" action="https://primary.example/wp-admin/admin-post.php?action=newsletter_signup&amp;x=1"></form>'
	. '<form id="d" action="https://primary.example/wp-admin/options.php"><input type="hidden" name="action" value="newsletter_signup"></form>'
	. '<form id="e" action="https://primary.example/contact/"><input name="action" value="newsletter_signup"></form>'
	. '<a href="https://primary.example/wp-admin/admin-post.php?action=newsletter_signup">link</a>';
$c   = wpda_components( array(), 'alias-one.example', '/' );
$out = $c->links->rewrite_html( $forms );
same( substr_count( $out, 'https://primary.example/wp-admin/admin-post.php' ), 4, 'default: every admin-post.php form and link stays on primary' );
add_filter( 'wp_domain_alias_admin_post_actions', function ( $a ) { $a[] = 'newsletter_signup'; $a[] = 'Bad Action!'; $a[] = array(); return $a; } );
same( $c->urls->admin_post_actions(), array( 'newsletter_signup' ), 'opt-in list keeps only valid action keys' );
$out = $c->links->rewrite_html( $forms );
ok( false !== strpos( $out, 'id="a" action="https://alias-one.example/wp-admin/admin-post.php"' ), 'opted-in action (hidden field) posts on the alias' );
ok( false !== strpos( $out, 'id="b" action="https://primary.example/wp-admin/admin-post.php"' ), 'other actions stay on primary' );
ok( false !== strpos( $out, 'id="c" action="https://alias-one.example/wp-admin/admin-post.php?action=newsletter_signup' ), 'opted-in action in the query string' );
ok( false !== strpos( $out, 'id="d" action="https://primary.example/wp-admin/options.php"' ), 'other admin endpoints never move, whatever the action field' );
ok( false !== strpos( $out, 'href="https://primary.example/wp-admin/admin-post.php?action=newsletter_signup"' ), 'links to admin-post.php are not moved' );
same( $c->urls->to_alias( 'https://primary.example/wp-admin/admin-post.php' ), 'https://primary.example/wp-admin/admin-post.php', 'to_alias keeps admin-post.php unless explicitly allowed' );
same( $c->urls->to_alias( 'https://primary.example/wp-admin/admin-post.php/../options.php', false, true ), 'https://primary.example/wp-admin/admin-post.php/../options.php', 'admin-post exception is an exact path match' );

group( 'admin-post.php redirects (opt-in)' );
$c = wpda_components( array(), 'alias-one.example', '/wp-admin/admin-post.php' );
$_REQUEST = array( 'action' => 'newsletter_signup' );
same( $c->redirects->wp_redirect( 'https://primary.example/newsletter/?sent=1' ), 'https://primary.example/newsletter/?sent=1', 'not opted in: redirect unchanged' );
add_filter( 'wp_domain_alias_admin_post_actions', function () { return array( 'newsletter_signup' ); } );
same( $c->redirects->wp_redirect( 'https://primary.example/newsletter/?sent=1' ), 'https://alias-one.example/newsletter/?sent=1', 'opted-in action redirects back to the alias' );
same( $c->redirects->wp_redirect( 'https://evil.example/' ), 'https://evil.example/', 'external redirect targets untouched' );
same( $c->redirects->wp_redirect( 'https://primary.example/wp-admin/' ), 'https://primary.example/wp-admin/', 'admin targets stay on primary' );
$_REQUEST = array( 'action' => 'other_action' );
same( $c->redirects->wp_redirect( 'https://primary.example/x/' ), 'https://primary.example/x/', 'other actions unchanged' );
$_REQUEST = array( 'action' => array( 'newsletter_signup' ) );
same( $c->redirects->wp_redirect( 'https://primary.example/x/' ), 'https://primary.example/x/', 'array action ignored' );
wpda_host( $c, 'primary.example' );
$_REQUEST = array( 'action' => 'newsletter_signup' );
same( $c->redirects->wp_redirect( 'https://primary.example/x/' ), 'https://primary.example/x/', 'primary-host request unchanged' );
$_REQUEST = array();
