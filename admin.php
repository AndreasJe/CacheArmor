<?php
/**
 * Settings screen for CacheArmor.
 *
 * @package CacheArmor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen: pick which REST routes to cache.
 */
final class CacheArmor_Admin {

	const CAP   = 'manage_options';
	const PAGE  = 'cachearmor';
	const SAVE  = 'cachearmor_save';
	const PURGE = 'cachearmor_purge';

	/**
	 * Lifetime presets offered in every lifetime field, in seconds.
	 */
	const PRESETS = array( 300, 900, 3600, 21600, 86400 );

	/**
	 * Hook suffix of the settings page, for loading assets there only.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_' . self::SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::PURGE, array( __CLASS__, 'handle_purge' ) );
		add_action( 'admin_notices', array( __CLASS__, 'purged_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// Derived at runtime so the link survives any installed folder name.
		add_filter( 'plugin_action_links_' . plugin_basename( CACHEARMOR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * URL of the settings page.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function settings_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'cachearmor' ) . '</a>' );
		return $links;
	}

	/**
	 * Add the settings page.
	 */
	public static function add_page() {
		self::$hook = (string) add_options_page(
			__( 'CacheArmor – REST API Response Cache', 'cachearmor' ),
			__( 'CacheArmor', 'cachearmor' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Styles and the route filter script, on the settings page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function assets( $hook ) {
		if ( '' === self::$hook || self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'cachearmor-admin', plugins_url( 'assets/admin.css', CACHEARMOR_FILE ), array(), CACHEARMOR_VERSION );
		wp_enqueue_script( 'cachearmor-admin', plugins_url( 'assets/admin.js', CACHEARMOR_FILE ), array(), CACHEARMOR_VERSION, true );
	}

	/**
	 * Discover registered REST routes, including custom ones.
	 *
	 * @return array Route => array( 'namespace', 'checked', 'blocked' ).
	 */
	public static function discover_routes() {
		$found = array();
		if ( ! function_exists( 'rest_get_server' ) ) {
			return $found;
		}
		$server = rest_get_server();
		if ( ! is_object( $server ) ) {
			return $found;
		}

		// Longest first, so /wc/store/v1/products lands in wc/store/v1, not wc/store.
		$namespaces = method_exists( $server, 'get_namespaces' ) ? (array) $server->get_namespaces() : array();
		usort(
			$namespaces,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $server->get_routes() as $route => $handlers ) {
			// Routes with regex placeholders cannot be selected here; only
			// collections and singular routes without them are listed.
			if ( '/' === $route || false !== strpos( $route, '(?P<' ) ) {
				continue;
			}

			$supports_get = false;
			$has_check    = true;
			foreach ( (array) $handlers as $handler ) {
				if ( empty( $handler['methods']['GET'] ) ) {
					continue;
				}
				$supports_get = true;
				$callback     = isset( $handler['permission_callback'] ) ? $handler['permission_callback'] : null;
				$has_check    = ! ( empty( $callback ) || '__return_true' === $callback );
				break;
			}
			if ( ! $supports_get ) {
				continue;
			}

			$namespace = self::namespace_of( $route, $namespaces );

			$found[ $route ] = array(
				'namespace' => $namespace,
				'checked'   => $has_check,
				'blocked'   => CacheArmor::is_blocked( $route ),
			);
		}

		ksort( $found );
		return $found;
	}

	/**
	 * The registered namespace a route belongs to, such as wp/v2.
	 *
	 * @param string $route      REST route.
	 * @param array  $namespaces Registered namespaces, longest first.
	 * @return string
	 */
	private static function namespace_of( $route, $namespaces ) {
		$path = trim( $route, '/' );
		foreach ( $namespaces as $ns ) {
			$ns = trim( (string) $ns, '/' );
			if ( '' !== $ns && ( $path === $ns || 0 === strpos( $path, $ns . '/' ) ) ) {
				return $ns;
			}
		}
		// Not under a registered namespace: fall back to the first segment.
		$first = strstr( $path, '/', true );
		return false === $first ? $path : $first;
	}

	/**
	 * Cache usage for the current site.
	 *
	 * @return array
	 */
	public static function stats() {
		$config  = CacheArmor::config();
		$entries = CacheArmor::entries();
		$count   = count( $entries );
		$bytes   = CacheArmor::bytes_of( $entries );
		return array(
			'count'     => $count,
			'bytes'     => $bytes,
			'max_count' => (int) $config['max_entries'],
			'max_bytes' => (int) $config['max_total_bytes'],
			'full'      => $count >= (int) $config['max_entries'] || $bytes >= (int) $config['max_total_bytes'],
			'dir'       => CacheArmor::dir(),
		);
	}

	/**
	 * Sanitize the submitted routes structure.
	 *
	 * @param array $raw Raw, unslashed input.
	 * @return array
	 */
	private static function sanitize_routes( $raw ) {
		$clean = array();
		if ( ! is_array( $raw ) ) {
			return $clean;
		}
		foreach ( $raw as $route => $conf ) {
			if ( ! is_array( $conf ) ) {
				continue;
			}
			$row = array();
			if ( ! empty( $conf['enabled'] ) ) {
				$row['enabled'] = 1;
			}
			if ( isset( $conf['ttl'] ) && '' !== $conf['ttl'] ) {
				$row['ttl'] = absint( $conf['ttl'] );
			}
			if ( isset( $conf['query'] ) ) {
				$row['query'] = sanitize_text_field( (string) $conf['query'] );
			}
			$clean[ sanitize_text_field( (string) $route ) ] = $row;
		}
		return $clean;
	}

	/**
	 * Save submitted settings.
	 */
	public static function handle_save() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'cachearmor' ), 403 );
		}
		check_admin_referer( self::SAVE );

