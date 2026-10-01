<?php
/**
 * The visitor-facing REST API (the chat widget and the ticket forms).
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/wp-json/zinn-chat/v1/…`, all POST so a visitor's chat token never sits in a URL or a log.
 *
 * A visitor is identified by the random token the widget keeps (sessionStorage for the tab,
 * localStorage when they chose "remember me" by giving an email). A signed-in user is identified
 * by WordPress's own cookie + REST nonce, which the page only carries for signed-in users (so a
 * cached public page never holds one).
 *
 * ⛔ Every endpoint is public by design and therefore checks everything itself: honeypot,
 * a Pro human check (when one is added), the owner's block list, per-address rate limits, message length, and that
 * the token owns the conversation. Refusals answer with a machine `code` and a translated
 * `message`, never a stack trace.
 */
final class Rest {

	public const NS = 'zinn-chat/v1';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'zinn_chat_waiting_ping', array( self::class, 'waiting_ping' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		// ⛔ A visitor has no WordPress account, so these cannot ask for a capability. Each route
		// names the check that stands in for one: `null` is a route anybody may call (starting a
		// chat, sending the ticket form, asking for a fresh link: guarded by the honeypot, the
		// block list and per-address rate limits in guard(), and listed with its reason in
		// wp/tests/wp-integration/access-allowlist.json); the others are refused by WordPress
		// itself unless the request carries the secret that owns the conversation or the ticket.
		$public = array(
			'chat/status'     => array( 'status', null ),
			'chat/message'    => array( 'message', null ),
			'chat/ticket'     => array( 'chat_ticket', null ),
			'tickets'         => array( 'ticket_create', null ),
			'tickets/link'    => array( 'ticket_link', null ),
			'chat/poll'       => array( 'poll', 'owns_conversation' ),
			'chat/human'      => array( 'human', 'owns_conversation' ),
			'chat/typing'     => array( 'typing', 'owns_conversation' ),
			'chat/close'      => array( 'close', 'owns_conversation' ),
			'chat/rate'       => array( 'rate', 'owns_conversation' ),
			'chat/transcript' => array( 'transcript', 'owns_conversation' ),
			'tickets/view'    => array( 'ticket_view', 'may_see_ticket' ),
			'tickets/reply'   => array( 'ticket_reply', 'may_see_ticket' ),
		);
		foreach ( $public as $route => $handler ) {
			register_rest_route(
				self::NS,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, $handler[0] ),
					'permission_callback' => null === $handler[1] ? '__return_true' : array( self::class, $handler[1] ),
				)
			);
		}
	}

	/**
	 * Permission: the request carries the token of a conversation (the visitor's own chat).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function owns_conversation( \WP_REST_Request $request ) {
		if ( self::conversation( $request ) ) {
			return true;
		}
		return new \WP_Error( 'unknown', __( 'This chat has ended.', 'zinn-chat' ), array( 'status' => 404 ) );
	}

	/**
	 * Permission: the request may see the ticket it names (its private link's token, or its
	 * signed-in owner). The same answers as before, now given by WordPress before the handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function may_see_ticket( \WP_REST_Request $request ) {
		$ticket = self::visible_ticket( $request );
		if ( $ticket instanceof \WP_REST_Response ) {
			$data = (array) $ticket->get_data();
			return new \WP_Error( (string) $data['code'], (string) $data['message'], array( 'status' => $ticket->get_status() ) );
		}
		return true;
	}

	/**
	 * A refusal.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Translated message.
	 * @param int    $status  HTTP status.
	 * @return \WP_REST_Response
	 */
	private static function refuse( string $code, string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'      => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}

	/**
	 * The local help desk is on (connected sites use the hosted API instead).
	 *
	 * @return bool
	 */
	private static function open(): bool {
		return ! Settings::connected() && (bool) Settings::get( 'enabled', false );
	}

	/**
	 * Common abuse checks for anything a visitor sends.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $action  Rate-limit bucket.
	 * @param string           $text    Text to check against the block list.
	 * @param string           $email   Email to check against the block list.
	 * @return \WP_REST_Response|null A refusal, or null when all is well.
	 */
	private static function guard( \WP_REST_Request $request, string $action, string $text = '', string $email = '' ): ?\WP_REST_Response {
		$ip = Util::client_ip();
		if ( Spam::honeypot_tripped( (array) $request->get_params() ) ) {
			return self::refuse( 'spam', __( 'Your message could not be sent.', 'zinn-chat' ) );
		}
		if ( Spam::blocked( $ip, $email, $text ) ) {
			return self::refuse( 'blocked', __( 'Your message could not be sent.', 'zinn-chat' ), 403 );
		}
		if ( Spam::over_limit( $action, '' !== $ip ? $ip : 'unknown' ) ) {
			return self::refuse( 'rate_limited', __( 'You are sending messages too quickly. Please wait a little and try again.', 'zinn-chat' ), 429 );
		}
		return null;
	}

	/**
	 * The conversation a request's token owns.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|null
	 */
	private static function conversation( \WP_REST_Request $request ): ?array {
		return Conversations::by_token( (string) $request->get_param( 'token' ) );
	}

	/**
	 * What the widget needs after any change.
	 *
	 * @param array<string, mixed> $conversation Conversation.
	 * @param int                  $after        Messages after this id.
	 * @return array<string, mixed>
	 */
	private static function state( array $conversation, int $after ): array {
		$messages = array_map( array( Conversations::class, 'public_message' ), Conversations::messages( (int) $conversation['id'], $after ) );
		$agent    = '';
		if ( (int) $conversation['agent_id'] ) {
			$user  = get_userdata( (int) $conversation['agent_id'] );
			$agent = $user ? (string) $user->display_name : '';
		}
		$typing = ! empty( $conversation['agent_typing_at'] ) && strtotime( (string) $conversation['agent_typing_at'] . ' UTC' ) > time() - 8;
		return array(
			'ok'           => true,
			'status'       => (string) $conversation['status'],
			'messages'     => $messages,
			'agent'        => $agent,
			'agent_typing' => $typing,
			'online'       => Presence::team_online(),
			'offer'        => self::offer( $conversation ),
		);
	}

	/**
	 * Which next steps to offer the visitor.
	 *
	 * @param array<string, mixed> $conversation Conversation.
	 * @return array{human: bool, ticket: bool}
	 */
	private static function offer( array $conversation ): array {
		$status  = (string) $conversation['status'];
		$tickets = (bool) Settings::get( 'tickets_enabled', true );
		$waiting = 'waiting' === $status && ! empty( $conversation['handoff_at'] ) && strtotime( (string) $conversation['handoff_at'] . ' UTC' ) < time() - 120;
		return array(
			'human'  => 'bot' === $status && (bool) Settings::get( 'human_enabled', true ),
			'ticket' => $tickets && ( 'bot' === $status || $waiting || 'offline' === $status ) && 0 === (int) $conversation['ticket_id'],
		);
	}

	/**
	 * POST chat/status: the words and switches the opened chat needs (one request, on open).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function status( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		if ( ! self::open() ) {
			return self::refuse( 'closed', __( 'The chat is not available right now.', 'zinn-chat' ), 403 );
		}
		$response = new \WP_REST_Response( Widget::status() );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST chat/message: a visitor message (starts the chat when there is no token yet).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function message( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::open() ) {
			return self::refuse( 'closed', __( 'The chat is not available right now.', 'zinn-chat' ), 403 );
		}
		$text = Util::clean_text( (string) $request->get_param( 'text' ), (int) Settings::get( 'max_chars', 2000 ) );
		if ( '' === $text ) {
			return self::refuse( 'empty', __( 'Please type a message.', 'zinn-chat' ) );
		}
		$refusal = self::guard( $request, 'message', $text );
		if ( $refusal ) {
			return $refusal;
		}
		$conversation = self::conversation( $request );
		$token        = (string) $request->get_param( 'token' );
		$after        = (int) $request->get_param( 'after' );
		if ( ! $conversation ) {
			if ( Settings::get( 'consent_required', true ) && ! $request->get_param( 'consent' ) ) {
				return self::refuse( 'consent', __( 'Please agree to the privacy notice to start the chat.', 'zinn-chat' ) );
			}
			$ip = Util::client_ip();
			if ( ! Spam::challenge_ok( $request->get_params(), $ip, 'chat' ) ) {
				return self::refuse( 'challenge', __( 'Please complete the check to show you are not a robot.', 'zinn-chat' ) );
			}
			if ( Spam::over_limit( 'chat', '' !== $ip ? $ip : 'unknown' ) ) {
				return self::refuse( 'rate_limited', __( 'You have started too many chats today. Please try again tomorrow or send us a ticket.', 'zinn-chat' ), 429 );
			}
			$user         = wp_get_current_user();
			$created      = Conversations::create(
				array(
					'name'       => $user->exists() ? $user->display_name : (string) $request->get_param( 'name' ),
					'email'      => $user->exists() ? $user->user_email : (string) $request->get_param( 'email' ),
					'user_id'    => (int) $user->ID,
					'page_url'   => (string) $request->get_param( 'page_url' ),
					'language'   => Util::language_tag( (string) $request->get_param( 'language' ) ),
					'ip'         => $ip,
					'user_agent' => (string) $request->get_header( 'user-agent' ),
					'consent'    => (bool) $request->get_param( 'consent' ),
				)
			);
			$token        = $created['token'];
			$conversation = Conversations::get( $created['id'] );
			$after        = 0;
			if ( ! $conversation ) {
				return self::refuse( 'db', __( 'The chat could not be started. Please try again.', 'zinn-chat' ), 500 );
			}
		}
		$id = (int) $conversation['id'];
		if ( in_array( $conversation['status'], array( 'closed', 'offline' ), true ) ) {
			Conversations::set_status( $id, 'bot' );
			$conversation['status'] = 'bot';
		}
		Conversations::add_message( $id, 'visitor', $text );

		if ( 'bot' === $conversation['status'] ) {
			if ( Assistant::available() ) {
				self::assistant_turn( $conversation, $text );
			} elseif ( Settings::get( 'human_enabled', true ) && Presence::team_online() ) {
				self::hand_over( $conversation, $text );
			} else {
				Conversations::add_message( $id, 'system', __( 'Nobody can answer in chat right now. Leave your email address and we will reply by email.', 'zinn-chat' ) );
			}
		}
		$state          = self::state( (array) Conversations::get( $id ), $after );
		$state['token'] = $token;
		return new \WP_REST_Response( $state );
	}

	/**
	 * Let the assistant answer, and record the outcome.
	 *
	 * @param array<string, mixed> $conversation Conversation.
	 * @param string               $text         Visitor message.
	 * @return void
	 */
	private static function assistant_turn( array $conversation, string $text ): void {
		$id     = (int) $conversation['id'];
		$answer = Assistant::answer(
			$text,
			array(
				'history'  => Conversations::recent( $id, 12 ),
				'page_url' => (string) $conversation['page_url'],
				'language' => (string) $conversation['language'],
				'user_id'  => (int) $conversation['user_id'],
			)
		);
		if ( null !== $answer['failure'] ) {
			// The visitor did not choose the provider and cannot fix it (§2.57): a plain message and
			// a way forward. The site owner sees the real reason in wp-admin.
			set_transient( 'zinn_chat_last_ai_failure', $answer['failure'], WEEK_IN_SECONDS );
			Conversations::add_message( $id, 'system', __( 'The assistant cannot answer right now. You can ask for a person or leave us a message.', 'zinn-chat' ) );
			return;
		}
		Conversations::add_message( $id, 'ai', $answer['text'], 0, $answer['sources'] );
		$unanswered = in_array( $answer['status'], array( 'unknown', 'staff' ), true ) ? (int) $conversation['unanswered'] + 1 : 0;
		Conversations::update(
			$id,
			array(
				'unanswered'    => $unanswered,
				'input_tokens'  => (int) $conversation['input_tokens'] + $answer['input_tokens'],
				'output_tokens' => (int) $conversation['output_tokens'] + $answer['output_tokens'],
			)
		);
		/**
		 * The assistant answered a visitor.
		 *
		 * @param int                  $id     Conversation id.
		 * @param array<string, mixed> $answer Answer.
		 * @param string               $text   Question.
		 */
		do_action( 'zinn_chat_assistant_answered', $id, $answer, $text );
	}

	/**
	 * Move a chat to "waiting for a person" and alert the team if nobody picks it up.
	 *
	 * @param array<string, mixed> $conversation Conversation.
	 * @param string               $last         Visitor's last message.
	 * @return void
	 */
	private static function hand_over( array $conversation, string $last ): void {
		$id = (int) $conversation['id'];
		Conversations::set_status( $id, 'waiting' );
		Conversations::add_message( $id, 'system', __( 'We are putting you through to a person. Please stay on this page.', 'zinn-chat' ) );
		$minutes = (int) Settings::get( 'waiting_ping_mins', 2 );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + max( 0, $minutes ) * MINUTE_IN_SECONDS, 'zinn_chat_waiting_ping', array( $id ), 'zinn-chat' );
		} else {
			Mailer::waiting( $conversation, $last );
		}
	}

	/**
	 * Action: nobody took a waiting chat in time — email the team once.
	 *
	 * @param int $id Conversation id.
	 * @return void
	 */
	public static function waiting_ping( $id ): void {
		$conversation = Conversations::get( (int) $id );
		if ( ! $conversation || 'waiting' !== $conversation['status'] || ! empty( $conversation['pinged_at'] ) ) {
			return;
		}
		$recent = Conversations::recent( (int) $id, 1 );
		Mailer::waiting( $conversation, (string) ( $recent[0]['body'] ?? '' ) );
		Conversations::update( (int) $id, array( 'pinged_at' => Util::now() ) );
	}

	/**
	 * POST chat/poll: new messages and state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function poll( \WP_REST_Request $request ): \WP_REST_Response {
		$conversation = self::conversation( $request );
		if ( ! $conversation ) {
			return self::refuse( 'unknown', __( 'This chat has ended.', 'zinn-chat' ), 404 );
		}
		return new \WP_REST_Response( self::state( $conversation, (int) $request->get_param( 'after' ) ) );
	}

	/**
	 * POST chat/human: ask for a person.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function human( \WP_REST_Request $request ): \WP_REST_Response {
		$conversation = self::conversation( $request );
		if ( ! $conversation || ! self::open() ) {
			return self::refuse( 'unknown', __( 'This chat has ended.', 'zinn-chat' ), 404 );
		}
		$id = (int) $conversation['id'];
		if ( 'bot' === $conversation['status'] ) {
			if ( Settings::get( 'human_enabled', true ) && Presence::team_online() ) {
				$recent = Conversations::recent( $id, 1 );
				self::hand_over( $conversation, (string) ( $recent[0]['body'] ?? '' ) );
			} else {
				Conversations::add_message( $id, 'system', __( 'Nobody can answer in chat right now. Leave your email address and we will reply by email.', 'zinn-chat' ) );
			}
		}
		return new \WP_REST_Response( self::state( (array) Conversations::get( $id ), (int) $request->get_param( 'after' ) ) );
	}

	/**
	 * POST chat/typing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function typing( \WP_REST_Request $request ): \WP_REST_Response {
		$conversation = self::conversation( $request );
		if ( $conversation ) {
			Conversations::update(
				(int) $conversation['id'],
				array(
					'visitor_typing_at' => Util::now(),
					'updated_at'        => $conversation['updated_at'],
				)
			);
		}
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * POST chat/ticket: turn the chat into a ticket answered by email.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function chat_ticket( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::open() || ! Settings::get( 'tickets_enabled', true ) ) {
			return self::refuse( 'closed', __( 'Tickets are not available right now.', 'zinn-chat' ), 403 );
		}
		$conversation = self::conversation( $request );
		$email        = sanitize_email( (string) $request->get_param( 'email' ) );
		$refusal      = self::guard( $request, 'ticket', (string) $request->get_param( 'text' ), $email );
		if ( $refusal ) {
			return $refusal;
		}
		$user  = wp_get_current_user();
		$lines = array();
		if ( $conversation ) {
			foreach ( Conversations::messages( (int) $conversation['id'] ) as $message ) {
				if ( in_array( $message['role'], array( 'visitor', 'ai', 'agent' ), true ) ) {
					$lines[] = ( 'visitor' === $message['role'] ? __( 'Visitor', 'zinn-chat' ) : ( 'ai' === $message['role'] ? Settings::text( 'assistant_name' ) : __( 'Team', 'zinn-chat' ) ) ) . ': ' . $message['body'];
				}
			}
		}
		$note = trim( (string) $request->get_param( 'text' ) );
		$body = ( '' !== $note ? $note . "\n\n" : '' ) . ( $lines ? "---\n" . __( 'Chat so far:', 'zinn-chat' ) . "\n" . implode( "\n", $lines ) : '' );
		$made = Tickets::create(
			array(
				'subject'         => (string) $request->get_param( 'subject' ),
				'body'            => $body,
				'name'            => $user->exists() ? $user->display_name : (string) $request->get_param( 'name' ),
				'email'           => $user->exists() ? $user->user_email : $email,
				'user_id'         => (int) $user->ID,
				'conversation_id' => $conversation ? (int) $conversation['id'] : 0,
				'channel'         => 'chat',
				'language'        => $conversation ? (string) $conversation['language'] : '',
				'ip'              => Util::client_ip(),
			)
		);
		if ( is_wp_error( $made ) ) {
			return self::refuse( (string) $made->get_error_code(), $made->get_error_message() );
		}
		if ( $conversation ) {
			Conversations::set_status(
				(int) $conversation['id'],
				'offline',
				array(
					'ticket_id' => $made['id'],
					'email'     => $user->exists() ? $user->user_email : $email,
				)
			);
			/* translators: %d: ticket number. */
			Conversations::add_message( (int) $conversation['id'], 'system', sprintf( __( 'Thank you. Your request is ticket #%d and we will reply by email.', 'zinn-chat' ), $made['id'] ) );
			return new \WP_REST_Response( self::state( (array) Conversations::get( (int) $conversation['id'] ), (int) $request->get_param( 'after' ) ) );
		}
		return new \WP_REST_Response(
			array(
				'ok'     => true,
				'ticket' => $made['id'],
			)
		);
	}

	/**
	 * POST chat/close.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function close( \WP_REST_Request $request ): \WP_REST_Response {
		$conversation = self::conversation( $request );
		if ( $conversation && 'offline' !== $conversation['status'] ) {
			Conversations::set_status( (int) $conversation['id'], 'closed' );
		}
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * POST chat/rate: thumbs up (1) or down (-1).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rate( \WP_REST_Request $request ): \WP_REST_Response {
		$conversation = self::conversation( $request );
		if ( $conversation ) {
			$rating = (int) $request->get_param( 'rating' ) > 0 ? 1 : -1;
			Conversations::update( (int) $conversation['id'], array( 'rating' => $rating ) );
			/**
			 * Fires after a visitor rates a conversation.
			 *
			 * @param int $conversation_id Conversation id.
			 * @param int $rating          1 for helpful, -1 for not.
			 */
			do_action( 'zinn_chat_rated', (int) $conversation['id'], $rating );
		}
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * POST chat/transcript: email the visitor a copy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function transcript( \WP_REST_Request $request ): \WP_REST_Response {
		$conversation = self::conversation( $request );
		$email        = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! $conversation || ! is_email( $email ) ) {
			return self::refuse( 'email', __( 'Please enter a valid email address.', 'zinn-chat' ) );
		}
		$refusal = self::guard( $request, 'ticket', '', $email );
		if ( $refusal ) {
			return $refusal;
		}
		Mailer::transcript( $conversation, $email );
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * POST tickets: the submit-a-ticket form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ticket_create( \WP_REST_Request $request ): \WP_REST_Response {
		if ( Settings::connected() || ! Settings::get( 'tickets_enabled', true ) ) {
			return self::refuse( 'closed', __( 'Tickets are not available right now.', 'zinn-chat' ), 403 );
		}
		$user    = wp_get_current_user();
		$email   = $user->exists() ? (string) $user->user_email : sanitize_email( (string) $request->get_param( 'email' ) );
		$body    = (string) $request->get_param( 'body' );
		$refusal = self::guard( $request, 'ticket', $body, $email );
		if ( $refusal ) {
			return $refusal;
		}
		$ip = Util::client_ip();
		if ( ! $user->exists() && ! Spam::challenge_ok( $request->get_params(), $ip, 'ticket_api' ) ) {
			return self::refuse( 'challenge', __( 'Please complete the check to show you are not a robot.', 'zinn-chat' ) );
		}
		if ( ! $user->exists() && Settings::get( 'consent_required', true ) && ! $request->get_param( 'consent' ) ) {
			return self::refuse( 'consent', __( 'Please agree to the privacy notice to send your request.', 'zinn-chat' ) );
		}
		$order = (int) $request->get_param( 'order_id' );
		if ( $order && ( ! $user->exists() || ! array_key_exists( $order, Woo::order_choices( (int) $user->ID ) ) ) ) {
			$order = 0;
		}
		$made = Tickets::create(
			array(
				'subject'  => (string) $request->get_param( 'subject' ),
				'body'     => $body,
				'name'     => $user->exists() ? $user->display_name : (string) $request->get_param( 'name' ),
				'email'    => $email,
				'user_id'  => (int) $user->ID,
				'order_id' => $order,
				'channel'  => 'account' === $request->get_param( 'channel' ) ? 'account' : 'form',
				'language' => Util::language_tag( (string) $request->get_param( 'language' ) ),
				'ip'       => $ip,
				'extra'    => is_array( $request->get_param( 'extra' ) ) ? map_deep( (array) $request->get_param( 'extra' ), 'sanitize_text_field' ) : array(),
			)
		);
		if ( is_wp_error( $made ) ) {
			return self::refuse( (string) $made->get_error_code(), $made->get_error_message() );
		}
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'ticket'  => $made['id'],
				/* translators: %d: ticket number. */
				'message' => sprintf( __( 'Thank you. Your request is ticket #%d. We have emailed you a link to follow it.', 'zinn-chat' ), $made['id'] ),
			)
		);
	}

	/**
	 * The ticket a request may see: by guest token, or by id for its signed-in owner.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_REST_Response
	 */
	private static function visible_ticket( \WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		if ( '' !== $token ) {
			$found = Tickets::by_token( $token );
			if ( ! $found['ticket'] ) {
				return self::refuse( 'unknown', __( 'This link is not valid.', 'zinn-chat' ), 404 );
			}
			if ( $found['expired'] ) {
				return self::refuse( 'expired', __( 'This link has expired. We can email you a new one.', 'zinn-chat' ), 410 );
			}
			return $found['ticket'];
		}
		$ticket = Tickets::get( (int) $request->get_param( 'id' ) );
		if ( ! $ticket || ! Tickets::owned_by( $ticket, get_current_user_id() ) ) {
			return self::refuse( 'unknown', __( 'You cannot see this ticket.', 'zinn-chat' ), 404 );
		}
		return $ticket;
	}

	/**
	 * POST tickets/view: a ticket and its replies (no internal notes).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ticket_view( \WP_REST_Request $request ): \WP_REST_Response {
		$ticket = self::visible_ticket( $request );
		if ( $ticket instanceof \WP_REST_Response ) {
			return $ticket;
		}
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'ticket'  => Tickets::public_ticket( $ticket ),
				'replies' => Front_Tickets::public_replies( (int) $ticket['id'] ),
			)
		);
	}

	/**
	 * POST tickets/reply: the customer answers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ticket_reply( \WP_REST_Request $request ): \WP_REST_Response {
		$ticket = self::visible_ticket( $request );
		if ( $ticket instanceof \WP_REST_Response ) {
			return $ticket;
		}
		$body    = (string) $request->get_param( 'body' );
		$refusal = self::guard( $request, 'message', $body, (string) $ticket['email'] );
		if ( $refusal ) {
			return $refusal;
		}
		$made = Tickets::reply( (int) $ticket['id'], 'customer', $body, get_current_user_id() );
		if ( is_wp_error( $made ) ) {
			return self::refuse( (string) $made->get_error_code(), $made->get_error_message() );
		}
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'replies' => Front_Tickets::public_replies( (int) $ticket['id'] ),
			)
		);
	}

	/**
	 * POST tickets/link: email a fresh private link. Always the same answer, so it cannot be used
	 * to find out whether an address has tickets.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ticket_link( \WP_REST_Request $request ): \WP_REST_Response {
		$email   = sanitize_email( (string) $request->get_param( 'email' ) );
		$refusal = self::guard( $request, 'ticket', '', $email );
		if ( $refusal ) {
			return $refusal;
		}
		if ( is_email( $email ) ) {
			$token = (string) $request->get_param( 'token' );
			$found = '' !== $token ? Tickets::by_token( $token ) : array( 'ticket' => null );
			$rows  = $found['ticket'] && 0 === strcasecmp( (string) $found['ticket']['email'], $email )
				? array( $found['ticket'] )
				: Tickets::query(
					array(
						'status'   => 'all',
						'email'    => $email,
						'per_page' => 5,
					)
				)['rows'];
			foreach ( $rows as $ticket ) {
				if ( 0 !== strcasecmp( (string) $ticket['email'], $email ) ) {
					continue;
				}
				Tickets::refresh_token( (int) $ticket['id'] );
				Mailer::fresh_link( (array) Tickets::get( (int) $ticket['id'] ) );
			}
		}
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'If there is a request under that address, we have emailed you a new link.', 'zinn-chat' ),
			)
		);
	}
}
