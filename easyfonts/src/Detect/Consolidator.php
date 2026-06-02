<?php
/**
 * Consolidator — the aggregate-and-replace engine.
 *
 * Instead of rewriting font URLs in place (which left protocol-relative URLs
 * behind, created @import chains, and produced malformed CSS), this runs ONE
 * pass that:
 *
 *   1. COLLECT  — gather every Google/Bunny font source from <link> tags,
 *                 inline <style> (@import + remote @font-face), same-origin and
 *                 external CSS files, and JS web-font loaders.
 *   2. AGGREGATE— parse all sources into normalized variants, dedupe, and filter
 *                 by subset (inferred from unicode-range, comment-independent).
 *   3. HOST     — download each unique woff2 once, register it, and build ONE
 *                 clean local stylesheet (correct font-display placement).
 *   4. APPLY    — strip every original source and inject a single <link>.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Detect;

use EasyFonts\Fonts\Downloader;
use EasyFonts\Fonts\Registry;
use EasyFonts\Fonts\Storage;
use EasyFonts\Frontend\HtmlProcessor;
use EasyFonts\Parser\FontFaceParser;
use EasyFonts\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Detects, consolidates, and locally hosts web fonts.
 */
class Consolidator {

	/**
	 * @var FontFaceParser
	 */
	private FontFaceParser $parser;

	/**
	 * @var Downloader
	 */
	private Downloader $downloader;

	/**
	 * @var Storage
	 */
	private Storage $storage;

	/**
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * @var StylesheetFetcher
	 */
	private StylesheetFetcher $fetcher;

	/**
	 * @var HtmlProcessor
	 */
	private HtmlProcessor $html;

	/**
	 * Provider CSS URLs to fetch: url => provider.
	 *
	 * @var array<string,string>
	 */
	private array $css_urls = array();

	/**
	 * Raw remote @font-face CSS snippets found inline / in files: each [css, provider].
	 *
	 * @var array<int,array{css:string,provider:string}>
	 */
	private array $raw_blocks = array();

	/**
	 * Exact <link> hrefs to physically remove.
	 *
	 * @var string[]
	 */
	private array $remove_links = array();

	/**
	 * Detector ids that contributed at least one source (for Auto-Config).
	 *
	 * @var array<string,bool>
	 */
	private array $contributed = array();

	/**
	 * Variants hosted this request (for fallbacks + usage logging).
	 *
	 * @var array<int,array{family:string,weight:string,style:string}>
	 */
	private static array $touched = array();

	/**
	 * Consolidated stylesheet URL produced by the most recent run ('' if none).
	 *
	 * @var string
	 */
	private static string $stylesheet_url = '';

	/**
	 * Cache-relative path of the consolidated stylesheet from the most recent
	 * run ('' if none). Used by the output buffer to inline the CSS.
	 *
	 * @var string
	 */
	private static string $stylesheet_file = '';

	/**
	 * Fonts touched this request.
	 *
	 * @return array<int,array{family:string,weight:string,style:string}>
	 */
	public static function touched(): array {
		return self::$touched;
	}

	/**
	 * Consolidated stylesheet URL from the most recent run.
	 *
	 * @return string
	 */
	public static function stylesheet_url(): string {
		return self::$stylesheet_url;
	}

	/**
	 * Cache-relative path of the consolidated stylesheet from the most recent
	 * run ('' if none).
	 *
	 * @return string
	 */
	public static function stylesheet_file(): string {
		return self::$stylesheet_file;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->parser     = new FontFaceParser();
		$this->downloader = new Downloader();
		$this->storage    = new Storage();
		$this->registry   = new Registry();
		$this->fetcher    = new StylesheetFetcher();
		$this->html       = new HtmlProcessor();
	}

	/**
	 * Run the full consolidation pass over a document.
	 *
	 * @param string $html  Full HTML.
	 * @param bool   $force Reserved (probe mode); detection is identical.
	 * @return string
	 */
	public function run( string $html, bool $force = false ): string {
		unset( $force );

		self::$stylesheet_url  = '';
		self::$stylesheet_file = '';

		// 1. COLLECT (each step may rewrite the HTML — e.g. strip inline blocks).
		$html = $this->collect_from_links( $html );
		$html = $this->collect_from_inline( $html );
		$html = $this->collect_from_webfont( $html );
		$this->collect_async_urls();

		// Physically remove the provider <link> tags we flagged.
		$html = $this->html->remove_stylesheet_links( $html, array_values( array_unique( $this->remove_links ) ) );

		if ( empty( $this->css_urls ) && empty( $this->raw_blocks ) ) {
			return $html;
		}

		// 2. AGGREGATE.
		$variants = $this->aggregate();

		if ( empty( $variants ) ) {
			return $html;
		}

		// 3. HOST (register + build the consolidated stylesheet).
		$file_url = $this->host( $variants );

		if ( null === $file_url ) {
			return $html;
		}

		// Record for the output buffer, which injects preloads + the stylesheet
		// + fallbacks together in the correct order. (We don't inject here so the
		// preloads can be positioned ABOVE the stylesheet.)
		self::$stylesheet_url = $file_url;

		foreach ( array_keys( $this->contributed ) as $id ) {
			Settings::learn_detector( $id );
		}

		return $html;
	}

