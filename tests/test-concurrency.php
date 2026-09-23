<?php
/**
 * Stampede protection under real concurrency.
 *
 * Spawns parallel PHP processes that all hit the same cold or expired entry
 * at the same instant. Exactly one may render; every other one must wait and
 * be served that render from the cache.
 *
 * Run: php tests/test-concurrency.php
 *
 * @package CacheArmor
 */

require __DIR__ . '/bootstrap.php';

const CA_WORKERS = 20;
const CA_ROUNDS  = 3;

/**
 * Fire CA_WORKERS workers at one instant and tally their outcomes.
 *
 * @return array{render:int,served:int,other:array}
 */
function ca_burst() {
	$go    = sprintf( '%.4f', microtime( true ) + 4 ); // Time for every process to start.
	$procs = array();
	for ( $i = 0; $i < CA_WORKERS; $i++ ) {
		$cmd     = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/gworker.php' ) . ' ' . $go . ' 2';
		$pipes   = array();
		$procs[] = array( proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes ), $pipes );
	}
	$tally = array( 'render' => 0, 'served' => 0, 'other' => array() );
	foreach ( $procs as list( $proc, $pipes ) ) {
		$out = trim( stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] ) );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );
		if ( 'RENDER' === $out ) {
			$tally['render']++;
		} elseif ( 0 === strpos( $out, 'SERVED:HIT' ) || 0 === strpos( $out, 'SERVED:STALE' ) ) {
			$tally['served']++;
		} else {
			$tally['other'][] = $out;
		}
	}
	return $tally;
}

section( 'Cold cache: ' . CA_WORKERS . ' simultaneous requests, ' . CA_ROUNDS . ' rounds' );
for ( $round = 1; $round <= CA_ROUNDS; $round++ ) {
	ca_wipe();
	$t = ca_burst();
	check( "round $round: exactly one render", 1 === $t['render'] && CA_WORKERS - 1 === $t['served'], "render={$t['render']} served={$t['served']}" . ( $t['other'] ? ' other=' . implode( ' | ', $t['other'] ) : '' ) );
}

section( 'Expired entry (past lifetime and grace): ' . CA_WORKERS . ' simultaneous requests' );
for ( $round = 1; $round <= CA_ROUNDS; $round++ ) {
	ca_wipe();
	ca_rule( '/wp/v2/pages', array( 'categories' => 467 ), 60 );
	ca_dispatch( '/wp-json/wp/v2/pages?categories=467', '/wp/v2/pages', array( 'categories' => '467' ) );
	$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/pages?categories=467';
	touch( CacheArmor::cache_file(), time() - 5000 );
	$t = ca_burst();
	check( "round $round: exactly one render", 1 === $t['render'] && CA_WORKERS - 1 === $t['served'], "render={$t['render']} served={$t['served']}" . ( $t['other'] ? ' other=' . implode( ' | ', $t['other'] ) : '' ) );
}

finish();
