<?php
/**
 * The REST routes for team discussions and the notification centre (2.14.0).
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `/wp-json/zinn-chat/v1/agent/discuss…` and `…/agent/notifications…`. Cookie + nonce
 * authentication; every route requires `zinn_chat_answer` (Agent_Rest::can_answer), and every
 * notification route acts on the signed-in agent's own rows only.
 */
final class Discuss_Rest {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		Discuss::init();
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		$answer = array( Agent_Rest::class, 'can_answer' );
		$routes = array(
			array( 'agent/discuss', 'GET', 'thread' ),
			array( 'agent/discuss', 'POST', 'post' ),
			array( 'agent/discuss/(?P<id>\d+)/react', 'POST', 'react' ),
			array( 'agent/notifications', 'GET', 'centre' ),
			array( 'agent/notifications/read', 'POST', 'read' ),
			array( 'agent/mentionable', 'GET', 'mentionable' ),
		);
		foreach ( $routes as $route ) {
			register_rest_route(
				Rest::NS,
				'/' . $route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( self::class, $route[2] ),
					'permission_callback' => $answer,
				)
			);
		}
	}

	/**
	 * The thread a request names, or the reason it cannot be opened.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{0: string, 1: string}|\WP_REST_Response
	 */
	private static function target( \WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		$key  = Discuss::key( $type, $request->get_param( 'key' ) );
		if ( ! Discuss::type_open( $type ) ) {
			return self::error( Discuss::closed( $type ) );
		}
		if ( '' === $key || ! Discuss::exists( $type, $key ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'not_found',
					'message' => __( 'Not found.', 'zinn-chat' ),
				),
				404
			);
		}
		return array( $type, $key );
	}

	/**
	 * A WP_Error as the console's error shape.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	private static function error( \WP_Error $error ): \WP_REST_Response {
		$data = $error->get_error_data();
		return new \WP_REST_Response(
			array(
				'ok'      => false,
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400
		);
	}

	/**
	 * GET agent/discuss?type=&key=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function thread( \WP_REST_Request $request ): \WP_REST_Response {
		$target = self::target( $request );
		if ( $target instanceof \WP_REST_Response ) {
			return $target;
		}
		return new \WP_REST_Response(
			array(
				'ok'        => true,
				'messages'  => Discuss::thread( $target[0], $target[1] ),
				'reactions' => Discuss::REACTIONS,
			)
		);
	}

	/**
	 * POST agent/discuss {type, key, body, parent_id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function post( \WP_REST_Request $request ): \WP_REST_Response {
		$target = self::target( $request );
		if ( $target instanceof \WP_REST_Response ) {
			return $target;
		}
		$id = Discuss::post( $target[0], $target[1], get_current_user_id(), (string) $request->get_param( 'body' ), (int) $request->get_param( 'parent_id' ) );
		if ( $id instanceof \WP_Error ) {
			return self::error( $id );
		}
		return self::thread( $request );
	}

	/**
	 * POST agent/discuss/{id}/react {reaction}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function react( \WP_REST_Request $request ): \WP_REST_Response {
		$result = Discuss::react( (int) $request['id'], get_current_user_id(), sanitize_key( (string) $request->get_param( 'reaction' ) ) );
		if ( $result instanceof \WP_Error ) {
			return self::error( $result );
		}
		return new \WP_REST_Response(
			array(
				'ok'        => true,
				'reactions' => (object) $result,
			)
		);
	}

	/**
	 * GET agent/notifications: the signed-in agent's centre.
	 *
	 * @return \WP_REST_Response
	 */
	public static function centre(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'ok' => true ) + Discuss::centre( get_current_user_id() ) );
	}

	/**
	 * POST agent/notifications/read {ids}: mark read (no ids: all).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function read( \WP_REST_Request $request ): \WP_REST_Response {
		$ids = $request->get_param( 'ids' );
		Discuss::read( get_current_user_id(), is_array( $ids ) ? $ids : array() );
		return self::centre();
	}

	/**
	 * GET agent/mentionable: the agents who can be mentioned (for the @ picker).
	 *
	 * @return \WP_REST_Response
	 */
	public static function mentionable(): \WP_REST_Response {
		$out = array();
		foreach ( Capabilities::agents() as $user ) {
			$out[] = array(
				'id'    => (int) $user->ID,
				'login' => (string) $user->user_login,
				'name'  => (string) $user->display_name,
			);
		}
		return new \WP_REST_Response(
			array(
				'ok'     => true,
				'agents' => $out,
			)
		);
	}
}
