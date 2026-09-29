<?php
/**
 * Keeping the index up to date: change hooks plus a resumable background sweep.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Index;

use ZinnDigital\ZinnChat\Schema;
use ZinnDigital\ZinnChat\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two ways content reaches the index, and neither is a capped cron job.
 *
 * 1. CHANGE HOOKS. Saving, editing, trashing or deleting a post, a product, a variation, a forum
 *    reply, a builder layout or a shipping zone queues that one object at once (an async
 *    Action Scheduler action, de-duplicated), so an edit is searchable within a minute.
 *
 * 2. THE SWEEP (hourly, and on demand). It catches whatever the hooks could not see (imports,
 *    direct database edits, a plugin that saves without hooks, a key added later):
 *      discover  walks every indexable post by ID in pages of 500 and marks new or changed ones;
 *      prune     removes items whose object is gone, unpublished or no longer an indexed type;
 *      work      indexes pending items and re-embeds items built with another model, a few at a
 *                time within a time budget, and QUEUES ITSELF AGAIN while work remains.
 *    ⛔ Nothing here stops at a count. A site with 50,000 products is fully indexed by the chain,
 *    however long it takes; the only bounds are per-action time budgets that protect the site's
 *    PHP workers and the provider's rate limits, and each action resumes where the last stopped
 *    (a cursor for discovery, `attempted_at` for work) — CLAUDE.md §2.10.
 */
final class Queue {

	public const GROUP = 'zinn-chat';

	/** Seconds one work action may spend before handing over to the next. */
	private const BUDGET = 20;

