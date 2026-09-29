<?php
/**
 * Split a page's text into passages the AI can quote.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Passages of roughly TARGET characters, cut at paragraph, then sentence, then word boundaries,
 * with a short overlap so a fact that straddles a cut is still whole in one passage.
 *
 * ⭐ Each passage is stored WITHOUT the page title and embedded WITH it: "Returns: 30 days" means
 * nothing on its own, but "Shipping and returns — Returns: 30 days" is what a visitor's question
 * matches. The title costs a few tokens per passage and is the biggest single retrieval win.
 */
final class Chunker {

	public const TARGET  = 900;
	public const MAX     = 1400;
	public const OVERLAP = 150;

	/** A page longer than this is cut: a 300 KB "page" is almost always a data dump, not help text. */
	public const MAX_CHUNKS = 120;

	/**
	 * Chunks of a text.
	 *
	 * @param string $text Plain text, paragraphs separated by blank lines.
	 * @return array<int, string>
	 */
	public static function split( string $text ): array {
		$text = trim( preg_replace( "/\n{3,}/", "\n\n", str_replace( "\r", '', $text ) ) );
		if ( '' === $text ) {
			return array();
		}
		$pieces = array();
		foreach ( preg_split( "/\n\n+/", $text ) as $paragraph ) {
			$paragraph = trim( (string) $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}
			if ( mb_strlen( $paragraph ) <= self::MAX ) {
				$pieces[] = $paragraph;
				continue;
			}
			foreach ( self::sentences( $paragraph ) as $sentence ) {
				$pieces[] = $sentence;
			}
		}
		$chunks  = array();
		$current = '';
		foreach ( $pieces as $piece ) {
			if ( '' === $current ) {
				$current = $piece;
			} elseif ( mb_strlen( $current ) + 2 + mb_strlen( $piece ) <= self::TARGET ) {
				$current .= "\n\n" . $piece;
			} else {
				$chunks[] = $current;
				$tail     = self::tail( $current );
				$current  = ( '' !== $tail ? $tail . ' … ' : '' ) . $piece;
			}
			if ( count( $chunks ) >= self::MAX_CHUNKS ) {
				break;
			}
		}
		if ( '' !== $current && count( $chunks ) < self::MAX_CHUNKS ) {
			$chunks[] = $current;
		}
		return $chunks;
	}

	/**
	 * Sentences of an over-long paragraph, each at most MAX (hard-cut at a word as a last resort).
	 *
	 * @param string $paragraph Paragraph.
	 * @return array<int, string>
	 */
	private static function sentences( string $paragraph ): array {
		$parts = preg_split( '/(?<=[.!?。！？])\s+/u', $paragraph );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			$part = (string) $part;
			while ( mb_strlen( $part ) > self::MAX ) {
				$cut   = mb_substr( $part, 0, self::MAX );
				$space = mb_strrpos( $cut, ' ' );
				$len   = ( false !== $space && $space > self::MAX / 2 ) ? $space : self::MAX;
				$out[] = trim( mb_substr( $part, 0, $len ) );
				$part  = trim( mb_substr( $part, $len ) );
			}
			if ( '' !== trim( $part ) ) {
				$out[] = trim( $part );
			}
		}
		return $out;
	}

	/**
	 * The last ~OVERLAP characters of a chunk, starting at a word.
	 *
	 * @param string $chunk Chunk.
	 * @return string
	 */
	private static function tail( string $chunk ): string {
		if ( mb_strlen( $chunk ) <= self::OVERLAP ) {
			return '';
		}
		$tail  = mb_substr( $chunk, -self::OVERLAP );
		$space = mb_strpos( $tail, ' ' );
		return false === $space ? '' : trim( mb_substr( $tail, $space ) );
	}
}
