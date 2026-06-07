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
	 * Canonical route key for a URL: the path only, with the query string
	 * dropped. This is the single normalization used everywhere a page is
	 * identified (usage rows, beacon decisions, warm locks), so the server
	 * buffer and the browser beacon always agree on one key per page — and
	 * volatile params (cache-busters like efbust, the easyfonts_probe key,
	 * builder flags, tracking params) can never mint duplicate rows.
	 *
	 * @param string $url Absolute or relative URL.
	 * @return string Path beginning with '/', never empty.
	 */
	public static function route_for( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' === $path ? '/' : $path;
	}

	/**
	 * Canonical route key for the current request.
	 *
	 * @return string
	 */
	public static function current_route(): string {
		return self::route_for( home_url( add_query_arg( array() ) ) );
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

		if ( empty( $records ) ) {
			return;
		}

		$now   = current_time( 'mysql' );
		$table = $this->usage_table();

		// Collapse to one row per (page, family, weight, style); merge any
		// duplicates within this batch (strongest rendered/above_fold wins,
		// beacon origin beats buffer) so a single multi-row upsert replaces what
		// used to be one query per variant on every render.
		$by_key = array();

		foreach ( $records as $rec ) {
			$family = sanitize_text_field( $rec['family'] ?? '' );

			if ( '' === $family ) {
				continue;
			}

			$weight = sanitize_text_field( $rec['weight'] ?? '400' );
			$style  = sanitize_text_field( $rec['style'] ?? 'normal' );
			$origin = sanitize_text_field( $rec['origin'] ?? 'buffer' );
			$key    = sha1( $page_url . '|' . strtolower( $family ) . '|' . $weight . '|' . $style );

			$rendered   = ! empty( $rec['rendered'] ) ? 1 : 0;
			$above_fold = ! empty( $rec['above_fold'] ) ? 1 : 0;

			if ( isset( $by_key[ $key ] ) ) {
				$by_key[ $key ]['rendered']   = max( $by_key[ $key ]['rendered'], $rendered );
				$by_key[ $key ]['above_fold'] = max( $by_key[ $key ]['above_fold'], $above_fold );

				if ( 'beacon' === $origin ) {
					$by_key[ $key ]['origin'] = 'beacon';
				}

				continue;
			}

			$by_key[ $key ] = array(
				'key'        => $key,
				'family'     => $family,
				'weight'     => $weight,
				'style'      => $style,
				'origin'     => $origin,
				'rendered'   => $rendered,
				'above_fold' => $above_fold,
			);
		}

		if ( empty( $by_key ) ) {
			return;
		}

		$placeholders = array();
		$args         = array();

		foreach ( $by_key as $row ) {
			$placeholders[] = '(%s,%s,%d,%s,%s,%s,%s,%d,%d,1,%s)';
			array_push(
				$args,
				$row['key'],
				$page_url,
				$page_id,
				$row['family'],
				$row['weight'],
				$row['style'],
				$row['origin'],
				$row['rendered'],
				$row['above_fold'],
				$now
			);
		}

		$sql = "INSERT INTO {$table}
				(usage_key, page_url, page_id, family, weight, style, origin, rendered, above_fold, hits, last_seen)
			 VALUES " . implode( ',', $placeholders ) . "
			 ON DUPLICATE KEY UPDATE
				hits       = hits + 1,
				rendered   = GREATEST(rendered, VALUES(rendered)),
				above_fold = GREATEST(above_fold, VALUES(above_fold)),
				origin     = VALUES(origin),
				last_seen  = VALUES(last_seen)";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
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
	 * Per-route render profile in a SINGLE query: which families the beacon has
	 * seen rendering on the route, and which of those are above the fold. Both
	 * are family-level and based on the page's authorial font-family, so they're
	 * robust to weight/style differences and self-correcting.
	 *
	 *   - `rendered`   drives page scoping (which fonts load on this page).
	 *   - `above_fold` drives preload (which of them to fetch early).
	 *
	 * Empty `rendered` means the route hasn't been measured yet — callers must
	 * neither scope nor preload from no data.
	 *
	 * @param string $route Canonical route (path).
	 * @return array{rendered:array<string,bool>,above_fold:array<string,bool>,scope_out:array<string,bool>,measured:bool}
	 */
	public function route_render_profile( string $route ): array {
		global $wpdb;

		$out = array(
			'rendered'   => array(),
			'above_fold' => array(),
			'scope_out'  => array(),
			'measured'   => false,
		);

		if ( '' === $route ) {
			return $out;
		}

		// Per family on this route: was it ever rendered (used), was it above
		// the fold, and has a real-browser beacon ever reported on it. The
		// beacon reports both what rendered AND what loaded-but-never-rendered
		// (recorded as rendered = 0, origin = 'beacon'), so a family is a
		// confirmed scope-out ONLY when a beacon has actually measured it and
		// never saw it render. A family that hasn't been beacon-measured yet
		// (e.g. a font just added/replaced in the Customizer) is NEVER scoped
		// out — it loads normally until a beacon has had a chance to judge it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT family,
					MAX(rendered) AS rnd,
					MAX(CASE WHEN rendered = 1 THEN above_fold ELSE 0 END) AS af,
					MAX(CASE WHEN origin = 'beacon' THEN 1 ELSE 0 END) AS beaconed
				 FROM {$this->usage_table()}
				 WHERE page_url = %s
				 GROUP BY family",
				$route
			),
			ARRAY_A
		);

		if ( $rows ) {
			foreach ( $rows as $r ) {
				$fam      = strtolower( (string) $r['family'] );
				$rendered = ! empty( $r['rnd'] );
				$beaconed = ! empty( $r['beaconed'] );

				if ( $beaconed ) {
					$out['measured'] = true;
				}

				if ( $rendered ) {
					$out['rendered'][ $fam ] = true;

					if ( ! empty( $r['af'] ) ) {
						$out['above_fold'][ $fam ] = true;
					}
				} elseif ( $beaconed ) {
					// Beacon measured it and never saw it render → scope out.
					$out['scope_out'][ $fam ] = true;
				}
			}
		}

		return $out;
	}

	/**
	 * Cached set of families that are above the fold on EVERY route where they
	 * render — i.e. uniformly above-the-fold site-wide. These can be preloaded
	 * wherever they appear without consulting per-route data (and even before a
	 * given route has been measured), which is the cheap path: no per-route
	 * check needed for the fonts whose answer is the same everywhere.
	 *
	 * @return array<string,bool> Lowercased family => true.
	 */
	public function global_preload_families(): array {
		$list = get_option( 'easyfonts_global_preload', array() );

		return is_array( $list ) ? array_fill_keys( array_map( 'strval', $list ), true ) : array();
	}

	/**
	 * Recompute and cache the uniformly-above-the-fold family set. A family
	 * qualifies when every measured row for it (across all routes/variants where
	 * it renders) is above the fold — so its preload answer doesn't vary by page
	 * and we can skip per-route checks for it. Cheap: one grouped scan of the
	 * (capped) usage table; called when a beacon is accepted and from GC.
	 *
	 * @return void
	 */
	public function recompute_global_preload(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$families = $wpdb->get_col(
			"SELECT family FROM {$this->usage_table()}
			 WHERE rendered = 1
			 GROUP BY family
			 HAVING MIN(above_fold) = 1"
		);

		$families = $families ? array_values( array_unique( array_map( 'strtolower', $families ) ) ) : array();

		update_option( 'easyfonts_global_preload', $families, true );
	}

	/**
	 * Forget the stored decision for a route (all devices), so the next beacon
	 * for it is accepted immediately instead of being throttled. Used by the
	 * "Optimize" action to force a fresh measurement on demand.
	 *
	 * @param string $route Canonical route (path).
	 * @return void
	 */
	public function forget_decision( string $route ): void {
		global $wpdb;

		foreach ( array( 'mobile', 'desktop', 'any' ) as $dev ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $this->decisions_table(), array( 'route_key' => sha1( $route . '|' . $dev ) ) );
		}
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

		// Pruning may have changed which families are uniformly above the fold.
		$this->recompute_global_preload();
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

		// No usage data left → no uniformly-above-the-fold fonts.
		delete_option( 'easyfonts_global_preload' );
	}
}
