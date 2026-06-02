<?php
/**
 * Metric backfill.
 *
 * Metric extraction needs an extra round-trip to Google (a TTF copy, since
 * served woff2 can't be parsed without Brotli). Doing that during a visitor's
 * page render risks slow responses or timeouts, so it runs here instead — on
 * admin page loads, throttled and capped, fully off the front-end critical
 * path. The metric-matched fallback faces appear as soon as the backfill has
 * populated each family's metrics.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Fonts;

use EasyFonts\Parser\FontMetricsReader;
use EasyFonts\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Fills in font metrics for families that don't have them yet.
 */
class MetricsBackfill {

	/**
	 * Families processed per admin load.
	 */
	const BATCH = 3;

	/**
	 * Minimum seconds between backfill runs.
	 */
	const THROTTLE = 60;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_init', array( $this, 'maybe_run' ) );
	}

	/**
	 * Run a small backfill batch if due and enabled.
	 */
	public function maybe_run(): void {
		if ( ! Settings::get( 'metric_fallbacks', 1 ) || ! Settings::get( 'enabled', 1 ) ) {
			return;
		}

		if ( get_transient( 'easyfonts_metrics_lock' ) ) {
			return;
		}

		$families = $this->families_missing_metrics( self::BATCH );

		if ( empty( $families ) ) {
			return;
		}

		// Short lock so concurrent admin loads don't pile on Google requests.
		set_transient( 'easyfonts_metrics_lock', 1, self::THROTTLE );

		$downloader = new Downloader();
		$reader     = new FontMetricsReader();
		$registry   = new Registry();

		foreach ( $families as $family ) {
			$bytes = $downloader->fetch_family_ttf( $family['family'], (string) $family['weight'] );

			if ( ! $bytes ) {
				continue;
			}

			$metrics = $reader->read( $bytes );

			if ( $metrics ) {
				$registry->set_family_metrics( $family['family'], $metrics );
			}
		}
	}

	/**
	 * Find Google families with no metrics yet.
	 *
	 * @param int $limit Max families.
	 * @return array<int,array{family:string,weight:string}>
	 */
	private function families_missing_metrics( int $limit ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'easyfonts_fonts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT family, MIN(weight) AS weight
				 FROM {$table}
				 WHERE provider = 'google' AND ( metrics IS NULL OR metrics = '' )
				 GROUP BY family
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}
}
