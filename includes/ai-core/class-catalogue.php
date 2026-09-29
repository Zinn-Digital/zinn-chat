<?php
/**
 * The recommended-models catalogue, bundled and (with consent) refreshed from Zinn Digital.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-catalogue.php by wp/bin/build-ai-core.php.
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
 * Which model is best, cheapest and fastest for each task, per provider (feature ai-3).
 *
 * ⛔⛔ EVERY COPY IS SIGNED AND EVERY COPY IS VERIFIED — THE BUNDLED ONE TOO. The catalogue
 * decides which model a site sends its content to, so a file anyone could edit on the way (a
 * compromised CDN, a man in the middle, a hand-edited plugin folder) would be a way to point a
 * site at a model it never chose. A catalogue whose Ed25519 signature does not verify against a
 * key compiled into this file is REFUSED, and the last good one stays in use (X3).
 *
 * ⛔ And a valid catalogue OLDER than the one in use is refused too: a correctly signed but stale
 * file replayed from a cache must not roll a site back to last year's recommendations.
 *
 * The remote fetch is OFF until the site owner ticks the box (consent), sends nothing but a
 * plain GET with our own User-Agent, and runs at most once a day.
 */
final class Catalogue {

	/** The envelope format this version reads. */
	public const FORMAT = 'zinn-ai-catalogue/1';

	/** Where Zinn Digital publishes the signed catalogue. */
	public const URL = 'https://api.zinndigital.com/v1/ai-model-catalogue';

	/**
	 * The signing keys we trust: key id => base64 Ed25519 public key.
	 *
	 * ⭐ A list so a key can be rotated: ship the new public key in a release, then start signing
	 * with it. The private half lives only in Zinn Digital's vault.
	 */
	private const KEYS = array(
		'zd-cat-2026-09' => 'XPl2f/yMtmUGWXMYN61T+FHVuSPAN6FXf5ZKUAP4eu4=',
	);

	/**
	 * A different bundled file (tests only: the package source keeps it in `catalogue/`).
	 *
	 * @var string|null
	 */
	private static ?string $bundle = null;

