<?php
/**
 * The OpenAI chat-completions dialect: OpenAI, Mistral, DeepSeek, OpenRouter and any compatible server.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-openai-compatible.php by wp/bin/build-ai-core.php.
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
 * Adapter for every provider that speaks `/models` + `/chat/completions`.
 *
 * ⭐ One adapter for five providers is the point: a local Ollama or LM Studio server, a vLLM box
 * or a gateway all speak this dialect, which is what makes "any OpenAI-compatible service"
 * (feature ai-1) true without a line of provider-specific code.
 */
final class Openai_Compatible extends Provider {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key API key.
	 * @return array<int, array{id: string, label: string, context: int, price: array{input: float, output: float}|null}>|Failure
	 */
	public function list_models( string $key ) {
		list( $body, $response ) = $this->call( 'GET', (string) ( $this->spec['models_path'] ?? '/models' ), $key );
		if ( null === $body ) {
			return $response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response );
		}

		$models = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) || ! self::is_text_model( $row['id'] ) ) {
				continue;
			}
			$price = null;
			// OpenRouter publishes its prices in the list, in USD per token.
			if ( isset( $row['pricing']['prompt'], $row['pricing']['completion'] ) && is_numeric( $row['pricing']['prompt'] ) && is_numeric( $row['pricing']['completion'] ) ) {
				$price = array(
					'input'  => round( (float) $row['pricing']['prompt'] * 1000000, 6 ),
					'output' => round( (float) $row['pricing']['completion'] * 1000000, 6 ),
				);
			}
			$models[] = array(
				'id'      => $row['id'],
				'label'   => isset( $row['name'] ) && is_string( $row['name'] ) ? $row['name'] : $row['id'],
				'context' => (int) ( $row['context_length'] ?? $row['max_context_length'] ?? 0 ),
				'price'   => $price,
			);
		}
		usort( $models, static fn( array $a, array $b ): int => strcmp( $a['id'], $b['id'] ) );

		return $models;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                                           $key      API key.
	 * @param string                                           $model    Model id.
	 * @param array<int, array{role: string, content: string}> $messages Conversation.
	 * @param array<string, mixed>                             $options  Options.
	 * @return Result
	 */
	public function generate( string $key, string $model, array $messages, array $options = array() ): Result {
		$schema = isset( $options['schema'] ) && is_array( $options['schema'] ) ? $options['schema'] : null;
		$mode   = (string) ( $this->spec['structured_output'] ?? 'json_schema' );

		$body = array(
			'model'    => $model,
			'messages' => array(),
		);
		if ( null !== $schema && 'json_schema' !== $mode ) {
			// No schema enforcement on this provider: say what we need in the prompt, and ask
			// for JSON mode where it exists. The answer is still validated as JSON below.
			array_unshift(
				$messages,
				array(
					'role'    => 'system',
					'content' => 'Reply with a single JSON object only, no prose, matching this JSON Schema: ' . wp_json_encode( $schema ),
				)
			);
		}
		foreach ( $messages as $message ) {
			$body['messages'][] = array(
				'role'    => $message['role'],
				'content' => self::openai_content( $message['content'] ),
			);
		}
		if ( isset( $options['max_tokens'] ) ) {
			$body[ (string) ( $this->spec['max_tokens_param'] ?? 'max_tokens' ) ] = (int) $options['max_tokens'];
		}
		if ( isset( $options['temperature'] ) ) {
			$body['temperature'] = (float) $options['temperature'];
		}
		if ( null !== $schema && 'json_schema' === $mode ) {
			$body['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => (string) ( $options['schema_name'] ?? 'response' ),
					'schema' => $schema,
					'strict' => true,
				),
			);
		} elseif ( null !== $schema && 'json_object' === $mode ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		list( $data, $response ) = $this->call( 'POST', (string) ( $this->spec['chat_path'] ?? '/chat/completions' ), $key, $body );
		if ( null === $data ) {
			return Result::failed(
				$response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response, $model ),
				$this->id,
				$model
			);
		}

		$result           = new Result();
		$result->provider = $this->id;
		$result->model    = isset( $data['model'] ) && is_string( $data['model'] ) ? $data['model'] : $model;
		$message          = $data['choices'][0]['message'] ?? array();
		$result->text     = is_array( $message ) && isset( $message['content'] ) && is_string( $message['content'] ) ? $message['content'] : '';

		$result->input_tokens  = (int) ( $data['usage']['prompt_tokens'] ?? 0 );
		$result->output_tokens = (int) ( $data['usage']['completion_tokens'] ?? 0 );

		if ( null !== $schema ) {
			$result->data = Json::object_from( $result->text );
			if ( null === $result->data ) {
				$result->failure = $this->unreadable( $response );
			}
		}

		return $result;
	}

	/**
	 * Content in the chat-completions shape: a plain string, or text + `image_url` parts with the
	 * image inlined as a data URL (1.1.0, vision).
	 *
	 * @param mixed $content Message content.
	 * @return string|array<int, array<string, mixed>>
	 */
	private static function openai_content( $content ) {
		if ( is_string( $content ) || ! self::has_image( $content ) ) {
			return self::text_of( $content );
		}
		$out = array();
		foreach ( self::parts( $content ) as $part ) {
			$out[] = 'image' === $part['type']
				? array(
					'type'      => 'image_url',
					'image_url' => array( 'url' => 'data:' . $part['mime'] . ';base64,' . $part['data'] ),
				)
				: array(
					'type' => 'text',
					'text' => (string) $part['text'],
				);
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key API key.
	 * @return array<int, array{id: string, label: string}>|Failure
	 */
	public function embedding_models( string $key ) {
		if ( ! $this->supports_embeddings() ) {
			return array();
		}
		// OpenRouter lists embedding models on their own path, and those ids need not contain
		// "embed" (voyage-4, measured 2026-09-29); everywhere else they share the model list.
		$own                     = isset( $this->spec['embeddings_models_path'] );
		list( $body, $response ) = $this->call( 'GET', (string) ( $this->spec['embeddings_models_path'] ?? $this->spec['models_path'] ?? '/models' ), $key );
		if ( null === $body ) {
			return $response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response );
		}
		$ids = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && is_string( $row['id'] ) && ( $own || self::is_embedding_model( $row['id'] ) ) ) {
				$ids[] = $row['id'];
			}
		}

		return array_map(
			static fn( string $id ): array => array(
				'id'    => $id,
				'label' => $id,
			),
			self::newest_first( $ids )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $key     API key.
	 * @param string               $model   Model id.
	 * @param array<int, string>   $texts   Texts.
	 * @param array<string, mixed> $options Options.
	 * @return Result
	 */
	public function embed( string $key, string $model, array $texts, array $options = array() ): Result {
		if ( ! $this->supports_embeddings() ) {
			return parent::embed( $key, $model, $texts, $options );
		}
		$body = array(
			'model' => $model,
			'input' => array_values( array_map( 'strval', $texts ) ),
		);
		// Only providers whose preset says so accept `dimensions` (OpenAI's text-embedding-3
		// family); sending it elsewhere is a 400, so the vectors come back at their own size.
		if ( ! empty( $this->spec['embeddings_dimensions'] ) && isset( $options['dimensions'] ) && (int) $options['dimensions'] > 0 && 1 === preg_match( '/text-embedding-3/', $model ) ) {
			$body['dimensions'] = (int) $options['dimensions'];
		}
		list( $data, $response ) = $this->call( 'POST', (string) ( $this->spec['embeddings_path'] ?? '/embeddings' ), $key, $body );
		if ( null === $data ) {
			return Result::failed(
				$response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response, $model ),
				$this->id,
				$model
			);
		}
		$rows = array();
		foreach ( (array) ( $data['data'] ?? array() ) as $position => $row ) {
			if ( is_array( $row ) && isset( $row['embedding'] ) && is_array( $row['embedding'] ) ) {
				$rows[ (int) ( $row['index'] ?? $position ) ] = array_map( 'floatval', $row['embedding'] );
			}
		}
		ksort( $rows );
		$result               = new Result();
		$result->provider     = $this->id;
		$result->model        = $model;
		$result->vectors      = array_values( $rows );
		$result->input_tokens = (int) ( $data['usage']['prompt_tokens'] ?? $data['usage']['total_tokens'] ?? 0 );
		if ( count( $result->vectors ) !== count( $texts ) ) {
			$result->failure = $this->unreadable( $response );
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key API key.
	 * @return array<int, array{id: string, label: string}>|Failure
	 */
	public function image_models( string $key ) {
		if ( ! $this->supports_images() ) {
			return array();
		}
		list( $body, $response ) = $this->call( 'GET', (string) ( $this->spec['models_path'] ?? '/models' ), $key );
		if ( null === $body ) {
			return $response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response );
		}
		$ids = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && is_string( $row['id'] ) && 1 === preg_match( '/^(gpt-image|dall-e)/', $row['id'] ) ) {
				$ids[] = $row['id'];
			}
		}

		return array_map(
			static fn( string $id ): array => array(
				'id'    => $id,
				'label' => $id,
			),
			self::newest_first( $ids )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $key     API key.
	 * @param string               $model   Model id.
	 * @param string               $prompt  What to draw.
	 * @param array<string, mixed> $options `size`.
	 * @return Result
	 */
	public function generate_image( string $key, string $model, string $prompt, array $options = array() ): Result {
		if ( ! $this->supports_images() ) {
			return parent::generate_image( $key, $model, $prompt, $options );
		}
		$sizes = array(
			'square'    => '1024x1024',
			'landscape' => '1536x1024',
			'portrait'  => '1024x1536',
		);
		$body  = array(
			'model'  => $model,
			'prompt' => $prompt,
			'n'      => 1,
			'size'   => $sizes[ (string) ( $options['size'] ?? 'square' ) ] ?? '1024x1024',
		);
		if ( str_starts_with( $model, 'dall-e' ) ) {
			$body['response_format'] = 'b64_json';
		}
		list( $data, $response ) = $this->call( 'POST', (string) ( $this->spec['images_path'] ?? '/images/generations' ), $key, $body, 180 );
		if ( null === $data ) {
			return Result::failed(
				$response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response, $model ),
				$this->id,
				$model
			);
		}
		$result                = new Result();
		$result->provider      = $this->id;
		$result->model         = $model;
		$result->input_tokens  = (int) ( $data['usage']['input_tokens'] ?? 0 );
		$result->output_tokens = (int) ( $data['usage']['output_tokens'] ?? 0 );
		foreach ( (array) ( $data['data'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['b64_json'] ) && is_string( $row['b64_json'] ) ) {
				$result->images[] = array(
					'mime' => 'image/' . (string) ( $data['output_format'] ?? 'png' ),
					'data' => $row['b64_json'],
				);
			}
		}
		if ( ! $result->images ) {
			$result->failure = $this->unreadable( $response );
		}

		return $result;
	}
}
