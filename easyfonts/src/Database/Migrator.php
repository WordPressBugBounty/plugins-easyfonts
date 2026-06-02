<?php
/**
 * Database installation, schema, and self-healing repair.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, versions, verifies, and repairs the plugin's tables.
 */
class Migrator {

	const DB_VERSION = '2.0.0';

	/**
	 * Install, upgrade, or repair tables. Safe to call on every load.
	 *
	 * Verifies the real schema (column presence), not just the version option,
	 * so a site left with an incompatible legacy schema heals automatically.
	 */
	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tables_ok = $this->tables_ok();

		// Up to date and intact → nothing to do.
		if ( $tables_ok && get_option( 'easyfonts_db_version' ) === self::DB_VERSION ) {
			return;
		}

		// An incompatible legacy schema is present → drop the empty legacy
		// tables so we can create the correct ones. Scoped to legacy schema only.
		if ( $this->has_legacy_schema() ) {
			$this->drop_tables();
		}

		$this->create_tables();

		if ( false === get_option( 'easyfonts_settings' ) ) {
			add_option( 'easyfonts_settings', self::default_settings(), '', false );
		}

		// Only record the version once the schema is verified correct.
		if ( $this->tables_ok() ) {
			update_option( 'easyfonts_db_version', self::DB_VERSION, false );
		}

