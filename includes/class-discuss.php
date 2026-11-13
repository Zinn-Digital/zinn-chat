<?php
/**
 * Team discussions and the notification centre (2.14.0).
 *
 * Agents discuss a live chat, a contact (by e-mail address) or, in Pro, a knowledge base article
 * in a thread visitors never see. `@name` mentions another agent; every mention, reply, reaction
 * and assignment lands in that agent's notification centre (the bell in the inbox).
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules: what may be discussed, who can be mentioned, who is told, who reads what.
 */
final class Discuss {

	/** What a thread can be about. `kb` is a Zinn Chat Pro article. */
	public const OBJECTS = array( 'chat', 'contact', 'kb' );

	/** What a notification can say. */
	public const KINDS = array( 'mention', 'reply', 'reaction', 'assignment' );

	/** The reactions an agent can add, by key. */
	public const REACTIONS = array(
		'like'   => '👍',
		'thanks' => '🙏',
		'done'   => '✅',
		'look'   => '👀',
	);

	/** The longest message, in characters. */
	public const MAX = 4000;

	/** The Pro article post type (class-kb.php, Kb::TYPE). */
	private const KB_TYPE = 'zc_kb_article';

	/**
	 * Storage (replaceable in tests).
	 *
	 * @var Discuss_Store|null
	 */
	private static ?Discuss_Store $store = null;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'zinn_chat_ticket_assigned', array( self::class, 'assigned' ), 10, 3 );
		add_action( 'zinn_chat_pro_assigned', array( self::class, 'auto_assigned' ), 10, 2 );
	}

	/**
	 * The store.
	 *
	 * @return Discuss_Store
	 */
	public static function store(): Discuss_Store {
		if ( null === self::$store ) {
			self::$store = new Discuss_Store();
		}
		return self::$store;
	}

	/**
	 * Use another store (tests).
	 *
	 * @param Discuss_Store|null $store Store, or null for the real one.
	 * @return void
	 */
	public static function use_store( ?Discuss_Store $store ): void {
		self::$store = $store;
	}

	/**
	 * May threads of this type be read and written now? Article threads are Pro: when a trial
	 * ends unpaid they stop (CLAUDE.md §2.62) and their messages are kept for when Pro returns.
	 *
	 * @param string $type Object type.
	 * @return bool
	 */
	public static function type_open( string $type ): bool {
		if ( ! in_array( $type, self::OBJECTS, true ) ) {
			return false;
		}
		return 'kb' !== $type || Plugin::is_pro();
	}

	/**
	 * The stored key for an object, or '' when it is not one: a chat or article id, or a
	 * contact's e-mail address in lower case.
	 *
	 * @param string $type Object type.
	 * @param mixed  $key  Raw key.
	 * @return string
	 */
	public static function key( string $type, $key ): string {
		if ( 'contact' === $type ) {
			$email = strtolower( trim( (string) $key ) );
			return is_email( $email ) ? $email : '';
		}
		$id = (int) $key;
		return $id > 0 ? (string) $id : '';
	}

	/**
	 * Does the object exist (a chat, or an article)? A contact is any valid address.
	 *
	 * @param string $type Object type.
	 * @param string $key  Stored key.
	 * @return bool
	 */
	public static function exists( string $type, string $key ): bool {
		if ( 'chat' === $type ) {
			return null !== Conversations::get( (int) $key );
		}
		if ( 'kb' === $type ) {
			return self::KB_TYPE === get_post_type( (int) $key );
		}
		return '' !== $key;
	}

	/**
	 * May this user be mentioned (and told anything)? Only someone who can answer chats on this
	 * site: a mention can never reach a customer, a subscriber, or a user of another site.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function can_be_told( int $user_id ): bool {
		return $user_id > 0 && ( user_can( $user_id, Capabilities::ANSWER ) || user_can( $user_id, Capabilities::MANAGE ) );
	}

	/**
	 * The agents a message mentions: `@login` or `@nicename`, each once, never the author, and
	 * only agents (can_be_told).
	 *
	 * @param string $body      Message.
	 * @param int    $author_id Author.
	 * @return array<int, int>
	 */
	public static function mentions( string $body, int $author_id ): array {
		if ( ! preg_match_all( '/(?<![\w@.])@([A-Za-z0-9._-]{2,60})/u', $body, $found ) ) {
			return array();
		}
		$ids = array();
		foreach ( array_unique( $found[1] ) as $name ) {
			$name = rtrim( $name, '.' );
			$user = get_user_by( 'login', $name );
			if ( ! $user ) {
				$user = get_user_by( 'slug', strtolower( $name ) );
			}
			if ( ! $user ) {
				continue;
			}
			$id = (int) $user->ID;
			if ( $id !== $author_id && self::can_be_told( $id ) ) {
				$ids[ $id ] = $id;
			}
		}
		return array_values( $ids );
	}

	/**
	 * Post a message to a thread and tell who it concerns: each agent it mentions, and (as a
	 * reply) the author of the message it answers and everyone else already in the thread.
	 *
	 * @param string $type      Object type.
	 * @param string $key       Stored key.
	 * @param int    $author_id Author.
	 * @param string $body      Message.
	 * @param int    $parent_id The message it answers (0: none).
	 * @return int|\WP_Error New message id.
	 */
	public static function post( string $type, string $key, int $author_id, string $body, int $parent_id = 0 ) {
		if ( ! self::type_open( $type ) ) {
			return self::closed( $type );
		}
		$body = Util::clean_text( $body, self::MAX );
		if ( '' === $body ) {
			return new \WP_Error( 'empty', __( 'Write a message first.', 'zinn-chat' ) );
		}
		$store  = self::store();
		$before = $store->messages( $type, $key );
		if ( $parent_id ) {
			$parent = $store->message( $parent_id );
			if ( ! $parent || (string) $parent['object_type'] !== $type || (string) $parent['object_key'] !== $key ) {
				$parent_id = 0;
			}
		}
		$id = $store->add_message(
			array(
				'object_type' => $type,
				'object_key'  => $key,
				'parent_id'   => $parent_id,
				'user_id'     => $author_id,
				'body'        => $body,
				'reactions'   => '{}',
				'created_at'  => Util::now(),
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'not_saved', __( 'The message could not be saved. Please try again.', 'zinn-chat' ) );
		}
		$told = array();
		foreach ( self::mentions( $body, $author_id ) as $user_id ) {
			self::tell( $user_id, 'mention', $type, $key, $id, $author_id, $body );
			$told[ $user_id ] = true;
		}
		foreach ( $before as $row ) {
			$user_id = (int) $row['user_id'];
			if ( $user_id === $author_id || isset( $told[ $user_id ] ) || ! self::can_be_told( $user_id ) ) {
				continue;
			}
			self::tell( $user_id, 'reply', $type, $key, $id, $author_id, $body );
			$told[ $user_id ] = true;
		}
		return $id;
	}

	/**
	 * Add or take back a reaction. Adding one tells the message's author.
	 *
	 * @param int    $message_id Message.
	 * @param int    $user_id    Agent reacting.
	 * @param string $reaction   A key of REACTIONS.
	 * @return array<string, array<int, int>>|\WP_Error The message's reactions now.
	 */
	public static function react( int $message_id, int $user_id, string $reaction ) {
		if ( ! isset( self::REACTIONS[ $reaction ] ) ) {
			return new \WP_Error( 'reaction', __( 'That reaction is not available.', 'zinn-chat' ) );
		}
		$store = self::store();
		$row   = $store->message( $message_id );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', __( 'Not found.', 'zinn-chat' ) );
		}
		$type = (string) $row['object_type'];
		if ( ! self::type_open( $type ) ) {
			return self::closed( $type );
		}
		$all  = self::reactions( (string) $row['reactions'] );
		$list = $all[ $reaction ] ?? array();
		if ( in_array( $user_id, $list, true ) ) {
			$list  = array_values( array_diff( $list, array( $user_id ) ) );
			$added = false;
		} else {
			$list[] = $user_id;
			$added  = true;
		}
		if ( $list ) {
			$all[ $reaction ] = $list;
		} else {
			unset( $all[ $reaction ] );
		}
		$store->set_reactions( $message_id, (string) wp_json_encode( (object) $all ) );
		$author = (int) $row['user_id'];
		if ( $added && $author !== $user_id && self::can_be_told( $author ) ) {
			self::tell( $author, 'reaction', $type, (string) $row['object_key'], $message_id, $user_id, self::REACTIONS[ $reaction ] . ' ' . (string) $row['body'] );
		}
		return $all;
	}

	/**
	 * Stored reactions, decoded and checked.
	 *
	 * @param string $json Stored JSON.
	 * @return array<string, array<int, int>>
	 */
	public static function reactions( string $json ): array {
		$raw = json_decode( $json, true );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $reaction => $ids ) {
			if ( isset( self::REACTIONS[ $reaction ] ) && is_array( $ids ) ) {
				$out[ (string) $reaction ] = array_values( array_unique( array_map( 'intval', $ids ) ) );
			}
		}
		return $out;
	}

	/**
	 * A ticket was assigned to an agent by someone else: tell them.
	 *
	 * @param int $ticket_id Ticket.
	 * @param int $agent_id  New assignee.
	 * @param int $by        Who assigned it (0: the routing rule).
	 * @return void
	 */
	public static function assigned( $ticket_id, $agent_id, $by = 0 ): void {
		$agent_id = (int) $agent_id;
		if ( ! $agent_id || $agent_id === (int) $by || ! self::can_be_told( $agent_id ) ) {
			return;
		}
		$ticket = Tickets::get( (int) $ticket_id );
		self::tell( $agent_id, 'assignment', 'ticket', (string) (int) $ticket_id, 0, (int) $by, $ticket ? (string) $ticket['subject'] : '' );
	}

	/**
	 * Pro's routing rule assigned a ticket.
	 *
	 * @param int $ticket_id Ticket.
	 * @param int $agent_id  Agent.
	 * @return void
	 */
	public static function auto_assigned( $ticket_id, $agent_id ): void {
		self::assigned( $ticket_id, $agent_id, 0 );
	}

	/**
	 * Write one notification.
	 *
	 * @param int    $user_id    Who it is for.
	 * @param string $kind       A KINDS value.
	 * @param string $type       Object type (or `ticket` for an assignment).
	 * @param string $key        Object key.
	 * @param int    $message_id Discussion message (0: none).
	 * @param int    $actor_id   Who did it (0: the plugin).
	 * @param string $text       What it says.
	 * @return void
	 */
	private static function tell( int $user_id, string $kind, string $type, string $key, int $message_id, int $actor_id, string $text ): void {
		self::store()->add_notification(
			array(
				'user_id'     => $user_id,
				'kind'        => $kind,
				'object_type' => $type,
				'object_key'  => $key,
				'message_id'  => $message_id,
				'actor_id'    => $actor_id,
				'excerpt'     => mb_substr( $text, 0, 190 ),
				'created_at'  => Util::now(),
			)
		);
	}

	/**
	 * An agent's notification centre. Only their own rows are ever returned.
	 *
	 * @param int $user_id Agent.
	 * @return array{rows: array<int, array<string, mixed>>, unread: int}
	 */
	public static function centre( int $user_id ): array {
		$store = self::store();
		$rows  = array();
		foreach ( $store->notifications( $user_id ) as $row ) {
			if ( (int) $row['user_id'] !== $user_id ) {
				continue;
			}
			$actor  = (int) $row['actor_id'] ? get_userdata( (int) $row['actor_id'] ) : null;
			$rows[] = array(
				'id'          => (int) $row['id'],
				'kind'        => (string) $row['kind'],
				'object_type' => (string) $row['object_type'],
				'object_key'  => (string) $row['object_key'],
				'message_id'  => (int) $row['message_id'],
				'actor'       => $actor ? (string) $actor->display_name : '',
				'text'        => (string) $row['excerpt'],
				'read'        => ! empty( $row['read_at'] ),
				'at'          => Util::iso( (string) $row['created_at'] ),
			);
		}
		return array(
			'rows'   => $rows,
			'unread' => $store->unread( $user_id ),
		);
	}

	/**
	 * Mark notifications read (the ids given, or all). Only the agent's own are touched.
	 *
	 * @param int             $user_id Agent.
	 * @param array<int, int> $ids     Ids (empty: all).
	 * @return void
	 */
	public static function read( int $user_id, array $ids ): void {
		self::store()->mark_read( $user_id, array_values( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * A thread for the console: messages with author names and reactions.
	 *
	 * @param string $type Object type.
	 * @param string $key  Stored key.
	 * @return array<int, array<string, mixed>>
	 */
	public static function thread( string $type, string $key ): array {
		$out = array();
		foreach ( self::store()->messages( $type, $key ) as $row ) {
			$author = get_userdata( (int) $row['user_id'] );
			$out[]  = array(
				'id'        => (int) $row['id'],
				'parent_id' => (int) $row['parent_id'],
				'user_id'   => (int) $row['user_id'],
				'author'    => $author ? (string) $author->display_name : '',
				'text'      => (string) $row['body'],
				'reactions' => (object) self::reactions( (string) $row['reactions'] ),
				'at'        => Util::iso( (string) $row['created_at'] ),
			);
		}
		return $out;
	}

	/**
	 * The refusal for a type that is not open: article threads when Pro is not active.
	 *
	 * @param string $type Object type.
	 * @return \WP_Error
	 */
	public static function closed( string $type ): \WP_Error {
		if ( 'kb' === $type ) {
			return new \WP_Error( 'pro_required', __( 'Discussions on knowledge base articles are part of Zinn® Chat Pro. Your earlier discussions are kept and come back when Pro is active again.', 'zinn-chat' ), array( 'status' => 403 ) );
		}
		return new \WP_Error( 'type', __( 'That cannot be discussed.', 'zinn-chat' ), array( 'status' => 400 ) );
	}
}