	/* --------------------------------------------------------------------- *
	 * 1. COLLECT
	 * --------------------------------------------------------------------- */

	/**
	 * Walk <link> tags: flag provider stylesheets for removal, and rewrite
	 * same-origin/external CSS files that hide provider fonts to cleaned copies.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function collect_from_links( string $html ): string {
		$result = $this->html->rewrite_stylesheet_links(
			$html,
			function ( $href ) {
				$url = Providers::normalize_url( $href );

				// Direct provider stylesheet → consolidated pipeline (removed +
				// re-emitted as one clean local sheet).
				if ( Providers::is_css_url( $url ) ) {
					$this->add_css_url( $url, 'link' );
					$this->remove_links[] = $href;
					return null;
				}

				// A real CSS file (theme/plugin) that may hide provider fonts in
				// @import or @font-face rules.
				if ( $this->fetcher->is_skippable( $url ) ) {
					return null;
				}

				$is_local = $this->fetcher->is_local( $url );
				$css      = $is_local
					? $this->fetcher->read_local( $url )
					: $this->fetcher->read_external( $url, $this->downloader );

				if ( null === $css || ! $this->mentions_provider( $css ) ) {
					return null;
				}

				$detector = $is_local ? 'local_css' : 'external_css';

				// Localise IN PLACE: download the provider fonts, rewrite their
				// @font-face src to local URLs, inline any provider @import (also
				// localised). The rest of the file is preserved, so the sheet stays
				// in its original cascade position — just self-hosted now.
				$localized = $this->localize_stylesheet( $css, $url );

				if ( null === $localized ) {
					return null;
				}

				// Save the localised copy in a clean css/ folder (no doubled-hash
				// folder, which security scanners flag).
				$filename = 'css/ext-' . substr( hash( 'sha256', $url ), 0, 18 ) . '.css';

				if ( ! $this->storage->write( $filename, $localized ) ) {
					return null;
				}

				$this->mark( $detector );

				return add_query_arg( 'ver', Settings::buster(), $this->storage->url( $filename ) );
			}
		);

		return $result['html'];
	}

	/**
	 * Walk inline <style> blocks: pull provider @import URLs and remote
	 * @font-face blocks out, leaving the rest of the CSS (e.g. Font Awesome)
	 * untouched.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function collect_from_inline( string $html ): string {
		$result = $this->html->rewrite_style_blocks(
			$html,
			function ( $css ) {
				$original = $css;

				// Provider @import → collect + remove the statement.
				$css = (string) preg_replace_callback(
					'/@import\s+(?:url\(\s*)?[\'"]?([^\'")\s]+)[\'"]?\s*\)?\s*;?/i',
					function ( $m ) {
						$url = Providers::normalize_url( $m[1] );

						if ( Providers::is_css_url( $url ) ) {
							$this->add_css_url( $url, 'inline' );
							return '';
						}

						return $m[0];
					},
					$css
				);

				// Remote @font-face → collect raw block + remove it.
				$blocks = $this->parser->blocks( $css );

				foreach ( $blocks as $block ) {
					if ( $this->parser->is_remote_origin( $block['full'] ) ) {
						$this->raw_blocks[] = array(
							'css'      => $block['full'],
							'provider' => Providers::id_for( $block['full'] ),
						);
						$css = str_replace( $block['full'], '', $css );
						$this->mark( 'inline' );
					}
				}

				return $css === $original ? null : $css;
			}
		);

		return $result['html'];
	}

	/**
	 * Detect the JS Web Font Loader, convert its config to a provider CSS URL,
	 * and neutralise the loader scripts.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function collect_from_webfont( string $html ): string {
		if ( false === stripos( $html, 'WebFont' ) && false === stripos( $html, 'webfont.js' ) ) {
			return $html;
		}

		// Match the Google config object in either authoring style:
		//   WebFontConfig['google'] = { families: [...] }   (bracket assignment)
		//   ... google: { families: [...] } ...             (object literal)
		// The object body has no nested braces (families uses [ ]), so [^{}] is safe.
		if ( ! preg_match(
			'/(?:WebFontConfig\s*\[\s*[\'"]google[\'"]\s*\]\s*=\s*|[\'"]?google[\'"]?\s*:\s*)(\{[^{}]*\})/is',
			$html,
			$m
		) ) {
			return $html;
		}

		// HTML-decode the captured object (&amp;subset=latin → &subset=latin)
		// BEFORE parsing, or the entity breaks family/subset extraction.
		$block = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );

		if ( ! preg_match( '/families\s*:\s*\[(.*?)\]/is', $block, $fm ) ) {
			return $html;
		}

		$families = array();
		$subsets  = array();

		if ( preg_match_all( '/[\'"]([^\'"]+)[\'"]/', $fm[1], $items ) ) {
			foreach ( $items[1] as $item ) {
				$parsed = $this->webfont_family_to_spec( $item );

				if ( ! empty( $parsed['family'] ) ) {
					$families[] = $parsed;

					if ( '' !== $parsed['subset'] ) {
						$subsets = array_merge( $subsets, explode( ',', $parsed['subset'] ) );
					}
				}
			}
		}

		if ( empty( $families ) ) {
			return $html;
		}

		// Build a modern Google CSS2 request. CSS2 is the endpoint the rest of the
		// pipeline is proven against (subset comments + unicode-ranges parse
		// cleanly), unlike the legacy v1 /css endpoint. Each family becomes a
		// `family=Name:ital,wght@…` parameter. Subsets from the config travel as a
		// global &subset= param; aggregate() merges in user-selected subsets too.
		$url = $this->build_css2_url( $families );

		if ( '' === $url ) {
			return $html;
		}

		$subsets = array_values( array_unique( array_filter( $subsets ) ) );

		if ( ! empty( $subsets ) ) {
			$url .= '&subset=' . implode( ',', $subsets );
		}

		$this->add_css_url( $url, 'webfont' );
		$this->mark( 'webfont' );

		// Remove webfont.js loader scripts + the WebFontConfig block.
		$html = (string) preg_replace( '#<script\b[^>]*\bsrc=[\'"][^\'"]*webfont(?:\.min)?\.js[\'"][^>]*>\s*</script>#i', '', $html );
		$html = (string) preg_replace( '#<script\b[^>]*>(?:(?!</script>).)*?WebFontConfig(?:(?!</script>).)*?</script>#is', '', $html );

		return $html;
	}

	/**
	 * Convert one Web Font Loader families[] entry into a structured spec:
	 *   { family: 'Open Sans', weights: ['400','700'], italics: ['400'], subset: '' }
	 *
	 * Handles plain names, weight lists, FVD notation ("n4"/"i7"), a trailing
	 * "&subset=" / ":subset" segment, URL-encoded names ("%2C"), and skips font
	 * stacks / generic families (e.g. "Tahoma,Geneva, sans-serif").
	 *
	 * @param string $entry Raw families[] entry.
	 * @return array{family:string,weights:string[],italics:string[],subset:string}
	 */
	private function webfont_family_to_spec( string $entry ): array {
		$none  = array( 'family' => '', 'weights' => array(), 'italics' => array(), 'subset' => '' );
		$entry = html_entity_decode( trim( $entry ), ENT_QUOTES | ENT_HTML5 );

		if ( '' === $entry ) {
			return $none;
		}

		// Pull off a &subset= / &subsets= query suffix and capture it.
		$subset = '';

		if ( preg_match( '/[&?]subsets?=([a-z0-9,\-]+)/i', $entry, $sm ) ) {
			$subset = strtolower( $sm[1] );
		}

		$entry = (string) preg_replace( '/[&?].*$/', '', $entry ); // drop the query part.

		$parts      = explode( ':', $entry );
		$family_raw = rawurldecode( trim( $parts[0] ) ); // %2C → ",".

		// Skip font stacks / generic families — not Google fonts.
		if ( false !== strpos( $family_raw, ',' ) ) {
			return $none;
		}

		$lc = strtolower( trim( $family_raw ) );

		if ( in_array( $lc, array( 'sans-serif', 'serif', 'monospace', 'cursive', 'fantasy', 'system-ui', '-apple-system', 'blinkmacsystemfont', 'inherit', 'initial' ), true ) ) {
			return $none;
		}

		$family = trim( $family_raw );

		if ( '' === $family ) {
			return $none;
		}

		// A third colon segment is a subset list in WebFont loader syntax.
		if ( isset( $parts[2] ) && '' !== trim( $parts[2] ) ) {
			$third  = strtolower( trim( $parts[2] ) );
			$subset = '' !== $subset ? $subset . ',' . $third : $third;
		}

		// Weights (second segment) → split into upright + italic numeric lists.
		$weights = array();
		$italics = array();

		if ( isset( $parts[1] ) && '' !== trim( $parts[1] ) ) {
			foreach ( explode( ',', $parts[1] ) as $token ) {
				$token = trim( $token );

				if ( preg_match( '/^([nio])([1-9])$/i', $token, $fvd ) ) {
					$w = $fvd[2] . '00';
					'i' === strtolower( $fvd[1] ) ? $italics[] = $w : $weights[] = $w;
				} elseif ( preg_match( '/^(\d{3})italic$/i', $token, $wm ) ) {
					$italics[] = $wm[1];
				} elseif ( preg_match( '/^\d{3}$/', $token ) ) {
					$weights[] = $token;
				} elseif ( 'bold' === strtolower( $token ) ) {
					$weights[] = '700';
				} elseif ( in_array( strtolower( $token ), array( 'regular', 'normal' ), true ) ) {
					$weights[] = '400';
				} elseif ( 'italic' === strtolower( $token ) ) {
					$italics[] = '400';
				}
			}
		}

		// Default to a single upright 400 if no weight was specified.
		if ( empty( $weights ) && empty( $italics ) ) {
			$weights[] = '400';
		}

		return array(
			'family'  => $family,
			'weights' => array_values( array_unique( $weights ) ),
			'italics' => array_values( array_unique( $italics ) ),
			'subset'  => $subset,
		);
	}

