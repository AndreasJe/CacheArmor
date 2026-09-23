<?php
/**
 * Plugin Name:       CacheArmor
 * Plugin URI:        https://github.com/AndreasJe/cachearmor
 * Description:       Caches expensive read-only WP REST API responses to disk, with stale-while-revalidate and stampede protection. Choose which routes to cache on the settings screen.
 * Version:           1.3.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Andreas Jensen
 * Author URI:        https://github.com/AndreasJe
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cachearmor
 *
 * CacheArmor is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
 *
 * @package CacheArmor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Guard with a constant, not class_exists(): PHP hoists unconditional class
 * declarations at compile time, so class_exists() would already be true here
 * and the file would always return early.
 */
if ( defined( 'CACHEARMOR_VERSION' ) ) {
	return;
}
define( 'CACHEARMOR_VERSION', '1.3.0' );
define( 'CACHEARMOR_FILE', __FILE__ );
define( 'CACHEARMOR_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Disk cache for read-only REST API responses.
 */
final class CacheArmor {

	/**
	 * Option holding the route selection, default lifetime and pause flag.
	 */
	const OPTION = 'cachearmor_settings';

	/**
	 * Option recording when the cache was last cleared, and why.
	 */
	const STATUS_OPTION = 'cachearmor_status';

	/**
	 * Response header reporting HIT, STALE or MISS.
	 */
	const HEADER = 'X-CacheArmor';

	/**
	 * Cache entries are inert JSON data files, never PHP.
	 *
	 * Plugins may not write files containing executable code, so the cache is
	 * protected by other means: filenames are unguessable hashes salted with
	 * the site's own auth salt, each directory denies direct access and has no
	 * listing, and only anonymous, session-free GET responses are stored.
	 */
	const EXT = '.json';

	/**
	 * Routes that are never cached, matched as prefixes.
	 *
	 * Their responses differ per user or per shopping session even for
	 * anonymous visitors. Extend with the 'cachearmor_blocked_routes' filter.
	 */
	const BLOCKED = array(
		'/wp/v2/users',
		'/wp/v2/settings',
		'/wp/v2/block-renderer',
		'/wp-site-health',
		// WooCommerce Store API: the cart, checkout and orders belong to one shopper.
		'/wc/store/cart',
		'/wc/store/v1/cart',
		'/wc/store/checkout',
		'/wc/store/v1/checkout',
		'/wc/store/order',
		'/wc/store/v1/order',
		'/wc/store/batch',
		'/wc/store/v1/batch',
	);

	/**
	 * Request headers that tie an anonymous visitor to server-side state.
	 *
	 * Keys as they appear in $_SERVER. X-WP-Nonce is deliberately absent:
	 * WordPress sends it on anonymous front-end requests too, where it carries
	 * no identity, and excluding it would stop those requests being cached.
	 */
	const SESSION_HEADERS = array( 'HTTP_CART_TOKEN', 'HTTP_NONCE' );

	/**
	 * Lock files created by this request, which it alone may remove.
	 *
	 * @var array
	 */
	private static $held = array();

	/**
	 * Cache keys answered from the cache during this request.
	 *
	 * @var array
	 */
	private static $served = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'pre_dispatch' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'post_dispatch' ), 10, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_transition' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'on_post_deleted' ), 10, 2 );
		add_action( 'created_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );

		if ( is_admin() ) {
			require_once CACHEARMOR_PATH . 'admin.php';
			CacheArmor_Admin::init();
		}
	}

	/**
	 * Tunables, filterable via 'cachearmor_config'.
	 *
	 * @return array
	 */
	public static function config() {
		$defaults = array(
			'dir'             => '', // Empty: a "cachearmor" folder in the uploads directory.
			'ttl'             => 900,
			'grace'           => 1800,
			'lock_ttl'        => 120,
			'cold_wait'       => 15,
			'max_bytes'       => 8388608,
			'max_entries'     => 1000,
			'max_total_bytes' => 268435456,
			'send_headers'    => true,
		);
		$config   = apply_filters( 'cachearmor_config', $defaults );
		return is_array( $config ) ? array_merge( $defaults, $config ) : $defaults;
	}

	/**
	 * Settings saved on the settings screen.
	 *
	 * @return array
	 */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = wp_parse_args(
			$stored,
			array(
				'routes' => array(),
				'ttl'    => 900,
				'paused' => false,
			)
		);
		if ( ! is_array( $settings['routes'] ) ) {
			$settings['routes'] = array();
		}
		return $settings;
	}

	/**
	 * Is caching paused, from the settings screen or wp-config.php?
	 *
	 * @return bool
	 */
	public static function is_paused() {
		if ( defined( 'CACHEARMOR_DISABLE' ) && CACHEARMOR_DISABLE ) {
			return true;
		}
		$settings = self::settings();
		return ! empty( $settings['paused'] );
	}

	/**
	 * Is this route one that may never be cached?
	 *
	 * @param string $route REST route, such as /wp/v2/pages.
	 * @return bool
	 */
	public static function is_blocked( $route ) {
		$route   = (string) $route;
		$blocked = apply_filters( 'cachearmor_blocked_routes', self::BLOCKED );
		foreach ( (array) $blocked as $prefix ) {
			$prefix = untrailingslashit( (string) $prefix );
			if ( '' !== $prefix && ( $route === $prefix || 0 === strpos( $route, $prefix . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cache rules: the settings screen's selection, then anything a filter adds.
	 *
	 * @return array
	 */
	public static function rules() {
		$rules = apply_filters( 'cachearmor_rules', self::rules_from_settings() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Convert the saved route selection into cache rules.
	 *
	 * @return array
	 */
	private static function rules_from_settings() {
		$settings = self::settings();
		$rules    = array();
		foreach ( $settings['routes'] as $route => $conf ) {
			if ( empty( $conf['enabled'] ) ) {
				continue;
			}
			$rule = array(
				'route' => (string) $route,
				'ttl'   => ! empty( $conf['ttl'] ) ? (int) $conf['ttl'] : (int) $settings['ttl'],
			);
			if ( ! empty( $conf['query'] ) ) {
				$parsed = array();
				parse_str( (string) $conf['query'], $parsed );
				if ( ! empty( $parsed ) ) {
					$rule['query'] = $parsed;
				}
			}
			$rules[] = $rule;
		}
		return $rules;
	}

	/**
	 * The requested URI, sanitized.
	 *
	 * Used for the cache key because WordPress mutates WP_REST_Request
	 * parameters during dispatch, so parameters read at rest_pre_dispatch do
	 * not match the same parameters at rest_post_dispatch. The raw URI does
	 * not change between the two hooks. It is only ever hashed; it is never
	 * output, echoed, or used in a database query.
	 *
	 * @return string
	 */
	public static function request_uri() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		return esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	}

	/**
	 * The requested host, lowercased. Only ever hashed.
	 *
	 * @return string
	 */
	private static function request_host() {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}
		return strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
	}

	/**
	 * The normalized request that identifies a cache entry.
	 *
	 * The host is included so that one site answering on several domains,
	 * such as a multilingual site with a domain per language, never shares an
	 * entry between them. Query parameters are sorted so that their order does
	 * not create duplicate entries, and a bare "_" parameter, the cache buster
	 * jQuery appends, is dropped so that it cannot defeat caching entirely.
	 *
	 * @return string
	 */
	public static function cache_key() {
		$parts = explode( '?', self::request_uri(), 2 );
		$key   = $parts[0];
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			$query = array();
			parse_str( $parts[1], $query );
			unset( $query['_'] );
			self::ksort_deep( $query );
			$query_string = http_build_query( $query, '', '&' );
			if ( '' !== $query_string ) {
				$key .= '?' . $query_string;
			}
		}
		return self::request_host() . $key;
	}

	/**
	 * Sort associative arrays by key, recursively, leaving lists in order.
	 *
	 * @param array $data Array to sort in place.
	 */
	private static function ksort_deep( array &$data ) {
		if ( array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) {
			ksort( $data );
		}
		foreach ( $data as &$value ) {
			if ( is_array( $value ) ) {
				self::ksort_deep( $value );
			}
		}
		unset( $value );
	}

	/**
	 * Does this anonymous request carry a shopping session or similar?
	 *
	 * Such a response can differ between two anonymous visitors, so it is
	 * never served from or stored in the cache.
	 *
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return bool
	 */
	public static function is_session_request( $request ) {
		foreach ( self::SESSION_HEADERS as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				return true;
			}
		}
		// WooCommerce's session and cart cookies. Only their presence matters.
		$cookies = array( 'woocommerce_cart_hash', 'woocommerce_items_in_cart' );
		if ( defined( 'COOKIEHASH' ) ) {
			$cookies[] = 'wp_woocommerce_session_' . COOKIEHASH;
		}
		foreach ( $cookies as $cookie ) {
			if ( isset( $_COOKIE[ $cookie ] ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'cachearmor_bypass_request', false, $request );
	}

	/**
	 * Find the first rule matching this request.
	 *
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return array|null
	 */
	public static function match_rule( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_method' ) ) {
			return null;
		}
		if ( 'GET' !== $request->get_method() ) {
			return null;
		}
		if ( is_user_logged_in() || self::is_paused() || '' === self::request_uri() ) {
			return null;
		}

		$route = (string) $request->get_route();
		if ( self::is_blocked( $route ) || self::is_session_request( $request ) ) {
			return null;
		}

		foreach ( self::rules() as $rule ) {
			if ( empty( $rule['route'] ) || $rule['route'] !== $route ) {
				continue;
			}
			$matched = true;
			if ( ! empty( $rule['query'] ) && is_array( $rule['query'] ) ) {
				foreach ( $rule['query'] as $key => $expected ) {
					$actual = $request->get_param( $key );
					if ( is_array( $actual ) ) {
						$actual = implode( ',', $actual );
					}
					if ( (string) $actual !== (string) $expected ) {
						$matched = false;
						break;
					}
				}
			}
			if ( $matched ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Cache directory for the current site, without trailing slash.
	 *
	 * By default a "cachearmor" folder in the uploads directory, resolved at
	 * runtime. WordPress resolves that per site, so each site in a network has
	 * its own cache and can never be served another's responses. A custom
	 * 'dir' is shared by every site, so the site ID is appended to it.
	 *
	 * @return string
	 */
	public static function dir() {
		$config = self::config();
		if ( ! empty( $config['dir'] ) ) {
			return untrailingslashit( (string) $config['dir'] ) . '/' . get_current_blog_id();
		}
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( $uploads['basedir'] ) . '/cachearmor';
	}

	/**
	 * Cache file path for the current request.
	 *
	 * @return string
	 */
	public static function cache_file() {
		return self::dir() . '/' . self::hash() . self::EXT;
	}

	/**
	 * Refresh lock path for the current request.
	 *
	 * @return string
	 */
	public static function lock_file() {
		return self::dir() . '/' . self::hash() . '.lock';
	}

	/**
	 * Unguessable filename component.
	 *
	 * Salted with the site's own auth salt so a cache filename cannot be
	 * derived from the request URL by an outsider.
	 *
	 * @return string
	 */
	private static function hash() {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : ABSPATH;
		return md5( $salt . '|' . self::cache_key() );
	}

	/**
	 * Create the cache directory and block direct HTTP access to it.
	 *
	 * @return bool
	 */
	public static function prepare_dir() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		/*
		 * Protect this directory only, never its parent: by default the parent
		 * is the uploads directory, where a deny rule would block every media
		 * file on the site. An empty index.html rather than the usual index.php,
		 * because plugins may not write files containing executable code; it
		 * suppresses the directory listing just the same.
		 */
		if ( ! file_exists( $dir . '/index.html' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cache writes must be local and synchronous.
			file_put_contents( $dir . '/index.html', '' );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cache writes must be local and synchronous.
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		return true;
	}

	/**
	 * Seconds since a file was modified, or null if it does not exist.
	 *
	 * @param string $file File path.
	 * @return int|null
	 */
	private static function file_age( $file ) {
		clearstatcache( true, $file );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a concurrent purge may remove the file at any moment; null is the expected result then.
		$mtime = @filemtime( $file );
		return false === $mtime ? null : max( 0, time() - $mtime );
	}

	/**
	 * Take the refresh lock. True means this request should regenerate.
	 *
	 * @return bool
	 */
	public static function take_lock() {
		$lock = self::lock_file();
		$age  = self::file_age( $lock );
		if ( null !== $age ) {
			$config = self::config();
			if ( $age < (int) $config['lock_ttl'] ) {
				return false;
			}
			// Left behind by a request that died mid-render.
			wp_delete_file( $lock );
		}
		/*
		 * Mode 'x' creates the file only if it does not already exist, and does
		 * so atomically, so exactly one of any number of concurrent requests
		 * wins. WP_Filesystem is not used: it can be backed by FTP or SSH,
		 * which are neither local nor atomic.
		 */
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- failure is the expected signal that another request holds the lock.
		$handle = @fopen( $lock, 'x' );
		if ( false === $handle ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the handle opened above.
		fclose( $handle );
		self::$held[ $lock ] = true;
		return true;
	}

	/**
	 * Release the refresh lock, if this request holds it.
	 *
	 * A request that rendered without the lock, after giving up waiting,
	 * must not remove the lock of the request that is still regenerating.
	 */
	public static function release_lock() {
		$lock = self::lock_file();
		if ( empty( self::$held[ $lock ] ) ) {
			return;
		}
		unset( self::$held[ $lock ] );
		wp_delete_file( $lock );
	}

	/**
	 * Does some request currently hold the lock for this entry?
	 *
	 * @return bool
	 */
	private static function lock_held() {
		$config = self::config();
		$age    = self::file_age( self::lock_file() );
		return null !== $age && $age < (int) $config['lock_ttl'];
	}

	/**
	 * Wait for another request to finish regenerating an entry.
	 *
	 * @param string $file    Cache file path.
	 * @param int    $limit   Oldest usable age, in seconds.
	 * @param float  $timeout Longest wait, in seconds.
	 * @return int|null Age of the fresh entry, or null to render normally.
	 */
	private static function wait_for_fresh( $file, $limit, $timeout ) {
		$waited = 0.0;
		while ( $waited < $timeout ) {
			usleep( 250000 );
			$waited += 0.25;
			$age     = self::file_age( $file );
			if ( null !== $age && $age <= $limit ) {
				return $age;
			}
			if ( ! self::lock_held() ) {
				// The regenerating request has finished. Look once more, since it
				// may have written the entry just before releasing the lock; if
				// not, it failed and there is nothing to wait for.
				$age = self::file_age( $file );
				return ( null !== $age && $age <= $limit ) ? $age : null;
			}
		}
		return null;
	}

	/**
	 * Read and decode a cache entry.
	 *
	 * @param string $file Cache file path.
	 * @return array|null
	 */
	private static function read_entry( $file ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local cache file that a concurrent purge may remove at any moment.
		$raw = @file_get_contents( $file );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		// json_decode, not unserialize: decoding must never be able to
		// instantiate PHP objects from a file on disk.
		$payload = json_decode( $raw, true );
		return ( is_array( $payload ) && array_key_exists( 'data', $payload ) ) ? $payload : null;
	}

	/**
	 * Serve a cached response where possible.
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed
	 */
	public static function pre_dispatch( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result; // Another filter has already answered this request.
		}
		$rule = self::match_rule( $request );
		if ( null === $rule || ! self::prepare_dir() ) {
			return $result;
		}

		$config = self::config();
		$ttl    = max( 1, isset( $rule['ttl'] ) ? (int) $rule['ttl'] : (int) $config['ttl'] );
		$limit  = $ttl + max( 0, (int) $config['grace'] );
		$file   = self::cache_file();
		$age    = self::file_age( $file );

		if ( null === $age || $age > $limit ) {
			// Missing, or too old to serve at all.
			if ( self::take_lock() ) {
				return $result; // This request regenerates it.
			}
			// Another request is already regenerating it: wait for that rather
			// than running the same expensive render in parallel.
			$age = self::wait_for_fresh( $file, $limit, (float) $config['cold_wait'] );
			if ( null === $age ) {
				return $result; // Nothing usable in time, so render normally.
			}
		} elseif ( $age > $ttl && self::take_lock() ) {
			return $result; // Stale: this request refreshes it; others get the stale copy meanwhile.
		}

		$payload = self::read_entry( $file );
		if ( null === $payload ) {
			return $result;
		}

		$response = new WP_REST_Response( $payload['data'], 200 );
		if ( ! empty( $payload['headers'] ) && is_array( $payload['headers'] ) ) {
			foreach ( $payload['headers'] as $name => $value ) {
				$response->header( (string) $name, $value );
			}
		}
		if ( ! empty( $config['send_headers'] ) ) {
			$response->header( self::HEADER, ( $age > $ttl ) ? 'STALE' : 'HIT' );
			$response->header( self::HEADER . '-Age', (string) $age );
		}
		self::$served[ self::hash() ] = true;
		return $response;
	}

	/**
	 * Store a freshly generated response.
	 *
	 * @param WP_REST_Response $response Result to send to the client.
	 * @param WP_REST_Server   $server   Server instance.
	 * @param WP_REST_Request  $request  Request used to generate the response.
	 * @return WP_REST_Response
	 */
	public static function post_dispatch( $response, $server, $request ) {
		if ( self::$served ) {
			$key = self::hash();
			if ( isset( self::$served[ $key ] ) ) {
				unset( self::$served[ $key ] );
				return $response; // Answered from the cache: nothing to store.
			}
		}

		$rule = self::match_rule( $request );
		if ( null === $rule || ! is_object( $response ) || ! method_exists( $response, 'get_status' ) || 200 !== (int) $response->get_status() ) {
			self::release_lock();
			return $response;
		}

		$config  = self::config();
		$headers = $response->get_headers();
		$keep    = array();
		foreach ( array( 'X-WP-Total', 'X-WP-TotalPages', 'Link' ) as $name ) {
			if ( isset( $headers[ $name ] ) ) {
				$keep[ $name ] = $headers[ $name ];
			}
		}

		$raw = wp_json_encode(
			array(
				'data'    => $response->get_data(),
				'headers' => $keep,
				'stored'  => time(),
			)
		);

		$file = self::cache_file();
		if ( is_string( $raw ) && '' !== $raw && strlen( $raw ) <= (int) $config['max_bytes']
			&& self::prepare_dir() && self::has_room( $file, strlen( $raw ) ) ) {
			self::write_entry( $file, $raw );
		}

		self::release_lock();
		if ( ! empty( $config['send_headers'] ) ) {
			$response->header( self::HEADER, 'MISS' );
		}
		return $response;
	}

	/**
	 * Is there room for a new entry of this size?
	 *
	 * Without a cap, anyone could fill the disk by requesting a cached route
	 * with an endless supply of made-up query parameters. Once the cap is
	 * reached, new responses are simply not stored until the next purge.
	 *
	 * @param string $file  Cache file about to be written.
	 * @param int    $bytes Size of the new entry.
	 * @return bool
	 */
	private static function has_room( $file, $bytes ) {
		if ( file_exists( $file ) ) {
			return true; // Replacing an entry does not grow the cache.
		}
		$config  = self::config();
		$entries = self::entries();
		if ( count( $entries ) >= (int) $config['max_entries'] ) {
			return false;
		}
		return self::bytes_of( $entries ) + $bytes <= (int) $config['max_total_bytes'];
	}

	/**
	 * Write an entry atomically.
	 *
	 * @param string $file Cache file path.
	 * @param string $raw  Encoded entry.
	 */
	private static function write_entry( $file, $raw ) {
		$temp = $file . '.' . uniqid( '', true ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cache writes must be local and synchronous.
		if ( false === file_put_contents( $temp, $raw ) ) {
			return;
		}
		/*
		 * rename() on the same filesystem is atomic, so readers never see a
		 * half-written entry. WP_Filesystem::move() offers no such guarantee.
		 * On Windows it fails while another request is reading the target;
		 * the old entry is then kept and this one discarded, and the warning
		 * is suppressed so it can never leak into the JSON response.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic replace is required; failure is handled below.
		if ( ! @rename( $temp, $file ) ) {
			wp_delete_file( $temp );
		}
	}

	/**
	 * Every cache entry for the current site.
	 *
	 * @return string[]
	 */
	public static function entries() {
		$files = glob( self::dir() . '/*' . self::EXT );
		return is_array( $files ) ? array_values( array_filter( $files, array( __CLASS__, 'is_cache_file' ) ) ) : array();
	}

	/**
	 * Combined size of some files, in bytes.
	 *
	 * @param string[] $files File paths.
	 * @return int
	 */
	public static function bytes_of( array $files ) {
		$total = 0;
		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a concurrent purge may remove the file mid-loop.
			$total += (int) @filesize( $file );
		}
		return $total;
	}

	/**
	 * Is this path a cache entry rather than the index or a temp file?
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function is_cache_file( $path ) {
		return (bool) preg_match( '/[0-9a-f]{32}\.json$/', $path );
	}

	/**
	 * Delete every cached response for the current site.
	 *
	 * @param string $reason What caused the purge: post, term, settings, manual or deactivate.
	 * @param string $label  What changed, for the status line on the settings screen.
	 * @return int Number of entries removed.
	 */
	public static function purge_all( $reason = 'manual', $label = '' ) {
		$count = 0;
		foreach ( self::entries() as $file ) {
			wp_delete_file( $file );
			++$count;
		}
		// A temp file is renamed within milliseconds of being written; one
		// older than a minute was orphaned by a request that died.
		$temps = glob( self::dir() . '/*.tmp' );
		foreach ( is_array( $temps ) ? $temps : array() as $temp ) {
			$age = self::file_age( $temp );
			if ( null !== $age && $age > 60 ) {
				wp_delete_file( $temp );
			}
		}
		update_option(
			self::STATUS_OPTION,
			array(
				'time'    => time(),
				'reason'  => (string) $reason,
				'label'   => (string) $label,
				'removed' => $count,
			),
			false
		);
		do_action( 'cachearmor_purged', $count, $reason );
		return $count;
	}

	/**
	 * Purge when content anonymous visitors can see is published, changed or unpublished.
	 *
	 * Drafts, auto-drafts, revisions and private posts never reach an
	 * anonymous REST request, and neither do post types hidden from the REST
	 * API, such as oEmbed caches or WooCommerce orders. Saving those used to
	 * empty the whole cache.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function on_post_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( ! is_object( $post ) || ! self::is_rest_post_type( $post->post_type ) ) {
			return;
		}
		self::maybe_purge( 'post', (int) $post->ID, (string) $post->post_title );
	}

	/**
	 * Purge when a published post is deleted outright.
	 *
	 * Moving a post to the trash is already handled as a status transition.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 */
	public static function on_post_deleted( $post_id, $post = null ) {
		if ( ! is_object( $post ) ) {
			$post = get_post( $post_id );
		}
		if ( ! is_object( $post ) || 'publish' !== $post->post_status || ! self::is_rest_post_type( $post->post_type ) ) {
			return;
		}
		self::maybe_purge( 'post', (int) $post_id, (string) $post->post_title );
	}

	/**
	 * Purge when a term in a REST-visible taxonomy changes.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function on_term_change( $term_id, $tt_id, $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! is_object( $tax ) || empty( $tax->show_in_rest ) ) {
			return;
		}
		$label = isset( $tax->labels->name ) ? (string) $tax->labels->name : (string) $taxonomy;
		self::maybe_purge( 'term', (int) $term_id, $label );
	}

	/**
	 * Is this post type exposed through the REST API?
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private static function is_rest_post_type( $post_type ) {
		$type = get_post_type_object( $post_type );
		return is_object( $type ) && ! empty( $type->show_in_rest );
	}

	/**
	 * Purge after a content change, unless a filter says otherwise.
	 *
	 * @param string $type      'post' or 'term'.
	 * @param int    $object_id ID of what changed.
	 * @param string $label     Title or taxonomy name, for the status line.
	 */
	private static function maybe_purge( $type, $object_id, $label ) {
		if ( ! apply_filters( 'cachearmor_should_purge', true, $type, $object_id ) ) {
			return;
		}
		self::purge_all( $type, $label );
	}

	/**
	 * Add a "Clear REST cache" item to the admin bar.
	 *
	 * @param WP_Admin_Bar $bar Admin bar instance.
	 */
	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'cachearmor-purge',
				'title' => esc_html__( 'Clear REST cache', 'cachearmor' ),
				'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=cachearmor_purge' ), 'cachearmor_purge' ),
			)
		);
	}

	/**
	 * Clear the cache when the plugin is deactivated.
	 */
	public static function deactivate() {
		self::purge_all( 'deactivate' );
	}
}

CacheArmor::init();

register_deactivation_hook( __FILE__, array( 'CacheArmor', 'deactivate' ) );
