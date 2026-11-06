<?php
/**
 * Test-only (wp eval-file): proves the health check pins its request to the
 * address it validated. Validation is told example.com resolves to 127.0.0.1
 * (and core's filter allows that address), while cURL would resolve the real
 * public address. A pinned request goes to 127.0.0.1:80 (closed) and fails;
 * the unpinned control request to the real address answers.
 */
$filter_ips = static function ( $ips, $host ) {
	return 'example.com' === $host ? array( '127.0.0.1' ) : $ips;
};
$filter_ext = static function ( $external, $host ) {
	return $external || 'example.com' === $host;
};
add_filter( 'wp_domain_alias_health_check_ips', $filter_ips, 99, 2 );
add_filter( 'http_request_host_is_external', $filter_ext, 99, 2 );
$debug = '';
add_action(
	'http_api_debug',
	static function ( $response ) use ( &$debug ) {
		if ( is_wp_error( $response ) ) {
			$debug = $response->get_error_message();
		}
	}
);

$saved = get_option( 'wp_domain_alias_settings' );
update_option( 'wp_domain_alias_settings', array_merge( (array) $saved, array( 'aliases' => array( 'example.com' ) ) ) );
delete_transient( 'wpda_hc_lock_0' );
$health = new WP_Domain_Alias\Health_Check( new WP_Domain_Alias\Domain_Manager() );
$run    = $health->start_run( 0 );
while ( is_array( $run ) && ! WP_Domain_Alias\Health_Check::is_complete( $run ) ) {
	$run = $health->run_step( 0, 3 );
}
$status = '';
foreach ( $run['results'] as $result ) {
	if ( 'example.com' === $result['host'] ) {
		$status = $result['checks'][0]['status'];
	}
}
update_option( 'wp_domain_alias_settings', $saved );
delete_transient( 'wpda_health_0' );
delete_transient( 'wpda_hc_lock_0' );

remove_filter( 'wp_domain_alias_health_check_ips', $filter_ips, 99 );
$control = wp_remote_get( 'http://example.com/', array( 'timeout' => 10, 'redirection' => 0 ) );

echo wp_json_encode(
	array(
		'pinned'   => array( 'status' => $status, 'debug' => $debug ),
		'unpinned' => array( 'code' => is_wp_error( $control ) ? $control->get_error_message() : wp_remote_retrieve_response_code( $control ) ),
	)
);
