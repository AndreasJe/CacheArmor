<?php
/**
 * Core behaviour: hits, misses, bypasses, staleness and purging.
 *
 * Run: php tests/test-core.php
 *
 * @package CacheArmor
 */

require __DIR__ . '/bootstrap.php';

$uri    = '/wp-json/wp/v2/pages?categories=467&per_page=100';
$params = array( 'categories' => '467' );

section( '1. Nothing is cached without a rule' );
ca_dispatch( $uri, '/wp/v2/pages', $params );
$r = ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'no cache header', '(none)' === ca_status( $r ), ca_status( $r ) );
check( 'no entries written', 0 === ca_count() );

section( '2. A rule turns caching on' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
$first  = ca_dispatch( $uri, '/wp/v2/pages', $params );
$second = ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'first request is a MISS', 'MISS' === ca_status( $first ), ca_status( $first ) );
check( 'second request is a HIT', 'HIT' === ca_status( $second ), ca_status( $second ) );
check( 'one entry on disk', 1 === ca_count(), ca_count() );
check( 'HIT returns the same data', $first->get_data() === $second->get_data() );

section( '3. Pagination headers survive the cache' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_dispatch( $uri, '/wp/v2/pages', $params );
$h = ca_dispatch( $uri, '/wp/v2/pages', $params )->get_headers();
check( 'X-WP-Total preserved', isset( $h['X-WP-Total'] ) && 27 === (int) $h['X-WP-Total'] );
check( 'age header present', isset( $h['X-CacheArmor-Age'] ) );

section( '4. The cache key survives parameter mutation during dispatch' );
$_SERVER['REQUEST_URI'] = $uri;
$before                 = CacheArmor::cache_file();
$request                = new WP_REST_Request( 'GET', '/wp/v2/pages', array( 'categories' => '467' ) );
$request->sanitize_params();
check( 'same file before and after', CacheArmor::cache_file() === $before );

section( '5. Requests that do not match a rule are left alone' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
foreach ( array(
	'other category' => array( '/wp-json/wp/v2/pages?categories=999', '/wp/v2/pages', array( 'categories' => '999' ) ),
	'other route'    => array( '/wp-json/wp/v2/posts?categories=467', '/wp/v2/posts', array( 'categories' => '467' ) ),
	'no parameter'   => array( '/wp-json/wp/v2/pages', '/wp/v2/pages', array() ),
) as $label => $case ) {
	$_SERVER['REQUEST_URI'] = $case[0];
	$result                 = apply_filters( 'rest_pre_dispatch', null, null, new WP_REST_Request( 'GET', $case[1], $case[2] ) );
	check( "$label is not short-circuited", null === $result );
}

section( '6. Only GET is cached' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_dispatch( $uri, '/wp/v2/pages', $params, 'ca_page', 'POST' );
check( 'POST stores nothing', 0 === ca_count() );

section( '7. Logged-in users bypass the cache' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
$GLOBALS['ca_logged_in'] = true;
$r                       = ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'no cache header', '(none)' === ca_status( $r ) );
check( 'nothing stored', 0 === ca_count() );

section( '8. Pausing from the settings screen stops caching' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
$GLOBALS['ca_options'][ CacheArmor::OPTION ] = array( 'paused' => true );
$r = ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'no cache header while paused', '(none)' === ca_status( $r ) );
check( 'nothing stored while paused', 0 === ca_count() );

section( '9. Errors are never stored' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_dispatch( $uri, '/wp/v2/pages', $params, function () { return new WP_REST_Response( array( 'code' => 'oops' ), 500 ); } );
check( 'a 500 stores nothing', 0 === ca_count() );

section( '10. Publishing content purges' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_dispatch( $uri, '/wp/v2/pages', $params );
do_action( 'transition_post_status', 'publish', 'publish', (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello' ) );
check( 'updating a published post purges', 0 === ca_count() );

section( '11. cachearmor_should_purge can veto a purge' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
add_filter( 'cachearmor_should_purge', function () { return false; } );
ca_dispatch( $uri, '/wp/v2/pages', $params );
do_action( 'transition_post_status', 'publish', 'publish', (object) array( 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello' ) );
check( 'entry survives', 1 === ca_count() );

section( '12. The cache lives in uploads/cachearmor, locked down, with no PHP' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'stored under the uploads directory in a folder named after the slug', CA_UPLOADS . '/cachearmor' === CacheArmor::dir() && 1 === ca_count() );
check( '.htaccess written', is_file( ca_dir() . '/.htaccess' ) );
check( 'index.html written', is_file( ca_dir() . '/index.html' ) );
// A deny rule in the uploads root would block every media file on the site.
check( 'uploads root left untouched', ! file_exists( CA_UPLOADS . '/.htaccess' ) && ! file_exists( CA_UPLOADS . '/index.html' ) );
$php = array();
foreach ( glob( ca_dir() . '/*' ) ?: array() as $file ) {
	if ( is_file( $file ) && ( '.php' === substr( $file, -4 ) || false !== strpos( (string) file_get_contents( $file ), '<?' ) ) ) {
		$php[] = basename( $file );
	}
}
check( 'no file contains PHP', empty( $php ), implode( ', ', $php ) );
$entries = glob( ca_dir() . '/*.json' );
check( 'entry is plain JSON', $entries && '{' === substr( (string) file_get_contents( $entries[0] ), 0, 1 ) );

section( '13. A corrupt entry is replaced, not served' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_dispatch( $uri, '/wp/v2/pages', $params );
$entries = glob( ca_dir() . '/*.json' );
file_put_contents( $entries[0], 'garbage' );
$r = ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'served fresh', 'MISS' === ca_status( $r ), ca_status( $r ) );
check( 'rewritten as valid JSON', is_array( json_decode( (string) file_get_contents( $entries[0] ), true ) ) );

section( '14. Stale entries: one request refreshes, others get the stale copy' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ), 60 );
ca_dispatch( $uri, '/wp/v2/pages', $params );
$_SERVER['REQUEST_URI'] = $uri;
$file                   = CacheArmor::cache_file();
touch( $file, time() - 100 ); // Past the 60 s lifetime, within the grace period.
touch( CacheArmor::lock_file() ); // Another request is already refreshing it.
$r = ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'lock held elsewhere: STALE served', 'STALE' === ca_status( $r ), ca_status( $r ) );
unlink( CacheArmor::lock_file() );
touch( $file, time() - 100 );
$r = ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'lock free: this request refreshes (MISS)', 'MISS' === ca_status( $r ), ca_status( $r ) );
check( 'refreshed entry is fresh', filemtime( $file ) >= time() - 2 );
check( 'lock released afterwards', ! file_exists( CacheArmor::lock_file() ) );

section( '15. With send_headers off, a HIT does not rewrite the entry' );
// Previously a HIT was recognised only by its header, so with headers off
// every HIT was stored again with a new mtime and the entry never expired.
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ), 60 );
ca_config( array( 'send_headers' => false ) );
ca_dispatch( $uri, '/wp/v2/pages', $params );
$_SERVER['REQUEST_URI'] = $uri;
$file                   = CacheArmor::cache_file();
touch( $file, time() - 30 );
ca_dispatch( $uri, '/wp/v2/pages', $params );
clearstatcache();
check( 'mtime unchanged by the HIT', abs( filemtime( $file ) - ( time() - 30 ) ) <= 1, ( time() - filemtime( $file ) ) . ' s old' );

finish();
