<?php
/**
 * Support tickets.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage and life cycle of tickets.
 *
 * Status: `open` (the team owes a reply), `pending` (waiting for the customer), `solved`, `closed`.
 * A customer reply re-opens anything but `closed`... and a closed ticket re-opens too, because a
 * customer writing back is the one signal that it was not finished.
 *
 * Guest tickets (no account) are reached by a private link carrying a random token. Only the
 * token's hash is stored. The link stops working after `guest_link_days`; the page then offers to
 * email a fresh one to the address on the ticket, so an old email found months later still gets
 * the customer back to their ticket.
 */
final class Tickets {

	public const STATUSES   = array( 'open', 'pending', 'solved', 'closed' );
	public const PRIORITIES = array( 'low', 'normal', 'high', 'urgent' );

	/**
	 * Open a ticket.
	 *
	 * @param array<string, mixed> $fields subject, body, name, email, user_id, order_id, conversation_id, channel, source_site, language, ip.
	 * @return array{id: int, token: string}|\WP_Error
	 */
	public static function create( array $fields ) {
		global $wpdb;
		$email   = sanitize_email( (string) ( $fields['email'] ?? '' ) );
		$user_id = (int) ( $fields['user_id'] ?? 0 );
		if ( ! $user_id && ! is_email( $email ) ) {
			return new \WP_Error( 'zinn_chat_email', __( 'Please enter a valid email address so we can reply.', 'zinn-chat' ) );
		}
		if ( $user_id && '' === $email ) {
			$user  = get_userdata( $user_id );
			$email = $user ? (string) $user->user_email : '';
		}
		$body = Util::clean_text( (string) ( $fields['body'] ?? '' ), (int) Settings::get( 'max_chars', 2000 ) * 5 );
		if ( '' === $body ) {
			return new \WP_Error( 'zinn_chat_body', __( 'Please describe what you need help with.', 'zinn-chat' ) );
		}
		$subject = mb_substr( sanitize_text_field( (string) ( $fields['subject'] ?? '' ) ), 0, 200 );
		if ( '' === $subject ) {
			$subject = mb_substr( wp_strip_all_tags( $body ), 0, 80 );
		}
		$now = Util::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
		$wpdb->insert(
			Schema::table( 'tickets' ),
			array(
				'status'           => 'open',
				'priority'         => in_array( $fields['priority'] ?? '', self::PRIORITIES, true ) ? $fields['priority'] : 'normal',
				'subject'          => $subject,
				'name'             => mb_substr( sanitize_text_field( (string) ( $fields['name'] ?? '' ) ), 0, 120 ),
				'email'            => $email,
				'user_id'          => $user_id,
				'order_id'         => (int) ( $fields['order_id'] ?? 0 ),
				'conversation_id'  => (int) ( $fields['conversation_id'] ?? 0 ),
				'channel'          => in_array( $fields['channel'] ?? '', array( 'form', 'chat', 'account', 'email', 'remote', 'admin' ), true ) ? $fields['channel'] : 'form',
				'source_site'      => mb_substr( sanitize_text_field( (string) ( $fields['source_site'] ?? '' ) ), 0, 190 ),
				'token_hash'       => '',
				'token_at'         => $now,
				'language'         => mb_substr( (string) ( $fields['language'] ?? '' ), 0, 20 ),
				'ip'               => Util::stored_ip( (string) ( $fields['ip'] ?? '' ) ),
				'unread'           => 1,
				'last_customer_at' => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new \WP_Error( 'zinn_chat_db', __( 'The ticket could not be saved. Please try again.', 'zinn-chat' ) );
		}
		$token = self::link_token( $id, $now );
		self::update( $id, array( 'token_hash' => Util::token_hash( $token ) ) );
		self::insert_reply( $id, 'customer', $body, $user_id, ( 'chat' === ( $fields['channel'] ?? '' ) ) ? 'chat' : 'web' );
		/**
		 * A ticket was opened. Mailer sends the confirmation and the team alert from here.
		 *
		 * @param int    $id    Ticket id.
		 * @param string $token The guest link token.
		 */
		do_action( 'zinn_chat_ticket_created', $id, $token );
		return array(
			'id'    => $id,
			'token' => $token,
		);
	}

	/**
	 * One ticket.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_tickets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The ticket a guest link token opens, and whether the link is still valid.
	 *
	 * @param string $token Token from the link.
	 * @return array{ticket: array<string, mixed>|null, expired: bool}
	 */
	public static function by_token( string $token ): array {
		global $wpdb;
		if ( strlen( $token ) < 20 ) {
			return array(
				'ticket'  => null,
				'expired' => false,
			);
		}
		$table = $wpdb->prefix . 'zinn_chat_tickets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', $table, Util::token_hash( $token ) ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array(
				'ticket'  => null,
				'expired' => false,
			);
		}
		// Valid for `guest_link_days` after the LAST activity, not after the link was made: every
		// email we send carries this same link, so a reply sent on day 40 must open on day 41.
		$last = 0;
		foreach ( array( 'token_at', 'last_agent_at', 'last_customer_at', 'created_at' ) as $column ) {
			$last = max( $last, (int) strtotime( (string) ( $row[ $column ] ?? '' ) . ' UTC' ) );
		}
		$expired = ( time() - $last ) > (int) Settings::get( 'guest_link_days', 30 ) * DAY_IN_SECONDS;
		return array(
			'ticket'  => $row,
			'expired' => $expired,
		);
	}

