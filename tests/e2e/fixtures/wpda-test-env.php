<?php
/**
 * Test environment only — never shipped in the plugin ZIP.
 *
 * The local test hosts (primary.test, alias-one.test, ...) do not exist in
 * DNS. This
 * - tells the health check they resolve to 127.0.0.1 and may be requested
 *   although that address is internal (core's own allowance filter), and
 * - routes WordPress HTTP API requests for them to the local PHP server.
 * Hosts not listed keep WordPress's normal behaviour, including real DNS and
 * the unsafe-URL block.
 *
 * Scenario hosts (handled by tests/e2e/router.php):
 *   slow.test           answers after 8 seconds (longer than the 5 s timeout)
 *   redirect-away.test  301 to another domain
 *   badtls.test         HTTPS with an untrusted self-signed certificate
 *                       (a test server on WPDA_PORT + 1000)
 */

function wpda_test_hosts() {
	return array( 'primary.test', 'www.primary.test', 'alias-one.test', 'alias-two.test', 'www.alias-two.test', 'slow.test', 'redirect-away.test', 'badtls.test' );
}

add_filter(
	'wp_domain_alias_health_check_ips',
	static function ( $ips, $host ) {
		return in_array( $host, wpda_test_hosts(), true ) ? array( '127.0.0.1' ) : $ips;
	},
	10,
	2
);

add_filter(
	'http_request_host_is_external',
	static function ( $external, $host ) {
		return $external || in_array( strtolower( (string) $host ), wpda_test_hosts(), true );
	},
	10,
	2
);

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		static $inside = false;
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( $inside || false !== $pre || ! in_array( $host, wpda_test_hosts(), true ) ) {
			return $pre;
		}
		$port   = getenv( 'WPDA_PORT' ) ? (int) getenv( 'WPDA_PORT' ) : 8890;
		$target = 'badtls.test' === $host
			? preg_replace( '~^https?://[^/]+~i', 'https://127.0.0.1:' . ( $port + 1000 ), $url )
			: preg_replace( '~^https?://[^/]+~i', 'http://127.0.0.1:' . $port, $url );
		$args['headers']            = array_merge( (array) $args['headers'], array( 'Host' => $host ) );
		$args['reject_unsafe_urls'] = false;
		$inside                     = true;
		$response                   = wp_remote_request( $target, $args );
		$inside                     = false;
		return $response;
	},
	10,
	3
);
