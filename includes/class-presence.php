<?php
/**
 * Is anybody there to answer?
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent presence, from the console's own heartbeat.
 *
 * An agent is ONLINE while their inbox is open (the console polls every few seconds) and they have
 * not switched themselves to "away". Nothing is guessed from a login: an admin who is signed in
 * but not looking at chats must not make a visitor wait for nobody.
 *
 * Pro's business hours plug in through `zinn_chat_team_online`.
 */
final class Presence {

	private const OPTION = 'zinn_chat_presence';

	/** Seconds after the last heartbeat that an agent still counts as online. */
	public const WINDOW = 90;

	/**
	 * Record a heartbeat from an agent's console.
	 *
	 * @param int  $user_id Agent.
	 * @param bool $away    The agent set themselves away.
	 * @return void
	 */
	public static function beat( int $user_id, bool $away = false ): void {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		foreach ( $all as $id => $row ) {
			if ( ! is_array( $row ) || (int) ( $row['at'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
				unset( $all[ $id ] );
			}
		}
		$all[ $user_id ] = array(
			'at'   => time(),
			'away' => $away,
		);
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Agents online now.
	 *
	 * @return array<int, int> User ids.
	 */
	public static function online(): array {
		$all = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $id => $row ) {
			if ( is_array( $row ) && empty( $row['away'] ) && (int) ( $row['at'] ?? 0 ) >= time() - self::WINDOW ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Can a visitor reach a person right now?
	 *
	 * @return bool
	 */
	public static function team_online(): bool {
		$online = (bool) Settings::get( 'human_enabled', true ) && array() !== self::online();
		/**
		 * Filters whether a person can answer now (Pro adds business hours here).
		 *
		 * @param bool $online From the agents' consoles.
		 */
		return (bool) apply_filters( 'zinn_chat_team_online', $online );
	}

	/**
	 * Is this agent set to away?
	 *
	 * @param int $user_id Agent.
	 * @return bool
	 */
	public static function is_away( int $user_id ): bool {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && ! empty( $all[ $user_id ]['away'] );
	}
}
