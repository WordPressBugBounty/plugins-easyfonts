<?php
/**
 * HTML manipulation backed by WordPress's native HTML API.
 *
 * Link discovery/rewriting goes through WP_HTML_Tag_Processor (a real
 * tokenizer — no catastrophic backtracking). Operations the tokenizer can't do
 * in core (reading <style> text, deleting nodes) use tightly-scoped helpers.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Frontend;

use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Reliable HTML read/rewrite helpers.
 */
class HtmlProcessor {

	/**
	 * Iterate every <link rel="stylesheet">, letting a callback rewrite the href.
	 *
	 * @param string   $html     HTML.
	 * @param callable $callback fn(string $href, array $attrs): ?string  — return new href or null to skip.
	 * @return array{html:string,changed:bool}
	 */
	public function rewrite_stylesheet_links( string $html, callable $callback ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$changed   = false;

		while ( $processor->next_tag( array( 'tag_name' => 'LINK' ) ) ) {
			$rel = (string) $processor->get_attribute( 'rel' );

			if ( false === stripos( $rel, 'stylesheet' ) ) {
				continue;
			}

			$href = $processor->get_attribute( 'href' );

			if ( ! is_string( $href ) || '' === $href ) {
				continue;
			}

			$attrs = array(
				'id'    => $processor->get_attribute( 'id' ),
				'media' => $processor->get_attribute( 'media' ),
			);

			$new = $callback( $href, $attrs );

			if ( is_string( $new ) && $new !== $href ) {
				$processor->set_attribute( 'href', $new );
				$changed = true;
			}
		}

		return array(
			'html'    => $processor->get_updated_html(),
			'changed' => $changed,
		);
	}

	/**
	 * Collect all <link rel="stylesheet"> hrefs (read-only).
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	public function stylesheet_hrefs( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$hrefs     = array();

		while ( $processor->next_tag( array( 'tag_name' => 'LINK' ) ) ) {
			$rel = (string) $processor->get_attribute( 'rel' );

			if ( false === stripos( $rel, 'stylesheet' ) ) {
				continue;
			}

			$href = $processor->get_attribute( 'href' );

			if ( is_string( $href ) && '' !== $href ) {
				$hrefs[] = $href;
			}
		}

		return $hrefs;
	}

	/**
	 * Physically remove specific <link> stylesheet tags by exact href.
	 *
	 * The tokenizer can't delete nodes, so we mark them inert with the
	 * tokenizer (reliable matching) and then strip the marked tags by regex.
	 *
	 * @param string   $html  HTML.
	 * @param string[] $hrefs Exact href values to remove.
	 * @return string
	 */
	public function remove_stylesheet_links( string $html, array $hrefs ): string {
		if ( empty( $hrefs ) ) {
			return $html;
		}

		$targets   = array_fill_keys( $hrefs, true );
		$processor = new WP_HTML_Tag_Processor( $html );
		$hit       = false;

		while ( $processor->next_tag( array( 'tag_name' => 'LINK' ) ) ) {
			$rel = (string) $processor->get_attribute( 'rel' );

			if ( false === stripos( $rel, 'stylesheet' ) ) {
				continue;
			}

			$href = $processor->get_attribute( 'href' );

			if ( is_string( $href ) && isset( $targets[ $href ] ) ) {
				$processor->set_attribute( 'data-easyfonts-remove', '1' );
				$hit = true;
			}
		}

		if ( ! $hit ) {
			return $html;
		}

		$html = $processor->get_updated_html();

		// Drop any <link …> carrying our marker.
		$html = preg_replace( '#<link\b[^>]*\bdata-easyfonts-remove=([\'"])1\1[^>]*/?>\s*#i', '', $html );

		return null === $html ? '' : $html;
	}

	/**
	 * Process the CSS contents of each <style> block.
	 *
	 * @param string   $html     HTML.
	 * @param callable $callback fn(string $css): ?string — return new CSS or null to leave unchanged.
	 * @return array{html:string,changed:bool}
	 */
	public function rewrite_style_blocks( string $html, callable $callback ): array {
		$changed = false;

		$out = preg_replace_callback(
			'/(<style\b[^>]*>)(.*?)(<\/style>)/is',
			static function ( $m ) use ( $callback, &$changed ) {
				$new = $callback( $m[2] );

				if ( is_string( $new ) && $new !== $m[2] ) {
					$changed = true;
					return $m[1] . $new . $m[3];
				}

				return $m[0];
			},
			$html
		);

		return array(
			'html'    => null === $out ? $html : $out,
			'changed' => $changed,
		);
	}

	/**
	 * Remove Google-font resource hints (preconnect / dns-prefetch / preload).
	 *
	 * @param string   $html     HTML.
	 * @param string[] $domains  Domains to strip hints for.
	 * @return array{html:string,changed:bool}
	 */
	public function strip_font_hints( string $html, array $domains ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$remove    = array();

		// Identify offending hint tags by their href (read pass).
		while ( $processor->next_tag( array( 'tag_name' => 'LINK' ) ) ) {
			$rel = strtolower( (string) $processor->get_attribute( 'rel' ) );

			if ( ! in_array( $rel, array( 'preconnect', 'dns-prefetch', 'preload' ), true ) ) {
				continue;
			}

			$href = (string) $processor->get_attribute( 'href' );

			foreach ( $domains as $domain ) {
				if ( false !== strpos( $href, $domain ) ) {
					// Neutralise: blank the href + rel so the hint is inert.
					$processor->set_attribute( 'href', '' );
					$processor->set_attribute( 'rel', 'easyfonts-removed' );
					$remove[] = true;
					break;
				}
			}
		}

		$html = $processor->get_updated_html();

		// Physically drop the neutralised tags.
		$html = preg_replace( '/<link\b[^>]*\brel=([\'"])easyfonts-removed\1[^>]*>\s*/i', '', $html );

		return array(
			'html'    => null === $html ? '' : $html,
			'changed' => ! empty( $remove ),
		);
	}

	/**
	 * Insert a snippet immediately before </head>.
	 *
	 * @param string $html    HTML.
	 * @param string $snippet Snippet.
	 * @return string
	 */
	public function before_head_close( string $html, string $snippet ): string {
		$pos = stripos( $html, '</head>' );

		if ( false === $pos ) {
			return $html;
		}

		return substr_replace( $html, $snippet . "\n</head>", $pos, 7 );
	}
}
