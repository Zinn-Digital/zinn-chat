<?php
/**
 * The agent REST API: the inbox console, tickets, and the assistant test console.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

use ZinnDigital\ZinnChat\Index\Index;
use ZinnDigital\ZinnChat\Index\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/wp-json/zinn-chat/v1/agent/…`. Cookie + nonce authentication; every route requires
 * `zinn_chat_answer` (agents) and the index-rebuild routes `zinn_chat_manage`.
 */
final class Agent_Rest {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Permission: an agent.
	 *
	 * @return bool
	 */
	public static function can_answer(): bool {
		return Capabilities::can_answer();
	}

	/**
	 * Permission: a manager.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return Capabilities::can_manage();
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		$answer = array( self::class, 'can_answer' );
		$manage = array( self::class, 'can_manage' );
		$routes = array(
			array( 'agent/inbox', 'GET', 'inbox', $answer ),
			array( 'agent/presence', 'POST', 'presence', $answer ),
			array( 'agent/chat/(?P<id>\d+)', 'GET', 'chat', $answer ),
			array( 'agent/chat/(?P<id>\d+)/take', 'POST', 'take', $answer ),
			array( 'agent/chat/(?P<id>\d+)/reply', 'POST', 'reply', $answer ),
			array( 'agent/chat/(?P<id>\d+)/typing', 'POST', 'typing', $answer ),
			array( 'agent/chat/(?P<id>\d+)/close', 'POST', 'close', $answer ),
			array( 'agent/chat/(?P<id>\d+)/bot', 'POST', 'to_bot', $answer ),
			array( 'agent/chat/(?P<id>\d+)/ticket', 'POST', 'to_ticket', $answer ),
			array( 'agent/tickets', 'GET', 'tickets', $answer ),
			array( 'agent/tickets', 'POST', 'ticket_new', $answer ),
			array( 'agent/tickets/(?P<id>\d+)', 'GET', 'ticket', $answer ),
			array( 'agent/tickets/(?P<id>\d+)/reply', 'POST', 'ticket_reply', $answer ),
			array( 'agent/tickets/(?P<id>\d+)/update', 'POST', 'ticket_update', $answer ),
			array( 'agent/tickets/(?P<id>\d+)', 'DELETE', 'ticket_delete', $manage ),
			array( 'agent/assistant/ask', 'POST', 'ask', $answer ),
			array( 'agent/index', 'GET', 'index_list', $answer ),
			array( 'agent/index/reindex', 'POST', 'reindex', $manage ),
			array( 'agent/index/reembed', 'POST', 'reembed', $manage ),
		);
		foreach ( $routes as $route ) {
			register_rest_route(
				Rest::NS,
				'/' . $route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( self::class, $route[2] ),
					'permission_callback' => $route[3],
				)
			);
		}
	}

	/**
	 * Not found.
	 *
	 * @return \WP_REST_Response
	 */
	private static function missing(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => __( 'Not found.', 'zinn-chat' ),
			),
			404
		);
	}

	/**
	 * A conversation row for the inbox list.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function list_row( array $row ): array {
		$last  = Conversations::recent( (int) $row['id'], 1 );
		$agent = (int) $row['agent_id'] ? get_userdata( (int) $row['agent_id'] ) : null;
		return array(
			'id'             => (int) $row['id'],
			'status'         => (string) $row['status'],
			'name'           => (string) $row['name'],
			'email'          => (string) $row['email'],
			'page_url'       => (string) $row['page_url'],
			'language'       => (string) $row['language'],
			'source_site'    => (string) $row['source_site'],
			'unread'         => (int) $row['unread'],
			'agent_id'       => (int) $row['agent_id'],
			'agent'          => $agent ? (string) $agent->display_name : '',
			'last'           => $last ? mb_substr( (string) $last[0]['body'], 0, 140 ) : '',
			'last_role'      => $last ? (string) $last[0]['role'] : '',
			'visitor_typing' => ! empty( $row['visitor_typing_at'] ) && strtotime( (string) $row['visitor_typing_at'] . ' UTC' ) > time() - 8,
			'waiting_since'  => Util::iso( (string) $row['handoff_at'] ),
			'updated_at'     => Util::iso( (string) $row['updated_at'] ),
			'created_at'     => Util::iso( (string) $row['created_at'] ),
			'ticket_id'      => (int) $row['ticket_id'],
		);
	}

	/**
	 * GET agent/inbox: the chat list (also records presence).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function inbox( \WP_REST_Request $request ): \WP_REST_Response {
		$user = get_current_user_id();
		Presence::beat( $user, Presence::is_away( $user ) );
		$view = in_array( $request->get_param( 'view' ), array( 'active', 'bot', 'closed', 'all' ), true ) ? (string) $request->get_param( 'view' ) : 'active';
		$rows = array_map( array( self::class, 'list_row' ), Conversations::for_console( $view, 100 ) );
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'rows'    => $rows,
				'counts'  => Conversations::counts(),
				'tickets' => Tickets::counts(),
				'online'  => Presence::online(),
				'away'    => Presence::is_away( $user ),
				'me'      => $user,
			)
		);
	}

	/**
	 * POST agent/presence {away}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function presence( \WP_REST_Request $request ): \WP_REST_Response {
		Presence::beat( get_current_user_id(), (bool) $request->get_param( 'away' ) );
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * GET agent/chat/{id}?after=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function chat( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = (int) $request['id'];
		$row = Conversations::get( $id );
		if ( ! $row ) {
			return self::missing();
		}
		$after    = (int) $request->get_param( 'after' );
		$messages = array_map( array( Conversations::class, 'public_message' ), Conversations::messages( $id, $after, true ) );
		if ( get_current_user_id() === (int) $row['agent_id'] || 'waiting' === $row['status'] ) {
			Conversations::update(
				$id,
				array(
					'unread'     => 0,
					'updated_at' => $row['updated_at'],
				)
			);
		}
		$data             = self::list_row( $row );
		$data['messages'] = $messages;
		$data['user_id']  = (int) $row['user_id'];
		$data['orders']   = (int) $row['user_id'] && function_exists( 'wc_get_orders' ) ? Woo::order_choices( (int) $row['user_id'] ) : array();
		/**
		 * Filters the chat payload for the agent console (Pro adds visitor details, notes, KB suggestions).
		 *
		 * @param array<string, mixed> $data Payload.
		 * @param array<string, mixed> $row  Conversation.
		 */
		return new \WP_REST_Response( array( 'ok' => true ) + (array) apply_filters( 'zinn_chat_agent_chat', $data, $row ) );
	}

	/**
	 * POST agent/chat/{id}/take.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function take( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = (int) $request['id'];
		$row = Conversations::get( $id );
		if ( ! $row ) {
			return self::missing();
		}
		$user = wp_get_current_user();
		Conversations::set_status( $id, 'staff', array( 'agent_id' => (int) $user->ID ) );
		/* translators: %s: agent name. */
		Conversations::add_message( $id, 'system', sprintf( __( '%s joined the chat.', 'zinn-chat' ), $user->display_name ) );
		return self::chat( $request );
	}

	/**
	 * POST agent/chat/{id}/reply {text, note}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function reply( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = (int) $request['id'];
		$row = Conversations::get( $id );
		if ( ! $row ) {
			return self::missing();
		}
		$text = Util::clean_text( (string) $request->get_param( 'text' ), 10000 );
		if ( '' === $text ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'empty',
					'message' => __( 'Type a reply first.', 'zinn-chat' ),
				),
				400
			);
		}
		$user = get_current_user_id();
		if ( $request->get_param( 'note' ) ) {
			Conversations::add_message( $id, 'note', $text, $user );
		} else {
			if ( in_array( $row['status'], array( 'bot', 'waiting', 'offline', 'closed' ), true ) ) {
				Conversations::set_status( $id, 'staff', array( 'agent_id' => $row['agent_id'] ? (int) $row['agent_id'] : $user ) );
			}
			Conversations::add_message( $id, 'agent', $text, $user );
		}
		return self::chat( $request );
	}

	/**
	 * POST agent/chat/{id}/typing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function typing( \WP_REST_Request $request ): \WP_REST_Response {
		$row = Conversations::get( (int) $request['id'] );
		if ( $row ) {
			Conversations::update(
				(int) $row['id'],
				array(
					'agent_typing_at' => Util::now(),
					'updated_at'      => $row['updated_at'],
				)
			);
		}
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * POST agent/chat/{id}/close.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function close( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request['id'];
		if ( ! Conversations::get( $id ) ) {
			return self::missing();
		}
		Conversations::add_message( $id, 'system', __( 'The chat has ended. Thank you for contacting us.', 'zinn-chat' ) );
		Conversations::set_status( $id, 'closed' );
		return self::chat( $request );
	}

	/**
	 * POST agent/chat/{id}/bot: hand the chat back to the assistant.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function to_bot( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request['id'];
		if ( ! Conversations::get( $id ) ) {
			return self::missing();
		}
		Conversations::set_status( $id, 'bot', array( 'agent_id' => 0 ) );
		Conversations::add_message( $id, 'system', __( 'You are chatting with the assistant again.', 'zinn-chat' ) );
		return self::chat( $request );
	}

	/**
	 * POST agent/chat/{id}/ticket: turn the chat into a ticket (needs the visitor's email).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function to_ticket( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = (int) $request['id'];
		$row = Conversations::get( $id );
		if ( ! $row ) {
			return self::missing();
		}
		$email = '' !== (string) $row['email'] ? (string) $row['email'] : sanitize_email( (string) $request->get_param( 'email' ) );
		$lines = array();
		foreach ( Conversations::messages( $id ) as $message ) {
			if ( in_array( $message['role'], array( 'visitor', 'ai', 'agent' ), true ) ) {
				$lines[] = $message['role'] . ': ' . $message['body'];
			}
		}
		$made = Tickets::create(
			array(
				'subject'         => (string) $request->get_param( 'subject' ),
				'body'            => implode( "\n", $lines ),
				'name'            => (string) $row['name'],
				'email'           => $email,
				'user_id'         => (int) $row['user_id'],
				'conversation_id' => $id,
				'channel'         => 'chat',
				'language'        => (string) $row['language'],
			)
		);
		if ( is_wp_error( $made ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => $made->get_error_code(),
					'message' => $made->get_error_message(),
				),
				400
			);
		}
		Conversations::update( $id, array( 'ticket_id' => $made['id'] ) );
		/* translators: %d: ticket number. */
		Conversations::add_message( $id, 'system', sprintf( __( 'This conversation is now ticket #%d. We will reply by email.', 'zinn-chat' ), $made['id'] ) );
		return self::chat( $request );
	}

	/**
	 * GET agent/tickets?status=&search=&page=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function tickets( \WP_REST_Request $request ): \WP_REST_Response {
		$found = Tickets::query(
			array(
				'status' => (string) ( $request->get_param( 'status' ) ?? 'active' ),
				'search' => (string) $request->get_param( 'search' ),
				'page'   => max( 1, (int) $request->get_param( 'page' ) ),
			)
		);
		return new \WP_REST_Response(
			array(
				'ok'     => true,
				'rows'   => array_map( array( Tickets::class, 'public_ticket' ), $found['rows'] ),
				'total'  => $found['total'],
				'counts' => Tickets::counts(),
			)
		);
	}

	/**
	 * GET agent/tickets/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ticket( \WP_REST_Request $request ): \WP_REST_Response {
		$ticket = Tickets::get( (int) $request['id'] );
		if ( ! $ticket ) {
			return self::missing();
		}
		if ( (int) $ticket['unread'] ) {
			Tickets::update( (int) $ticket['id'], array( 'unread' => 0 ) );
		}
		$replies = array();
		foreach ( Tickets::replies( (int) $ticket['id'], true ) as $reply ) {
			$user      = (int) $reply['user_id'] ? get_userdata( (int) $reply['user_id'] ) : null;
			$replies[] = array(
				'id'     => (int) $reply['id'],
				'author' => (string) $reply['author'],
				'name'   => $user ? (string) $user->display_name : ( 'customer' === $reply['author'] ? (string) $ticket['name'] : '' ),
				'text'   => (string) $reply['body'],
				'html'   => Util::render_text( (string) $reply['body'] ),
				'via'    => (string) $reply['via'],
				'at'     => Util::iso( (string) $reply['created_at'] ),
			);
		}
		$order = array();
		if ( (int) $ticket['order_id'] && function_exists( 'wc_get_order' ) ) {
			$wc = wc_get_order( (int) $ticket['order_id'] );
			if ( $wc ) {
				$order = array(
					'id'     => (int) $wc->get_id(),
					'number' => (string) $wc->get_order_number(),
					'status' => wc_get_order_status_name( $wc->get_status() ),
					'total'  => html_entity_decode( wp_strip_all_tags( $wc->get_formatted_order_total() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'url'    => (string) $wc->get_edit_order_url(),
				);
			}
		}
		$data = array(
			'ok'      => true,
			'ticket'  => Tickets::public_ticket( $ticket ),
			'replies' => $replies,
			'order'   => $order,
			'agents'  => array_map(
				static function ( \WP_User $u ): array {
					return array(
						'id'   => (int) $u->ID,
						'name' => (string) $u->display_name,
					);
				},
				Capabilities::agents()
			),
		);
		/**
		 * Filters the ticket payload for wp-admin (Pro adds canned replies, SLA, KB suggestions).
		 *
		 * @param array<string, mixed> $data   Payload.
		 * @param array<string, mixed> $ticket Ticket.
		 */
		return new \WP_REST_Response( (array) apply_filters( 'zinn_chat_agent_ticket', $data, $ticket ) );
	}

	/**
	 * POST agent/tickets: open a ticket on a customer's behalf.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ticket_new( \WP_REST_Request $request ): \WP_REST_Response {
		$made = Tickets::create(
			array(
				'subject' => (string) $request->get_param( 'subject' ),
				'body'    => (string) $request->get_param( 'body' ),
				'name'    => (string) $request->get_param( 'name' ),
				'email'   => (string) $request->get_param( 'email' ),
				'channel' => 'admin',
			)
		);
		if ( is_wp_error( $made ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => $made->get_error_code(),
					'message' => $made->get_error_message(),
				),
				400
			);
		}
		return new \WP_REST_Response(
			array(
				'ok' => true,
				'id' => $made['id'],
			)
		);
	}

	/**
	 * POST agent/tickets/{id}/reply {text, note, status}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ticket_reply( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request['id'];
		$made = Tickets::reply( $id, $request->get_param( 'note' ) ? 'note' : 'agent', (string) $request->get_param( 'text' ), get_current_user_id(), 'web', (string) $request->get_param( 'status' ) );
		if ( is_wp_error( $made ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => $made->get_error_code(),
					'message' => $made->get_error_message(),
				),
				400
			);
		}
		return self::ticket( $request );
	}

	/**
	 * POST agent/tickets/{id}/update {status, priority, assignee_id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ticket_update( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request['id'];
		if ( ! Tickets::get( $id ) ) {
			return self::missing();
		}
		if ( null !== $request->get_param( 'status' ) ) {
			Tickets::set_status( $id, (string) $request->get_param( 'status' ) );
		}
		$fields = array();
		if ( in_array( $request->get_param( 'priority' ), Tickets::PRIORITIES, true ) ) {
			$fields['priority'] = (string) $request->get_param( 'priority' );
		}
		if ( null !== $request->get_param( 'assignee_id' ) ) {
			$assignee = (int) $request->get_param( 'assignee_id' );
			if ( 0 === $assignee || user_can( $assignee, Capabilities::ANSWER ) || user_can( $assignee, Capabilities::MANAGE ) ) {
				$fields['assignee_id'] = $assignee;
			}
		}
		if ( $fields ) {
			Tickets::update( $id, $fields );
		}
		return self::ticket( $request );
	}

	/**
	 * DELETE agent/tickets/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ticket_delete( \WP_REST_Request $request ): \WP_REST_Response {
		Privacy::delete_tickets( array( (int) $request['id'] ) );
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * POST agent/assistant/ask {question}: the test console.
	 *
	 * Returns the answer AND every source the search found (not only the ones the answer used),
	 * with score, URL, title, last modified, snippet, and the old/outdated flags, so the site owner
	 * can see exactly why the assistant said what it said.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ask( \WP_REST_Request $request ): \WP_REST_Response {
		$question = Util::clean_text( (string) $request->get_param( 'question' ), 2000 );
		if ( '' === $question ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Type a question first.', 'zinn-chat' ),
				),
				400
			);
		}
		$answer  = Assistant::answer(
			$question,
			array(
				'purpose'  => 'zinn-chat-test',
				'language' => (string) $request->get_param( 'language' ),
			)
		);
		$sources = array();
		foreach ( $answer['search']['sources'] as $source ) {
			$item           = Index::get_item( (int) $source['item_id'] );
			$source['used'] = false;
			foreach ( $answer['sources'] as $cited ) {
				if ( $cited['url'] === $source['url'] ) {
					$source['used'] = true;
				}
			}
			$source['object_type'] = $item ? (string) $item['object_type'] : '';
			$source['object_id']   = $item ? (int) $item['object_id'] : 0;
			$source['indexed_at']  = $item ? Util::iso( (string) $item['indexed_at'] ) : '';
			unset( $source['text'] );
			$sources[] = $source;
		}
		return new \WP_REST_Response(
			array(
				'ok'      => null === $answer['failure'],
				'answer'  => $answer['text'],
				'html'    => Util::render_text( $answer['text'] ),
				'status'  => $answer['status'],
				'mode'    => $answer['search']['mode'],
				'error'   => $answer['search']['error'],
				'failure' => $answer['failure'],
				'sources' => $sources,
				'tokens'  => array( $answer['input_tokens'], $answer['output_tokens'] ),
			)
		);
	}

	/**
	 * GET agent/index?search=&status=&page=: indexed content and the index numbers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function index_list( \WP_REST_Request $request ): \WP_REST_Response {
		$found = Index::list_items( (string) $request->get_param( 'search' ), (string) $request->get_param( 'status' ), max( 1, (int) $request->get_param( 'page' ) ) );
		$rows  = array();
		foreach ( $found['rows'] as $row ) {
			$rows[] = array(
				'id'          => (int) $row['id'],
				'object_type' => (string) $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'kind'        => (string) $row['kind'],
				'title'       => (string) $row['title'],
				'url'         => (string) $row['url'],
				'status'      => (string) $row['status'],
				'chunks'      => (int) $row['chunk_count'],
				'semantic'    => '' !== (string) $row['embed_model'],
				'error'       => (string) $row['error'],
				'modified'    => Util::iso( (string) $row['modified_gmt'] ),
				'indexed_at'  => Util::iso( (string) $row['indexed_at'] ),
				'edit_url'    => 'post' === $row['object_type'] ? (string) get_edit_post_link( (int) $row['object_id'], 'raw' ) : '',
			);
		}
		return new \WP_REST_Response(
			array(
				'ok'        => true,
				'rows'      => $rows,
				'total'     => $found['total'],
				'stats'     => Index::stats(),
				'remaining' => Queue::remaining(),
			)
		);
	}

	/**
	 * POST agent/index/reindex {item_id?, all?}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function reindex( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $request->get_param( 'all' ) ) {
			Queue::manual();
			Index::mark_all_stale();
			Queue::start_sweep();
			Queue::kick_work();
			return new \WP_REST_Response(
				array(
					'ok'      => true,
					'message' => __( 'The whole site is being read again in the background.', 'zinn-chat' ),
				)
			);
		}
		$item = Index::get_item( (int) $request->get_param( 'item_id' ) );
		if ( ! $item ) {
			return self::missing();
		}
		$result = Index::index_object( (string) $item['object_type'], (int) $item['object_id'], true );
		return new \WP_REST_Response(
			array(
				'ok'     => 'error' !== $result,
				'result' => $result,
				'item'   => Index::get_item( (int) $item['id'] ) ? true : false,
			)
		);
	}

	/**
	 * POST agent/index/reembed {item_id?, all?}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function reembed( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $request->get_param( 'all' ) ) {
			Queue::manual();
			Index::mark_all_reembed();
			Queue::kick_work();
			return new \WP_REST_Response(
				array(
					'ok'      => true,
					'message' => __( 'Meanings are being rebuilt in the background.', 'zinn-chat' ),
				)
			);
		}
		delete_transient( 'zinn_chat_embed_blocked' );
		$ok = Index::reembed( (int) $request->get_param( 'item_id' ) );
		return new \WP_REST_Response( array( 'ok' => $ok ) );
	}
}
