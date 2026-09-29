<?php
/**
 * GDPR: personal data export and erasure, retention, and the privacy policy text.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into WordPress's own privacy tools (Tools → Export / Erase Personal Data), so a request
 * for a person's data includes their chats and tickets, and an erasure removes them.
 *
 * Retention: with `retention_days` set, closed chats and solved/closed tickets older than that are
 * deleted daily, in batches that repeat until none are left.
 */
final class Privacy {

	private const BATCH = 200;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'admin_init', array( self::class, 'policy_text' ) );
		add_action( 'zinn_chat_retention', array( self::class, 'retention' ) );
		add_action( 'init', array( self::class, 'ensure_schedule' ), 20 );
	}

	/**
	 * Daily retention run.
	 *
	 * @return void
	 */
	public static function ensure_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || get_transient( 'zinn_chat_retention_scheduled' ) ) {
			return;
		}
		if ( false === as_has_scheduled_action( 'zinn_chat_retention', array(), 'zinn-chat' ) ) {
			as_schedule_recurring_action( time() + 3600, DAY_IN_SECONDS, 'zinn_chat_retention', array(), 'zinn-chat' );
		}
		set_transient( 'zinn_chat_retention_scheduled', 1, DAY_IN_SECONDS );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public static function exporters( array $exporters ): array {
		$exporters['zinn-chat'] = array(
			'exporter_friendly_name' => __( 'Zinn® Chat chats and support tickets', 'zinn-chat' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public static function erasers( array $erasers ): array {
		$erasers['zinn-chat'] = array(
			'eraser_friendly_name' => __( 'Zinn® Chat chats and support tickets', 'zinn-chat' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * The user id of an email address (0 if none).
	 *
	 * @param string $email Email.
	 * @return int
	 */
	private static function user_of( string $email ): int {
		$user = get_user_by( 'email', $email );
		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Export one page of a person's data.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (1-based).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$user   = self::user_of( $email );
		$data   = array();
		$offset = ( max( 1, $page ) - 1 ) * 20;
		$conv   = $wpdb->prefix . 'zinn_chat_conversations';
		$tick   = $wpdb->prefix . 'zinn_chat_tickets';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own tables.
		$chats   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE (email <> '' AND email = %s) OR (user_id > 0 AND user_id = %d) ORDER BY id LIMIT 20 OFFSET %d", $conv, $email, $user, $offset ), ARRAY_A );
		$tickets = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE (email <> '' AND email = %s) OR (user_id > 0 AND user_id = %d) ORDER BY id LIMIT 20 OFFSET %d", $tick, $email, $user, $offset ), ARRAY_A );
		// phpcs:enable
		foreach ( (array) $chats as $chat ) {
			$lines = array();
			foreach ( Conversations::messages( (int) $chat['id'] ) as $message ) {
				$lines[] = $message['created_at'] . ' ' . $message['role'] . ': ' . $message['body'];
			}
			$data[] = array(
				'group_id'    => 'zinn-chat-chats',
				'group_label' => __( 'Chats', 'zinn-chat' ),
				'item_id'     => 'zinn-chat-' . $chat['id'],
				'data'        => array(
					array(
						'name'  => __( 'Started', 'zinn-chat' ),
						'value' => $chat['created_at'],
					),
					array(
						'name'  => __( 'Name', 'zinn-chat' ),
						'value' => $chat['name'],
					),
					array(
						'name'  => __( 'Email', 'zinn-chat' ),
						'value' => $chat['email'],
					),
					array(
						'name'  => __( 'Page', 'zinn-chat' ),
						'value' => $chat['page_url'],
					),
					array(
						'name'  => __( 'Messages', 'zinn-chat' ),
						'value' => implode( "\n", $lines ),
					),
				),
			);
		}
		foreach ( (array) $tickets as $ticket ) {
			$lines = array();
			foreach ( Tickets::replies( (int) $ticket['id'] ) as $reply ) {
				$lines[] = $reply['created_at'] . ' ' . $reply['author'] . ': ' . $reply['body'];
			}
			$data[] = array(
				'group_id'    => 'zinn-chat-tickets',
				'group_label' => __( 'Support tickets', 'zinn-chat' ),
				'item_id'     => 'zinn-ticket-' . $ticket['id'],
				'data'        => array(
					array(
						'name'  => __( 'Ticket', 'zinn-chat' ),
						'value' => '#' . $ticket['id'] . ' ' . $ticket['subject'],
					),
					array(
						'name'  => __( 'Opened', 'zinn-chat' ),
						'value' => $ticket['created_at'],
					),
					array(
						'name'  => __( 'Status', 'zinn-chat' ),
						'value' => Tickets::status_label( (string) $ticket['status'] ),
					),
					array(
						'name'  => __( 'Messages', 'zinn-chat' ),
						'value' => implode( "\n", $lines ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( (array) $chats ) < 20 && count( (array) $tickets ) < 20,
		);
	}

	/**
	 * Erase one batch of a person's data.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (unused: each call deletes what is left).
	 * @return array{items_removed: int, items_retained: int, messages: array<int, string>, done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		unset( $page );
		global $wpdb;
		$user = self::user_of( $email );
		$conv = $wpdb->prefix . 'zinn_chat_conversations';
		$tick = $wpdb->prefix . 'zinn_chat_tickets';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own tables.
		$chats   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE (email <> '' AND email = %s) OR (user_id > 0 AND user_id = %d) LIMIT %d", $conv, $email, $user, self::BATCH ) );
		$tickets = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE (email <> '' AND email = %s) OR (user_id > 0 AND user_id = %d) LIMIT %d", $tick, $email, $user, self::BATCH ) );
		// phpcs:enable
		self::delete_conversations( array_map( 'intval', (array) $chats ) );
		self::delete_tickets( array_map( 'intval', (array) $tickets ) );
		$removed = count( (array) $chats ) + count( (array) $tickets );
		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => count( (array) $chats ) < self::BATCH && count( (array) $tickets ) < self::BATCH,
		);
	}

	/**
	 * Delete conversations with their messages.
	 *
	 * @param array<int, int> $ids Ids.
	 * @return void
	 */
	public static function delete_conversations( array $ids ): void {
		global $wpdb;
		if ( ! $ids ) {
			return;
		}
		$list = implode( ',', array_map( 'intval', $ids ) );
		$m    = $wpdb->prefix . 'zinn_chat_messages';
		$c    = $wpdb->prefix . 'zinn_chat_conversations';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own tables; ids cast to int.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE conversation_id IN ({$list})", $m ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$list})", $c ) );
		// phpcs:enable
	}

	/**
	 * Delete tickets with their replies.
	 *
	 * @param array<int, int> $ids Ids.
	 * @return void
	 */
	public static function delete_tickets( array $ids ): void {
		global $wpdb;
		if ( ! $ids ) {
			return;
		}
		$list = implode( ',', array_map( 'intval', $ids ) );
		$r    = $wpdb->prefix . 'zinn_chat_replies';
		$t    = $wpdb->prefix . 'zinn_chat_tickets';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own tables; ids cast to int.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE ticket_id IN ({$list})", $r ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$list})", $t ) );
		// phpcs:enable
	}

	/**
	 * Delete what is older than the retention period, batch by batch until nothing is left.
	 *
	 * @return int How many rows were removed.
	 */
	public static function retention(): int {
		global $wpdb;
		$days = (int) Settings::get( 'retention_days', 0 );
		// Idle chats are closed whatever the retention setting.
		Conversations::close_idle( 120 );
		if ( $days < 1 ) {
			return 0;
		}
		$conv  = $wpdb->prefix . 'zinn_chat_conversations';
		$tick  = $wpdb->prefix . 'zinn_chat_tickets';
		$total = 0;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own tables.
			$chats   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status IN ('closed','offline') AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT %d", $conv, $days, self::BATCH ) );
			$tickets = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status IN ('solved','closed') AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) LIMIT %d", $tick, $days, self::BATCH ) );
			// phpcs:enable
			self::delete_conversations( array_map( 'intval', (array) $chats ) );
			self::delete_tickets( array_map( 'intval', (array) $tickets ) );
			$done   = count( (array) $chats ) + count( (array) $tickets );
			$total += $done;
		} while ( $done > 0 );
		return $total;
	}

	/**
	 * Suggested text for the site's privacy policy (Settings → Privacy).
	 *
	 * @return void
	 */
	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'When you use the chat or submit a support request, we store your messages, the name and email address you give us, the page you were on and a coded form of your IP address, so that we can answer you and keep a record of your request.', 'zinn-chat' ) . '</p>'
			. '<p>' . esc_html__( 'If our AI assistant answers you, your message and passages from this website are sent to the AI provider we use to produce the answer.', 'zinn-chat' ) . '</p>';
		wp_add_privacy_policy_content( 'Zinn® Chat', wp_kses_post( $text ) );
	}
}