		if ( false === get_option( 'easyfonts_cache_buster' ) ) {
			update_option( 'easyfonts_cache_buster', time(), false );
		}
	}

	/**
	 * Force a clean rebuild (drop + recreate). Used by the repair action / CLI.
	 */
	public function repair(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$this->drop_tables();
		$this->create_tables();

		if ( $this->tables_ok() ) {
			update_option( 'easyfonts_db_version', self::DB_VERSION, false );
		}
	}

	/**
	 * Create (or, via dbDelta, reconcile) the three tables. Timestamps are set
	 * from PHP, so no DB-side DEFAULT CURRENT_TIMESTAMP is required (portable
	 * across MySQL/MariaDB versions and strict sql_modes).
	 */
	private function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$fonts   = $wpdb->prefix . 'easyfonts_fonts';
		$usage   = $wpdb->prefix . 'easyfonts_usage';
		$dec     = $wpdb->prefix . 'easyfonts_decisions';

		dbDelta(
			"CREATE TABLE {$fonts} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				family VARCHAR(190) NOT NULL,
				weight VARCHAR(40) NOT NULL DEFAULT '400',
				style VARCHAR(20) NOT NULL DEFAULT 'normal',
				subset VARCHAR(60) NOT NULL DEFAULT 'latin',
				variant_key VARCHAR(120) NOT NULL,
				is_variable TINYINT(1) NOT NULL DEFAULT 0,
				is_enabled TINYINT(1) NOT NULL DEFAULT 1,
				is_preloaded TINYINT(1) NOT NULL DEFAULT 0,
				load_user_set TINYINT(1) NOT NULL DEFAULT 0,
				preload_user_set TINYINT(1) NOT NULL DEFAULT 0,
				provider VARCHAR(40) NOT NULL DEFAULT 'google',
				css_file VARCHAR(190) DEFAULT NULL,
				font_file VARCHAR(190) DEFAULT NULL,
				file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
				metrics LONGTEXT DEFAULT NULL,
				source_url TEXT,
				created_at DATETIME DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_variant (variant_key),
				KEY idx_family (family)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$usage} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				usage_key CHAR(40) NOT NULL,
				page_url VARCHAR(500) NOT NULL,
				page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				family VARCHAR(190) NOT NULL,
				weight VARCHAR(40) NOT NULL DEFAULT '400',
				style VARCHAR(20) NOT NULL DEFAULT 'normal',
				origin VARCHAR(30) NOT NULL DEFAULT 'buffer',
				rendered TINYINT(1) NOT NULL DEFAULT 0,
				above_fold TINYINT(1) NOT NULL DEFAULT 0,
				hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
				last_seen DATETIME DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_usage (usage_key),
				KEY idx_family (family),
				KEY idx_page (page_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$dec} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				route_key CHAR(40) NOT NULL,
				route VARCHAR(500) NOT NULL,
				device VARCHAR(10) NOT NULL DEFAULT 'any',
				preload LONGTEXT DEFAULT NULL,
				unload LONGTEXT DEFAULT NULL,
				updated_at DATETIME DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_route (route_key)
			) {$charset};"
		);
	}

	/**
	 * Drop all three tables (IF EXISTS).
	 */
	private function drop_tables(): void {
		global $wpdb;

		foreach ( array( 'easyfonts_fonts', 'easyfonts_usage', 'easyfonts_decisions' ) as $name ) {
			$table = $wpdb->prefix . $name;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	/**
	 * Are all three tables present with the current signature columns?
	 *
	 * @return bool
	 */
	public function tables_ok(): bool {
		return $this->column_exists( 'easyfonts_fonts', 'variant_key' )
			&& $this->column_exists( 'easyfonts_fonts', 'is_enabled' )
			&& $this->column_exists( 'easyfonts_fonts', 'load_user_set' )
			&& $this->column_exists( 'easyfonts_fonts', 'preload_user_set' )
			&& $this->column_exists( 'easyfonts_usage', 'usage_key' )
			&& $this->column_exists( 'easyfonts_decisions', 'route_key' );
	}

	/**
	 * Is a legacy fonts table present? Detected by an old column that
	 * the current schema never uses, alongside the absence of its signature column.
	 *
	 * @return bool
	 */
	public function has_legacy_schema(): bool {
		if ( ! $this->table_exists( 'easyfonts_fonts' ) ) {
			return false;
		}

		$has_v3     = $this->column_exists( 'easyfonts_fonts', 'variant_key' );
		$has_legacy = $this->column_exists( 'easyfonts_fonts', 'local_filename' )
			|| $this->column_exists( 'easyfonts_fonts', 'is_active' )
			|| $this->column_exists( 'easyfonts_fonts', 'downloaded_at' );

		return $has_legacy && ! $has_v3;
	}

	/**
	 * Per-table status for diagnostics.
	 *
	 * @return array<string,array{exists:bool,schema_ok:bool,rows:int}>
	 */
	public function status(): array {
		global $wpdb;

		$map = array(
			'fonts'     => array( 'easyfonts_fonts', 'variant_key' ),
			'usage'     => array( 'easyfonts_usage', 'usage_key' ),
			'decisions' => array( 'easyfonts_decisions', 'route_key' ),
		);

		$out = array();

		foreach ( $map as $label => [$name, $signature] ) {
			$exists = $this->table_exists( $name );
			$rows   = 0;

			if ( $exists ) {
				$table = $wpdb->prefix . $name;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			}

			$out[ $label ] = array(
				'exists'    => $exists,
				'schema_ok' => $exists && $this->column_exists( $name, $signature ),
				'rows'      => $rows,
			);
		}

		return $out;
	}

	/**
	 * Does a table exist?
	 *
	 * @param string $name Unprefixed table name.
	 * @return bool
	 */
	private function table_exists( string $name ): bool {
		global $wpdb;

		$table = $wpdb->prefix . $name;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Does a column exist on a table?
	 *
	 * @param string $name   Unprefixed table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function column_exists( string $name, string $column ): bool {
		global $wpdb;

		if ( ! $this->table_exists( $name ) ) {
			return false;
		}

		$table = $wpdb->prefix . $name;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );

		return ! empty( $found );
	}

	/**
	 * Default settings. Detectors are managed by Auto-Config, not exposed as toggles.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings(): array {
		return array(
			'enabled'                  => 1,
			'auto_config'              => 1,
			'font_display'             => 'swap',
			'strip_hints'              => 1,
			'smart_preload'            => 1,
			'metric_fallbacks'         => 1,
			'beacon'                   => 1,
			'inline_css'               => 0,
			'async_blocker'            => 0,
			'subsets'                  => array( 'latin', 'latin-ext' ),
			'detectors'                => array(),
			'excluded_urls'            => array(),
			'cdn_url'                  => '',
			'remove_data_on_uninstall' => 0,
		);
	}
}
