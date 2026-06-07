<?php
/**
 * Smart preload.
 *
 * Emits <link rel="preload"> for the font faces that render ABOVE THE FOLD on
 * the current page, so the browser fetches them as early as possible. Preload
 * is a scarce resource — preloading a font that isn't critical (or isn't even
 * on the page) competes with the hero content — so the set is built narrowly:
 *
 *   1. PER-ROUTE above-the-fold families — measured by the beacon for THIS
 *      route. A font above the fold only on one page is preloaded only there.
 *   2. GLOBAL above-the-fold families — families that are above the fold on
 *      every route where they render (e.g. the body/heading font). Their answer
 *      is the same everywhere, so they're preloaded wherever present without any
 *      per-route lookup (the cheap path the site-wide fonts take).
 *   3. USER-FORCED families — variants the user toggled to "always preload".
 *
 * Every candidate is intersected with the faces actually emitted on this page
 * (Consolidator::touched), and resolved to the file that is genuinely hosted —
 * so a font is never preloaded on a page that doesn't use it, and a face the
 * beacon reported at a weight we don't host still resolves to the right file.
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
	 * @param string             $route             Canonical route (path) of the current page.
	 * @param string             $device            'mobile'|'desktop'|'any' (reserved; matching is family-level).
	 * @param array<string,bool> $present_families  Lowercased families emitted on this page (from Consolidator::touched).
	 * @param array<string,bool> $above_fold        Lowercased families measured above the fold on THIS route.
	 * @return string
	 */
	public function build( string $route = '', string $device = 'any', array $present_families = array(), array $above_fold = array() ): string {
		if ( ! Settings::get( 'smart_preload', 1 ) ) {
			return '';
		}

		// Families that should preload here: this route's above-the-fold set,
		// plus the uniformly-above-the-fold (global) set. The global set is the
		// "skip per-route checking" path — its members preload wherever present.
		$want = $above_fold;

		foreach ( ( new UsageTracker() )->global_preload_families() as $fam => $_ ) {
			$want[ $fam ] = true;
		}

		$cap = max( 1, (int) apply_filters( 'easyfonts_preload_cap', 6 ) );

		$links = '';
		$seen  = array();
		$count = 0;

		// 1. Above-the-fold (per-route + global): preload the faces this page
		//    actually emits for those families — using the hosted variant, so a
		//    faux-bold/weight-mismatch report still resolves to a real file.
		foreach ( Consolidator::touched() as $t ) {
			if ( $count >= $cap ) {
				break;
			}

			$fam = strtolower( (string) $t['family'] );

			if ( empty( $want[ $fam ] ) ) {
				continue;
			}

			$file = $this->registry->resolve_preload_file( (string) $t['family'], (string) $t['weight'], (string) $t['style'] );

			if ( $this->emit_link( $file, $seen, $links ) ) {
				$count++;
			}
		}

		// 2. User-forced "always preload" variants, intersected with the page.
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

		return $links;
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

		$url    = add_query_arg( 'ver', Settings::buster(), $this->storage->url( $file ) );
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
