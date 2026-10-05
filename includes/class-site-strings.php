<?php
/**
 * The site owner's own chat texts, in the visitor's language.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The words a site owner types into the settings (the greeting, the offline message, the chat
 * title, the agreement text, the assistant's name, Pro's proactive messages) are ONE stored
 * string each. Until 2.10.12 a multilingual site showed them in that one language on every
 * language of the site (seen on demo.zinnchat.com /ar/ and /de/, 2026-10-05).
 *
 * Each text is now offered to the site's multilingual plugin the way that plugin expects, and
 * shown in the visitor's language:
 *
 * - Tranzly: listed with the site's own texts (scope `site`, key `text.<sha1 of the text>`), so
 *   Tranzly translates it with the rest and keeps it in its per-language string store;
 * - WPML: `wpml_register_single_string` / `wpml_translate_single_string`, context "Zinn® Chat";
 * - Polylang: `pll_register_string` / `pll_translate_string`, group "Zinn® Chat";
 * - no multilingual plugin: the owner's own per-language versions (setting `texts`).
 *
 * Order when a visitor reads one: the owner's own version for that language, then WPML or
 * Polylang, then Tranzly, then the text as typed.
 *
 * ⛔ The language comes from the PAGE. The chat's words arrive over REST, which has no page, so
 * the REST routes pass the page language the widget sends (`page_lang`) to use_language().
 */
final class Site_Strings {

	/** WPML context and Polylang group. */
	public const CONTEXT = 'Zinn® Chat';

	/** Settings key holding the owner's own versions: language => name => text. */
	public const SETTING = 'texts';

	/** Option: hash of the texts last registered with WPML (registration is stored by WPML). */
	public const WPML_HASH = 'zinn_chat_wpml_strings';

	/** The settings that are text a visitor reads. */
	public const NAMES = array( 'title', 'greeting', 'offline_greeting', 'assistant_name', 'consent_text' );

	/** Most languages the own-versions setting keeps. */
	public const MAX_LANGUAGES = 100;

