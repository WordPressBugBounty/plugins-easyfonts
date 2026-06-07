<?php
/**
 * Output buffer — the authoritative font detector.
 *
 * Detection happens inside the real visitor request on the true rendered HTML
 * (no loopback crawling, no cache mismatch). The buffer runs the detector
 * pipeline, strips now-useless resource hints, injects metric fallbacks and
 * smart preloads, and logs what it hosted.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Frontend;

use EasyFonts\Detect\Consolidator;
use EasyFonts\Detect\Pipeline;
use EasyFonts\Detect\Providers;
use EasyFonts\Fonts\Downloader;
use EasyFonts\Fonts\Storage;
use EasyFonts\Fonts\UsageTracker;
use EasyFonts\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Buffers and processes frontend HTML.
 */
class OutputBuffer {

	/**
	 * Query params page builders set on their preview/edit screens.
	 *
	 * @var string[]
	 */
	const BUILDER_PARAMS = array(
		'elementor-preview',
		'et_fb',
		'et_pb_preview',
		'fl_builder',
		'fl_builder_preview',
		'ct_builder',
		'ct_inner',
		'oxygen_iframe',
		'tve',
		'brizy-edit',
		'brizy-edit-iframe',
		'vc_action',
		'vc_editable',
		'vcv-action',
		'siteorigin_panels_live_editor',
		'op3editor',
		'fb-edit',
		'bricks',
		'builder',
		'wp-customize',
		'customize_changeset_uuid',
		'perfmatters',
	);

	/**
	 * @var HtmlProcessor
	 */
	private HtmlProcessor $html;

	/**
	 * @var Pipeline
	 */
	private Pipeline $pipeline;

