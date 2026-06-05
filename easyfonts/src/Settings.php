<?php
/**
 * Settings accessor.
 *
 * @package EasyFonts
 */

namespace EasyFonts;

use EasyFonts\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Thin, cached wrapper around the single settings option.
 */
class Settings {

	const OPTION = 'easyfonts_settings';

	/**
	 * Per-request cache.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Get all settings (merged over defaults).
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = is_array( $stored )
				? array_merge( Migrator::default_settings(), $stored )
				: Migrator::default_settings();
		}

		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();

		return $all[ $key ] ?? $default;
	}

	/**
	 * Persist the full settings array.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	public static function save( array $settings ): void {
		update_option( self::OPTION, $settings, false );
		self::$cache = null;
	}

	/**
	 * Flush the cache (after external writes).
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Has a detector been learned as "active" for this site?
	 *
	 * @param string $detector Detector id.
	 * @return bool
	 */
	public static function detector_active( string $detector ): bool {
		$detectors = (array) self::get( 'detectors', array() );

		return in_array( $detector, $detectors, true );
	}

	/**
	 * Mark a detector active (Auto-Config self-learning). Persists only on change.
	 *
	 * @param string $detector Detector id.
	 */
	public static function learn_detector( string $detector ): void {
		// Respect the Auto-Config toggle: when off, freeze the learned set.
		if ( ! self::get( 'auto_config', 1 ) ) {
			return;
		}

		$all       = self::all();
		$detectors = (array) ( $all['detectors'] ?? array() );

		if ( in_array( $detector, $detectors, true ) ) {
			return;
		}

		$detectors[]       = $detector;
		$all['detectors']  = array_values( array_unique( $detectors ) );
		self::save( $all );
	}

	/**
	 * Current cache-buster timestamp (appended to served URLs).
	 *
	 * @return int
	 */
	public static function buster(): int {
		return (int) get_option( 'easyfonts_cache_buster', 0 );
	}

	/**
	 * Secret key authorising background cache-warming loopback requests.
	 * Generated lazily and stored autoloaded. Lets an unauthenticated self-request
	 * (?easyfonts_probe=KEY) be trusted to perform downloads, without wp-cron.
	 *
	 * @return string
	 */
	public static function warm_key(): string {
		$key = (string) get_option( 'easyfonts_warm_key', '' );

		if ( '' === $key ) {
			$key = wp_generate_password( 40, false, false );
			update_option( 'easyfonts_warm_key', $key, true );
		}

		return $key;
	}

	/**
	 * Bump the cache-buster (after re-optimisation / purge).
	 */
	public static function bump_buster(): void {
		update_option( 'easyfonts_cache_buster', time(), false );
	}
}