	/**
	 * The language forced for this request (the page's, on REST), or null.
	 *
	 * @var string|null
	 */
	private static ?string $language = null;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'tranzly_shared_strings', array( self::class, 'tranzly_sources' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'register_with_plugins' ) );
	}

	/**
	 * The settings that are text a visitor reads, with their labels: name => label.
	 *
	 * @return array<string, string>
	 */
	public static function fields(): array {
		return array(
			'title'            => __( 'Chat title', 'zinn-chat' ),
			'greeting'         => __( 'Greeting', 'zinn-chat' ),
			'offline_greeting' => __( 'Greeting when nobody can answer', 'zinn-chat' ),
			'assistant_name'   => __( 'Assistant name', 'zinn-chat' ),
			'consent_text'     => __( 'Agreement text', 'zinn-chat' ),
		);
	}

	/**
	 * Every owner text a visitor can read now: name => text. Empty settings are left out (the
	 * plugin's own default is translated with the plugin).
	 *
	 * @return array<string, string>
	 */
	public static function sources(): array {
		$out = array();
		foreach ( self::NAMES as $name ) {
			$text = trim( (string) Settings::get( $name, '' ) );
			if ( '' !== $text ) {
				$out[ $name ] = $text;
			}
		}

		/**
		 * Filters the owner texts offered for translation (Pro adds its proactive messages).
		 *
		 * @param array<string, string> $out name => text.
		 */
		$out = (array) apply_filters( 'zinn_chat_site_strings', $out );

		return array_filter(
			array_map( static fn( $text ) => trim( (string) $text ), $out ),
			static fn( string $text ) => '' !== $text
		);
	}

	/**
	 * `tranzly_shared_strings`: list the texts with the site's own texts, keyed as Tranzly's
	 * string store looks shared text up (`text.<sha1>`).
	 *
	 * @param mixed  $sources Key => source text.
	 * @param string $scope   Tranzly's scope.
	 * @return mixed
	 */
	public static function tranzly_sources( $sources, $scope = '' ) {
		if ( 'site' !== $scope || ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( self::sources() as $text ) {
			$sources[ self::tranzly_key( $text ) ] = $text;
		}
		return $sources;
	}

	/**
	 * Tranzly's key for a text.
	 *
	 * @param string $text The text.
	 * @return string
	 */
	public static function tranzly_key( string $text ): string {
		return 'text.' . sha1( $text );
	}

	/**
	 * Register the texts with WPML and Polylang (wp-admin only, where their screens list them).
	 *
	 * Polylang keeps its list in memory, so it is told on every admin request; WPML stores what
	 * it is told, so it is told again only when a text changed.
	 *
	 * @return void
	 */
	public static function register_with_plugins(): void {
		$sources = self::sources();
		if ( function_exists( 'pll_register_string' ) ) {
			foreach ( $sources as $name => $text ) {
				pll_register_string( $name, $text, self::CONTEXT, str_contains( $text, "\n" ) );
			}
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$hash = md5( (string) wp_json_encode( $sources ) );
			if ( get_option( self::WPML_HASH ) !== $hash ) {
				foreach ( $sources as $name => $text ) {
					do_action( 'wpml_register_single_string', self::CONTEXT, $name, $text ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook (Polylang answers it too).
				}
				update_option( self::WPML_HASH, $hash, false );
			}
		}
	}

	/**
	 * Use this language for the rest of the request (the page's, sent by the widget), or null to
	 * go back to the multilingual plugin's.
	 *
	 * @param string|null $tag A language tag (`ar`, `de-DE`, `pt_BR`).
	 * @return void
	 */
	public static function use_language( ?string $tag ): void {
		$tag            = null === $tag ? '' : self::normalise( $tag );
		self::$language = '' === $tag ? null : $tag;
	}

	/**
	 * The visitor's language, normalised (`de_de`): the page's on REST, else the multilingual
	 * plugin's current language, else WordPress's locale.
	 *
	 * @return string
	 */
	public static function language(): string {
		if ( null !== self::$language ) {
			return self::$language;
		}
		$wpml = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook (Polylang answers it too).
		if ( is_string( $wpml ) && '' !== self::normalise( $wpml ) ) {
			return self::normalise( $wpml );
		}
		if ( function_exists( 'pll_current_language' ) ) {
			$pll = pll_current_language( 'locale' );
			if ( is_string( $pll ) && '' !== self::normalise( $pll ) ) {
				return self::normalise( $pll );
			}
		}
		return self::normalise( (string) determine_locale() );
	}

	/**
	 * A text in the visitor's language (or the given one), falling back to the text as typed.
	 *
	 * @param string      $name The setting name (`greeting`, `proactive_…`).
	 * @param string      $text The text as typed.
	 * @param string|null $lang A language; the visitor's when null.
	 * @return string
	 */
	public static function translate( string $name, string $text, ?string $lang = null ): string {
		$lang = null === $lang ? self::language() : self::normalise( $lang );
		$own  = self::own( $name, $lang );
		if ( '' !== $own ) {
			return $own;
		}
		$text = trim( $text );
		if ( '' === $text || '' === $lang ) {
			return $text;
		}
		foreach ( self::candidates( $lang ) as $tag ) {
			$code = str_replace( '_', '-', $tag );
			$wpml = apply_filters( 'wpml_translate_single_string', $text, self::CONTEXT, $name, $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook (Polylang answers it too).
			if ( is_string( $wpml ) && '' !== trim( $wpml ) && $wpml !== $text ) {
				return $wpml;
			}
			if ( function_exists( 'pll_translate_string' ) ) {
				$pll = pll_translate_string( $text, $code );
				if ( is_string( $pll ) && '' !== trim( $pll ) && $pll !== $text ) {
					return $pll;
				}
			}
		}
		$tranzly = self::from_tranzly( $text, $lang );
		return '' !== $tranzly ? $tranzly : $text;
	}

	/**
	 * The owner's own version of a text for a language, or ''.
	 *
	 * @param string $name A setting name.
	 * @param string $lang A language (normalised or not).
	 * @return string
	 */
	public static function own( string $name, string $lang ): string {
		$texts = Settings::get( self::SETTING, array() );
		if ( ! is_array( $texts ) ) {
			return '';
		}
		foreach ( self::candidates( self::normalise( $lang ) ) as $tag ) {
			$value = $texts[ $tag ][ $name ] ?? '';
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		return '';
	}

	/**
	 * Clean the own-versions setting from its form rows (`[ ['lang' => 'de', 'greeting' => …] ]`)
	 * or from the stored shape (`[ 'de' => [ 'greeting' => … ] ]`).
	 *
	 * @param mixed $input Raw value.
	 * @return array<string, array<string, string>>
	 */
	public static function clean( $input ): array {
		$out = array();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		$areas = array( 'greeting', 'offline_greeting', 'consent_text' );
		foreach ( $input as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$lang = self::normalise( (string) ( $row['lang'] ?? ( is_string( $key ) ? $key : '' ) ) );
			if ( '' === $lang ) {
				continue;
			}
			$clean = array();
			foreach ( self::NAMES as $name ) {
				$value = trim( (string) ( $row[ $name ] ?? '' ) );
				if ( '' === $value ) {
					continue;
				}
				$clean[ $name ] = in_array( $name, $areas, true )
					? mb_substr( sanitize_textarea_field( $value ), 0, 4000 )
					: mb_substr( sanitize_text_field( $value ), 0, 120 );
			}
			if ( array() !== $clean ) {
				$out[ $lang ] = array_merge( $out[ $lang ] ?? array(), $clean );
			}
			if ( count( $out ) >= self::MAX_LANGUAGES ) {
				break;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * A language tag in one spelling (`de-DE`, `DE_de` → `de_de`), or '' when it is not one.
	 *
	 * @param string $tag A language tag.
	 * @return string
	 */
	public static function normalise( string $tag ): string {
		$tag = strtolower( str_replace( '-', '_', trim( $tag ) ) );
		return 1 === preg_match( '/^[a-z]{2,3}(?:_[a-z0-9]{2,8}){0,2}$/', $tag ) ? $tag : '';
	}

	/**
	 * The tags to try for a language: itself, then its language alone (`de_de`, `de`).
	 *
	 * @param string $lang A normalised tag.
	 * @return array<int, string>
	 */
	private static function candidates( string $lang ): array {
		if ( '' === $lang ) {
			return array();
		}
		$primary = explode( '_', $lang )[0];
		return array_values( array_unique( array( $lang, $primary ) ) );
	}

	/**
	 * Tranzly's translation of a text in a language, or ''.
	 *
	 * @param string $text The text as typed.
	 * @param string $lang A normalised tag.
	 * @return string
	 */
	private static function from_tranzly( string $text, string $lang ): string {
		if ( ! class_exists( '\ZinnDigital\Tranzly\Core\Strings' ) || ! class_exists( '\ZinnDigital\Tranzly\Languages' ) ) {
			return '';
		}
		foreach ( self::candidates( $lang ) as $tag ) {
			$code = \ZinnDigital\Tranzly\Languages::resolve( $tag );
			if ( null === $code ) {
				continue;
			}
			$value = \ZinnDigital\Tranzly\Core\Strings::all( $code )[ self::tranzly_key( $text ) ] ?? '';
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return $value;
			}
		}
		return '';
	}
}
