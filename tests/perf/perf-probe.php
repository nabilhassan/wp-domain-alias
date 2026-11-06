<?php
/**
 * Test-only MU plugin used by tests/perf/bench.mjs. Never shipped.
 *
 * Opens the outermost output buffer, so its callback runs after every inner
 * buffer (including WP Domain Alias's HTML pass) has finished, then records
 * the request's peak memory and wall time to a log file.
 */
$GLOBALS['wpda_perf_start'] = microtime( true );
ob_start(
	static function ( $buffer, $phase ) {
		if ( $phase & PHP_OUTPUT_HANDLER_FINAL ) {
			$line = sprintf(
				"%s\t%s\t%d\t%.2f\n",
				isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '',
				isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '',
				memory_get_peak_usage(),
				( microtime( true ) - $GLOBALS['wpda_perf_start'] ) * 1000
			);
			file_put_contents( WP_CONTENT_DIR . '/wpda-perf.log', $line, FILE_APPEND | LOCK_EX );
		}
		return $buffer;
	}
);
