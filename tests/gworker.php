<?php
/**
 * One concurrent request, spawned by test-concurrency.php.
 *
 * Arguments: the start time to fire at (a barrier, so every worker hits the
 * lock at the same instant) and the render time in seconds. Prints RENDER if
 * this worker produced the response, or SERVED:<status> if it was answered
 * from the cache.
 *
 * @package CacheArmor
 */

require __DIR__ . '/bootstrap.php';

$go     = isset( $argv[1] ) ? (float) $argv[1] : 0.0;
$render = isset( $argv[2] ) ? (float) $argv[2] : 2.0;

ca_rule( '/wp/v2/pages', array( 'categories' => 467 ), 60 );
ca_config( array( 'cold_wait' => 15 ) );
$_SERVER['HTTP_HOST'] = 'example.test';

// Sleep until just before the barrier, then spin for precision.
while ( ( $left = $go - microtime( true ) ) > 0.02 ) {
	usleep( (int) ( ( $left - 0.01 ) * 1000000 ) );
}
while ( microtime( true ) < $go ) {
	// Spin.
}

$rendered = false;
$response = ca_dispatch(
	'/wp-json/wp/v2/pages?categories=467',
	'/wp/v2/pages',
	array( 'categories' => '467' ),
	function () use ( $render, &$rendered ) {
		$rendered = true;
		usleep( (int) ( $render * 1000000 ) ); // An expensive render.
		return ca_page();
	}
);

echo $rendered ? "RENDER\n" : 'SERVED:' . ca_status( $response ) . "\n";
