<?php
/**
 * Who may do what.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two capabilities, so an agent can exist without being an administrator.
 *
 *  - `zinn_chat_answer`  answer live chats and tickets, use the test console (an AGENT);
 *  - `zinn_chat_manage`  change settings, rebuild the index, delete data (a MANAGER).
 *
 * The free edition grants both to administrators only (one team, the site's own admins). Pro adds a
 * "Support agent" role that holds `zinn_chat_answer` and `read` and nothing else, so a colleague
 * can answer customers without reaching the rest of wp-admin. Every check in this plugin is on one
 * of these two capabilities, never on `manage_options`, which is what makes that role possible.
 */
final class Capabilities {

	public const ANSWER = 'zinn_chat_answer';
	public const MANAGE = 'zinn_chat_manage';

	/** Stamp of the grant, so it is re-applied when this list changes. */
	private const VERSION = 1;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'maybe_grant' ) );
	}

	/**
	 * Grant the capabilities to administrators once per VERSION.
	 *
	 * @return void
	 */
	public static function maybe_grant(): void {
		if ( (int) get_option( 'zinn_chat_caps', 0 ) >= self::VERSION ) {
			return;
		}
		self::grant();
	}

	/**
	 * Grant to administrators (and anything Pro adds through the filter).
	 *
	 * @return void
	 */
	public static function grant(): void {
		/**
		 * Filters which roles receive which Zinn Chat capabilities.
		 *
		 * @param array<string, array<int, string>> $map Role => capabilities.
		 */
		$map = (array) apply_filters(
			'zinn_chat_role_capabilities',
			array( 'administrator' => array( self::ANSWER, self::MANAGE ) )
		);
		foreach ( $map as $role_name => $caps ) {
			$role = get_role( (string) $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( (array) $caps as $cap ) {
				$role->add_cap( (string) $cap );
			}
		}
		update_option( 'zinn_chat_caps', self::VERSION, false );
	}

	/**
	 * Remove every Zinn Chat capability from every role (uninstall).
	 *
	 * @return void
	 */
	public static function revoke(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( self::ANSWER );
			$role->remove_cap( self::MANAGE );
		}
		delete_option( 'zinn_chat_caps' );
	}

	/**
	 * May the current user answer customers?
	 *
	 * @return bool
	 */
	public static function can_answer(): bool {
		return current_user_can( self::ANSWER ) || current_user_can( self::MANAGE );
	}

	/**
	 * May the current user manage the help desk?
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( self::MANAGE );
	}

	/**
	 * Users who may answer (for notifications and the "assigned to" list).
	 *
	 * @return array<int, \WP_User>
	 */
	public static function agents(): array {
		$users = get_users(
			array(
				'capability__in' => array( self::ANSWER, self::MANAGE ),
				'number'         => 200,
				'fields'         => 'all',
			)
		);
		return is_array( $users ) ? $users : array();
	}
}
