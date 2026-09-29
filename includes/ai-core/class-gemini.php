<?php
/**
 * The Google Gemini API dialect.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-gemini.php by wp/bin/build-ai-core.php.
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
 * Adapter for Google Gemini (AI Studio keys).
 */
final class Gemini extends Provider {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key API key.
	 * @return array<int, array{id: string, label: string, context: int, price: array{input: float, output: float}|null}>|Failure
	 */
	public function list_models( string $key ) {
		list( $body, $response ) = $this->call( 'GET', (string) ( $this->spec['models_path'] ?? '/models' ) . '?pageSize=1000', $key );
		if ( null === $body ) {
			return $response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response );
		}

		$models = array();
		foreach ( (array) ( $body['models'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'] ) || ! is_string( $row['name'] ) ) {
				continue;
			}
			// Only models that can generate text; the list also carries embedding models.
			if ( ! in_array( 'generateContent', (array) ( $row['supportedGenerationMethods'] ?? array() ), true ) ) {
				continue;
			}
			$id = str_starts_with( $row['name'], 'models/' ) ? substr( $row['name'], 7 ) : $row['name'];
			if ( ! self::is_text_model( $id ) ) {
				continue;
			}
			$models[] = array(
				'id'      => $id,
				'label'   => isset( $row['displayName'] ) && is_string( $row['displayName'] ) ? $row['displayName'] : $id,
				'context' => (int) ( $row['inputTokenLimit'] ?? 0 ),
				'price'   => null,
			);
		}

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
		list( $system, $turns ) = self::split_system( $messages );
		$schema                 = isset( $options['schema'] ) && is_array( $options['schema'] ) ? $options['schema'] : null;

		$body = array( 'contents' => array() );
		foreach ( $turns as $turn ) {
			$body['contents'][] = array(
				'role'  => 'assistant' === $turn['role'] ? 'model' : 'user',
				'parts' => self::gemini_parts( $turn['content'] ),
			);
		}
		if ( '' !== $system ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system ) ) );
		}
		$config = array();
		if ( isset( $options['max_tokens'] ) ) {
			$config[ (string) ( $this->spec['max_tokens_param'] ?? 'maxOutputTokens' ) ] = (int) $options['max_tokens'];
		}
		if ( isset( $options['temperature'] ) ) {
			$config['temperature'] = (float) $options['temperature'];
		}
		if ( null !== $schema ) {
			$config['responseMimeType'] = 'application/json';
			$config[ (string) ( $this->spec['schema_field'] ?? 'responseJsonSchema' ) ] = $schema;
		}
		if ( array() !== $config ) {
			$body['generationConfig'] = $config;
		}

		$path                    = str_replace( '{model}', rawurlencode( $model ), (string) ( $this->spec['chat_path'] ?? '/models/{model}:generateContent' ) );
		list( $data, $response ) = $this->call( 'POST', $path, $key, $body );
		if ( null === $data ) {
			return Result::failed(
				$response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response, $model ),
				$this->id,
				$model
			);
		}

		$result                = new Result();
		$result->provider      = $this->id;
		$result->model         = isset( $data['modelVersion'] ) && is_string( $data['modelVersion'] ) ? $data['modelVersion'] : $model;
		$result->input_tokens  = (int) ( $data['usageMetadata']['promptTokenCount'] ?? 0 );
		$result->output_tokens = (int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 ) + (int) ( $data['usageMetadata']['thoughtsTokenCount'] ?? 0 );

		$text = array();
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) && empty( $part['thought'] ) ) {
				$text[] = $part['text'];
			}
		}
		$result->text = implode( '', $text );
		if ( null !== $schema ) {
			$result->data = Json::object_from( $result->text );
			if ( null === $result->data ) {
				$result->failure = $this->unreadable( $response );
			}
		}

		return $result;
	}

	/**
	 * Content as Gemini parts: `text` and `inline_data` (1.1.0, vision).
	 *
	 * @param mixed $content Message content.
	 * @return array<int, array<string, mixed>>
	 */
	private static function gemini_parts( $content ): array {
		$out = array();
		foreach ( self::parts( $content ) as $part ) {
			$out[] = 'image' === $part['type']
				? array(
					'inline_data' => array(
						'mime_type' => $part['mime'],
						'data'      => $part['data'],
					),
				)
				: array( 'text' => (string) $part['text'] );
		}

		return $out ? $out : array( array( 'text' => '' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key API key.
	 * @return array<int, array{id: string, label: string}>|Failure
	 */
	public function embedding_models( string $key ) {
		list( $body, $response ) = $this->call( 'GET', (string) ( $this->spec['models_path'] ?? '/models' ) . '?pageSize=1000', $key );
		if ( null === $body ) {
			return $response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response );
		}
		$ids = array();
		foreach ( (array) ( $body['models'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'] ) || ! is_string( $row['name'] ) ) {
				continue;
			}
			if ( ! in_array( 'embedContent', (array) ( $row['supportedGenerationMethods'] ?? array() ), true ) ) {
				continue;
			}
			$ids[] = str_starts_with( $row['name'], 'models/' ) ? substr( $row['name'], 7 ) : $row['name'];
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
	 * {@inheritDoc} Uses `batchEmbedContents` (measured 2026-09-29: accepted by
	 * gemini-embedding-001 and gemini-embedding-2 with `outputDimensionality`).
	 *
	 * @param string               $key     API key.
	 * @param string               $model   Model id.
	 * @param array<int, string>   $texts   Texts.
	 * @param array<string, mixed> $options Options.
	 * @return Result
	 */
	public function embed( string $key, string $model, array $texts, array $options = array() ): Result {
		$requests = array();
		foreach ( array_values( $texts ) as $text ) {
			$request = array(
				'model'    => 'models/' . $model,
				'content'  => array( 'parts' => array( array( 'text' => (string) $text ) ) ),
				'taskType' => 'query' === ( $options['type'] ?? 'document' ) ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT',
			);
			if ( isset( $options['dimensions'] ) && (int) $options['dimensions'] > 0 ) {
				$request['outputDimensionality'] = (int) $options['dimensions'];
			}
			$requests[] = $request;
		}
		$path                    = str_replace( '{model}', rawurlencode( $model ), (string) ( $this->spec['embeddings_path'] ?? '/models/{model}:batchEmbedContents' ) );
		list( $data, $response ) = $this->call( 'POST', $path, $key, array( 'requests' => $requests ) );
		if ( null === $data ) {
			return Result::failed(
				$response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response, $model ),
				$this->id,
				$model
			);
		}
		$result           = new Result();
		$result->provider = $this->id;
		$result->model    = $model;
		foreach ( (array) ( $data['embeddings'] ?? array() ) as $row ) {
			$result->vectors[] = is_array( $row ) && isset( $row['values'] ) && is_array( $row['values'] ) ? array_map( 'floatval', $row['values'] ) : array();
		}
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
		list( $body, $response ) = $this->call( 'GET', (string) ( $this->spec['models_path'] ?? '/models' ) . '?pageSize=1000', $key );
		if ( null === $body ) {
			return $response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response );
		}
		$ids    = array();
		$labels = array();
		foreach ( (array) ( $body['models'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'] ) || ! is_string( $row['name'] ) ) {
				continue;
			}
			$id = str_starts_with( $row['name'], 'models/' ) ? substr( $row['name'], 7 ) : $row['name'];
			if ( 1 === preg_match( '/^gemini-.*-image/', $id ) && in_array( 'generateContent', (array) ( $row['supportedGenerationMethods'] ?? array() ), true ) ) {
				$ids[]         = $id;
				$labels[ $id ] = isset( $row['displayName'] ) && is_string( $row['displayName'] ) ? $row['displayName'] : $id;
			}
		}

		return array_map(
			static fn( string $id ): array => array(
				'id'    => $id,
				'label' => $labels[ $id ],
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
		$ratios                  = array(
			'square'    => '1:1',
			'landscape' => '16:9',
			'portrait'  => '9:16',
		);
		$body                    = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $prompt ) ),
				),
			),
			'generationConfig' => array(
				'responseModalities' => array( 'TEXT', 'IMAGE' ),
				'imageConfig'        => array( 'aspectRatio' => $ratios[ (string) ( $options['size'] ?? 'square' ) ] ?? '1:1' ),
			),
		);
		$path                    = str_replace( '{model}', rawurlencode( $model ), (string) ( $this->spec['chat_path'] ?? '/models/{model}:generateContent' ) );
		list( $data, $response ) = $this->call( 'POST', $path, $key, $body, 180 );
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
		$result->input_tokens  = (int) ( $data['usageMetadata']['promptTokenCount'] ?? 0 );
		$result->output_tokens = (int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 );
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			$inline = is_array( $part ) ? ( $part['inlineData'] ?? $part['inline_data'] ?? null ) : null;
			if ( is_array( $inline ) && isset( $inline['data'] ) && is_string( $inline['data'] ) ) {
				$result->images[] = array(
					'mime' => (string) ( $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png' ),
					'data' => $inline['data'],
				);
			} elseif ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$result->text .= $part['text'];
			}
		}
		if ( ! $result->images ) {
			$result->failure = $this->unreadable( $response );
		}

		return $result;
	}
}
