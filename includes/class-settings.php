<?php
/**
 * The plugin's settings: one option, and the only place its shape is written down.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stored settings.
 *
 * ⛔ ONE option row. It is read on every front-end page (the widget decides whether to render
 * from it), so it is one small autoloaded array rather than a dozen rows.
 *
 * ⛔ `enabled` defaults to FALSE and that is the consent design: the plugin is preinstalled on
 * sites whose owner never asked for a chat window.
 *
 * Two modes. `local` (the default for a new install) runs the whole help desk on this site: the
 * data lives in this site's database and the AI uses the owner's own key. `connected` is the
 * 1.x behaviour: the widget and inbox are Zinn Digital's hosted service, answered from the Zinn
 * app. A 1.x install that had a key keeps `connected` on upgrade, so nothing changes for it.
 */
final class Settings {

	public const OPTION = 'zinn_chat_settings';

	/**
	 * Per-request cache.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'schema'            => 2,
			'mode'              => 'local',
			'enabled'           => false,
			// Connected mode (1.x).
			'public_key'        => '',
			'api_base'          => 'https://api.zinndigital.com',
			'config'            => array(),
			// Widget.
			'title'             => '',
			'greeting'          => '',
			'offline_greeting'  => '',
			'colour'            => '#1f6feb',
			'text_colour'       => '#ffffff',
			'position'          => 'right',
			'consent_required'  => true,
			'consent_text'      => '',
			'privacy_url'       => '',
			// Off unless the owner opts in: a credit link on the public site needs permission (WordPress.org guideline 10).
			'branding'          => false,
			'hide_for_admins'   => false,
			// AI assistant.
			'ai_enabled'        => true,
			'assistant_name'    => '',
			'instructions'      => '',
			'language'          => 'auto',
			'max_answer_tokens' => 900,
			'min_score'         => 0.30,
			'handoff_after'     => 2,
			// People.
			'human_enabled'     => true,
			'notify_email'      => '',
			'waiting_ping_mins' => 2,
			// Tickets.
			'tickets_enabled'   => true,
			'ticket_page_id'    => 0,
			'guest_link_days'   => 30,
			'woo_tab'           => true,
			'woo_orders'        => true,
			'from_name'         => '',
			// Privacy.
			'retention_days'    => 0,
			'keep_ip'           => false,
			// Spam.
			'rate_messages'     => 30,
			'rate_chats'        => 10,
			'rate_tickets'      => 5,
			'max_chars'         => 2000,
			'blocked'           => '',
			// Site index.
			'index_types'       => array(),
			'index_woo'         => true,
			'index_forums'      => true,
			'index_fetch'       => false,
			'index_exclude'     => '',
		);
	}

	/**
	 * Current settings, defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback Value when unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Forget the per-request cache (after a write by another path, and in tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Is the site in connected (Zinn Digital hosted inbox) mode?
	 *
	 * @return bool
	 */
	public static function connected(): bool {
		return 'connected' === self::get( 'mode' );
	}

	/**
	 * Whether the widget renders at all.
	 *
	 * ⛔ In connected mode BOTH the box and a key: a site that ticked the box and cleared the key
	 * would otherwise load a script that finds no widget.
	 *
	 * @return bool
	 */
	public static function is_live(): bool {
		$all = self::all();
		if ( empty( $all['enabled'] ) ) {
			return false;
		}
		if ( 'connected' === $all['mode'] ) {
			return '' !== trim( (string) $all['public_key'] );
		}
		return true;
	}