	/**
	 * Build a single Google CSS2 URL from structured family specs.
	 *
	 * Produces e.g.
	 *   https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,400;0,700;1,400&family=Roboto:wght@400&display=swap
	 *
	 * @param array<int,array{family:string,weights:string[],italics:string[],subset:string}> $families Specs.
	 * @return string CSS2 URL, or '' if no families.
	 */
	private function build_css2_url( array $families ): string {
		$params = array();

		foreach ( $families as $spec ) {
			$name = str_replace( ' ', '+', $spec['family'] );

			if ( '' === $name ) {
				continue;
			}

			$uprights = $spec['weights'];
			$italics  = $spec['italics'];

			if ( ! empty( $italics ) ) {
				// Mixed axis: must list every tuple as ital,wght and sort ascending.
				$tuples = array();

				foreach ( $uprights as $w ) {
					$tuples[] = '0,' . $w;
				}

				foreach ( $italics as $w ) {
					$tuples[] = '1,' . $w;
				}

				sort( $tuples ); // CSS2 requires ascending tuple order.
				$params[] = $name . ':ital,wght@' . implode( ';', $tuples );
			} else {
				// Upright only.
				$ws = $uprights;
				sort( $ws, SORT_STRING );
				$params[] = $name . ':wght@' . implode( ';', $ws );
			}
		}

		if ( empty( $params ) ) {
			return '';
		}

		$display = (string) Settings::get( 'font_display', 'swap' );

		return 'https://fonts.googleapis.com/css2?family=' . implode( '&family=', $params ) . '&display=' . $display;
	}