	/**
	 * The effective catalogue, per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $current = null;

	/**
	 * Verify a signed envelope and return its payload.
	 *
	 * @param string $envelope The envelope JSON.
	 * @return array<string, mixed>|null Null when anything about it is wrong.
	 */
	public static function verify( string $envelope ): ?array {
		$data = json_decode( $envelope, true );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? null ) ) {
			return null;
		}
		$key_id = $data['key_id'] ?? null;
		if ( ! is_string( $key_id ) || ! isset( self::KEYS[ $key_id ] ) || ! is_string( $data['payload'] ?? null ) || ! is_string( $data['signature'] ?? null ) ) {
			return null;
		}
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signed binary fields.
		$payload   = base64_decode( $data['payload'], true );
		$signature = base64_decode( $data['signature'], true );
		$public    = base64_decode( self::KEYS[ $key_id ], true );
		// phpcs:enable
		if ( false === $payload || false === $signature || false === $public
			|| SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public ) ) {
			return null;
		}
		try {
			if ( ! sodium_crypto_sign_verify_detached( $signature, $payload, $public ) ) {
				return null;
			}
		} catch ( \SodiumException $e ) {
			return null;
		}
		$catalogue = json_decode( $payload, true );
		if ( ! is_array( $catalogue ) || 1 !== ( $catalogue['schema'] ?? null ) || ! is_string( $catalogue['published_at'] ?? null ) || ! is_array( $catalogue['providers'] ?? null ) ) {
			return null;
		}

		return $catalogue;
	}

	/**
	 * The catalogue in use: the newest valid one of the bundled copy and the fetched copy.
	 *
	 * @return array<string, mixed> An empty catalogue when neither verifies.
	 */
	public static function current(): array {
		if ( null !== self::$current ) {
			return self::$current;
		}
		$bundled = self::verify( (string) file_get_contents( self::$bundle ?? __DIR__ . '/catalogue.signed.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside this plugin.
		$stored  = (string) ( ( (array) ( Store::get()['catalogue'] ?? array() ) )['envelope'] ?? '' );
		$remote  = '' === $stored ? null : self::verify( $stored );

		$best = $bundled;
		if ( null !== $remote && ( null === $best || self::time( $remote ) > self::time( $best ) ) ) {
			$best = $remote;
		}
		self::$current = $best ?? array(
			'schema'       => 1,
			'published_at' => '',
			'providers'    => array(),
		);

		return self::$current;
	}

	/**
	 * Read the bundled catalogue from another file (tests only), or null to restore.
	 *
	 * @param string|null $path File.
	 * @return void
	 */
	public static function bundle( ?string $path ): void {
		self::$bundle  = $path;
		self::$current = null;
	}

	/**
	 * Forget the per-request copy (after a fetch, and in tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$current = null;
	}

	/**
	 * When the catalogue in use was published, as a Unix time (0 when unknown).
	 *
	 * @return int
	 */
	public static function published(): int {
		return self::time( self::current() );
	}

	/**
	 * The catalogue's models for one provider.
	 *
	 * @param string $provider Provider id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function models( string $provider ): array {
		$row = self::current()['providers'][ $provider ] ?? array();

		return is_array( $row ) && is_array( $row['models'] ?? null ) ? array_values( array_filter( $row['models'], 'is_array' ) ) : array();
	}

	/**
	 * Best / cheap / fast for a task on a provider.
	 *
	 * @param string $provider Provider id.
	 * @param string $task     Task id.
	 * @return array<string, string> Keys `best`, `cheap`, `fast` when known.
	 */
	public static function recommended( string $provider, string $task ): array {
		$row  = self::current()['providers'][ $provider ]['recommended'] ?? array();
		$pick = is_array( $row ) ? ( $row[ $task ] ?? $row['general'] ?? array() ) : array();

		return is_array( $pick ) ? array_filter( array_map( 'strval', $pick ) ) : array();
	}

	/**
	 * The catalogue's price for a model, USD per million tokens.
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model id.
	 * @return array{input: float, output: float}|null
	 */
	public static function price( string $provider, string $model ): ?array {
		foreach ( self::models( $provider ) as $row ) {
			if ( ( $row['id'] ?? null ) === $model && isset( $row['price']['input'], $row['price']['output'] ) ) {
				return array(
					'input'  => (float) $row['price']['input'],
					'output' => (float) $row['price']['output'],
				);
			}
		}

		return null;
	}

	/**
	 * Fetch the catalogue from Zinn Digital, if the site owner agreed to it.
	 *
	 * @return string `off` (no consent), `updated`, `unchanged` (valid but not newer), `refused` (did not verify) or `failed` (no answer).
	 */
	public static function refresh(): string {
		$record = (array) ( Store::get()['catalogue'] ?? array() );
		if ( empty( $record['remote'] ) ) {
			return 'off';
		}
		$response = Http::send( 'GET', self::URL, array( 'Accept' => 'application/json' ) );
		$status   = 'failed';
		if ( 200 === $response['status'] ) {
			$fetched = self::verify( $response['body'] );
			if ( null === $fetched ) {
				$status = 'refused';
			} elseif ( self::time( $fetched ) > self::published() ) {
				$status = 'updated';
			} else {
				$status = 'unchanged';
			}
		}
		$body = $response['body'];
		Store::update(
			static function ( array $store ) use ( $status, $body ): array {
				$row                = is_array( $store['catalogue'] ?? null ) ? $store['catalogue'] : array();
				$row['checked_at']  = time();
				$row['last_result'] = $status;
				if ( 'updated' === $status ) {
					$row['envelope'] = $body;
				}
				$store['catalogue'] = $row;

				return $store;
			}
		);
		self::reset();

		return $status;
	}

	/**
	 * A catalogue's publication time.
	 *
	 * @param array<string, mixed> $catalogue Catalogue.
	 * @return int
	 */
	private static function time( array $catalogue ): int {
		$time = strtotime( (string) ( $catalogue['published_at'] ?? '' ) );

		return false === $time ? 0 : $time;
	}
}
