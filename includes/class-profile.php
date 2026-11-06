<?php
/**
 * The customer profile beside a chat in the agent console (2.13.0): who this visitor is to the
 * site — their earlier chats, their tickets and their WooCommerce orders — and the two things an
 * agent most often does next (e-mail them, open a ticket for them).
 *
 * The person is matched on the signed-in WordPress user OR the e-mail address, never on a name or
 * an IP address: those are not the same person.
 *
 * Neil Lock — CEO, Zinn Digital® Ltd — GPL-2.0-or-later.
 *
 * @package ZinnDigital\ZinnChat
 */

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer profile.
 */
final class Profile {

	/** How many earlier chats and tickets are listed. */
	public const LIMIT = 10;

	/**
	 * The profile of a conversation's visitor.
	 *
	 * @param array<string, mixed> $row Conversation.
	 * @return array<string, mixed>
	 */
	public static function for_conversation( array $row ): array {
		$email   = (string) ( $row['email'] ?? '' );
		$user_id = (int) ( $row['user_id'] ?? 0 );
		if ( '' === $email && $user_id > 0 ) {
			$user  = get_userdata( $user_id );
			$email = $user ? (string) $user->user_email : '';
		}
		$chats   = Conversations::by_person( $email, $user_id, (int) $row['id'], self::LIMIT );
		$tickets = ( '' !== $email || $user_id > 0 )
			? Tickets::query(
				array(
					'status'   => 'all',
					'email'    => $email,
					'user_id'  => $user_id,
					'per_page' => self::LIMIT,
				)
			)
			: array(
				'rows'  => array(),
				'total' => 0,
			);
		return self::shape( $email, $user_id, $chats, $tickets );
	}

	/**
	 * The payload, from what was found.
	 *
	 * @param string                                                    $email   E-mail address.
	 * @param int                                                       $user_id User.
	 * @param array{rows: array<int, array<string, mixed>>, total: int} $chats   Earlier chats.
	 * @param array{rows: array<int, array<string, mixed>>, total: int} $tickets Tickets.
	 * @return array<string, mixed>
	 */
	public static function shape( string $email, int $user_id, array $chats, array $tickets ): array {
		$chat_list = array();
		foreach ( $chats['rows'] as $chat ) {
			$chat_list[] = array(
				'id'      => (int) $chat['id'],
				'status'  => (string) $chat['status'],
				'subject' => (string) ( $chat['subject'] ?? '' ),
				'at'      => Util::iso( (string) $chat['updated_at'] ),
			);
		}
		$ticket_list = array();
		foreach ( $tickets['rows'] as $ticket ) {
			$ticket_list[] = array(
				'id'           => (int) $ticket['id'],
				'subject'      => (string) $ticket['subject'],
				'status'       => (string) $ticket['status'],
				'status_label' => Tickets::status_label( (string) $ticket['status'] ),
				'at'           => Util::iso( (string) $ticket['updated_at'] ),
			);
		}
		$valid = '' !== $email && is_email( $email );
		return array(
			'email'         => $valid ? $email : '',
			'user_id'       => $user_id,
			'chats'         => $chat_list,
			'chats_total'   => (int) $chats['total'],
			'tickets'       => $ticket_list,
			'tickets_total' => (int) $tickets['total'],
			// The quick actions: what the console offers depends on what is known.
			'can_email'     => $valid,
			'can_ticket'    => $valid,
		);
	}
}
