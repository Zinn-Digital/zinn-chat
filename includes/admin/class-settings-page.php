<?php
/**
 * Settings screen.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Admin;

use ZinnDigital\ZinnChat\AiCore\Core;
use ZinnDigital\ZinnChat\Capabilities;
use ZinnDigital\ZinnChat\Connect;
use ZinnDigital\ZinnChat\Index\Extractor;
use ZinnDigital\ZinnChat\Index\Index;
use ZinnDigital\ZinnChat\Index\Queue;
use ZinnDigital\ZinnChat\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One screen, tabs of fields declared as data (so Pro adds fields and tabs with a filter, and the
 * save path sanitises everything through Settings::save()).
 */
final class Settings_Page {

	public const SLUG = 'zinn-chat-settings';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_zinn_chat_save_settings', array( self::class, 'save' ) );
	}

	/**
	 * Tabs: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		$tabs = array(
			'general'   => __( 'Chat', 'zinn-chat' ),
			'assistant' => __( 'AI assistant', 'zinn-chat' ),
			'tickets'   => __( 'Tickets and email', 'zinn-chat' ),
			'index'     => __( 'Site index', 'zinn-chat' ),
			'privacy'   => __( 'Privacy and spam', 'zinn-chat' ),
			'connect'   => __( 'Connect to Zinn Digital®', 'zinn-chat' ),
		);
		/**
		 * Filters the settings tabs.
		 *
		 * @param array<string, string> $tabs slug => label.
		 */
		return (array) apply_filters( 'zinn_chat_settings_tabs', $tabs );
	}

	/**
	 * Fields of a tab: key => [type, label, description, options].
	 *
	 * @param string $tab Tab.
	 * @return array<string, array<int, mixed>>
	 */
	public static function fields( string $tab ): array {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$types[ $type->name ] = $type->labels->name;
			}
		}
		$fields = array(
			'general'   => array(
				'enabled'          => array( 'checkbox', __( 'Show the chat on the site', 'zinn-chat' ), __( 'Nothing appears on the site, and nothing is loaded, until this is ticked.', 'zinn-chat' ) ),
				'title'            => array( 'text', __( 'Chat title', 'zinn-chat' ), '' ),
				'greeting'         => array( 'textarea', __( 'Greeting', 'zinn-chat' ), __( 'The first thing a visitor sees.', 'zinn-chat' ) ),
				'offline_greeting' => array( 'textarea', __( 'Greeting when nobody can answer', 'zinn-chat' ), '' ),
				'colour'           => array( 'color', __( 'Colour', 'zinn-chat' ), '' ),
				'text_colour'      => array( 'color', __( 'Text colour on the colour', 'zinn-chat' ), '' ),
				'position'         => array(
					'select',
					__( 'Position', 'zinn-chat' ),
					'',
					array(
						'right' => __( 'Bottom, end side (right in left-to-right languages)', 'zinn-chat' ),
						'left'  => __( 'Bottom, start side', 'zinn-chat' ),
					),
				),
				'human_enabled'    => array( 'checkbox', __( 'Offer "Talk to a person"', 'zinn-chat' ), __( 'Shown while somebody has the Inbox open and is not set to away.', 'zinn-chat' ) ),
				'hide_for_admins'  => array( 'checkbox', __( 'Hide the chat from your support team while they are signed in', 'zinn-chat' ), '' ),
				'branding'         => array( 'checkbox', __( 'Show "Powered by Zinn® Chat" under the chat', 'zinn-chat' ), __( 'Optional. Off unless you turn it on.', 'zinn-chat' ) ),
			),
			'assistant' => array(
				'ai_enabled'        => array( 'checkbox', __( 'Let the AI assistant answer visitors', 'zinn-chat' ), '' ),
				'assistant_name'    => array( 'text', __( 'Assistant name', 'zinn-chat' ), '' ),
				'instructions'      => array( 'textarea', __( 'Extra instructions for the assistant', 'zinn-chat' ), __( 'For example: "Always mention our 30-day returns." They cannot override the safety rules.', 'zinn-chat' ) ),
				'language'          => array( 'text', __( 'Answer language', 'zinn-chat' ), __( '"auto" answers in the visitor\'s own language; or a locale such as en_GB or de_DE to always answer in one language.', 'zinn-chat' ) ),
				'min_score'         => array( 'number', __( 'Minimum relevance', 'zinn-chat' ), __( 'Passages scoring below this (0 to 0.95) are not used, and the assistant says it does not know. Check scores in the test console.', 'zinn-chat' ), array( 'step' => '0.01' ) ),
				'handoff_after'     => array( 'number', __( 'Offer a person after this many unanswered questions', 'zinn-chat' ), '' ),
				'max_answer_tokens' => array( 'number', __( 'Longest answer (tokens)', 'zinn-chat' ), '' ),
			),
			'tickets'   => array(
				'tickets_enabled'   => array( 'checkbox', __( 'Accept support tickets', 'zinn-chat' ), '' ),
				'ticket_page_id'    => array( 'page', __( 'Support page', 'zinn-chat' ), __( 'The page with the [zinn_chat_support] shortcode or the Support page block. Ticket links in emails open this page.', 'zinn-chat' ) ),
				'notify_email'      => array( 'text', __( 'Send team alerts to', 'zinn-chat' ), __( 'One or more email addresses, separated by commas. Empty: the site admin email.', 'zinn-chat' ) ),
				'from_name'         => array( 'text', __( 'Sender name on emails', 'zinn-chat' ), '' ),
				'waiting_ping_mins' => array( 'number', __( 'Email the team when a visitor waits for a person longer than (minutes)', 'zinn-chat' ), '' ),
				'guest_link_days'   => array( 'number', __( 'Private ticket links work for (days after the last message)', 'zinn-chat' ), __( 'After that the page offers to email a new link.', 'zinn-chat' ) ),
				'woo_tab'           => array( 'checkbox', __( 'Add a Support tab to the WooCommerce account area', 'zinn-chat' ), '' ),
				'woo_orders'        => array( 'checkbox', __( 'Let the assistant tell signed-in customers about their own orders', 'zinn-chat' ), __( 'Answers "where is my order?" from the order status and tracking.', 'zinn-chat' ) ),
			),
			'index'     => array(
				'index_types'   => array( 'multi', __( 'Content the assistant reads', 'zinn-chat' ), __( 'None ticked means every public type.', 'zinn-chat' ), $types ),
				'index_woo'     => array( 'checkbox', __( 'WooCommerce products, prices, stock, shipping and payment methods', 'zinn-chat' ), '' ),
				'index_forums'  => array( 'checkbox', __( 'Forums (bbPress, BuddyBoss, wpForo)', 'zinn-chat' ), '' ),
				'index_fetch'   => array( 'checkbox', __( 'Read pages as visitors see them when a page builder\'s content cannot be read directly', 'zinn-chat' ), __( 'Fetches the page from your own site in the background.', 'zinn-chat' ) ),
				'index_exclude' => array( 'text', __( 'Never read these posts or pages (IDs)', 'zinn-chat' ), __( 'Comma-separated.', 'zinn-chat' ) ),
			),
			'privacy'   => array(
				'consent_required' => array( 'checkbox', __( 'Ask visitors to agree before a chat or ticket', 'zinn-chat' ), '' ),
				'consent_text'     => array( 'textarea', __( 'Agreement text', 'zinn-chat' ), '' ),
				'privacy_url'      => array( 'text', __( 'Privacy policy link', 'zinn-chat' ), __( 'Empty: the site\'s privacy policy page.', 'zinn-chat' ) ),
				'retention_days'   => array( 'number', __( 'Delete finished chats and tickets after (days)', 'zinn-chat' ), __( '0 keeps them.', 'zinn-chat' ) ),
				'keep_ip'          => array( 'checkbox', __( 'Store visitors\' IP addresses', 'zinn-chat' ), __( 'Off: only a coded form is kept, enough for spam limits.', 'zinn-chat' ) ),
				'rate_messages'    => array( 'number', __( 'Messages per visitor per hour', 'zinn-chat' ), '' ),
				'rate_chats'       => array( 'number', __( 'Chats per visitor per day', 'zinn-chat' ), '' ),
				'rate_tickets'     => array( 'number', __( 'Tickets per visitor per day', 'zinn-chat' ), '' ),
				'max_chars'        => array( 'number', __( 'Longest chat message (characters)', 'zinn-chat' ), '' ),
				'blocked'          => array( 'textarea', __( 'Block list', 'zinn-chat' ), __( 'One per line: an IP address, an email address, @domain.com, or a word.', 'zinn-chat' ) ),
			),
			'connect'   => array(
				'mode'       => array(
					'select',
					__( 'Where chats are answered', 'zinn-chat' ),
					__( '"This site" keeps everything in this site\'s database and uses your own AI key. "Zinn Digital®" uses the hosted inbox in the Zinn® app (for Zinn® hosting customers).', 'zinn-chat' ),
					array(
						'local'     => __( 'This site (recommended)', 'zinn-chat' ),
						'connected' => __( 'Zinn Digital® app', 'zinn-chat' ),
					),
				),
				'public_key' => array( 'text', __( 'Zinn® widget key', 'zinn-chat' ), __( 'From the Zinn® app: Live chat, then your widget. Starts with zc_.', 'zinn-chat' ) ),
			),
		);
		/**
		 * Filters the fields of a settings tab (Pro adds its own).
		 *
		 * @param array<string, array<int, mixed>> $fields Fields.
		 * @param string                           $tab    Tab.
		 */
		return (array) apply_filters( 'zinn_chat_settings_fields', $fields[ $tab ] ?? array(), $tab );
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
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		$all = Settings::all();
		echo '<div class="wrap zinn-chat-admin"><h1>' . esc_html__( 'Zinn® Chat settings', 'zinn-chat' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag set by our redirect.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'zinn-chat' ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			echo '<a class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'assistant' === $tab ) {
			echo '<p>' . esc_html__( 'The assistant uses your own AI key.', 'zinn-chat' ) . ' <a class="button" href="' . esc_url( Core::settings_url() ) . '">' . esc_html__( 'AI providers and models', 'zinn-chat' ) . '</a></p>';
		}
		/**
		 * Output above a settings tab's form.
		 *
		 * @param string $tab Tab.
		 */
		do_action( 'zinn_chat_settings_before', $tab );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="zinn_chat_save_settings"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'zinn_chat_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( self::fields( $tab ) as $key => $field ) {
			self::field( (string) $key, $field, $all[ $key ] ?? null );
		}
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
		// The shared Zinn® panel, below the controls (generated by wp/bin/build-promo.php). A
		// direct static call: the promo gate greps for `::render_panel`.
		if ( class_exists( 'Zinn_Chat_Promo' ) ) {
			\Zinn_Chat_Promo::render_panel();
		}
		echo '</div>';
	}

	/**
	 * One field row.
	 *
	 * @param string            $key   Setting.
	 * @param array<int, mixed> $field Definition.
	 * @param mixed             $value Current value.
	 * @return void
	 */
	private static function field( string $key, array $field, $value ): void {
		list( $type, $label ) = $field;
		$help                 = (string) ( $field[2] ?? '' );
		$options              = (array) ( $field[3] ?? array() );
		$id                   = 'zc-' . $key;
		$name                 = 'zinn_chat[' . $key . ']';
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		switch ( $type ) {
			case 'checkbox':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . '>';
				break;
			case 'textarea':
				echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
				break;
			case 'color':
				echo '<input type="color" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
				break;
			case 'number':
				echo '<input type="number" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" step="' . esc_attr( (string) ( $options['step'] ?? '1' ) ) . '">';
				break;
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( $options as $option => $text ) {
					echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( (string) $value, (string) $option, false ) . '>' . esc_html( (string) $text ) . '</option>';
				}
				echo '</select>';
				break;
			case 'multi':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="">';
				foreach ( $options as $option => $text ) {
					echo '<label style="display:inline-block;margin-inline-end:16px"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( (string) $option ) . '"' . checked( in_array( $option, (array) $value, true ), true, false ) . '> ' . esc_html( (string) $text ) . '</label>';
				}
				break;
			case 'page':
				wp_dropdown_pages(
					array(
						'name'              => esc_attr( $name ),
						'id'                => esc_attr( $id ),
						'selected'          => (int) $value,
						'show_option_none'  => esc_html__( '— None —', 'zinn-chat' ),
						'option_none_value' => '0',
					)
				);
				break;
			case 'secret':
				echo '<input type="password" class="regular-text" autocomplete="new-password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="" placeholder="' . esc_attr( '' !== (string) $value ? __( 'Saved — leave empty to keep', 'zinn-chat' ) : '' ) . '">';
				if ( '' !== (string) $value ) {
					echo ' <label><input type="checkbox" name="' . esc_attr( 'zinn_chat[' . $key . '_clear]' ) . '" value="1"> ' . esc_html__( 'Remove the saved key', 'zinn-chat' ) . '</label>';
				}
				break;
			default:
				echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_array( $value ) ? '' : (string) $value ) . '">';
		}
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Save a tab.
	 *
	 * @return void
	 */
	public static function save(): void {
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'zinn-chat' ), 403 );
		}
		check_admin_referer( 'zinn_chat_settings' );
		$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';
		$posted = isset( $_POST['zinn_chat'] ) && is_array( $_POST['zinn_chat'] ) ? wp_unslash( $_POST['zinn_chat'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is sanitised by Settings::save().
		$input  = array();
		foreach ( array_keys( self::fields( $tab ) ) as $key ) {
			if ( array_key_exists( $key, $posted ) ) {
				$input[ $key ] = 'index_types' === $key ? array_filter( (array) $posted[ $key ] ) : $posted[ $key ];
			}
		}
		foreach ( self::fields( $tab ) as $key => $field ) {
			// A secret field is write-only; its "remove" box is the only way to empty it.
			if ( 'secret' === ( $field[0] ?? '' ) && isset( $posted[ $key . '_clear' ] ) ) {
				$input[ $key . '_clear' ] = true;
			}
		}
		$before = Settings::all();
		$after  = Settings::save( $input );
		if ( 'connected' === $after['mode'] ) {
			Connect::schedule();
			Connect::refresh();
		} else {
			Connect::unschedule();
		}
		// What the assistant reads changed: go through the site again.
		foreach ( array( 'index_types', 'index_woo', 'index_forums', 'index_fetch', 'index_exclude' ) as $key ) {
			if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
				Index::mark_all_stale();
				Queue::start_sweep();
				Queue::kick_work();
				break;
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab . '&saved=1' ) );
		exit;
	}

	/**
	 * Post types the index would read now (for the Setup screen).
	 *
	 * @return array<int, string>
	 */
	public static function indexed_types(): array {
		return Extractor::post_types();
	}
}
