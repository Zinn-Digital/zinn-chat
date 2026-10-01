<?php
/**
 * The Zinn Chat menu and its screens in wp-admin.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Admin;

use ZinnDigital\ZinnChat\AiCore\Core;
use ZinnDigital\ZinnChat\Capabilities;
use ZinnDigital\ZinnChat\Conversations;
use ZinnDigital\ZinnChat\Settings;
use ZinnDigital\ZinnChat\Tickets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu: Inbox · Tickets · Assistant · Settings · Setup (+ the licensing SDK's Account/Upgrade).
 *
 * Agents (`zinn_chat_answer`) see Inbox, Tickets and Assistant; managers (`zinn_chat_manage`)
 * also see Settings and Setup. Nothing here checks `manage_options`, so Pro's Support agent role
 * works with no extra code. Screens register through `zinn_chat_admin_pages`, which is where Pro
 * adds its own (reports, canned replies, knowledge base, agents).
 */
final class Admin {

	public const MENU = 'zinn-chat';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ZINN_CHAT_FILE ), array( self::class, 'action_links' ) );
		add_action( 'admin_init', array( self::class, 'redirect_after_activation' ) );
		Settings_Page::init();
		Setup_Page::init();
		Upsell::init();
	}

	/**
	 * The screens: slug => [title, capability, renderer].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function pages(): array {
		$pages = array(
			self::MENU            => array( __( 'Inbox', 'zinn-chat' ), Capabilities::ANSWER, array( Console_Page::class, 'render' ) ),
			'zinn-chat-tickets'   => array( __( 'Tickets', 'zinn-chat' ), Capabilities::ANSWER, array( Tickets_Page::class, 'render' ) ),
			'zinn-chat-assistant' => array( __( 'Assistant', 'zinn-chat' ), Capabilities::ANSWER, array( Assistant_Page::class, 'render' ) ),
			Settings_Page::SLUG   => array( __( 'Settings', 'zinn-chat' ), Capabilities::MANAGE, array( Settings_Page::class, 'render' ) ),
			Setup_Page::SLUG      => array( __( 'Setup', 'zinn-chat' ), Capabilities::MANAGE, array( Setup_Page::class, 'render' ) ),
		);
		/**
		 * Filters the Zinn Chat admin screens (Pro adds its own).
		 *
		 * @param array<string, array<int, mixed>> $pages slug => [title, capability, callable].
		 */
		return (array) apply_filters( 'zinn_chat_admin_pages', $pages );
	}

	/**
	 * Build the menu.
	 *
	 * @return void
	 */
	public static function menu(): void {
		$badge = '';
		if ( ! Settings::connected() && Capabilities::can_answer() ) {
			$chats   = Conversations::counts();
			$tickets = Tickets::counts();
			$count   = $chats['waiting'] + (int) $tickets['open'];
			if ( $count > 0 ) {
				$badge = ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . number_format_i18n( $count ) . '</span></span>';
			}
		}
		$pages = self::pages();
		if ( Settings::connected() ) {
			// In connected mode the inbox lives in the Zinn app: the menu opens Settings instead.
			unset( $pages[ self::MENU ], $pages['zinn-chat-tickets'], $pages['zinn-chat-assistant'] );
		}
		$top = (string) array_key_first( $pages );
		add_menu_page( __( 'Zinn® Chat', 'zinn-chat' ), __( 'Zinn® Chat', 'zinn-chat' ) . $badge, (string) $pages[ $top ][1], $top, $pages[ $top ][2], 'dashicons-format-chat', 26 );
		foreach ( $pages as $slug => $page ) {
			add_submenu_page( $top, (string) $page[0], (string) $page[0], (string) $page[1], (string) $slug, $page[2] );
		}
	}

	/**
	 * Is this one of our screens?
	 *
	 * @param string $hook Admin page hook.
	 * @return string The page slug, or ''.
	 */
	public static function screen( string $hook ): string {
		foreach ( array_keys( self::pages() ) as $slug ) {
			if ( false !== strpos( $hook, (string) $slug ) ) {
				return (string) $slug;
			}
		}
		return '';
	}

	/**
	 * Scripts and styles, only on our own screens (CLAUDE.md §2.22).
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public static function assets( string $hook ): void {
		$slug = self::screen( $hook );
		if ( '' === $slug ) {
			return;
		}
		wp_enqueue_style( 'zinn-chat-admin', ZINN_CHAT_URL . 'assets/css/admin.css', array(), ZINN_CHAT_VERSION );
		if ( in_array( $slug, array( self::MENU, 'zinn-chat-tickets', 'zinn-chat-assistant' ), true ) ) {
			wp_enqueue_script( 'zinn-chat-console', ZINN_CHAT_URL . 'assets/js/console.js', array( 'wp-api-fetch', 'wp-i18n' ), ZINN_CHAT_VERSION, true );
			wp_set_script_translations( 'zinn-chat-console', 'zinn-chat', ZINN_CHAT_DIR . 'languages' );
			/**
			 * Filters the extra configuration handed to the operator console's script. Zinn® Chat Pro
			 * adds its features here.
			 *
			 * @param array<string, mixed> $config Extra console configuration (empty in the free plugin).
			 */
			$extension = (array) apply_filters( 'zinn_chat_console_config', array() );
			wp_add_inline_script(
				'zinn-chat-console',
				'window.zinnChatConsole = ' . wp_json_encode(
					array(
						'me'        => get_current_user_id(),
						'manage'    => Capabilities::can_manage(),
						'aiUrl'     => Core::settings_url(),
						'adminUrl'  => admin_url( 'admin.php' ),
						'extension' => $extension,
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array<int|string, string> $links Links.
	 * @return array<int|string, string>
	 */
	public static function action_links( array $links ): array {
		// ⛔ No "Settings" link while Freemius waits for the opt-in choice: the screen is not
		// registered yet, so the link answered 403. Freemius's own "Opt In" link is shown then.
		if ( function_exists( 'zinn_chat_fs' ) && zinn_chat_fs()->is_activation_mode() ) {
			return $links;
		}
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Settings_Page::SLUG ) ) . '">' . esc_html__( 'Settings', 'zinn-chat' ) . '</a>' );
		return $links;
	}

	/**
	 * Go to Setup after a fresh activation (once, and never on bulk activation).
	 *
	 * @return void
	 */
	public static function redirect_after_activation(): void {
		if ( ! get_transient( 'zinn_chat_activated' ) ) {
			return;
		}
		delete_transient( 'zinn_chat_activated' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core's own bulk-activation flag.
		if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || ! Capabilities::can_manage() || Settings::get( 'enabled' ) ) {
			return;
		}
		// ⛔ While Freemius is waiting for the owner's opt-in choice it holds the plugin's own
		// screens back (they are not registered yet), so redirecting to Setup answered 403 on the
		// first admin page after activation — on every site outside our hosting (2.3.0). Freemius
		// shows its own opt-in screen then, and sends the owner to Setup (`first-path`) after it.
		if ( function_exists( 'zinn_chat_fs' ) && zinn_chat_fs()->is_activation_mode() ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . Setup_Page::SLUG ) );
		exit;
	}

	/**
	 * The last AI failure, on our screens: the site owner is the one who can fix a key (§2.57).
	 *
	 * @return void
	 */
	public static function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || '' === self::screen( (string) $screen->id ) || ! Capabilities::can_manage() ) {
			return;
		}
		$db = get_transient( 'zinn_chat_db_error' );
		if ( is_string( $db ) && '' !== $db ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Zinn® Chat could not create its database tables:', 'zinn-chat' ) . '</strong> ' . esc_html( $db ) . '</p></div>';
		}
		$failure = get_transient( 'zinn_chat_last_ai_failure' );
		if ( ! is_array( $failure ) || empty( $failure['message'] ) ) {
			return;
		}
		echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'The AI assistant could not answer a visitor:', 'zinn-chat' ) . '</strong> ' . esc_html( (string) $failure['message'] );
		if ( ! empty( $failure['link'] ) ) {
			echo ' <a href="' . esc_url( (string) $failure['link'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Fix it', 'zinn-chat' ) . '</a>';
		}
		echo '</p></div>';
	}
}