	/**
	 * Fold in Google/Bunny CSS URLs that were injected by JavaScript at runtime
	 * and reported by async-blocker.js (stored via REST /async-fonts). These
	 * never appear in the server-rendered HTML, so this is how arbitrary
	 * JS-loaded fonts get self-hosted — closing the one detection gap versus a
	 * pure output-buffer scanner.
	 *
	 * @return void
	 */
	private function collect_async_urls(): void {
		if ( ! Settings::get( 'async_blocker', 0 ) ) {
			return;
		}

		$urls = (array) get_option( 'easyfonts_async_urls', array() );

		foreach ( $urls as $url ) {
			$url = Providers::normalize_url( (string) $url );

			if ( Providers::is_css_url( $url ) ) {
				$this->add_css_url( $url, 'async' );
			}
		}
	}

	/**
	 * Parse every collected source into normalized, deduped, subset-filtered
	 * variants.
	 *
	 * @return array<string,array<string,mixed>> Keyed by variant_key.
	 */
	private function aggregate(): array {
		$variants = array();
		$subsets  = $this->effective_subsets();

		// Provider CSS URLs → fetch + parse. Google v1 URLs are augmented so the
		// API actually returns the non-latin subsets the user enabled.
		foreach ( $this->css_urls as $url => $provider ) {
			$css = $this->downloader->fetch_css( $this->augment_v1_subsets( $url, $subsets ) );

			if ( null === $css ) {
				continue;
			}

			$this->collect_variants( $css, $provider, $subsets, $variants, $url );
		}

		// Raw inline @font-face snippets → parse directly.
		foreach ( $this->raw_blocks as $block ) {
			$this->collect_variants( $block['css'], $block['provider'], $subsets, $variants, '' );
		}

		return $variants;
	}