	/**
	 * Visitor-facing text with its translated default when the owner left it empty.
	 *
	 * @param string $key Setting name.
	 * @return string
	 */
	public static function text( string $key ): string {
		$value = trim( (string) self::get( $key, '' ) );
		if ( '' !== $value ) {
			return $value;
		}
		switch ( $key ) {
			case 'title':
				return __( 'Chat with us', 'zinn-chat' );
			case 'greeting':
				return __( 'Hi! Ask me anything about this site. I answer from its own pages, and I can put you through to a person.', 'zinn-chat' );
			case 'greeting_no_ai':
				// ⛔ Without an AI key there is no assistant, so the default greeting must not promise
				// one (2.0.1). An owner's own greeting (the `greeting` field) still wins.
				$own = trim( (string) self::get( 'greeting', '' ) );
				return '' !== $own ? $own : __( 'Hi! How can we help? Send us a message and we will get back to you.', 'zinn-chat' );
			case 'offline_greeting':
				return __( 'Nobody is online right now. Leave your question and email address and we will reply by email.', 'zinn-chat' );
			case 'consent_text':
				return __( 'We keep this conversation so we can answer you.', 'zinn-chat' );
			case 'assistant_name':
				return __( 'Assistant', 'zinn-chat' );
			case 'from_name':
				return (string) get_bloginfo( 'name' );
			case 'notify_email':
				return (string) get_option( 'admin_email' );
			case 'privacy_url':
				return (string) get_privacy_policy_url();
		}
		return '';
	}

