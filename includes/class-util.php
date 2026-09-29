<?php
/**
 * Small shared helpers.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Time, tokens and the visitor's address.
 */
final class Util {

	/**
	 * Now, as a UTC MySQL datetime.
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	public static function now( int $offset = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}

	/**
	 * A UTC MySQL datetime as an ISO 8601 string for JSON (empty for null).
	 *
	 * @param string|null $datetime UTC datetime.
	 * @return string
	 */
	public static function iso( ?string $datetime ): string {
		if ( null === $datetime || '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}
		return gmdate( 'c', (int) strtotime( $datetime . ' UTC' ) );
	}

	/**
	 * A new random token (URL-safe) — for a visitor's chat and a guest ticket link.
	 *
	 * @return string
	 */
	public static function token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a URL-safe random token, not obfuscation.
	}

	/**
	 * What is stored for a token: its keyed hash, never the token itself, so a database leak does
	 * not hand anybody a working chat or ticket link.
	 *
	 * @param string $token Token.
	 * @return string 64 hex characters.
	 */
	public static function token_hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) . 'zinn-chat' );
	}

	/**
	 * The visitor's IP address, as the web server saw it.
	 *
	 * ⛔ REMOTE_ADDR only. Proxy headers are spoofable by the visitor, and this value feeds the
	 * rate limits; a site behind a proxy can map it with the `zinn_chat_client_ip` filter.
	 *
	 * @return string
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP address used for rate limits and records.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'zinn_chat_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * What is stored for an address: the address itself only when the owner chose to keep it,
	 * otherwise a keyed hash (still good for rate limits and blocking, useless to anybody else).
	 *
	 * @param string $ip Address.
	 * @return string
	 */
	public static function stored_ip( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}
		return Settings::get( 'keep_ip' ) ? $ip : 'h:' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 40 );
	}

	/**
	 * Plain text from a message someone typed: no HTML, normalised line breaks, bounded length.
	 *
	 * @param string $text Raw text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	public static function clean_text( string $text, int $max ): string {
		$text = wp_check_invalid_utf8( $text );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = sanitize_textarea_field( $text );
		return trim( mb_substr( $text, 0, $max ) );
	}

	/**
	 * A short language tag from a browser locale string ("en-GB,en;q=0.9" -> "en-GB").
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function language_tag( string $value ): string {
		$first = trim( (string) strtok( $value, ',;' ) );
		return preg_match( '/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$/', $first ) ? $first : '';
	}

	/**
	 * Render a stored message (plain text with [title](url) links) as safe HTML.
	 *
	 * Only http(s) links survive; everything else is escaped text.
	 *
	 * @param string $text Stored text.
	 * @return string HTML.
	 */
	public static function render_text( string $text ): string {
		$out    = '';
		$offset = 0;
		if ( preg_match_all( '/\[([^\]\n]{1,300})\]\((https?:\/\/[^\s)]{1,700})\)/', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $index => $match ) {
				$out   .= esc_html( substr( $text, $offset, $match[1] - $offset ) );
				$out   .= '<a href="' . esc_url( $matches[2][ $index ][0] ) . '" target="_blank" rel="noopener">' . esc_html( $matches[1][ $index ][0] ) . '</a>';
				$offset = $match[1] + strlen( $match[0] );
			}
		}
		$out .= esc_html( substr( $text, $offset ) );
		return nl2br( $out );
	}
}
