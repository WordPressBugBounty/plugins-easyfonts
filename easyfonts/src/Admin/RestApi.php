<?php
/**
 * REST API.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Admin;

use EasyFonts\Database\Migrator;
use EasyFonts\Fonts\Registry;
use EasyFonts\Fonts\Storage;
use EasyFonts\Fonts\UsageTracker;
use EasyFonts\Settings;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles all REST routes.
 */
class RestApi {

	const NS = 'easyfonts/v1';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Register routes.
	 */
	public function register(): void {
		$admin = array( $this, 'can_manage' );

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $admin,
				),
			)
		);

		register_rest_route( self::NS, '/fonts', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_fonts' ),
			'permission_callback' => $admin,
		) );

		register_rest_route( self::NS, '/font/toggle', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'toggle_font' ),
			'permission_callback' => $admin,
		) );

		register_rest_route( self::NS, '/fonts/bulk', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'bulk_toggle' ),
			'permission_callback' => $admin,
		) );

		register_rest_route( self::NS, '/optimize', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'optimize' ),
			'permission_callback' => $admin,
		) );

		register_rest_route( self::NS, '/usage', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_usage' ),
			'permission_callback' => $admin,
		) );

		register_rest_route( self::NS, '/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_stats' ),
			'permission_callback' => $admin,
		) );

		register_rest_route( self::NS, '/purge', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'purge' ),
			'permission_callback' => $admin,
		) );

		register_rest_route( self::NS, '/repair', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'repair' ),
			'permission_callback' => $admin,
		) );

		register_rest_route( self::NS, '/diagnostics', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'diagnostics' ),
			'permission_callback' => $admin,
		) );

		// Public beacon ingest (nonce-checked in handler).
		register_rest_route( self::NS, '/beacon', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'beacon' ),
			'permission_callback' => '__return_true',
		) );

		// Public async-font ingest: records Google/Bunny CSS URLs injected by JS
		// at runtime so the next render can self-host them (nonce-checked).
		register_rest_route( self::NS, '/async-fonts', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'async_fonts' ),
			'permission_callback' => '__return_true',
		) );

		// Settings export / import (admin only).
		register_rest_route( self::NS, '/export', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'export_settings' ),
			'permission_callback' => $admin,
		) );

		register_rest_route( self::NS, '/import', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'import_settings' ),
			'permission_callback' => $admin,
		) );
	}

	/**
	 * Capability gate.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /settings
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( Settings::all(), 200 );
	}

	/**
	 * POST /settings
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$input   = (array) $request->get_json_params();
		$current = Settings::all();

		$bools = array( 'enabled', 'auto_config', 'strip_hints', 'smart_preload', 'metric_fallbacks', 'beacon', 'inline_css', 'async_blocker', 'remove_data_on_uninstall' );

		foreach ( $bools as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$current[ $key ] = $input[ $key ] ? 1 : 0;
			}
		}

		if ( isset( $input['font_display'] ) ) {
			$allowed                  = array( 'swap', 'fallback', 'optional', 'block', 'auto', 'none' );
			$val                      = sanitize_text_field( $input['font_display'] );
			$current['font_display']  = in_array( $val, $allowed, true ) ? $val : 'swap';
		}

		if ( array_key_exists( 'cdn_url', $input ) ) {
			$cdn = trim( (string) $input['cdn_url'] );

			// Accept protocol-relative; require http(s); store without trailing slash.
			if ( '' === $cdn ) {
				$current['cdn_url'] = '';
			} else {
				if ( 0 === strpos( $cdn, '//' ) ) {
					$cdn = ( is_ssl() ? 'https:' : 'http:' ) . $cdn;
				}

				$current['cdn_url'] = preg_match( '#^https?://#i', $cdn )
					? untrailingslashit( esc_url_raw( $cdn ) )
					: '';
			}
		}

		if ( isset( $input['subsets'] ) && is_array( $input['subsets'] ) ) {
			$current['subsets'] = array_values( array_map( 'sanitize_text_field', $input['subsets'] ) );
		}

		if ( isset( $input['excluded_urls'] ) && is_array( $input['excluded_urls'] ) ) {
			$current['excluded_urls'] = array_values( array_filter( array_map( 'sanitize_text_field', $input['excluded_urls'] ) ) );
		}

		Settings::save( $current );
		Settings::bump_buster();

		return new WP_REST_Response( Settings::all(), 200 );
	}

	/**
	 * GET /fonts
	 *
	 * @return WP_REST_Response
	 */
	public function get_fonts(): WP_REST_Response {
		$rendered = ( new UsageTracker() )->rendered_set();

		$grouped = ( new Registry() )->grouped_with_usage(
			$rendered['variants'],
			$rendered['families']
		);

		return new WP_REST_Response( $grouped, 200 );
	}

	/**
	 * POST /font/toggle — enable/disable or set preload on a variant or family.
	 *
	 * Body: { field: 'enabled'|'preloaded', value: bool, family: string,
	 *         weight?: string, style?: string }
	 * Omitting weight/style targets the whole family.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function toggle_font( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();

		$field  = sanitize_text_field( $params['field'] ?? '' );
		$family = sanitize_text_field( $params['family'] ?? '' );
		$weight = isset( $params['weight'] ) ? sanitize_text_field( (string) $params['weight'] ) : '';
		$style  = isset( $params['style'] ) ? sanitize_text_field( (string) $params['style'] ) : '';
		$value  = ! empty( $params['value'] );

		$column = 'enabled' === $field ? 'is_enabled' : ( 'preloaded' === $field ? 'is_preloaded' : '' );

		if ( '' === $column || '' === $family ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'bad_request' ), 400 );
		}

		( new Registry() )->set_flag( $column, $value, $family, $weight, $style );

		// Enabling/disabling changes which fonts the stylesheet contains, so the
		// next render must rebuild it; bump the buster to invalidate caches.
		if ( 'is_enabled' === $column ) {
			Settings::bump_buster();
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * POST /fonts/bulk — enable/disable (or preload) every font in a scope at
	 * once. Body: { field:'enabled'|'preloaded', value:bool, scope:'used'|'unused'|'all' }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function bulk_toggle( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();

		$field  = 'preloaded' === ( $params['field'] ?? '' ) ? 'is_preloaded' : 'is_enabled';
		$value  = ! empty( $params['value'] );
		$scope  = in_array( $params['scope'] ?? 'all', array( 'used', 'unused', 'all' ), true ) ? $params['scope'] : 'all';

		$registry = new Registry();

		if ( 'all' === $scope ) {
			$registry->set_flag_all( $field, $value );
		} else {
			$rendered = ( new UsageTracker() )->rendered_set();
			$grouped  = $registry->grouped_with_usage( $rendered['variants'], $rendered['families'] );

			foreach ( $grouped as $family ) {
				$is_used = ! empty( $family['used'] );

				if ( ( 'used' === $scope && $is_used ) || ( 'unused' === $scope && ! $is_used ) ) {
					$registry->set_flag( $field, $value, $family['family'] );
				}
			}
		}

		if ( 'is_enabled' === $field ) {
			Settings::bump_buster();
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * POST /optimize — prime optimisation now via a cache-busting loopback to
	 * the front page, so the user doesn't have to visit it manually.
	 *
	 * @return WP_REST_Response
	 */
	public function optimize(): WP_REST_Response {
		$targets = apply_filters(
			'easyfonts_optimize_urls',
			array( home_url( '/' ) )
		);

		// Clear any throttled decision for the target routes so the X-ray beacon
		// that runs right after this is accepted immediately (a fresh, on-demand
		// measurement rather than waiting out the throttle window).
		$tracker = new UsageTracker();

		foreach ( (array) $targets as $t ) {
			$tracker->forget_decision( UsageTracker::route_for( (string) $t ) );
		}

		$ok = 0;

		foreach ( (array) $targets as $target ) {
			// Cache-busting param forces a fresh PHP render (bypasses page cache).
			// The warm key authorises this unauthenticated self-request to download.
			$url = add_query_arg(
				array(
					'easyfonts_probe' => Settings::warm_key(),
					'efbust'          => (string) time(),
				),
				$target
			);

			$response = wp_remote_get(
				$url,
				array(
					'timeout'   => 30,
					/**
					 * Verify SSL on the loopback self-request. True by default;
					 * filter to false only for self-signed/staging certs.
					 *
					 * @param bool $verify
					 */
					'sslverify' => (bool) apply_filters( 'easyfonts_loopback_sslverify', true ),
				)
			);

			if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 400 ) {
				$ok++;
			}
		}

		$stats = ( new Registry() )->stats();

		return new WP_REST_Response(
			array(
				'ok'       => $ok > 0,
				'probed'   => $ok,
				'families' => $stats['families'],
				'variants' => $stats['variants'],
				// The admin loads this in a hidden iframe to run the real-browser
				// X-ray (beacon), which measures rendered fonts + subsets.
				'probe_url' => add_query_arg( 'easyfonts_probe', (string) time(), home_url( '/' ) ),
				// Snapshot of the last-beacon time BEFORE the X-ray; the client
				// polls /stats and watches for this to advance, which confirms the
				// iframe actually rendered (and wasn't blocked by X-Frame-Options).
				'beacon_before' => (int) get_option( 'easyfonts_last_beacon', 0 ),
			),
			200
		);
	}

	/**
	 * GET /usage
	 *
	 * @return WP_REST_Response
	 */
	public function get_usage(): WP_REST_Response {
		return new WP_REST_Response( ( new UsageTracker() )->summary(), 200 );
	}

	/**
	 * GET /stats
	 *
	 * @return WP_REST_Response
	 */
	public function get_stats(): WP_REST_Response {
		$registry = new Registry();
		$storage  = new Storage();
		$stats    = $registry->stats();

		return new WP_REST_Response(
			array(
				'families'    => $stats['families'],
				'variants'    => $stats['variants'],
				'hosted_kb'   => (int) round( $stats['bytes'] / 1024 ),
				'cache_kb'    => (int) round( $storage->total_size() / 1024 ),
				'detectors'   => (array) Settings::get( 'detectors', array() ),
				'last_buster' => Settings::buster(),
				'last_beacon' => (int) get_option( 'easyfonts_last_beacon', 0 ),
			),
			200
		);
	}

	/**
	 * POST /purge — clear cache + tables, keep settings.
	 *
	 * @return WP_REST_Response
	 */
	public function purge(): WP_REST_Response {
		( new Storage() )->purge();
		( new Registry() )->truncate();
		( new UsageTracker() )->truncate();

		delete_option( 'easyfonts_processed_local' );
		delete_option( 'easyfonts_processed_external' );

		$settings              = Settings::all();
		$settings['detectors'] = array();
		Settings::save( $settings );
		Settings::bump_buster();

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * POST /repair — drop + recreate the tables (fixes stale/legacy schema),
	 * then clear cache + learned state so the next page view rebuilds cleanly.
	 *
	 * @return WP_REST_Response
	 */
	public function repair(): WP_REST_Response {
		( new Migrator() )->repair();
		( new Storage() )->purge();

		delete_option( 'easyfonts_processed_local' );
		delete_option( 'easyfonts_processed_external' );
		delete_transient( 'easyfonts_schema_checked' );

		$settings              = Settings::all();
		$settings['detectors'] = array();
		Settings::save( $settings );
		Settings::bump_buster();

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'tables' => ( new Migrator() )->status(),
			),
			200
		);
	}

	/**
	 * GET /diagnostics
	 *
	 * @return WP_REST_Response
	 */
	public function diagnostics(): WP_REST_Response {
		return new WP_REST_Response( Diagnostics::collect(), 200 );
	}

	/**
	 * POST /beacon — ingest real-render decisions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function beacon( WP_REST_Request $request ): WP_REST_Response {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! wp_verify_nonce( (string) $nonce, 'wp_rest' ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		$params = (array) $request->get_json_params();
		$route  = sanitize_text_field( $params['route'] ?? '/' );
		$device = in_array( $params['device'] ?? '', array( 'mobile', 'desktop' ), true ) ? $params['device'] : 'any';

		// Cap the route length to keep the indexed column sane.
		$route = '' === $route ? '/' : substr( $route, 0, 480 );

		$usage = new UsageTracker();

		// Per-route/device throttle: once we record a beacon for a route we
		// ignore further beacons for it for a short window. This bounds writes
		// on an anonymous endpoint and neutralises repeated-request abuse, while
		// still letting used/unused classification and page scoping self-correct
		// quickly (hourly by default) as pages change — the old week-long
		// write-once meant a font that started or stopped being used wasn't
		// reflected for days.
		$throttle = (int) apply_filters( 'easyfonts_beacon_throttle_hours', 1 );

		if ( $throttle > 0 && $usage->has_fresh_decision( $route, (string) $device, $throttle ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'cached' => true ), 200 );
		}

		// Hard caps on payload size (defence against oversized public payloads).
		$cap      = 200;
		$rendered = is_array( $params['rendered'] ?? null ) ? array_slice( $params['rendered'], 0, $cap ) : array();
		$preload  = is_array( $params['preload'] ?? null ) ? array_slice( $params['preload'], 0, $cap ) : array();
		$unload   = is_array( $params['unload'] ?? null ) ? array_slice( $params['unload'], 0, $cap ) : array();

		// Mark that a real-browser beacon just reported in. The admin polls this
		// after opening the optimize iframe to confirm the X-ray actually ran
		// (vs. being blocked by X-Frame-Options / CSP).
		update_option( 'easyfonts_last_beacon', time(), false );

		// Record observed usage.
		$records = array();

		foreach ( $rendered as $r ) {
			if ( empty( $r['family'] ) ) {
				continue;
			}

			$records[] = array(
				'family'     => $r['family'],
				'weight'     => $r['weight'] ?? '400',
				'style'      => $r['style'] ?? 'normal',
				'origin'     => 'beacon',
				'rendered'   => 1,
				'above_fold' => ! empty( $r['above_fold'] ) ? 1 : 0,
			);
		}

		// Also record what loaded but never rendered, as beacon-origin rows with
		// rendered = 0. This is what lets page scoping tell a CONFIRMED-unused
		// font (drop it from this page) apart from a font that simply hasn't been
		// measured yet (keep it) — so a newly added/replaced font is never
		// wrongly scoped out before the beacon has judged it.
		foreach ( $unload as $u ) {
			if ( empty( $u['family'] ) ) {
				continue;
			}

			$records[] = array(
				'family'     => $u['family'],
				'weight'     => $u['weight'] ?? '400',
				'style'      => $u['style'] ?? 'normal',
				'origin'     => 'beacon',
				'rendered'   => 0,
				'above_fold' => 0,
			);
		}

		if ( ! empty( $records ) ) {
			$usage->record( $route, url_to_postid( home_url( $route ) ), $records );

			// Refresh the uniformly-above-the-fold family cache from the updated
			// usage data. Runs only when a beacon is accepted (throttled), so the
			// cost is a single grouped scan of the capped usage table.
			$usage->recompute_global_preload();
		}

		// Persist the raw beacon measurement for this route/device. Page scoping
		// and preload are driven from the recorded usage above (family-level,
		// self-correcting); this row anchors the per-route throttle (updated_at)
		// and keeps the raw above-the-fold/unrendered snapshot for diagnostics.
		$usage->store_decision( $route, (string) $device, $this->clean_variants( $preload ), $this->clean_variants( $unload ) );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Sanitise a list of {family,weight,style} entries.
	 *
	 * @param array<int,mixed> $list List.
	 * @return array<int,array{family:string,weight:string,style:string}>
	 */
	private function clean_variants( array $list ): array {
		$out = array();

		foreach ( $list as $item ) {
			if ( empty( $item['family'] ) ) {
				continue;
			}

			$out[] = array(
				'family' => sanitize_text_field( $item['family'] ),
				'weight' => sanitize_text_field( (string) ( $item['weight'] ?? '400' ) ),
				'style'  => sanitize_text_field( (string) ( $item['style'] ?? 'normal' ) ),
			);
		}

		return $out;
	}

	/**
	 * POST /async-fonts — record Google/Bunny CSS URLs that were injected by
	 * JavaScript at runtime (caught client-side by async-blocker.js) so the next
	 * server render can self-host them. Nonce-checked, provider-validated, and
	 * capped. Public input only ever queues *provider* CSS URLs for hosting; it
	 * cannot write arbitrary data or flip any persistent setting.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function async_fonts( WP_REST_Request $request ): WP_REST_Response {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! wp_verify_nonce( (string) $nonce, 'wp_rest' ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		if ( ! Settings::get( 'enabled', 1 ) || ! Settings::get( 'async_blocker', 0 ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 403 );
		}

		$params = (array) $request->get_json_params();
		$urls   = is_array( $params['urls'] ?? null ) ? array_slice( $params['urls'], 0, 25 ) : array();

		$stored  = (array) get_option( 'easyfonts_async_urls', array() );
		$before  = count( $stored );

		foreach ( $urls as $raw ) {
			$url = \EasyFonts\Detect\Providers::normalize_url( (string) $raw );

			// Only accept genuine provider stylesheet URLs.
			if ( ! \EasyFonts\Detect\Providers::is_css_url( $url ) ) {
				continue;
			}

			$url = esc_url_raw( $url );

			if ( '' !== $url && ! in_array( $url, $stored, true ) ) {
				$stored[] = $url;
			}
		}

		// Keep the queue bounded.
		if ( count( $stored ) > 50 ) {
			$stored = array_slice( $stored, -50 );
		}

		if ( count( $stored ) !== $before ) {
			update_option( 'easyfonts_async_urls', array_values( $stored ), false );
			// New runtime fonts discovered → invalidate so the next render hosts them.
			Settings::bump_buster();
		}

		return new WP_REST_Response( array( 'ok' => true, 'queued' => count( $stored ) ), 200 );
	}

	/**
	 * GET /export — return the current settings as a portable JSON payload.
	 *
	 * @return WP_REST_Response
	 */
	public function export_settings(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'_type'    => 'easyfonts-settings',
				'_version' => EASYFONTS_VERSION,
				'settings' => Settings::all(),
			),
			200
		);
	}

	/**
	 * POST /import — replace settings from an exported payload. Values are run
	 * through the same validation as the normal save path (no raw write).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function import_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) $request->get_json_params();

		// Accept either the wrapped export ({settings:{…}}) or a bare object.
		$incoming = isset( $body['settings'] ) && is_array( $body['settings'] ) ? $body['settings'] : $body;

		if ( empty( $incoming ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'empty' ), 400 );
		}

		$defaults = Migrator::default_settings();
		$current  = Settings::all();

		// Booleans.
		foreach ( array( 'enabled', 'auto_config', 'strip_hints', 'smart_preload', 'metric_fallbacks', 'beacon', 'inline_css', 'async_blocker', 'remove_data_on_uninstall' ) as $key ) {
			if ( array_key_exists( $key, $incoming ) ) {
				$current[ $key ] = $incoming[ $key ] ? 1 : 0;
			}
		}

		// font-display.
		if ( isset( $incoming['font_display'] ) ) {
			$allowed                 = array( 'swap', 'fallback', 'optional', 'block', 'auto', 'none' );
			$val                     = sanitize_text_field( (string) $incoming['font_display'] );
			$current['font_display'] = in_array( $val, $allowed, true ) ? $val : 'swap';
		}

		// Subsets / excluded URLs (arrays of strings).
		foreach ( array( 'subsets', 'excluded_urls' ) as $key ) {
			if ( isset( $incoming[ $key ] ) && is_array( $incoming[ $key ] ) ) {
				$current[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', $incoming[ $key ] ) ) );
			}
		}

		// CDN URL.
		if ( array_key_exists( 'cdn_url', $incoming ) ) {
			$cdn = trim( (string) $incoming['cdn_url'] );

			if ( '' !== $cdn && 0 === strpos( $cdn, '//' ) ) {
				$cdn = ( is_ssl() ? 'https:' : 'http:' ) . $cdn;
			}

			$current['cdn_url'] = ( '' !== $cdn && preg_match( '#^https?://#i', $cdn ) )
				? untrailingslashit( esc_url_raw( $cdn ) )
				: '';
		}

		// Never import a foreign detector set; keep what this site learned.
		$current['detectors'] = (array) ( $current['detectors'] ?? $defaults['detectors'] );

		Settings::save( $current );
		Settings::bump_buster();

		return new WP_REST_Response( array( 'ok' => true, 'settings' => Settings::all() ), 200 );
	}
}