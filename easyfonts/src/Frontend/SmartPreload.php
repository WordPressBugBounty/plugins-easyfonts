<?php
/**
 * Smart preload.
 *
 * Emits <link rel="preload"> for the font faces that render ABOVE THE FOLD on
 * the current page, so the browser fetches them as early as possible. Preload
 * is a scarce resource — preloading a font that isn't critical (or isn't even
 * on the page) competes with the hero content — so the set is built narrowly:
 *
 *   1. USER-FORCED variants — toggled to "always preload". First, so an
 *      explicit choice never loses its slot to an automatic one.
 *   2. PER-ROUTE above-the-fold faces — measured by the beacon for THIS route.
 *      A font above the fold only on one page is preloaded only there.
 *   3. GLOBAL above-the-fold faces — for families this route hasn't measured:
 *      the faces of families that are above the fold on every route where they
 *      render (e.g. the body/heading font), preloaded wherever present without
 *      any per-route lookup (the cheap path the site-wide fonts take).
 *
 * (2.0.4) Faces, not families: a family with seven weights used to fill every
 * slot with weights the page never shows, in declaration order, while the one
 * it did show came last and was cut by the cap. At most 3 per page by default
 * (was 6) — fonts compete with the hero image — shared round-robin, so every
 * above-the-fold family gets a slot before any family gets a second.
 *
 * Every candidate is intersected with the families actually emitted on this
 * page (Consolidator::touched), and resolved to the file that is genuinely
 * hosted, in the subset of the page language — so a font is never preloaded
 * on a page that doesn't use it, and a face the beacon reported at a weight we
 * don't host still resolves to the right file.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Frontend;

use EasyFonts\Detect\Consolidator;
use EasyFonts\Fonts\Registry;
use EasyFonts\Fonts\Storage;
use EasyFonts\Fonts\UsageTracker;
use EasyFonts\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds deduped, page-scoped preload link markup.
 */
class SmartPreload {