	/**
	 * Save, sanitising every field. Unknown keys are dropped.
	 *
	 * ⛔ Sanitised HERE, not at the form: WP-CLI, our own provisioning and the screen all write
	 * this option, and a validator that guards one of them guards one in three.
	 *
	 * @param array<string, mixed> $input Raw values (a partial set updates only those keys).
	 * @return array<string, mixed> What was stored.
	 */
	public static function save( array $input ): array {
		$current = self::all();
		$clean   = $current;
		$bools   = array( 'enabled', 'consent_required', 'branding', 'hide_for_admins', 'ai_enabled', 'human_enabled', 'tickets_enabled', 'woo_tab', 'woo_orders', 'keep_ip', 'index_woo', 'index_forums', 'index_fetch' );
		foreach ( $bools as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = ! empty( $input[ $key ] ) && 'false' !== $input[ $key ] && '0' !== $input[ $key ];
			}
		}
		$texts = array( 'title', 'assistant_name', 'from_name' );
		foreach ( $texts as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = mb_substr( sanitize_text_field( (string) $input[ $key ] ), 0, 120 );
			}
		}
		$areas = array( 'greeting', 'offline_greeting', 'consent_text', 'instructions', 'blocked', 'index_exclude' );
		foreach ( $areas as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = mb_substr( sanitize_textarea_field( (string) $input[ $key ] ), 0, 4000 );
			}
		}
		foreach ( array( 'colour', 'text_colour' ) as $key ) {
			if ( isset( $input[ $key ] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $input[ $key ] ) ) {
				$clean[ $key ] = strtolower( (string) $input[ $key ] );
			}
		}
		if ( isset( $input['position'] ) ) {
			$clean['position'] = 'left' === $input['position'] ? 'left' : 'right';
		}
		if ( isset( $input['mode'] ) ) {
			$clean['mode'] = 'connected' === $input['mode'] ? 'connected' : 'local';
		}
		if ( isset( $input['language'] ) ) {
			$lang              = sanitize_text_field( (string) $input['language'] );
			$clean['language'] = ( 'auto' === $lang || preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/', $lang ) ) ? $lang : 'auto';
		}
		if ( isset( $input['privacy_url'] ) ) {
			$clean['privacy_url'] = esc_url_raw( trim( (string) $input['privacy_url'] ) );
		}
		if ( isset( $input['notify_email'] ) ) {
			$emails = array();
			foreach ( preg_split( '/[\s,;]+/', (string) $input['notify_email'] ) as $email ) {
				$email = sanitize_email( $email );
				if ( is_email( $email ) ) {
					$emails[] = $email;
				}
			}
			$clean['notify_email'] = implode( ', ', array_slice( array_unique( $emails ), 0, 10 ) );
		}
		$ints = array(
			'max_answer_tokens' => array( 200, 4000 ),
			'handoff_after'     => array( 0, 10 ),
			'waiting_ping_mins' => array( 0, 120 ),
			'ticket_page_id'    => array( 0, PHP_INT_MAX ),
			'guest_link_days'   => array( 1, 3650 ),
			'retention_days'    => array( 0, 3650 ),
			'rate_messages'     => array( 1, 1000 ),
			'rate_chats'        => array( 1, 1000 ),
			'rate_tickets'      => array( 1, 1000 ),
			'max_chars'         => array( 100, 10000 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$clean[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['min_score'] ) && is_numeric( $input['min_score'] ) ) {
			$clean['min_score'] = max( 0.0, min( 0.95, round( (float) $input['min_score'], 2 ) ) );
		}
		if ( isset( $input['index_types'] ) ) {
			$types = array();
			foreach ( (array) $input['index_types'] as $type ) {
				$type = sanitize_key( (string) $type );
				if ( '' !== $type && post_type_exists( $type ) ) {
					$types[] = $type;
				}
			}
			$clean['index_types'] = array_values( array_unique( $types ) );
		}
		// Connected mode (1.x shape, unchanged validation).
		if ( isset( $input['public_key'] ) ) {
			$key = trim( (string) $input['public_key'] );
			// The engine mints these as `zc_` + URL-safe base64; anything else is a paste accident.
			$clean['public_key'] = ( '' === $key || preg_match( '/^zc_[A-Za-z0-9_-]{8,64}$/', $key ) ) ? $key : '';
		}
		if ( isset( $input['api_base'] ) ) {
			$api               = esc_url_raw( trim( (string) $input['api_base'] ) );
			$clean['api_base'] = '' === $api ? self::defaults()['api_base'] : untrailingslashit( $api );
		}
		if ( isset( $input['config'] ) && is_array( $input['config'] ) ) {
			$clean['config'] = self::clean_config( $input['config'] );
		}
		$clean['schema'] = 2;

		/**
		 * Filters settings before they are stored (Pro adds its own keys here).
		 *
		 * @param array<string, mixed> $clean Sanitised settings.
		 * @param array<string, mixed> $input Raw input.
		 */
		$clean = (array) apply_filters( 'zinn_chat_save_settings', $clean, $input );

		update_option( self::OPTION, $clean, true );
		self::$cache = null;
		return self::all();
	}

	/**
	 * Keep only the fields the hosted widget reads (connected mode), with the shapes it expects.
	 *
	 * ⛔ An allow-list, not a sanitiser pass over whatever arrived: this value is printed into a
	 * page as a JSON attribute.
	 *
	 * @param array<string, mixed> $config Raw config.
	 * @return array<string, mixed>
	 */
	public static function clean_config( array $config ): array {
		$colour = isset( $config['accent_colour'] ) ? (string) $config['accent_colour'] : '';
		return array(
			'widget_id'     => isset( $config['widget_id'] ) ? sanitize_text_field( (string) $config['widget_id'] ) : '',
			'team_name'     => isset( $config['team_name'] ) ? sanitize_text_field( (string) $config['team_name'] ) : '',
			'greeting'      => isset( $config['greeting'] ) ? sanitize_text_field( (string) $config['greeting'] ) : '',
			'accent_colour' => preg_match( '/^#[0-9a-fA-F]{3,6}$/', $colour ) ? $colour : '#1f6feb',
			'position'      => ( isset( $config['position'] ) && 'left' === $config['position'] ) ? 'left' : 'right',
			'branding'      => ! isset( $config['branding'] ) || (bool) $config['branding'],
			'logo_url'      => ( isset( $config['logo_url'] ) && 0 === strpos( (string) $config['logo_url'], 'https://' ) ) ? esc_url_raw( (string) $config['logo_url'], array( 'https' ) ) : '',
			'ai_enabled'    => ! isset( $config['ai_enabled'] ) || (bool) $config['ai_enabled'],
			'online'        => ! empty( $config['online'] ),
			'strings'       => self::clean_strings( $config['strings'] ?? array() ),
		);
	}

	/**
	 * The hosted widget's visitor-facing words, as synced from the engine.
	 *
	 * @param mixed $strings Decoded `strings` object.
	 * @return array<string, string>
	 */
	public static function clean_strings( $strings ): array {
		if ( ! is_array( $strings ) ) {
			return array();
		}
		$clean = array();
		foreach ( $strings as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/^[a-z_]{1,32}$/', $key ) && is_string( $value ) ) {
				$clean[ $key ] = sanitize_text_field( mb_substr( $value, 0, 200 ) );
			}
		}
		return $clean;
	}
}
