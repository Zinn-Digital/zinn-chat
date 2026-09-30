<?php
/**
 * The setup checklist.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Admin;

use ZinnDigital\ZinnChat\AiCore\Client;
use ZinnDigital\ZinnChat\AiCore\Core;
use ZinnDigital\ZinnChat\Capabilities;
use ZinnDigital\ZinnChat\Front_Tickets;
use ZinnDigital\ZinnChat\Index\Index;
use ZinnDigital\ZinnChat\Index\Queue;
use ZinnDigital\ZinnChat\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every step a new site needs, each with its state measured from the site (never a remembered
 * "done" flag) and the one button that does it.
 */
final class Setup_Page {

	public const SLUG = 'zinn-chat-setup';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_zinn_chat_setup', array( self::class, 'handle' ) );
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! Capabilities::can_manage() ) {
			return;
		}
		$on      = (bool) Settings::get( 'enabled' );
		$ai      = Client::ready();
		$embed   = Client::embedding_choice();
		$stats   = Index::stats();
		$page    = Front_Tickets::page_url();
		$woo     = class_exists( 'WooCommerce' );
		$steps   = array();
		$steps[] = array(
			$on,
			__( 'Turn the chat on', 'zinn-chat' ),
			$on ? __( 'The chat is showing on your site.', 'zinn-chat' ) : __( 'Nothing is shown or loaded on your site until you turn it on.', 'zinn-chat' ),
			self::button( $on ? 'off' : 'on', $on ? __( 'Turn off', 'zinn-chat' ) : __( 'Turn on', 'zinn-chat' ) ),
		);
		$steps[] = array(
			$ai,
			__( 'Connect your AI provider', 'zinn-chat' ),
			$ai
				? ( '' !== $embed['provider']
					/* translators: 1: provider id, 2: model id. */
					? sprintf( __( 'Connected. Site search uses %1$s (%2$s).', 'zinn-chat' ), $embed['provider'], $embed['model'] )
					: __( 'Connected. Your provider cannot build a meaning-based index, so the assistant uses keyword search. Add a Google Gemini, OpenAI, Mistral or OpenRouter key for better answers.', 'zinn-chat' ) )
				: __( 'Add a key for Google Gemini, OpenAI, Anthropic Claude, Mistral, OpenRouter or any OpenAI-compatible service. The newest model is chosen for you; you can change it.', 'zinn-chat' ),
			'<a class="button" href="' . esc_url( Core::settings_url() ) . '">' . esc_html__( 'AI providers', 'zinn-chat' ) . '</a>',
		);
		$steps[] = array(
			$stats['counts']['indexed'] > 0 && 0 === Queue::remaining(),
			__( 'Let the assistant read your site', 'zinn-chat' ),
			/* translators: 1: pages read, 2: pages waiting, 3: passages. */
			sprintf( __( '%1$d pages read, %2$d waiting, %3$d passages. New and edited content is picked up automatically.', 'zinn-chat' ), $stats['counts']['indexed'], Queue::remaining(), $stats['passages'] ),
			self::button( 'index', __( 'Read the whole site now', 'zinn-chat' ) ),
		);
		$steps[] = array(
			'' !== $page,
			__( 'Create your support page', 'zinn-chat' ),
			'' !== $page ? __( 'Visitors submit tickets there, and ticket emails link to it.', 'zinn-chat' ) : __( 'A page with the ticket form, where customers also follow their requests.', 'zinn-chat' ),
			'' !== $page ? '<a class="button" href="' . esc_url( $page ) . '">' . esc_html__( 'View', 'zinn-chat' ) . '</a>' : self::button( 'page', __( 'Create the support page', 'zinn-chat' ) ),
		);
		$steps[] = array(
			null,
			__( 'Add "Submit a ticket" and "Chat with us" to a menu', 'zinn-chat' ),
			__( 'Appearance, Menus: use the Zinn® Chat box. Or add the Zinn® Chat blocks to any page, in any page builder.', 'zinn-chat' ),
			'<a class="button" href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '">' . esc_html__( 'Menus', 'zinn-chat' ) . '</a>',
		);
		if ( $woo ) {
			$steps[] = array(
				(bool) Settings::get( 'woo_tab' ),
				__( 'WooCommerce account area', 'zinn-chat' ),
				__( 'Customers see their tickets under Support in My Account, and the assistant can tell them where their order is.', 'zinn-chat' ),
				'<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . Settings_Page::SLUG . '&tab=tickets' ) ) . '">' . esc_html__( 'Settings', 'zinn-chat' ) . '</a>',
			);
		}
		$steps[] = array(
			null,
			__( 'Test the assistant', 'zinn-chat' ),
			__( 'Ask it questions and see exactly which pages it answered from, including out-of-date ones.', 'zinn-chat' ),
			'<a class="button" href="' . esc_url( admin_url( 'admin.php?page=zinn-chat-assistant' ) ) . '">' . esc_html__( 'Open the test console', 'zinn-chat' ) . '</a>',
		);
		$steps[] = array(
			null,
			__( 'Answer live chats', 'zinn-chat' ),
			__( 'Visitors can ask for a person while you have the Inbox open. It works on a phone too.', 'zinn-chat' ),
			'<a class="button" href="' . esc_url( admin_url( 'admin.php?page=zinn-chat' ) ) . '">' . esc_html__( 'Open the Inbox', 'zinn-chat' ) . '</a>',
		);

		echo '<div class="wrap zinn-chat-admin"><h1>' . esc_html__( 'Set up Zinn® Chat', 'zinn-chat' ) . '</h1>';
		if ( Settings::connected() ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'This site is connected to the Zinn Digital® app: chats are answered there. To run the help desk on this site instead, choose "This site" under Settings, Connect to Zinn Digital®.', 'zinn-chat' ) . '</p></div>';
		}
		echo '<ol class="zc-steps">';
		foreach ( $steps as $step ) {
			$state = null === $step[0] ? 'info' : ( $step[0] ? 'done' : 'todo' );
			echo '<li class="zc-step zc-' . esc_attr( $state ) . '"><div><strong>' . esc_html( $step[1] ) . '</strong><p>' . esc_html( $step[2] ) . '</p></div><div>' . $step[3] . '</div></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $step[3] is built from escaped parts above.
		}
		echo '</ol>';
		/**
		 * Output under the setup checklist (the free edition shows the Pro card here).
		 */
		do_action( 'zinn_chat_setup_after' );
		echo '</div>';
	}

	/**
	 * A one-button form.
	 *
	 * @param string $action Action.
	 * @param string $label Label.
	 * @return string
	 */
	private static function button( string $action, string $label ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="zinn_chat_setup"><input type="hidden" name="do" value="' . esc_attr( $action ) . '">'
			. wp_nonce_field( 'zinn_chat_setup', '_wpnonce', true, false )
			. '<button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Handle a button.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'zinn-chat' ), 403 );
		}
		check_admin_referer( 'zinn_chat_setup' );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		switch ( $do ) {
			case 'on':
			case 'off':
				Settings::save( array( 'enabled' => 'on' === $do ) );
				if ( 'on' === $do ) {
					Queue::start_sweep();
				}
				break;
			case 'index':
				Queue::manual();
				Index::mark_all_stale();
				Queue::start_sweep();
				Queue::kick_work();
				break;
			case 'page':
				Front_Tickets::create_page();
				break;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
