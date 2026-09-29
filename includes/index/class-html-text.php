<?php
/**
 * Rendered HTML to the text a visitor actually reads.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode's own property names.

/**
 * Converts HTML to readable text: headings and paragraphs on their own lines, list items as
 * "- item", table rows as "cell | cell", image alt text kept, and everything a visitor does not read
 * as content (scripts, styles, navigation, headers, footers, forms, hidden elements) dropped.
 *
 * Page builders wrap text in many nested containers; walking the DOM rather than strip_tags()
 * is what keeps "Price" and "$20" on the same line instead of four lines apart.
 */
final class Html_Text {

	private const DROP  = array( 'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'object', 'embed', 'nav', 'form', 'button', 'select', 'input', 'textarea', 'head' );
	private const BLOCK = array( 'p', 'div', 'section', 'article', 'main', 'aside', 'header', 'footer', 'blockquote', 'pre', 'figure', 'figcaption', 'address', 'details', 'summary', 'dl', 'dt', 'dd', 'ul', 'ol', 'table', 'tbody', 'thead', 'tfoot', 'hr' );

	/**
	 * Text of an HTML fragment.
	 *
	 * @param string $html      HTML.
	 * @param bool   $page_only For a whole fetched page: keep only the main content area.
	 * @return string
	 */
	public static function convert( string $html, bool $page_only = false ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}
		if ( ! class_exists( '\DOMDocument' ) ) {
			return self::fallback( $html );
		}
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $root ) {
			return self::fallback( $html );
		}
		if ( $page_only ) {
			$main = self::main_area( $doc );
			if ( $main ) {
				$root = $main;
			} else {
				foreach ( array( 'header', 'footer', 'aside' ) as $tag ) {
					self::remove_all( $doc, $tag );
				}
			}
		}
		$out = self::walk( $root );
		$out = html_entity_decode( $out, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$out = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $out );
		$out = preg_replace( "/ *\n */", "\n", (string) $out );
		$out = preg_replace( "/\n{3,}/", "\n\n", (string) $out );
		return trim( (string) $out );
	}

	/**
	 * The main content element of a full page, if the theme marks one.
	 *
	 * @param \DOMDocument $doc Document.
	 * @return \DOMElement|null
	 */
	private static function main_area( \DOMDocument $doc ): ?\DOMElement {
		$xpath = new \DOMXPath( $doc );
		foreach ( array( '//main', '//*[@role="main"]', '//article', '//*[@id="content"]', '//*[contains(concat(" ",normalize-space(@class)," ")," entry-content ")]' ) as $query ) {
			$nodes = $xpath->query( $query );
			if ( $nodes && $nodes->length > 0 && $nodes->item( 0 ) instanceof \DOMElement ) {
				return $nodes->item( 0 );
			}
		}
		return null;
	}

	/**
	 * Remove every element with a tag.
	 *
	 * @param \DOMDocument $doc Document.
	 * @param string       $tag Tag.
	 * @return void
	 */
	private static function remove_all( \DOMDocument $doc, string $tag ): void {
		$list = $doc->getElementsByTagName( $tag );
		for ( $i = $list->length - 1; $i >= 0; $i-- ) {
			$node = $list->item( $i );
			if ( $node && $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	/**
	 * Depth-first text of a node.
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	private static function walk( \DOMNode $node ): string {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$out .= preg_replace( '/\s+/u', ' ', (string) $child->nodeValue );
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, self::DROP, true ) || self::hidden( $child ) ) {
				continue;
			}
			switch ( $tag ) {
				case 'br':
					$out .= "\n";
					break;
				case 'img':
					$alt  = trim( (string) $child->getAttribute( 'alt' ) );
					$out .= '' !== $alt ? ' ' . $alt . ' ' : '';
					break;
				case 'h1':
				case 'h2':
				case 'h3':
				case 'h4':
				case 'h5':
				case 'h6':
					$out .= "\n\n" . trim( self::walk( $child ) ) . "\n";
					break;
				case 'li':
					$out .= "\n- " . trim( self::walk( $child ) );
					break;
				case 'tr':
					$cells = array();
					foreach ( $child->childNodes as $cell ) {
						if ( $cell instanceof \DOMElement && in_array( strtolower( $cell->tagName ), array( 'td', 'th' ), true ) ) {
							$cells[] = trim( preg_replace( '/\s+/u', ' ', self::walk( $cell ) ) );
						}
					}
					$out .= "\n" . implode( ' | ', $cells );
					break;
				case 'a':
					$out .= self::walk( $child );
					break;
				default:
					$inner = self::walk( $child );
					$out  .= in_array( $tag, self::BLOCK, true ) || in_array( $tag, array( 'li', 'td', 'th' ), true ) ? "\n\n" . $inner . "\n\n" : $inner;
			}
		}
		return $out;
	}

	/**
	 * Is an element hidden from visitors (and so not content)?
	 *
	 * @param \DOMElement $el Element.
	 * @return bool
	 */
	private static function hidden( \DOMElement $el ): bool {
		if ( $el->hasAttribute( 'hidden' ) || 'true' === $el->getAttribute( 'aria-hidden' ) ) {
			return true;
		}
		$style = strtolower( str_replace( ' ', '', (string) $el->getAttribute( 'style' ) ) );
		if ( false !== strpos( $style, 'display:none' ) || false !== strpos( $style, 'visibility:hidden' ) ) {
			return true;
		}
		$class = ' ' . strtolower( (string) $el->getAttribute( 'class' ) ) . ' ';
		foreach ( array( ' screen-reader-text ', ' sr-only ', ' visually-hidden ', ' skip-link ' ) as $marker ) {
			if ( false !== strpos( $class, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Without DOM: strip tags, keep block boundaries as blank lines.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function fallback( string $html ): string {
		$html = preg_replace( '#<(script|style|noscript|svg|nav|form)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#</?(p|div|h[1-6]|li|tr|section|article|br)\b[^>]*>#i', "\n\n", (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", (string) preg_replace( '/[ \t]+/', ' ', $text ) ) );
	}
}
