<?php
/**
 * Test-only MU plugin (copied in by the browser tests, never shipped): a
 * typical theme/plugin form that posts to admin-post.php.
 *
 * Shortcode [wpda_admin_post_form] prints the form; the handler checks the
 * nonce, sets a cookie and redirects back to the referring page the usual
 * way (wp_get_referer() + wp_safe_redirect()).
 * The option wpda_test_admin_post_optin = 1 opts the action in through
 * WP Domain Alias's wp_domain_alias_admin_post_actions filter.
 */
add_shortcode(
	'wpda_admin_post_form',
	static function () {
		ob_start();
		echo '<form id="ap-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpda_test_form' );
		echo '<input type="hidden" name="action" value="wpda_test_form"><input type="text" name="email" value="visitor@example.org"><button type="submit">Subscribe</button></form>';
		return ob_get_clean();
	}
);
$wpda_test_form_handler = static function () {
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wpda_test_form' ) ) {
		wp_die( 'bad nonce', '', array( 'response' => 403 ) );
	}
	setcookie( 'wpda_form_sent', '1', 0, COOKIEPATH, COOKIE_DOMAIN );
	$back = wp_get_referer();
	wp_safe_redirect( add_query_arg( 'sent', '1', $back ? $back : home_url( '/' ) ) );
	exit;
};
add_action( 'admin_post_nopriv_wpda_test_form', $wpda_test_form_handler );
add_action( 'admin_post_wpda_test_form', $wpda_test_form_handler );
if ( get_option( 'wpda_test_admin_post_optin' ) ) {
	add_filter(
		'wp_domain_alias_admin_post_actions',
		static function ( $actions ) {
			$actions[] = 'wpda_test_form';
			return $actions;
		}
	);
}