	/**
	 * Parse CSS into variants and merge them into the accumulator.
	 *
	 * @param string                            $css       CSS.
	 * @param string                            $provider  Provider id.
	 * @param string[]                          $subsets   Allowed subsets.
	 * @param array<string,array<string,mixed>> $variants  Accumulator (by ref).
	 * @param string                            $source    Source URL (for provenance).
	 */
	private function collect_variants( string $css, string $provider, array $subsets, array &$variants, string $source ): void {
		$blocks       = $this->parser->blocks( $css );
		$variable_map = $this->parser->variable_families( $blocks );

		foreach ( $blocks as $block ) {
			$props  = $this->parser->properties( $block['body'] );
			$family = $props['family'];
			$src    = $props['src'];

			if ( '' === $family || '' === $src ) {
				continue;
			}

			$src = Providers::normalize_url( $src, $source );

			// Subset: prefer the Google /* comment */, else infer from range.
			$subset = isset( $block['lead'] ) ? $this->clean_subset( $block['lead'] ) : '';

			if ( '' === $subset ) {
				$subset = FontFaceParser::subset_from_unicode_range( $props['unicode_range'] );
			}

			// Filter by the EFFECTIVE subset set (user selection + latin baseline,
			// computed by the caller). latin/latin-ext are always kept, so enabling
			// an extra script (e.g. cyrillic) never drops a latin-only font. Unknown
			// subset ('') is always kept (we can't tell what it is).
			if ( '' !== $subset && ! in_array( $subset, $subsets, true ) ) {
				continue;
			}

			$is_variable = isset( $variable_map[ strtolower( $family ) ] )
				|| (bool) preg_match( '/\d+\s+\d+/', $props['weight'] );

			$subset_key = '' !== $subset ? $subset : 'latin';
			$key        = Registry::variant_key( $family, $props['weight'], $props['style'], $subset_key );

			// First write wins; identical variants from multiple sources collapse.
			if ( isset( $variants[ $key ] ) ) {
				continue;
			}

			$variants[ $key ] = array(
				'family'        => $family,
				'weight'        => $props['weight'],
				'style'         => $props['style'],
				'subset'        => $subset_key,
				'src'           => $src,
				'unicode_range' => $props['unicode_range'],
				'is_variable'   => $is_variable,
				'provider'      => $provider,
				'source'        => $source,
			);
		}
	}

	/* --------------------------------------------------------------------- *
	 * 3. HOST
	 * --------------------------------------------------------------------- */

	/**
	 * Register every variant and write one consolidated stylesheet. The file is
	 * keyed by the variant set + display + subsets, so pages sharing fonts share
	 * the file.
	 *
	 * Registration runs for EVERY variant on every request (idempotent upsert),
	 * even when the consolidated file already exists — so the hosted-fonts list
	 * can't drift out of sync with what's actually served. Font binaries are
	 * downloaded only when missing. Metric extraction is deliberately NOT done
	 * here; it runs off the page-render path (see MetricsBackfill).
	 *
	 * @param array<string,array<string,mixed>> $variants Variants by key.
	 * @return string|null Local stylesheet URL (cache-busted), or null.
	 */
	private function host( array $variants ): ?string {
		$keys = array_keys( $variants );
		sort( $keys );

		$display    = (string) Settings::get( 'font_display', 'swap' );
		$subsets    = implode( ',', (array) Settings::get( 'subsets', array() ) );

		$disabled = $this->registry->disabled_keys();

		// Fold the disabled set into the cache key so enabling/disabling a
		// variant yields a different stylesheet filename (no stale CSS served).
		$disabled_sig = implode( ',', array_keys( array_intersect_key( array_flip( $keys ), $disabled ) ) );
		$hash         = substr( hash( 'sha256', implode( '|', $keys ) . '|' . $display . '|' . $subsets . '|x:' . $disabled_sig ), 0, 20 );

		// Consolidated stylesheet lives in a readable `css/` folder (no doubled
		// hash folder, which security scanners flag). Font binaries live under
		// `fonts/{family}/…` so identical variants are shared across stylesheets.
		$file        = 'css/' . $hash . '.css';
		$file_exists = $this->storage->exists( $file );

		$css    = "/**\n * Auto Generated by EasyFonts\n * @url: https://fluxpress.io\n */\n";
		$hosted = 0;

		foreach ( $variants as $key => $v ) {
			// User-disabled variant: keep it registered (so it shows in the
			// "unused/disabled" list to re-enable) but don't download, don't add
			// to the stylesheet, and don't preload.
			$is_disabled = isset( $disabled[ $key ] );

			$basename  = $this->basename_for( $v );
			$font_file = $this->locate_font( $basename );
			$size      = $font_file ? $this->storage->size( $font_file ) : 0;

			if ( ! $is_disabled && null === $font_file ) {
				$download = $this->downloader->fetch_font( $v['src'], $basename, $this->storage );

				if ( null === $download ) {
					continue;
				}

				$font_file = $download['file'];
				$size      = $download['size'];
			}

			$this->registry->upsert(
				array(
					'family'      => $v['family'],
					'weight'      => $v['weight'],
					'style'       => $v['style'],
					'subset'      => $v['subset'],
					'is_variable' => $v['is_variable'],
					'provider'    => $v['provider'],
					'css_file'    => $file,
					'font_file'   => (string) $font_file,
					'file_size'   => $size,
					'source_url'  => $v['source'],
				)
			);

			if ( $is_disabled || null === $font_file ) {
				continue;
			}

			$local_url = add_query_arg( 'ver', Settings::buster(), $this->storage->url( $font_file ) );
			$css      .= $this->build_face_rule( $v, $local_url, $display );

			self::$touched[] = array(
				'family' => $v['family'],
				'weight' => $v['weight'],
				'style'  => $v['style'],
			);

			$hosted++;
		}

		if ( 0 === $hosted ) {
			return null;
		}

		// Rewrite the stylesheet whenever the enabled set might have changed
		// (cheap; keyed file means content is deterministic for a given set).
		if ( ! $file_exists ) {
			if ( ! $this->storage->write( $file, $css ) ) {
				return null;
			}
		}

		self::$stylesheet_file = $file;

		return add_query_arg( 'ver', Settings::buster(), $this->storage->url( $file ) );
	}

