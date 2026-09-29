<?php
/**
 * The one REST route: is AI ready, and where is it set up.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-rest.php by wp/bin/build-ai-core.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\ZinnChat\AiCore
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\AiCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `zinn-ai/v1/status`, registered by the elected copy only.
 *
 * ⭐ It exists so a plugin's editor screen (a React app) can show "Set up AI" with the right link
 * instead of a button that fails. It never returns a key, a hint of one, or a model list: only
 * whether AI can be used and where the settings are.
 */
final class Rest {

	/** REST namespace, shared by every copy. */
	public const NAMESPACE = 'zinn-ai/v1';

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => array( self::class, 'can_edit' ),
			)
		);
	}

	/**
	 * Only people who can write content use AI features.
	 *
	 * @return bool
	 */
	public static function can_edit(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET status.
	 *
	 * @return \WP_REST_Response
	 */
	public static function status(): \WP_REST_Response {
		$default = Store::default_for( 'general' );

		return new \WP_REST_Response(
			array(
				'ready'       => Client::ready(),
				'provider'    => '' !== $default['provider'] ? (string) ( Registry::get( $default['provider'] )['label'] ?? '' ) : '',
				'settingsUrl' => current_user_can( 'manage_options' ) ? Core::settings_url() : '',
			)
		);
	}
}
