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
 * ⭐ The encryption key is derived from the site's own salts, which live in `wp-config.php`,
 * not in the database. So a leaked database dump or a stolen backup of the options table does
 * not reveal a working key on its own. The derivation is the SAME in every copy of this core,
 * which is what lets Tranzly read a key that Page Builder Sandwich saved (feature ai-10).
 *
 * ⛔ If the salts change (a security reset rotates them) the stored keys can no longer be
 * opened. That is reported as "enter your key again", never as a crash or a silently empty key.
 */
final class Crypto {

	/** Version tag on every sealed value; an unknown tag means a newer core wrote it. */
	private const PREFIX = 'v1:';

	/** HKDF context string. Changing it makes every stored key unreadable. */
	private const INFO = 'zinn-ai-core/keys/v1';

	/**
	 * Seal a plaintext key.
	 *
	 * @param string $plain Plain API key.
	 * @return string `v1:` + base64(nonce . ciphertext).
	 */
	public static function seal( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );

		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
	}

	/**
	 * Open a sealed key.
	 *
	 * @param string $sealed Sealed value.
	 * @return string|null Null when it cannot be opened (salts changed, damaged, or written by a newer version).
	 */
	public static function open( string $sealed ): ?string {
		if ( ! str_starts_with( $sealed, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see seal().
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);

		return false === $plain ? null : $plain;
	}

	/**
	 * Is this value written in a format this version understands?
	 *
	 * @param string $sealed Sealed value.
	 * @return bool
	 */
	public static function understood( string $sealed ): bool {
		return str_starts_with( $sealed, self::PREFIX );
	}

	/**
	 * The last four characters, for "…abcd" in the screen. Never more.
	 *
	 * @param string $plain Plain key.
	 * @return string
	 */
	public static function hint( string $plain ): string {
		return strlen( $plain ) > 8 ? substr( $plain, -4 ) : '';
	}

	/**
	 * The 32-byte encryption key.
	 *
	 * @return string
	 */
	private static function key(): string {
		return hash_hkdf( 'sha256', wp_salt( 'auth' ), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::INFO );
	}
}
