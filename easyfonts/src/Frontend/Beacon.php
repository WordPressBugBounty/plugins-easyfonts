<?php
/**
 * Frontend script loader: usage beacon + X-ray overlay.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Frontend;

use EasyFonts\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the runtime beacon and (for admins) the X-ray overlay.
 */
class Beacon {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		// The async blocker must run before any theme/plugin script can inject a
		// provider <link>, so it loads as early as possible in <head>.
		add_action( 'wp_head', array( $this, 'print_async_blocker' ), 1 );
	}

	/**
	 * Print the async-injection blocker inline, very early in <head>.
	 *
	 * It intercepts runtime-injected Google/Bunny stylesheet links (which the
	 * server buffer can't see), neutralises the remote request, and reports the
	 * URLs so the next render self-hosts them. Front-end only; respects the
	 * master + async toggles and skips admins' builder/preview screens.
	 */
	public function print_async_blocker(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! Settings::get( 'enabled', 1 ) || ! Settings::get( 'async_blocker', 0 ) ) {
			return;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() || is_feed() ) {
			return;
		}

		$cfg = array(
			'endpoint' => esc_url_raw( rest_url( 'easyfonts/v1/async-fonts' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
		);

		$src = EASYFONTS_DIR . 'assets/frontend/async-blocker.js';

		if ( ! is_readable( $src ) ) {
			return;
		}

		$js = file_get_contents( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reading a bundled plugin asset.

		if ( false === $js || '' === $js ) {
			return;
		}

		// Config first, then the blocker. Printed via core's helper so the tag
		// is generated and escaped by WordPress (no manual echo of markup), and
		// runs early in <head> so it beats any font-injecting script.
		$inline = 'window.EasyFontsAsync=' . wp_json_encode( $cfg ) . ';' . $js;

		wp_print_inline_script_tag( $inline, array( 'id' => 'easyfonts-async-blocker' ) );
	}

	/**
	 * Enqueue scripts.
	 */
	public function enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		$route  = (string) wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_PATH );
		$device = wp_is_mobile() ? 'mobile' : 'desktop';

		if ( Settings::get( 'beacon', 1 ) ) {
			wp_enqueue_script(
				'easyfonts-beacon',
				EASYFONTS_URL . 'assets/frontend/beacon.js',
				array(),
				EASYFONTS_VERSION,
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);

			wp_localize_script(
				'easyfonts-beacon',
				'EasyFontsBeacon',
				array(
					'endpoint' => esc_url_raw( rest_url( 'easyfonts/v1/beacon' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'route'    => '' === $route ? '/' : $route,
					'device'   => $device,
				)
			);
		}

		// X-ray overlay: admins only, on demand.
		if ( current_user_can( 'manage_options' ) && isset( $_GET['easyfonts_xray'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_enqueue_script(
				'easyfonts-xray',
				EASYFONTS_URL . 'assets/frontend/xray.js',
				array(),
				EASYFONTS_VERSION,
				array( 'in_footer' => true )
			);
		}
	}
}
