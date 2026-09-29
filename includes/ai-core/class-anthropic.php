<?php
/**
 * The Anthropic Messages dialect.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-anthropic.php by wp/bin/build-ai-core.php.
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
 * Adapter for Anthropic (Claude).
 *
 * Structured output uses the Messages API's own JSON-schema output (`output_config.format`).
 *
 * ⛔ NOT a forced tool call, which was the usual trick: the current Claude models refuse
 * `tool_choice` forcing with a 400 (measured from Anthropic's docs, 2026-09-25), so a schema
 * request built that way would fail on exactly the models a customer is most likely to pick.
 */
final class Anthropic extends Provider {

	/** Messages requires an output cap; used when the caller sets none. */
	private const DEFAULT_MAX_TOKENS = 1024;

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key API key.
	 * @return array<int, array{id: string, label: string, context: int, price: array{input: float, output: float}|null}>|Failure
	 */
	public function list_models( string $key ) {
		list( $body, $response ) = $this->call( 'GET', (string) ( $this->spec['models_path'] ?? '/models' ) . '?limit=1000', $key );
		if ( null === $body ) {
			return $response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response );
		}

		$models = array();
		foreach ( (array) ( $body['data'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) ) {
				continue;
			}
			$models[] = array(
				'id'      => $row['id'],
				'label'   => isset( $row['display_name'] ) && is_string( $row['display_name'] ) ? $row['display_name'] : $row['id'],
				'context' => (int) ( $row['max_input_tokens'] ?? 0 ),
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

		$body = array(
			'model'      => $model,
			'max_tokens' => (int) ( $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS ),
			'messages'   => array(),
		);
		if ( '' !== $system ) {
			$body['system'] = $system;
		}
		foreach ( $turns as $turn ) {
			$body['messages'][] = array(
				'role'    => 'assistant' === $turn['role'] ? 'assistant' : 'user',
				'content' => self::anthropic_content( $turn['content'] ),
			);
		}
		if ( isset( $options['temperature'] ) ) {
			$body['temperature'] = (float) $options['temperature'];
		}
		if ( null !== $schema ) {
			$body['output_config'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => $schema,
				),
			);
		}

		list( $data, $response ) = $this->call( 'POST', (string) ( $this->spec['chat_path'] ?? '/messages' ), $key, $body );
		if ( null === $data ) {
			return Result::failed(
				$response['status'] >= 200 && $response['status'] < 300 ? $this->unreadable( $response ) : Failure::from_response( $this->id, $response, $model ),
				$this->id,
				$model
			);
		}

		$result                = new Result();
		$result->provider      = $this->id;
		$result->model         = isset( $data['model'] ) && is_string( $data['model'] ) ? $data['model'] : $model;
		$result->input_tokens  = (int) ( $data['usage']['input_tokens'] ?? 0 );
		$result->output_tokens = (int) ( $data['usage']['output_tokens'] ?? 0 );

		$text = array();
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) && isset( $block['text'] ) && is_string( $block['text'] ) ) {
				$text[] = $block['text'];
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
	 * Content in the Messages API shape: a plain string, or text + base64 `image` blocks (1.1.0).
	 *
	 * @param mixed $content Message content.
	 * @return string|array<int, array<string, mixed>>
	 */
	private static function anthropic_content( $content ) {
		if ( is_string( $content ) || ! self::has_image( $content ) ) {
			return self::text_of( $content );
		}
		$out = array();
		foreach ( self::parts( $content ) as $part ) {
			$out[] = 'image' === $part['type']
				? array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => $part['mime'],
						'data'       => $part['data'],
					),
				)
				: array(
					'type' => 'text',
					'text' => (string) $part['text'],
				);
		}

		return $out;
	}
}
