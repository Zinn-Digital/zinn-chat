<?php
/**
 * The site index: what the assistant knows about the site.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Index;

use ZinnDigital\ZinnChat\AiCore\Client;
use ZinnDigital\ZinnChat\AiCore\Failure;
use ZinnDigital\ZinnChat\Schema;
use ZinnDigital\ZinnChat\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Items (one per page/product/topic) and their chunks (passages), with vectors built by the site
 * owner's own AI key.
 *
 * ⭐ Keyword search works with NO key at all (MySQL FULLTEXT over the passages), so the assistant
 * is never blind; a key that can embed adds meaning-based search on top. When the embedding model
 * changes, items are re-embedded without being re-read (the text is already stored).
 *
 * ⛔ attempted_at is stamped BEFORE an item's result is known, for every item reached, so a page
 * that cannot be read cannot be selected first by every sweep for ever (CLAUDE.md §2.10, D14162).
 */
final class Index {

	/** When embedding is refused for billing/key reasons, stop asking for this long. */
	private const BLOCK_SECONDS = HOUR_IN_SECONDS;

	/**
	 * The embedding model identity new vectors are built with: provider/model/dims, or '' when no
	 * connected key can embed.
	 *
	 * @return string
	 */
	public static function model_key(): string {
		$choice = Client::embedding_choice();
		if ( '' === $choice['provider'] || '' === $choice['model'] ) {
			return '';
		}
		return $choice['provider'] . '/' . $choice['model'] . '/' . Vectors::DIMENSIONS;
	}

	/**
	 * Why embedding is paused, if it is ('' when not).
	 *
	 * @return string
	 */
	public static function embed_blocked(): string {
		return (string) get_transient( 'zinn_chat_embed_blocked' );
	}

	/**
	 * Index (or re-index) one object.
	 *
	 * @param string $type  Object type.
	 * @param int    $id    Object id.
	 * @param bool   $force Re-read and re-embed even when unchanged.
	 * @return string `indexed`, `unchanged`, `removed`, `error`.
	 */
	public static function index_object( string $type, int $id, bool $force = false ): string {
		$item = self::item( $type, $id );
		// Stamp the attempt first (the cursor rule in the class comment).
		$item_id = self::touch( $type, $id, $item );
		try {
			$data = Extractor::extract( $type, $id );
		} catch ( \Throwable $e ) {
			self::update_item(
				$item_id,
				array(
					'status' => 'error',
					'error'  => mb_substr( $e->getMessage(), 0, 300 ),
				)
			);
			return 'error';
		}
		if ( null === $data ) {
			self::delete_item( $item_id );
			return 'removed';
		}
		$hash  = sha1( $data['title'] . "\n" . $data['url'] . "\n" . $data['text'] );
		$model = self::model_key();
		// Unchanged text (whatever the status: a re-read of the whole site after an update) costs
		// nothing more; only new or edited text is embedded again.
		if ( ! $force && $item && $hash === $item['content_hash'] && (int) $item['chunk_count'] > 0 && ( '' === $model || $model === $item['embed_model'] || '' !== self::embed_blocked() ) ) {
			self::update_item(
				$item_id,
				array(
					'url'    => mb_substr( $data['url'], 0, 700 ),
					'status' => 'indexed',
					'error'  => '',
				)
			);
			return 'unchanged';
		}
		$chunks = Chunker::split( $data['text'] );
		if ( ! $chunks ) {
			self::delete_item( $item_id );
			return 'removed';
		}
		$vectors = self::embed_passages( $data['title'], $chunks );
		self::store_chunks( $item_id, $chunks, $vectors['vectors'] );
		self::update_item(
			$item_id,
			array(
				'kind'         => mb_substr( $data['kind'], 0, 40 ),
				'url'          => mb_substr( $data['url'], 0, 700 ),
				'title'        => $data['title'],
				'language'     => mb_substr( $data['language'], 0, 20 ),
				'modified_gmt' => '' !== $data['modified'] ? $data['modified'] : null,
				'content_hash' => $hash,
				'status'       => 'indexed',
				'chunk_count'  => count( $chunks ),
				'embed_model'  => $vectors['model'],
				'dims'         => $vectors['dims'],
				'embedding'    => $vectors['vectors'] ? Vectors::pack( Vectors::mean( $vectors['vectors'] ) ) : null,
				'error'        => mb_substr( $vectors['error'], 0, 300 ),
				'indexed_at'   => Util::now(),
			)
		);
		/**
		 * An object was (re)indexed.
		 *
		 * @param string $type Object type.
		 * @param int    $id   Object id.
		 */
		do_action( 'zinn_chat_indexed', $type, $id );
		return 'indexed';
	}

