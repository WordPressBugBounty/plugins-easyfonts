<?php
/**
 * Font metrics reader.
 *
 * Parses the head / hhea / OS-2 tables out of a real font binary so we can
 * compute size-adjust + *-override values for a metric-matched fallback face
 * (the technique that drives layout shift to ~0 during font swap).
 *
 * TTF/OTF (sfnt) parse directly. WOFF is zlib-per-table, decompressed inline.
 * WOFF2 is Brotli and cannot be decoded in stock PHP, so we return null and let
 * the caller fetch a TTF copy purely for metric extraction.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Reads vertical + horizontal metrics from font binaries.
 */
class FontMetricsReader {

	/**
	 * Detect the container and extract metrics.
	 *
	 * @param string $bytes Raw font bytes.
	 * @return array<string,float|int>|null
	 */
	public function read( string $bytes ): ?array {
		if ( strlen( $bytes ) < 12 ) {
			return null;
		}

		$sig = substr( $bytes, 0, 4 );

		if ( 'wOF2' === $sig ) {
			return null; // Brotli — not decodable here.
		}

		if ( 'wOFF' === $sig ) {
			$bytes = $this->woff_to_tables( $bytes );

			return $bytes ? $this->parse_tables( $bytes ) : null;
		}

		// 0x00010000 (TrueType) or 'OTTO' (CFF/OpenType) or 'true'/'typ1'.
		return $this->parse_sfnt( $bytes );
	}

	/**
	 * Parse a raw sfnt (TTF/OTF) buffer.
	 *
	 * @param string $bytes sfnt bytes.
	 * @return array<string,float|int>|null
	 */
	private function parse_sfnt( string $bytes ): ?array {
		$num_tables = $this->u16( $bytes, 4 );

		if ( $num_tables <= 0 || $num_tables > 4096 ) {
			return null;
		}

		$tables = array();
		$cursor = 12;

		for ( $i = 0; $i < $num_tables; $i++ ) {
			$tag    = substr( $bytes, $cursor, 4 );
			$offset = $this->u32( $bytes, $cursor + 8 );
			$length = $this->u32( $bytes, $cursor + 12 );

			$tables[ $tag ] = substr( $bytes, $offset, $length );
			$cursor        += 16;
		}

		return $this->extract( $tables );
	}

	/**
	 * Convenience wrapper used by the WOFF path (tables already keyed).
	 *
	 * @param array<string,string> $tables Tag => bytes.
	 * @return array<string,float|int>|null
	 */
	private function parse_tables( array $tables ): ?array {
		return $this->extract( $tables );
	}

	/**
	 * Decompress a WOFF container into a tag => bytes map.
	 *
	 * @param string $bytes WOFF bytes.
	 * @return array<string,string>|null
	 */
	private function woff_to_tables( string $bytes ): ?array {
		$num_tables = $this->u16( $bytes, 12 );

		if ( $num_tables <= 0 || $num_tables > 4096 ) {
			return null;
		}

		$tables = array();
		$cursor = 44; // WOFF header length.

		for ( $i = 0; $i < $num_tables; $i++ ) {
			$tag      = substr( $bytes, $cursor, 4 );
			$offset   = $this->u32( $bytes, $cursor + 4 );
			$comp_len = $this->u32( $bytes, $cursor + 8 );
			$orig_len = $this->u32( $bytes, $cursor + 12 );

			$chunk = substr( $bytes, $offset, $comp_len );

			if ( $comp_len < $orig_len ) {
				$chunk = @gzuncompress( $chunk );

				if ( false === $chunk ) {
					$cursor += 20;
					continue;
				}
			}

			$tables[ $tag ] = $chunk;
			$cursor        += 20;
		}

		return $tables;
	}

