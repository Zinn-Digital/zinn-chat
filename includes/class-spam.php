<?php
/**
 * Spam and abuse protection for everything a visitor can send.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Honeypot, rate limits and the owner's block list (and a Pro human check, when one is added).
 *
 * ⛔ Every visitor message may cost the site owner an AI call on their own key, so the limits are
 * a spending control as much as an abuse one. They are counted per visitor address (hashed) in
 * transients, so they work with or without a persistent object cache.
 */
final class Spam {

	/**
	 * Is a hidden form field filled in? (Humans never see it; bots fill everything.)
	 *
	 * @param array<string, mixed> $params Request parameters.
	 * @return bool
	 */
	public static function honeypot_tripped( array $params ): bool {
		return isset( $params['website'] ) && '' !== trim( (string) $params['website'] );
	}

	/**
	 * Count one action and say whether it is over its limit.
	 *
	 * @param string $action `message`, `chat` or `ticket`.
	 * @param string $who    Who is acting (an address; hashed here).
	 * @return bool True when the limit is reached (the action must be refused).
	 */
	public static function over_limit( string $action, string $who ): bool {
		$limits = array(
			'message' => array( (int) Settings::get( 'rate_messages', 30 ), HOUR_IN_SECONDS ),
			'chat'    => array( (int) Settings::get( 'rate_chats', 10 ), DAY_IN_SECONDS ),
			'ticket'  => array( (int) Settings::get( 'rate_tickets', 5 ), DAY_IN_SECONDS ),
		);
		if ( ! isset( $limits[ $action ] ) || '' === $who ) {
			return false;
		}
		list( $max, $window ) = $limits[ $action ];
		$bucket               = (int) floor( time() / $window );
		$key                  = 'zinn_chat_rl_' . $action . '_' . substr( hash_hmac( 'sha256', $who, wp_salt( 'nonce' ) ), 0, 24 ) . '_' . $bucket;
		$count                = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}

	/**
	 * Is this address, email or text on the owner's block list?
	 *
	 * One entry per line: an IP address, an email address, `@domain.com`, or a word/phrase.
	 *
	 * @param string $ip    Address.
	 * @param string $email Email (may be empty).
	 * @param string $text  Text sent (may be empty).
	 * @return bool
	 */
	public static function blocked( string $ip, string $email, string $text ): bool {
		$list = preg_split( '/\R/', (string) Settings::get( 'blocked', '' ) );
		foreach ( (array) $list as $entry ) {
			$entry = strtolower( trim( (string) $entry ) );
			if ( '' === $entry ) {
				continue;
			}
			if ( filter_var( $entry, FILTER_VALIDATE_IP ) ) {
				if ( $entry === $ip ) {
					return true;
				}
				continue;
			}
			if ( '' !== $email && ( strtolower( $email ) === $entry || ( '@' === substr( $entry, 0, 1 ) && str_ends_with( strtolower( $email ), $entry ) ) ) ) {
				return true;
			}
			if ( '' !== $text && strlen( $entry ) >= 3 && false !== strpos( strtolower( $text ), $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The human check a Pro add-on puts in front of new chats and ticket forms, if any.
	 *
	 * The free edition has none: it relies on the honeypot, per-visitor limits and the block list
	 * above. Zinn Chat Pro adds Cloudflare Turnstile through these filters (a third-party script,
	 * which the WordPress.org build does not load — decided 2026-09-29, docs/655).
	 *
	 * @return array{src?: string, key?: string, global?: string} Empty when there is no check.
	 */
	public static function challenge(): array {
		/**
		 * Filters the human check shown to visitors (script URL, site key, and the global object
		 * the script defines with a `render( element, { sitekey, callback } )` method).
		 *
		 * @param array<string, string> $challenge Empty: no check.
		 */
		$challenge = (array) apply_filters( 'zinn_chat_challenge', array() );
		return ( ! empty( $challenge['src'] ) && ! empty( $challenge['key'] ) ) ? $challenge : array();
	}

	/**
	 * Did the visitor pass the human check? True when there is none.
	 *
	 * @param array<string, mixed> $params What the visitor sent (form fields or request parameters).
	 * @param string               $ip     Visitor address.
	 * @param string               $where  `chat`, `ticket_api` or `ticket_form`.
	 * @return bool
	 */
	public static function challenge_ok( array $params, string $ip, string $where ): bool {
		if ( ! self::challenge() ) {
			return true;
		}
		/**
		 * Filters whether the visitor passed the human check. A check that cannot be verified must
		 * return false (fail closed): a form that lets everything through whenever the service is
		 * unreachable is a form a bot can make succeed by timing.
		 *
		 * @param bool                 $ok     False until a check says otherwise.
		 * @param array<string, mixed> $params What the visitor sent.
		 * @param string               $ip     Visitor address.
		 * @param string               $where  Where the check was shown.
		 */
		return (bool) apply_filters( 'zinn_chat_challenge_ok', false, $params, $ip, $where );
	}
}