		$ttl      = isset( $_POST['default_ttl'] ) ? absint( wp_unslash( $_POST['default_ttl'] ) ) : 900;
		$settings = array(
			'routes' => array(),
			'ttl'    => max( 10, min( 604800, $ttl ) ),
			'paused' => ! empty( $_POST['paused'] ),
		);

		$submitted = array();
		if ( isset( $_POST['routes'] ) && is_array( $_POST['routes'] ) ) {
			// Sanitize the submitted structure up front: keys are route
			// strings, values are a small fixed set of scalar fields.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by self::sanitize_routes().
			$submitted = self::sanitize_routes( wp_unslash( $_POST['routes'] ) );
		}

		$available = self::discover_routes();
		foreach ( $submitted as $raw_route => $conf ) {
			$route = '/' . ltrim( $raw_route, '/' );

			// Only accept routes that actually exist and may be cached.
			if ( ! isset( $available[ $route ] ) || $available[ $route ]['blocked'] || empty( $conf['enabled'] ) ) {
				continue;
			}

			$entry = array( 'enabled' => 1 );
			if ( isset( $conf['ttl'] ) && '' !== $conf['ttl'] ) {
				$entry['ttl'] = max( 10, min( 604800, absint( $conf['ttl'] ) ) );
			}
			if ( isset( $conf['query'] ) && '' !== trim( (string) $conf['query'] ) ) {
				$entry['query'] = sanitize_text_field( ltrim( (string) $conf['query'], '?&' ) );
			}
			$settings['routes'][ $route ] = $entry;
		}

		// Autoloaded: the settings are read on every REST request.
		update_option( CacheArmor::OPTION, $settings, true );
		CacheArmor::purge_all( 'settings' );

		// Return to the tab the form was submitted from.
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$url = self::settings_url( array( 'updated' => '1' ) );
		if ( in_array( $tab, array( 'routes', 'settings', 'help' ), true ) ) {
			$url .= '#' . $tab;
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Empty the cache, from the settings page or the admin bar.
	 */
	public static function handle_purge() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cachearmor' ), 403 );
		}
		check_admin_referer( self::PURGE );

		$removed = CacheArmor::purge_all( 'manual' );

