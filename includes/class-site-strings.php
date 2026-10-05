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
		foreach ( self::multilingual_codes( $lang ) as $code ) {
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
		$with = array_keys( array_filter( $texts, static fn( $row ): bool => is_array( $row ) && is_string( $row[ $name ] ?? null ) && '' !== trim( $row[ $name ] ) ) );
		$tag  = self::best_match( $lang, array_map( 'strval', $with ) );
		return '' === $tag ? '' : trim( (string) $texts[ $tag ][ $name ] );
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
	 * The tag in `$available` that best serves a reader of `$lang`, in its own spelling, or ''.
	 *
	 * Page tags, WordPress locales and multilingual plugins spell one language several ways: a
	 * page says `de` where Tranzly lists `de_DE`, or `de-AT` where the site has German only as
	 * `de_DE`. In this order:
	 *
	 * 1. the same tag, in any spelling (`de-DE` = `de_DE` = `DE_de`);
	 * 2. the language alone (`de` for `de-AT`);
	 * 3. the language's usual form (`de` → `de_DE`, `en` → `en_US`, `pt` → `pt_BR`, `zh` → `zh_CN`);
	 * 4. any other form of it, alphabetically (so the answer never depends on list order).
	 *
	 * ⛔ Never across a separate written standard: Traditional Chinese (`zh_TW`, `zh_HK`, `zh_MO`,
	 * `zh_Hant…`) and Simplified (`zh`, `zh_CN`, `zh_SG`, `zh_Hans…`) never stand in for each
	 * other, and neither do Brazilian (`pt_BR`) and European Portuguese (`pt_PT` and every other
	 * region). Only a BARE `pt` may be served by either (Brazilian first, as CLDR's likely subtags
	 * say), because a page that says only "Portuguese" names no standard. The text as typed is a
	 * better answer than the other standard.
	 *
	 * @param string            $lang      A language tag (`de`, `de-DE`, `zh_Hant_TW`).
	 * @param array<int, mixed> $available Tags to choose from, in any spelling.
	 * @return string One of `$available` as given, or '' when none serves this reader.
	 */
	public static function best_match( string $lang, array $available ): string {
		$want = self::normalise( $lang );
		if ( '' === $want ) {
			return '';
		}
		$spelled = array();
		foreach ( $available as $tag ) {
			$key = is_string( $tag ) ? self::normalise( $tag ) : '';
			if ( '' !== $key && ! isset( $spelled[ $key ] ) ) {
				$spelled[ $key ] = $tag;
			}
		}
		if ( isset( $spelled[ $want ] ) ) {
			return $spelled[ $want ];
		}
		$same = array_values( array_filter( array_keys( $spelled ), static fn( string $tag ): bool => self::serves( $tag, $want ) ) );
		if ( array() === $same ) {
			return '';
		}
		$primary = explode( '_', $want )[0];
		foreach ( array( $primary, self::usual_form( $want ) ) as $preferred ) {
			if ( in_array( $preferred, $same, true ) ) {
				return $spelled[ $preferred ];
			}
		}
		sort( $same, SORT_STRING );
		return $spelled[ $same[0] ];
	}

	/**
	 * May a text in `$tag` be shown to a reader of `$want` (both normalised, not equal)?
	 *
	 * @param string $tag  A tag the site has.
	 * @param string $want The reader's tag.
	 * @return bool
	 */
	private static function serves( string $tag, string $want ): bool {
		$primary = explode( '_', $want )[0];
		if ( explode( '_', $tag )[0] !== $primary ) {
			return false;
		}
		if ( 'zh' !== $primary && ( $tag === $primary || $want === $primary ) ) {
			return true; // The language alone, either way round (never for Chinese: bare `zh` is Simplified).
		}
		return self::standard( $tag ) === self::standard( $want );
	}

	/**
	 * The written standard of a tag: `zh_hant` / `zh_hans`, `pt_br` / `pt_pt`, else the language.
	 *
	 * @param string $tag A normalised tag.
	 * @return string
	 */
	private static function standard( string $tag ): string {
		$parts   = explode( '_', $tag );
		$primary = array_shift( $parts );
		if ( 'zh' === $primary ) {
			return array() !== array_intersect( $parts, array( 'hant', 'tw', 'hk', 'mo' ) ) ? 'zh_hant' : 'zh_hans';
		}
		if ( 'pt' === $primary && array() !== $parts ) {
			return in_array( 'br', $parts, true ) ? 'pt_br' : 'pt_pt';
		}
		return $primary;
	}

	/**
	 * A language's usual form (CLDR likely subtags, as WordPress spells locales): `de_de`,
	 * `en_us`, `pt_br` (`pt_pt` for a European reader), `zh_cn` / `zh_tw`.
	 *
	 * @param string $want A normalised tag.
	 * @return string
	 */
	private static function usual_form( string $want ): string {
		$standard = self::standard( $want );
		$usual    = array(
			'zh_hans' => 'zh_cn',
			'zh_hant' => 'zh_tw',
			'pt'      => 'pt_br',
			'pt_br'   => 'pt_br',
			'pt_pt'   => 'pt_pt',
			'en'      => 'en_us',
			'ar'      => 'ar_sa',
			'bn'      => 'bn_bd',
			'cs'      => 'cs_cz',
			'da'      => 'da_dk',
			'el'      => 'el_gr',
			'fa'      => 'fa_ir',
			'he'      => 'he_il',
			'hi'      => 'hi_in',
			'ja'      => 'ja_jp',
			'ka'      => 'ka_ge',
			'ko'      => 'ko_kr',
			'ms'      => 'ms_my',
			'nb'      => 'nb_no',
			'sr'      => 'sr_rs',
			'sv'      => 'sv_se',
			'uk'      => 'uk_ua',
			'vi'      => 'vi_vn',
		);
		return $usual[ $standard ] ?? $standard . '_' . $standard;
	}

	/**
	 * The codes to ask WPML / Polylang for a language: the best of the languages they list
	 * (`wpml_active_languages`, which Polylang answers too, by code and by locale), else the tag
	 * and its language alone, as they spell codes (`de-de`, `de`).
	 *
	 * @param string $lang A normalised tag.
	 * @return array<int, string>
	 */
	private static function multilingual_codes( string $lang ): array {
		$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook (Polylang answers it too).
		if ( is_array( $active ) && array() !== $active ) {
			$by = array();
			foreach ( $active as $key => $language ) {
				$code        = (string) ( is_array( $language ) ? ( $language['code'] ?? $key ) : $key );
				$by[ $code ] = $code;
				$locale      = is_array( $language ) ? (string) ( $language['default_locale'] ?? '' ) : '';
				if ( '' !== $locale && ! isset( $by[ $locale ] ) ) {
					$by[ $locale ] = $code;
				}
			}
			$best = self::best_match( $lang, array_map( 'strval', array_keys( $by ) ) );
			return '' === $best ? array() : array( $by[ $best ] );
		}
		$primary = explode( '_', $lang )[0];
		$tags    = array( $lang );
		if ( self::serves( $primary, $lang ) ) {
			$tags[] = $primary;
		}
		return array_map( static fn( string $tag ): string => str_replace( '_', '-', $tag ), array_values( array_unique( $tags ) ) );
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
		$code = self::best_match( $lang, array_column( \ZinnDigital\Tranzly\Languages::all(), 'code' ) );
		if ( '' === $code ) {
			return '';
		}
		$value = \ZinnDigital\Tranzly\Core\Strings::all( $code )[ self::tranzly_key( $text ) ] ?? '';
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return $value;
		}
		return '';
	}
}
