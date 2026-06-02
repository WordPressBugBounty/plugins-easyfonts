<?php
/**
 * @font-face parser.
 *
 * Uses a brace-aware scanner instead of a naive `\{([^}]+)\}` regex, so it
 * survives minified CSS, nested blocks, and data-URI sources.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Parses @font-face blocks out of arbitrary CSS.
 */
class FontFaceParser {

	/**
	 * Extract every @font-face block (full text + inner body) from CSS.
	 *
	 * @param string $css CSS source.
	 * @return array<int,array{full:string,body:string}>
	 */
	public function blocks( string $css ): array {
		$blocks = array();
		$offset = 0;
		$len    = strlen( $css );

		while ( $offset < $len ) {
			$at = stripos( $css, '@font-face', $offset );

			if ( false === $at ) {
				break;
			}

			// Find the opening brace after @font-face.
			$brace = strpos( $css, '{', $at );

			if ( false === $brace ) {
				break;
			}

			// Walk forward tracking depth so nested braces don't end the block early.
			$depth = 0;
			$end   = $brace;

			for ( $i = $brace; $i < $len; $i++ ) {
				$ch = $css[ $i ];

				if ( '{' === $ch ) {
					$depth++;
				} elseif ( '}' === $ch ) {
					$depth--;

					if ( 0 === $depth ) {
						$end = $i;
						break;
					}
				}
			}

			if ( $depth !== 0 ) {
				break; // Unbalanced; bail rather than mis-slice.
			}

			$full = substr( $css, $at, ( $end - $at ) + 1 );
			$body = substr( $css, $brace + 1, ( $end - $brace ) - 1 );

			// Capture an immediately-preceding /* subset */ comment, if any.
			$lead   = '';
			$before = substr( $css, 0, $at );

			if ( preg_match( '/\/\*([^*]*)\*\/\s*$/', $before, $cm ) ) {
				$lead = trim( $cm[1] );
			}

			$blocks[] = array(
				'full' => $full,
				'body' => $body,
				'lead' => $lead,
			);

			$offset = $end + 1;
		}

		return $blocks;
	}

	/**
	 * Parse a single @font-face body into structured properties.
	 *
	 * @param string $body Inner CSS of one @font-face block.
	 * @return array{family:string,weight:string,style:string,src:string,unicode_range:string,display:string}
	 */
	public function properties( string $body ): array {
		return array(
			'family'        => $this->prop_value( $body, 'font-family', '', true ),
			'weight'        => $this->prop_value( $body, 'font-weight', '400' ),
			'style'         => $this->prop_value( $body, 'font-style', 'normal' ),
			'src'           => $this->first_src_url( $body ),
			'unicode_range' => $this->prop_value( $body, 'unicode-range', '' ),
			'display'       => $this->prop_value( $body, 'font-display', '' ),
		);
	}

