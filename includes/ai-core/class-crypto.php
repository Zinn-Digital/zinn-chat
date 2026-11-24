<?php
/**
 * Encryption of API keys at rest.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-crypto.php by wp/bin/build-ai-core.php.
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
 * Seals API keys before they reach the database (feature ai-6).
 *
 * ⭐ The encryption key is 32 random bytes made once per site with `random_bytes()` and kept in
 * its own option, which is NOT autoloaded: it is read only when a key is sealed or opened, never
 * on every page. In a self-hosted build the option is `SHARED_KEY_OPTION`, shared by every plugin
 * that carries this core, as `zinn_ai_core` is, which is what lets Tranzly read a key that Page
 * Builder Sandwich saved (feature ai-10). The wordpress.org build reads only this host's own,
 * prefixed option (`key_option()`).
 *
 * ⭐ Values from before this key existed (`v1:`) were sealed with a key derived from the site's
 * salts. A self-hosted build still opens them, and the store seals them again with the new key the
 * first time it reads them (`Store::key()`), so the old derivation is used only while an old value
 * is still stored. The wordpress.org build carries no salt derivation at all: it never shipped one.
 *
 * ⛔ If the key option is lost, the stored keys can no longer be opened. That is reported as
 * "enter your key again", never as a crash or a silently empty key.
 */
final class Crypto {

	/** Version tag on a value sealed with the site's random key. */
	private const PREFIX = 'v2:';

	/** Version tag on a value sealed by an older core, with the salt-derived key. */
	private const LEGACY_PREFIX = 'v1:';

	/** This host's own key option, base64 (the render writes the host prefix). */
	public const HOST_KEY_OPTION = 'zinn_chat_ai_core_key';

	// dotorg-omit:begin
	/** The key option shared by every self-hosted copy of this core, base64. */
	public const SHARED_KEY_OPTION = 'zinn_ai_core_key';

	/** HKDF context of the older, salt-derived key. Kept only to open `v1:` values. */
	private const LEGACY_INFO = 'zinn-ai-core/keys/v1';
	// dotorg-omit:end

	/**
	 * The random key, once read this request.
	 *
	 * @var string|null
	 */
	private static ?string $key = null;

	/**
	 * Seal a plaintext key.
	 *
	 * @param string $plain Plain API key.
	 * @return string `v2:` + base64(nonce . ciphertext).
	 */
	public static function seal( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );

		return self::PREFIX . sodium_bin2base64( $nonce . $box, SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/**
	 * Open a sealed key.
	 *
	 * @param string $sealed Sealed value.
	 * @return string|null Null when it cannot be opened (key lost, damaged, or written by a newer version).
	 */
	public static function open( string $sealed ): ?string {
		if ( str_starts_with( $sealed, self::PREFIX ) ) {
			$key = self::stored_key();
			return null === $key ? null : self::unbox( substr( $sealed, strlen( self::PREFIX ) ), $key );
		}
		if ( ! str_starts_with( $sealed, self::LEGACY_PREFIX ) ) {
			return null;
		}
		$body  = substr( $sealed, strlen( self::LEGACY_PREFIX ) );
		$key   = self::stored_key();
		$plain = null === $key ? null : self::unbox( $body, $key );
		// dotorg-omit:begin
		if ( null === $plain ) {
			$plain = self::unbox( $body, self::legacy_key() );
		}
		// dotorg-omit:end

		return $plain;
	}

	/**
	 * The option that holds the random key: the shared one in a self-hosted build, this host's
	 * own in the wordpress.org build.
	 *
	 * @return string
	 */
	public static function key_option(): string {
		$option = self::HOST_KEY_OPTION;
		// dotorg-omit:begin
		$option = self::SHARED_KEY_OPTION;
		// dotorg-omit:end

		return $option;
	}

	/**
	 * Was this value sealed by an older core, so it should be sealed again with the random key?
	 *
	 * @param string $sealed Sealed value.
	 * @return bool
	 */
	public static function is_legacy( string $sealed ): bool {
		return str_starts_with( $sealed, self::LEGACY_PREFIX );
	}

	/**
	 * Is this value written in a format this version understands?
	 *
	 * @param string $sealed Sealed value.
	 * @return bool
	 */
	public static function understood( string $sealed ): bool {
		return str_starts_with( $sealed, self::PREFIX ) || str_starts_with( $sealed, self::LEGACY_PREFIX );
	}

	/**
	 * The last four characters, for "…abcd" in the screen, API-token characters only. Never more.
	 *
	 * @param string $plain Plain key.
	 * @return string
	 */
	public static function hint( string $plain ): string {
		if ( strlen( $plain ) <= 8 ) {
			return '';
		}
		// The hint is shown back to the admin, so it keeps API-token characters only.
		// The key itself is sealed byte-exact: sanitizing it would corrupt it.
		return sanitize_text_field( (string) preg_replace( '#[^A-Za-z0-9._~+/=-]#', '', substr( $plain, -4 ) ) );
	}

	/**
	 * Forget the key read this request (tests, and after the option is replaced).
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$key = null;
	}

	/**
	 * Open the base64 body of a sealed value with one key.
	 *
	 * @param string $body Base64 of nonce . ciphertext.
	 * @param string $key  32-byte key.
	 * @return string|null
	 */
	private static function unbox( string $body, string $key ): ?string {
		try {
			$raw = sodium_base642bin( $body, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException $e ) {
			return null;
		}
		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);

		return false === $plain ? null : $plain;
	}

	/**
	 * The site's random key when one has been made, without making one. Opening only ever READS:
	 * a public request that shows a provider's state (Tranzly's `/languages`) must not write an
	 * option (wp-zip-check Z008, A002), and nothing can have been sealed before the key existed.
	 *
	 * @return string|null
	 */
	private static function stored_key(): ?string {
		if ( null === self::$key ) {
			self::$key = self::decode_key( get_option( self::key_option(), '' ) );
		}

		return self::$key;
	}

	/**
	 * The site's random 32-byte key, made the first time a key is sealed.
	 *
	 * @return string
	 */
	private static function key(): string {
		$key = self::stored_key();
		if ( null === $key ) {
			$made = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			// Not autoloaded: the key is read only when a key is sealed or opened.
			if ( add_option( self::key_option(), sodium_bin2base64( $made, SODIUM_BASE64_VARIANT_ORIGINAL ), '', false ) ) {
				$key = $made;
			} else {
				// Another request made it first: use that one, so both agree.
				$key = self::decode_key( get_option( self::key_option(), '' ) ) ?? $made;
			}
		}
		self::$key = $key;

		return $key;
	}

	/**
	 * A stored key, decoded, or null when it is missing or not 32 bytes.
	 *
	 * @param mixed $stored Option value.
	 * @return string|null
	 */
	private static function decode_key( $stored ): ?string {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		try {
			$key = sodium_base642bin( $stored, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException $e ) {
			return null;
		}

		return SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $key ) ? $key : null;
	}

	// dotorg-omit:begin
	/**
	 * The older key, derived from the site's salts. Only ever used to open a `v1:` value.
	 *
	 * @return string
	 */
	private static function legacy_key(): string {
		return hash_hkdf( 'sha256', wp_salt( 'auth' ), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::LEGACY_INFO );
	}
	// dotorg-omit:end
}
