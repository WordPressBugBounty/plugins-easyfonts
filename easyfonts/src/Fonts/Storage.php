<?php
/**
 * Font cache storage.
 *
 * All writes are atomic (tmp + rename) so a concurrent request never serves a
 * half-written CSS or font file.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Fonts;

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes files in the cache directory.
 */
class Storage {

	/**
	 * Ensure the cache directory exists (and is shielded from listing).
	 *
	 * @return bool
	 */
	public function ensure_dir(): bool {
		if ( is_dir( EASYFONTS_CACHE_DIR ) ) {
			return true;
		}

		$made = wp_mkdir_p( EASYFONTS_CACHE_DIR );

		if ( $made ) {
			$index = trailingslashit( EASYFONTS_CACHE_DIR ) . 'index.html';

			if ( ! file_exists( $index ) ) {
				@file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		return $made;
	}

	/**
	 * Absolute path for a cache-relative filename.
	 *
	 * Defence-in-depth: `..` segments are stripped so a filename can never
	 * escape the cache directory, even though filenames are internally
	 * generated (hashes / slugs) and not user-supplied.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	public function path( string $filename ): string {
		$filename = str_replace( array( '../', '..\\', "\0" ), '', ltrim( $filename, '/' ) );

		return trailingslashit( EASYFONTS_CACHE_DIR ) . $filename;
	}

	/**
	 * Public URL for a cache-relative filename.
	 *
	 * When a CDN base is configured (Settings → cdn_url), the site origin is
	 * swapped for the CDN origin while the path is preserved — the standard
	 * pull-zone model. This one method is the single emission point for the
	 * consolidated stylesheet, every @font-face src, localised external CSS,
	 * and all preloads, so the CDN applies everywhere consistently.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	public function url( string $filename ): string {
		$url = trailingslashit( EASYFONTS_CACHE_URL ) . ltrim( $filename, '/' );

		return self::cdn_rewrite( $url );
	}

	/**
	 * Rewrite a same-origin asset URL onto the configured CDN origin.
	 *
	 * The CDN value is treated as an origin (scheme + host[/optional base]);
	 * the asset path is preserved. Returns the URL unchanged when no CDN is set,
	 * the CDN is invalid, or the URL isn't same-origin.
	 *
	 * @param string $url Absolute same-origin URL.
	 * @return string
	 */
	public static function cdn_rewrite( string $url ): string {
		$cdn = self::cdn_base();

		if ( '' === $cdn || '' === $url ) {
			return $url;
		}

		$home = wp_parse_url( home_url() );

		if ( empty( $home['host'] ) ) {
			return $url;
		}

		$origin = ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . $home['host']
			. ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		// Only rewrite our own origin; never touch third-party URLs.
		if ( 0 === strpos( $url, $origin ) ) {
			return $cdn . substr( $url, strlen( $origin ) );
		}

		// Protocol-relative or root-relative cache URLs (rare) → prefix the CDN.
		if ( 0 === strpos( $url, '//' ) ) {
			return $cdn . substr( $url, strpos( $url, '/', 2 ) );
		}

		return $url;
	}

	/**
	 * Normalised CDN origin from settings ('' when unset/invalid). Cached.
	 *
	 * @return string
	 */
	private static function cdn_base(): string {
		static $base = null;

		if ( null !== $base ) {
			return $base;
		}

		$raw = trim( (string) \EasyFonts\Settings::get( 'cdn_url', '' ) );

		if ( '' === $raw ) {
			return $base = '';
		}

		// Accept protocol-relative input by adopting the current scheme.
		if ( 0 === strpos( $raw, '//' ) ) {
			$raw = ( is_ssl() ? 'https:' : 'http:' ) . $raw;
		}

		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			return $base = '';
		}

		return $base = untrailingslashit( esc_url_raw( $raw ) );
	}

	/**
	 * Does a cache file exist?
	 *
	 * @param string $filename Filename.
	 * @return bool
	 */
	public function exists( string $filename ): bool {
		return is_file( $this->path( $filename ) );
	}

	/**
	 * File size in bytes (0 if missing).
	 *
	 * @param string $filename Filename.
	 * @return int
	 */
	public function size( string $filename ): int {
		$path = $this->path( $filename );

		return is_file( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * Atomically write content to a cache file.
	 *
	 * @param string $filename Filename (may include a subdir).
	 * @param string $content  Bytes.
	 * @return bool
	 */
	public function write( string $filename, string $content ): bool {
		$this->ensure_dir();

		$path = $this->path( $filename );
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$tmp = $path . '.' . wp_generate_password( 6, false ) . '.tmp';

		// phpcs:disable WordPress.WP.AlternativeFunctions
		$written = file_put_contents( $tmp, $content, LOCK_EX );

		if ( false === $written || $written < strlen( $content ) ) {
			@unlink( $tmp );
			return false;
		}

		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return false;
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions

		return true;
	}

	/**
	 * Total cache size in bytes.
	 *
	 * @return int
	 */
	public function total_size(): int {
		if ( ! is_dir( EASYFONTS_CACHE_DIR ) ) {
			return 0;
		}

		$bytes = 0;
		$iter  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( EASYFONTS_CACHE_DIR, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iter as $file ) {
			$bytes += $file->getSize();
		}

		return $bytes;
	}

	/**
	 * Remove every cached file.
	 */
	public function purge(): void {
		if ( ! is_dir( EASYFONTS_CACHE_DIR ) ) {
			return;
		}

		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( EASYFONTS_CACHE_DIR, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		// phpcs:disable WordPress.WP.AlternativeFunctions
		foreach ( $iter as $file ) {
			$file->isDir() ? @rmdir( $file->getRealPath() ) : @unlink( $file->getRealPath() );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}
}
