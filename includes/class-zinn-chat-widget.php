<?php
/**
 * Putting the widget on the page — and the performance promise that governs how.
 *
 * @package ZinnChat
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the embed on the front end.
 *
 * ⛔⛔ **EVERY LINE HERE IS A PERFORMANCE DECISION AND NONE OF THEM IS OPTIONAL.**
 * The reason a site owner would replace tawk.to with this is that the competitor's embed
 * pulls several hundred kilobytes onto every page. So:
 *
 *   - `wp_enqueue_scripts` with `$in_footer = true` — the tag is after the content, so
 *     the parser never blocks on it;
 *   - `defer` on top of that, so even the fetch does not compete with the render;
 *   - the config travels INLINE as a data attribute, so the widget makes no network
 *     request at all until a visitor clicks it;
 *   - nothing is enqueued in the admin, in a feed, in an AMP view or on a REST request;
 *   - no stylesheet, ever. The widget's CSS lives inside its own shadow root, which is
 *     also what stops it inheriting the theme's styles or leaking into them.
 *
 * ⭐ There is no `wp_localize_script`. That would emit a second inline `<script>` block
 * with a global variable in it — more bytes, a global on somebody's page, and a second
 * thing to keep in step with the first.
 */
final class Zinn_Chat_Widget {

	/**
	 * Script handle.
	 */
	private const HANDLE = 'zinn-chat';

	/**
	 * Script handle for the standalone AI agent (W42-L's product — see `agent_enqueue`).
	 */
	private const AGENT_HANDLE = 'zinn-chat-agent';

	/**
	 * The `wp-config.php` constant the engine writes when a site has bought a chat agent.
	 *
	 * ⭐ It arrives with no customer action at all: `apply_site_constants()` writes a managed
	 * block into `wp-config.php`, which is already how `ZINN_SSO_KEY` reaches every hosted
	 * site. That is what makes the agent a ONE-CLICK install on hosting we run, against a
	 * copy-and-paste snippet everywhere else.
	 */
	private const AGENT_CONSTANT = 'ZINN_CHAT_AGENT_KEY';

	/**
	 * Register the front-end hooks.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'agent_enqueue' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'attributes' ), 10, 2 );
	}

	/**
	 * Where the embed is served from.
	 *
	 * ⭐ Filterable so a customer behind their own CDN, or a Zinn® staff member testing a
	 * build, can point it elsewhere without editing the plugin. Defaults to the public
	 * asset, which is edge-cached and versioned.
	 */
	public static function src(): string {
		/**
		 * Filters the URL the chat widget is loaded from.
		 *
		 * @param string $src Absolute URL of the embed script.
		 */
		return (string) apply_filters( 'zinn_chat_script_src', 'https://zinndigital.com/embed/zinn-chat.js' );
	}

	/**
	 * Should the widget appear on THIS request?
	 *
	 * ⛔ `is_admin()` is not enough on its own: a REST request and a cron run are both
	 * front-end contexts as far as that function is concerned, and enqueueing there is
	 * wasted work at best and a stray script tag in a JSON response at worst.
	 */
	public static function should_render(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( is_feed() ) {
			return false;
		}
		if ( ! Zinn_Chat_Settings::is_live() ) {
			return false;
		}
		/**
		 * Filters whether the chat widget renders on this request. Return false to hide
		 * it — on a checkout page, say, or for signed-in staff.
		 *
		 * @param bool $render Whether to render.
		 */
		return (bool) apply_filters( 'zinn_chat_should_render', true );
	}

	/**
	 * Enqueue the embed.
	 */
	public static function enqueue(): void {
		if ( ! self::should_render() ) {
			return;
		}
		wp_enqueue_script(
			self::HANDLE,
			self::src(),
			array(),
			ZINN_CHAT_VERSION,
			true
		);
	}

	/**
	 * Put the standalone Zinn® AI chat agent on the page, when the site has one and the
	 * full live chat is NOT running.
	 *
	 * ⛔⛔ **THE `! should_render()` IS THE WHOLE POINT AND IS NOT A TIDY-UP.** Two chat
	 * launchers in the same corner of one page is a product defect a customer would be
	 * embarrassed by, and it is exactly what shipping both unconditionally would produce.
	 * They are not alternatives to weigh up:
	 *
	 * - With Zinn® Chat **on**, the agent already answers INSIDE this widget — the engine
	 *   routes a visitor's question through the same agent and a person can take over
	 *   mid-conversation. That is strictly the better product, so it wins.
	 * - With Zinn® Chat **off**, the agent is the only thing the customer bought, and it
	 *   should appear on its own.
	 *
	 * ⭐ Carried here rather than in a second plugin because a second Zinn chat plugin on
	 * one WordPress site is how a customer ends up with two of everything — and because the
	 * WordPress surface is this plugin's fence, agreed with W42-L in writing before either
	 * of us built anything (§6).
	 */
	public static function agent_enqueue(): void {
		if ( self::should_render() ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) {
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
			 * @param string $src Absolute URL of the agent embed script.
			 */
			(string) apply_filters(
				'zinn_chat_agent_script_src',
				'https://zinndigital.com/embed/chat-agent.js'
			),
			array(),
			ZINN_CHAT_VERSION,
			true
		);
	}

	/**
	 * Add the widget's own attributes to its script tag.
	 *
	 * ⛔ Via `script_loader_tag` rather than by echoing a tag in `wp_footer`. Going
	 * through the enqueue pipeline means a caching or optimisation plugin can see this
	 * script, defer it, or exclude it — the ones that rewrite the page have no idea what
	 * a hand-printed tag is, which is how a chat widget ends up double-loaded or
	 * concatenated into a bundle it was never meant to be in.
	 *
	 * @param string $tag    The `<script>` tag.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function attributes( string $tag, string $handle ): string {
		if ( self::AGENT_HANDLE === $handle ) {
			// ⛔ `esc_attr` on a value that came from `wp-config.php`. It is written by our
			// own engine and is a URL-safe key — and a plugin that trusts a constant because
			// of who usually writes it is a plugin that breaks the day somebody edits the
			// file by hand.
			return str_replace(
				' src=',
				sprintf(
					' defer data-zinn-agent="%s" src=',
					esc_attr( trim( (string) constant( self::AGENT_CONSTANT ) ) )
				),
				$tag
			);
		}
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		$settings = Zinn_Chat_Settings::all();
		$config   = Zinn_Chat_Settings::clean_config( is_array( $settings['config'] ) ? $settings['config'] : array() );

		$extra = sprintf(
			' defer data-zinn-chat="%s" data-api="%s"',
			esc_attr( (string) $settings['public_key'] ),
			esc_url( (string) $settings['api_base'] )
		);
		if ( ! empty( $config['widget_id'] ) ) {
			// ⛔ `wp_json_encode` then `esc_attr`, in that order. The JSON is the value and
			// the attribute is the container; escaping first would corrupt the JSON, and
			// not escaping at all would let a stored quote break out of the attribute.
			$extra .= sprintf( ' data-config="%s"', esc_attr( (string) wp_json_encode( $config ) ) );
		}

		return str_replace( ' src=', $extra . ' src=', $tag );
	}
}
