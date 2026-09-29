<?php
/**
 * Putting the chat on the page, and the performance promise that governs how.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front end.
 *
 * ⛔⛔ EVERY LINE HERE IS A PERFORMANCE DECISION. The reason to choose this over tawk.to and
 * friends is that they pull hundreds of kilobytes onto every page.
 *
 * LOCAL MODE: the page gets ONE inline script of about 2 KB (the launcher), in the footer, with
 * its configuration as a function argument (no global, no second request). It draws a fixed-
 * position button inside a shadow root after the browser is idle, so it is never the LCP element
 * and cannot shift layout. The chat itself (widget.js, its CSS inside) is fetched only when a
 * visitor clicks, or when they already have a chat open from an earlier page. A page nobody chats
 * on makes zero requests on the chat's behalf.
 *
 * CONNECTED MODE (1.x): the hosted embed, deferred, with its configuration inline as data
 * attributes, exactly as before.
 */
final class Widget {

	private const HANDLE       = 'zinn-chat';
	private const AGENT_HANDLE = 'zinn-chat-agent';

	/** The engine writes this into wp-config.php when a site has bought the standalone AI agent. */
	private const AGENT_CONSTANT = 'ZINN_CHAT_AGENT_KEY';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'agent_enqueue' ) );
		add_filter( 'script_loader_tag', array( self::class, 'attributes' ), 10, 2 );
	}

	/**
	 * Should the chat appear on this request?
	 *
	 * @return bool
	 */
	public static function should_render(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( ! Settings::is_live() ) {
			return false;
		}
		if ( Settings::get( 'hide_for_admins', false ) && Capabilities::can_answer() ) {
			return false;
		}
		/**
		 * Filters whether the chat renders on this request (Pro's page targeting hooks here).
		 *
		 * @param bool $render Whether to render.
		 */
		return (bool) apply_filters( 'zinn_chat_should_render', true );
	}

	/**
	 * Enqueue the launcher (local) or the hosted embed (connected).
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! self::should_render() ) {
			return;
		}
		if ( Settings::connected() ) {
			wp_enqueue_script( self::HANDLE, self::hosted_src(), array(), ZINN_CHAT_VERSION, true );
			return;
		}
		$loader = (string) file_get_contents( ZINN_CHAT_DIR . 'assets/js/loader.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file inlined on purpose (zero requests).
		// Comments and indentation are for the source, not for every page view.
		$loader = (string) preg_replace( array( '#/\*.*?\*/#s', '#^\s*//[^\n]*$#m', '#^\s+#m' ), '', $loader );
		wp_register_script( self::HANDLE, false, array(), ZINN_CHAT_VERSION, true );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, '(' . trim( $loader ) . ')(' . wp_json_encode( self::config() ) . ');' );
	}

	/**
	 * What the launcher needs, printed on the page. Kept tiny: everything else (the words, the
	 * switches, who is online) is fetched with `chat/status` only when a visitor opens the chat.
	 *
	 * ⛔ A REST nonce only for signed-in users: a public page may be cached and served to anybody,
	 * and pages for signed-in users are not cached.
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$user   = wp_get_current_user();
		$config = array(
			'api'    => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			'src'    => esc_url_raw( add_query_arg( 'ver', ZINN_CHAT_VERSION, ZINN_CHAT_URL . 'assets/js/widget.js' ) ),
			'nonce'  => $user->exists() ? wp_create_nonce( 'wp_rest' ) : '',
			'colour' => (string) Settings::get( 'colour', '#1f6feb' ),
			'text'   => (string) Settings::get( 'text_colour', '#ffffff' ),
			'side'   => 'left' === Settings::get( 'position' ) ? 'left' : 'right',
			'lang'   => str_replace( '_', '-', determine_locale() ),
			'i18n'   => array( 'open' => __( 'Open chat', 'zinn-chat' ) ),
		);
		/**
		 * Filters the launcher configuration printed on the page.
		 *
		 * @param array<string, mixed> $config Configuration.
		 */
		return (array) apply_filters( 'zinn_chat_widget_config', $config );
	}

	/**
	 * Everything else the chat needs, for `chat/status` (translated on the server).
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$user   = wp_get_current_user();
		$status = array(
			'ok'        => true,
			'user'      => $user->exists() ? array(
				'name'  => $user->display_name,
				'email' => $user->user_email,
			) : null,
			'consent'   => (bool) Settings::get( 'consent_required', true ) && ! $user->exists(),
			'privacy'   => esc_url_raw( Settings::text( 'privacy_url' ) ),
			'tickets'   => (bool) Settings::get( 'tickets_enabled', true ),
			'human'     => (bool) Settings::get( 'human_enabled', true ),
			'ai'        => Assistant::available(),
			'online'    => Presence::team_online(),
			'branding'  => (bool) Settings::get( 'branding', true ),
			'challenge' => Spam::challenge() ? Spam::challenge() : null,
			'max'       => (int) Settings::get( 'max_chars', 2000 ),
			'i18n'      => array(
				'title'       => Settings::text( 'title' ),
				'greeting'    => Settings::text( 'greeting' ),
				'offline'     => Settings::text( 'offline_greeting' ),
				'consent'     => Settings::text( 'consent_text' ),
				'privacy'     => __( 'Privacy policy', 'zinn-chat' ),
				'agree'       => __( 'I agree', 'zinn-chat' ),
				'placeholder' => __( 'Type your message…', 'zinn-chat' ),
				'send'        => __( 'Send', 'zinn-chat' ),
				'close'       => __( 'Close chat', 'zinn-chat' ),
				'minimise'    => __( 'Minimise', 'zinn-chat' ),
				'human'       => __( 'Talk to a person', 'zinn-chat' ),
				'ticket'      => __( 'Leave a message', 'zinn-chat' ),
				'name'        => __( 'Your name', 'zinn-chat' ),
				'email'       => __( 'Your email', 'zinn-chat' ),
				'message'     => __( 'Your message', 'zinn-chat' ),
				'submit'      => __( 'Send message', 'zinn-chat' ),
				'cancel'      => __( 'Cancel', 'zinn-chat' ),
				'you'         => __( 'You', 'zinn-chat' ),
				'bot'         => Settings::text( 'assistant_name' ),
				'team'        => __( 'Support team', 'zinn-chat' ),
				'typing'      => __( 'is typing…', 'zinn-chat' ),
				'thinking'    => __( 'Looking this up…', 'zinn-chat' ),
				'sources'     => __( 'Sources', 'zinn-chat' ),
				'helpful'     => __( 'Was this helpful?', 'zinn-chat' ),
				'yes'         => __( 'Yes', 'zinn-chat' ),
				'no'          => __( 'No', 'zinn-chat' ),
				'thanks'      => __( 'Thank you for your feedback.', 'zinn-chat' ),
				'transcript'  => __( 'Email me this chat', 'zinn-chat' ),
				'sent'        => __( 'Sent.', 'zinn-chat' ),
				'end'         => __( 'End chat', 'zinn-chat' ),
				'ended'       => __( 'The chat has ended.', 'zinn-chat' ),
				'error'       => __( 'Something went wrong. Please try again.', 'zinn-chat' ),
				'powered'     => __( 'Powered by Zinn® Chat', 'zinn-chat' ),
				'newchat'     => __( 'Start a new chat', 'zinn-chat' ),
			),
		);
		/**
		 * Filters what the opened chat receives (Pro adds its own switches and words).
		 *
		 * @param array<string, mixed> $status Status.
		 */
		return (array) apply_filters( 'zinn_chat_widget_status', $status );
	}

	/**
	 * Where the hosted (connected mode) embed is served from.
	 *
	 * @return string
	 */
	public static function hosted_src(): string {
		/**
		 * Filters the URL the hosted chat widget is loaded from (connected mode).
		 *
		 * @param string $src Absolute URL.
		 */
		return (string) apply_filters( 'zinn_chat_script_src', 'https://zinndigital.com/embed/zinn-chat.js' );
	}

	/**
	 * The standalone Zinn® AI agent, when a site has one and the chat is NOT running (two
	 * launchers in one corner would be a defect).
	 *
	 * @return void
	 */
	public static function agent_enqueue(): void {
		if ( self::should_render() || is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( ! defined( self::AGENT_CONSTANT ) || '' === trim( (string) constant( self::AGENT_CONSTANT ) ) ) {
			return;
		}
		wp_enqueue_script(
			self::AGENT_HANDLE,
			/**
			 * Filters the URL the standalone AI chat agent is loaded from.
			 *
			 * @param string $src Absolute URL.
			 */
			(string) apply_filters( 'zinn_chat_agent_script_src', 'https://zinndigital.com/embed/chat-agent.js' ),
			array(),
			ZINN_CHAT_VERSION,
			true
		);
	}

	/**
	 * `defer` and the inline configuration on the hosted scripts.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @return string
	 */
	public static function attributes( string $tag, string $handle ): string {
		if ( self::AGENT_HANDLE === $handle ) {
			return str_replace( ' src=', sprintf( ' defer data-zinn-agent="%s" src=', esc_attr( trim( (string) constant( self::AGENT_CONSTANT ) ) ) ), $tag );
		}
		if ( self::HANDLE !== $handle || ! Settings::connected() ) {
			return $tag;
		}
		$settings = Settings::all();
		$config   = Settings::clean_config( is_array( $settings['config'] ) ? $settings['config'] : array() );
		$extra    = sprintf( ' defer data-zinn-chat="%s" data-api="%s"', esc_attr( (string) $settings['public_key'] ), esc_url( (string) $settings['api_base'] ) );
		if ( ! empty( $config['widget_id'] ) ) {
			// wp_json_encode then esc_attr: the JSON is the value, the attribute is the container.
			$extra .= sprintf( ' data-config="%s"', esc_attr( (string) wp_json_encode( $config ) ) );
		}
		return str_replace( ' src=', $extra . ' src=', $tag );
	}
}
