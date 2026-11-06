<?php
/**
 * Runs every tests/unit/test-*.php file. Exits non-zero on any failure.
 *
 * Usage: php tests/unit/run.php
 */
require __DIR__ . '/bootstrap.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}

$fails = $GLOBALS['wpda_fail'];
$total = $GLOBALS['wpda_pass'] + count( $fails );
echo "\n" . $GLOBALS['wpda_pass'] . "/$total assertions passed\n";
exit( $fails ? 1 : 0 );
