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
	 * @param bool                  $local   Allow a private or loopback address (a self-hosted model).
	 * @param int                   $timeout Seconds to wait (image generation takes longer than text).
	 * @return array{status: int, body: string, error: string, headers?: array<string, string>} `status` 0 means no response arrived.
	 */
	public static function send( string $method, string $url, array $headers = array(), ?string $body = null, bool $local = false, int $timeout = 60 ): array {
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

		// A self-hosted model lives on localhost or the LAN by definition, which the safe variant
		// refuses. Only an administrator can set that address (Admin checks manage_options).
		$response = $local ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
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
	 * Replace the sender (tests only). Pass null to restore the real one.
	 *
	 * @param (callable(string, string, array<string, string>, ?string): array{status: int, body: string, error: string, headers?: array<string, string>})|null $sender Replacement.
	 * @return void
	 */
	public static function fake( ?callable $sender ): void {
		self::$fake = $sender;
	}
}
