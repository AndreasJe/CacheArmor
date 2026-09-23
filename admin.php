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

			$namespace = trim( (string) strstr( ltrim( $route, '/' ), '/', true ) );
			if ( '' === $namespace ) {
				$namespace = ltrim( $route, '/' );
			}

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

		wp_safe_redirect( self::settings_url( array( 'updated' => '1' ) ) );
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
	 * Render the settings page.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$settings = CacheArmor::settings();
		$routes   = self::discover_routes();

		echo '<div class="wrap cachearmor">';
		echo '<h1>' . esc_html__( 'CacheArmor', 'cachearmor' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		if ( isset( $_GET['updated'] ) ) {
			self::notice( 'success', __( 'Settings saved and cache cleared.', 'cachearmor' ), true );
		}
		if ( defined( 'CACHEARMOR_DISABLE' ) && CACHEARMOR_DISABLE ) {
			self::notice( 'warning', __( 'Caching is paused by CACHEARMOR_DISABLE in wp-config.php. Remove that line to resume.', 'cachearmor' ) );
		} elseif ( ! empty( $settings['paused'] ) ) {
			self::notice( 'warning', __( 'Caching is paused. Every request is served fresh until you untick Pause caching below.', 'cachearmor' ) );
		}

		echo '<p>' . esc_html__( 'Choose which REST API routes to cache. Only anonymous GET requests are ever cached, and nothing is cached until you enable a route.', 'cachearmor' ) . '</p>';

		self::render_status();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '">';

		self::render_settings( $settings );

		echo '<h2>' . esc_html__( 'Routes', 'cachearmor' ) . '</h2>';
		if ( empty( $routes ) ) {
			echo '<p>' . esc_html__( 'No REST routes could be detected.', 'cachearmor' ) . '</p>';
		} else {
			self::render_routes( $routes, $settings['routes'], (int) $settings['ttl'] );
		}

		echo '<p class="description">' . esc_html__( '"No permission check" means anyone may call the route. It does not mean every visitor gets the same response: a route can still vary by cookie, session or location. Enable a route only when you are sure its response is identical for every anonymous visitor.', 'cachearmor' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Leave "Only when query matches" empty to cache every variation of a route. Each distinct URL is stored separately, up to the limit shown under Status.', 'cachearmor' ) . '</p>';

		submit_button( __( 'Save settings', 'cachearmor' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Cache usage, the last purge, and the Clear button.
	 */
	private static function render_status() {
		$stats = self::stats();

		echo '<h2>' . esc_html__( 'Status', 'cachearmor' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: cached responses, 2: maximum responses, 3: size on disk, 4: maximum size. */
				__( 'Cached responses: %1$s of %2$s (%3$s of %4$s on disk)', 'cachearmor' ),
				number_format_i18n( $stats['count'] ),
				number_format_i18n( $stats['max_count'] ),
				size_format( $stats['bytes'] ),
				size_format( $stats['max_bytes'] )
			)
		) . '</p>';

		if ( $stats['full'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The cache is full, so new responses are not being stored until it is next cleared. Narrow your query filters, or raise max_entries or max_total_bytes with the cachearmor_config filter.', 'cachearmor' ) . '</p></div>';
		}

		echo '<p>' . esc_html( self::last_cleared_text() ) . '</p>';
		echo '<p><code>' . esc_html( $stats['dir'] ) . '</code></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::PURGE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::PURGE ) . '">';
		submit_button( __( 'Clear cache now', 'cachearmor' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Pause toggle and default lifetime.
	 *
	 * @param array $settings Saved settings.
	 */
	private static function render_settings( $settings ) {
		$ttl = (int) $settings['ttl'];

		echo '<h2>' . esc_html__( 'Settings', 'cachearmor' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Caching', 'cachearmor' ) . '</th><td>';
		echo '<label><input type="checkbox" name="paused" value="1"' . checked( ! empty( $settings['paused'] ), true, false ) . '> ' . esc_html__( 'Pause caching', 'cachearmor' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Serves every request fresh without losing your route selection.', 'cachearmor' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="cachearmor-default-ttl">' . esc_html__( 'Default lifetime', 'cachearmor' ) . '</label></th><td>';
		echo '<input type="number" id="cachearmor-default-ttl" name="default_ttl" min="10" max="604800" list="cachearmor-ttl-presets" class="cachearmor-ttl" value="' . esc_attr( $ttl ) . '"> ';
		echo esc_html__( 'seconds', 'cachearmor' ) . ' <span class="description">(' . esc_html( human_time_diff( 0, $ttl ) ) . ')</span>';
		echo '<p class="description">' . esc_html__( 'Used by every route without a lifetime of its own. Once it passes, the stale copy keeps being served while one request refreshes it.', 'cachearmor' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<datalist id="cachearmor-ttl-presets">';
		foreach ( self::PRESETS as $seconds ) {
			echo '<option value="' . esc_attr( $seconds ) . '" label="' . esc_attr( human_time_diff( 0, $seconds ) ) . '"></option>';
		}
		echo '</datalist>';
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
		echo '<label><input type="checkbox" id="cachearmor-enabled-only"> ' . esc_html__( 'Show enabled only', 'cachearmor' ) . '</label>';
		/* translators: 1: number of routes shown, 2: total number of routes. */
		echo '<span id="cachearmor-count" class="cachearmor-count" aria-live="polite" data-template="' . esc_attr__( '%1$d of %2$d routes shown', 'cachearmor' ) . '"></span>';
		echo '</div>';

		echo '<table id="cachearmor-routes" class="widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col" class="cachearmor-col-cache">' . esc_html__( 'Cache', 'cachearmor' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Route', 'cachearmor' ) . '</th>';
		echo '<th scope="col" class="cachearmor-col-access">' . esc_html__( 'Access', 'cachearmor' ) . '</th>';
		echo '<th scope="col" class="cachearmor-col-query">' . esc_html__( 'Only when query matches', 'cachearmor' ) . '</th>';
		echo '<th scope="col" class="cachearmor-col-ttl">' . esc_html__( 'Lifetime (s)', 'cachearmor' ) . '</th>';
		echo '</tr></thead><tbody>';

		$current_ns = null;
		foreach ( $routes as $route => $info ) {
			if ( $info['namespace'] !== $current_ns ) {
				$current_ns = $info['namespace'];
				echo '<tr class="cachearmor-ns"><td colspan="5">' . esc_html( $current_ns ) . '</td></tr>';
			}
			$conf  = isset( $enabled[ $route ] ) && is_array( $enabled[ $route ] ) ? $enabled[ $route ] : array();
			$id    = 'cachearmor-route-' . md5( $route );
			$field = 'routes[' . $route . ']';

			echo '<tr data-route="' . esc_attr( $route ) . '">';

			if ( $info['blocked'] ) {
				echo '<td><span aria-hidden="true">&#8212;</span></td>';
				echo '<td><code>' . esc_html( $route ) . '</code></td>';
				echo '<td><span class="cachearmor-blocked" title="' . esc_attr__( 'Responses from this route differ per user or per shopping session, so it can never be cached.', 'cachearmor' ) . '">' . esc_html__( 'Not cacheable', 'cachearmor' ) . '</span></td>';
				echo '<td></td><td></td></tr>';
				continue;
			}

			echo '<td><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '[enabled]" value="1"' . checked( ! empty( $conf['enabled'] ), true, false ) . '></td>';
			echo '<td><label for="' . esc_attr( $id ) . '"><code>' . esc_html( $route ) . '</code></label></td>';

			echo '<td>';
			if ( $info['checked'] ) {
				echo '<span class="cachearmor-auth">' . esc_html__( 'Permission check', 'cachearmor' ) . '</span>';
			} else {
				echo esc_html__( 'No permission check', 'cachearmor' );
			}
			echo '</td>';

			/* translators: %s: REST route, such as /wp/v2/pages. */
			$query_label = sprintf( __( 'Query filter for %s', 'cachearmor' ), $route );
			echo '<td><input type="text" name="' . esc_attr( $field ) . '[query]" value="' . esc_attr( isset( $conf['query'] ) ? $conf['query'] : '' ) . '" placeholder="categories=42" aria-label="' . esc_attr( $query_label ) . '"></td>';

			/* translators: %s: REST route, such as /wp/v2/pages. */
			$ttl_label = sprintf( __( 'Lifetime in seconds for %s', 'cachearmor' ), $route );
			echo '<td><input type="number" name="' . esc_attr( $field ) . '[ttl]" min="10" max="604800" list="cachearmor-ttl-presets" class="cachearmor-ttl" value="' . esc_attr( isset( $conf['ttl'] ) ? $conf['ttl'] : '' ) . '" placeholder="' . esc_attr( $default_ttl ) . '" aria-label="' . esc_attr( $ttl_label ) . '"></td>';

			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
