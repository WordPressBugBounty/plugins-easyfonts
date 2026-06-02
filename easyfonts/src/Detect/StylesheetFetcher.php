<?php
/**
 * Fetches the *contents* of linked stylesheets so fonts hidden inside theme /
 * plugin / CDN CSS can be detected — the capability plain output-buffer
 * scanners miss.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Detect;

use EasyFonts\Fonts\Downloader;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves stylesheet URLs to CSS contents (disk for local, HTTP for external).
 */
class StylesheetFetcher {

	/**
	 * Already-processed URLs this request (avoids duplicate work).
	 *
	 * @var array<string,bool>
	 */
	private array $seen = array();

	/**
	 * Read a same-origin stylesheet from disk.
	 *
	 * @param string $url Absolute or root-relative URL.
	 * @return string|null
	 */
	public function read_local( string $url ): ?string {
		$url = $this->normalize( $url );

		if ( isset( $this->seen[ $url ] ) ) {
			return null;
		}

		$this->seen[ $url ] = true;

		$path = $this->url_to_path( $url );

		if ( null === $path || ! is_file( $path ) ) {
			return null;
		}

		$css = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return false === $css ? null : $css;
	}

	/**
	 * Fetch an external stylesheet over HTTP (once).
	 *
	 * @param string     $url        URL.
	 * @param Downloader $downloader Downloader.
	 * @return string|null
	 */
	public function read_external( string $url, Downloader $downloader ): ?string {
		$url = $this->normalize( $url );

		if ( isset( $this->seen[ $url ] ) ) {
			return null;
		}

		$this->seen[ $url ] = true;

		return $downloader->fetch_css( $url );
	}

	/**
	 * Is this URL same-origin?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_local( string $url ): bool {
		$url  = $this->normalize( $url );
		$home = home_url();

		return str_starts_with( $url, $home ) || str_starts_with( $url, '/' );
	}

	/**
	 * Should we skip this stylesheet entirely (core, our own cache, etc.)?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_skippable( string $url ): bool {
		foreach ( array( 'wp-includes', '/easyfonts/', 'fonts.googleapis.com', 'fonts.bunny.net' ) as $needle ) {
			if ( false !== strpos( $url, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map a same-origin URL to a filesystem path.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	private function url_to_path( string $url ): ?string {
		$url = strtok( $url, '?' ); // Drop query (dynamic CSS handled elsewhere).

		$content_url = content_url();

		if ( str_starts_with( $url, $content_url ) ) {
			return str_replace( $content_url, WP_CONTENT_DIR, $url );
		}

		$home = home_url();

		if ( str_starts_with( $url, $home ) ) {
			return str_replace( $home, untrailingslashit( ABSPATH ), $url );
		}

		if ( str_starts_with( $url, '/' ) ) {
			return untrailingslashit( ABSPATH ) . $url;
		}

		return null;
	}

	/**
	 * Normalize protocol-relative / root-relative URLs to absolute.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function normalize( string $url ): string {
		$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );

		if ( str_starts_with( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}

		if ( str_starts_with( $url, '/' ) ) {
			return home_url( $url );
		}

		return $url;
	}
}
