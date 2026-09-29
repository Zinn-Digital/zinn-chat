<?php
/**
 * Starts everything, and the plugin's life cycle.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

use ZinnDigital\ZinnChat\Index\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boot, activation, deactivation, uninstall, and the Pro seam.
 *
 * ⭐ THE PRO SEAM, for the Pro edition (lane CHAT-PRO): premium code lives only under
 * `includes/pro__premium_only/` and is loaded by `load_pro()` when Freemius says the site may use
 * it. Freemius strips that directory (and every `__premium_only` call) from the free package, so
 * the free edition never contains Pro code — WordPress.org's "no locked features" rule.
 * Pro extends the free plugin through hooks only (see docs/655 §"Extension points").
 */
final class Plugin {

	/**
	 * Hooks for every request.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'plugins_loaded', array( self::class, 'boot' ), 5 );
	}

	/**
	 * Is the Pro edition licensed (or trialling) on this site?
	 *
	 * @return bool
	 */
	public static function is_pro(): bool {
		if ( ! function_exists( 'zinn_chat_fs' ) ) {
			return false;
		}
		$fs = zinn_chat_fs();
		return is_object( $fs ) && method_exists( $fs, 'can_use_premium_code' ) && (bool) $fs->can_use_premium_code();
	}

	/**
	 * Start.
	 *
	 * @return void
	 */
	public static function boot(): void {
		load_plugin_textdomain( 'zinn-chat', false, dirname( plugin_basename( ZINN_CHAT_FILE ) ) . '/languages' );
		Schema::maybe_upgrade();
		// A new version may read content differently (a new builder, a new exclusion): re-read the
		// site once. Unchanged text is not embedded again, so this costs nothing but PHP time.
		if ( get_option( 'zinn_chat_version' ) !== ZINN_CHAT_VERSION ) {
			update_option( 'zinn_chat_version', ZINN_CHAT_VERSION, true );
			\ZinnDigital\ZinnChat\Index\Index::mark_all_stale();
			add_action( 'init', array( Queue::class, 'start_sweep' ), 30 );
		}

		Capabilities::init();
		Mailer::init();
		Rest::init();
		Agent_Rest::init();
		Widget::init();
		Connect::init();
		Front_Tickets::init();
		Blocks::init();
		Privacy::init();
		Queue::init();
		add_action( 'init', array( Woo::class, 'init' ), 0 );
		Freemius_I18n::register();

		if ( is_admin() ) {
			Admin\Admin::init();
		}

		// The Zinn® updater serves the free house build to Zinn-hosted sites until WordPress.org
		// lists the plugin. It is inert in the premium package (installed as zinn-chat-premium,
		// which our channel does not publish; Freemius updates it) and absent from the
		// WordPress.org build, which removes these lines.
		( new \Zinn_Chat_Updater( ZINN_CHAT_FILE, ZINN_CHAT_VERSION ) )->register();

		self::load_pro();

		/**
		 * Zinn Chat has loaded (the extension point for add-ons).
		 */
		do_action( 'zinn_chat_loaded' );
	}

	/**
	 * Load the Pro layer when licensed.
	 *
	 * @return void
	 */
	private static function load_pro(): void {
		// The path is split so the premium-only token never appears in a file of the free package
		// (Freemius strips such calls, and a free file must carry none: build-plugin.php checks).
		$pro = __DIR__ . '/pro_' . '_premium_only/class-pro.php'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- deliberate split, see above.
		if ( is_readable( $pro ) && self::is_pro() ) {
			require_once $pro;
			if ( class_exists( __NAMESPACE__ . '\\Pro\\Pro' ) ) {
				call_user_func( array( __NAMESPACE__ . '\\Pro\\Pro', 'init' ) );
			}
		}
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Schema::maybe_upgrade();
		Capabilities::grant();
		if ( Settings::connected() ) {
			Connect::schedule();
		}
		set_transient( 'zinn_chat_activated', 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Deactivation: stop background work, keep data.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Connect::unschedule();
		Queue::unschedule();
		delete_transient( 'zinn_chat_sweep_scheduled' );
		delete_transient( 'zinn_chat_retention_scheduled' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'zinn_chat_retention', null, 'zinn-chat' );
			as_unschedule_all_actions( 'zinn_chat_waiting_ping', null, 'zinn-chat' );
		}
		delete_option( 'zinn_chat_rewrite' );
	}

	/**
	 * Uninstall: remove everything this plugin stored — its tables, options, capabilities and
	 * scheduled work. (WordPress calls this only when the owner deletes the plugin.)
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		self::deactivate();
		Schema::drop();
		Capabilities::revoke();
		foreach ( array( Settings::OPTION, 'zinn_chat_version', 'zinn_chat_presence', 'zinn_chat_licence', 'zinn_chat_caps', 'zinn_chat_rewrite', 'zinn_chat_index_cursor' ) as $option ) {
			delete_option( $option );
		}
		foreach ( array( 'zinn_chat_embed_blocked', 'zinn_chat_last_ai_failure', 'zinn_chat_activated', 'zinn_chat_sweep_scheduled', 'zinn_chat_retention_scheduled', 'zinn_chat_db_error' ) as $transient ) {
			delete_transient( $transient );
		}
		wp_clear_scheduled_hook( 'zinn_chat_licence_check' );
		/**
		 * Zinn Chat is being uninstalled (Pro removes its own data here).
		 */
		do_action( 'zinn_chat_uninstall' );
	}
}
