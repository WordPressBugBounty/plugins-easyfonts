<?php
/**
 * Registry of hosted font variants.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Fonts;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD over the easyfonts_fonts table.
 */
class Registry {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'easyfonts_fonts';
	}

	/**
	 * Stable identity for a variant.
	 *
	 * @param string $family Family.
	 * @param string $weight Weight.
	 * @param string $style  Style.
	 * @param string $subset Subset.
	 * @return string
	 */
	public static function variant_key( string $family, string $weight, string $style, string $subset ): string {
		return strtolower( str_replace( ' ', '-', $family ) ) . ':' . $weight . ':' . $style . ':' . $subset;
	}

	/**
	 * Last DB error from a write, if any (for diagnostics).
	 *
	 * @var string
	 */
	private static string $last_error = '';

	/**
	 * Most recent write error.
	 *
	 * @return string
	 */
	public static function last_error(): string {
		return self::$last_error;
	}

	/**
	 * Insert or update a variant by its variant_key.
	 *
	 * Uses SELECT + insert/update with explicit format arrays (rather than a
	 * hand-built INSERT … ON DUPLICATE KEY UPDATE) so NULL metrics and the
	 * MySQL-8.0.20-deprecated VALUES() function can't break the write.
	 *
	 * @param array<string,mixed> $data Variant data.
	 * @return void
	 */
	public function upsert( array $data ): void {
		global $wpdb;

		$family = sanitize_text_field( $data['family'] ?? '' );

		if ( '' === $family ) {
			return;
		}

		$weight = sanitize_text_field( $data['weight'] ?? '400' );
		$style  = sanitize_text_field( $data['style'] ?? 'normal' );
		$subset = sanitize_text_field( $data['subset'] ?? 'latin' );
		$key    = self::variant_key( $family, $weight, $style, $subset );

		$row = array(
			'family'      => $family,
			'weight'      => $weight,
			'style'       => $style,
			'subset'      => $subset,
			'variant_key' => $key,
			'is_variable' => ! empty( $data['is_variable'] ) ? 1 : 0,
			'provider'    => sanitize_text_field( $data['provider'] ?? 'google' ),
			'css_file'    => sanitize_text_field( $data['css_file'] ?? '' ),
			'font_file'   => sanitize_text_field( $data['font_file'] ?? '' ),
			'file_size'   => absint( $data['file_size'] ?? 0 ),
			'source_url'  => esc_url_raw( $data['source_url'] ?? '' ),
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' );

		// metrics is nullable: only include it when we actually have an array,
		// so a missing value leaves the existing column untouched.
		if ( ! empty( $data['metrics'] ) && is_array( $data['metrics'] ) ) {
			$row['metrics'] = wp_json_encode( $data['metrics'] );
			$formats[]      = '%s';
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE variant_key = %s", $key )
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ), $formats, array( '%d' ) );
		} else {
			$row['created_at'] = current_time( 'mysql' );
			$formats[]         = '%s';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $row, $formats );
		}

		if ( ! empty( $wpdb->last_error ) ) {
			self::$last_error = $wpdb->last_error;

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'EasyFonts: font registration failed — ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}

	/**
	 * Variant keys that the user has disabled (is_enabled = 0). The consolidator
	 * consults this to skip those variants when building the stylesheet.
	 *
	 * @return array<string,bool> Map of variant_key => true.
	 */
	public function disabled_keys(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$keys = $wpdb->get_col( "SELECT variant_key FROM {$this->table()} WHERE is_enabled = 0" );

		return $keys ? array_fill_keys( $keys, true ) : array();
	}

	/**
	 * Batch-fetch the currently-stored font file for a set of variant keys, so
	 * the consolidator can register a page's variants with a single SELECT
	 * instead of one per variant. Returns a map of variant_key => font_file
	 * (only for keys that exist).
	 *
	 * @param string[] $keys Variant keys.
	 * @return array<string,string>
	 */
	public function existing_map( array $keys ): array {
		global $wpdb;

		$keys = array_values( array_unique( array_filter( $keys, 'strlen' ) ) );

		if ( empty( $keys ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT variant_key, font_file FROM {$this->table()} WHERE variant_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$keys
			),
			ARRAY_A
		);

		$out = array();

		if ( $rows ) {
			foreach ( $rows as $r ) {
				$out[ $r['variant_key'] ] = (string) $r['font_file'];
			}
		}

		return $out;
	}

	/**
	 * User-FORCED preload targets: variants the user explicitly toggled to
	 * "always preload" (is_preloaded = 1 AND preload_user_set = 1) and that are
	 * still enabled, each resolved to a font file. These are intentional global
	 * overrides — they apply on every page that actually uses the family (the
	 * caller intersects with the fonts present on the current page). Automatic,
	 * above-the-fold preloads are NOT sourced here; they come from the per-route
	 * beacon decision (see UsageTracker::get_decision / SmartPreload).
	 *
	 * Deduped: one entry per family+weight+style, preferring the latin subset.
	 *
	 * @return array<int,array{family:string,weight:string,style:string,file:string}>
	 */
	public function preload_targets(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT family, weight, style, font_file, subset
			 FROM {$this->table()}
			 WHERE is_preloaded = 1 AND preload_user_set = 1 AND is_enabled = 1 AND font_file <> ''
			 ORDER BY ( subset = 'latin' ) DESC",
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		$seen = array();
		$out  = array();

		foreach ( $rows as $r ) {
			$dedupe = strtolower( $r['family'] ) . '|' . $r['weight'] . '|' . $r['style'];

			if ( isset( $seen[ $dedupe ] ) ) {
				continue; // First (latin-preferred) wins.
			}

			$seen[ $dedupe ] = true;
			$out[]           = array(
				'family' => $r['family'],
				'weight' => $r['weight'],
				'style'  => $r['style'],
				'file'   => $r['font_file'],
			);
		}

		return $out;
	}

	/**
	 * Set a boolean flag (is_enabled | is_preloaded) on ALL variants at once.
	 *
	 * @param string $field 'is_enabled' or 'is_preloaded'.
	 * @param bool   $value New value.
	 * @return void
	 */
	public function set_flag_all( string $field, bool $value ): void {
		global $wpdb;

		if ( ! in_array( $field, array( 'is_enabled', 'is_preloaded' ), true ) ) {
			return;
		}

		// Bulk toggles are user actions, so also stamp the matching user_set
		// column to protect the choice from later auto-detection.
		$col = 'is_enabled' === $field ? 'load_user_set' : 'preload_user_set';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$this->table()} SET `{$field}` = " . ( $value ? 1 : 0 ) . ", `{$col}` = 1" );
	}

	/**
	 * Set a boolean flag (is_enabled | is_preloaded) on variants.
	 *
	 * @param string $field      'is_enabled' or 'is_preloaded'.
	 * @param bool   $value      New value.
	 * @param string $family     Family to target.
	 * @param string $weight     Weight (empty = whole family).
	 * @param string $style      Style (empty = whole family).
	 * @return void
	 */
	public function set_flag( string $field, bool $value, string $family, string $weight = '', string $style = '', bool $mark_user = true ): void {
		global $wpdb;

		if ( ! in_array( $field, array( 'is_enabled', 'is_preloaded' ), true ) ) {
			return;
		}

		$where         = array( 'family' => $family );
		$where_formats = array( '%s' );

		if ( '' !== $weight ) {
			$where['weight']  = $weight;
			$where_formats[]  = '%s';
		}

		if ( '' !== $style ) {
			$where['style']  = $style;
			$where_formats[] = '%s';
		}

		$data    = array( $field => $value ? 1 : 0 );
		$formats = array( '%d' );

		// Record that a human set this, so the auto-detect logic never overrides
		// the choice later (load → load_user_set, preload → preload_user_set).
		if ( $mark_user ) {
			$col          = 'is_enabled' === $field ? 'load_user_set' : 'preload_user_set';
			$data[ $col ] = 1;
			$formats[]    = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$this->table(),
			$data,
			$where,
			$formats,
			$where_formats
		);
	}

	/**
	 * Grouped families with usage classification + per-(weight,style) flags, for
	 * the admin Fonts screen. Subsets are merged into each weight/style row.
	 *
	 * @param array<string,bool> $rendered Set of "family|weight|style" (lowercased) seen rendering.
	 * @param array<string,bool> $rendered_family Set of lowercased families seen rendering.
	 * @return array<int,array<string,mixed>>
	 */
	public function grouped_with_usage( array $rendered, array $rendered_family ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY family ASC, weight ASC", ARRAY_A );

		if ( ! $rows ) {
			return array();
		}

		$families = array();

		foreach ( $rows as $row ) {
			$family = $row['family'];
			$lc     = strtolower( $family );

			if ( ! isset( $families[ $family ] ) ) {
				$families[ $family ] = array(
					'family'      => $family,
					'is_variable' => (int) $row['is_variable'],
					'has_metrics' => ! empty( $row['metrics'] ),
					'used'        => isset( $rendered_family[ $lc ] ),
					'total_size'  => 0,
					'enabled'     => false,
					'_variants'   => array(),
				);
			}

			$ws  = $row['weight'] . '|' . $row['style'];
			$key = $lc . '|' . $row['weight'] . '|' . $row['style'];

			if ( ! isset( $families[ $family ]['_variants'][ $ws ] ) ) {
				$families[ $family ]['_variants'][ $ws ] = array(
					'weight'       => $row['weight'],
					'style'        => $row['style'],
					'subsets'      => array(),
					'is_enabled'   => true,
					'is_preloaded' => false,
					'used'         => isset( $rendered[ $key ] ),
					'size'         => 0,
				);
			}

			$v = &$families[ $family ]['_variants'][ $ws ];
			$v['subsets'][]    = $row['subset'];
			$v['size']        += (int) $row['file_size'];
			$v['is_enabled']   = $v['is_enabled'] && ( 1 === (int) $row['is_enabled'] );
			$v['is_preloaded'] = $v['is_preloaded'] || ( 1 === (int) $row['is_preloaded'] );
			unset( $v );

			$families[ $family ]['total_size'] += (int) $row['file_size'];

			if ( 1 === (int) $row['is_enabled'] ) {
				$families[ $family ]['enabled'] = true;
			}
		}

		// Flatten variant maps to lists.
		$out = array();

		foreach ( $families as $fam ) {
			$fam['variants'] = array_values( $fam['_variants'] );
			unset( $fam['_variants'] );
			$out[] = $fam;
		}

		return $out;
	}

	/**
	 * All variants grouped by family.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function grouped(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY family ASC, weight ASC", ARRAY_A );

		if ( ! $rows ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $row ) {
			$family = $row['family'];

			if ( ! isset( $out[ $family ] ) ) {
				$out[ $family ] = array(
					'family'      => $family,
					'is_variable' => (int) $row['is_variable'],
					'has_metrics' => ! empty( $row['metrics'] ),
					'total_size'  => 0,
					'variants'    => array(),
				);
			}

			$out[ $family ]['variants'][] = array(
				'id'     => (int) $row['id'],
				'weight' => $row['weight'],
				'style'  => $row['style'],
				'subset' => $row['subset'],
				'size'   => (int) $row['file_size'],
			);

			$out[ $family ]['total_size'] += (int) $row['file_size'];
		}

		return array_values( $out );
	}

	/**
	 * Store metrics for every variant of a family (used by the backfill).
	 *
	 * @param string                  $family  Family.
	 * @param array<string,float|int> $metrics Metrics.
	 * @return void
	 */
	public function set_family_metrics( string $family, array $metrics ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$this->table(),
			array( 'metrics' => wp_json_encode( $metrics ) ),
			array( 'family' => $family ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Stored metrics for a family (first variant that has them).
	 *
	 * @param string $family Family.
	 * @return array<string,float|int>|null
	 */
	public function metrics_for( string $family ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$json = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT metrics FROM {$this->table()} WHERE family = %s AND metrics IS NOT NULL LIMIT 1",
				$family
			)
		);

		if ( ! $json ) {
			return null;
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Resolve the local font file for a family/weight/style (first subset match).
	 *
	 * @param string $family Family.
	 * @param string $weight Weight.
	 * @param string $style  Style.
	 * @return string|null Cache-relative font filename.
	 */
	public function resolve_file( string $family, string $weight, string $style ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$file = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT font_file FROM {$this->table()}
				 WHERE family = %s AND weight = %s AND style = %s AND font_file <> ''
				 ORDER BY ( subset = 'latin' ) DESC
				 LIMIT 1",
				$family,
				$weight,
				$style
			)
		);

		return $file ? (string) $file : null;
	}

	/**
	 * Best hosted file to PRELOAD for a family, tolerant of weight/style drift.
	 * The beacon reports the above-the-fold face using the element's COMPUTED
	 * weight/style, which often differs from the hosted variant (e.g. a
	 * single-weight family rendered bold reports weight 700, but only 400 is
	 * hosted). An exact lookup would then return nothing and the font would
	 * never preload. So we prefer an exact match, then the same style, then the
	 * latin subset, then the closest available weight — always returning a real
	 * file when the (enabled) family is hosted at all.
	 *
	 * @param string $family Family.
	 * @param string $weight Desired weight.
	 * @param string $style  Desired style.
	 * @return string|null
	 */
	public function resolve_preload_file( string $family, string $weight, string $style ): ?string {
		global $wpdb;

		$target = (int) preg_replace( '/\D+/', '', $weight );
		$target = $target > 0 ? $target : 400;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$file = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT font_file FROM {$this->table()}
				 WHERE family = %s AND is_enabled = 1 AND font_file <> ''
				 ORDER BY
					( weight = %s AND style = %s ) DESC,
					( style = %s ) DESC,
					( subset = 'latin' ) DESC,
					ABS( CAST( NULLIF(weight,'') AS UNSIGNED ) - %d ) ASC,
					id ASC
				 LIMIT 1",
				$family,
				$weight,
				$style,
				$style,
				$target
			)
		);

		return $file ? (string) $file : null;
	}

	/**
	 * Stats helper.
	 *
	 * @return array{families:int,variants:int,bytes:int}
	 */
	public function stats(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$variants = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
		$families = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT family) FROM {$this->table()}" );
		$bytes    = (int) $wpdb->get_var( "SELECT COALESCE(SUM(file_size),0) FROM {$this->table()}" );
		// phpcs:enable

		return array(
			'families' => $families,
			'variants' => $variants,
			'bytes'    => $bytes,
		);
	}

	/**
	 * Wipe the table (used on purge).
	 */
	public function truncate(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "TRUNCATE TABLE {$this->table()}" );
	}
}