	/**
	 * Does this block reference a known web-font origin (gstatic / Bunny / WP proxy)?
	 *
	 * @param string $text Block text.
	 * @return bool
	 */
	public function is_remote_origin( string $text ): bool {
		foreach ( array( 'fonts.gstatic.com', 'fonts.bunny.net', 'fonts.wp.com', 'fonts-api.wp.com' ) as $needle ) {
			if ( false !== stripos( $text, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect variable fonts: the same src file used by 2+ @font-face blocks,
	 * or a font-weight expressed as a range ("100 900").
	 *
	 * @param array<int,array{full:string,body:string}> $blocks Parsed blocks.
	 * @return array<string,bool> Map of lowercased family => true.
	 */
	public function variable_families( array $blocks ): array {
		$src_count = array();
		$variable  = array();

		foreach ( $blocks as $block ) {
			$props  = $this->properties( $block['body'] );
			$family = strtolower( $props['family'] );

			if ( '' === $family ) {
				continue;
			}

			// Range weight => variable.
			if ( preg_match( '/\d+\s+\d+/', $props['weight'] ) ) {
				$variable[ $family ] = true;
			}

			if ( '' !== $props['src'] ) {
				$key               = $family . '|' . $props['src'];
				$src_count[ $key ] = ( $src_count[ $key ] ?? 0 ) + 1;

				if ( $src_count[ $key ] > 1 ) {
					$variable[ $family ] = true;
				}
			}
		}

		return $variable;
	}

	/**
	 * Build a normalized family-name comparator (case/space insensitive).
	 *
	 * @param string $family Family name.
	 * @return string
	 */
	public static function normalize_family( string $family ): string {
		return strtolower( trim( $family, " \t\n\r\0\x0B\"'" ) );
	}

	/**
	 * Infer the Google subset from a unicode-range declaration.
	 *
	 * Google's per-subset ranges are stable, so signature codepoints identify
	 * the subset even when the `/* subset *\/` comment is stripped (minified or
	 * inline CSS) — which is exactly why subset filtering failed for inline and
	 * @import sources.
	 *
	 * @param string $range unicode-range value.
	 * @return string Subset name, or '' if it can't be determined.
	 */
	public static function subset_from_unicode_range( string $range ): string {
		$range = strtoupper( $range );

		if ( '' === trim( $range ) ) {
			return '';
		}

		// Signature codepoints per Google subset. Order matters: the most
		// specific / extended ranges are tested before their base script so an
		// "-ext" face is never misread as the base subset. This mirrors OMGF's
		// unicode-range → subset map and lets subset filtering work even when
		// the `/* subset */` comment is stripped (minified / inline / @import).
		$signatures = array(
			'vietnamese'        => array( 'U+1EA0-1EF9', 'U+1EA0', 'U+1EF9', 'U+0102-0103', 'U+0300-0301', 'U+0303-0304', 'U+0309', 'U+0323' ),
			'cyrillic-ext'      => array( 'U+0460-052F', 'U+0460', 'U+1C80', 'U+1C88', 'U+2DE0', 'U+A640', 'U+A69F' ),
			'cyrillic'          => array( 'U+0400-045F', 'U+0400', 'U+0401', 'U+0410', 'U+0450', 'U+0490-0491', 'U+04B0-04B1' ),
			'greek-ext'         => array( 'U+1F00-1FFF', 'U+1F00' ),
			'greek'             => array( 'U+0370-0377', 'U+0370', 'U+0384', 'U+0386', 'U+0388-038A', 'U+0391', 'U+03A9' ),
			'devanagari'        => array( 'U+0900-097F', 'U+0900', 'U+0966-096F', 'U+1CD0-1CF9', 'U+200C-200D', 'U+A830-A839' ),
			'bengali'           => array( 'U+0980-09FE', 'U+0980', 'U+0985', 'U+09E6-09EF' ),
			'gujarati'          => array( 'U+0A80-0AFF', 'U+0A80', 'U+0AE6-0AEF' ),
			'gurmukhi'          => array( 'U+0A00-0A76', 'U+0A01', 'U+0A66-0A6F' ),
			'tamil'             => array( 'U+0B82-0BFA', 'U+0B82', 'U+0BE6-0BEF' ),
			'telugu'            => array( 'U+0C00-0C7F', 'U+0C00', 'U+0C66-0C6F' ),
			'kannada'           => array( 'U+0C80-0CF3', 'U+0C80', 'U+0CE6-0CEF' ),
			'malayalam'         => array( 'U+0D00-0D7F', 'U+0D00', 'U+0D66-0D6F' ),
			'sinhala'           => array( 'U+0D81-0DF4', 'U+0D82', 'U+0DE6-0DEF' ),
			'oriya'             => array( 'U+0B01-0B77', 'U+0B01', 'U+0B66-0B6F' ),
			'thai'              => array( 'U+0E01-0E5B', 'U+0E01', 'U+0E50-0E59' ),
			'khmer'             => array( 'U+1780-17FF', 'U+1780', 'U+17E0-17E9' ),
			'myanmar'           => array( 'U+1000-109F', 'U+1000', 'U+1040-1049' ),
			'tibetan'           => array( 'U+0F00-0FFF', 'U+0F00', 'U+0F20-0F29' ),
			'hebrew'            => array( 'U+0590-05FF', 'U+0591-05F4', 'U+05D0-05EA', 'U+FB1D-FB4F' ),
			'arabic'            => array( 'U+0600-06FF', 'U+0600', 'U+0750-077F', 'U+0870-088E', 'U+FB50-FDFF', 'U+FE70-FEFF' ),
			// CJK families ship many numbered ranges; a Hangul signature is the
			// one reliable named CJK marker. Chinese/Japanese arrive as numbered
			// subsets ('[0]') which stay unfiltered (kept) by the caller.
			'korean'            => array( 'U+AC00-D7A3', 'U+1100-11FF', 'U+3130-318F', 'U+A960-A97F', 'U+D7B0-D7FF' ),
			'latin-ext'         => array( 'U+0100-02AF', 'U+0100-024F', 'U+1E00-1EFF', 'U+0100', 'U+1E00', 'U+2C60-2C7F', 'U+A720-A7FF' ),
			'latin'             => array( 'U+0000-00FF', 'U+0001-000F', 'U+0131', 'U+0152-0153', 'U+2000-206F' ),
		);

		foreach ( $signatures as $subset => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $range, $needle ) ) {
					return $subset;
				}
			}
		}

		// Basic-Latin range present and nothing else matched → latin.
		if ( false !== strpos( $range, 'U+00' ) ) {
			return 'latin';
		}

		return '';
	}

	/**
	 * Extract a single property value from a declaration body.
	 *
	 * @param string $body     Declaration body.
	 * @param string $prop     Property name.
	 * @param string $fallback Default value.
	 * @param bool   $unquote  Strip surrounding quotes (for family names).
	 * @return string
	 */
	private function prop_value( string $body, string $prop, string $fallback, bool $unquote = false ): string {
		// Match `prop : value ;` (or end of block). Property names are literal.
		if ( ! preg_match( '/(?:^|[;{\s])' . preg_quote( $prop, '/' ) . '\s*:\s*([^;]+)/i', $body, $m ) ) {
			return $fallback;
		}

		$value = trim( $m[1] );

		if ( $unquote ) {
			$value = trim( $value, " \t\n\r\0\x0B\"'" );
		}

		return '' === $value ? $fallback : $value;
	}

	/**
	 * Pull the first url() out of a src declaration.
	 *
	 * @param string $body Declaration body.
	 * @return string
	 */
	private function first_src_url( string $body ): string {
		if ( ! preg_match( '/src\s*:\s*[^;]*?url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $body, $m ) ) {
			return '';
		}

		return trim( $m[1] );
	}
}
