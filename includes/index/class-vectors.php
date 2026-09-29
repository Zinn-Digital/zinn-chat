<?php
/**
 * Vector arithmetic for the site index.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vectors are stored L2-normalised and quantised to signed bytes (one byte per dimension).
 *
 * ⭐ Why bytes: a 256-dimension vector is 260 bytes instead of 1 KB as float32, so a 20,000-page
 * site's whole item matrix is about 5 MB and one query can scan all of it in PHP quickly. The
 * quantisation error is far below the ranking noise: the cosine of a vector with its own packed
 * copy is above 0.999 (asserted by ZinnChatIndexTest).
 */
final class Vectors {

	/** Dimensions requested from providers that can shorten their vectors. */
	public const DIMENSIONS = 256;

	/**
	 * Normalise to unit length.
	 *
	 * @param array<int, float> $vector Vector.
	 * @return array<int, float>
	 */
	public static function normalise( array $vector ): array {
		$sum = 0.0;
		foreach ( $vector as $value ) {
			$sum += $value * $value;
		}
		if ( $sum <= 0.0 ) {
			return $vector;
		}
		$norm = sqrt( $sum );
		return array_map(
			static function ( $value ) use ( $norm ): float {
				return (float) $value / $norm;
			},
			$vector
		);
	}

	/**
	 * Pack a vector: a 4-byte scale, then one signed byte per dimension.
	 *
	 * The vector is normalised, then scaled so its LARGEST component uses the whole byte range
	 * (a normalised 256-dimension vector has components around ±0.1, so a fixed ×127 would use a
	 * tenth of the range). The scale travels with the vector, so cosine() can undo it exactly.
	 *
	 * @param array<int, float> $vector Vector.
	 * @return string Binary.
	 */
	public static function pack( array $vector ): string {
		$unit = self::normalise( $vector );
		$max  = 0.0;
		foreach ( $unit as $value ) {
			$max = max( $max, abs( (float) $value ) );
		}
		if ( $max <= 0.0 ) {
			return '';
		}
		$bytes = array();
		foreach ( $unit as $value ) {
			$bytes[] = max( -127, min( 127, (int) round( $value * 127 / $max ) ) );
		}
		return pack( 'g', $max / 127 ) . pack( 'c*', ...$bytes );
	}

	/**
	 * Unpack: the scale and the bytes.
	 *
	 * @param string $binary Packed vector.
	 * @return array{0: float, 1: array<int, int>}
	 */
	public static function unpack( string $binary ): array {
		if ( strlen( $binary ) < 5 ) {
			return array( 0.0, array() );
		}
		$scale  = unpack( 'g', substr( $binary, 0, 4 ) );
		$values = unpack( 'c*', substr( $binary, 4 ) );
		return array( is_array( $scale ) ? (float) $scale[1] : 0.0, is_array( $values ) ? array_values( $values ) : array() );
	}

	/**
	 * Cosine similarity of a float query (already normalised) and a packed vector, in [-1, 1].
	 *
	 * @param array<int, float> $query  Normalised query vector.
	 * @param string            $packed Packed vector of the same length.
	 * @return float
	 */
	public static function cosine( array $query, string $packed ): float {
		list( $scale, $bytes ) = self::unpack( $packed );
		$n                     = min( count( $query ), count( $bytes ) );
		if ( 0 === $n ) {
			return 0.0;
		}
		$dot = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$dot += $query[ $i ] * $bytes[ $i ];
		}
		return $dot * $scale;
	}

	/**
	 * The mean of several vectors, normalised (an item's vector from its chunks).
	 *
	 * @param array<int, array<int, float>> $vectors Vectors of equal length.
	 * @return array<int, float>
	 */
	public static function mean( array $vectors ): array {
		$vectors = array_values( array_filter( $vectors ) );
		if ( ! $vectors ) {
			return array();
		}
		$sum = array_fill( 0, count( $vectors[0] ), 0.0 );
		foreach ( $vectors as $vector ) {
			foreach ( self::normalise( $vector ) as $i => $value ) {
				if ( isset( $sum[ $i ] ) ) {
					$sum[ $i ] += $value;
				}
			}
		}
		return self::normalise( $sum );
	}

	/**
	 * Cosine of two packed vectors.
	 *
	 * @param string $a Packed.
	 * @param string $b Packed.
	 * @return float
	 */
	public static function cosine_packed( string $a, string $b ): float {
		list( $scale, $bytes ) = self::unpack( $a );
		$query                 = array_map(
			static function ( int $v ) use ( $scale ): float {
				return $v * $scale;
			},
			$bytes
		);
		return self::cosine( self::normalise( $query ), $b );
	}
}
