<?php
/**
 * Plugin Name:       Easy Fonts
 * Plugin URI:        https://fluxpress.io
 * Description:       Detect, self-host, preload, and trim Google Fonts automatically — reliably, with zero-CLS metric-matched fallbacks. Built on WordPress's native HTML API.
 * Version:           2.0.2
 * Author:            Uzair
 * Author URI:        https://fluxpress.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       easyfonts
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Requires PHP:      7.4
 *
 * @package EasyFonts
 */

defined( 'ABSPATH' ) || exit;

define( 'EASYFONTS_VERSION', '2.0.2' );
define( 'EASYFONTS_FILE', __FILE__ );
define( 'EASYFONTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EASYFONTS_URL', plugin_dir_url( __FILE__ ) );
define( 'EASYFONTS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PHP 7.4 polyfills. The plugin advertises "Requires PHP: 7.4", but parts of the
 * codebase use string helpers added in PHP 8.0. These guarded shims keep it
 * genuinely 7.4-compatible without changing any call sites.
 */
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $haystack, $needle ): bool {
		return '' === $needle || 0 === strncmp( (string) $haystack, (string) $needle, strlen( (string) $needle ) );
	}
}
if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $haystack, $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}
		$len = strlen( (string) $needle );
		return $len <= strlen( (string) $haystack ) && 0 === substr_compare( (string) $haystack, (string) $needle, -$len );
	}
}
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $haystack, $needle ): bool {
		return '' === $needle || false !== strpos( (string) $haystack, (string) $needle );
	}
}

/**
 * PSR-4 autoloader for the EasyFonts\ namespace.
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'EasyFonts\\';
		$len    = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}

		$relative = substr( $class, $len );
		$path     = EASYFONTS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Resolve the upload directory + URL once. Scheme-correct, multisite-safe.
 *
 * @return array{path:string,url:string}
 */
function easyfonts_paths(): array {
	static $paths = null;

	if ( null === $paths ) {
		$upload = wp_upload_dir();
		$paths  = array(
			'path' => trailingslashit( $upload['basedir'] ) . 'easyfonts',
			'url'  => set_url_scheme( trailingslashit( $upload['baseurl'] ) . 'easyfonts' ),
		);
	}

	return $paths;
}

/**
 * Boot the plugin on plugins_loaded.
 */
add_action(
	'plugins_loaded',
	static function () {
		$paths = easyfonts_paths();

		if ( ! defined( 'EASYFONTS_CACHE_DIR' ) ) {
			define( 'EASYFONTS_CACHE_DIR', $paths['path'] );
		}
		if ( ! defined( 'EASYFONTS_CACHE_URL' ) ) {
			define( 'EASYFONTS_CACHE_URL', $paths['url'] );
		}

		\EasyFonts\Plugin::instance();
	}
);

/**
 * WP-CLI registration (loaded outside the normal request lifecycle).
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action(
		'cli_init',
		static function () {
			\WP_CLI::add_command( 'easyfonts', \EasyFonts\Cli\Command::class );
		}
	);
}

/**
 * Activation — create tables, seed defaults, mark for rewrite.
 *
 * On multisite the activation hook fires once even for a network-wide enable,
 * so when network-activated we install on every existing site. New sites
 * created later are handled by the wp_initialize_site hook below, and any site
 * that somehow slips through self-heals on its first load (Plugin::maybe_migrate).
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
register_activation_hook(
	__FILE__,
	static function ( $network_wide = false ) {
		require_once EASYFONTS_DIR . 'src/Database/Migrator.php';

		$install = static function () {
			( new \EasyFonts\Database\Migrator() )->install();
			add_option( 'easyfonts_activated_at', time() );
		};

		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				$install();
				restore_current_blog();
			}

			return;
		}

		$install();
	}
);

/**
 * Multisite: install tables when a brand-new subsite is created, if the plugin
 * is network-active. (WP 5.1+ passes a WP_Site; older signatures are tolerated.)
 *
 * @param mixed $site New site (WP_Site) or blog id.
 */
add_action(
	'wp_initialize_site',
	static function ( $site ) {
		if ( ! is_multisite() ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( EASYFONTS_BASENAME ) ) {
			return;
		}

		$blog_id = is_object( $site ) && isset( $site->blog_id ) ? (int) $site->blog_id : (int) $site;

		if ( $blog_id <= 0 ) {
			return;
		}

		require_once EASYFONTS_DIR . 'src/Database/Migrator.php';

		switch_to_blog( $blog_id );
		( new \EasyFonts\Database\Migrator() )->install();
		restore_current_blog();
	},
	10,
	1
);

/**
 * Deactivation — clear scheduled events.
 */
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'easyfonts_cleanup' );
	}
);
