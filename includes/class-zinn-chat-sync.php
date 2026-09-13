<?php
/**
 * Keep this site's cached copy of the widget's appearance in step with the dashboard.
 *
 * @package ZinnChat
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches the widget's public config and stores it in the plugin's one option.
 *
 * ⛔⛔ **WITHOUT THIS THE PLUGIN'S HEADLINE CLAIM IS FALSE, AND NOTHING SAID SO** (D25511,
 * W43-17, 2026-09-12). `zinn-chat.php` promises the config *"travels INLINE as a data
 * attribute — so a page nobody chats on makes not one network request on the widget's
 * behalf"*, and `marketplace-web/public/embed/zinn-chat.js` says the same from the other
 * end: *"the WordPress plugin always can [supply `data-config`]"*. It never could. Nothing
 * on the estate wrote `config`: `Zinn_Chat_Admin::sanitize()` passes the CURRENT value
 * straight back, so the array a fresh install starts empty stayed empty for ever,
 * `Zinn_Chat_Widget::attributes()`'s `if ( ! empty( $config['widget_id'] ) )` never fired,
 * and every visitor to every hosted WordPress site paid a round trip to
 * `api.zinndigital.com` **before the launcher could be drawn**.
 *
 * ⭐⭐ **§2.38 in one option key: a producer with no consumer.** The engine's
 * `/v1/public/chat/<key>/config` endpoint was built, the allow-list
 * (`Zinn_Chat_Settings::clean_config`) was built, the renderer that reads the cache was
 * built, the JS that prefers it was built — and the one line that *fills* it was not. Each
 * piece is correct and reads as finished on its own, which is why it survived being
 * written and reviewed.
 *
 * ⛔ **NOT on `admin_init`, and that constraint is `Zinn_Chat_Admin`'s, not a preference.**
 * That hook runs on every wp-admin page load; reaching our API from it would make every
 * admin page of a site we may not even host depend on our uptime. So this fires on exactly
 * two occasions — when the settings are SAVED, and once a day from WP-Cron.
 *
 * ⛔ **A FAILED FETCH KEEPS THE OLD CONFIG, NEVER CLEARS IT** (§2.44). An unreachable API,
 * a timeout or a 500 are all *"I could not see"*, and they are indistinguishable from
 * *"there is nothing"* in a response body. Treating them as the latter would blank a
 * working widget's greeting and colour on the day our API had a bad minute — a blind
 * instrument resolving to the destructive answer.
 */
final class Zinn_Chat_Sync {

	/** The daily cron hook. */
	public const CRON_HOOK = 'zinn_chat_refresh_config';

	/** How long we wait for our own API. Short: nobody's save should hang on it. */
	private const TIMEOUT = 6;

	/**
	 * Re-entrancy guard.
	 *
	 * ⛔⛔ **THIS IS NOT DEFENSIVE PROGRAMMING, IT IS THE ONLY THING STOPPING A LOOP.**
	 * `refresh()` ends in `update_option()`, and `update_option()` is what fires the
	 * `update_option_…` hook this class subscribes to — so the method calls itself
	 * through WordPress. It happens to terminate today, because WordPress skips the hook
	 * when the stored value is unchanged and the second pass stores the same bytes; that
	 * is an accident of the payload being stable, not a property of the code, and the day
	 * a config carries a timestamp it becomes an unbounded loop of HTTP requests from
	 * every hosted site at once.
	 *
	 * @var bool
	 */
	private static bool $running = false;

	/**
	 * Register the hooks.
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh' ) );
		// ⭐ `update_option_…` rather than `pre_update_option_…`: the key must already be
		// stored before we go and ask about it, or a first save fetches with the OLD key.
		add_action( 'update_option_' . Zinn_Chat_Settings::OPTION, array( __CLASS__, 'refresh' ) );
		// ⛔ `add_option_…` as well. WordPress fires `update_option_*` only when a row
		// already exists, so a brand-new install — the population this plugin is shipped
		// to — would never sync on its first save. The two hooks are not alternatives.
		add_action( 'add_option_' . Zinn_Chat_Settings::OPTION, array( __CLASS__, 'refresh' ) );
	}

	/**
	 * Start the daily refresh. Called on activation.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// ⭐ A random offset inside the day. Every hosted WordPress site activating this
			// plugin in the same provisioning run would otherwise ask our API at the same
			// second for ever — a self-inflicted thundering herd that grows with the estate
			// (§2.10: the population is the fleet).
			wp_schedule_event( time() + wp_rand( 60, DAY_IN_SECONDS ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Stop the daily refresh. Called on deactivation.
	 */
	public static function unschedule(): void {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	/**
	 * Fetch the widget's public config and store it. Safe to call at any time.
	 *
	 * @return bool Whether a fresh config was stored.
	 */
	public static function refresh(): bool {
		if ( self::$running ) {
			return false;
		}
		$settings = Zinn_Chat_Settings::all();
		$key      = trim( (string) $settings['public_key'] );
		if ( '' === $key ) {
			return false;
		}

		$url = untrailingslashit( (string) $settings['api_base'] )
			. '/v1/public/chat/' . rawurlencode( $key ) . '/config'
			// The widget's words in this site's language; the engine answers the nearest of its
			// 58 languages, or English.
			. '?locale=' . rawurlencode( determine_locale() );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				// ⭐ Named so a 404 in our own logs can be told from a visitor's browser.
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['widget_id'] ) ) {
			// ⛔ `widget_id` is the positive control. A 200 carrying an error envelope, a
			// captive-portal login page or an empty object all decode to *something*;
			// only the id proves we reached the endpoint we meant to (§2.44).
			return false;
		}

		$stored           = Zinn_Chat_Settings::all();
		$stored['config'] = Zinn_Chat_Settings::clean_config( $body );

		self::$running = true;
		try {
			update_option( Zinn_Chat_Settings::OPTION, $stored, false );
		} finally {
			self::$running = false;
		}
		return true;
	}
}
