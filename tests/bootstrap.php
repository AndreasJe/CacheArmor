<?php
/**
 * Minimal WordPress stand-ins, so the plugin can be exercised from the
 * command line, plus the assertion helpers every test file shares.
 *
 * Not shipped in the distributed package.
 *
 * @package CacheArmor
 */

if ( 'cli' !== PHP_SAPI ) {
	header( 'HTTP/1.1 403 Forbidden' );
	exit( 'This file can only be run from the command line.' );
}
if ( defined( 'ABSPATH' ) ) {
	exit( 'This file must not be loaded inside WordPress.' );
}

define( 'ABSPATH', '/tmp/cachearmor-test/' );
define( 'WP_CONTENT_DIR', '/tmp/cachearmor-test/wp-content' );
define( 'CA_UPLOADS', WP_CONTENT_DIR . '/uploads' );
define( 'COOKIEHASH', 'testhash' );

$GLOBALS['ca_filters']    = array();
$GLOBALS['ca_options']    = array();
$GLOBALS['ca_logged_in']  = false;
$GLOBALS['ca_blog_id']    = 1;
$GLOBALS['ca_post_types'] = array(
	'post'         => true,
	'page'         => true,
	'oembed_cache' => false,
	'shop_order'   => false,
);
$GLOBALS['ca_taxonomies'] = array(
	'category'           => true,
	'product_visibility' => false,
);

// ---------------------------------------------------------------- hooks.
function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['ca_filters'][ $tag ][] = $callback;
	return true;
}
function add_action( $tag, $callback, $priority = 10, $args = 1 ) {
	return add_filter( $tag, $callback, $priority, $args );
}
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['ca_filters'][ $tag ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	foreach ( $GLOBALS['ca_filters'][ $tag ] ?? array() as $callback ) {
		$callback( ...$args );
	}
}

// ------------------------------------------------------------- general.
function is_admin() { return false; }
function is_user_logged_in() { return $GLOBALS['ca_logged_in']; }
function get_current_blog_id() { return $GLOBALS['ca_blog_id']; }
function current_user_can( $cap ) { return true; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['ca_options'] ) ? $GLOBALS['ca_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['ca_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['ca_options'][ $key ] ); return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0775, true ); }
function wp_delete_file( $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_salt( $scheme = '' ) { return 'test-salt'; }
function register_deactivation_hook( $file, $callback ) {}
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function esc_url_raw( $url ) { return $url; }
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function get_post( $id ) { return null; }
// Like WordPress: the main site uses uploads/, others uploads/sites/{id}/.
function wp_upload_dir( $time = null, $create_dir = true ) {
	$base = 1 === $GLOBALS['ca_blog_id'] ? CA_UPLOADS : CA_UPLOADS . '/sites/' . $GLOBALS['ca_blog_id'];
	return array( 'basedir' => $base, 'error' => false );
}
function plugins_url( $path, $file ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/' . $path; }
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) { $GLOBALS['ca_enqueued'][ 'style:' . $handle ] = $src; }
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) { $GLOBALS['ca_enqueued'][ 'script:' . $handle ] = $src; }
function get_post_type_object( $type ) {
	return isset( $GLOBALS['ca_post_types'][ $type ] ) ? (object) array( 'show_in_rest' => $GLOBALS['ca_post_types'][ $type ] ) : null;
}
function get_taxonomy( $taxonomy ) {
	return isset( $GLOBALS['ca_taxonomies'][ $taxonomy ] )
		? (object) array( 'show_in_rest' => $GLOBALS['ca_taxonomies'][ $taxonomy ], 'labels' => (object) array( 'name' => ucfirst( $taxonomy ) ) )
		: false;
}

