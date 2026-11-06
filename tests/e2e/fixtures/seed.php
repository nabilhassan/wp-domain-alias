<?php
/**
 * Seeds the local test site. Run with: wp eval-file seed.php
 */

$primary = home_url( '/' );

$about = wp_insert_post( array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'About',
	'post_name'    => 'about',
	'post_content' => '<!-- wp:paragraph --><p>About us. <a id="abs-internal" href="' . esc_url( home_url( '/contact/' ) ) . '?utm_source=test&amp;gclid=abc#form">Absolute internal link</a>, <a id="rel-internal" href="/contact/">relative link</a>, <a id="external" href="https://example.org/page/">external link</a>, <a id="admin-link" href="' . esc_url( admin_url( 'edit.php' ) ) . '">admin link</a>, <a id="login-link" href="' . esc_url( wp_login_url() ) . '">login link</a>, <a id="lookalike" href="https://primary.test.evil.example/">lookalike</a>.</p><!-- /wp:paragraph -->',
) );

$contact = wp_insert_post( array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'Contact',
	'post_name'    => 'contact',
	'post_content' => '<!-- wp:html --><form id="plain-form" method="post" action="' . esc_url( home_url( '/contact/' ) ) . '"><input name="q" value="x"><button type="submit" formaction="' . esc_url( home_url( '/about/' ) ) . '">Go</button></form><div id="clickable" data-column-clickable="' . esc_url( home_url( '/about/' ) ) . '">Clickable column</div><!-- /wp:html -->',
) );

$cat = wp_insert_term( 'News', 'category' );
$cat = is_wp_error( $cat ) ? (int) $cat->get_error_data() : (int) $cat['term_id'];
for ( $i = 1; $i <= 12; $i++ ) {
	wp_insert_post( array(
		'post_status'   => 'publish',
		'post_title'    => "Test post $i",
		'post_name'     => "test-post-$i",
		'post_content'  => "<!-- wp:paragraph --><p>Post $i body.</p><!-- /wp:paragraph -->",
		'post_category' => array( $cat ),
		'post_date'     => gmdate( 'Y-m-d H:i:s', time() - ( 100 - $i ) * HOUR_IN_SECONDS ),
	) );
}

// Remove the default sample content so assertions are predictable.
wp_delete_post( 1, true );
wp_delete_post( 2, true );

update_option( 'wpda_test_ids', array( 'about' => $about, 'contact' => $contact, 'category' => $cat ) );
echo "Seeded about=$about contact=$contact category=$cat\n";
