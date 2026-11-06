<?php
/**
 * Router for PHP's built-in server: serve static files, otherwise WordPress.
 */
// Health-check scenario hosts (see fixtures/wpda-test-env.php).
$scenario_host = strtolower( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' );
if ( 'slow.test' === $scenario_host ) {
	sleep( 8 );
	echo 'slow';
	return;
}
if ( 'redirect-away.test' === $scenario_host ) {
	header( 'Location: https://elsewhere.example/landing/', true, 301 );
	return;
}

$root = $_SERVER['DOCUMENT_ROOT'];
$path = urldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
$file = realpath( $root . $path );

if ( false !== $file && is_dir( $file ) && is_file( $file . '/index.php' ) ) {
	$file = $file . '/index.php';
	$path = rtrim( $path, '/' ) . '/index.php';
}
if ( false !== $file && is_file( $file ) ) {
	if ( '.php' !== substr( $file, -4 ) ) {
		return false;
	}
	$_SERVER['SCRIPT_NAME']     = $path;
	$_SERVER['PHP_SELF']        = $path;
	$_SERVER['SCRIPT_FILENAME'] = $file;
	chdir( dirname( $file ) );
	require $file;
	return;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
chdir( $root );
require $root . '/index.php';
