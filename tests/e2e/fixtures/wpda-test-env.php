<?php
/**
 * Test environment only — never shipped in the plugin ZIP.
 *
 * The local test hosts (primary.test, alias-one.test, ...) do not exist in
 * DNS. This routes WordPress HTTP API requests for those hosts to the local
 * PHP server so the health check can be exercised end to end. Hosts not in
 * the list keep WordPress's normal behaviour, including the unsafe-URL block.
 */

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		static $inside = false;
		$hosts = array( 'primary.test', 'www.primary.test', 'alias-one.test', 'alias-two.test', 'www.alias-two.test' );
		$host  = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( $inside || false !== $pre || ! in_array( $host, $hosts, true ) ) {
			return $pre;
		}
		$port   = getenv( 'WPDA_PORT' ) ? getenv( 'WPDA_PORT' ) : '8890';
		$target = preg_replace( '~^https?://[^/]+~i', 'http://127.0.0.1:' . $port, $url );
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
