<?php
/**
 * Every email the help desk sends.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emails, through wp_mail() so whatever SMTP plugin the site uses delivers them.
 *
 * To the customer: ticket received (with their private link), a reply from the team, and a copy of
 * a chat when they ask for one. To the team (`notify_email`, default the site admin email): a new
 * ticket, a customer reply, a visitor waiting for a person, and a message left while offline.
 *
 * Every email goes through the `zinn_chat_email` filter, which is where Pro adds a reply-to address
 * for answering tickets by email.
 */
final class Mailer {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'zinn_chat_ticket_created', array( self::class, 'ticket_created' ), 10, 2 );
		add_action( 'zinn_chat_ticket_reply', array( self::class, 'ticket_reply' ), 10, 3 );
	}

	/**
	 * A ticket was opened: confirm to the customer, alert the team.
	 *
	 * @param int    $id    Ticket id.
	 * @param string $token Guest link token.
	 * @return void
	 */
	public static function ticket_created( int $id, string $token ): void {
		unset( $token );
		$ticket = Tickets::get( $id );
		if ( ! $ticket ) {
			return;
		}
		$replies = Tickets::replies( $id );
		$first   = (string) ( $replies[0]['body'] ?? '' );
		$link    = self::customer_link( $ticket );
		/* translators: 1: ticket number, 2: ticket subject. */
		$subject = sprintf( __( '[#%1$d] We have your request: %2$s', 'zinn-chat' ), $id, $ticket['subject'] );
		$body    = '<p>' . esc_html__( 'Thank you for contacting us. We have received your request and will reply as soon as we can.', 'zinn-chat' ) . '</p>'
			. self::quote( $first );
		self::send( 'ticket_received', (string) $ticket['email'], $subject, $body, $link, __( 'View your request', 'zinn-chat' ), $ticket );

		/* translators: 1: ticket number, 2: ticket subject. */
		$subject = sprintf( __( '[#%1$d] New ticket: %2$s', 'zinn-chat' ), $id, $ticket['subject'] );
		$body    = '<p>' . esc_html( self::who( $ticket ) ) . '</p>' . self::quote( $first );
		self::send( 'team_ticket', self::team(), $subject, $body, self::admin_link( $id ), __( 'Open the ticket', 'zinn-chat' ), $ticket );
	}

	/**
	 * A reply was added: email the other side.
	 *
	 * @param int    $reply_id  Reply id.
	 * @param int    $ticket_id Ticket id.
	 * @param string $author    customer|agent|note.
	 * @return void
	 */
	public static function ticket_reply( int $reply_id, int $ticket_id, string $author ): void {
		unset( $reply_id );
		$ticket = Tickets::get( $ticket_id );
		if ( ! $ticket || 'note' === $author ) {
			return;
		}
		$replies = Tickets::replies( $ticket_id );
		$last    = end( $replies );
		$text    = is_array( $last ) ? (string) $last['body'] : '';
		if ( 'agent' === $author ) {
			/* translators: 1: ticket number, 2: ticket subject. */
			$subject = sprintf( __( '[#%1$d] New reply: %2$s', 'zinn-chat' ), $ticket_id, $ticket['subject'] );
			$body    = self::quote( $text ) . '<p>' . esc_html__( 'You can reply on the page below.', 'zinn-chat' ) . '</p>';
			self::send( 'ticket_reply', (string) $ticket['email'], $subject, $body, self::customer_link( $ticket ), __( 'View and reply', 'zinn-chat' ), $ticket );
			return;
		}
		/* translators: 1: ticket number, 2: ticket subject. */
		$subject = sprintf( __( '[#%1$d] Customer replied: %2$s', 'zinn-chat' ), $ticket_id, $ticket['subject'] );
		$body    = '<p>' . esc_html( self::who( $ticket ) ) . '</p>' . self::quote( $text );
		self::send( 'team_reply', self::team( (int) $ticket['assignee_id'] ), $subject, $body, self::admin_link( $ticket_id ), __( 'Open the ticket', 'zinn-chat' ), $ticket );
	}

	/**
	 * A visitor asked for a person and nobody picked the chat up.
	 *
	 * @param array<string, mixed> $conversation Conversation.
	 * @param string               $last         The visitor's last message.
	 * @return void
	 */
	public static function waiting( array $conversation, string $last ): void {
		/* translators: %d: chat number. */
		$subject = sprintf( __( 'A visitor is waiting in chat #%d', 'zinn-chat' ), (int) $conversation['id'] );
		$body    = '<p>' . esc_html__( 'A visitor asked to talk to a person and is waiting for an answer.', 'zinn-chat' ) . '</p>' . self::quote( $last );
		self::send( 'team_waiting', self::team(), $subject, $body, admin_url( 'admin.php?page=zinn-chat&chat=' . (int) $conversation['id'] ), __( 'Answer now', 'zinn-chat' ), $conversation );
	}

	/**
	 * Send a copy of a chat to the visitor.
	 *
	 * @param array<string, mixed> $conversation Conversation.
	 * @param string               $email        Where.
	 * @return bool
	 */
	public static function transcript( array $conversation, string $email ): bool {
		$lines = '';
		foreach ( Conversations::messages( (int) $conversation['id'] ) as $row ) {
			if ( 'system' === $row['role'] ) {
				continue;
			}
			$who    = 'visitor' === $row['role'] ? __( 'You', 'zinn-chat' ) : ( 'ai' === $row['role'] ? Settings::text( 'assistant_name' ) : Settings::text( 'from_name' ) );
			$lines .= '<p><strong>' . esc_html( $who ) . ':</strong><br>' . Util::render_text( (string) $row['body'] ) . '</p>';
		}
		/* translators: %s: site name. */
		$subject = sprintf( __( 'Your chat with %s', 'zinn-chat' ), Settings::text( 'from_name' ) );
		return self::send( 'transcript', $email, $subject, $lines, '', '', $conversation );
	}

	/**
	 * Email a fresh private link to a ticket's owner.
	 *
	 * @param array<string, mixed> $ticket Ticket (with the new token_at).
	 * @return bool
	 */
	public static function fresh_link( array $ticket ): bool {
		/* translators: %d: ticket number. */
		$subject = sprintf( __( '[#%d] Your link to your request', 'zinn-chat' ), (int) $ticket['id'] );
		$body    = '<p>' . esc_html__( 'Here is a new link to your request. The old one no longer works.', 'zinn-chat' ) . '</p>';
		return self::send( 'fresh_link', (string) $ticket['email'], $subject, $body, self::customer_link( $ticket ), __( 'View your request', 'zinn-chat' ), $ticket );
	}

	/**
	 * Where the customer reads their ticket: the account area when they have an account, else the
	 * private link.
	 *
	 * @param array<string, mixed> $ticket Ticket.
	 * @return string
	 */
	public static function customer_link( array $ticket ): string {
		$guest = Tickets::guest_url( $ticket );
		/**
		 * Filters the link a customer is sent for their ticket.
		 *
		 * @param string               $url    Private link (support page + token).
		 * @param array<string, mixed> $ticket Ticket.
		 */
		return (string) apply_filters( 'zinn_chat_customer_ticket_url', $guest, $ticket );
	}

	/**
	 * The ticket in wp-admin.
	 *
	 * @param int $id Ticket id.
	 * @return string
	 */
	public static function admin_link( int $id ): string {
		return admin_url( 'admin.php?page=zinn-chat-tickets&ticket=' . $id );
	}

	/**
	 * Team recipients.
	 *
	 * @param int $assignee Prefer this agent's own email as well.
	 * @return string
	 */
	private static function team( int $assignee = 0 ): string {
		$to = Settings::text( 'notify_email' );
		if ( $assignee ) {
			$user = get_userdata( $assignee );
			if ( $user && false === stripos( $to, (string) $user->user_email ) ) {
				$to .= ', ' . $user->user_email;
			}
		}
		return $to;
	}

	/**
	 * "Name <email>" of a ticket/conversation's customer.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return string
	 */
	private static function who( array $row ): string {
		$name  = trim( (string) ( $row['name'] ?? '' ) );
		$email = trim( (string) ( $row['email'] ?? '' ) );
		$from  = trim( $name . ( '' !== $email ? ' <' . $email . '>' : '' ) );
		/* translators: %s: customer name and email. */
		return sprintf( __( 'From: %s', 'zinn-chat' ), '' !== $from ? $from : __( 'a visitor', 'zinn-chat' ) );
	}

	/**
	 * A quoted message.
	 *
	 * @param string $text Text.
	 * @return string HTML.
	 */
	private static function quote( string $text ): string {
		return '' === $text ? '' : '<blockquote style="margin:16px 0;padding:12px 16px;border-inline-start:4px solid #dfe3e8;background:#f6f7f9">' . Util::render_text( $text ) . '</blockquote>';
	}

	/**
	 * Build and send one email.
	 *
	 * @param string               $type    Kind (for the filter).
	 * @param string               $to      Recipient(s).
	 * @param string               $subject Subject.
	 * @param string               $content HTML content.
	 * @param string               $link    Button URL ('' for none).
	 * @param string               $label   Button text.
	 * @param array<string, mixed> $context Ticket or conversation row.
	 * @return bool
	 */
	private static function send( string $type, string $to, string $subject, string $content, string $link, string $label, array $context ): bool {
		if ( '' === trim( $to ) ) {
			return false;
		}
		$site   = Settings::text( 'from_name' );
		$button = '' === $link ? '' : '<p style="margin:24px 0"><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:10px 18px;border-radius:6px;background:' . esc_attr( (string) Settings::get( 'colour', '#1f6feb' ) ) . ';color:' . esc_attr( (string) Settings::get( 'text_colour', '#ffffff' ) ) . ';text-decoration:none">' . esc_html( $label ) . '</a></p>';
		$dir    = is_rtl() ? 'rtl' : 'ltr';
		$html   = '<!doctype html><html dir="' . $dir . '"><body style="margin:0;padding:24px;background:#f3f4f6;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1f2328">'
			. '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;padding:24px">'
			. '<p style="font-size:13px;color:#57606a;margin:0 0 16px">' . esc_html( $site ) . '</p>'
			. $content . $button
			. '</div></body></html>';
		$email  = array(
			'type'    => $type,
			'to'      => $to,
			'subject' => $subject,
			'html'    => $html,
			'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
			'context' => $context,
		);
		/**
		 * Filters an email before it is sent. Return an empty `to` to stop it.
		 *
		 * @param array<string, mixed> $email type, to, subject, html, headers, context.
		 */
		$email = (array) apply_filters( 'zinn_chat_email', $email );
		if ( '' === trim( (string) ( $email['to'] ?? '' ) ) ) {
			return false;
		}
		return (bool) wp_mail( (string) $email['to'], (string) $email['subject'], (string) $email['html'], (array) $email['headers'] );
	}
}
