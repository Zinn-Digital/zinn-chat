<?php
/**
 * Connected mode: the Zinn Digital hosted inbox (the 1.x behaviour), kept working.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For a V2 hosting customer who answers chats from the Zinn app: the widget is Zinn Digital's
 * hosted embed and the conversations live in the Zinn dashboard, exactly as in 1.x. This class
 * refreshes the widget's cached appearance from the dashboard once a day and whenever the
 * settings are saved, so the visitor's browser never has to ask for it.
 */
final class Connect {

	public const CRON_HOOK = 'zinn_chat_refresh_config';

	/** Seconds to wait for our API. Short: nobody's save should hang on it. */
	private const TIMEOUT = 6;

	/**
	 * Re-entrancy guard: refresh() writes the option whose update hook calls refresh().
	 *
	 * @var bool
	 */
	private static bool $running = false;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( self::class, 'refresh' ) );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'refresh' ) );
		add_action( 'add_option_' . Settings::OPTION, array( self::class, 'refresh' ) );
	}

	/**
	 * Schedule the daily refresh (activation, and when connected mode is chosen).
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + wp_rand( 60, DAY_IN_SECONDS ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the daily refresh.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Fetch the widget's appearance from the dashboard and cache it.
	 *
	 * @return bool Whether it was refreshed.
	 */
	public static function refresh(): bool {
		if ( self::$running || ! Settings::connected() ) {
			return false;
		}
		$settings = Settings::all();
		$key      = trim( (string) $settings['public_key'] );
		if ( '' === $key ) {
			return false;
		}
		$url      = untrailingslashit( (string) $settings['api_base'] ) . '/v1/public/chat/' . rawurlencode( $key ) . '/config?locale=' . rawurlencode( determine_locale() );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['widget_id'] ) ) {
			return false;
		}
		self::$running = true;
		try {
			Settings::save( array( 'config' => $body ) );
		} finally {
			self::$running = false;
		}
		return true;
	}
}
