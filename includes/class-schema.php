<?php
/**
 * The plugin's own database tables, their migrations, and the upgrade from 1.x.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tables. Everything the help desk stores lives in these six tables on the site's own database.
 *
 * ⛔ Custom tables, not posts/postmeta: a busy site has hundreds of thousands of chat messages
 * and index chunks, and storing them as posts would put them in every `WP_Query` join, every
 * export and every search a theme runs.
 *
 * Migrations are dbDelta against the full CREATE statements (idempotent), stamped with
 * DB_VERSION; anything dbDelta cannot express goes in a numbered step in migrate().
 */
final class Schema {

	/** Bump with any change to the statements below or a new migrate() step. */
	public const DB_VERSION = 1;

	private const OPTION = 'zinn_chat_db';

	/**
	 * A table name with the site prefix.
	 *
	 * @param string $name Short name: conversations, messages, tickets, replies, items, chunks.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'zinn_chat_' . $name;
	}

	/**
	 * Every short table name.
	 *
	 * @return array<int, string>
	 */
	public static function names(): array {
		return array( 'conversations', 'messages', 'tickets', 'replies', 'items', 'chunks' );
	}

	/**
	 * Create or upgrade when the stored version is behind. Cheap when current (one autoloaded option).
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored = (int) get_option( self::OPTION, 0 );
		if ( $stored >= self::DB_VERSION ) {
			return;
		}
		self::install();
		// ⛔ Stamp the version only when every table really exists. dbDelta reports nothing on a
		// failed CREATE, so a stamp written regardless would leave a site without its tables for
		// ever while every screen loads (a MariaDB that reserves a column name did exactly that).
		$missing = self::missing();
		if ( $missing ) {
			global $wpdb;
			set_transient( 'zinn_chat_db_error', implode( ', ', $missing ) . ': ' . $wpdb->last_error, DAY_IN_SECONDS );
			return;
		}
		delete_transient( 'zinn_chat_db_error' );
		self::migrate( $stored );
		update_option( self::OPTION, self::DB_VERSION, true );
	}

	/**
	 * Tables that should exist and do not.
	 *
	 * @return array<int, string>
	 */
	public static function missing(): array {
		global $wpdb;
		$missing = array();
		foreach ( self::names() as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	/**
	 * CREATE / ALTER every table to match the statements.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$c       = $wpdb->prefix . 'zinn_chat_conversations';
		$m       = $wpdb->prefix . 'zinn_chat_messages';
		$t       = $wpdb->prefix . 'zinn_chat_tickets';
		$r       = $wpdb->prefix . 'zinn_chat_replies';
		$i       = $wpdb->prefix . 'zinn_chat_items';
		$k       = $wpdb->prefix . 'zinn_chat_chunks';

		// dbDelta's own format: two spaces after PRIMARY KEY, one key per line, no backticks.
		$sql = array(
			"CREATE TABLE {$c} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL DEFAULT '',
  status varchar(12) NOT NULL DEFAULT 'bot',
  name varchar(120) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  page_url varchar(700) NOT NULL DEFAULT '',
  language varchar(20) NOT NULL DEFAULT '',
  ip varchar(64) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  subject varchar(200) NOT NULL DEFAULT '',
  source_site varchar(190) NOT NULL DEFAULT '',
  unanswered tinyint(3) unsigned NOT NULL DEFAULT 0,
  unread smallint(5) unsigned NOT NULL DEFAULT 0,
  ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
  rating tinyint(3) NOT NULL DEFAULT 0,
  input_tokens int(10) unsigned NOT NULL DEFAULT 0,
  output_tokens int(10) unsigned NOT NULL DEFAULT 0,
  consent_at datetime DEFAULT NULL,
  handoff_at datetime DEFAULT NULL,
  pinged_at datetime DEFAULT NULL,
  last_visitor_at datetime DEFAULT NULL,
  last_agent_at datetime DEFAULT NULL,
  visitor_typing_at datetime DEFAULT NULL,
  agent_typing_at datetime DEFAULT NULL,
  closed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY token_hash (token_hash),
  KEY status_updated (status,updated_at),
  KEY email (email),
  KEY user_id (user_id)
) {$charset};",
			"CREATE TABLE {$m} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL,
  role varchar(10) NOT NULL,
  body mediumtext NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sources longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY conversation (conversation_id,id)
) {$charset};",
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(12) NOT NULL DEFAULT 'open',
  priority varchar(10) NOT NULL DEFAULT 'normal',
  subject varchar(200) NOT NULL DEFAULT '',
  name varchar(120) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  channel varchar(12) NOT NULL DEFAULT 'form',
  source_site varchar(190) NOT NULL DEFAULT '',
  assignee_id bigint(20) unsigned NOT NULL DEFAULT 0,
  token_hash char(64) NOT NULL DEFAULT '',
  token_at datetime DEFAULT NULL,
  language varchar(20) NOT NULL DEFAULT '',
  ip varchar(64) NOT NULL DEFAULT '',
  unread tinyint(3) unsigned NOT NULL DEFAULT 0,
  last_customer_at datetime DEFAULT NULL,
  last_agent_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status_updated (status,updated_at),
  KEY email (email),
  KEY user_id (user_id),
  KEY token_hash (token_hash)
) {$charset};",
			"CREATE TABLE {$r} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ticket_id bigint(20) unsigned NOT NULL,
  author varchar(10) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  body mediumtext NOT NULL,
  via varchar(10) NOT NULL DEFAULT 'web',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY ticket (ticket_id,id)
) {$charset};",
			"CREATE TABLE {$i} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(40) NOT NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(40) NOT NULL DEFAULT '',
  url varchar(700) NOT NULL DEFAULT '',
  title varchar(400) NOT NULL DEFAULT '',
  language varchar(20) NOT NULL DEFAULT '',
  modified_gmt datetime DEFAULT NULL,
  content_hash char(40) NOT NULL DEFAULT '',
  status varchar(12) NOT NULL DEFAULT 'pending',
  chunk_count smallint(5) unsigned NOT NULL DEFAULT 0,
  embed_model varchar(160) NOT NULL DEFAULT '',
  dims smallint(5) unsigned NOT NULL DEFAULT 0,
  embedding blob DEFAULT NULL,
  error varchar(300) NOT NULL DEFAULT '',
  attempted_at datetime DEFAULT NULL,
  indexed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY object (object_type,object_id),
  KEY status_attempted (status,attempted_at)
) {$charset};",
			"CREATE TABLE {$k} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id bigint(20) unsigned NOT NULL,
  seq smallint(5) unsigned NOT NULL DEFAULT 0,
  body text NOT NULL,
  embedding blob DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY item (item_id,seq),
  FULLTEXT KEY body_ft (body)
) {$charset};",
		);
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Steps dbDelta cannot express, and the upgrade from the 1.x (hosted-only) plugin.
	 *
	 * @param int $from Stored DB version (0 = never installed).
	 * @return void
	 */
	public static function migrate( int $from ): void {
		if ( $from < 1 ) {
			self::upgrade_from_one();
		}
	}

	/**
	 * 1.x kept one option and was a thin client of the hosted service. Keep it working exactly:
	 * a site that had a key stays in connected mode with the same key and the same switch; any
	 * other site becomes a local help desk that is OFF until its owner turns it on. The 1.x Pro
	 * licence option and its cron (the retired Zinn licence server) are removed.
	 *
	 * @return void
	 */
	public static function upgrade_from_one(): void {
		$old = get_option( Settings::OPTION, null );
		if ( is_array( $old ) && empty( $old['schema'] ) ) {
			$has_key       = '' !== trim( (string) ( $old['public_key'] ?? '' ) );
			$old['mode']   = $has_key ? 'connected' : 'local';
			$old['schema'] = 2;
			if ( ! $has_key ) {
				$old['enabled'] = false;
			}
			update_option( Settings::OPTION, $old, true );
			Settings::flush();
		}
		delete_option( 'zinn_chat_licence' );
		wp_clear_scheduled_hook( 'zinn_chat_licence_check' );
	}

	/**
	 * Does the chunk table carry a FULLTEXT index? (MySQL < 5.6 InnoDB cannot; search then falls
	 * back to LIKE.) Cached per request.
	 *
	 * @return bool
	 */
	public static function has_fulltext(): bool {
		static $has = null;
		if ( null === $has ) {
			global $wpdb;
			$table = $wpdb->prefix . 'zinn_chat_chunks';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; schema metadata read.
			$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM %i WHERE Key_name = 'body_ft'", $table ) );
			$has  = ! empty( $rows );
		}
		return $has;
	}

	/**
	 * Drop every table and the stamp (uninstall).
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;
		foreach ( self::names() as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall removes the plugin's own tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		delete_option( self::OPTION );
	}
}
