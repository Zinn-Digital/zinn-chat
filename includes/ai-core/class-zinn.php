<?php
/**
 * Zinn® credits: AI paid from a Zinn® account instead of the site owner's own key (1.4.0).
 *
 * Generated from wp/packages/zinn-ai-core/src/class-zinn.php by wp/bin/build-ai-core.php.
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
 * The provider `zinn`: requests go to the Zinn Digital® API through a DOOR a host plugin offers
 * (`Core::boot( array( 'credits' => … ) )`), and are paid from the Zinn® account that host's site
 * is connected to. The core holds no token and makes no call of its own: the host owns the
 * connection, the consent and the address, and answers in this shape —
 *
 *   `state()`    → `array{ connected: bool, can_spend: bool, account: string, balance_micros: int|null,
 *                    buy_url: string, connect_url: string, host: string }`;
 *   `complete( array $requests, string $purpose )` → `array{ ok: bool, failure?: array, balance_micros?: int,
 *                    results?: array<int, array> }` where each request is `{id, messages, system,
 *                    schema, schema_name, max_tokens, temperature}` (text only) and each result is
 *                    `{id, ok, text, data, model, input_tokens, output_tokens, charged_micros}` or
 *                    `{id, ok: false, failure}`; a failure is `{kind, message, link, detail}`
 *                    with `kind` one of the `Failure` classes, the sentence already in the
 *                    reader's language.
 *
 * ⛔⛔ BATCHED, NEVER ONE CALL PER ITEM. The Zinn® API takes up to `MAX_BATCH` independent
 * requests in one call and runs them together; `generate_batch()` sends a bulk job's items
 * that many at a time. `generate()` is the one-item case of the same path.
 *
 * ⛔ TEXT ONLY. The credits endpoint reads text; a request that carries an image is refused here
 * with a sentence that says so, before anything is sent or spent. A feature that wants an
 * image read asks `Zinn::sees_images()` first and writes its prompt from the image's context.
 *
 * ⛔ No class guesses a vendor: an outage is named only when the API names the vendor, and
 * "our fault" names nobody (CLAUDE.md §2.57).
 */
final class Zinn extends Provider {

	/** The provider id. */
	public const ID = 'zinn';

	/** The one model id: Zinn® picks the model (the cheapest verified one for the job). */
	public const MODEL = 'auto';

	/** Requests per call to the Zinn® API (its `pluginAiComplete` limit). */
	public const MAX_BATCH = 20;

	/** Longest output a request may ask for (the API's limit). */
	public const MAX_TOKENS = 4096;

	/** Characters across one request's messages and system prompt (the API's limit). */
	public const MAX_CHARS = 60000;

	/**
	 * The preset row, built in code: these are not a vendor's measured facts (`providers.json`)
	 * but our own, and the label is a description that must be translated.
	 *
	 * @return array<string, mixed>
	 */
	public static function preset(): array {
		return array(
			'label'             => __( 'Zinn® credits', 'zinn-chat' ),
			'adapter'           => 'zinn',
			'auth'              => 'none',
			'key_optional'      => true,
			'key_url'           => '',
			'billing_url'       => '',
			'status_url'        => '',
			'structured_output' => 'json_schema',
			'models_public'     => true,
		);
	}

	/**
	 * Is there a door on this site?
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return null !== Core::door();
	}

	/**
	 * The door's state, or the "no door" state.
	 *
	 * @return array{connected: bool, can_spend: bool, account: string, balance_micros: int|null, buy_url: string, connect_url: string, host: string}
	 */
	public static function state(): array {
		$none = array(
			'connected'      => false,
			'can_spend'      => false,
			'account'        => '',
			'balance_micros' => null,
			'buy_url'        => '',
			'connect_url'    => '',
			'host'           => '',
		);
		$door = Core::door();
		if ( null === $door ) {
			return $none;
		}
		$state = call_user_func( $door['state'] );
		if ( ! is_array( $state ) ) {
			return $none;
		}

		return array(
			'connected'      => ! empty( $state['connected'] ),
			'can_spend'      => ! empty( $state['can_spend'] ),
			'account'        => (string) ( $state['account'] ?? '' ),
			'balance_micros' => isset( $state['balance_micros'] ) && is_numeric( $state['balance_micros'] ) ? (int) $state['balance_micros'] : null,
			'buy_url'        => (string) ( $state['buy_url'] ?? '' ),
			'connect_url'    => (string) ( $state['connect_url'] ?? '' ),
			'host'           => (string) ( $state['host'] ?? '' ),
		);
	}

