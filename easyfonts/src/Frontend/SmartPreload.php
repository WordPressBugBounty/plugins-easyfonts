<?php
/**
 * Smart preload.
 *
 * Emits <link rel="preload"> for the font variants the user has marked to
 * preload (Fonts screen → per-variant Preload toggle). Results are deduped by
 * file and capped, and the output buffer positions them ABOVE the consolidated
 * stylesheet so the browser starts fetching them as early as possible.
 *
 * When "smart preload" is enabled, the beacon auto-marks above-the-fold variants
 * as preloaded after a real visit (see RestApi::beacon), so this populates with
 * sensible defaults that the user can then refine.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Frontend;

use EasyFonts\Fonts\Registry;
use EasyFonts\Fonts\Storage;
use EasyFonts\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds deduped preload link markup.
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
	 * @return string
	 */
	public function build(): string {
		if ( ! Settings::get( 'smart_preload', 1 ) ) {
			return '';
		}

		$targets = $this->registry->preload_targets();

		if ( empty( $targets ) ) {
			return '';
		}

		$links = '';
		$seen  = array();
		$count = 0;

		foreach ( $targets as $t ) {
			if ( $count >= 6 ) {
				break; // Hard cap — preloading too much hurts more than it helps.
			}

			$file = $t['file'];

			if ( '' === $file || isset( $seen[ $file ] ) || ! $this->storage->exists( $file ) ) {
				continue;
			}

			$seen[ $file ] = true;

			$url    = add_query_arg( 'ver', Settings::buster(), $this->storage->url( $file ) );
			$links .= sprintf(
				'<link rel="preload" href="%s" as="font" type="%s" crossorigin>' . "\n",
				esc_url( $url ),
				esc_attr( $this->mime_for( $file ) )
			);
			$count++;
		}

		return $links;
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