	/** Hooks. */
	public static function init(): void {
		add_action( 'zinn_chat_index_object', array( self::class, 'run_object' ), 10, 2 );
		add_action( 'zinn_chat_index_sweep', array( self::class, 'sweep' ) );
		add_action( 'zinn_chat_index_discover', array( self::class, 'discover' ) );
		add_action( 'zinn_chat_index_prune', array( self::class, 'prune' ) );
		add_action( 'zinn_chat_index_work', array( self::class, 'work' ) );

		add_action( 'save_post', array( self::class, 'on_save_post' ), 99, 2 );
		add_action( 'deleted_post', array( self::class, 'on_delete_post' ) );
		add_action( 'trashed_post', array( self::class, 'on_delete_post' ) );
		add_action( 'woocommerce_update_product', array( self::class, 'on_product' ) );
		add_action( 'woocommerce_product_set_stock', array( self::class, 'on_product_object' ) );
		add_action( 'woocommerce_variation_set_stock', array( self::class, 'on_product_object' ) );
		add_action( 'woocommerce_after_shipping_zone_object_save', array( self::class, 'on_store' ) );
		add_action( 'woocommerce_shipping_zone_method_added', array( self::class, 'on_store' ) );
		add_action( 'woocommerce_shipping_zone_method_deleted', array( self::class, 'on_store' ) );
		add_action( 'woocommerce_update_options', array( self::class, 'on_store' ) );
		add_action( 'updated_post_meta', array( self::class, 'on_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( self::class, 'on_meta' ), 10, 3 );
		add_action( 'fl_builder_after_save_layout', array( self::class, 'on_post_id' ) );
		add_action( 'elementor/editor/after_save', array( self::class, 'on_post_id' ) );
		add_action( 'acf/save_post', array( self::class, 'on_post_id' ), 20 );
		foreach ( array( 'wpforo_after_add_topic', 'wpforo_after_edit_topic', 'wpforo_after_add_post', 'wpforo_after_edit_post' ) as $hook ) {
			add_action( $hook, array( self::class, 'on_wpforo' ) );
		}
		foreach ( array( 'wpforo_after_delete_topic', 'wpforo_after_delete_post' ) as $hook ) {
			add_action( $hook, array( self::class, 'on_wpforo' ) );
		}
		add_action( 'init', array( self::class, 'ensure_schedule' ), 20 );
	}

	/**
	 * Keep the hourly sweep scheduled (once; Action Scheduler stores it).
	 *
	 * @return void
	 */
	public static function ensure_schedule(): void {
		// Checked at most hourly (a transient), not on every page view: it is a database query.
		if ( ! function_exists( 'as_has_scheduled_action' ) || Settings::connected() || get_transient( 'zinn_chat_sweep_scheduled' ) ) {
			return;
		}
		if ( false === as_has_scheduled_action( 'zinn_chat_index_sweep', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, HOUR_IN_SECONDS, 'zinn_chat_index_sweep', array(), self::GROUP );
		}
		set_transient( 'zinn_chat_sweep_scheduled', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Stop every scheduled action (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'zinn_chat_index_sweep', 'zinn_chat_index_discover', 'zinn_chat_index_prune', 'zinn_chat_index_work', 'zinn_chat_index_object' ) as $hook ) {
				as_unschedule_all_actions( $hook, null, self::GROUP );
			}
		}
	}

	/**
	 * Queue one object for (re)indexing, at most once at a time.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return void
	 */
	public static function enqueue( string $type, int $id ): void {
		if ( Settings::connected() ) {
			return;
		}
		$args = array( $type, $id );
		// Before Action Scheduler has initialised (a plugin activating and creating pages, say)
		// the item is only marked: the next work chain picks it up (Action Scheduler refuses
		// early calls with a notice, and a notice is a defect).
		if ( function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' ) ) {
			if ( ! as_has_scheduled_action( 'zinn_chat_index_object', $args, self::GROUP ) ) {
				as_enqueue_async_action( 'zinn_chat_index_object', $args, self::GROUP );
			}
			return;
		}
		Index::mark_pending( $type, $id );
	}

	/**
	 * Start a full sweep now (the admin buttons, activation, a model change).
	 *
	 * @return void
	 */
	public static function start_sweep(): void {
		if ( function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' ) && ! as_has_scheduled_action( 'zinn_chat_index_discover', null, self::GROUP ) ) {
			delete_option( 'zinn_chat_index_cursor' );
			as_enqueue_async_action( 'zinn_chat_index_discover', array( 0 ), self::GROUP );
		}
	}

	/**
	 * Action: index one object.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return void
	 */
	public static function run_object( $type, $id ): void {
		Index::index_object( (string) $type, (int) $id );
	}

	/**
	 * Action: the hourly sweep.
	 *
	 * @return void
	 */
	public static function sweep(): void {
		self::start_sweep();
	}

	/**
	 * Action: discovery, one page of posts, then the next page (or prune when done).
	 *
	 * @param int $after Post ID cursor.
	 * @return void
	 */
	public static function discover( $after = 0 ): void {
		global $wpdb;
		$after = (int) $after;
		$types = Extractor::post_types();
		$next  = 0;
		if ( $types ) {
			$items = $wpdb->prefix . 'zinn_chat_items';
			$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- core table + the plugin's own; $in is placeholders only.
			$page = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in}) AND ID > %d ORDER BY ID LIMIT 500", array_merge( $types, array( $after ) ) ) );
			if ( $page ) {
				$ids     = implode( ',', array_map( 'intval', $page ) );
				$changed = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN %i i ON i.object_type = 'post' AND i.object_id = p.ID WHERE p.ID IN ({$ids}) AND (i.id IS NULL OR i.status <> 'indexed' OR i.modified_gmt IS NULL OR p.post_modified_gmt > i.modified_gmt)", $items ) );
				foreach ( (array) $changed as $id ) {
					Index::mark_pending( 'post', (int) $id );
				}
				$next = (int) end( $page );
			}
			// phpcs:enable
		}
		if ( $next && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'zinn_chat_index_discover', array( $next ), self::GROUP );
			return;
		}
		// Posts done: the other object types (few), then prune and work.
		self::discover_others();
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'zinn_chat_index_prune', array(), self::GROUP );
		}
	}

	/**
	 * The wpForo topics and the WooCommerce store item.
	 *
	 * @return void
	 */
	private static function discover_others(): void {
		global $wpdb;
		if ( function_exists( 'WC' ) && Settings::get( 'index_woo', true ) ) {
			Index::mark_pending( 'woo_store', 1 );
		}
		if ( function_exists( 'WPF' ) && Settings::get( 'index_forums', true ) ) {
			$table = $wpdb->prefix . 'wpforo_topics';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpForo's own table, read-only.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists ) {
				$items = $wpdb->prefix . 'zinn_chat_items';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpForo's table and ours.
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT t.topicid FROM %i t LEFT JOIN %i i ON i.object_type = 'wpforo_topic' AND i.object_id = t.topicid WHERE t.status = 0 AND t.private = 0 AND (i.id IS NULL OR i.status <> 'indexed' OR t.modified > i.modified_gmt)", $table, $items ) );
				foreach ( (array) $ids as $id ) {
					Index::mark_pending( 'wpforo_topic', (int) $id );
				}
			}
		}
	}

	/**
	 * Action: remove items whose post is gone or no longer indexable, a page at a time.
	 *
	 * Walks the items by id with a cursor, so each run resumes where the last stopped and the whole
	 * index is covered however large it is (CLAUDE.md §2.10); the post type is checked here rather
	 * than in SQL so the statement stays fixed.
	 *
	 * @param int $after Last item id already checked.
	 * @return void
	 */
	public static function prune( int $after = 0 ): void {
		global $wpdb;
		$after = max( 0, $after );
		$items = $wpdb->prefix . 'zinn_chat_items';
		$types = Extractor::post_types();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's table + core posts.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT i.id, p.ID AS post_id, p.post_status, p.post_type FROM %i i LEFT JOIN %i p ON p.ID = i.object_id WHERE i.object_type = 'post' AND i.id > %d ORDER BY i.id LIMIT 500", $items, $wpdb->posts, $after ), ARRAY_A );
		$last = $after;
		foreach ( (array) $rows as $row ) {
			$last = (int) $row['id'];
			if ( empty( $row['post_id'] ) || 'publish' !== $row['post_status'] || ! in_array( $row['post_type'], $types, true ) ) {
				Index::delete_item( $last );
			}
		}
		if ( 500 === count( (array) $rows ) && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'zinn_chat_index_prune', array( $last ), self::GROUP );
			return;
		}
		self::kick_work();
	}

	/**
	 * Make sure one work chain is running.
	 *
	 * @return void
	 */
	public static function kick_work(): void {
		if ( function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' ) && ! as_has_scheduled_action( 'zinn_chat_index_work', null, self::GROUP ) ) {
			as_enqueue_async_action( 'zinn_chat_index_work', array(), self::GROUP );
		}
	}

	/**
	 * Action: index pending items and re-embed out-of-date ones within the budget; queue itself
	 * again while anything is left.
	 *
	 * @return void
	 */
	public static function work(): void {
		$start = microtime( true );
		do {
			$batch = self::next_batch();
			foreach ( $batch as $row ) {
				if ( 'reembed' === $row['job'] ) {
					Index::reembed( (int) $row['id'] );
				} else {
					Index::index_object( (string) $row['object_type'], (int) $row['object_id'] );
				}
				if ( microtime( true ) - $start > self::BUDGET ) {
					break 2;
				}
			}
		} while ( $batch );
		if ( self::remaining() > 0 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'zinn_chat_index_work', array(), self::GROUP );
		}
	}

	/**
	 * The next items to do: pending first (never-attempted first), then errors due a retry, then
	 * items whose vectors were built by another model.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function next_batch(): array {
		global $wpdb;
		$items = $wpdb->prefix . 'zinn_chat_items';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, object_id, 'index' AS job FROM %i WHERE status = 'pending' OR (status = 'error' AND attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)) ORDER BY (attempted_at IS NULL) DESC, attempted_at ASC LIMIT 10", $items ), ARRAY_A );
		if ( ! $rows ) {
			$model = Index::model_key();
			if ( '' !== $model && '' === Index::embed_blocked() ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, object_id, 'reembed' AS job FROM %i WHERE status = 'indexed' AND embed_model <> %s AND (attempted_at IS NULL OR attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)) ORDER BY attempted_at ASC LIMIT 10", $items, $model ), ARRAY_A );
			}
		}
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How much is left.
	 *
	 * @return int
	 */
	public static function remaining(): int {
		global $wpdb;
		$items = $wpdb->prefix . 'zinn_chat_items';
		$model = Index::model_key();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'pending'", $items ) );
		$stale   = ( '' !== $model && '' === Index::embed_blocked() ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'indexed' AND embed_model <> %s", $items, $model ) ) : 0;
		// phpcs:enable
		return $pending + $stale;
	}

	/**
	 * A post was saved.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( 'reply' === $post->post_type && $post->post_parent ) {
			self::enqueue( 'post', (int) $post->post_parent );
			return;
		}
		if ( 'product_variation' === $post->post_type && $post->post_parent ) {
			self::enqueue( 'post', (int) $post->post_parent );
			return;
		}
		if ( in_array( $post->post_type, Extractor::post_types(), true ) || Index::item( 'post', (int) $post_id ) ) {
			self::enqueue( 'post', (int) $post_id );
		}
	}

	/**
	 * A post was trashed or deleted: remove it now (no AI call needed).
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function on_delete_post( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post && 'reply' === $post->post_type && $post->post_parent ) {
			self::enqueue( 'post', (int) $post->post_parent );
			return;
		}
		Index::delete_object( 'post', (int) $post_id );
	}

	/**
	 * A product changed (price, stock, anything WooCommerce saves).
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public static function on_product( $product_id ): void {
		$parent = (int) wp_get_post_parent_id( (int) $product_id );
		self::enqueue( 'post', $parent ? $parent : (int) $product_id );
	}

	/**
	 * Stock changed (hook passes the product object).
	 *
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	public static function on_product_object( $product ): void {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$parent = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
			self::enqueue( 'post', $parent ? $parent : (int) $product->get_id() );
		}
	}

	/**
	 * Shipping or store settings changed.
	 *
	 * @return void
	 */
	public static function on_store(): void {
		self::enqueue( 'woo_store', 1 );
	}

	/**
	 * A page builder saved its layout into post meta.
	 *
	 * @param int    $meta_id  Meta id.
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Key.
	 * @return void
	 */
	public static function on_meta( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id );
		if ( in_array( (string) $meta_key, array( '_elementor_data', '_fl_builder_data', '_bricks_page_content_2', '_et_pb_old_content', '_pbs_content' ), true ) ) {
			self::enqueue( 'post', (int) $post_id );
		}
	}

	/**
	 * A hook that passes a post id.
	 *
	 * @param mixed $post_id Post id (or a builder's own argument).
	 * @return void
	 */
	public static function on_post_id( $post_id ): void {
		if ( is_numeric( $post_id ) && (int) $post_id > 0 ) {
			self::enqueue( 'post', (int) $post_id );
		}
	}

	/**
	 * A wpForo topic or post changed (hooks pass the row as an array).
	 *
	 * @param mixed $row Topic or post.
	 * @return void
	 */
	public static function on_wpforo( $row ): void {
		$topic = is_array( $row ) ? (int) ( $row['topicid'] ?? 0 ) : 0;
		if ( $topic ) {
			self::enqueue( 'wpforo_topic', $topic );
		}
	}
}
