<?php
/**
 * Why an AI request failed, in words a site owner can act on.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-failure.php by wp/bin/build-ai-core.php.
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
 * A classified failure (feature ai-7).
 *
 * ⛔⛔ THE CLASS DECIDES THE ADVICE, SO AN UNCLASSIFIABLE FAILURE IS ITS OWN CLASS. A `500` from
 * a proxy the customer runs and a real provider outage look alike to a naive handler, and
 * guessing "outage" blames the provider for something they can fix — or for our bug. So only a
 * response the provider itself marks as overloaded or failing is an outage; anything we cannot
 * place is `UNKNOWN`, which names nobody.
 *
 * The provider IS named in every other class, deliberately: the site owner chose it and holds
 * the key, so "OpenAI says your key has no credit" is information they can act on. This mirrors
 * the platform's own rule for AI vendors (CLAUDE.md §2.57).
 */
final class Failure {

	/** The provider is down or overloaded. Retry later; link the status page. */
	public const OUTAGE = 'provider_outage';

	/** No response arrived at all (DNS, TLS, firewall, timeout). */
	public const UNREACHABLE = 'unreachable';

	/** The key has no credit / quota left. Link the billing page. */
	public const BILLING = 'credential_billing';

	/** The key is wrong, revoked, or lacks permission. Link the keys page. */
	public const INVALID_KEY = 'credential_invalid';

	/** The provider is throttling this key. Wait and retry. */
	public const RATE_LIMITED = 'rate_limited';

	/** The chosen model does not exist for this key. */
	public const MODEL = 'model_unavailable';

	/** We declined on purpose: no key set, a monthly limit, a role not allowed. Not an error. */
	public const REFUSED = 'refused';

	/** Anything else. Names no provider and links nothing. */
	public const UNKNOWN = 'unknown';

	/**
	 * Failure class, one of the constants above.
	 *
	 * @var string
	 */
	public string $kind;

	/**
	 * Provider id, or '' when the failure is ours.
	 *
	 * @var string
	 */
	public string $provider;

	/**
	 * The sentence shown to the site owner.
	 *
	 * @var string
	 */
	public string $message;

	/**
	 * Where the fix is (status, billing or keys page), or ''.
	 *
	 * @var string
	 */
	public string $link;

	/**
	 * The provider's own words, for the log and for support. Never the key.
	 *
	 * @var string
	 */
	public string $detail;

	/**
	 * Build a failure.
	 *
	 * @param string $kind     Class.
	 * @param string $provider Provider id.
	 * @param string $message  Human sentence.
	 * @param string $link     Remedy URL.
	 * @param string $detail   Provider's own error text.
	 */
	public function __construct( string $kind, string $provider, string $message, string $link = '', string $detail = '' ) {
		$this->kind     = $kind;
		$this->provider = $provider;
		$this->message  = $message;
		$this->link     = $link;
		$this->detail   = $detail;
	}

	/**
	 * A refusal of our own (no key, a limit, a role).
	 *
	 * @param string $message Human sentence.
	 * @param string $link    Where to change it, usually our settings screen.
	 * @return self
	 */
	public static function refused( string $message, string $link = '' ): self {
		return new self( self::REFUSED, '', $message, $link );
	}

