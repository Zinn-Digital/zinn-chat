<?php
/**
 * One interface over every AI provider.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-provider.php by wp/bin/build-ai-core.php.
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
 * A provider adapter: list the models a key can use, and generate.
 *
 * Every adapter takes the same request and returns the same `Result`, so a feature written
 * against OpenAI works unchanged on Claude, Gemini or a model on the site owner's own machine.
 */
abstract class Provider {

	/**
	 * Provider id.
	 *
	 * @var string
	 */
	protected string $id;

	/**
	 * Preset row (data/providers.json), with the site owner's base URL applied for custom.
	 *
	 * @var array<string, mixed>
	 */
	protected array $spec;

	/**
	 * Build an adapter.
	 *
	 * @param string               $id   Provider id.
	 * @param array<string, mixed> $spec Preset row.
	 */
	public function __construct( string $id, array $spec ) {
		$this->id   = $id;
		$this->spec = $spec;
	}

	/**
	 * Provider id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The models this key can use, straight from the provider (feature ai-2).
	 *
	 * @param string $key API key ('' when the provider needs none).
	 * @return array<int, array{id: string, label: string, context: int, price: array{input: float, output: float}|null}>|Failure
	 */
	abstract public function list_models( string $key );

	/**
	 * Generate.
	 *
	 * @param string                                           $key     API key.
	 * @param string                                           $model   Model id.
	 * @param array<int, array{role: string, content: string}> $messages Conversation; `system` rows are allowed.
	 * @param array<string, mixed>                             $options `max_tokens` (int), `schema` (JSON Schema array), `schema_name` (string), `temperature` (float).
	 * @return Result
	 */
	abstract public function generate( string $key, string $model, array $messages, array $options = array() ): Result;

	/**
	 * The API base, without a trailing slash.
	 *
	 * @return string
	 */
	protected function base(): string {
		return rtrim( (string) ( $this->spec['api_base'] ?? '' ), '/' );
	}

	/**
	 * Is the base a self-hosted address? Only the custom preset may be.
	 *
	 * @return bool
	 */
	protected function local(): bool {
		return Registry::CUSTOM === $this->id;
	}

	/**
	 * Headers for an authenticated call.
	 *
	 * @param string $key API key.
	 * @return array<string, string>
	 */
	protected function headers( string $key ): array {
		$headers = array( 'Content-Type' => 'application/json' );
		foreach ( (array) ( $this->spec['extra_headers'] ?? array() ) as $name => $value ) {
			$headers[ (string) $name ] = (string) $value;
		}
		if ( '' === $key ) {
			return $headers;
		}
		switch ( (string) ( $this->spec['auth'] ?? 'bearer' ) ) {
			case 'x-api-key':
				$headers['x-api-key'] = $key;
				break;
			case 'x-goog-api-key':
				$headers['x-goog-api-key'] = $key;
				break;
			default:
				$headers['Authorization'] = 'Bearer ' . $key;
		}

		return $headers;
	}

	/**
	 * Send and decode a JSON call.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Path below the API base.
	 * @param string                    $key    API key.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @param int                       $timeout Seconds (image generation needs longer than text).
	 * @return array{0: array<mixed>|null, 1: array{status: int, body: string, error: string}} Decoded body (null unless 2xx JSON) and the raw response.
	 */
	protected function call( string $method, string $path, string $key, ?array $body = null, int $timeout = 60 ): array {
		$response = Http::send(
			$method,
			$this->base() . $path,
			$this->headers( $key ),
			null === $body ? null : (string) wp_json_encode( $body ),
			$this->local(),
			$timeout
		);
		$decoded  = null;
		if ( $response['status'] >= 200 && $response['status'] < 300 ) {
			$json    = json_decode( $response['body'], true );
			$decoded = is_array( $json ) ? $json : null;
		}

		return array( $decoded, $response );
	}

	/**
	 * A 2xx answer we could not read is not the provider's fault to announce: it is ours.
	 *
	 * @param array{status: int, body: string, error: string} $response Raw response.
	 * @return Failure
	 */
	protected function unreadable( array $response ): Failure {
		return new Failure(
			Failure::UNKNOWN,
			'',
			__( 'The AI provider answered in a form this plugin could not read. Try again; if it keeps happening, choose another model or contact support.', 'zinn-chat' ),
			'',
			substr( $response['body'], 0, 300 )
		);
	}

	/**
	 * Split the system prompt from the conversation.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Conversation.
	 * @return array{0: string, 1: array<int, array{role: string, content: string}>}
	 */
	protected static function split_system( array $messages ): array {
		$system = array();
		$turns  = array();
		foreach ( $messages as $message ) {
			if ( 'system' === $message['role'] ) {
				$system[] = self::text_of( $message['content'] );
			} else {
				$turns[] = $message;
			}
		}

		return array( implode( "\n\n", $system ), $turns );
	}

