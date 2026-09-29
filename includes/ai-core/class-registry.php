<?php
/**
 * The providers we know how to talk to.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-registry.php by wp/bin/build-ai-core.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\ZinnChat\AiCore
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\AiCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider presets.
 *
 * ⭐ The facts about each provider (addresses, where a key is created, where the bill is paid,
 * the status page) are DATA in `data/providers.json`, measured and dated, never string literals
 * scattered through the code. A provider moving its console is then a one-line data change, and
 * the failure messages, the setup wizard and the readme's external-services list all read the
 * same row.
 */
final class Registry {

	/** The any-OpenAI-compatible-server preset (feature ai-1: "including self-hosted/local"). */
	public const CUSTOM = 'custom';

	/**
	 * Decoded presets.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $presets = null;

	/**
	 * Every preset, keyed by provider id, in display order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null === self::$presets ) {
			$raw           = (string) file_get_contents( __DIR__ . '/data/providers.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside this plugin.
			$data          = json_decode( $raw, true );
			self::$presets = array();
			foreach ( is_array( $data ) ? $data : array() as $id => $row ) {
				if ( is_string( $id ) && is_array( $row ) && '$' !== $id[0] ) {
					self::$presets[ $id ] = $row;
				}
			}
			// Every other label is a company's name and stays as written; this one is a
			// description, so it must be translatable like any other sentence (§2.19).
			if ( isset( self::$presets[ self::CUSTOM ] ) ) {
				self::$presets[ self::CUSTOM ]['label'] = __( 'Custom (OpenAI-compatible)', 'zinn-chat' );
			}
		}

		return self::$presets;
	}

	/**
	 * One preset.
	 *
	 * @param string $id Provider id.
	 * @return array<string, mixed> Empty when unknown.
	 */
	public static function get( string $id ): array {
		return self::all()[ $id ] ?? array();
	}

	/**
	 * Is this a provider id we know?
	 *
	 * @param string $id Provider id.
	 * @return bool
	 */
	public static function exists( string $id ): bool {
		return array() !== self::get( $id );
	}

	/**
	 * The adapter for a provider, with the site owner's saved settings applied.
	 *
	 * @param string               $id       Provider id.
	 * @param array<string, mixed> $settings Saved provider settings (base_url for custom).
	 * @return Provider|null
	 */
	public static function adapter( string $id, array $settings = array() ): ?Provider {
		$spec = self::get( $id );
		if ( array() === $spec ) {
			return null;
		}
		if ( self::CUSTOM === $id && isset( $settings['base_url'] ) && is_string( $settings['base_url'] ) ) {
			$spec['api_base'] = $settings['base_url'];
		}

		switch ( (string) ( $spec['adapter'] ?? '' ) ) {
			case 'anthropic':
				return new Anthropic( $id, $spec );
			case 'gemini':
				return new Gemini( $id, $spec );
			default:
				return new Openai_Compatible( $id, $spec );
		}
	}
}