	/**
	 * @var bool
	 */
	private bool $force;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'template_redirect', array( $this, 'start' ), 4 );
		add_action( 'login_init', array( $this, 'start' ), 4 );
	}

	/**
	 * Start buffering if allowed.
	 */
	public function start(): void {
		if ( ! $this->should_run() ) {
			return;
		}

		$this->html     = new HtmlProcessor();
		$this->pipeline = new Pipeline();
		$this->force    = $this->is_probe();

		// Only warm (probe) requests may download font binaries. A normal visitor
		// render serves cached fonts only and never blocks on remote downloads —
		// this is the core fix for the output-buffer stall / white screen.
		Downloader::set_allow_fonts( $this->force );

		ob_start( array( $this, 'process' ) );
	}

	/**
	 * Buffer callback.
	 *
	 * @param string $buffer Full HTML.
	 * @return string
	 */
	public function process( string $buffer ): string {
		if ( '' === trim( $buffer ) || ! $this->looks_like_html( $buffer ) ) {
			return $buffer;
		}

		$original = $buffer;

		try {
			// Resolve the page identity once (canonical route + device).
			$route  = UsageTracker::current_route();
			$device = wp_is_mobile() ? 'mobile' : 'desktop';

			// Page scope: the families a beacon has seen actually used on this
			// route. A normal render is scoped to those (so a globally-declared
			// font that isn't used here is left off the page); a warm/probe is
			// NOT scoped, so it downloads every declared font and keeps the
			// shared cache complete for the routes that do use them. With no
			// beacon data yet, scoping is off and every declared font loads —
			// so the first visit still self-hosts everything, and later visits
			// trim down once the page has been measured.
			// Page scope + preload, from one per-route query. We scope out only
			// fonts a beacon CONFIRMED are loaded-but-unused here — never a font
			// that simply hasn't been measured yet (so a just-added/replaced
			// Customizer font loads immediately, then gets judged by the beacon).
			// A warm/probe is never scoped, so it downloads every declared font
			// and keeps the shared cache complete.
			$tracker    = new UsageTracker();
			$profile    = $this->force
				? array( 'scope_out' => array(), 'above_fold' => array(), 'measured' => false )
				: $tracker->route_render_profile( $route );
			$scope_out  = $profile['scope_out'];
			$above_fold = $profile['above_fold'];
			$measured   = ! empty( $profile['measured'] );

			// 1. Detect + localise. The consolidator is transactional: it only
			//    rewrites the page when every font it needs is already hosted
			//    locally; otherwise it returns the page untouched and flags a warm.
			$buffer = $this->pipeline->process( $buffer, $this->force, $scope_out, $measured );

			// Did we actually commit a localization this run (consolidated sheet
			// and/or an in-place local stylesheet rewrite)?
			$localized = Consolidator::localized();

			if ( $localized ) {
				// 2. Strip now-useless Google resource hints (only when we've
				//    actually localised — otherwise the page still uses the
				//    provider and needs its preconnect/dns-prefetch hints).
				if ( Settings::get( 'strip_hints', 1 ) ) {
					$stripped = $this->html->strip_font_hints( $buffer, Providers::hint_hosts() );
					$buffer   = $stripped['html'];
				}

				// 3. Inject preloads → consolidated stylesheet → metric fallbacks.
				$head = $this->build_head( $route, $device, $above_fold );

				if ( '' !== $head ) {
					$buffer = $this->html->before_head_close( $buffer, $head );
				}

				// 4. Log what we hosted this request (origin=buffer).
				$this->log_usage( $route );
			}

			// Fail-safe: never hand back an empty document.
			if ( '' === $buffer ) {
				$buffer = $original;
			}
		} catch ( \Throwable $e ) {
			// Whatever went wrong, never white-screen the site: return the page
			// exactly as it came in.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'EasyFonts: output buffer failed — ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}

			$buffer = $original;
		}

		// If the page needs fonts that aren't cached yet, warm the cache in the
		// background via a non-blocking loopback (no wp-cron). Never from a warm
		// request itself (avoids loops).
		if ( ! $this->force && Consolidator::needs_warm() ) {
			$this->schedule_warm();
		}

		return $buffer;
	}

	/**
	 * Spawn a single non-blocking loopback request that warms the cache for the
	 * current URL (downloads the missing fonts off the visitor's render path).
	 * Rate-limited per URL so a burst of traffic can't fan out into many self
	 * requests. No wp-cron involved.
	 */
	private function schedule_warm(): void {
		$url  = home_url( add_query_arg( array() ) );
		$lock = 'easyfonts_warm_' . md5( UsageTracker::route_for( $url ) );

		// One in-flight warm per route at a time.
		if ( get_transient( $lock ) ) {
			return;
		}

		set_transient( $lock, 1, 60 );

		$target = add_query_arg(
			array(
				'easyfonts_probe' => Settings::warm_key(),
				'efbust'          => (string) time(),
			),
			$url
		);

		wp_remote_get(
			$target,
			array(
				'blocking'  => false,
				'timeout'   => 0.01,
				'sslverify' => (bool) apply_filters( 'easyfonts_loopback_sslverify', true ),
				'headers'   => array( 'X-EasyFonts-Warm' => '1' ),
			)
		);
	}

	/**
	 * Build the ordered head block: preloads first (so the browser fetches the
	 * hero fonts before it even parses the stylesheet), then the consolidated
	 * stylesheet, then the metric-matched fallback faces.
	 *
	 * @param string             $route      Canonical route for this page.
	 * @param string             $device     Device ('mobile'|'desktop').
	 * @param array<string,bool> $above_fold Lowercased families above the fold on this route.
	 * @return string
	 */
	private function build_head( string $route = '', string $device = 'any', array $above_fold = array() ): string {
		$out        = '';
		$stylesheet = Consolidator::stylesheet_url();

		// Preloads — above the stylesheet, deduped inside SmartPreload, scoped
		// to the fonts actually emitted on this page (Consolidator::touched).
		$out .= ( new SmartPreload() )->build( $route, $device, $this->present_families(), $above_fold );

		// The single consolidated stylesheet — either inlined (non-render-
		// blocking: no extra request) or as a normal <link>.
		if ( '' !== $stylesheet ) {
			$inlined = false;

			if ( Settings::get( 'inline_css', 0 ) ) {
				$file = Consolidator::stylesheet_file();

				if ( '' !== $file ) {
					$storage = new Storage();
					$css     = $storage->exists( $file ) ? (string) @file_get_contents( $storage->path( $file ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions

					if ( '' !== $css ) {
						$out    .= '<style id="easyfonts-fonts">' . $this->minify_css( $css ) . '</style>' . "\n";
						$inlined = true;
					}
				}
			}

			if ( ! $inlined ) {
				$out .= sprintf(
					'<link rel="stylesheet" id="easyfonts-fonts" href="%s" media="all">' . "\n",
					esc_url( $stylesheet )
				);
			}
		}

		// Metric-matched fallback faces (zero-CLS).
		$touched = Consolidator::touched();

		if ( Settings::get( 'metric_fallbacks', 1 ) && ! empty( $touched ) ) {
			$css = ( new MetricFallbacks() )->build_for( $touched );

			if ( '' !== $css ) {
				$out .= '<style id="easyfonts-fallbacks">' . $css . '</style>' . "\n";
			}
		}

		return $out;
	}

	/**
	 * Lowercased set of families actually emitted on this page (from the
	 * consolidator's "touched" list), used to gate preload so a font is never
	 * preloaded on a page that doesn't use it.
	 *
	 * @return array<string,bool>
	 */
	private function present_families(): array {
		$out = array();

		foreach ( Consolidator::touched() as $t ) {
			$out[ strtolower( (string) $t['family'] ) ] = true;
		}

		return $out;
	}

	/**
	 * Persist hosted-font observations for this URL.
	 *
	 * @param string $route Canonical route (path) for this page.
	 */
	private function log_usage( string $route = '' ): void {
		$touched = Consolidator::touched();

		if ( empty( $touched ) ) {
			return;
		}

		// Key usage by the canonical route (path only). This keeps the buffer
		// and the browser beacon on one identity per page, and prevents volatile
		// query params (the warm cache-buster efbust, the easyfonts_probe key,
		// builder/tracking flags) from minting duplicate rows.
		if ( '' === $route ) {
			$route = UsageTracker::current_route();
		}

		// Throttle this bookkeeping write to at most once per route per window.
		// These rows only record "the plugin hosted these fonts here" (for the
		// usage dashboard and GC freshness); the beacon — which drives page
		// scoping and preload — records separately and is NOT throttled here. A
		// warm/probe render always logs so "Optimize" reflects immediately.
		if ( ! $this->force ) {
			$lock = 'easyfonts_logged_' . md5( $route );

			if ( get_transient( $lock ) ) {
				return;
			}

			$ttl = (int) apply_filters( 'easyfonts_usage_log_throttle', HOUR_IN_SECONDS );

			if ( $ttl > 0 ) {
				set_transient( $lock, 1, $ttl );
			}
		}

		$records = array();

		foreach ( $touched as $t ) {
			$records[] = array(
				'family'   => $t['family'],
				'weight'   => $t['weight'],
				'style'    => $t['style'],
				'origin'   => 'buffer',
				'rendered' => 0,
			);
		}

		( new UsageTracker() )->record( $route, (int) get_queried_object_id(), $records );
	}

	/**
	 * Minify CSS for inlining: strip comments and collapse whitespace, while
	 * preserving values that matter (unicode-range, url(), font names). Safe and
	 * conservative — it never touches the inside of strings/urls.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	private function minify_css( string $css ): string {
		$input = $css;

		// Remove /* ... */ comments (incl. the generated banner).
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		if ( ! is_string( $css ) ) {
			return $input;
		}

		// Collapse all runs of whitespace to a single space.
		$css = preg_replace( '/\s+/', ' ', $css );
		if ( ! is_string( $css ) ) {
			return $input;
		}

		// Drop spaces around structural punctuation.
		$css = preg_replace( '/\s*([{};:,>])\s*/', '$1', $css );
		if ( ! is_string( $css ) ) {
			return $input;
		}

		// Remove the last semicolon before a closing brace, and any leftover gaps.
		$css = str_replace( ';}', '}', $css );

		return trim( $css );
	}

	/**
	 * Is this request a front-end page-builder EDITOR (not the live site)?
	 * Covers the major builders' edit modes via their own API where available,
	 * plus a query-string fallback. Conservative: any hit means "don't process".
	 *
	 * @return bool
	 */
	private static function is_builder_editor(): bool {
		// Elementor edit/preview mode.
		if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) ) {
			$el = \Elementor\Plugin::instance();
			if ( isset( $el->editor ) && method_exists( $el->editor, 'is_edit_mode' ) && $el->editor->is_edit_mode() ) {
				return true;
			}
			if ( isset( $el->preview ) && method_exists( $el->preview, 'is_preview_mode' ) && $el->preview->is_preview_mode() ) {
				return true;
			}
		}

		// Beaver Builder active edit session.
		if ( class_exists( '\FLBuilderModel' ) && method_exists( '\FLBuilderModel', 'is_builder_active' ) && \FLBuilderModel::is_builder_active() ) {
			return true;
		}

		// Divi front-end builder.
		if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
			return true;
		}

		// Query-string fallback for builders without a stable API check here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$flags = array( 'fl_builder', 'elementor-preview', 'et_fb', 'ct_builder', 'oxygen_iframe', 'brizy-edit', 'vcv-action', 'tve', 'bricks' );
		foreach ( $flags as $f ) {
			if ( isset( $_GET[ $f ] ) ) {
				return true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return false;
	}

	/**
	 * Should the buffer run for this request?
	 *
	 * @return bool
	 */
	private function should_run(): bool {
		if ( ! Settings::get( 'enabled', 1 ) ) {
			return false;
		}

		// Hard environment bail-outs.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) {
			return false;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}

		// Explicit kill switch.
		if ( isset( $_GET['easyfonts'] ) && 'off' === $_GET['easyfonts'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		// Probe mode always runs (admin only).
		if ( $this->is_probe() ) {
			return true;
		}

		// Builder previews / customizer.
		foreach ( self::BUILDER_PARAMS as $param ) {
			if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return false;
			}
		}

		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}

		// Front-end page-builder EDIT modes (not just previews). When a builder
		// loads its editor on a front-end URL, we must not strip/replace fonts
		// or the user can't see real font styling while editing.
		if ( self::is_builder_editor() ) {
			return false;
		}

		/**
		 * Allow themes/plugins to force-skip Easy Fonts for the current request
		 * (e.g. bespoke editors). Return true to skip.
		 *
		 * @param bool $skip
		 */
		if ( apply_filters( 'easyfonts_skip_request', false ) ) {
			return false;
		}

		// PageSpeed=off convention.
		if ( isset( $_GET['PageSpeed'] ) && 'off' === $_GET['PageSpeed'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		// Excluded URLs. Patterns are matched against the request PATH with
		// explicit, anchored semantics (no loose substring matching, which made
		// "/cart" also exclude "/cart-guide"):
		//   - a pattern containing "*" is a glob (fnmatch), e.g. "/shop/*"
		//   - otherwise it matches the exact path, or the path as a path-segment
		//     prefix, e.g. "/cart" matches "/cart" and "/cart/checkout" but NOT
		//     "/cart-guide".
		$excluded = (array) Settings::get( 'excluded_urls', array() );
		$path     = (string) wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_PATH );
		$path     = '' === $path ? '/' : $path;

		foreach ( $excluded as $ex ) {
			$ex = trim( (string) $ex );

			if ( '' === $ex ) {
				continue;
			}

			if ( false !== strpos( $ex, '*' ) ) {
				if ( fnmatch( $ex, $path ) ) {
					return false;
				}
				continue;
			}

			$norm = '/' . ltrim( $ex, '/' );

			if ( $path === $norm || $path === rtrim( $norm, '/' ) ) {
				return false;
			}

			if ( 0 === strpos( $path, rtrim( $norm, '/' ) . '/' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Is this a "warm" (probe) request that may download fonts and force a full
	 * uncached pass? Authorised either by an admin (?easyfonts_probe=1 in the
	 * browser) or by the secret warm key (used by the background loopback, the
	 * admin "Optimize" action and WP-CLI). The key path lets an unauthenticated
	 * self-request be trusted without wp-cron.
	 *
	 * @return bool
	 */
	private function is_probe(): bool {
		if ( ! isset( $_GET['easyfonts_probe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$provided = (string) wp_unslash( $_GET['easyfonts_probe'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$key      = (string) get_option( 'easyfonts_warm_key', '' );

		return '' !== $key && hash_equals( $key, $provided );
	}

	/**
	 * Quick HTML sniff (skip JSON/feeds/fragments).
	 *
	 * @param string $buffer Buffer.
	 * @return bool
	 */
	private function looks_like_html( string $buffer ): bool {
		$head = ltrim( substr( $buffer, 0, 200 ) );

		return ( false !== stripos( $head, '<!doctype html' ) || false !== stripos( $head, '<html' ) );
	}
}
