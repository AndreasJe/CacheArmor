<?php
/**
 * Settings screen: saved rules, route discovery, statistics and rendering.
 *
 * Run: php tests/test-admin.php
 *
 * @package CacheArmor
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/admin.php';

$uri    = '/wp-json/wp/v2/pages?categories=467&per_page=100';
$params = array( 'categories' => '467' );

function ca_settings( array $routes, $ttl = 900 ) {
	$GLOBALS['ca_options'][ CacheArmor::OPTION ] = array(
		'routes' => $routes,
		'ttl'    => $ttl,
	);
}

section( 'A. No routes enabled: nothing cached' );
ca_settings( array() );
ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'nothing stored', 0 === ca_count() );

section( 'B. A route enabled in settings is cached' );
ca_settings( array( '/wp/v2/pages' => array( 'enabled' => 1, 'ttl' => 600 ) ) );
$cold = ca_dispatch( $uri, '/wp/v2/pages', $params );
$warm = ca_dispatch( $uri, '/wp/v2/pages', $params );
check( 'MISS then HIT', 'MISS' === ca_status( $cold ) && 'HIT' === ca_status( $warm ), ca_status( $cold ) . ' / ' . ca_status( $warm ) );

section( 'C. A query filter narrows what is cached' );
ca_settings( array( '/wp/v2/pages' => array( 'enabled' => 1, 'query' => 'categories=467' ) ) );
ca_dispatch( $uri, '/wp/v2/pages', $params );
ca_dispatch( '/wp-json/wp/v2/pages?categories=999', '/wp/v2/pages', array( 'categories' => '999' ) );
check( 'only the matching request is stored', 1 === ca_count(), ca_count() );

section( 'D. A blocked route stored in the option is still refused' );
ca_settings( array( '/wp/v2/users' => array( 'enabled' => 1 ) ) );
ca_dispatch( '/wp-json/wp/v2/users', '/wp/v2/users' );
ca_dispatch( '/wp-json/wp/v2/users', '/wp/v2/users' );
check( 'nothing stored', 0 === ca_count() );

section( 'E. Custom endpoints are supported' );
ca_settings( array( '/myplugin/v1/things' => array( 'enabled' => 1 ) ) );
ca_dispatch( '/wp-json/myplugin/v1/things?x=1', '/myplugin/v1/things', array( 'x' => '1' ) );
$r = ca_dispatch( '/wp-json/myplugin/v1/things?x=1', '/myplugin/v1/things', array( 'x' => '1' ) );
check( 'HIT', 'HIT' === ca_status( $r ), ca_status( $r ) );

section( 'F. Settings rules and filter rules combine' );
ca_settings( array( '/wp/v2/pages' => array( 'enabled' => 1 ) ) );
ca_rule( '/other/v1/x' );
check( 'two rules', 2 === count( CacheArmor::rules() ), count( CacheArmor::rules() ) );

section( 'G. Statistics' );
ca_rule( '/wp/v2/pages', array( 'categories' => 467 ) );
$s = CacheArmor_Admin::stats();
check( 'empty cache', 0 === $s['count'] && 0 === $s['bytes'] );
ca_dispatch( $uri, '/wp/v2/pages', $params );
ca_dispatch( '/wp-json/wp/v2/pages?categories=467&per_page=5', '/wp/v2/pages', $params );
$s = CacheArmor_Admin::stats();
check( 'two entries counted, index excluded', 2 === $s['count'], $s['count'] );
check( 'size counted', $s['bytes'] > 0 );
check( 'not full', false === $s['full'] );
CacheArmor::purge_all();
$s = CacheArmor_Admin::stats();
check( 'zero after purge', 0 === $s['count'] );
check( 'index.html survives a purge', is_file( ca_dir() . '/index.html' ) );

section( 'H. Settings link on the Plugins screen' );
$links = CacheArmor_Admin::action_links( array( '<a href="#">Deactivate</a>' ) );
check( 'prepended', 2 === count( $links ) && false !== strpos( $links[0], 'Settings' ) );
check( 'points at the settings page', false !== strpos( $links[0], 'page=cachearmor' ) );

section( 'I. Route discovery' );
$routes = CacheArmor_Admin::discover_routes();
check( 'WooCommerce cart is listed as blocked', isset( $routes['/wc/store/v1/cart'] ) && true === $routes['/wc/store/v1/cart']['blocked'] );
check( 'users route is blocked', isset( $routes['/wp/v2/users'] ) && true === $routes['/wp/v2/users']['blocked'] );
check( 'Store API products are selectable', isset( $routes['/wc/store/v1/products'] ) && false === $routes['/wc/store/v1/products']['blocked'] );
check( 'no permission check detected', false === $routes['/wc/store/v1/products']['checked'] );
check( 'permission check detected', true === $routes['/wp/v2/pages']['checked'] );
check( 'regex routes skipped', ! isset( $routes['/wp/v2/pages/(?P<id>\d+)'] ) );
check( 'POST-only routes skipped', ! isset( $routes['/myplugin/v1/submit'] ) );
check( 'index route skipped', ! isset( $routes['/'] ) );

section( 'J. The settings page renders, labelled and accessible' );
ca_settings( array( '/wp/v2/pages' => array( 'enabled' => 1, 'ttl' => 600, 'query' => 'categories=1' ) ) );
$GLOBALS['ca_options'][ CacheArmor::STATUS_OPTION ] = array( 'time' => time() - 180, 'reason' => 'post', 'label' => 'Hello <b>world</b>', 'removed' => 3 );
ob_start();
CacheArmor_Admin::render();
$html = ob_get_clean();
check( 'renders', strlen( $html ) > 1000, strlen( $html ) . ' bytes' );
check( 'pause toggle present', false !== strpos( $html, 'name="paused"' ) );
check( 'lifetime presets present', false !== strpos( $html, 'id="cachearmor-ttl-presets"' ) );
check( 'filter toolbar present, hidden until JS runs', (bool) preg_match( '/id="cachearmor-toolbar"[^>]*hidden/', $html ) );
preg_match_all( '/<input type="checkbox" id="(cachearmor-route-[0-9a-f]{32})"/', $html, $boxes );
preg_match_all( '/<label for="(cachearmor-route-[0-9a-f]{32})"/', $html, $labels );
check( 'every route checkbox has a label', ! empty( $boxes[1] ) && $boxes[1] === $labels[1], count( $boxes[1] ) . ' boxes, ' . count( $labels[1] ) . ' labels' );
check( 'text and number inputs have aria-labels', 0 === preg_match( '/<input type="(text|number)" name="routes\[[^"]*"(?![^>]*aria-label=)[^>]*>/', $html ) );
check( 'blocked cart row has no checkbox', 1 === preg_match( '#data-route="/wc/store/v1/cart"><td><span aria-hidden="true">#', $html ) );
check( 'honest access labels', false !== strpos( $html, 'No permission check' ) && false !== strpos( $html, 'Permission check' ) && false === strpos( $html, '>Public<' ) );
check( 'saved route shows as enabled', 1 === preg_match( '#data-route="/wp/v2/pages"><td><input type="checkbox"[^>]*checked#', $html ) );
check( 'last-cleared line shown', false !== strpos( $html, 'Last cleared 3 mins ago' ) );
check( 'post title escaped', false === strpos( $html, '<b>world</b>' ) && false !== strpos( $html, '&lt;b&gt;world&lt;/b&gt;' ) );
check( 'usage against the limit shown', false !== strpos( $html, 'of 1,000' ) );
preg_match_all( '/<input type="number"[^>]*>/', $html, $numbers );
$narrow = array_filter( $numbers[0], function ( $i ) { return false === strpos( $i, 'class="cachearmor-ttl"' ); } );
check( 'every lifetime field uses the wide cachearmor-ttl class', ! empty( $numbers[0] ) && ! $narrow, count( $numbers[0] ) . ' fields, ' . count( $narrow ) . ' narrow' );
check( 'the stylesheet sizes that class', 1 === preg_match( '/\.cachearmor-ttl\s*\{[^}]*width:\s*8em/', (string) file_get_contents( dirname( __DIR__ ) . '/assets/admin.css' ) ) );

section( 'K. Paused settings are reflected on the page' );
$GLOBALS['ca_options'][ CacheArmor::OPTION ] = array( 'paused' => true );
ob_start();
CacheArmor_Admin::render();
$html = ob_get_clean();
check( 'pause notice shown', false !== strpos( $html, 'Caching is paused' ) );
check( 'pause box ticked', 1 === preg_match( '/name="paused" value="1" checked/', $html ) );

section( 'L. Admin bar item' );
$bar = new class() {
	public $nodes = array();
	public function add_node( $node ) { $this->nodes[] = $node; }
};
CacheArmor::admin_bar( $bar );
check( 'one node added', 1 === count( $bar->nodes ) );
check( 'links to the purge action with a nonce', false !== strpos( $bar->nodes[0]['href'], 'action=cachearmor_purge' ) && false !== strpos( $bar->nodes[0]['href'], '_wpnonce=' ) );

section( 'M. Assets are real enqueued files, loaded on the settings page only' );
$GLOBALS['ca_enqueued'] = array();
CacheArmor_Admin::add_page();
CacheArmor_Admin::assets( 'index.php' );
check( 'nothing enqueued on other screens', empty( $GLOBALS['ca_enqueued'] ) );
CacheArmor_Admin::assets( 'settings_page_cachearmor' );
check( 'stylesheet enqueued from the plugin', 'https://example.test/wp-content/plugins/cachearmor/assets/admin.css' === ( $GLOBALS['ca_enqueued']['style:cachearmor-admin'] ?? '' ) );
check( 'script enqueued from the plugin', 'https://example.test/wp-content/plugins/cachearmor/assets/admin.js' === ( $GLOBALS['ca_enqueued']['script:cachearmor-admin'] ?? '' ) );
check( 'both files exist and are not empty', filesize( dirname( __DIR__ ) . '/assets/admin.css' ) > 0 && filesize( dirname( __DIR__ ) . '/assets/admin.js' ) > 0 );

finish();
