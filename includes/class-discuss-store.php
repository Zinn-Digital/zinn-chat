<?php
/**
 * Storage for team discussions and the notification centre (2.14.0): two of the plugin's own
 * tables, `zinn_chat_discussions` and `zinn_chat_notifications` (class-schema.php).
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain reads and writes; every rule (who may read what, who is told) lives in Discuss.
 *
 * ⛔ Every notification read or update is scoped `WHERE user_id = %d` here as well as checked in
 * Discuss: an agent's centre is theirs alone (wp/tests/unit/ZinnChatDiscussTest.php pins both).
 */
class Discuss_Store {

	/**
	 * Insert a discussion message.
	 *
	 * @param array<string, mixed> $row Columns.
	 * @return int New id (0: failed).
	 */
	public function add_message( array $row ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
		$ok = $wpdb->insert( Schema::table( 'discussions' ), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * The messages of one thread, oldest first.
	 *
	 * @param string $type  Object type.
	 * @param string $key   Object key.
	 * @param int    $limit Most rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function messages( string $type, string $key, int $limit = 300 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE object_type = %s AND object_key = %s ORDER BY id ASC LIMIT %d', Schema::table( 'discussions' ), $type, $key, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One message.
	 *
	 * @param int $id Message.
	 * @return array<string, mixed>|null
	 */
	public function message( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'discussions' ), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Save a message's reactions.
	 *
	 * @param int    $id   Message.
	 * @param string $json Reactions as JSON.
	 * @return void
	 */
	public function set_reactions( int $id, string $json ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->update( Schema::table( 'discussions' ), array( 'reactions' => $json ), array( 'id' => $id ) );
	}

	/**
	 * Insert a notification.
	 *
	 * @param array<string, mixed> $row Columns.
	 * @return int New id (0: failed).
	 */
	public function add_notification( array $row ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
		$ok = $wpdb->insert( Schema::table( 'notifications' ), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One agent's notifications, newest first.
	 *
	 * @param int $user_id Agent.
	 * @param int $limit   Most rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function notifications( int $user_id, int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d', Schema::table( 'notifications' ), $user_id, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many of an agent's notifications are unread.
	 *
	 * @param int $user_id Agent.
	 * @return int
	 */
	public function unread( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read fresh.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND read_at IS NULL', Schema::table( 'notifications' ), $user_id ) );
	}

	/**
	 * Mark an agent's notifications read: the listed ids, or all of them.
	 *
	 * @param int             $user_id Agent.
	 * @param array<int, int> $ids     Notification ids (empty: all).
	 * @return void
	 */
	public function mark_read( int $user_id, array $ids ): void {
		global $wpdb;
		$table = Schema::table( 'notifications' );
		$now   = Util::now();
		if ( ! $ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET read_at = %s WHERE user_id = %d AND read_at IS NULL', $table, $now, $user_id ) );
			return;
		}
		$ids   = array_values( array_map( 'intval', $ids ) );
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the IN list is only %d placeholders.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET read_at = %s WHERE user_id = %d AND read_at IS NULL AND id IN ({$marks})", array_merge( array( $table, $now, $user_id ), $ids ) ) );
	}

	/**
	 * Remove a thread and the notifications that point into it (privacy erase of a contact).
	 *
	 * @param string $type Object type.
	 * @param string $key  Object key.
	 * @return void
	 */
	public function delete_thread( string $type, string $key ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->delete(
			Schema::table( 'discussions' ),
			array(
				'object_type' => $type,
				'object_key'  => $key,
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->delete(
			Schema::table( 'notifications' ),
			array(
				'object_type' => $type,
				'object_key'  => $key,
			)
		);
	}
}