	/**
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * @var Storage
	 */
	private Storage $storage;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->registry = new Registry();
		$this->storage  = new Storage();
	}

	/**
	 * Build preload markup for the current request.
	 *
	 * @param string                            $route            Canonical route (path) of the current page.
	 * @param string                            $device           'mobile'|'desktop'|'any' (reserved).
	 * @param array<string,bool>                $present_families Lowercased families emitted on this page (from Consolidator::touched).
	 * @param array<string,array<string,bool>>  $fold_faces       Per family rendered on THIS route: its above-the-fold faces ('weight|style').
	 * @return string
	 */
	public function build( string $route = '', string $device = 'any', array $present_families = array(), array $fold_faces = array() ): string {
		if ( ! Settings::get( 'smart_preload', 1 ) ) {
			return '';
		}

		$cap = max( 1, (int) apply_filters( 'easyfonts_preload_cap', 3 ) );

		$links = '';
		$seen  = array();
		$count = 0;

		// 1. User-forced "always preload" variants, intersected with the page.
		$gate = ! empty( $present_families );

		foreach ( $this->registry->preload_targets() as $u ) {
			if ( $count >= $cap ) {
				break;
			}

			if ( $gate && empty( $present_families[ strtolower( (string) $u['family'] ) ] ) ) {
				continue; // forced font isn't on this page — don't preload here.
			}

			if ( $this->emit_link( $u['file'], $seen, $links ) ) {
				$count++;
			}
		}

		// 2 + 3. Above-the-fold faces (per-route, else global) — resolved to the
		//    hosted variant, so a faux-bold/weight-mismatch report still lands
		//    on a real file.
		$subset = self::page_subset();

		foreach ( $this->candidates( $fold_faces ) as $c ) {
			if ( $count >= $cap ) {
				break;
			}

			$file = $this->registry->resolve_preload_file( $c['family'], $c['weight'], $c['style'], $subset );

			if ( $this->emit_link( $file, $seen, $links ) ) {
				$count++;
			}
		}

		return $links;
	}

	/**
	 * Above-the-fold faces to preload on this page, best first.
	 *
	 * Per family emitted here: its faces measured above the fold on this route
	 * (none, when it renders only further down); for a family this route hasn't
	 * measured, its global faces. Within a family the most body-like face comes
	 * first (normal style, weight nearest 400); families then take turns, so a
	 * small cap covers every above-the-fold family before a second weight of
	 * any one of them.
	 *
	 * @param array<string,array<string,bool>> $fold_faces Per-route faces by lowercased family.
	 * @return array<int,array{family:string,weight:string,style:string}>
	 */
	private function candidates( array $fold_faces ): array {
		// Families in page order, with the emitted spelling (registry lookups
		// match on it) and their emitted faces (for the 2.0.3 fallback).
		$names   = array();
		$emitted = array();

		foreach ( Consolidator::touched() as $t ) {
			$fam = strtolower( (string) $t['family'] );

			if ( ! isset( $names[ $fam ] ) ) {
				$names[ $fam ] = (string) $t['family'];
			}

			$emitted[ $fam ][ $t['weight'] . '|' . $t['style'] ] = true;
		}

		$global = ( new UsageTracker() )->global_preload_faces();
		$lists  = array();

		foreach ( $names as $fam => $family ) {
			if ( isset( $fold_faces[ $fam ] ) ) {
				$faces = $fold_faces[ $fam ];
			} elseif ( isset( $global[ $fam ] ) ) {
				// `true` = stored by 2.0.3 without faces: the whole family, as then.
				$faces = true === $global[ $fam ] ? $emitted[ $fam ] : $global[ $fam ];
			} else {
				continue;
			}

			$list = array();

			foreach ( array_keys( $faces ) as $face ) {
				$parts  = explode( '|', (string) $face, 2 );
				$list[] = array(
					'family' => $family,
					'weight' => $parts[0],
					'style'  => $parts[1] ?? 'normal',
				);
			}

			usort(
				$list,
				function ( $a, $b ) {
					return self::rank( $a ) <=> self::rank( $b );
				}
			);

			$lists[] = $list;
		}

		$out = array();

		for ( $depth = 0; ; $depth++ ) {
			$added = false;

			foreach ( $lists as $list ) {
				if ( isset( $list[ $depth ] ) ) {
					$out[] = $list[ $depth ];
					$added = true;
				}
			}

			if ( ! $added ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Sort key for a face within its family: normal before italic, then the
	 * weight nearest 400 (body text) first.
	 *
	 * @param array{weight:string,style:string} $face Face.
	 * @return int
	 */
	private static function rank( array $face ): int {
		return ( 'normal' === $face['style'] ? 0 : 1000 ) + abs( (int) $face['weight'] - 400 );
	}

	/**
	 * The subset the page's text is set in, from its locale: the one file per
	 * face worth preloading. Latin-script languages — and Chinese/Japanese,
	 * which Google slices into numbered ranges instead — keep 'latin'; a face
	 * whose family lacks the subset falls back to latin in the lookup.
	 *
	 * @return string
	 */
	private static function page_subset(): string {
		$locale = (string) get_locale();
		$lang   = strtolower( (string) strtok( $locale, '_-' ) );

		$map = array(
			'ru'  => 'cyrillic',
			'uk'  => 'cyrillic',
			'bg'  => 'cyrillic',
			'sr'  => 'cyrillic',
			'mk'  => 'cyrillic',
			'be'  => 'cyrillic',
			'kk'  => 'cyrillic',
			'ky'  => 'cyrillic',
			'mn'  => 'cyrillic',
			'tg'  => 'cyrillic',
			'tt'  => 'cyrillic',
			'ba'  => 'cyrillic',
			'sah' => 'cyrillic',
			'os'  => 'cyrillic',
			'el'  => 'greek',
			'he'  => 'hebrew',
			'ar'  => 'arabic',
			'ary' => 'arabic',
			'fa'  => 'arabic',
			'ur'  => 'arabic',
			'ps'  => 'arabic',
			'ckb' => 'arabic',
			'ug'  => 'arabic',
			'skr' => 'arabic',
			'hi'  => 'devanagari',
			'mr'  => 'devanagari',
			'ne'  => 'devanagari',
			'bn'  => 'bengali',
			'as'  => 'bengali',
			'gu'  => 'gujarati',
			'pa'  => 'gurmukhi',
			'ta'  => 'tamil',
			'te'  => 'telugu',
			'kn'  => 'kannada',
			'ml'  => 'malayalam',
			'si'  => 'sinhala',
			'or'  => 'oriya',
			'th'  => 'thai',
			'km'  => 'khmer',
			'my'  => 'myanmar',
			'bo'  => 'tibetan',
			'ko'  => 'korean',
		);

		/**
		 * Subset to preload on this page (Google subset name, e.g. 'latin',
		 * 'cyrillic', 'greek').
		 *
		 * @param string $subset Subset derived from the locale.
		 * @param string $locale Locale.
		 */
		return (string) apply_filters( 'easyfonts_preload_subset', $map[ $lang ] ?? 'latin', $locale );
	}

	/**
	 * Append a preload <link> for a hosted file, deduping by file and skipping
	 * anything missing on disk. Returns true when a link was added.
	 *
	 * @param string|null         $file  Font filename.
	 * @param array<string,bool>  $seen  Dedupe set (by reference).
	 * @param string              $links Accumulated markup (by reference).
	 * @return bool
	 */
	private function emit_link( ?string $file, array &$seen, string &$links ): bool {
		$file = (string) $file;

		if ( '' === $file || isset( $seen[ $file ] ) || ! $this->storage->exists( $file ) ) {
			return false;
		}

		$seen[ $file ] = true;

		$url    = $this->storage->font_url( $file );
		$links .= sprintf(
			'<link rel="preload" href="%s" as="font" type="%s" crossorigin>' . "\n",
			esc_url( $url ),
			esc_attr( $this->mime_for( $file ) )
		);

		return true;
	}

	/**
	 * MIME for a font file.
	 *
	 * @param string $file Filename.
	 * @return string
	 */
	private function mime_for( string $file ): string {
		$ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );

		$map = array(
			'woff2' => 'font/woff2',
			'woff'  => 'font/woff',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
		);

		return $map[ $ext ] ?? 'font/woff2';
	}
}