	/**
	 * A message's content as a list of parts (1.1.0). Content is either a string (text) or a list of
	 * parts: `{type: text, text}` and `{type: image, mime, data}` where `data` is base64. Anything
	 * else is dropped, so a caller cannot smuggle a provider-specific field through the core.
	 *
	 * @param mixed $content Message content.
	 * @return array<int, array{type: string, text?: string, mime?: string, data?: string}>
	 */
	protected static function parts( $content ): array {
		if ( is_string( $content ) ) {
			return array(
				array(
					'type' => 'text',
					'text' => $content,
				),
			);
		}
		$parts = array();
		foreach ( is_array( $content ) ? $content : array() as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			if ( 'text' === ( $part['type'] ?? '' ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$parts[] = array(
					'type' => 'text',
					'text' => $part['text'],
				);
			} elseif ( 'image' === ( $part['type'] ?? '' ) && isset( $part['data'], $part['mime'] ) && is_string( $part['data'] ) && 1 === preg_match( '#^image/(png|jpeg|webp|gif)$#', (string) $part['mime'] ) ) {
				$parts[] = array(
					'type' => 'image',
					'mime' => (string) $part['mime'],
					'data' => $part['data'],
				);
			}
		}

		return $parts;
	}

	/**
	 * Just the text of a message's content.
	 *
	 * @param mixed $content Message content.
	 * @return string
	 */
	protected static function text_of( $content ): string {
		$text = array();
		foreach ( self::parts( $content ) as $part ) {
			if ( 'text' === $part['type'] ) {
				$text[] = (string) $part['text'];
			}
		}

		return implode( "\n", $text );
	}

	/**
	 * Does a content value carry an image?
	 *
	 * @param mixed $content Message content.
	 * @return bool
	 */
	protected static function has_image( $content ): bool {
		foreach ( self::parts( $content ) as $part ) {
			if ( 'image' === $part['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Can this provider embed text (preset flag `embeddings`, 1.2.0)?
	 *
	 * @return bool
	 */
	public function supports_embeddings(): bool {
		return ! empty( $this->spec['embeddings'] );
	}

	/**
	 * Embedding models this key can use, newest first. Providers that embed override.
	 *
	 * @param string $key API key.
	 * @return array<int, array{id: string, label: string}>|Failure
	 */
	public function embedding_models( string $key ) {
		unset( $key );

		return array();
	}

	/**
	 * Embed texts (1.2.0). Providers that embed override.
	 *
	 * @param string               $key     API key.
	 * @param string               $model   Model id.
	 * @param array<int, string>   $texts   Texts, in order.
	 * @param array<string, mixed> $options `dimensions` (int), `type` (`document`|`query`).
	 * @return Result `vectors`: one per text, in order.
	 */
	public function embed( string $key, string $model, array $texts, array $options = array() ): Result {
		unset( $key, $texts, $options );

		return Result::failed( Failure::refused( __( 'This AI provider cannot build a search index. Connect Google Gemini, OpenAI, Mistral or a compatible service for site search.', 'zinn-chat' ) ), $this->id, $model );
	}

	/**
	 * Is this model id an embedding model?
	 *
	 * @param string $id Model id.
	 * @return bool
	 */
	protected static function is_embedding_model( string $id ): bool {
		return 1 === preg_match( '/embed/i', $id );
	}

	/**
	 * Can this provider make images (preset flag `images`)?
	 *
	 * @return bool
	 */
	public function supports_images(): bool {
		return ! empty( $this->spec['images'] );
	}

	/**
	 * Image-generation models this key can use, newest first. Providers that make images override.
	 *
	 * @param string $key API key.
	 * @return array<int, array{id: string, label: string}>|Failure
	 */
	public function image_models( string $key ) {
		unset( $key );

		return array();
	}

	/**
	 * Generate an image. Providers that make images override.
	 *
	 * @param string               $key     API key.
	 * @param string               $model   Model id.
	 * @param string               $prompt  What to draw.
	 * @param array<string, mixed> $options `size` (`square`, `landscape`, `portrait`).
	 * @return Result
	 */
	public function generate_image( string $key, string $model, string $prompt, array $options = array() ): Result {
		unset( $key, $prompt, $options );

		return Result::failed( Failure::refused( __( 'This AI provider cannot make images. Choose OpenAI or Google Gemini for images in the AI settings.', 'zinn-chat' ) ), $this->id, $model );
	}

	/**
	 * Sort image model ids newest first: a higher version number first, then the plain name before
	 * a variant (`-mini`, `-lite`), and dated snapshots and previews last.
	 *
	 * @param array<int, string> $ids Model ids.
	 * @return array<int, string>
	 */
	protected static function newest_first( array $ids ): array {
		$score = static function ( string $id ): array {
			preg_match( '/(\d+(?:\.\d+)*)/', $id, $m );
			$version = $m[1] ?? '0';
			$penalty = 0;
			if ( 1 === preg_match( '/\d{4}-\d{2}-\d{2}|preview|latest/', $id ) ) {
				$penalty += 2;
			}
			if ( 1 === preg_match( '/mini|lite|nano/', $id ) ) {
				++$penalty;
			}
			return array( $penalty, $version );
		};
		usort(
			$ids,
			static function ( string $a, string $b ) use ( $score ): int {
				list( $pa, $va ) = $score( $a );
				list( $pb, $vb ) = $score( $b );
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}
				// version_compare, never `<=>` on arrays: PHP compares arrays by LENGTH first, which
				// put 1.5 above 2 (measured by the test that pins this order).
				$c = version_compare( (string) $vb, (string) $va );
				return 0 !== $c ? $c : strcmp( $a, $b );
			}
		);

		return array_values( $ids );
	}

	/**
	 * A provider id or label that looks like a chat model, for the live list.
	 *
	 * ⭐ Discovery returns everything a key can call, including embeddings, speech and image
	 * models that would fail as a text model. They are hidden from the picker; "add a model
	 * yourself" (ai-4) still reaches any of them.
	 *
	 * @param string $id Model id.
	 * @return bool
	 */
	protected static function is_text_model( string $id ): bool {
		return 1 !== preg_match( '/(embed|tts|whisper|transcri|dall-e|image|moderation|audio|realtime|speech|rerank|ocr|search-preview)/i', $id );
	}
}