	/**
	 * Issue a fresh guest link token (the old one stops working).
	 *
	 * @param int $id Ticket id.
	 * @return string The new token.
	 */
	public static function refresh_token( int $id ): string {
		global $wpdb;
		$at    = Util::now();
		$token = self::link_token( $id, $at );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->update(
			Schema::table( 'tickets' ),
			array(
				'token_hash' => Util::token_hash( $token ),
				'token_at'   => $at,
			),
			array( 'id' => $id )
		);
		return $token;
	}

	/**
	 * The guest link token of a ticket. Derived, never stored: a keyed hash of the ticket id and
	 * the moment the link was issued, so every email can carry the same working link while only
	 * its hash sits in the database. Issuing a new one (refresh_token) changes `token_at`, which
	 * retires the old link.
	 *
	 * @param int    $id       Ticket id.
	 * @param string $token_at When the link was issued (UTC datetime).
	 * @return string
	 */
	public static function link_token( int $id, string $token_at ): string {
		$raw = hash_hmac( 'sha256', $id . '|' . $token_at, wp_salt( 'auth' ) . 'zinn-chat-ticket-link', true );
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a URL-safe token, not obfuscation.
	}

	/**
	 * The guest link URL of a ticket (the support page with the token), or '' without one.
	 *
	 * @param array<string, mixed> $ticket Ticket row.
	 * @return string
	 */
	public static function guest_url( array $ticket ): string {
		$page = Front_Tickets::page_url();
		if ( '' === $page ) {
			return '';
		}
		$token = self::link_token( (int) $ticket['id'], (string) $ticket['token_at'] );
		return add_query_arg( 'zc_ticket', rawurlencode( $token ), $page );
	}

