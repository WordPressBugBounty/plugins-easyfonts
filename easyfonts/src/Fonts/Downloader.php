<?php
/**
 * Remote fetcher for stylesheets and font binaries.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Fonts;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads Google/Bunny CSS and font files.
 */
class Downloader {

	/**
	 * Whether remote font-binary downloads are allowed in the current request.
	 * Set to false during a normal visitor render (so the page is never blocked
	 * by synchronous downloads); true during admin/CLI/warm (probe) requests.
	 *
	 * @var bool
	 */
	private static bool $allow_fonts = true;

	/**
	 * Allow/disallow remote font-binary downloads for this request.
	 *
	 * @param bool $allow Allowed?
	 * @return void
	 */
	public static function set_allow_fonts( bool $allow ): void {
		self::$allow_fonts = $allow;
	}

	/**
	 * Are remote font-binary downloads currently allowed?
	 *
	 * @return bool
	 */
	public static function fonts_allowed(): bool {
		return self::$allow_fonts;
	}

	/**
	 * Modern desktop UA so the API returns woff2.
	 */
	const UA_WOFF2 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0';

	/**
	 * Transient value marking a recently-failed CSS fetch (negative cache).
	 */
	const FAIL_MARKER = '__easyfonts_fetch_failed__';

	/**
	 * Old UA that makes the API return TTF (used for metric extraction only).
	 */
	const UA_TTF = 'Mozilla/5.0 (Windows NT 6.1; rv:27.0) Gecko/20100101 Firefox/27.0';

	/**
	 * Content-Type => extension.
	 */
	const MIME = array(
		'font/woff2'                    => 'woff2',
		'application/font-woff2'        => 'woff2',
		'font/woff'                     => 'woff',
		'application/font-woff'         => 'woff',
		'font/ttf'                      => 'ttf',
		'application/x-font-ttf'        => 'ttf',
		'font/sfnt'                     => 'ttf',
		'application/font-sfnt'         => 'ttf',
		'font/otf'                      => 'otf',
		'application/x-font-opentype'   => 'otf',
		'application/vnd.ms-fontobject' => 'eot',
	);

	/**
	 * SSRF guard for stylesheet fetches. Unlike font binaries (locked to a
	 * provider allow-list), CSS may legitimately be fetched from ANY public host
	 * — that's how fonts hidden in third-party/CDN stylesheets are detected. So
	 * here we don't allow-list hosts; we deny-list internal targets: non-HTTP(S)
	 * schemes and any host that resolves to a private, reserved, loopback or
	 * link-local address (e.g. 127.0.0.1, 10/8, 192.168/16, 169.254.169.254).
	 * This blocks pointing the fetcher at internal services without affecting
	 * any real public stylesheet. Filterable for intranet installs.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public static function is_public_url( string $url ): bool {
		/**
		 * Allow fetching from private/internal hosts (intranet installs).
		 *
		 * @param bool   $allow Default false.
		 * @param string $url   The URL under test.
		 */
		if ( apply_filters( 'easyfonts_allow_private_fetch', false, $url ) ) {
			return true;
		}

		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = (string) ( $parts['host'] ?? '' );

		if ( ( 'http' !== $scheme && 'https' !== $scheme ) || '' === $host ) {
			return false;
		}

		// Collect candidate IPs: literal host, or resolved A/AAAA records.
		$ips = array();

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$v4 = gethostbynamel( $host );

			if ( is_array( $v4 ) ) {
				$ips = array_merge( $ips, $v4 );
			}

