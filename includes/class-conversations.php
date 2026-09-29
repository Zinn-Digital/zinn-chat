<?php
/**
 * Chat conversations and their messages.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage for live chat.
 *
 * A conversation moves through: `bot` (with the assistant) -> `waiting` (a person was asked for)
 * -> `staff` (an agent took it) -> `closed`. `offline` is a chat that ended as a message left for
 * the team because nobody was online; it becomes a ticket.
 *
 * Message roles: `visitor`, `ai`, `agent`, `system`, and `note` (an agent-only note, never shown to
 * the visitor).
 */
final class Conversations {

	public const STATUSES = array( 'bot', 'waiting', 'staff', 'offline', 'closed' );
	public const ROLES    = array( 'visitor', 'ai', 'agent', 'system', 'note' );

	/**
	 * Start a conversation.
	 *
	 * @param array<string, mixed> $fields Optional: name, email, user_id, page_url, language, ip, user_agent, source_site.
	 * @return array{id: int, token: string}
	 */
	public static function create( array $fields ): array {
		global $wpdb;
		$token = Util::token();
		$now   = Util::now();
		$row   = array(
			'token_hash'  => Util::token_hash( $token ),
			'status'      => 'bot',
			'name'        => mb_substr( sanitize_text_field( (string) ( $fields['name'] ?? '' ) ), 0, 120 ),
			'email'       => sanitize_email( (string) ( $fields['email'] ?? '' ) ),
			'user_id'     => (int) ( $fields['user_id'] ?? 0 ),
			'page_url'    => mb_substr( esc_url_raw( (string) ( $fields['page_url'] ?? '' ) ), 0, 700 ),
			'language'    => mb_substr( (string) ( $fields['language'] ?? '' ), 0, 20 ),
			'ip'          => Util::stored_ip( (string) ( $fields['ip'] ?? '' ) ),
			'user_agent'  => mb_substr( sanitize_text_field( (string) ( $fields['user_agent'] ?? '' ) ), 0, 255 ),
			'source_site' => mb_substr( sanitize_text_field( (string) ( $fields['source_site'] ?? '' ) ), 0, 190 ),
			'consent_at'  => ! empty( $fields['consent'] ) ? $now : null,
			'created_at'  => $now,
			'updated_at'  => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
		$wpdb->insert( Schema::table( 'conversations' ), $row );
		$id = (int) $wpdb->insert_id;
		/**
		 * A visitor started a chat.
		 *
		 * @param int $id Conversation id.
		 */
		do_action( 'zinn_chat_conversation_started', $id );
		return array(
			'id'    => $id,
			'token' => $token,
		);
	}

	/**
	 * One conversation by id.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_conversations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; live data.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The conversation a visitor token belongs to.
	 *
	 * @param string $token Token the widget holds.
	 * @return array<string, mixed>|null
	 */
	public static function by_token( string $token ): ?array {
		global $wpdb;
		if ( strlen( $token ) < 20 ) {
			return null;
		}
		$table = $wpdb->prefix . 'zinn_chat_conversations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; live data.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', $table, Util::token_hash( $token ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update columns.
	 *
	 * @param int                  $id     Id.
	 * @param array<string, mixed> $fields Columns.
	 * @return void
	 */
	public static function update( int $id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = $fields['updated_at'] ?? Util::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->update( Schema::table( 'conversations' ), $fields, array( 'id' => $id ) );
	}

	/**
	 * Change status, and announce it.
	 *
	 * @param int                  $id     Id.
	 * @param string               $status New status.
	 * @param array<string, mixed> $extra  More columns to set.
	 * @return void
	 */
	public static function set_status( int $id, string $status, array $extra = array() ): void {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		$before          = self::get( $id );
		$extra['status'] = $status;
		if ( 'closed' === $status ) {
			$extra['closed_at'] = Util::now();
		}
		if ( 'waiting' === $status && empty( $before['handoff_at'] ) ) {
			$extra['handoff_at'] = Util::now();
		}
		self::update( $id, $extra );
		/**
		 * A conversation changed status.
		 *
		 * @param int    $id     Conversation id.
		 * @param string $status New status.
		 * @param string $old    Previous status.
		 */
		do_action( 'zinn_chat_conversation_status', $id, $status, (string) ( $before['status'] ?? '' ) );
	}

	/**
	 * Add a message.
	 *
	 * @param int                             $conversation_id Conversation.
	 * @param string                          $role            One of ROLES.
	 * @param string                          $body            Text.
	 * @param int                             $user_id         Author (agents).
	 * @param array<int, array<string,mixed>> $sources         Sources an AI answer used.
	 * @return int Message id.
	 */
	public static function add_message( int $conversation_id, string $role, string $body, int $user_id = 0, array $sources = array() ): int {
		global $wpdb;
		if ( ! in_array( $role, self::ROLES, true ) ) {
			return 0;
		}
		$now = Util::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
		$wpdb->insert(
			Schema::table( 'messages' ),
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'body'            => $body,
				'user_id'         => $user_id,
				'sources'         => $sources ? (string) wp_json_encode( $sources ) : null,
				'created_at'      => $now,
			)
		);
		$id    = (int) $wpdb->insert_id;
		$table = $wpdb->prefix . 'zinn_chat_conversations';
		if ( 'visitor' === $role ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_visitor_at = %s, updated_at = %s, visitor_typing_at = NULL, unread = unread + 1 WHERE id = %d', $table, $now, $now, $conversation_id ) );
		} elseif ( 'agent' === $role ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_agent_at = %s, updated_at = %s, agent_typing_at = NULL, unread = 0 WHERE id = %d', $table, $now, $now, $conversation_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET updated_at = %s WHERE id = %d', $table, $now, $conversation_id ) );
		}
		/**
		 * A message was added to a conversation.
		 *
		 * @param int    $id              Message id.
		 * @param int    $conversation_id Conversation id.
		 * @param string $role            Role.
		 */
		do_action( 'zinn_chat_message_added', $id, $conversation_id, $role );
		return $id;
	}

