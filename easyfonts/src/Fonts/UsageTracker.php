<?php
/**
 * Font usage + beacon decisions.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Fonts;

defined( 'ABSPATH' ) || exit;

/**
 * Records real-render font usage and stores beacon-derived decisions.
 */
class UsageTracker {

	/**
	 * Usage table.
	 *
	 * @return string
	 */
	private function usage_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'easyfonts_usage';
	}

	/**
	 * Decisions table.
	 *
	 * @return string
	 */
	private function decisions_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'easyfonts_decisions';
	}

	/**
	 * Record a set of font observations for one page in a single query each.
	 *
	 * @param string                       $page_url Page URL.
	 * @param int                          $page_id  Post ID (0 if unknown).
	 * @param array<int,array<string,mixed>> $records  Each: family, weight, style, origin, rendered, above_fold.
	 */
	public function record( string $page_url, int $page_id, array $records ): void {
		global $wpdb;

		$now   = current_time( 'mysql' );
		$table = $this->usage_table();

		foreach ( $records as $rec ) {
			$family = sanitize_text_field( $rec['family'] ?? '' );

			if ( '' === $family ) {
				continue;
			}

			$weight     = sanitize_text_field( $rec['weight'] ?? '400' );
			$style      = sanitize_text_field( $rec['style'] ?? 'normal' );
			$origin     = sanitize_text_field( $rec['origin'] ?? 'buffer' );
			$rendered   = ! empty( $rec['rendered'] ) ? 1 : 0;
			$above_fold = ! empty( $rec['above_fold'] ) ? 1 : 0;
			$key        = sha1( $page_url . '|' . strtolower( $family ) . '|' . $weight . '|' . $style );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
						(usage_key, page_url, page_id, family, weight, style, origin, rendered, above_fold, hits, last_seen)
					 VALUES (%s,%s,%d,%s,%s,%s,%s,%d,%d,1,%s)
					 ON DUPLICATE KEY UPDATE
						hits       = hits + 1,
						rendered   = GREATEST(rendered, VALUES(rendered)),
						above_fold = GREATEST(above_fold, VALUES(above_fold)),
						origin     = VALUES(origin),
						last_seen  = VALUES(last_seen)",
					$key,
					$page_url,
					$page_id,
					$family,
					$weight,
					$style,
					$origin,
					$rendered,
					$above_fold,
					$now
				)
			);
		}
	}

	/**
	 * Sets of what the beacon observed actually rendering, for used/unused
	 * classification on the Fonts screen.
	 *
	 * @return array{variants:array<string,bool>,families:array<string,bool>}
	 */
	public function rendered_set(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT DISTINCT family, weight, style FROM {$this->usage_table()} WHERE rendered = 1",
			ARRAY_A
		);

		$variants = array();
		$families = array();

		if ( $rows ) {
			foreach ( $rows as $r ) {
				$lc                = strtolower( $r['family'] );
				$families[ $lc ]   = true;
				$variants[ $lc . '|' . $r['weight'] . '|' . $r['style'] ] = true;
			}
		}

		return array(
			'variants' => $variants,
			'families' => $families,
		);
	}

	/**
	 * Store beacon decisions (preload / unload) for a route + device.
	 *
	 * @param string                $route   Path (e.g. /pricing).
	 * @param string                $device  'mobile'|'desktop'|'any'.
	 * @param array<int,mixed>      $preload Above-the-fold variants to preload.
	 * @param array<int,mixed>      $unload  Loaded-but-unrendered variants to unload.
	 */
	public function store_decision( string $route, string $device, array $preload, array $unload ): void {
		global $wpdb;

		$key = sha1( $route . '|' . $device );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->decisions_table()}
					(route_key, route, device, preload, unload, updated_at)
				 VALUES (%s,%s,%s,%s,%s,%s)
				 ON DUPLICATE KEY UPDATE
					preload    = VALUES(preload),
					unload     = VALUES(unload),
					updated_at = VALUES(updated_at)",
				$key,
				$route,
				$device,
				wp_json_encode( array_values( $preload ) ),
				wp_json_encode( array_values( $unload ) ),
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Get the decision for a route + device (falls back to 'any').
	 *
	 * @param string $route  Path.
	 * @param string $device Device.
	 * @return array{preload:array,unload:array}
	 */
	public function get_decision( string $route, string $device ): array {
		global $wpdb;

		$empty = array(
			'preload' => array(),
			'unload'  => array(),
		);

		foreach ( array( $device, 'any' ) as $dev ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT preload, unload FROM {$this->decisions_table()} WHERE route_key = %s",
					sha1( $route . '|' . $dev )
				),
				ARRAY_A
			);

			if ( $row ) {
				return array(
					'preload' => json_decode( (string) $row['preload'], true ) ?: array(),
					'unload'  => json_decode( (string) $row['unload'], true ) ?: array(),
				);
			}
		}

		return $empty;
	}

	/**
	 * Aggregated usage summary (one row per family) for the dashboard.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function summary(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT
				family,
				GROUP_CONCAT(DISTINCT weight ORDER BY weight SEPARATOR ',') AS weights,
				SUM(hits) AS hits,
				COUNT(DISTINCT page_url) AS pages,
				MAX(rendered) AS rendered,
				MAX(above_fold) AS above_fold,
				MAX(last_seen) AS last_seen
			 FROM {$this->usage_table()}
			 GROUP BY family
			 ORDER BY hits DESC",
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Families that were loaded but never observed rendering (cleanup candidates).
	 *
	 * @return string[]
	 */
	public function never_rendered_families(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col(
			"SELECT family FROM {$this->usage_table()}
			 GROUP BY family
			 HAVING MAX(rendered) = 0"
		);

		return $rows ?: array();
	}

	/**
	 * Garbage-collect stale rows. Keeps the usage/decisions tables bounded on
	 * long-running / high-traffic sites: anything not seen within the retention
	 * window is dropped, and a hard row cap trims the oldest beyond it. Called
	 * from the weekly cleanup event.
	 *
	 * @param int $days     Retention window in days.
	 * @param int $max_rows Hard cap on usage rows after time-pruning.
	 * @return void
	 */
	public function gc( int $days = 30, int $max_rows = 5000 ): void {
		global $wpdb;

		$days  = max( 1, $days );
		$usage = $this->usage_table();
		$dec   = $this->decisions_table();

		// 1. Time-based prune (usage + decisions).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$usage} WHERE last_seen IS NOT NULL AND last_seen < ( NOW() - INTERVAL %d DAY )",
				$days
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$dec} WHERE updated_at IS NOT NULL AND updated_at < ( NOW() - INTERVAL %d DAY )",
				$days
			)
		);
		// phpcs:enable

		// 2. Hard cap: if usage still exceeds the cap, drop the oldest surplus.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$usage}" );

		if ( $total > $max_rows ) {
			$surplus = $total - $max_rows;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$usage} ORDER BY last_seen ASC LIMIT %d",
					$surplus
				)
			);
		}
	}

	/**
	 * Has a fresh decision already been recorded for this route + device?
	 *
	 * Used to make public beacon ingestion effectively write-once per
	 * route/device until the retention window elapses — capping DB writes and
	 * neutralising repeated-request abuse without losing any functionality.
	 *
	 * @param string $route       Path.
	 * @param string $device      Device.
	 * @param int    $fresh_hours Consider a decision "fresh" for this many hours.
	 * @return bool
	 */
	public function has_fresh_decision( string $route, string $device, int $fresh_hours = 168 ): bool {
		global $wpdb;

		$key = sha1( $route . '|' . $device );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT updated_at FROM {$this->decisions_table()} WHERE route_key = %s",
				$key
			)
		);

		if ( ! $updated ) {
			return false;
		}

		return ( time() - strtotime( (string) $updated ) ) < ( max( 1, $fresh_hours ) * HOUR_IN_SECONDS );
	}

	/**
	 * Empty both tables.
	 */
	public function truncate(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "TRUNCATE TABLE {$this->usage_table()}" );
		$wpdb->query( "TRUNCATE TABLE {$this->decisions_table()}" );
		// phpcs:enable
	}
}