		$target = wp_get_referer();
		if ( ! $target ) {
			$target = self::settings_url();
		}
		// Only admin screens get the confirmation parameter; a front-end page
		// the admin bar was used from is returned to unchanged.
		if ( 0 === strpos( $target, admin_url() ) ) {
			$target = add_query_arg( 'cachearmor_purged', $removed, remove_query_arg( array( 'updated', 'cachearmor_purged' ), $target ) );
		}
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Confirm a purge on whichever admin screen it was triggered from.
	 */
	public static function purged_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		if ( ! isset( $_GET['cachearmor_purged'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only count, absint() sanitizes.
		$count = absint( $_GET['cachearmor_purged'] );
		self::notice(
			'success',
			sprintf(
				/* translators: %s: number of cached responses removed. */
				_n( 'REST cache cleared. %s cached response removed.', 'REST cache cleared. %s cached responses removed.', $count, 'cachearmor' ),
				number_format_i18n( $count )
			),
			true
		);
	}

	/**
	 * Print an admin notice.
	 *
	 * @param string $type        success, warning or error.
	 * @param string $message     Plain-text message.
	 * @param bool   $dismissible Whether it can be dismissed.
	 */
	private static function notice( $type, $message, $dismissible = false ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . ( $dismissible ? ' is-dismissible' : '' ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * When the cache was last cleared, and why.
	 *
	 * @return string
	 */
	private static function last_cleared_text() {
		$status = get_option( CacheArmor::STATUS_OPTION );
		if ( ! is_array( $status ) || empty( $status['time'] ) ) {
			return __( 'The cache has not been cleared yet.', 'cachearmor' );
		}
		$ago    = human_time_diff( (int) $status['time'], time() );
		$label  = isset( $status['label'] ) ? (string) $status['label'] : '';
		$reason = isset( $status['reason'] ) ? (string) $status['reason'] : '';

		if ( 'post' === $reason && '' !== $label ) {
			/* translators: 1: time since, such as "3 mins", 2: post title. */
			return sprintf( __( 'Last cleared %1$s ago, when the post “%2$s” was published, updated or removed.', 'cachearmor' ), $ago, $label );
		}
		if ( 'post' === $reason ) {
			/* translators: %s: time since, such as "3 mins". */
			return sprintf( __( 'Last cleared %s ago, when a post was published, updated or removed.', 'cachearmor' ), $ago );
		}
		if ( 'term' === $reason ) {
			/* translators: 1: time since, such as "3 mins", 2: taxonomy name, such as "Categories". */
			return sprintf( __( 'Last cleared %1$s ago, when a term in %2$s was added, changed or removed.', 'cachearmor' ), $ago, $label );
		}
		if ( 'settings' === $reason ) {
			/* translators: %s: time since, such as "3 mins". */
			return sprintf( __( 'Last cleared %s ago, when the settings were saved.', 'cachearmor' ), $ago );
		}
		if ( 'deactivate' === $reason ) {
			/* translators: %s: time since, such as "3 mins". */
			return sprintf( __( 'Last cleared %s ago, when the plugin was deactivated.', 'cachearmor' ), $ago );
		}
		/* translators: %s: time since, such as "3 mins". */
		return sprintf( __( 'Last cleared %s ago, by hand.', 'cachearmor' ), $ago );
	}

	/**
	 * Short summary of the last purge for the status card.
	 *
	 * @return array { when: string, why: string }
	 */
	private static function last_cleared_short() {
		$status = get_option( CacheArmor::STATUS_OPTION );
		if ( ! is_array( $status ) || empty( $status['time'] ) ) {
			return array(
				'when' => __( 'Never', 'cachearmor' ),
				'why'  => __( 'Not cleared yet', 'cachearmor' ),
			);
		}
		/* translators: %s: time since, such as "12 mins". */
		$when   = sprintf( __( '%s ago', 'cachearmor' ), human_time_diff( (int) $status['time'], time() ) );
		$label  = isset( $status['label'] ) ? (string) $status['label'] : '';
		$reason = isset( $status['reason'] ) ? (string) $status['reason'] : '';

		if ( 'post' === $reason && '' !== $label ) {
			/* translators: %s: post title. */
			$why = sprintf( __( 'Post “%s” updated', 'cachearmor' ), $label );
		} elseif ( 'post' === $reason ) {
			$why = __( 'A post was updated', 'cachearmor' );
		} elseif ( 'term' === $reason ) {
			/* translators: %s: taxonomy name, such as "Categories". */
			$why = sprintf( __( 'A term in %s changed', 'cachearmor' ), $label );
		} elseif ( 'settings' === $reason ) {
			$why = __( 'Settings saved', 'cachearmor' );
		} elseif ( 'deactivate' === $reason ) {
			$why = __( 'Plugin deactivated', 'cachearmor' );
		} else {
			$why = __( 'Cleared by hand', 'cachearmor' );
		}
		return array(
			'when' => $when,
			'why'  => $why,
		);
	}

	/**
	 * A lifetime in seconds as a short label, such as "15 min" or "1 hour".
	 *
	 * @param int $seconds Lifetime.
	 * @return string
	 */
	private static function duration( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds >= 86400 && 0 === $seconds % 86400 ) {
			$n = $seconds / 86400;
			/* translators: %s: number of days. */
			return sprintf( _n( '%s day', '%s days', $n, 'cachearmor' ), number_format_i18n( $n ) );
		}
		if ( $seconds >= 3600 && 0 === $seconds % 3600 ) {
			$n = $seconds / 3600;
			/* translators: %s: number of hours. */
			return sprintf( _n( '%s hour', '%s hours', $n, 'cachearmor' ), number_format_i18n( $n ) );
		}
		if ( $seconds >= 60 && 0 === $seconds % 60 ) {
			/* translators: %s: number of minutes. */
			return sprintf( __( '%s min', 'cachearmor' ), number_format_i18n( $seconds / 60 ) );
		}
		/* translators: %s: number of seconds. */
		return sprintf( __( '%s s', 'cachearmor' ), number_format_i18n( $seconds ) );
	}

	/**
	 * Strings the settings script needs, kept translatable.
	 *
	 * @return array
	 */
	private static function script_strings() {
		return array(
			/* translators: %s: number of days. */
			'day'      => array( __( '%s day', 'cachearmor' ), __( '%s days', 'cachearmor' ) ),
			/* translators: %s: number of hours. */
			'hour'     => array( __( '%s hour', 'cachearmor' ), __( '%s hours', 'cachearmor' ) ),
			/* translators: %s: number of minutes. */
			'min'      => __( '%s min', 'cachearmor' ),
			/* translators: %s: number of seconds. */
			'sec'      => __( '%s s', 'cachearmor' ),
			'default'  => __( 'default', 'cachearmor' ),
			/* translators: %s: number of unsaved changes. */
			'unsaved'  => array( __( '%s unsaved change', 'cachearmor' ), __( '%s unsaved changes', 'cachearmor' ) ),
			/* translators: 1: routes enabled in a namespace, 2: routes in that namespace. */
			'enabled'  => __( '%1$d of %2$d enabled', 'cachearmor' ),
			'leave'    => __( 'You have unsaved changes.', 'cachearmor' ),
		);
	}

	/**
	 * Inline SVG icons used on the screen. All decorative.
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	private static function icon( $name ) {
		$icons = array(
			'shield'  => '<svg viewBox="0 0 24 24" width="28" height="28"><path fill="#fff" d="M12 2 4 5v6c0 5 3.4 9.3 8 11 4.6-1.7 8-6 8-11V5l-8-3Z"/><path fill="#1d2327" d="M12 4.2 6 6.5V11c0 3.9 2.5 7.3 6 8.8 3.5-1.5 6-4.9 6-8.8V6.5l-6-2.3Z"/><path fill="#fff" d="M12 6.3 8 7.8V11c0 2.8 1.7 5.3 4 6.5 2.3-1.2 4-3.7 4-6.5V7.8l-4-1.5Z"/><circle cx="10.4" cy="10.6" r="1.1" fill="#1d2327"/><circle cx="13.6" cy="10.6" r="1.1" fill="#1d2327"/><path fill="#1d2327" d="M10.6 13.4h2.8l-1.4 1.8z"/></svg>',
			'globe'   => '<svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="10" cy="10" r="7.5"/><path d="M2.5 10h15M10 2.5c2.2 2.2 3.2 4.7 3.2 7.5S12.2 15.3 10 17.5C7.8 15.3 6.8 12.8 6.8 10S7.8 4.7 10 2.5Z"/></svg>',
			'lock'    => '<svg viewBox="0 0 20 20" width="16" height="16"><path fill="currentColor" d="M6 8V6a4 4 0 1 1 8 0v2h1a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1h1Zm2 0h4V6a2 2 0 1 0-4 0v2Z"/></svg>',
			'ban'     => '<svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10" cy="10" r="7"/><path d="m5.1 14.9 9.8-9.8"/></svg>',
			'chevron' => '<svg viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 7.5 5 5 5-5"/></svg>',
			'warn'    => '<svg viewBox="0 0 20 20" width="22" height="22"><circle cx="10" cy="10" r="9" fill="#dba617"/><path fill="#fff" d="M9 5h2v6H9zM9 13h2v2H9z"/></svg>',
		);
		return isset( $icons[ $name ] ) ? '<span class="cachearmor-icon cachearmor-icon-' . esc_attr( $name ) . '" aria-hidden="true">' . $icons[ $name ] . '</span>' : '';
	}

	/**
	 * Why a blocked route can never be cached.
	 *
	 * @param string $route REST route.
	 * @return string
	 */
	private static function blocked_reason( $route ) {
		if ( 0 === strpos( $route, '/wc/store' ) ) {
			return __( 'Varies per shopping session', 'cachearmor' );
		}
		return __( 'Varies per user', 'cachearmor' );
	}

	/**
	 * Render the settings page.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$settings = CacheArmor::settings();
		$routes   = self::discover_routes();

		wp_add_inline_script( 'cachearmor-admin', 'window.cachearmorL10n = ' . wp_json_encode( self::script_strings() ) . ';', 'before' );

		echo '<div class="wrap cachearmor">';
		self::render_header( $settings, $routes );

		// Core moves admin notices to just after this marker.
		echo '<hr class="wp-header-end">';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		if ( isset( $_GET['updated'] ) ) {
			self::notice( 'success', __( 'Settings saved and cache cleared.', 'cachearmor' ), true );
		}
		if ( defined( 'CACHEARMOR_DISABLE' ) && CACHEARMOR_DISABLE ) {
			self::notice( 'warning', __( 'Caching is paused by CACHEARMOR_DISABLE in wp-config.php. Remove that line to resume.', 'cachearmor' ) );
		} elseif ( ! empty( $settings['paused'] ) ) {
			self::notice( 'warning', __( 'Caching is paused. Every request is served fresh until you turn off Pause caching under Settings.', 'cachearmor' ) );
		}

		self::render_status( $settings, $routes );

		echo '<nav class="nav-tab-wrapper cachearmor-tabs" aria-label="' . esc_attr__( 'CacheArmor sections', 'cachearmor' ) . '">';
		echo '<a href="#routes" class="nav-tab nav-tab-active" data-tab="routes">' . esc_html__( 'Routes', 'cachearmor' ) . '</a>';
		echo '<a href="#settings" class="nav-tab" data-tab="settings">' . esc_html__( 'Settings', 'cachearmor' ) . '</a>';
		echo '<a href="#help" class="nav-tab" data-tab="help">' . esc_html__( 'Help', 'cachearmor' ) . '</a>';
		echo '</nav>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="cachearmor-form">';
		wp_nonce_field( self::SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '">';
		echo '<input type="hidden" name="tab" id="cachearmor-tab" value="routes">';

		echo '<section class="cachearmor-panel" id="cachearmor-panel-routes" data-panel="routes">';
		echo '<h2 class="cachearmor-panel-title">' . esc_html__( 'Routes', 'cachearmor' ) . '</h2>';
		if ( empty( $routes ) ) {
			echo '<p>' . esc_html__( 'No REST routes could be detected.', 'cachearmor' ) . '</p>';
		} else {
			self::render_routes( $routes, $settings['routes'], (int) $settings['ttl'] );
		}
		echo '<div class="cachearmor-callout"><p>';
		printf(
			/* translators: %s: the word "Public", in bold. */
			esc_html__( 'Only anonymous GET requests are ever cached. %s means anyone may call the route, not that every visitor gets the same response: enable a route only when its response is identical for every anonymous visitor.', 'cachearmor' ),
			'<strong>' . esc_html__( 'Public', 'cachearmor' ) . '</strong>'
		);
		echo '</p></div>';
		echo '</section>';

		echo '<section class="cachearmor-panel" id="cachearmor-panel-settings" data-panel="settings">';
		echo '<h2 class="cachearmor-panel-title">' . esc_html__( 'Settings', 'cachearmor' ) . '</h2>';
		self::render_settings( $settings );
		echo '</section>';

		echo '<section class="cachearmor-panel" id="cachearmor-panel-help" data-panel="help">';
		echo '<h2 class="cachearmor-panel-title">' . esc_html__( 'Help', 'cachearmor' ) . '</h2>';
		self::render_help();
		echo '</section>';

		self::render_savebar();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Title bar: logo, version, status and the Clear button.
	 *
	 * @param array $settings Saved settings.
	 * @param array $routes   Discovered routes.
	 */
	private static function render_header( $settings, $routes ) {
		if ( CacheArmor::is_paused() ) {
			$state = 'paused';
			$label = __( 'Caching paused', 'cachearmor' );
		} elseif ( ! CacheArmor::rules() ) {
			$state = 'idle';
			$label = __( 'No routes enabled', 'cachearmor' );
		} else {
			$state = 'active';
			$label = __( 'Caching active', 'cachearmor' );
		}

		echo '<div class="cachearmor-header">';
		echo '<span class="cachearmor-logo">' . self::icon( 'shield' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
		// The product name is not translated; the accent on "Armor" is decoration.
		echo '<h1 class="cachearmor-title">Cache<span>Armor</span></h1>';
		echo '<span class="cachearmor-version">' . esc_html( CACHEARMOR_VERSION ) . '</span>';
		echo '<span class="cachearmor-pill is-' . esc_attr( $state ) . '"><span class="cachearmor-dot" aria-hidden="true"></span>' . esc_html( $label ) . '</span>';

		echo '<div class="cachearmor-header-actions">';
		echo '<a href="#help" data-tab="help" class="cachearmor-help-link">' . esc_html__( 'Help & filters', 'cachearmor' ) . '</a>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::PURGE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::PURGE ) . '">';
		echo '<button type="submit" class="button cachearmor-button-outline">' . esc_html__( 'Clear cache now', 'cachearmor' ) . '</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Usage bar for a status card.
	 *
	 * @param int $value Current value.
	 * @param int $max   Maximum.
	 */
	private static function meter( $value, $max ) {
		$pct = $max > 0 ? min( 100, max( 0, round( $value / $max * 100, 1 ) ) ) : 0;
		// A sliver stays visible so an almost empty cache still shows a bar.
		if ( $value > 0 && $pct < 1 ) {
			$pct = 1;
		}
		echo '<div class="cachearmor-meter' . ( $pct >= 100 ? ' is-full' : '' ) . '" aria-hidden="true"><span style="width:' . esc_attr( $pct ) . '%"></span></div>';
	}

	/**
	 * The four status cards.
	 *
	 * @param array $settings Saved settings.
	 * @param array $routes   Discovered routes.
	 */
	private static function render_status( $settings, $routes ) {
		$stats   = self::stats();
		$enabled = 0;
		foreach ( $settings['routes'] as $route => $conf ) {
			if ( ! empty( $conf['enabled'] ) && isset( $routes[ $route ] ) && ! $routes[ $route ]['blocked'] ) {
				++$enabled;
			}
		}
		$cleared = self::last_cleared_short();

		echo '<div class="cachearmor-cards">';

		echo '<div class="cachearmor-card"><h2>' . esc_html__( 'Cached responses', 'cachearmor' ) . '</h2>';
		echo '<p class="cachearmor-figure"><strong>' . esc_html( number_format_i18n( $stats['count'] ) ) . '</strong> ';
		/* translators: %s: maximum number of cached responses. */
		echo '<span>' . esc_html( sprintf( __( 'of %s', 'cachearmor' ), number_format_i18n( $stats['max_count'] ) ) ) . '</span></p>';
		self::meter( $stats['count'], $stats['max_count'] );
		echo '</div>';

		echo '<div class="cachearmor-card"><h2>' . esc_html__( 'Size on disk', 'cachearmor' ) . '</h2>';
		echo '<p class="cachearmor-figure"><strong>' . esc_html( size_format( $stats['bytes'] ? $stats['bytes'] : 0 ) ) . '</strong> ';
		/* translators: %s: maximum cache size, such as "256 MB". */
		echo '<span>' . esc_html( sprintf( __( 'of %s', 'cachearmor' ), size_format( $stats['max_bytes'] ) ) ) . '</span></p>';
		self::meter( $stats['bytes'], $stats['max_bytes'] );
		echo '</div>';

		echo '<div class="cachearmor-card"><h2>' . esc_html__( 'Routes cached', 'cachearmor' ) . '</h2>';
		echo '<p class="cachearmor-figure"><strong>' . esc_html( number_format_i18n( $enabled ) ) . '</strong> ';
		/* translators: %s: number of REST routes detected on the site. */
		echo '<span>' . esc_html( sprintf( __( 'of %s detected', 'cachearmor' ), number_format_i18n( count( $routes ) ) ) ) . '</span></p>';
		/* translators: %s: default lifetime, such as "5 min". */
		echo '<p class="cachearmor-sub">' . esc_html( sprintf( __( 'Default lifetime %s', 'cachearmor' ), self::duration( (int) $settings['ttl'] ) ) ) . '</p>';
		echo '</div>';

		echo '<div class="cachearmor-card" title="' . esc_attr( self::last_cleared_text() ) . '"><h2>' . esc_html__( 'Last cleared', 'cachearmor' ) . '</h2>';
		echo '<p class="cachearmor-figure"><strong>' . esc_html( $cleared['when'] ) . '</strong></p>';
		echo '<p class="cachearmor-sub">' . esc_html( $cleared['why'] ) . '</p>';
		echo '</div>';

		echo '</div>';

		if ( $stats['full'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The cache is full, so new responses are not being stored until it is next cleared. Narrow your query filters, or raise max_entries or max_total_bytes with the cachearmor_config filter.', 'cachearmor' ) . '</p></div>';
		}
	}

	/**
	 * Pause toggle, default lifetime and where the cache lives.
	 *
	 * @param array $settings Saved settings.
	 */
	private static function render_settings( $settings ) {
		$ttl   = (int) $settings['ttl'];
		$stats = self::stats();

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Caching', 'cachearmor' ) . '</th><td>';
		echo '<label class="cachearmor-switch-label"><span class="cachearmor-switch"><input type="checkbox" role="switch" name="paused" value="1"' . checked( ! empty( $settings['paused'] ), true, false ) . '><span class="cachearmor-track" aria-hidden="true"></span></span> ' . esc_html__( 'Pause caching', 'cachearmor' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Serves every request fresh without losing your route selection.', 'cachearmor' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="cachearmor-default-ttl">' . esc_html__( 'Default lifetime', 'cachearmor' ) . '</label></th><td>';
		echo '<span class="cachearmor-ttl-wrap"><input type="number" id="cachearmor-default-ttl" name="default_ttl" min="10" max="604800" list="cachearmor-ttl-presets" class="cachearmor-ttl" value="' . esc_attr( $ttl ) . '"> ';
		echo '<span class="cachearmor-ttl-label">' . esc_html( self::duration( $ttl ) ) . '</span></span>';
		echo '<p class="description">' . esc_html__( 'In seconds. Used by every route without a lifetime of its own. Once it passes, the stale copy keeps being served while one request refreshes it.', 'cachearmor' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Cache folder', 'cachearmor' ) . '</th><td>';
		echo '<code>' . esc_html( $stats['dir'] ) . '</code>';
		echo '<p class="description">' . esc_html__( 'Change it with the dir option of the cachearmor_config filter.', 'cachearmor' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<datalist id="cachearmor-ttl-presets">';
		foreach ( self::PRESETS as $seconds ) {
			echo '<option value="' . esc_attr( $seconds ) . '" label="' . esc_attr( self::duration( $seconds ) ) . '"></option>';
		}
		echo '</datalist>';
	}

	/**
	 * Short reference for the Help tab.
	 */
	private static function render_help() {
		echo '<div class="cachearmor-help">';

		echo '<h3>' . esc_html__( 'How it works', 'cachearmor' ) . '</h3>';
		echo '<p>' . esc_html__( 'Nothing is cached until you enable a route. Only anonymous GET requests that return 200 are stored. When an entry expires it keeps being served while a single request refreshes it, so visitors are never queued behind the refresh.', 'cachearmor' ) . '</p>';
		echo '<p>' . esc_html__( 'Leave "Only when query matches" empty to cache every variation of a route. Each distinct URL is stored separately, up to the limits shown above.', 'cachearmor' ) . '</p>';

		echo '<h3>' . esc_html__( 'Checking a response', 'cachearmor' ) . '</h3>';
		echo '<p>' . wp_kses( __( 'Responses carry an <code>X-CacheArmor</code> header with HIT, STALE or MISS, plus <code>X-CacheArmor-Age</code> in seconds.', 'cachearmor' ), array( 'code' => array() ) ) . '</p>';

		echo '<h3>' . esc_html__( 'Filters', 'cachearmor' ) . '</h3>';
		echo '<dl class="cachearmor-filters">';
		$filters = array(
			'cachearmor_rules'          => __( 'Add cache rules in code, including routes with placeholders that cannot be picked here.', 'cachearmor' ),
			'cachearmor_config'         => __( 'Change ttl, grace, lock_ttl, cold_wait, max_bytes, max_entries, max_total_bytes, send_headers and dir.', 'cachearmor' ),
			'cachearmor_blocked_routes' => __( 'Add route prefixes that may never be cached.', 'cachearmor' ),
			'cachearmor_bypass_request' => __( 'Skip the cache for individual requests.', 'cachearmor' ),
			'cachearmor_should_purge'   => __( 'Decide which content changes clear the cache.', 'cachearmor' ),
		);
		foreach ( $filters as $name => $desc ) {
			echo '<dt><code>' . esc_html( $name ) . '</code></dt><dd>' . esc_html( $desc ) . '</dd>';
		}
		echo '</dl>';

		echo '<h3>' . esc_html__( 'Pausing from code', 'cachearmor' ) . '</h3>';
		echo '<p>' . wp_kses( __( 'Add <code>define( \'CACHEARMOR_DISABLE\', true );</code> to wp-config.php.', 'cachearmor' ), array( 'code' => array() ) ) . '</p>';

		echo '</div>';
	}

	/**
	 * Sticky bar with the unsaved-changes count and the Save button.
	 */
	private static function render_savebar() {
		echo '<div class="cachearmor-savebar">';
		echo '<span class="cachearmor-unsaved" id="cachearmor-unsaved" hidden>' . self::icon( 'warn' ) . '<strong id="cachearmor-unsaved-count"></strong></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
		echo '<span class="cachearmor-savebar-note">' . esc_html__( 'Saving clears the whole cache.', 'cachearmor' ) . '</span>';
		echo '<span class="cachearmor-savebar-actions">';
		echo '<button type="button" class="button cachearmor-button-outline" id="cachearmor-discard" hidden>' . esc_html__( 'Discard', 'cachearmor' ) . '</button>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save settings', 'cachearmor' ) . '</button>';
		echo '</span>';
		echo '</div>';
	}

	/**
	 * The route filter bar and table.
	 *
	 * @param array $routes      Discovered routes.
	 * @param array $enabled     Saved route selection.
	 * @param int   $default_ttl Default lifetime, shown as a placeholder.
	 */
	private static function render_routes( $routes, $enabled, $default_ttl ) {
		// Hidden until the script runs: without JavaScript it would do nothing.
		echo '<div id="cachearmor-toolbar" class="cachearmor-toolbar" hidden>';
		echo '<label class="screen-reader-text" for="cachearmor-filter">' . esc_html__( 'Filter routes', 'cachearmor' ) . '</label>';
		echo '<input type="search" id="cachearmor-filter" placeholder="' . esc_attr__( 'Filter routes…', 'cachearmor' ) . '">';
		echo '<label class="screen-reader-text" for="cachearmor-access">' . esc_html__( 'Filter by access', 'cachearmor' ) . '</label>';
		echo '<select id="cachearmor-access">';
		echo '<option value="">' . esc_html__( 'All access types', 'cachearmor' ) . '</option>';
		echo '<option value="public">' . esc_html__( 'Public', 'cachearmor' ) . '</option>';
		echo '<option value="check">' . esc_html__( 'Permission check', 'cachearmor' ) . '</option>';
		echo '<option value="blocked">' . esc_html__( 'Not cacheable', 'cachearmor' ) . '</option>';
		echo '</select>';
		echo '<label><input type="checkbox" id="cachearmor-enabled-only"> ' . esc_html__( 'Show enabled only', 'cachearmor' ) . '</label>';
		/* translators: 1: number of routes shown, 2: total number of routes. */
		echo '<span id="cachearmor-count" class="cachearmor-count" aria-live="polite" data-template="' . esc_attr__( '%1$d of %2$d routes shown', 'cachearmor' ) . '"></span>';
		echo '</div>';

		// Group by namespace first so every namespace gets one tbody.
		$groups = array();
		foreach ( $routes as $route => $info ) {
			$groups[ $info['namespace'] ][ $route ] = $info;
		}

		echo '<table id="cachearmor-routes" class="cachearmor-table">';
		echo '<thead><tr>';
		echo '<th scope="col" class="cachearmor-col-cache">' . esc_html__( 'Cache', 'cachearmor' ) . '</th>';
		echo '<th scope="col" class="cachearmor-col-route">' . esc_html__( 'Route', 'cachearmor' ) . '</th>';
		echo '<th scope="col" class="cachearmor-col-access">' . esc_html__( 'Access', 'cachearmor' ) . '</th>';
		echo '<th scope="col" class="cachearmor-col-query">' . esc_html__( 'Only when query matches', 'cachearmor' ) . '</th>';
		echo '<th scope="col" class="cachearmor-col-ttl">' . esc_html__( 'Lifetime', 'cachearmor' ) . '</th>';
		echo '</tr></thead>';

		$default_label = __( 'default', 'cachearmor' );
		foreach ( $groups as $namespace => $members ) {
			$on = 0;
			foreach ( $members as $route => $info ) {
				if ( ! $info['blocked'] && ! empty( $enabled[ $route ]['enabled'] ) ) {
					++$on;
				}
			}

			echo '<tbody class="cachearmor-group">';
			echo '<tr class="cachearmor-ns"><th colspan="5" scope="rowgroup">';
			// Name and count share one button so the whole header toggles, and the
			// count starts at the same offset in every group.
			echo '<button type="button" class="cachearmor-ns-toggle" aria-expanded="true">' . self::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon is static markup.
			echo '<span class="cachearmor-ns-name">' . esc_html( $namespace ) . '</span>';
			/* translators: 1: routes enabled in a namespace, 2: routes in that namespace. */
			echo '<span class="cachearmor-ns-count">' . esc_html( sprintf( __( '%1$d of %2$d enabled', 'cachearmor' ), $on, count( $members ) ) ) . '</span>';
			echo '</button>';
			echo '</th></tr>';

			foreach ( $members as $route => $info ) {
				$conf  = isset( $enabled[ $route ] ) && is_array( $enabled[ $route ] ) ? $enabled[ $route ] : array();
				$id    = 'cachearmor-route-' . md5( $route );
				$field = 'routes[' . $route . ']';

				if ( $info['blocked'] ) {
					echo '<tr class="cachearmor-row is-blocked" data-route="' . esc_attr( $route ) . '" data-access="blocked">';
					echo '<td>' . self::icon( 'lock' ) . '<span class="screen-reader-text">' . esc_html__( 'Cannot be enabled', 'cachearmor' ) . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon is static markup.
					echo '<td><code>' . esc_html( $route ) . '</code></td>';
					echo '<td><span class="cachearmor-badge is-blocked" title="' . esc_attr__( 'Responses from this route differ per user or per shopping session, so it can never be cached.', 'cachearmor' ) . '">' . self::icon( 'ban' ) . esc_html__( 'Not cacheable', 'cachearmor' ) . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon is static markup.
					echo '<td colspan="2" class="cachearmor-reason">' . esc_html( self::blocked_reason( $route ) ) . '</td>';
					echo '</tr>';
					continue;
				}

				$is_on  = ! empty( $conf['enabled'] );
				$ttl    = isset( $conf['ttl'] ) ? (int) $conf['ttl'] : 0;
				$access = $info['checked'] ? 'check' : 'public';

				echo '<tr class="cachearmor-row' . ( $is_on ? ' is-enabled' : '' ) . '" data-route="' . esc_attr( $route ) . '" data-access="' . esc_attr( $access ) . '">';

				echo '<td><span class="cachearmor-switch"><input type="checkbox" role="switch" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '[enabled]" value="1"' . checked( $is_on, true, false ) . '><span class="cachearmor-track" aria-hidden="true"></span></span></td>';
				echo '<td><label for="' . esc_attr( $id ) . '"><code>' . esc_html( $route ) . '</code></label></td>';

				if ( $info['checked'] ) {
					echo '<td><span class="cachearmor-badge is-check">' . self::icon( 'lock' ) . esc_html__( 'Permission check', 'cachearmor' ) . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon is static markup.
				} else {
					echo '<td><span class="cachearmor-access">' . self::icon( 'globe' ) . esc_html__( 'Public', 'cachearmor' ) . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon is static markup.
				}

				/* translators: %s: REST route, such as /wp/v2/pages. */
				$query_label = sprintf( __( 'Query filter for %s', 'cachearmor' ), $route );
				echo '<td><span class="cachearmor-off" aria-hidden="true">&#8212;</span>';
				echo '<input type="text" class="cachearmor-field cachearmor-query" name="' . esc_attr( $field ) . '[query]" value="' . esc_attr( isset( $conf['query'] ) ? $conf['query'] : '' ) . '" placeholder="' . esc_attr__( 'any query', 'cachearmor' ) . '" aria-label="' . esc_attr( $query_label ) . '"></td>';

				/* translators: %s: REST route, such as /wp/v2/pages. */
				$ttl_label = sprintf( __( 'Lifetime in seconds for %s', 'cachearmor' ), $route );
				echo '<td><span class="cachearmor-off" aria-hidden="true">&#8212;</span>';
				echo '<span class="cachearmor-field cachearmor-ttl-wrap"><input type="number" name="' . esc_attr( $field ) . '[ttl]" min="10" max="604800" list="cachearmor-ttl-presets" class="cachearmor-ttl" value="' . esc_attr( $ttl ? $ttl : '' ) . '" placeholder="' . esc_attr( $default_ttl ) . '" aria-label="' . esc_attr( $ttl_label ) . '"> ';
				echo '<span class="cachearmor-ttl-label">' . esc_html( $ttl ? self::duration( $ttl ) : $default_label ) . '</span></span></td>';

				echo '</tr>';
			}
			echo '</tbody>';
		}
		echo '</table>';
	}
}
