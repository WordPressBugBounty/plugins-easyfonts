<?php
/**
 * Detection pipeline.
 *
 * Thin entry point that delegates to the Consolidator (the aggregate-and-replace
 * engine). Kept as a stable seam so the output buffer and any add-ons don't need
 * to know about the consolidation internals.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Detect;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates font detection + local hosting for a document.
 */
class Pipeline {

	/**
	 * Process a document: detect every web-font source, host them locally as a
	 * single consolidated stylesheet, and return the rewritten HTML.
	 *
	 * @param string             $html      Full HTML.
	 * @param bool               $force     Force a full pass (probe mode).
	 * @param array<string,bool> $scope_out Confirmed loaded-but-unused families on this route (lowercased => true).
	 * @param bool               $measured  Route has beacon data (enables scoping).
	 * @return string
	 */
	public function process( string $html, bool $force = false, array $scope_out = array(), bool $measured = false ): string {
		/**
		 * Filter the consolidator instance, allowing advanced replacement.
		 *
		 * @param Consolidator $consolidator
		 */
		$consolidator = apply_filters( 'easyfonts_consolidator', new Consolidator() );

		if ( method_exists( $consolidator, 'set_page_scope' ) ) {
			$consolidator->set_page_scope( $scope_out, $measured );
		}

		return $consolidator->run( $html, $force );
	}

	/**
	 * Variants hosted during the most recent run (for fallbacks + usage).
	 *
	 * @return array<int,array{family:string,weight:string,style:string}>
	 */
	public static function touched(): array {
		return Consolidator::touched();
	}
}
