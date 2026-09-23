<?php
/**
 * Isolation, session safety, size limits, purge filtering and locking.
 *
 * Each section guards against a specific defect found in review.
 *
 * Run: php tests/test-hardening.php
 *
 * @package CacheArmor
 */

require __DIR__ . '/bootstrap.php';

$uri    = '/wp-json/wp/v2/pages?categories=467';
$params = array( 'categories' => '467' );

function ca_other_site() {
	return new WP_REST_Response( array( array( 'id' => 1, 'site' => 'two' ) ), 200 );
}

section( 'A. Multisite: sites never share entries' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
$one                   = ca_dispatch( $uri, '/wp/v2/pages', $params );
$GLOBALS['ca_blog_id'] = 2;
$two                   = ca_dispatch( $uri, '/wp/v2/pages', $params, 'ca_other_site' );
check( 'site 2 is a MISS, not site 1\'s HIT', 'MISS' === ca_status( $two ), ca_status( $two ) );
check( 'site 2 gets its own data', $one->get_data() !== $two->get_data() );
check( 'separate directories', 1 === ca_count( 1 ) && 1 === ca_count( 2 ) );
CacheArmor::purge_all();
check( 'purging site 2 leaves site 1 alone', 1 === ca_count( 1 ) && 0 === ca_count( 2 ) );
check( 'each site uses its own uploads directory', CA_UPLOADS . '/sites/2/cachearmor' === CacheArmor::dir() );

section( 'A2. A custom cache directory still keeps sites apart' );
$custom = WP_CONTENT_DIR . '/custom-cache';
ca_config( array( 'dir' => $custom ) );
check( 'site 1 gets its own subdirectory', $custom . '/1' === CacheArmor::dir() );
$GLOBALS['ca_blog_id'] = 2;
check( 'site 2 gets its own subdirectory', $custom . '/2' === CacheArmor::dir() );

section( 'B. One site on two domains keeps them apart' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
$_SERVER['HTTP_HOST'] = 'en.example.test';
ca_dispatch( $uri, '/wp/v2/pages', $params );
$_SERVER['HTTP_HOST'] = 'de.example.test';
$r                    = ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'second domain is a MISS', 'MISS' === ca_status( $r ), ca_status( $r ) );

section( 'C. WooCommerce cart and checkout can never be cached' );
foreach ( array( '/wc/store/v1/cart', '/wc/store/v1/cart/items', '/wc/store/cart', '/wc/store/v1/checkout', '/wc/store/v1/batch' ) as $route ) {
	check( "$route is blocked", CacheArmor::is_blocked( $route ) );
}
check( '/wp/v2/users/me is blocked', CacheArmor::is_blocked( '/wp/v2/users/me' ) );
check( '/wc/store/v1/products is not', ! CacheArmor::is_blocked( '/wc/store/v1/products' ) );
check( 'a lookalike prefix is not', ! CacheArmor::is_blocked( '/wc/store/v1/cartography' ) );
ca_rule( '/wc/store/v1/cart' ); // A developer adding it in code is still refused.
ca_dispatch( '/wp-json/wc/store/v1/cart', '/wc/store/v1/cart' );
$r = ca_dispatch( '/wp-json/wc/store/v1/cart', '/wc/store/v1/cart' );
check( 'cart rule is ignored', '(none)' === ca_status( $r ) && 0 === ca_count() );
add_filter( 'cachearmor_blocked_routes', function ( $b ) { $b[] = '/myplugin/v1/basket'; return $b; } );
check( 'blocklist is extensible', CacheArmor::is_blocked( '/myplugin/v1/basket' ) );

section( 'D. Requests carrying a session are never served from or stored in the cache' );
foreach ( array(
	'Cart-Token header' => function () { $_SERVER['HTTP_CART_TOKEN'] = 'abc'; },
	'Nonce header'      => function () { $_SERVER['HTTP_NONCE'] = 'abc'; },
	'Woo session cookie' => function () { $_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] = '1'; },
	'Woo cart cookie'    => function () { $_COOKIE['woocommerce_items_in_cart'] = '1'; },
	'bypass filter'     => function () { add_filter( 'cachearmor_bypass_request', '__return_true_ca' ); },
) as $label => $setup ) {
	ca_reset();
	ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
	ca_dispatch( $uri, '/wp/v2/pages', $params ); // Prime as an anonymous visitor.
	$setup();
	$r = ca_dispatch( $uri, '/wp/v2/pages', $params );
	check( "$label: not served from cache", '(none)' === ca_status( $r ), ca_status( $r ) );
}
function __return_true_ca() { return true; }

