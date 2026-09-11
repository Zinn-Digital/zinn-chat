<?php
/**
 * The plugin's one option, and the only place its shape is written down.
 *
 * @package ZinnChat
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stored settings for Zinn® Chat.
 *
 * ⛔ ONE option row, not six. Each `add_option` is a row in `wp_options`, and options
 * without `autoload=no` are loaded on every single request of every page — so a plugin
 * that spreads its settings across half a dozen keys taxes a site whether or not the
 * feature is in use. One serialised array is one read.
 */
final class Zinn_Chat_Settings {

	public const OPTION = 'zinn_chat_settings';

	/**
	 * Defaults. ⛔ `enabled` is FALSE, and that is the consent design rather than caution:
	 * this plugin ships in the deploy footprint of every site Zinn hosts, so it exists on
	 * sites whose owner never asked for it.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'    => false,
			'public_key' => '',
			'api_base'   => 'https://api.zinndigital.com',
			/**
			 * What the widget draws before it has spoken to anybody. Cached from the
			 * dashboard so the visitor's browser never has to ask for it — see the
			 * plugin header's note about paying a round trip for something we hold.
			 *
			 * @var array<string, mixed>
			 */
			'config'     => array(),
		);
	}

	/**
	 * The current settings, defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key      Setting name.
	 * @param mixed  $fallback  Value when it is not set.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Whether the widget should render at all.
	 *
	 * ⛔ BOTH conditions, and the key check is not redundant. A site that ticked the box
	 * and then cleared the key would otherwise emit a script tag naming an empty widget,
	 * which loads the file, finds nothing, and costs the visitor the bytes for no chat.
	 */
	public static function is_live(): bool {
		$all = self::all();
		return ! empty( $all['enabled'] ) && '' !== trim( (string) $all['public_key'] );
	}

	/**
	 * Save, sanitising every field.
	 *
	 * ⛔ Sanitised HERE rather than at the form, so a value written by WP-CLI or by our
	 * own provisioning gets the same treatment as one a human typed. A validator that
	 * only guards the screen guards one of the three ways this option is written.
	 *
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed> What was stored.
	 */
	public static function save( array $input ): array {
		$current = self::all();

		$key = isset( $input['public_key'] ) ? trim( (string) $input['public_key'] ) : $current['public_key'];
		// The engine mints these as `zc_` + URL-safe base64; anything else is a paste
		// accident and is rejected rather than stored to fail silently later.
		if ( '' !== $key && ! preg_match( '/^zc_[A-Za-z0-9_-]{8,64}$/', $key ) ) {
			$key = '';
		}

		$api = isset( $input['api_base'] ) ? esc_url_raw( trim( (string) $input['api_base'] ) ) : $current['api_base'];
		if ( '' === $api ) {
			$api = self::defaults()['api_base'];
		}

		$config = isset( $input['config'] ) && is_array( $input['config'] ) ? $input['config'] : $current['config'];

		$clean = array(
			'enabled'    => ! empty( $input['enabled'] ),
			'public_key' => $key,
			'api_base'   => untrailingslashit( $api ),
			'config'     => self::clean_config( $config ),
		);

		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Keep only the fields the widget reads, with the shapes it expects.
	 *
	 * ⛔ An allow-list, not a sanitiser pass over whatever arrived. This value is printed
	 * into a page as a JSON attribute; anything not on this list has no business being
	 * there, and "clean whatever we were given" is how an unexpected key ends up in front
	 * of a visitor.
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
			'ai_enabled'    => ! isset( $config['ai_enabled'] ) || (bool) $config['ai_enabled'],
			'online'        => ! empty( $config['online'] ),
		);
	}
}