			if ( function_exists( 'dns_get_record' ) ) {
				$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

				if ( is_array( $v6 ) ) {
					foreach ( $v6 as $rec ) {
						if ( ! empty( $rec['ipv6'] ) ) {
							$ips[] = $rec['ipv6'];
						}
					}
				}
			}
		}

		// Couldn't resolve → not a known-internal target; let the HTTP layer
		// deal with it (it simply won't connect).
		if ( empty( $ips ) ) {
			return true;
		}

		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false; // private / reserved / loopback / link-local.
			}
		}

		return true;
	}

	/**
	 * Fetch CSS text from a font-provider URL.
	 *
	 * @param string $url Stylesheet URL.
	 * @return string|null
	 */
	public function fetch_css( string $url ): ?string {
		$url = \EasyFonts\Detect\Providers::normalize_url( $url );

		// Cache the fetched CSS so font detection doesn't hit the network on every
		// page render. Keyed by URL + cache-buster, so any settings/font change
		// (which bumps the buster) transparently refreshes it.
		$cache_key = 'easyfonts_css_' . md5( $url . '|' . \EasyFonts\Settings::buster() );
		$cached    = get_transient( $cache_key );

		// Negative cache: a recently-failed fetch is not retried on every render,
		// so a dead/blocked CSS host can never add its full timeout to each view.
		if ( self::FAIL_MARKER === $cached ) {
			return null;
		}

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		/**
		 * How long a failed stylesheet fetch is remembered before retrying.
		 *
		 * @param int $ttl Seconds.
		 */
		$fail_ttl = (int) apply_filters( 'easyfonts_css_fail_ttl', HOUR_IN_SECONDS );

		// SSRF guard: never fetch a stylesheet from an internal/private address.
		if ( ! self::is_public_url( $url ) ) {
			set_transient( $cache_key, self::FAIL_MARKER, $fail_ttl );
			return null;
		}

		$response = wp_remote_get(
			$url,
			array(
				/**
				 * Timeout for stylesheet fetches (seconds).
				 *
				 * @param int $timeout
				 */
				'timeout'    => (int) apply_filters( 'easyfonts_css_timeout', 10 ),
				'user-agent' => apply_filters( 'easyfonts_css_user_agent', self::UA_WOFF2 ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, self::FAIL_MARKER, $fail_ttl );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			set_transient( $cache_key, self::FAIL_MARKER, $fail_ttl );
			return null;
		}

		set_transient( $cache_key, $body, 7 * DAY_IN_SECONDS );

		return $body;
	}

	/**
	 * Download a font binary into the cache and return [filename, ext, size].
	 *
	 * @param string  $url      Font file URL.
	 * @param string  $basename Cache basename (without extension).
	 * @param Storage $storage  Storage.
	 * @return array{file:string,ext:string,size:int}|null
	 */
	public function fetch_font( string $url, string $basename, Storage $storage ): ?array {
		// Never download a font binary during a normal visitor page render — that
		// is what could stall the request and white-screen the site. On the render
		// path we serve only already-cached fonts; missing ones are fetched by a
		// background warm (probe) request. Admin/CLI/warm requests set this true.
		if ( ! self::$allow_fonts ) {
			return null;
		}

		$url = \EasyFonts\Detect\Providers::normalize_url( $url );

		// SSRF guard: only fetch font binaries from recognised provider hosts
		// (or the site's own origin). A crafted @font-face can otherwise point
		// src at an internal address; this blocks that without affecting any
		// legitimate Google/Bunny/wp.com font. Filterable for niche providers.
		if ( ! self::host_allowed( $url ) ) {
			return null;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => self::UA_WOFF2,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return null;
		}

		$ct  = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
		$ext = self::MIME[ $ct ] ?? $this->ext_from_url( $url );

		$filename = $basename . '.' . $ext;

		if ( ! $storage->write( $filename, $body ) ) {
			return null;
		}

		return array(
			'file' => $filename,
			'ext'  => $ext,
			'size' => strlen( $body ),
		);
	}

	/**
	 * Fetch the raw bytes of a TTF copy of a Google Fonts family for metrics.
	 * Returns the binary, never writes it to disk.
	 *
	 * @param string $css_url Original CSS URL.
	 * @return string|null
	 */
	public function fetch_ttf_bytes( string $css_url ): ?string {
		$response = wp_remote_get(
			$css_url,
			array(
				'timeout'    => 20,
				'user-agent' => self::UA_TTF,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$css = wp_remote_retrieve_body( $response );

		if ( ! preg_match( '/src\s*:\s*[^;]*?url\(\s*[\'"]?([^\'")]+\.(?:ttf|otf))[\'"]?\s*\)/i', $css, $m ) ) {
			return null;
		}

		// The font URL comes out of fetched CSS — guard it the same way.
		if ( ! self::is_public_url( $m[1] ) ) {
			return null;
		}

		$font = wp_remote_get( $m[1], array( 'timeout' => 20 ) );

		if ( is_wp_error( $font ) || 200 !== (int) wp_remote_retrieve_response_code( $font ) ) {
			return null;
		}

		$bytes = wp_remote_retrieve_body( $font );

		return '' === $bytes ? null : $bytes;
	}

	/**
	 * Fetch TTF bytes for a specific Google family/weight purely for metric
	 * extraction. Source-independent: we construct a css2 request from the
	 * family name, so it works whether the font came from a <link>, @import, or
	 * an inline @font-face. Returns binary, never written to disk.
	 *
	 * @param string $family Family name (e.g. "Open Sans").
	 * @param string $weight Representative numeric weight.
	 * @return string|null
	 */
	public function fetch_family_ttf( string $family, string $weight = '400' ): ?string {
		$weight = (string) absint( $weight );

		if ( '' === $weight || '0' === $weight ) {
			$weight = '400';
		}

		$url = 'https://fonts.googleapis.com/css2?family='
			. rawurlencode( $family ) . ':wght@' . $weight . '&display=swap';
		$url = str_replace( '%20', '+', $url );

		return $this->fetch_ttf_bytes( $url );
	}

	/**
	 * Is this URL host allowed for font-binary downloads? Provider font hosts
	 * plus the site's own origin. Filterable via `easyfonts_font_src_hosts`.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private static function host_allowed( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		/**
		 * Allowed host suffixes for downloading font binaries.
		 *
		 * @param string[] $hosts
		 */
		$allowed = apply_filters(
			'easyfonts_font_src_hosts',
			array(
				'fonts.gstatic.com',
				'fonts.googleapis.com',
				'fonts.bunny.net',
				'fonts.wp.com',
				'fonts-api.wp.com',
				's.w.org',
				'i0.wp.com',
				'i1.wp.com',
				'i2.wp.com',
			)
		);

		if ( '' !== $own ) {
			$allowed[] = $own;
		}

		foreach ( $allowed as $candidate ) {
			$candidate = strtolower( trim( (string) $candidate ) );

			if ( '' === $candidate ) {
				continue;
			}

			// Exact host or a subdomain of an allowed host.
			if ( $host === $candidate || substr( $host, -strlen( '.' . $candidate ) ) === '.' . $candidate ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Guess an extension from a URL path.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function ext_from_url( string $url ): string {
		$ext = strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		return in_array( $ext, array( 'woff2', 'woff', 'ttf', 'otf', 'eot' ), true ) ? $ext : 'woff2';
	}
}
