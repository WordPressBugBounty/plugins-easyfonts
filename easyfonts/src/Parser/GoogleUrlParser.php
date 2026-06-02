<?php
/**
 * Google Fonts API URL parser.
 *
 * Understands both the legacy v1 syntax (?family=Roboto:400,700|Open+Sans:300)
 * and the css2 syntax (?family=Roboto:ital,wght@0,400;1,700).
 *
 * @package EasyFonts
 */

namespace EasyFonts\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a Google/Bunny Fonts CSS URL into a structured family map.
 */
class GoogleUrlParser {

	/**
	 * Parse a CSS URL into families.
	 *
	 * @param string $url Stylesheet URL.
	 * @return array<string,array{weights:string[],styles:string[]}>
	 */
	public function parse( string $url ): array {
		$url   = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
		$query = wp_parse_url( $url, PHP_URL_QUERY );

		if ( ! $query ) {
			return array();
		}

		parse_str( $query, $params );

		$raw = array();

		if ( isset( $params['family'] ) ) {
			$raw = is_array( $params['family'] ) ? $params['family'] : array( $params['family'] );
		}

		$families = array();

		foreach ( $raw as $family_string ) {
			$this->parse_family_string( (string) $family_string, $families );
		}

		return $families;
	}

	/**
	 * Parse a single family= value (may contain v1 pipes or a css2 axis spec).
	 *
	 * @param string                                                          $value    Family value.
	 * @param array<string,array{weights:string[],styles:string[]}>           $families Accumulator (by ref).
	 */
	private function parse_family_string( string $value, array &$families ): void {
		if ( false !== strpos( $value, '@' ) ) {
			$this->parse_css2( $value, $families );
			return;
		}

		// v1: pipe-separated families, each "Name:specs".
		foreach ( explode( '|', $value ) as $chunk ) {
			$parts  = explode( ':', $chunk, 2 );
			$family = $this->clean_name( $parts[0] );

			if ( '' === $family ) {
				continue;
			}

			$weights = array();
			$styles  = array();

			$specs = $parts[1] ?? '400';

			foreach ( explode( ',', $specs ) as $spec ) {
				$spec = trim( $spec );

				if ( preg_match( '/^(\d+)(italic)?$/i', $spec, $m ) ) {
					$weights[] = $m[1];
					$styles[]  = isset( $m[2] ) && '' !== $m[2] ? 'italic' : 'normal';
				} elseif ( 'italic' === strtolower( $spec ) ) {
					$weights[] = '400';
					$styles[]  = 'italic';
				} elseif ( 'regular' === strtolower( $spec ) || '' === $spec ) {
					$weights[] = '400';
					$styles[]  = 'normal';
				}
			}

			$this->merge( $families, $family, $weights, $styles );
		}
	}

	/**
	 * Parse css2 axis syntax: "Name:ital,wght@0,400;1,700" or "Name:wght@400;700".
	 *
	 * @param string                                                $value    Family value.
	 * @param array<string,array{weights:string[],styles:string[]}> $families Accumulator (by ref).
	 */
	private function parse_css2( string $value, array &$families ): void {
		[ $left, $axes ] = array_pad( explode( '@', $value, 2 ), 2, '' );

		$family = $this->clean_name( explode( ':', $left )[0] );

		if ( '' === $family ) {
			return;
		}

		// Axis names appear before '@', e.g. "Roboto:ital,wght".
		$axis_names = array();

		if ( false !== strpos( $left, ':' ) ) {
			$axis_names = explode( ',', explode( ':', $left, 2 )[1] );
		}

		$ital_index = array_search( 'ital', $axis_names, true );
		$wght_index = array_search( 'wght', $axis_names, true );

		$weights = array();
		$styles  = array();

		foreach ( explode( ';', $axes ) as $tuple ) {
			$cols = explode( ',', $tuple );

			$weight = '400';
			$style  = 'normal';

			if ( false !== $wght_index && isset( $cols[ $wght_index ] ) ) {
				$weight = trim( $cols[ $wght_index ] );
			} elseif ( 1 === count( $cols ) && is_numeric( trim( $cols[0] ) ) ) {
				$weight = trim( $cols[0] );
			}

			if ( false !== $ital_index && isset( $cols[ $ital_index ] ) ) {
				$style = '1' === trim( $cols[ $ital_index ] ) ? 'italic' : 'normal';
			}

			// Variable range like "400..700" — keep the base weight.
			if ( false !== strpos( $weight, '..' ) ) {
				$weight = explode( '..', $weight )[0];
			}

			if ( '' !== $weight ) {
				$weights[] = $weight;
				$styles[]  = $style;
			}
		}

		if ( empty( $weights ) ) {
			$weights = array( '400' );
			$styles  = array( 'normal' );
		}

		$this->merge( $families, $family, $weights, $styles );
	}

	/**
	 * Merge parsed weights/styles into the accumulator.
	 *
	 * @param array<string,array{weights:string[],styles:string[]}> $families Accumulator (by ref).
	 * @param string                                                 $family   Family name.
	 * @param string[]                                               $weights  Weights.
	 * @param string[]                                               $styles   Styles.
	 */
	private function merge( array &$families, string $family, array $weights, array $styles ): void {
		if ( empty( $weights ) ) {
			$weights = array( '400' );
			$styles  = array( 'normal' );
		}

		if ( ! isset( $families[ $family ] ) ) {
			$families[ $family ] = array(
				'weights' => array(),
				'styles'  => array(),
			);
		}

		$families[ $family ]['weights'] = array_values( array_unique( array_merge( $families[ $family ]['weights'], $weights ) ) );
		$families[ $family ]['styles']  = array_values( array_unique( array_merge( $families[ $family ]['styles'], $styles ) ) );
	}

	/**
	 * Normalize a raw family token ("Open+Sans" => "Open Sans").
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private function clean_name( string $name ): string {
		return trim( str_replace( '+', ' ', trim( $name ) ) );
	}
}
