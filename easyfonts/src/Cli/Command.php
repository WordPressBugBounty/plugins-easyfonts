<?php
/**
 * WP-CLI commands.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Cli;

use EasyFonts\Admin\Diagnostics;
use EasyFonts\Fonts\Registry;
use EasyFonts\Fonts\Storage;
use EasyFonts\Fonts\UsageTracker;
use EasyFonts\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Easy Fonts from the command line.
 */
class Command {

	/**
	 * Probe one or more URLs to detect + host their fonts.
	 *
	 * ## OPTIONS
	 *
	 * <url>...
	 * : One or more front-end URLs to probe.
	 *
	 * ## EXAMPLES
	 *
	 *     wp easyfonts scan https://example.com https://example.com/about
	 *
	 * @param string[] $args Positional args.
	 */
	public function scan( array $args ): void {
		foreach ( $args as $url ) {
			$probe = add_query_arg(
				array(
					'easyfonts_probe' => Settings::warm_key(),
					'efbust'          => (string) time(),
				),
				$url
			);

			$response = wp_remote_get(
				$probe,
				array(
					'timeout'   => 60,
					'cookies'   => $this->auth_cookies(),
					'sslverify' => (bool) apply_filters( 'easyfonts_loopback_sslverify', true ),
				)
			);

			if ( is_wp_error( $response ) ) {
				\WP_CLI::warning( sprintf( '%s — %s', $url, $response->get_error_message() ) );
				continue;
			}

			\WP_CLI::log( sprintf( 'Probed %s (HTTP %d)', $url, (int) wp_remote_retrieve_response_code( $response ) ) );
		}

		$stats = ( new Registry() )->stats();
		\WP_CLI::success( sprintf( '%d families / %d variants hosted.', $stats['families'], $stats['variants'] ) );
	}

	/**
	 * List hosted font families.
	 *
	 * @subcommand list
	 */
	public function list_fonts(): void {
		$grouped = ( new Registry() )->grouped();

		if ( empty( $grouped ) ) {
			\WP_CLI::log( 'No fonts hosted yet. Run: wp easyfonts scan <url>' );
			return;
		}

		$rows = array();

		foreach ( $grouped as $family ) {
			$rows[] = array(
				'family'   => $family['family'],
				'variants' => count( $family['variants'] ),
				'variable' => $family['is_variable'] ? 'yes' : 'no',
				'metrics'  => $family['has_metrics'] ? 'yes' : 'no',
				'size_kb'  => (int) round( $family['total_size'] / 1024 ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'family', 'variants', 'variable', 'metrics', 'size_kb' ) );
	}

	/**
	 * Purge all cached fonts, stylesheets, and learned state.
	 */
	public function purge(): void {
		( new Storage() )->purge();
		( new Registry() )->truncate();
		( new UsageTracker() )->truncate();

		delete_option( 'easyfonts_processed_local' );
		delete_option( 'easyfonts_processed_external' );
		delete_option( 'easyfonts_warm_key' ); // Rotate the loopback secret.

		$settings              = Settings::all();
		$settings['detectors'] = array();
		Settings::save( $settings );
		Settings::bump_buster();

		\WP_CLI::success( 'Cache and learned state cleared.' );
	}

	/**
	 * Drop and recreate the database tables (fixes a stale/legacy schema).
	 */
	public function repair(): void {
		( new \EasyFonts\Database\Migrator() )->repair();
		( new Storage() )->purge();

		delete_option( 'easyfonts_processed_local' );
		delete_option( 'easyfonts_processed_external' );
		delete_option( 'easyfonts_warm_key' ); // Rotate the loopback secret.

		$settings              = Settings::all();
		$settings['detectors'] = array();
		Settings::save( $settings );
		Settings::bump_buster();

		\WP_CLI::success( 'Tables rebuilt and cache cleared.' );
	}

	/**
	 * Print a diagnostics report as JSON.
	 */
	public function diagnostics(): void {
		\WP_CLI::log( wp_json_encode( Diagnostics::collect(), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Build a cookie jar so probes run as an authenticated admin if possible.
	 *
	 * @return array<int,\WP_Http_Cookie>
	 */
	private function auth_cookies(): array {
		return array();
	}
}