	/**
	 * Insert a reply row only (no status change, no email).
	 *
	 * @param int    $ticket_id Ticket.
	 * @param string $author    customer|agent|system|note.
	 * @param string $body      Text.
	 * @param int    $user_id   Author user.
	 * @param string $via       web|email|chat.
	 * @return int Reply id.
	 */
	private static function insert_reply( int $ticket_id, string $author, string $body, int $user_id, string $via ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
		$wpdb->insert(
			Schema::table( 'replies' ),
			array(
				'ticket_id'  => $ticket_id,
				'author'     => $author,
				'user_id'    => $user_id,
				'body'       => $body,
				'via'        => $via,
				'created_at' => Util::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Add a reply and move the ticket on.
	 *
	 * @param int    $ticket_id Ticket.
	 * @param string $author    customer|agent|note.
	 * @param string $body      Text.
	 * @param int    $user_id   Author user (agents; a signed-in customer).
	 * @param string $via       web|email.
	 * @param string $status    For an agent reply: the status to set (default pending).
	 * @return int|\WP_Error Reply id.
	 */
	public static function reply( int $ticket_id, string $author, string $body, int $user_id = 0, string $via = 'web', string $status = '' ) {
		$ticket = self::get( $ticket_id );
		if ( ! $ticket ) {
			return new \WP_Error( 'zinn_chat_ticket', __( 'That ticket does not exist.', 'zinn-chat' ) );
		}
		$body = Util::clean_text( $body, (int) Settings::get( 'max_chars', 2000 ) * 5 );
		if ( '' === $body ) {
			return new \WP_Error( 'zinn_chat_body', __( 'The reply is empty.', 'zinn-chat' ) );
		}
		if ( ! in_array( $author, array( 'customer', 'agent', 'note' ), true ) ) {
			return new \WP_Error( 'zinn_chat_author', 'bad author' );
		}
		$id  = self::insert_reply( $ticket_id, $author, $body, $user_id, $via );
		$now = Util::now();
		if ( 'customer' === $author ) {
			self::update(
				$ticket_id,
				array(
					'status'           => 'open',
					'unread'           => 1,
					'last_customer_at' => $now,
				)
			);
		} elseif ( 'agent' === $author ) {
			$status = in_array( $status, self::STATUSES, true ) ? $status : 'pending';
			$fields = array(
				'status'        => $status,
				'unread'        => 0,
				'last_agent_at' => $now,
			);
			if ( ! (int) $ticket['assignee_id'] && $user_id ) {
				$fields['assignee_id'] = $user_id;
			}
			self::update( $ticket_id, $fields );
		} else {
			self::update( $ticket_id, array() );
		}
		/**
		 * A reply was added to a ticket. Mailer emails the other side from here.
		 *
		 * @param int    $id        Reply id.
		 * @param int    $ticket_id Ticket id.
		 * @param string $author    customer|agent|note.
		 */
		do_action( 'zinn_chat_ticket_reply', $id, $ticket_id, $author );
		return $id;
	}

	/**
	 * Update columns.
	 *
	 * @param int                  $id     Ticket.
	 * @param array<string, mixed> $fields Columns.
	 * @return void
	 */
	public static function update( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = Util::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->update( Schema::table( 'tickets' ), $fields, array( 'id' => $id ) );
	}

	/**
	 * Change status (agent action).
	 *
	 * @param int    $id     Ticket.
	 * @param string $status New status.
	 * @return void
	 */
	public static function set_status( int $id, string $status ): void {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		$before = self::get( $id );
		self::update( $id, array( 'status' => $status ) );
		/**
		 * A ticket changed status.
		 *
		 * @param int    $id     Ticket id.
		 * @param string $status New status.
		 * @param string $old    Previous status.
		 */
		do_action( 'zinn_chat_ticket_status', $id, $status, (string) ( $before['status'] ?? '' ) );
	}

	/**
	 * Replies of a ticket, oldest first.
	 *
	 * @param int  $ticket_id Ticket.
	 * @param bool $notes     Include internal notes.
	 * @return array<int, array<string, mixed>>
	 */
	public static function replies( int $ticket_id, bool $notes = false ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_replies';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_id = %d AND author <> %s ORDER BY id ASC', $table, $ticket_id, $notes ? '' : 'note' ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Search/list tickets for wp-admin.
	 *
	 * @param array<string, mixed> $args status (open|pending|solved|closed|active|all), search, user_id, email, page, per_page.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'zinn_chat_tickets';
		$status = (string) ( $args['status'] ?? 'active' );
		if ( 'active' !== $status && ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'all';
		}
		// ⛔ Each owner condition only when it is set: `user_id = 0` would match every guest ticket.
		$uid    = max( 0, (int) ( $args['user_id'] ?? 0 ) );
		$email  = (string) ( $args['email'] ?? '' );
		$search = (string) ( $args['search'] ?? '' );
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$per    = max( 1, min( 200, (int) ( $args['per_page'] ?? 25 ) ) );
		$offset = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per );
		// One fixed statement: each filter is switched on or off by its own parameters, so no SQL
		// is ever assembled from pieces.
		$sid = (int) $search;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = 'all' OR ( %s = 'active' AND status IN ('open','pending') ) OR status = %s ) AND ( ( %d = 0 AND %s = '' ) OR ( %d > 0 AND user_id = %d ) OR ( %s <> '' AND email = %s ) ) AND ( %s = '' OR subject LIKE %s OR email LIKE %s OR name LIKE %s OR id = %d )", $table, $status, $status, $status, $uid, $email, $uid, $uid, $email, $email, $search, $like, $like, $like, $sid ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = 'all' OR ( %s = 'active' AND status IN ('open','pending') ) OR status = %s ) AND ( ( %d = 0 AND %s = '' ) OR ( %d > 0 AND user_id = %d ) OR ( %s <> '' AND email = %s ) ) AND ( %s = '' OR subject LIKE %s OR email LIKE %s OR name LIKE %s OR id = %d ) ORDER BY (status = 'open') DESC, updated_at DESC LIMIT %d OFFSET %d", $table, $status, $status, $status, $uid, $email, $uid, $uid, $email, $email, $search, $like, $like, $like, $sid, $per, $offset ), ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}


	/**
	 * Counts per status, for the menu badge and the list filters.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_tickets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', $table ), ARRAY_A );
		$out  = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * May this visitor/customer see this ticket? (Owner by account, or holder of a valid link.)
	 *
	 * @param array<string, mixed> $ticket Ticket.
	 * @param int                  $user_id Signed-in user.
	 * @return bool
	 */
	public static function owned_by( array $ticket, int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		if ( (int) $ticket['user_id'] === $user_id ) {
			return true;
		}
		$user = get_userdata( $user_id );
		return $user && '' !== (string) $ticket['email'] && 0 === strcasecmp( (string) $user->user_email, (string) $ticket['email'] );
	}

	/**
	 * Ticket shape for JSON.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	public static function public_ticket( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'status'      => (string) $row['status'],
			'priority'    => (string) $row['priority'],
			'subject'     => (string) $row['subject'],
			'name'        => (string) $row['name'],
			'email'       => (string) $row['email'],
			'user_id'     => (int) $row['user_id'],
			'order_id'    => (int) $row['order_id'],
			'channel'     => (string) $row['channel'],
			'source_site' => (string) $row['source_site'],
			'assignee_id' => (int) $row['assignee_id'],
			'unread'      => (int) $row['unread'],
			'created_at'  => Util::iso( (string) $row['created_at'] ),
			'updated_at'  => Util::iso( (string) $row['updated_at'] ),
		);
	}

	/**
	 * Human label of a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'open':
				return __( 'Open', 'zinn-chat' );
			case 'pending':
				return __( 'Waiting for customer', 'zinn-chat' );
			case 'solved':
				return __( 'Solved', 'zinn-chat' );
			case 'closed':
				return __( 'Closed', 'zinn-chat' );
		}
		return $status;
	}
}
