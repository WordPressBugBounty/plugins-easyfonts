<?php
/**
 * Diagnostics payload for support.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Admin;

use EasyFonts\Database\Migrator;
use EasyFonts\Fonts\Registry;
use EasyFonts\Fonts\Storage;
use EasyFonts\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds an at-a-glance diagnostics report.
 */
class Diagnostics {

	/**
	 * Collect diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public static function collect(): array {
		global $wp_version;

		$registry = new Registry();
		$storage  = new Storage();
		$stats    = $registry->stats();
		$migrator = new Migrator();

		return array(
			'plugin'      => array(
				'version'      => EASYFONTS_VERSION,
				'db_version'   => get_option( 'easyfonts_db_version', '' ),
				'cache_dir'    => EASYFONTS_CACHE_DIR,
				'cache_writable' => is_writable( dirname( EASYFONTS_CACHE_DIR ) ),
			),
			'environment' => array(
				'wp'           => $wp_version,
				'php'          => PHP_VERSION,
				'has_html_api' => class_exists( 'WP_HTML_Tag_Processor' ),
				'brotli'       => function_exists( 'brotli_uncompress' ),
				'is_multisite' => is_multisite(),
				'ssl'          => is_ssl(),
			),
			'tables'      => $migrator->status(),
			'tables_ok'   => $migrator->tables_ok(),
			'last_db_error' => Registry::last_error(),
			'settings'    => Settings::all(),
			'detectors'   => array(
				'learned' => (array) Settings::get( 'detectors', array() ),
			),
			'cache'       => array(
				'families'   => $stats['families'],
				'variants'   => $stats['variants'],
				'hosted_kb'  => (int) round( $stats['bytes'] / 1024 ),
				'total_kb'   => (int) round( $storage->total_size() / 1024 ),
			),
			'generated'   => current_time( 'mysql' ),
		);
	}
}