section( 'E. X-WP-Nonce alone does not bypass' );
// WordPress sends it on anonymous front-end requests, which should still hit.
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
$_SERVER['HTTP_X_WP_NONCE'] = 'abc';
ca_dispatch( $uri, '/wp/v2/pages', $params );
$r = ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'still a HIT', 'HIT' === ca_status( $r ), ca_status( $r ) );

section( 'F. Made-up query parameters cannot fill the disk' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_config( array( 'max_entries' => 5 ) );
for ( $i = 1; $i <= 25; $i++ ) {
	ca_dispatch( "/wp-json/wp/v2/pages?categories=467&junk=$i", '/wp/v2/pages', array( 'categories' => '467', 'junk' => "$i" ) );
}
check( '25 junk requests store at most 5', 5 === ca_count(), ca_count() );
check( 'stats report the cache as full', CacheArmor_Admin_stats_full() );
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/pages?categories=467&junk=1';
touch( CacheArmor::cache_file(), time() - 5000 );
ca_dispatch( '/wp-json/wp/v2/pages?categories=467&junk=1', '/wp/v2/pages', array( 'categories' => '467', 'junk' => '1' ) );
check( 'an existing entry is still refreshed when full', filemtime( CacheArmor::cache_file() ) >= time() - 2 );

section( 'G. Total size is capped too' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_config( array( 'max_total_bytes' => 100000 ) ); // Each entry is roughly 41 KB.
for ( $i = 1; $i <= 10; $i++ ) {
	ca_dispatch( "/wp-json/wp/v2/pages?categories=467&v=$i", '/wp/v2/pages', array( 'categories' => '467', 'v' => "$i" ) );
}
$bytes = CacheArmor::bytes_of( CacheArmor::entries() );
check( 'stays under max_total_bytes', $bytes <= 100000, "$bytes bytes in " . ca_count() . ' entries' );

section( 'H. One logical request is one entry' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_dispatch( '/wp-json/wp/v2/pages?categories=467&per_page=10', '/wp/v2/pages', $params );
$r = ca_dispatch( '/wp-json/wp/v2/pages?per_page=10&categories=467', '/wp/v2/pages', $params );
check( 'parameter order does not matter', 'HIT' === ca_status( $r ) && 1 === ca_count(), ca_status( $r ) );
ca_dispatch( '/wp-json/wp/v2/pages?categories=467&_=1695300000001', '/wp/v2/pages', $params );
$r = ca_dispatch( '/wp-json/wp/v2/pages?categories=467&_=1695300000002', '/wp/v2/pages', $params );
check( 'jQuery "_" cache buster is ignored', 'HIT' === ca_status( $r ), ca_status( $r ) );

section( 'I. Only changes anonymous visitors can see purge the cache' );
$post = function ( $type, $status, $title = 'Hello' ) {
	return (object) array( 'ID' => 9, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title );
};
$cases = array(
	'auto-draft created (Add New)'   => array( 'transition_post_status', array( 'auto-draft', 'new', $post( 'post', 'auto-draft' ) ), false ),
	'draft saved'                    => array( 'transition_post_status', array( 'draft', 'draft', $post( 'post', 'draft' ) ), false ),
	'oEmbed cache written'           => array( 'transition_post_status', array( 'publish', 'publish', $post( 'oembed_cache', 'publish' ) ), false ),
	'WooCommerce order saved'        => array( 'transition_post_status', array( 'publish', 'publish', $post( 'shop_order', 'publish' ) ), false ),
	'post published'                 => array( 'transition_post_status', array( 'publish', 'draft', $post( 'post', 'publish' ) ), true ),
	'published post updated'         => array( 'transition_post_status', array( 'publish', 'publish', $post( 'page', 'publish' ) ), true ),
	'post unpublished'               => array( 'transition_post_status', array( 'draft', 'publish', $post( 'post', 'draft' ) ), true ),
	'published post deleted'         => array( 'deleted_post', array( 9, $post( 'post', 'publish' ) ), true ),
	'draft deleted'                  => array( 'deleted_post', array( 9, $post( 'post', 'draft' ) ), false ),
	'category term edited'           => array( 'edited_term', array( 3, 3, 'category' ), true ),
	'non-REST taxonomy term edited'  => array( 'edited_term', array( 3, 3, 'product_visibility' ), false ),
);
foreach ( $cases as $label => $case ) {
	ca_reset();
	ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
	ca_dispatch( $uri, '/wp/v2/pages', $params );
	do_action( $case[0], ...$case[1] );
	$purged = 0 === ca_count();
	check( $label . ( $case[2] ? ' purges' : ' does not purge' ), $case[2] === $purged );
}

section( 'J. The last purge is recorded for the status line' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_dispatch( $uri, '/wp/v2/pages', $params );
do_action( 'transition_post_status', 'publish', 'draft', $post( 'post', 'publish', 'Launch day' ) );
$status = get_option( CacheArmor::STATUS_OPTION );
check( 'reason recorded', is_array( $status ) && 'post' === $status['reason'] );
check( 'title recorded', is_array( $status ) && 'Launch day' === $status['label'] );
check( 'count recorded', is_array( $status ) && 1 === $status['removed'] );

section( 'K. The lock admits exactly one holder' );
$_SERVER['REQUEST_URI'] = $uri;
CacheArmor::prepare_dir();
check( 'first take succeeds', true === CacheArmor::take_lock() );
check( 'second take fails', false === CacheArmor::take_lock() );
CacheArmor::release_lock();
check( 'holder releases it', ! file_exists( CacheArmor::lock_file() ) );
touch( CacheArmor::lock_file() ); // Held by some other request.
CacheArmor::release_lock();
check( 'a non-holder cannot release it', file_exists( CacheArmor::lock_file() ) );
touch( CacheArmor::lock_file(), time() - 500 ); // Abandoned by a request that died.
check( 'an abandoned lock is reclaimed', true === CacheArmor::take_lock() );
CacheArmor::release_lock();

section( 'L. An expired entry is not served while another request rebuilds it' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ), 60 );
ca_dispatch( $uri, '/wp/v2/pages', $params );
$_SERVER['REQUEST_URI'] = $uri;
touch( CacheArmor::cache_file(), time() - 5000 ); // Past lifetime and grace.
touch( CacheArmor::lock_file() );
$r = ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'expired copy not served', ! in_array( ca_status( $r ), array( 'HIT', 'STALE' ), true ), ca_status( $r ) );
unlink( CacheArmor::lock_file() );

section( 'M. A waiting request stops as soon as the rebuilding request gives up' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_config( array( 'cold_wait' => 5, 'lock_ttl' => 1 ) );
$_SERVER['REQUEST_URI'] = $uri;
CacheArmor::prepare_dir();
touch( CacheArmor::lock_file() ); // Held, and will count as abandoned after 1 s.
$start = microtime( true );
$r     = ca_dispatch( $uri, '/wp/v2/pages', $params );
$took  = microtime( true ) - $start;
check( 'rendered itself', 'MISS' === ca_status( $r ), ca_status( $r ) );
check( 'did not sit out the full 5 s', $took < 3.5, sprintf( '%.1f s', $took ) );
wp_delete_file( CacheArmor::lock_file() );

section( 'N. Orphaned temp files are cleaned up' );
$_SERVER['REQUEST_URI'] = $uri;
CacheArmor::prepare_dir();
$old   = ca_dir() . '/' . md5( 'x' ) . '.json.1.tmp';
$fresh = ca_dir() . '/' . md5( 'y' ) . '.json.2.tmp';
file_put_contents( $old, '{}' );
file_put_contents( $fresh, '{}' );
touch( $old, time() - 600 );
CacheArmor::purge_all();
check( 'old temp file removed', ! file_exists( $old ) );
check( 'in-flight temp file kept', file_exists( $fresh ) );
unlink( $fresh );

section( 'P. Replacing an entry that is being read never emits a warning' );
// On Windows, rename() fails while another process has the target open. A
// warning there would print into the JSON response when WP_DEBUG_DISPLAY is on.
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ), 60 );
ca_dispatch( $uri, '/wp/v2/pages', $params );
$_SERVER['REQUEST_URI'] = $uri;
$file                   = CacheArmor::cache_file();
touch( $file, time() - 100 ); // Stale, so the next request refreshes it.
$reader   = fopen( $file, 'r' ); // A concurrent reader.
$warnings = array();
set_error_handler(
	function ( $errno, $message ) use ( &$warnings ) {
		if ( error_reporting() & $errno ) { // Only what would actually be shown.
			$warnings[] = $message;
		}
		return true;
	}
);
$r = ca_dispatch( $uri, '/wp/v2/pages', $params );
restore_error_handler();
fclose( $reader );
check( 'no warning surfaced', empty( $warnings ), implode( ' | ', $warnings ) );
check( 'no temp file left behind', empty( glob( ca_dir() . '/*.tmp' ) ) );
check( 'entry is still valid JSON', is_array( json_decode( (string) file_get_contents( $file ), true ) ) );

section( 'O. Uninstall removes options and every cache file, and nothing else' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
ca_dispatch( $uri, '/wp/v2/pages', $params );
$GLOBALS['ca_blog_id'] = 2;
ca_dispatch( $uri, '/wp/v2/pages', $params );
$GLOBALS['ca_blog_id'] = 1;
$GLOBALS['ca_options'][ CacheArmor::OPTION ]        = array( 'routes' => array() );
$GLOBALS['ca_options'][ CacheArmor::STATUS_OPTION ] = array( 'time' => time() );
file_put_contents( ca_dir( 2 ) . '/unrelated.txt', 'not ours' );
file_put_contents( CA_UPLOADS . '/photo.jpg', 'media' );
function is_multisite() { return true; }
function get_sites( $args ) { return array( 1, 2 ); }
function switch_to_blog( $id ) { $GLOBALS['ca_blog_id'] = $id; }
function restore_current_blog() { $GLOBALS['ca_blog_id'] = 1; }
define( 'WP_UNINSTALL_PLUGIN', 'cachearmor/cachearmor.php' );
require dirname( __DIR__ ) . '/uninstall.php';
check( 'options deleted', ! isset( $GLOBALS['ca_options'][ CacheArmor::OPTION ] ) && ! isset( $GLOBALS['ca_options'][ CacheArmor::STATUS_OPTION ] ) );
check( 'site 1 cache directory removed', ! is_dir( ca_dir( 1 ) ) );
check( 'site 2 cache emptied of its own files', 0 === ca_count( 2 ) && ! file_exists( ca_dir( 2 ) . '/.htaccess' ) );
check( 'a file it did not create is left alone', is_file( ca_dir( 2 ) . '/unrelated.txt' ) );
check( 'media in the uploads root is untouched', is_file( CA_UPLOADS . '/photo.jpg' ) );
unlink( ca_dir( 2 ) . '/unrelated.txt' );
unlink( CA_UPLOADS . '/photo.jpg' );

finish();

/**
 * Admin stats helper, loading the admin class on first use.
 *
 * @return bool
 */
function CacheArmor_Admin_stats_full() {
	if ( ! class_exists( 'CacheArmor_Admin' ) ) {
		require_once dirname( __DIR__ ) . '/admin.php';
	}
	$stats = CacheArmor_Admin::stats();
	return $stats['full'];
}
