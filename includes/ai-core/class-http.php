<?php
/**
 * The one place the AI core talks to the network.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-http.php by wp/bin/build-ai-core.php.
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
 * HTTP transport.
 *
 * ⛔ Every outbound call the core makes goes through `send()`, so "keys are only ever sent to the
 * provider you chose" (feature ai-6) is a property of one method rather than of every caller.
 * The User-Agent is our own on purpose: WordPress's default carries the site's URL, and the
 * catalogue request must not tell us which site asked (X3: no user data).
 *
 * Tests replace the sender with `Http::fake()`; the recorded fixtures are the provider's real
 * response shapes, so a parser change is tested against what the provider actually sends.
 */
final class Http {

	/**
	 * A replacement sender, used by the tests only.
	 *
	 * @var (callable(string, string, array<string, string>, ?string): array{status: int, body: string, error: string, headers?: array<string, string>})|null
	 */
	private static $fake = null;

	/**
	 * Send a request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string|null           $body    Request body, already encoded.
	 * @param int                   $timeout Seconds to wait (image generation takes longer than text).
	 * @return array{status: int, body: string, error: string, headers?: array<string, string>} `status` 0 means no response arrived.
	 */
	public static function send( string $method, string $url, array $headers = array(), ?string $body = null, int $timeout = 60 ): array {
		// The same private-address check for every call, an administrator's custom address included.
		if ( ! self::allowed_address( $url ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'error'  => __( 'That address is on a private or local network. Use a public address.', 'zinn-chat' ),
			);
		}
		if ( null !== self::$fake ) {
			return ( self::$fake )( $method, $url, $headers, $body );
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => max( 5, $timeout ),
			'redirection' => 2,
			'user-agent'  => 'ZinnAiCore/' . Core::VERSION,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		// The safe variant for every public address: it also refuses a public name that resolves to a private
		// address. Only a private address the site owner allowed in wp-config takes the plain request.
		$response = 'wp_remote_request' === self::request_function( $url ) ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'error'  => $response->get_error_message(),
			);
		}

		// Response headers, lower-cased. Some providers say things only there: Mistral answers a
		// model the account's plan excludes with a 429 whose request limit is zero, and the body
		// alone cannot tell that apart from a real rate limit (see Failure::from_response()).
		$headers = array();
		// ⛔ Not `(array)`: WordPress returns a CaseInsensitiveDictionary OBJECT, and casting an
		// object gives its private properties, not the headers. It is iterable; iterate it.
		$raw = wp_remote_retrieve_headers( $response );
		foreach ( is_iterable( $raw ) ? $raw : array() as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'error'   => '',
			'headers' => $headers,
		);
	}

	/**
	 * Whether a URL is http(s) on a public host: no loopback, private, reserved or link-local IP
	 * literal, no single-label name and no local-only suffix. wp_safe_remote_request() then checks
	 * what the name resolves to.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function public_address( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		$host = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
		if ( '' === $host || strlen( $host ) > 253 ) {
			return false;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		return str_contains( $host, '.' ) && 1 !== preg_match( '/(^|\.)(localhost|local|internal|localdomain|lan|home\.arpa)$/', $host );
	}

	/**
	 * Whether the site owner allowed a private or local AI address (a self-hosted model on localhost or the LAN):
	 * only by defining PBSW_ALLOW_PRIVATE_AI_URL (or ZINN_ALLOW_PRIVATE_AI_URL) as exactly true in wp-config.php.
	 *
	 * @return bool
	 */
	public static function private_allowed(): bool {
		foreach ( array( 'PBSW_ALLOW_PRIVATE_AI_URL', 'ZINN_ALLOW_PRIVATE_AI_URL' ) as $name ) {
			if ( defined( $name ) && true === constant( $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a URL may be fetched: a public address always; an http(s) address with a host on a private or local
	 * network only when private_allowed().
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function allowed_address( string $url ): bool {
		if ( self::public_address( $url ) ) {
			return true;
		}
		if ( ! self::private_allowed() ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& '' !== trim( (string) ( $parts['host'] ?? '' ), '[]' );
	}

	/**
	 * The WordPress request function for an allowed URL: the safe one for a public address, the plain one for a
	 * private address the site owner allowed (the safe one would refuse it).
	 *
	 * @param string $url URL, already allowed_address().
	 * @return string
	 */
	public static function request_function( string $url ): string {
		return self::public_address( $url ) ? 'wp_safe_remote_request' : 'wp_remote_request';
	}

	/**
	 * Replace the sender (tests only). Pass null to restore the real one.
	 *
	 * @param (callable(string, string, array<string, string>, ?string): array{status: int, body: string, error: string, headers?: array<string, string>})|null $sender Replacement.
	 * @return void
	 */
	public static function fake( ?callable $sender ): void {
		self::$fake = $sender;
	}
}