	/**
	 * Find an already-downloaded font file for a basename (any known extension).
	 *
	 * @param string $basename Cache-relative basename (no extension).
	 * @return string|null Cache-relative filename, or null if none.
	 */
	private function locate_font( string $basename ): ?string {
		foreach ( array( 'woff2', 'woff', 'ttf', 'otf' ) as $ext ) {
			$candidate = $basename . '.' . $ext;

			if ( $this->storage->exists( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Build one clean @font-face rule (correct property order + placement).
	 *
	 * @param array<string,mixed> $v         Variant.
	 * @param string              $local_url Local font URL.
	 * @param string              $display   font-display value.
	 * @return string
	 */
	private function build_face_rule( array $v, string $local_url, string $display ): string {
		$ext    = strtolower( (string) pathinfo( (string) wp_parse_url( $local_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$format = 'woff' === $ext ? 'woff' : ( 'ttf' === $ext ? 'truetype' : ( 'otf' === $ext ? 'opentype' : 'woff2' ) );

		$lines   = array();
		$lines[] = '@font-face {';
		$lines[] = "  font-family: '" . str_replace( "'", '', $v['family'] ) . "';";
		$lines[] = '  font-style: ' . $v['style'] . ';';
		$lines[] = '  font-weight: ' . $v['weight'] . ';';

		if ( '' !== $display && 'auto' !== $display ) {
			$lines[] = '  font-display: ' . $display . ';';
		}

		$lines[] = "  src: url('" . esc_url_raw( $local_url ) . "') format('" . $format . "');";

		if ( '' !== $v['unicode_range'] ) {
			$lines[] = '  unicode-range: ' . $v['unicode_range'] . ';';
		}

		$lines[] = "}\n";

		return implode( "\n", $lines );
	}

	/**
	 * Deterministic, human-readable font path for a variant, grouped by family.
	 * Variable fonts drop the weight. Returns a cache-relative basename (no ext).
	 *
	 * e.g. fonts/open-sans/open-sans-normal-latin-400
	 *
	 * @param array<string,mixed> $v Variant.
	 * @return string
	 */
	private function basename_for( array $v ): string {
		$slug  = $this->slug( $v['family'] );
		$parts = array( $slug, $v['style'], $v['subset'] );

		if ( empty( $v['is_variable'] ) ) {
			$parts[] = $this->slug( $v['weight'] );
		}

		return 'fonts/' . $slug . '/' . implode( '-', array_filter( $parts ) );
	}

	/**
	 * Filesystem-safe slug (lowercase alphanumerics + hyphens).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function slug( string $text ): string {
		$text = strtolower( trim( $text ) );
		$text = preg_replace( '/[^a-z0-9]+/', '-', $text );

		return trim( (string) $text, '-' );
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Localise a theme/plugin stylesheet IN PLACE: download provider fonts and
	 * rewrite their @font-face src to local URLs, and inline any provider
	 * @import (also localised). Everything else is preserved so the sheet keeps
	 * its cascade position. Returns the localised CSS, or null if it contained
	 * no provider fonts.
	 *
	 * @param string $css      CSS contents.
	 * @param string $base_url The file's own URL (for absolutising).
	 * @return string|null
	 */
	private function localize_stylesheet( string $css, string $base_url ): ?string {
		$found     = false;
		$effective = $this->effective_subsets();
		$out       = $this->absolutize_urls( $css, $base_url );

		// 1. Provider @import → fetch that CSS, localise its faces, inline it.
		$out = (string) preg_replace_callback(
			'/@import\s+(?:url\(\s*)?[\'"]?([^\'")\s]+)[\'"]?\s*\)?\s*;?/i',
			function ( $m ) use ( &$found, $effective ) {
				$url = Providers::normalize_url( $m[1] );

				if ( ! Providers::is_css_url( $url ) ) {
					return $m[0];
				}

				$imported = $this->downloader->fetch_css( $this->augment_v1_subsets( $url, $effective ) );

				if ( null === $imported ) {
					return ''; // Drop a dead/blocked provider import.
				}

				$found = true;

				return $this->localize_faces_in_css( $imported, $url );
			},
			$out
		);

		// 2. Provider @font-face already in the file → localise src in place.
		$out = $this->localize_faces_in_css( $out, $base_url, $found );

		return $found ? $out : null;
	}

	/**
	 * Rewrite every remote provider @font-face in a CSS string to a locally
	 * hosted copy: download the binary, register the variant (honouring the
	 * user's enable/disable + subset choices), and replace the block with a
	 * clean local @font-face. Non-provider faces are left untouched.
	 *
	 * @param string $css    CSS string.
	 * @param string $source Source URL (for provenance + absolutising).
	 * @param bool   $found  Set true if any provider face was localised (by ref).
	 * @return string
	 */
	private function localize_faces_in_css( string $css, string $source, bool &$found = false ): string {
		$blocks = $this->parser->blocks( $css );

		if ( empty( $blocks ) ) {
			return $css;
		}

		$variable_map = $this->parser->variable_families( $blocks );
		$disabled     = $this->registry->disabled_keys();
		$display      = (string) Settings::get( 'font_display', 'swap' );
		$effective    = $this->effective_subsets();

		foreach ( $blocks as $block ) {
			if ( ! $this->parser->is_remote_origin( $block['full'] ) ) {
				continue;
			}

			$props  = $this->parser->properties( $block['body'] );
			$family = $props['family'];
			$src    = $props['src'];

			if ( '' === $family || '' === $src ) {
				continue;
			}

			$src    = Providers::normalize_url( $src, $source );
			$subset = isset( $block['lead'] ) ? $this->clean_subset( $block['lead'] ) : '';

			if ( '' === $subset ) {
				$subset = FontFaceParser::subset_from_unicode_range( $props['unicode_range'] );
			}

			// Subset filter (baseline-inclusive). Drop an unselected, non-baseline
			// subset face from the sheet entirely.
			if ( '' !== $subset && ! in_array( $subset, $effective, true ) ) {
				$css = $this->str_replace_once( $block['full'], '', $css );
				continue;
			}

			$subset_key  = '' !== $subset ? $subset : 'latin';
			$is_variable = isset( $variable_map[ strtolower( $family ) ] )
				|| (bool) preg_match( '/\d+\s+\d+/', $props['weight'] );

			$variant = array(
				'family'        => $family,
				'weight'        => $props['weight'],
				'style'         => $props['style'],
				'subset'        => $subset_key,
				'unicode_range' => $props['unicode_range'],
				'is_variable'   => $is_variable,
				'provider'      => Providers::id_for( $src ),
				'source'        => $source,
			);

			$key = Registry::variant_key( $family, $props['weight'], $props['style'], $subset_key );

			// Disabled variant → register (so it shows in the admin to re-enable)
			// but remove the face so it doesn't load.
			if ( isset( $disabled[ $key ] ) ) {
				$this->register_variant( $variant, '', 0 );
				$css   = $this->str_replace_once( $block['full'], '', $css );
				$found = true;
				continue;
			}

			$basename  = $this->basename_for( $variant );
			$font_file = $this->locate_font( $basename );
			$size      = $font_file ? $this->storage->size( $font_file ) : 0;

			if ( null === $font_file ) {
				$dl = $this->downloader->fetch_font( $src, $basename, $this->storage );

				if ( null === $dl ) {
					continue; // Leave the original src if the download fails.
				}

				$font_file = $dl['file'];
				$size      = $dl['size'];
			}

			$local_url = add_query_arg( 'ver', Settings::buster(), $this->storage->url( $font_file ) );

			$this->register_variant( $variant, $font_file, $size );

			$css = $this->str_replace_once( $block['full'], rtrim( $this->build_face_rule( $variant, $local_url, $display ) ), $css );

			self::$touched[] = array(
				'family' => $family,
				'weight' => $props['weight'],
				'style'  => $props['style'],
			);

			$found = true;
		}

		return $css;
	}

	/**
	 * Register (idempotent upsert) one variant.
	 *
	 * @param array<string,mixed> $v         Variant.
	 * @param string              $font_file Cache-relative font file ('' if disabled/none).
	 * @param int                 $size      File size in bytes.
	 * @return void
	 */
	private function register_variant( array $v, string $font_file, int $size = 0 ): void {
		$this->registry->upsert(
			array(
				'family'      => $v['family'],
				'weight'      => $v['weight'],
				'style'       => $v['style'],
				'subset'      => $v['subset'],
				'is_variable' => $v['is_variable'],
				'provider'    => $v['provider'],
				'css_file'    => '',
				'font_file'   => $font_file,
				'file_size'   => $size,
				'source_url'  => $v['source'] ?? '',
			)
		);
	}

	/**
	 * Replace only the first occurrence of a substring.
	 *
	 * @param string $needle      Needle.
	 * @param string $replacement Replacement.
	 * @param string $haystack    Haystack.
	 * @return string
	 */
	private function str_replace_once( string $needle, string $replacement, string $haystack ): string {
		$pos = strpos( $haystack, $needle );

		if ( false === $pos ) {
			return $haystack;
		}

		return substr_replace( $haystack, $replacement, $pos, strlen( $needle ) );
	}

	/**
	 * Effective subset set: exactly what the user selected. Latin is no longer
	 * force-included — users may deselect it. As a safety net, if the selection
	 * is completely empty we fall back to latin so a site is never left without
	 * any matching faces (which would drop every identifiable-subset font).
	 *
	 * @return string[]
	 */
	private function effective_subsets(): array {
		$selected = array_values( array_filter( array_map( 'strval', (array) Settings::get( 'subsets', array( 'latin' ) ) ) ) );

		if ( empty( $selected ) ) {
			return array( 'latin' );
		}

		return array_values( array_unique( $selected ) );
	}

	/**
	 * Append/merge a global &subset= param onto a Google CSS v1 request so the
	 * API returns the requested scripts. Non-v1 URLs are returned unchanged.
	 *
	 * @param string   $url     URL.
	 * @param string[] $subsets Subsets to ensure are requested.
	 * @return string
	 */
	private function augment_v1_subsets( string $url, array $subsets ): string {
		// Only Google v1 (/css?) honours the global subset param; v2 (/css2) and
		// other providers control subsets differently.
		if ( false === strpos( $url, 'fonts.googleapis.com/css' ) || false !== strpos( $url, '/css2' ) ) {
			return $url;
		}

		$existing = array();

		if ( preg_match( '/[?&]subset=([^&]+)/i', $url, $m ) ) {
			$existing = explode( ',', strtolower( $m[1] ) );
		}

		$merged = array_values( array_unique( array_filter( array_merge( $existing, $subsets ) ) ) );

		// Strip any existing subset param, then re-append the merged list.
		$url = (string) preg_replace( '/([?&])subset=[^&]*/i', '$1', $url );
		$url = rtrim( $url, '?&' );
		$sep = false === strpos( $url, '?' ) ? '?' : '&';

		return $url . $sep . 'subset=' . implode( ',', $merged );
	}

	/**
	 * Absolutize relative url() references against a base URL so a cleaned copy
	 * served from /uploads/easyfonts/ still resolves theme assets correctly.
	 *
	 * @param string $css      CSS.
	 * @param string $base_url Base URL of the original file.
	 * @return string
	 */
	private function absolutize_urls( string $css, string $base_url ): string {
		return (string) preg_replace_callback(
			'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
			function ( $m ) use ( $base_url ) {
				$url = trim( $m[2] );

				if ( '' === $url
					|| 0 === strpos( $url, 'data:' )
					|| 0 === strpos( $url, '//' )
					|| 0 === strpos( $url, '/' )
					|| preg_match( '#^https?://#i', $url ) ) {
					return $m[0];
				}

				return "url('" . Providers::normalize_url( $url, $base_url ) . "')";
			},
			$css
		);
	}

	/**
	 * Normalize a subset comment token (e.g. "latin", "[0]" → '').
	 *
	 * @param string $lead Raw comment text.
	 * @return string
	 */
	private function clean_subset( string $lead ): string {
		$lead = strtolower( trim( $lead ) );

		return preg_match( '/^[a-z]+(?:-[a-z]+)?$/', $lead ) ? $lead : '';
	}

	/**
	 * Does this CSS reference any provider host? (cheap pre-filter)
	 *
	 * @param string $css CSS.
	 * @return bool
	 */
	private function mentions_provider( string $css ): bool {
		foreach ( Providers::hint_hosts() as $host ) {
			if ( false !== strpos( $css, $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register a provider CSS URL to fetch.
	 *
	 * @param string $url      Normalized URL.
	 * @param string $detector Detector id ('' if from a nested source).
	 */
	private function add_css_url( string $url, string $detector ): void {
		$this->css_urls[ $url ] = Providers::id_for( $url );

		if ( '' !== $detector ) {
			$this->mark( $detector );
		}
	}

	/**
	 * Flag a detector as having contributed.
	 *
	 * @param string $detector Detector id.
	 */
	private function mark( string $detector ): void {
		$this->contributed[ $detector ] = true;
	}
}
