<?php
/**
 * Removes CacheArmor's options and cache files when the plugin is deleted.
 *
 * @package CacheArmor
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove the options and cache directory of the current site.
 *
 * Only names CacheArmor itself writes are deleted, and the directory is
 * removed only once empty, so a misconfigured 'dir' can never cost anything
 * else.
 */
function cachearmor_uninstall_site() {
	delete_option( 'cachearmor_settings' );
	delete_option( 'cachearmor_status' );

	$config = apply_filters( 'cachearmor_config', array( 'dir' => '' ) );
	if ( is_array( $config ) && ! empty( $config['dir'] ) ) {
		$dir = untrailingslashit( (string) $config['dir'] ) . '/' . get_current_blog_id();
	} else {
		$uploads = wp_upload_dir( null, false );
		$dir     = untrailingslashit( $uploads['basedir'] ) . '/cachearmor';
	}
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$files = scandir( $dir );
	foreach ( is_array( $files ) ? $files : array() as $file ) {
		if ( in_array( $file, array( 'index.html', '.htaccess' ), true )
			|| preg_match( '/^[0-9a-f]{32}\.(json|lock|json\..+\.tmp)$/', $file ) ) {
			wp_delete_file( $dir . '/' . $file );
		}
	}
	$left = scandir( $dir );
	if ( is_array( $left ) && 2 === count( $left ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes the plugin's own, now empty, cache directory.
		rmdir( $dir );
	}
}

if ( is_multisite() ) {
	$cachearmor_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $cachearmor_sites as $cachearmor_site_id ) {
		switch_to_blog( $cachearmor_site_id );
		cachearmor_uninstall_site();
		restore_current_blog();
	}
} else {
	cachearmor_uninstall_site();
}