	/**
	 * Classify a provider response.
	 *
	 * @param string                                                                           $provider Provider id.
	 * @param array{status: int, body: string, error: string, headers?: array<string, string>} $response The transport's answer.
	 * @param string                                                                           $model    The model that was asked, for the model message.
	 * @return self
	 */
	public static function from_response( string $provider, array $response, string $model = '' ): self {
		$spec   = Registry::get( $provider );
		$label  = (string) ( $spec['label'] ?? $provider );
		$status = $response['status'];
		$text   = self::error_text( $response['body'] );
		$needle = strtolower( $text . ' ' . $response['body'] );
		$detail = '' !== $text ? $text : ( 0 === $status ? $response['error'] : 'HTTP ' . $status );

		if ( 0 === $status && Registry::CUSTOM === $provider ) {
			return new self(
				self::UNREACHABLE,
				$provider,
				__( 'This site could not reach your AI service. Check that it is running and that its address is reachable from your web server (a service on your own computer is not reachable from a hosted site).', 'zinn-chat' ),
				'',
				$detail
			);
		}
		if ( 0 === $status ) {
			return new self(
				self::UNREACHABLE,
				$provider,
				sprintf(
					/* translators: %s: AI provider name, e.g. OpenAI. */
					__( 'This site could not reach %s. Check their status page; if they are up, your host may be blocking outgoing connections, or the address is wrong.', 'zinn-chat' ),
					$label
				),
				(string) ( $spec['status_url'] ?? '' ),
				$detail
			);
		}

		// Billing first: OpenAI reports an empty balance as a 429, so the rate-limit test below
		// would otherwise tell a customer with no credit to "wait and retry" for ever.
		if ( 402 === $status || self::mentions( $needle, array( 'insufficient_quota', 'credit_balance_exhausted', 'spend_limit', 'spend limit', 'usage_limit_exceeded', 'failed_precondition', 'insufficient balance', 'insufficient_balance', 'credit balance', 'billing', 'insufficient credits', 'payment required', 'exceeded your current quota' ) ) ) {
			return new self(
				self::BILLING,
				$provider,
				sprintf(
					/* translators: %s: AI provider name. */
					__( '%s says your account has no credit or quota left. Add credit or raise your limit in your account with them, then try again.', 'zinn-chat' ),
					$label
				),
				(string) ( $spec['billing_url'] ?? '' ),
				$detail
			);
		}

		if ( 401 === $status || self::mentions( $needle, array( 'invalid_api_key', 'api_key_invalid', 'invalid x-api-key', 'authentication_error', 'incorrect api key', 'api key not valid', 'unauthorized' ) ) ) {
			return new self(
				self::INVALID_KEY,
				$provider,
				sprintf(
					/* translators: %s: AI provider name. */
					__( '%s did not accept your key. It may be mistyped, revoked or expired. Create a new key and paste it here.', 'zinn-chat' ),
					$label
				),
				(string) ( $spec['key_url'] ?? '' ),
				$detail
			);
		}

		if ( 403 === $status ) {
			return new self(
				self::INVALID_KEY,
				$provider,
				sprintf(
					/* translators: %s: AI provider name. */
					__( '%s refused this request for your key. The key may not have access to this model or feature, or your account needs verifying.', 'zinn-chat' ),
					$label
				),
				(string) ( $spec['key_url'] ?? '' ),
				$detail
			);
		}

		if ( 404 === $status || ( 400 === $status && self::mentions( $needle, array( 'model_not_found', 'does not exist', 'not found', 'unknown model', 'invalid model', 'no such model' ) ) ) ) {
			return new self(
				self::MODEL,
				$provider,
				sprintf(
					/* translators: 1: model name, 2: AI provider name. */
					__( 'The model "%1$s" is not available from %2$s for your key. Choose another model in the AI settings.', 'zinn-chat' ),
					$model,
					$label
				),
				'',
				$detail
			);
		}

		// A per-minute request limit of ZERO is not a rate limit: the account's plan does not
		// include this model at all (Mistral, measured 2026-09-26). Waiting would never help.
		if ( 429 === $status && '0' === (string) ( $response['headers']['x-ratelimit-limit-req-minute'] ?? '' ) ) {
			return new self(
				self::MODEL,
				$provider,
				sprintf(
					/* translators: 1: model name, 2: AI provider name. */
					__( 'Your %2$s plan does not include the model "%1$s". Choose another model in the AI settings, or upgrade your plan with them.', 'zinn-chat' ),
					$model,
					$label
				),
				(string) ( $spec['billing_url'] ?? '' ),
				$detail
			);
		}

		if ( 429 === $status ) {
			return new self(
				self::RATE_LIMITED,
				$provider,
				sprintf(
					/* translators: %s: AI provider name. */
					__( '%s is limiting how fast your key can send requests. Wait a minute and try again, or raise your rate limit with them.', 'zinn-chat' ),
					$label
				),
				(string) ( $spec['billing_url'] ?? '' ),
				$detail
			);
		}

		if ( in_array( $status, array( 408, 500, 502, 503, 504, 529 ), true ) || self::mentions( $needle, array( 'overloaded', '"unavailable"', 'service unavailable' ) ) ) {
			return new self(
				self::OUTAGE,
				$provider,
				sprintf(
					/* translators: %s: AI provider name. */
					__( '%s is having problems right now (an outage or overload on their side). Nothing is wrong with your settings. Try again shortly.', 'zinn-chat' ),
					$label
				),
				(string) ( $spec['status_url'] ?? '' ),
				$detail
			);
		}

		return new self(
			self::UNKNOWN,
			'',
			__( 'The AI request failed for a reason we could not identify. Try again; if it keeps happening, contact support with the details below.', 'zinn-chat' ),
			'',
			$detail
		);
	}

	/**
	 * The provider's own error sentence, from any of the shapes they use.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	public static function error_text( string $body ): string {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return '';
		}
		$error = $data['error'] ?? null;
		if ( is_string( $error ) ) {
			return $error;
		}
		if ( is_array( $error ) ) {
			$parts = array();
			foreach ( array( 'type', 'code', 'status', 'message' ) as $key ) {
				if ( isset( $error[ $key ] ) && is_scalar( $error[ $key ] ) && '' !== (string) $error[ $key ] ) {
					$parts[] = (string) $error[ $key ];
				}
			}
			foreach ( (array) ( $error['details'] ?? array() ) as $item ) {
				if ( is_array( $item ) && isset( $item['reason'] ) && is_string( $item['reason'] ) ) {
					$parts[] = $item['reason'];
				}
			}
			return implode( ': ', $parts );
		}
		foreach ( array( 'message', 'detail' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				return $data[ $key ];
			}
		}

		return '';
	}

	/**
	 * Does the haystack contain any needle?
	 *
	 * @param string            $haystack Lower-cased text.
	 * @param array<int,string> $needles  Lower-case needles.
	 * @return bool
	 */
	private static function mentions( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The failure as data, for a REST response or a log row.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'kind'     => $this->kind,
			'provider' => $this->provider,
			'message'  => $this->message,
			'link'     => $this->link,
			'detail'   => $this->detail,
		);
	}
}