	/**
	 * Ready to spend: a door, a connection, and the right to spend.
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		$state = self::state();

		return $state['connected'] && $state['can_spend'];
	}

	/**
	 * Can this provider read images? Not on credits (see the class comment).
	 *
	 * @return bool
	 */
	public static function sees_images(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Unused: there is no key.
	 * @return array<int, array{id: string, label: string, context: int, price: array{input: float, output: float}|null}>|Failure
	 */
	public function list_models( string $key ) {
		unset( $key );
		if ( ! self::available() ) {
			return self::not_ready();
		}

		return array(
			array(
				'id'      => self::MODEL,
				'label'   => __( 'Chosen by Zinn® for each job', 'zinn-chat' ),
				'context' => 0,
				'price'   => null,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                                          $key      Unused.
	 * @param string                                          $model    Unused: Zinn® picks.
	 * @param array<int, array{role: string, content: mixed}> $messages Conversation.
	 * @param array<string, mixed>                            $options  See Provider::generate().
	 * @return Result
	 */
	public function generate( string $key, string $model, array $messages, array $options = array() ): Result {
		unset( $key, $model );
		$results = $this->generate_batch(
			array(
				array(
					'id'       => '0',
					'messages' => $messages,
					'options'  => $options,
				),
			),
			(string) ( $options['purpose'] ?? '' )
		);

		return $results[0] ?? Result::failed( self::ours( '' ), self::ID, self::MODEL );
	}

	/**
	 * Many requests, `MAX_BATCH` per call.
	 *
	 * @param array<int, array{id?: string, messages: array, options?: array<string, mixed>}> $items   Requests, in order.
	 * @param string                                                                          $purpose What they are for (the account's usage log).
	 * @return array<int, Result> One per item, in input order; `id` echoed.
	 */
	public function generate_batch( array $items, string $purpose = '' ): array {
		$out   = array();
		$ready = array();
		foreach ( array_values( $items ) as $index => $item ) {
			$id       = (string) ( $item['id'] ?? (string) $index );
			$messages = is_array( $item['messages'] ?? null ) ? $item['messages'] : array();
			$options  = is_array( $item['options'] ?? null ) ? $item['options'] : array();
			$request  = self::request( $id, $messages, $options );
			if ( $request instanceof Failure ) {
				$out[ $index ] = self::tagged( Result::failed( $request, self::ID, self::MODEL ), $id );
				continue;
			}
			$ready[ $index ] = $request;
		}

		$door = Core::door();
		foreach ( array_chunk( $ready, self::MAX_BATCH, true ) as $chunk ) {
			if ( null === $door ) {
				foreach ( $chunk as $index => $request ) {
					$out[ $index ] = self::tagged( Result::failed( self::not_ready(), self::ID, self::MODEL ), (string) $request['id'] );
				}
				continue;
			}
			$answer = call_user_func( $door['complete'], array_values( $chunk ), $purpose );
			$answer = is_array( $answer ) ? $answer : array();
			if ( empty( $answer['ok'] ) ) {
				// The whole call was refused (no credit, the site's limit, an account on hold) or
				// failed: every request in it carries the same answer, nothing was spent.
				$failure = self::failure( is_array( $answer['failure'] ?? null ) ? $answer['failure'] : array() );
				foreach ( $chunk as $index => $request ) {
					$out[ $index ] = self::tagged( Result::failed( $failure, self::ID, self::MODEL ), (string) $request['id'] );
				}
				continue;
			}
			$by_id = array();
			foreach ( (array) ( $answer['results'] ?? array() ) as $row ) {
				if ( is_array( $row ) && isset( $row['id'] ) ) {
					$by_id[ (string) $row['id'] ] = $row;
				}
			}
			foreach ( $chunk as $index => $request ) {
				$row           = $by_id[ (string) $request['id'] ] ?? null;
				$out[ $index ] = self::tagged( null === $row ? Result::failed( self::ours( 'missing result' ), self::ID, self::MODEL ) : self::result( $row, null !== $request['schema'] ), (string) $request['id'] );
			}
		}
		ksort( $out );

		return array_values( $out );
	}

	/**
	 * One request in the API's shape, or why it cannot be sent.
	 *
	 * @param string               $id       Echoed id.
	 * @param array<int, mixed>    $messages Conversation.
	 * @param array<string, mixed> $options  Options.
	 * @return array<string, mixed>|Failure
	 */
	public static function request( string $id, array $messages, array $options ) {
		$system = array();
		$turns  = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			if ( self::has_image( $message['content'] ?? '' ) ) {
				return Failure::refused( __( 'Zinn® credits read text only, so they cannot look at an image. Use your own key from a provider that reads images (OpenAI, Anthropic or Google Gemini) for this.', 'zinn-chat' ), Core::settings_url() );
			}
			$text = self::text_of( $message['content'] ?? '' );
			if ( 'system' === ( $message['role'] ?? '' ) ) {
				$system[] = $text;
			} else {
				$turns[] = array(
					'role'    => 'assistant' === ( $message['role'] ?? '' ) ? 'assistant' : 'user',
					'content' => $text,
				);
			}
		}
		if ( array() === $turns ) {
			return self::ours( 'no message' );
		}
		$system = implode( "\n\n", $system );
		$size   = strlen( $system );
		foreach ( $turns as $turn ) {
			$size += strlen( $turn['content'] );
		}
		if ( $size > self::MAX_CHARS ) {
			return Failure::refused( __( 'This is too long to send in one request. Shorten the text and try again.', 'zinn-chat' ) );
		}
		$schema = is_array( $options['schema'] ?? null ) ? $options['schema'] : null;

		return array(
			'id'          => substr( $id, 0, 64 ),
			'messages'    => $turns,
			'system'      => substr( $system, 0, 8000 ),
			'schema'      => $schema,
			'schema_name' => 1 === preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', (string) ( $options['schema_name'] ?? '' ) ) ? (string) $options['schema_name'] : 'answer',
			'max_tokens'  => max( 1, min( self::MAX_TOKENS, (int) ( $options['max_tokens'] ?? 1024 ) ) ),
			'temperature' => number_format( max( 0.0, min( 1.0, (float) ( $options['temperature'] ?? 0.2 ) ) ), 2, '.', '' ),
		);
	}

	/**
	 * A result row from the API as a Result.
	 *
	 * @param array<string, mixed> $row       The row.
	 * @param bool                 $structured A schema was asked for.
	 * @return Result
	 */
	private static function result( array $row, bool $structured ): Result {
		if ( empty( $row['ok'] ) ) {
			return Result::failed( self::failure( is_array( $row['failure'] ?? null ) ? $row['failure'] : array() ), self::ID, self::MODEL );
		}
		$result                 = new Result();
		$result->provider       = self::ID;
		$result->model          = (string) ( $row['model'] ?? self::MODEL );
		$result->text           = (string) ( $row['text'] ?? '' );
		$result->input_tokens   = max( 0, (int) ( $row['input_tokens'] ?? 0 ) );
		$result->output_tokens  = max( 0, (int) ( $row['output_tokens'] ?? 0 ) );
		$result->cost_micro_usd = max( 0, (int) ( $row['charged_micros'] ?? 0 ) );
		if ( $structured ) {
			$data = $row['data'] ?? null;
			if ( ! is_array( $data ) ) {
				$decoded = json_decode( $result->text, true );
				$data    = is_array( $decoded ) ? $decoded : null;
			}
			if ( ! is_array( $data ) ) {
				return Result::failed( self::ours( 'structured answer missing' ), self::ID, $result->model );
			}
			$result->data = $data;
		}

		return $result;
	}

	/**
	 * A failure the door described.
	 *
	 * @param array<string, mixed> $failure `{kind, message, link, detail}`.
	 * @return Failure
	 */
	public static function failure( array $failure ): Failure {
		$kinds = array( Failure::OUTAGE, Failure::UNREACHABLE, Failure::BILLING, Failure::INVALID_KEY, Failure::RATE_LIMITED, Failure::MODEL, Failure::REFUSED, Failure::UNKNOWN );
		$kind  = (string) ( $failure['kind'] ?? '' );
		if ( ! in_array( $kind, $kinds, true ) ) {
			return self::ours( (string) ( $failure['detail'] ?? '' ) );
		}
		$message = trim( (string) ( $failure['message'] ?? '' ) );
		if ( '' === $message ) {
			return self::ours( (string) ( $failure['detail'] ?? '' ) );
		}
		$link = (string) ( $failure['link'] ?? '' );
		if ( '' !== $link && 'https' !== strtolower( (string) wp_parse_url( $link, PHP_URL_SCHEME ) ) && 0 !== strpos( $link, admin_url() ) ) {
			$link = '';
		}

		return new Failure( $kind, self::ID, $message, $link, substr( (string) ( $failure['detail'] ?? '' ), 0, 300 ) );
	}

	/**
	 * Our own fault: names nobody, links nothing.
	 *
	 * @param string $detail For support.
	 * @return Failure
	 */
	private static function ours( string $detail ): Failure {
		return new Failure( Failure::UNKNOWN, '', __( 'Something went wrong on our side. Nothing was charged. Please try again in a few minutes.', 'zinn-chat' ), '', $detail );
	}

	/**
	 * No door, no connection, or a connection that may not spend.
	 *
	 * @return Failure
	 */
	public static function not_ready(): Failure {
		$state = self::state();
		if ( ! $state['connected'] ) {
			return Failure::refused( __( 'To pay with Zinn® credits, connect this site to a Zinn® account first.', 'zinn-chat' ), $state['connect_url'] );
		}

		return Failure::refused( __( 'This site is connected to its Zinn® account for viewing only, so it cannot spend credit. The account\'s owner can allow it.', 'zinn-chat' ), $state['connect_url'] );
	}

	/**
	 * A Result with its id.
	 *
	 * @param Result $result The result.
	 * @param string $id     The id.
	 * @return Result
	 */
	private static function tagged( Result $result, string $id ): Result {
		$result->id = $id;

		return $result;
	}
}
