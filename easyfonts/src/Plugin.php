<?php
/**
 * Plugin orchestrator.
 *
 * @package EasyFonts
 */

namespace EasyFonts;

use EasyFonts\Admin\AdminPage;
use EasyFonts\Admin\RestApi;
use EasyFonts\Database\Migrator;
use EasyFonts\Fonts\UsageTracker;
use EasyFonts\Frontend\Beacon;
use EasyFonts\Frontend\OutputBuffer;
use EasyFonts\Fonts\MetricsBackfill;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires the plugin together.
 */
class Plugin {

	/**
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Wire subsystems.
	 */
	private function boot(): void {
		$this->maybe_migrate();

		( new OutputBuffer() )->boot();
		( new Beacon() )->boot();
		( new RestApi() )->boot();

		if ( is_admin() ) {
			( new AdminPage() )->boot();
			( new MetricsBackfill() )->boot();
		}

		// Weekly housekeeping.
		add_action( 'easyfonts_cleanup', array( $this, 'cleanup' ) );

		if ( ! wp_next_scheduled( 'easyfonts_cleanup' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'easyfonts_cleanup' );
		}

		load_plugin_textdomain( 'easyfonts', false, dirname( EASYFONTS_BASENAME ) . '/languages' );
	}

	/**
	 * Install / repair tables on load.
	 *
	 * Triggers when the version moved OR the live schema is wrong — the latter
	 * is what heals a site carrying an incompatible legacy table even though the
	 * version option already reads as current. The check is cached for the day
	 * so it costs one tiny query at most once per 24h on healthy sites.
	 */
	private function maybe_migrate(): void {
		if ( get_option( 'easyfonts_db_version' ) !== Migrator::DB_VERSION ) {
			( new Migrator() )->install();
			return;
		}

		if ( get_transient( 'easyfonts_schema_checked' ) ) {
			return;
		}

		set_transient( 'easyfonts_schema_checked', 1, DAY_IN_SECONDS );

		$migrator = new Migrator();

		if ( ! $migrator->tables_ok() ) {
			$migrator->install();
		}
	}

	/**
	 * Scheduled cleanup — prune stale usage/decisions rows so the tables stay
	 * bounded on long-running and high-traffic sites.
	 */
	public function cleanup(): void {
		$tracker = new UsageTracker();

		/**
		 * Retention window (days) for usage/decision rows.
		 *
		 * @param int $days
		 */
		$days = (int) apply_filters( 'easyfonts_gc_retention_days', 30 );

		/**
		 * Hard cap on usage rows after time-based pruning.
		 *
		 * @param int $max
		 */
		$max = (int) apply_filters( 'easyfonts_gc_max_usage_rows', 5000 );

		$tracker->gc( $days, $max );

		/**
		 * Fires on the weekly Easy Fonts cleanup.
		 *
		 * @param UsageTracker $tracker
		 */
		do_action( 'easyfonts_run_cleanup', $tracker );
	}
}