	/**
	 * Extract the numbers we care about from parsed tables.
	 *
	 * @param array<string,string> $tables Tag => bytes.
	 * @return array<string,float|int>|null
	 */
	private function extract( array $tables ): ?array {
		if ( empty( $tables['head'] ) ) {
			return null;
		}

		$head         = $tables['head'];
		$units_per_em = $this->u16( $head, 18 );

		if ( $units_per_em <= 0 ) {
			return null;
		}

		$ascent  = 0;
		$descent = 0;
		$line_gap = 0;
		$x_avg    = 0;
		$cap      = 0;
		$x_height = 0;

		// Prefer OS/2 typographic metrics; they're the most consistent across renderers.
		if ( ! empty( $tables['OS/2'] ) ) {
			$os2     = $tables['OS/2'];
			$version = $this->u16( $os2, 0 );

			$x_avg    = $this->s16( $os2, 2 );
			$ascent   = $this->s16( $os2, 68 );  // sTypoAscender
			$descent  = $this->s16( $os2, 70 );  // sTypoDescender
			$line_gap = $this->s16( $os2, 72 );  // sTypoLineGap

			if ( $version >= 2 && strlen( $os2 ) >= 90 ) {
				$x_height = $this->s16( $os2, 86 );
				$cap      = $this->s16( $os2, 88 );
			}
		}

		// Fall back to hhea for ascent/descent if OS/2 was missing or zeroed.
		if ( ( 0 === $ascent || 0 === $descent ) && ! empty( $tables['hhea'] ) ) {
			$hhea     = $tables['hhea'];
			$ascent   = $this->s16( $hhea, 4 );
			$descent  = $this->s16( $hhea, 6 );
			$line_gap = $this->s16( $hhea, 8 );
		}

		if ( 0 === $ascent ) {
			return null;
		}

		return array(
			'unitsPerEm'    => $units_per_em,
			'ascent'        => $ascent,
			'descent'       => $descent,
			'lineGap'       => $line_gap,
			'xAvgCharWidth' => $x_avg,
			'capHeight'     => $cap,
			'xHeight'       => $x_height,
		);
	}

	/**
	 * Reference avg-width ratios for common system fallbacks (xAvgCharWidth / unitsPerEm).
	 * Values are objective font metrics; used only to size the local() fallback.
	 *
	 * @return array<string,array{stack:string,ratio:float}>
	 */
	public static function system_fallbacks(): array {
		return array(
			'sans-serif' => array(
				'stack' => 'Arial, "Helvetica Neue", Helvetica, sans-serif',
				'local' => 'Arial',
				'ratio' => 0.4414, // Arial: 904 / 2048
			),
			'serif'      => array(
				'stack' => 'Georgia, "Times New Roman", Times, serif',
				'local' => 'Georgia',
				'ratio' => 0.4438, // Georgia: 909 / 2048
			),
			'monospace'  => array(
				'stack' => '"Courier New", Courier, monospace',
				'local' => 'Courier New',
				'ratio' => 0.6024, // Courier New: 1233 / 2048
			),
		);
	}

	/**
	 * Read an unsigned 16-bit big-endian integer.
	 *
	 * @param string $bin    Binary string.
	 * @param int    $offset Byte offset.
	 * @return int
	 */
	private function u16( string $bin, int $offset ): int {
		if ( $offset + 2 > strlen( $bin ) ) {
			return 0;
		}

		$parts = unpack( 'n', substr( $bin, $offset, 2 ) );

		return $parts ? (int) $parts[1] : 0;
	}

	/**
	 * Read a signed 16-bit big-endian integer.
	 *
	 * @param string $bin    Binary string.
	 * @param int    $offset Byte offset.
	 * @return int
	 */
	private function s16( string $bin, int $offset ): int {
		$value = $this->u16( $bin, $offset );

		return $value >= 0x8000 ? $value - 0x10000 : $value;
	}

	/**
	 * Read an unsigned 32-bit big-endian integer.
	 *
	 * @param string $bin    Binary string.
	 * @param int    $offset Byte offset.
	 * @return int
	 */
	private function u32( string $bin, int $offset ): int {
		if ( $offset + 4 > strlen( $bin ) ) {
			return 0;
		}

		$parts = unpack( 'N', substr( $bin, $offset, 4 ) );

		return $parts ? (int) $parts[1] : 0;
	}
}
