<?php
/**
 * Font-provider URL recognition.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Detect;

defined( 'ABSPATH' ) || exit;

/**
 * Knows which URLs are font-provider stylesheets and which provider they are.
 */
class Providers {

	/**
	 * Hostnames that serve font CSS (incl. GDPR proxies).
	 *
	 * @return string[]
	 */
	public static function css_hosts(): array {
		return apply_filters(
			'easyfonts_css_hosts',
			array(
				'fonts.googleapis.com',
				'fonts.bunny.net',
				'fonts-api.wp.com',
			)
		);
	}

	/**
	 * Hostnames + domains used for resource hints.
	 *
	 * @return string[]
	 */
	public static function hint_hosts(): array {
		return array(
			'fonts.googleapis.com',
			'fonts.gstatic.com',
			'fonts.bunny.net',
			'fonts-api.wp.com',
		);
	}

	/**
	 * Is this a provider stylesheet URL (css / css2 / icon)?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_css_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $host ) {
			return false;
		}

		foreach ( self::css_hosts() as $candidate ) {
			if ( self::host_matches( $host, $candidate )
				&& ( 0 === strpos( $path, '/css' ) || 0 === strpos( $path, '/icon' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does $host equal an allowed host or a subdomain of it? Parsed-host match
	 * (not substring), so a crafted URL like https://evil.test/fonts.googleapis.com/css
	 * — whose real host is evil.test — is correctly rejected.
	 *
	 * @param string $host      Lowercased host from the URL under test.
	 * @param string $candidate Allowed host.
	 * @return bool
	 */
	private static function host_matches( string $host, string $candidate ): bool {
		$candidate = strtolower( trim( $candidate ) );

		if ( '' === $candidate ) {
			return false;
		}

		return $host === $candidate || substr( $host, -strlen( '.' . $candidate ) ) === '.' . $candidate;
	}

	/**
	 * Provider id for a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function id_for( string $url ): string {
		if ( false !== strpos( $url, 'bunny.net' ) ) {
			return 'bunny';
		}

		if ( false !== strpos( $url, 'wp.com' ) ) {
			return 'wp';
		}

		return 'google';
	}

	/**
	 * Normalize a URL to an absolute, fetchable form.
	 *
	 * Handles the cases that broke detection: protocol-relative `//host/...`
	 * (the main bug — wp_remote_get can't fetch these) and root-relative
	 * `/path`. HTML-decodes `&amp;` etc. so query strings survive.
	 *
	 * @param string $url      Raw URL (possibly `//…`, `/…`, or entity-encoded).
	 * @param string $base_url Optional base for resolving truly relative paths.
	 * @return string
	 */
	public static function normalize_url( string $url, string $base_url = '' ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return $url;
		}

		// Protocol-relative → add the current scheme.
		if ( 0 === strpos( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}

		// Already absolute.
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		// Root-relative → site root.
		if ( 0 === strpos( $url, '/' ) ) {
			return home_url( $url );
		}

		// Truly relative → resolve against the base directory.
		if ( '' !== $base_url ) {
			return self::resolve_relative( $url, $base_url );
		}

		return $url;
	}

	/**
	 * Does this URL point at a known font-provider host (any path)?
	 *
	 * Broader than is_css_url(): also matches gstatic font binaries and any
	 * provider host, so we can recognise remote @font-face src values.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_provider_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		foreach ( self::hint_hosts() as $candidate ) {
			if ( self::host_matches( $host, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a relative URL against a base URL's directory.
	 *
	 * @param string $relative Relative path.
	 * @param string $base_url Absolute base URL.
	 * @return string
	 */
	private static function resolve_relative( string $relative, string $base_url ): string {
		$parts = wp_parse_url( $base_url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $relative;
		}

		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$dir    = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';

		// Collapse ../ segments.
		$path = $dir . $relative;
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $segments );
			} elseif ( '.' !== $segment && '' !== $segment ) {
				$segments[] = $segment;
			}
		}

		return $origin . '/' . implode( '/', $segments );
	}
}
