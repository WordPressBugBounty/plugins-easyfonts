<?php
/**
 * Uninstall — remove plugin data only when the user opted in.
 *
 * Per WordPress.org guidance, user data is preserved by default. Data is only
 * removed when Settings → "Remove all data on uninstall" was enabled (the
 * `remove_data_on_uninstall` flag in the easyfonts_settings option).
 *
 * On multisite, this runs per-site for each site the plugin is removed from.
 *
 * @package EasyFonts
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Should we wipe everything for the current site?
 *
 * @return bool
 */
function easyfonts_should_remove_data(): bool {
	$settings = get_option( 'easyfonts_settings' );

	return is_array( $settings ) && ! empty( $settings['remove_data_on_uninstall'] );
}

/**
 * Remove every trace of the plugin for the current site.
 */
function easyfonts_purge_site(): void {
	global $wpdb;

	// Always clear scheduled events (cheap, avoids orphaned cron).
	wp_clear_scheduled_hook( 'easyfonts_cleanup' );

	if ( ! easyfonts_should_remove_data() ) {
		return;
	}

	// Drop tables.
	foreach ( array( 'easyfonts_fonts', 'easyfonts_usage', 'easyfonts_decisions' ) as $table ) {
		$name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
	}

	// Delete options + transients.
	foreach (
		array(
			'easyfonts_settings',
			'easyfonts_db_version',
			'easyfonts_cache_buster',
			'easyfonts_activated_at',
			'easyfonts_processed_local',
			'easyfonts_processed_external',
			'easyfonts_last_beacon',
			'easyfonts_global_preload',
			'easyfonts_async_urls',
		) as $option
	) {
		delete_option( $option );
	}

	delete_transient( 'easyfonts_schema_checked' );
	delete_transient( 'easyfonts_metrics_lock' );

	// Purge the cache directory.
	$upload = wp_upload_dir();
	$dir    = trailingslashit( $upload['basedir'] ) . 'easyfonts';

	if ( is_dir( $dir ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions
			$file->isDir() ? @rmdir( $file->getRealPath() ) : @unlink( $file->getRealPath() );
			// phpcs:enable
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

// Run for every site on multisite; otherwise just the current site.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		easyfonts_purge_site();
		restore_current_blog();
	}
} else {
	easyfonts_purge_site();
}