	/**
	 * Re-embed an already-read item with the current model (no re-extraction).
	 *
	 * @param int $item_id Item.
	 * @return bool
	 */
	public static function reembed( int $item_id ): bool {
		global $wpdb;
		$item = self::get_item( $item_id );
		if ( ! $item ) {
			return false;
		}
		self::update_item( $item_id, array( 'attempted_at' => Util::now() ) );
		$table = $wpdb->prefix . 'zinn_chat_chunks';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, body FROM %i WHERE item_id = %d ORDER BY seq', $table, $item_id ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( ! $rows ) {
			return false;
		}
		$vectors = self::embed_passages( (string) $item['title'], wp_list_pluck( $rows, 'body' ) );
		if ( ! $vectors['vectors'] ) {
			self::update_item( $item_id, array( 'error' => mb_substr( $vectors['error'], 0, 300 ) ) );
			return false;
		}
		foreach ( $rows as $index => $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
			$wpdb->update( $table, array( 'embedding' => Vectors::pack( $vectors['vectors'][ $index ] ?? array() ) ), array( 'id' => (int) $row['id'] ) );
		}
		self::update_item(
			$item_id,
			array(
				'embed_model' => $vectors['model'],
				'dims'        => $vectors['dims'],
				'embedding'   => Vectors::pack( Vectors::mean( $vectors['vectors'] ) ),
				'error'       => '',
			)
		);
		return true;
	}

	/**
	 * Embed passages with their title, or explain why not.
	 *
	 * @param string             $title  Page title.
	 * @param array<int, string> $chunks Passages.
	 * @return array{vectors: array<int, array<int, float>>, model: string, dims: int, error: string}
	 */
	private static function embed_passages( string $title, array $chunks ): array {
		$none  = array(
			'vectors' => array(),
			'model'   => '',
			'dims'    => 0,
			'error'   => '',
		);
		$model = self::model_key();
		if ( '' === $model ) {
			return $none;
		}
		$blocked = self::embed_blocked();
		if ( '' !== $blocked ) {
			$none['error'] = $blocked;
			return $none;
		}
		$texts = array();
		foreach ( $chunks as $chunk ) {
			$texts[] = ( '' !== $title ? $title . "\n\n" : '' ) . $chunk;
		}
		$result = Client::embed(
			$texts,
			array(
				'purpose'    => 'zinn-chat-index',
				'dimensions' => Vectors::DIMENSIONS,
				'type'       => 'document',
				'system'     => true,
			)
		);
		if ( ! $result->ok() ) {
			$failure = $result->failure;
			$message = $failure ? $failure->message : '';
			if ( $failure && in_array( $failure->kind, array( Failure::BILLING, Failure::INVALID_KEY, Failure::REFUSED, Failure::RATE_LIMITED ), true ) ) {
				set_transient( 'zinn_chat_embed_blocked', $message, self::BLOCK_SECONDS );
			}
			$none['error'] = $message;
			return $none;
		}
		$dims = isset( $result->vectors[0] ) ? count( $result->vectors[0] ) : 0;
		return array(
			'vectors' => $result->vectors,
			'model'   => $model,
			'dims'    => $dims,
			'error'   => '',
		);
	}

	/**
	 * Replace an item's chunks.
	 *
	 * @param int                           $item_id Item.
	 * @param array<int, string>            $chunks  Passages.
	 * @param array<int, array<int, float>> $vectors Vectors (may be empty).
	 * @return void
	 */
	private static function store_chunks( int $item_id, array $chunks, array $vectors ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_chunks';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->delete( $table, array( 'item_id' => $item_id ) );
		foreach ( $chunks as $seq => $body ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
			$wpdb->insert(
				$table,
				array(
					'item_id'   => $item_id,
					'seq'       => $seq,
					'body'      => $body,
					'embedding' => isset( $vectors[ $seq ] ) ? Vectors::pack( $vectors[ $seq ] ) : null,
				)
			);
		}
	}

	/**
	 * The item row of an object.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return array<string, mixed>|null
	 */
	public static function item( string $type, int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE object_type = %s AND object_id = %d', $table, $type, $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * An item by its own id.
	 *
	 * @param int $item_id Item.
	 * @return array<string, mixed>|null
	 */
	public static function get_item( int $item_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $item_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Make sure an item row exists and stamp attempted_at.
	 *
	 * @param string                    $type Object type.
	 * @param int                       $id   Object id.
	 * @param array<string, mixed>|null $item Existing row.
	 * @return int Item id.
	 */
	private static function touch( string $type, int $id, ?array $item ): int {
		global $wpdb;
		if ( $item ) {
			self::update_item( (int) $item['id'], array( 'attempted_at' => Util::now() ) );
			return (int) $item['id'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
		$wpdb->insert(
			Schema::table( 'items' ),
			array(
				'object_type'  => $type,
				'object_id'    => $id,
				'status'       => 'pending',
				'attempted_at' => Util::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark an object for (re)indexing without doing it now (the queue picks it up).
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return void
	 */
	public static function mark_pending( string $type, int $id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (object_type, object_id, status) VALUES (%s, %d, 'pending') ON DUPLICATE KEY UPDATE status = 'pending'", $table, $type, $id ) );
	}

	/**
	 * Update item columns.
	 *
	 * @param int                  $item_id Item.
	 * @param array<string, mixed> $fields  Columns.
	 * @return void
	 */
	public static function update_item( int $item_id, array $fields ): void {
		global $wpdb;
		if ( ! $fields ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->update( Schema::table( 'items' ), $fields, array( 'id' => $item_id ) );
	}

	/**
	 * Remove an item and its chunks.
	 *
	 * @param int $item_id Item.
	 * @return void
	 */
	public static function delete_item( int $item_id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own tables.
		$wpdb->delete( Schema::table( 'chunks' ), array( 'item_id' => $item_id ) );
		$wpdb->delete( Schema::table( 'items' ), array( 'id' => $item_id ) );
		// phpcs:enable
	}

	/**
	 * Remove an object from the index now.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return void
	 */
	public static function delete_object( string $type, int $id ): void {
		$item = self::item( $type, $id );
		if ( $item ) {
			self::delete_item( (int) $item['id'] );
		}
	}

	/**
	 * Mark every item for re-reading (the "Re-read the whole site" button, a settings change, a
	 * plugin update). Text that did not change is not embedded again, so this costs no AI calls
	 * beyond the pages that really changed.
	 *
	 * @return void
	 */
	public static function mark_all_stale(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'pending' WHERE status <> 'pending'", $table ) );
	}

	/**
	 * Mark every item for re-embedding only (the "Rebuild meanings" button).
	 *
	 * @return void
	 */
	public static function mark_all_reembed(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'zinn_chat_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET embed_model = '' WHERE status = 'indexed'", $table ) );
		delete_transient( 'zinn_chat_embed_blocked' );
	}

	/**
	 * Numbers for the Assistant screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function stats(): array {
		global $wpdb;
		$items  = $wpdb->prefix . 'zinn_chat_items';
		$chunks = $wpdb->prefix . 'zinn_chat_chunks';
		$model  = self::model_key();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own tables.
		$by_status = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', $items ), ARRAY_A );
		$semantic  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'indexed' AND embed_model = %s AND embed_model <> ''", $items, $model ) );
		$passages  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $chunks ) );
		$last      = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(indexed_at) FROM %i', $items ) );
		// phpcs:enable
		$counts = array(
			'indexed' => 0,
			'pending' => 0,
			'error'   => 0,
		);
		foreach ( (array) $by_status as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
		}
		return array(
			'counts'   => $counts,
			'semantic' => $semantic,
			'passages' => $passages,
			'last'     => Util::iso( $last ),
			'model'    => $model,
			'blocked'  => self::embed_blocked(),
			'keyword'  => Schema::has_fulltext(),
		);
	}

	/**
	 * Indexed items for the Assistant screen's content list.
	 *
	 * @param string $search Title/URL filter.
	 * @param string $status Status filter ('' = all).
	 * @param int    $page   Page (1-based).
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function list_items( string $search, string $status, int $page ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'zinn_chat_items';
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$status = in_array( $status, array( 'indexed', 'pending', 'error' ), true ) ? $status : '';
		$per    = 50;
		$offset = max( 0, $page - 1 ) * $per;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = '' OR title LIKE %s OR url LIKE %s ) AND ( %s = '' OR status = %s )", $table, $search, $like, $like, $status, $status ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, object_id, kind, url, title, status, chunk_count, embed_model, error, modified_gmt, indexed_at FROM %i WHERE ( %s = '' OR title LIKE %s OR url LIKE %s ) AND ( %s = '' OR status = %s ) ORDER BY indexed_at DESC, id DESC LIMIT %d OFFSET %d", $table, $search, $like, $like, $status, $status, $per, $offset ), ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}
}