// ---------------------------------------------------- output and i18n.
function __( $text, $domain = null ) { return $text; }
function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
function esc_url( $url ) { return (string) $url; }
function number_format_i18n( $number ) { return number_format( (float) $number ); }
function size_format( $bytes ) { return $bytes . ' B'; }
function human_time_diff( $from, $to ) {
	$diff = abs( $to - $from );
	if ( $diff >= 86400 ) { return round( $diff / 86400 ) . ' days'; }
	if ( $diff >= 3600 ) { return round( $diff / 3600 ) . ' hours'; }
	return max( 1, round( $diff / 60 ) ) . ' mins';
}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=' . $action; }
function checked( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="' . $action . '">'; }
function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button type="submit">' . $text . '</button>'; }
function add_options_page( ...$args ) { return 'settings_page_cachearmor'; }

// ---------------------------------------------------------------- REST.
class WP_REST_Response {
	private $data; private $status; private $headers = array();
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
	public function get_headers() { return $this->headers; }
	public function header( $key, $value ) { $this->headers[ $key ] = $value; }
}
class WP_REST_Request {
	private $method; private $route; private $params;
	public function __construct( $method, $route, $params ) { $this->method = $method; $this->route = $route; $this->params = $params; }
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
	public function get_param( $key ) { return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null; }
	// Mimics the parameter mutation WordPress performs during dispatch.
	public function sanitize_params() {
		if ( isset( $this->params['categories'] ) && ! is_array( $this->params['categories'] ) ) {
			$this->params['categories'] = array( (int) $this->params['categories'] );
		}
		if ( ! isset( $this->params['page'] ) ) { $this->params['page'] = 1; }
	}
}
class WP_REST_Server {
	public function get_routes() {
		$get = array( 'GET' => true );
		return array(
			'/'                         => array( array( 'methods' => $get ) ),
			'/wp/v2/pages'              => array( array( 'methods' => $get, 'permission_callback' => array( 'X', 'get_items_permissions_check' ) ) ),
			'/wp/v2/pages/(?P<id>\d+)'  => array( array( 'methods' => $get ) ),
			'/wp/v2/users'              => array( array( 'methods' => $get, 'permission_callback' => array( 'X', 'check' ) ) ),
			'/wc/store/v1/cart'         => array( array( 'methods' => $get, 'permission_callback' => '__return_true' ) ),
			'/wc/store/v1/products'     => array( array( 'methods' => $get, 'permission_callback' => '__return_true' ) ),
			'/myplugin/v1/things'       => array( array( 'methods' => $get ) ),
			'/myplugin/v1/submit'       => array( array( 'methods' => array( 'POST' => true ) ) ),
		);
	}
}
function rest_get_server() { return new WP_REST_Server(); }

require_once dirname( __DIR__ ) . '/cachearmor.php';

// The plugin's own hooks, restored between tests by ca_reset().
$GLOBALS['ca_baseline'] = $GLOBALS['ca_filters'];

// ------------------------------------------------------------- harness.
function ca_dir( $blog_id = 1 ) {
	return ( 1 === $blog_id ? CA_UPLOADS : CA_UPLOADS . '/sites/' . $blog_id ) . '/cachearmor';
}

function ca_count( $blog_id = 1 ) {
	$files = glob( ca_dir( $blog_id ) . '/*.json' );
	return is_array( $files ) ? count( $files ) : 0;
}

/** Remove every file the plugin can create, for both test sites, plus anything stray in the uploads root. */
function ca_wipe() {
	foreach ( array( ca_dir( 1 ), ca_dir( 2 ) ) as $dir ) {
		foreach ( array_merge( glob( "$dir/*" ) ?: array(), array( "$dir/.htaccess" ) ) as $file ) {
			if ( is_file( $file ) ) { unlink( $file ); }
		}
	}
	foreach ( array( CA_UPLOADS . '/.htaccess', CA_UPLOADS . '/index.html' ) as $file ) {
		if ( is_file( $file ) ) { unlink( $file ); }
	}
}

/** Fresh state: plugin hooks only, no options, anonymous, blog 1, empty cache. */
function ca_reset() {
	$GLOBALS['ca_filters']   = $GLOBALS['ca_baseline'];
	$GLOBALS['ca_options']   = array();
	$GLOBALS['ca_logged_in'] = false;
	$GLOBALS['ca_blog_id']   = 1;
	$_COOKIE                 = array();
	unset( $_SERVER['HTTP_CART_TOKEN'], $_SERVER['HTTP_NONCE'], $_SERVER['HTTP_X_WP_NONCE'] );
	$_SERVER['HTTP_HOST'] = 'example.test';
	// Keep waits short so the suite runs in seconds.
	add_filter( 'cachearmor_config', function ( $c ) { $c['cold_wait'] = 0.5; return $c; } );
	ca_wipe();
}

function ca_rule( $route, $query = array(), $ttl = 900 ) {
	add_filter(
		'cachearmor_rules',
		function ( $rules ) use ( $route, $query, $ttl ) {
			$rules[] = array( 'route' => $route, 'query' => $query, 'ttl' => $ttl );
			return $rules;
		}
	);
}

function ca_config( array $overrides ) {
	add_filter( 'cachearmor_config', function ( $c ) use ( $overrides ) { return array_merge( $c, $overrides ); } );
}

/** A 27-item collection, like the page-builder responses the plugin exists for. */
function ca_page() {
	$data = array();
	for ( $i = 0; $i < 27; $i++ ) {
		$data[] = array( 'id' => 40000 + $i, 'content' => str_repeat( 'y', 1500 ) );
	}
	$response = new WP_REST_Response( $data, 200 );
	$response->header( 'X-WP-Total', 27 );
	$response->header( 'X-WP-TotalPages', 1 );
	return $response;
}

/** Run a request through the two REST hooks the way WordPress does. */
function ca_dispatch( $uri, $route, $params = array(), $renderer = 'ca_page', $method = 'GET' ) {
	$_SERVER['REQUEST_URI'] = $uri;
	$request                = new WP_REST_Request( $method, $route, $params );
	$response               = apply_filters( 'rest_pre_dispatch', null, null, $request );
	if ( null === $response ) {
		$request->sanitize_params();
		$response = $renderer();
	}
	return apply_filters( 'rest_post_dispatch', $response, null, $request );
}

function ca_status( $response ) {
	$headers = $response->get_headers();
	return isset( $headers['X-CacheArmor'] ) ? $headers['X-CacheArmor'] : '(none)';
}

// ---------------------------------------------------------- assertions.
$GLOBALS['ca_checks']   = 0;
$GLOBALS['ca_failures'] = 0;

function check( $label, $ok, $detail = '' ) {
	$GLOBALS['ca_checks']++;
	if ( ! $ok ) {
		$GLOBALS['ca_failures']++;
	}
	printf( "  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' === $detail ? '' : "  ($detail)" );
}

function section( $title ) {
	echo "\n$title\n";
	ca_reset();
}

function finish() {
	printf( "\n%d checks, %d failed\n", $GLOBALS['ca_checks'], $GLOBALS['ca_failures'] );
	exit( $GLOBALS['ca_failures'] ? 1 : 0 );
}
