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
	 * Is WordPress's HTML API available? (WP 6.2+.) When it isn't, every method
	 * here fails open — returning the HTML unchanged — so a missing core class can
	 * never fatal inside the output buffer and white-screen the site.
	 *
	 * @return bool
	 */
	private function api_ready(): bool {
		return class_exists( '\\WP_HTML_Tag_Processor' );
	}

	/**
	 * Iterate every <link rel="stylesheet">, letting a callback rewrite the href.
	 *
	 * @param string   $html     HTML.
	 * @param callable $callback fn(string $href, array $attrs): ?string  — return new href or null to skip.
	 * @return array{html:string,changed:bool}
	 */
	public function rewrite_stylesheet_links( string $html, callable $callback ): array {
		if ( ! $this->api_ready() ) {
			return array( 'html' => $html, 'changed' => false );
		}

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
		if ( ! $this->api_ready() ) {
			return array();
		}

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

		if ( ! $this->api_ready() ) {
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

		$updated = $processor->get_updated_html();

		// Drop any <link …> carrying our marker. If PCRE bails (e.g. backtrack
		// limit), keep the tokenized HTML rather than blanking the document.
		$cleaned = preg_replace( '#<link\b[^>]*\bdata-easyfonts-remove=([\'"])1\1[^>]*/?>\s*#i', '', $updated );

		return null === $cleaned ? $updated : $cleaned;
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

		// Linear <style>…</style> scanner — NOT a regex. A regex such as
		// `(<style\b[^>]*>)(.*?)(</style>)/s` catastrophically backtracks (and on
		// PCRE2+JIT can crash the process) over the megabyte-sized inline CSS that
		// page builders emit, which silently blanked the page. This strpos walk is
		// O(n) and cannot backtrack or overflow.
		$max    = (int) apply_filters( 'easyfonts_max_style_block', 2 * 1024 * 1024 );
		$out    = '';
		$offset = 0;
		$len    = strlen( $html );

		while ( $offset < $len ) {
			$open = stripos( $html, '<style', $offset );

			if ( false === $open ) {
				$out .= substr( $html, $offset );
				break;
			}

			// Make sure "<style" is a real tag start (next char ends the name).
			$boundary = $html[ $open + 6 ] ?? '';

			if ( '' !== $boundary && '>' !== $boundary && '/' !== $boundary && ! ctype_space( $boundary ) ) {
				$out   .= substr( $html, $offset, ( $open + 6 ) - $offset );
				$offset = $open + 6;
				continue;
			}

			$gt = strpos( $html, '>', $open );

			if ( false === $gt ) {
				$out .= substr( $html, $offset );
				break;
			}

			$close = stripos( $html, '</style>', $gt + 1 );

			if ( false === $close ) {
				$out .= substr( $html, $offset );
				break;
			}

			// Copy everything up to this <style>, then the opener verbatim.
			$out     .= substr( $html, $offset, $open - $offset );
			$tag_open = substr( $html, $open, ( $gt - $open ) + 1 );
			$content  = substr( $html, $gt + 1, $close - ( $gt + 1 ) );

			if ( strlen( $content ) <= $max ) {
				$new = $callback( $content );

				if ( is_string( $new ) && $new !== $content ) {
					$changed = true;
					$content = $new;
				}
			}

			$out   .= $tag_open . $content . '</style>';
			$offset = $close + 8;
		}

		return array(
			'html'    => $out,
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
		if ( ! $this->api_ready() ) {
			return array( 'html' => $html, 'changed' => false );
		}

		$input     = $html;
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

		// Physically drop the neutralised tags. If PCRE bails, fall back to the
		// input HTML rather than returning '' (which would blank the page).
		$cleaned = preg_replace( '/<link\b[^>]*\brel=([\'"])easyfonts-removed\1[^>]*>\s*/i', '', $html );

		if ( null === $cleaned ) {
			return array( 'html' => $input, 'changed' => false );
		}

		return array(
			'html'    => $cleaned,
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