	/**
	 * Messages after an id.
	 *
	 * @param int  $conversation_id Conversation.
	 * @param int  $after           Only ids above this.
	 * @param bool $notes           Include agent notes (never for the visitor).
	 * @return array<int, array<string, mixed>>
	 */
	public static function messages( int $conversation_id, int $after = 0, bool $notes = false ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_messages';
		// Notes are agent-only: the visitor's copy never includes them (`%s` = 'note' excludes them).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE conversation_id = %d AND id > %d AND role <> %s ORDER BY id ASC LIMIT 500', $table, $conversation_id, $after, $notes ? '' : 'note' ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The last N messages, oldest first (the AI's memory of the chat).
	 *
	 * @param int $conversation_id Conversation.
	 * @param int $count           How many.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $conversation_id, int $count ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_messages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT role, body FROM %i WHERE conversation_id = %d AND role IN ('visitor','ai','agent') ORDER BY id DESC LIMIT %d", $table, $conversation_id, $count ), ARRAY_A );
		return array_reverse( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Message shape for the widget and the console.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	public static function public_message( array $row ): array {
		$sources = array();
		if ( ! empty( $row['sources'] ) ) {
			$decoded = json_decode( (string) $row['sources'], true );
			foreach ( is_array( $decoded ) ? $decoded : array() as $source ) {
				if ( is_array( $source ) && ! empty( $source['url'] ) ) {
					$sources[] = array(
						'title' => (string) ( $source['title'] ?? '' ),
						'url'   => (string) $source['url'],
					);
				}
			}
		}
		$name = '';
		if ( 'agent' === $row['role'] && ! empty( $row['user_id'] ) ) {
			$user = get_userdata( (int) $row['user_id'] );
			$name = $user ? (string) $user->display_name : '';
		}
		return array(
			'id'      => (int) $row['id'],
			'role'    => (string) $row['role'],
			'text'    => (string) $row['body'],
			'html'    => Util::render_text( (string) $row['body'] ),
			'name'    => $name,
			'sources' => $sources,
			'at'      => Util::iso( (string) $row['created_at'] ),
		);
	}

	/**
	 * Conversations for the agent console, newest activity first.
	 *
	 * @param string $view `active` (waiting + with an agent + offline), `bot` (with the assistant), `closed`, `all`.
	 * @param int    $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_console( string $view, int $limit = 100 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_conversations';
		// One fixed query; the view selects which condition applies (no SQL is assembled).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE ( %s = 'all' )
					OR ( %s = 'bot' AND status = 'bot' AND updated_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) )
					OR ( %s = 'closed' AND status = 'closed' )
					OR ( %s = 'active' AND status IN ('waiting','staff','offline') )
				ORDER BY (status = 'waiting') DESC, updated_at DESC LIMIT %d",
				$table,
				$view,
				$view,
				$view,
				in_array( $view, array( 'bot', 'closed', 'all' ), true ) ? $view : 'active',
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Counts for the admin menu badge.
	 *
	 * @return array{waiting: int, unread: int}
	 */
	public static function counts(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_conversations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status = 'waiting') AS waiting, SUM(CASE WHEN status IN ('staff','waiting') THEN unread ELSE 0 END) AS unread FROM %i WHERE status IN ('waiting','staff')", $table ), ARRAY_A );
		return array(
			'waiting' => (int) ( $row['waiting'] ?? 0 ),
			'unread'  => (int) ( $row['unread'] ?? 0 ),
		);
	}

	/**
	 * Close chats nobody has written in for a while, so the console does not fill with ghosts.
	 *
	 * @param int $minutes Idle minutes.
	 * @return int How many were closed.
	 */
	public static function close_idle( int $minutes ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_conversations';
		$now   = Util::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'closed', closed_at = %s WHERE status IN ('bot','staff') AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)", $table, $now, $minutes ) );
	}
}
