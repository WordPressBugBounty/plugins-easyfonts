<?php
/**
 * Metric-matched fallback fonts (zero-CLS).
 *
 * For every hosted family with extracted metrics, generates a fallback
 * @font-face that aliases a local system font but overrides its metrics
 * (size-adjust, ascent/descent/line-gap-override) so it occupies the same space
 * as the web font — eliminating layout shift during swap.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Frontend;

use EasyFonts\Fonts\Registry;
use EasyFonts\Parser\FontMetricsReader;

defined( 'ABSPATH' ) || exit;

/**
 * Builds + injects metric-matched fallback faces.
 */
class MetricFallbacks {

	/**
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->registry = new Registry();
	}

	/**
	 * Build the fallback stylesheet for all families touched this request.
	 *
	 * @param array<int,array{family:string,weight:string,style:string}> $touched Touched fonts.
	 * @return string CSS (may be empty).
	 */
	public function build_for( array $touched ): string {
		$seen = array();
		$css  = '';

		foreach ( $touched as $item ) {
			$family = $item['family'];
			$key    = strtolower( $family );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			$metrics = $this->registry->metrics_for( $family );

			if ( ! $metrics ) {
				continue;
			}

			$face = $this->build_face( $family, $metrics );

			if ( '' !== $face ) {
				$css .= $face;
			}
		}

		return $css;
	}

	/**
	 * Compute the override values and emit a fallback @font-face + nothing else.
	 * The fallback family is named "<Family> Fallback"; we also append it to the
	 * original family via a small @font-face alias is not possible, so callers
	 * should rely on the generated stack. Here we emit the override face only.
	 *
	 * @param string                    $family  Family.
	 * @param array<string,float|int>   $metrics Web-font metrics.
	 * @return string
	 */
	private function build_face( string $family, array $metrics ): string {
		$units = (float) ( $metrics['unitsPerEm'] ?? 0 );

		if ( $units <= 0 ) {
			return '';
		}

		$ascent  = (float) ( $metrics['ascent'] ?? 0 );
		$descent = (float) ( $metrics['descent'] ?? 0 );
		$gap     = (float) ( $metrics['lineGap'] ?? 0 );
		$x_avg   = (float) ( $metrics['xAvgCharWidth'] ?? 0 );

		$category = $this->guess_category( $family );
		$fallback = FontMetricsReader::system_fallbacks()[ $category ];

		// size-adjust = webfont avg-width ratio / fallback avg-width ratio.
		$web_ratio = $x_avg > 0 ? ( $x_avg / $units ) : 0;
		$size_adj  = ( $web_ratio > 0 && $fallback['ratio'] > 0 ) ? ( $web_ratio / $fallback['ratio'] ) : 1.0;

		if ( $size_adj <= 0 ) {
			$size_adj = 1.0;
		}

		// Overrides are relative to the web font, scaled by size-adjust.
		$ascent_pct  = ( $ascent / ( $units * $size_adj ) ) * 100;
		$descent_pct = ( abs( $descent ) / ( $units * $size_adj ) ) * 100;
		$gap_pct     = ( $gap / ( $units * $size_adj ) ) * 100;

		// Harden against CSS-context injection: a malicious @font-face family
		// name elsewhere on the site must not be able to break out of the
		// declaration. Strip quotes/braces/semicolons before interpolation.
		$family_css   = self::css_safe( $family );
		$fallback_css = self::css_safe( (string) $fallback['local'] );

		return sprintf(
			"@font-face{font-family:'%s Fallback';src:local('%s');size-adjust:%s%%;ascent-override:%s%%;descent-override:%s%%;line-gap-override:%s%%;}\n",
			$family_css,
			$fallback_css,
			$this->fmt( $size_adj * 100 ),
			$this->fmt( $ascent_pct ),
			$this->fmt( $descent_pct ),
			$this->fmt( $gap_pct )
		);
	}

	/**
	 * Strip characters that could break out of a CSS string/declaration.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function css_safe( string $text ): string {
		return trim( str_replace( array( "'", '"', ';', '{', '}', '<', '>', "\\", "\r", "\n" ), '', $text ) );
	}

	/**
	 * Naive serif/sans/mono category guess from the family name.
	 *
	 * @param string $family Family.
	 * @return string
	 */
	private function guess_category( string $family ): string {
		$lower = strtolower( $family );

		if ( false !== strpos( $lower, 'mono' ) || false !== strpos( $lower, 'code' ) || false !== strpos( $lower, 'courier' ) ) {
			return 'monospace';
		}

		foreach ( array( 'serif', 'slab', 'georgia', 'times', 'playfair', 'merriweather', 'lora', 'pt serif', 'noto serif' ) as $hint ) {
			if ( false !== strpos( $lower, $hint ) ) {
				return 'serif';
			}
		}

		return 'sans-serif';
	}

	/**
	 * Format a percentage with up to 4 decimals, trimmed.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private function fmt( float $value ): string {
		return rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
	}
}
