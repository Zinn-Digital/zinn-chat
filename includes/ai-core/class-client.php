<?php
/**
 * What a plugin feature calls to use AI.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-client.php by wp/bin/build-ai-core.php.
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
 * The API a feature uses: ask for a task, get a Result.
 *
 * ```php
 * $result = \ZinnDigital\Tranzly\AiCore\Client::generate(
 *     array( array( 'role' => 'user', 'content' => 'Translate …' ) ),
 *     array( 'task' => 'translate', 'purpose' => 'translate-post', 'schema' => $schema )
 * );
 * if ( ! $result->ok() ) { show $result->failure->message and ->link }
 * ```
 *
 * The feature never chooses a provider or holds a key: it names a TASK, and the site owner's
 * default for that task decides (a caller may still pass `provider` and `model`).
 */
final class Client {

	/**
	 * Generate.
	 *
	 * @param array<int, array{role: string, content: string|array<int, array<string, string>>}> $messages Conversation. Content may be a list of parts: `{type: text, text}` and `{type: image, mime, data}` (base64), for models that read images (1.1.0).
	 * @param array<string, mixed>                                                               $options `task`, `purpose` (for the usage log), `provider`, `model`, `schema`, `schema_name`, `max_tokens`, `temperature`, `system` (1.2.0: made by the site, not a person).
	 * @return Result
	 */
	public static function generate( array $messages, array $options = array() ): Result {
		$task     = in_array( $options['task'] ?? '', Store::TASKS, true ) ? (string) $options['task'] : 'general';
		$choice   = Store::default_for( $task );
		$provider = (string) ( $options['provider'] ?? $choice['provider'] );
		$model    = (string) ( $options['model'] ?? ( $provider === $choice['provider'] ? $choice['model'] : '' ) );

		// Before the "not set up" test: a key that exists but cannot be opened IS set up, and the
		// site owner needs to hear why it stopped working, not that it was never there.
		$state = '' === $provider ? 'none' : Store::key_state( $provider );
		if ( 'newer' === $state || 'unreadable' === $state ) {
			$message = 'newer' === $state
				? __( 'Your AI key was saved by a newer version of one of your plugins. Update this plugin, or enter the key again in the AI settings.', 'zinn-chat' )
				: __( 'Your saved AI key can no longer be opened (the site\'s security keys changed). Enter it again in the AI settings.', 'zinn-chat' );
			return Result::failed( Failure::refused( $message, Core::settings_url() ), $provider );
		}

		if ( '' === $provider || ! Store::configured( $provider ) ) {
			return Result::failed( Failure::refused( __( 'AI is not set up yet. Add a key for an AI provider in the AI settings.', 'zinn-chat' ), Core::settings_url() ) );
		}
		if ( '' === $model ) {
			$model = (string) ( Catalogue::recommended( $provider, $task )['best'] ?? '' );
		}
		if ( '' === $model ) {
			return Result::failed( Failure::refused( __( 'No model is chosen for this task. Pick one in the AI settings.', 'zinn-chat' ), Core::settings_url() ), $provider );
		}

		$context = array(
			'task'     => $task,
			'purpose'  => (string) ( $options['purpose'] ?? $task ),
			'provider' => $provider,
			'model'    => $model,
			'user'     => get_current_user_id(),
			// 1.2.0: a request the SITE makes on nobody's behalf (a visitor's chat answer, a
			// background index run) is not refused by the "which roles may use AI" rule, which
			// is about people; spending limits still apply.
			'system'   => ! empty( $options['system'] ),
		);
		$refusal = Core::policy()->check( $context );
		if ( null !== $refusal ) {
			Core::policy()->record( $context, Result::failed( $refusal, $provider, $model ) );
			return Result::failed( $refusal, $provider, $model );
		}

		// The host's own hook point (Core::boot `messages`): Page Builder Sandwich adds the site's
		// brand kit here. Each copy calls only its own host, so no plugin changes another's requests.
		$messages = Core::messages( $messages, $context );

		$adapter = Registry::adapter( $provider, Store::provider( $provider ) );
		if ( null === $adapter ) {
			return Result::failed( Failure::refused( __( 'That AI provider is not supported by this version of the plugin.', 'zinn-chat' ) ), $provider, $model );
		}

		$generate = array();
		foreach ( array( 'schema', 'schema_name', 'max_tokens', 'temperature' ) as $name ) {
			if ( isset( $options[ $name ] ) ) {
				$generate[ $name ] = $options[ $name ];
			}
		}
		$result = $adapter->generate( (string) Store::key( $provider ), $model, $messages, $generate );
		Core::policy()->record( $context, $result );

		return $result;
	}

	/**
	 * Generate an image (1.1.0). The site owner's image choice decides the provider and model; with
	 * none saved, the first connected provider that can make images is used with its newest image
	 * model. Same policy (limits, roles) and usage log as text.
	 *
	 * @param string               $prompt  What to draw.
	 * @param array<string, mixed> $options `purpose`, `provider`, `model`, `size` (square|landscape|portrait).
	 * @return Result `images`: each `mime` + base64 `data`.
	 */
	public static function image( string $prompt, array $options = array() ): Result {
		$choice   = Store::image_default();
		$provider = (string) ( $options['provider'] ?? $choice['provider'] );
		$model    = (string) ( $options['model'] ?? ( $provider === $choice['provider'] ? $choice['model'] : '' ) );
		if ( '' === $provider ) {
			foreach ( array_keys( Registry::all() ) as $id ) {
				$adapter = Store::configured( $id ) ? Registry::adapter( $id, Store::provider( $id ) ) : null;
				if ( $adapter && $adapter->supports_images() ) {
					$provider = $id;
					break;
				}
			}
		}
		if ( '' === $provider || ! Store::configured( $provider ) ) {
			return Result::failed( Failure::refused( __( 'No AI provider that can make images is connected. Add an OpenAI or Google Gemini key in the AI settings.', 'zinn-chat' ), Core::settings_url() ) );
		}
		$adapter = Registry::adapter( $provider, Store::provider( $provider ) );
		if ( null === $adapter || ! $adapter->supports_images() ) {
			return Result::failed( Failure::refused( __( 'This AI provider cannot make images. Choose OpenAI or Google Gemini for images in the AI settings.', 'zinn-chat' ), Core::settings_url() ), $provider );
		}
		if ( '' === $model ) {
			$model = (string) ( Models::image_choices( $provider )[0]['id'] ?? '' );
		}
		if ( '' === $model ) {
			return Result::failed( Failure::refused( __( 'Your AI key cannot use any image model. Check the key\'s permissions with the provider, or choose another provider for images.', 'zinn-chat' ), Core::settings_url() ), $provider );
		}

		$context = array(
			'task'     => 'image',
			'purpose'  => (string) ( $options['purpose'] ?? 'image' ),
			'provider' => $provider,
			'model'    => $model,
			'user'     => get_current_user_id(),
			// 1.2.0: a request the SITE makes on nobody's behalf (a visitor's chat answer, a
			// background index run) is not refused by the "which roles may use AI" rule, which
			// is about people; spending limits still apply.
			'system'   => ! empty( $options['system'] ),
		);
		$refusal = Core::policy()->check( $context );
		if ( null !== $refusal ) {
			Core::policy()->record( $context, Result::failed( $refusal, $provider, $model ) );
			return Result::failed( $refusal, $provider, $model );
		}
		$result = $adapter->generate_image( (string) Store::key( $provider ), $model, $prompt, array( 'size' => (string) ( $options['size'] ?? 'square' ) ) );
		Core::policy()->record( $context, $result );

		return $result;
	}

	/**
	 * Embed texts for search (1.2.0). The site owner's embedding choice decides the provider and
	 * model; with none saved, the provider of the `general` default is used when it can embed,
	 * else the first connected provider that can, with its newest embedding model. Texts are sent
	 * in batches; the vectors come back in input order. Same policy and usage log as text.
	 *
	 * @param array<int, string>   $texts   Texts to embed.
	 * @param array<string, mixed> $options `purpose`, `provider`, `model`, `dimensions`, `type` (`document`|`query`), `batch`, `system`.
	 * @return Result `vectors`, one per text; `provider`/`model` say what built them.
	 */
	public static function embed( array $texts, array $options = array() ): Result {
		$choice   = self::embedding_choice( $options );
		$provider = $choice['provider'];
		$model    = $choice['model'];
		if ( '' === $provider ) {
			return Result::failed( Failure::refused( __( 'No connected AI provider can build a search index. Add a Google Gemini, OpenAI, Mistral or OpenRouter key in the AI settings.', 'zinn-chat' ), Core::settings_url() ) );
		}
		if ( '' === $model ) {
			return Result::failed( Failure::refused( __( 'Your AI key cannot use any embedding model. Check the key\'s permissions with the provider, or choose another provider for site search.', 'zinn-chat' ), Core::settings_url() ), $provider );
		}
		$context = array(
			'task'     => 'embed',
			'purpose'  => (string) ( $options['purpose'] ?? 'embed' ),
			'provider' => $provider,
			'model'    => $model,
			'user'     => get_current_user_id(),
			// 1.2.0: a request the SITE makes on nobody's behalf (a visitor's chat answer, a
			// background index run) is not refused by the "which roles may use AI" rule, which
			// is about people; spending limits still apply.
			'system'   => ! empty( $options['system'] ),
		);
		$refusal = Core::policy()->check( $context );
		if ( null !== $refusal ) {
			Core::policy()->record( $context, Result::failed( $refusal, $provider, $model ) );
			return Result::failed( $refusal, $provider, $model );
		}
		$adapter = Registry::adapter( $provider, Store::provider( $provider ) );
		if ( null === $adapter || ! $adapter->supports_embeddings() ) {
			return Result::failed( Failure::refused( __( 'This AI provider cannot build a search index. Connect Google Gemini, OpenAI, Mistral or a compatible service for site search.', 'zinn-chat' ) ), $provider, $model );
		}
		$size            = max( 1, min( 100, (int) ( $options['batch'] ?? 64 ) ) );
		$total           = new Result();
		$total->provider = $provider;
		$total->model    = $model;
		$pass            = array(
			'dimensions' => (int) ( $options['dimensions'] ?? 0 ),
			'type'       => (string) ( $options['type'] ?? 'document' ),
		);
		foreach ( array_chunk( array_values( $texts ), $size ) as $batch ) {
			$result = $adapter->embed( (string) Store::key( $provider ), $model, $batch, $pass );
			if ( ! $result->ok() ) {
				Core::policy()->record( $context, $result );
				return $result;
			}
			$total->input_tokens += $result->input_tokens;
			foreach ( $result->vectors as $vector ) {
				$total->vectors[] = $vector;
			}
		}
		Core::policy()->record( $context, $total );

		return $total;
	}

	/**
	 * Which provider and model embeddings would use now, without calling anything but a cached
	 * model list. Callers store it beside their vectors: a change means the index must be rebuilt.
	 *
	 * @param array<string, mixed> $options `provider`, `model` overrides.
	 * @return array{provider: string, model: string}
	 */
	public static function embedding_choice( array $options = array() ): array {
		$saved    = Store::embed_default();
		$provider = (string) ( $options['provider'] ?? $saved['provider'] );
		$model    = (string) ( $options['model'] ?? ( $provider === $saved['provider'] ? $saved['model'] : '' ) );
		if ( '' !== $provider && ! Store::configured( $provider ) ) {
			$provider = '';
			$model    = '';
		}
		if ( '' === $provider ) {
			$candidates = array_keys( Registry::all() );
			$general    = Store::default_for( 'general' )['provider'];
			if ( '' !== $general ) {
				array_unshift( $candidates, $general );
			}
			foreach ( array_unique( $candidates ) as $id ) {
				$adapter = Store::configured( $id ) ? Registry::adapter( $id, Store::provider( $id ) ) : null;
				if ( $adapter && $adapter->supports_embeddings() ) {
					$provider = $id;
					break;
				}
			}
		}
		if ( '' !== $provider && '' === $model ) {
			$model = (string) ( Models::embedding_choices( $provider )[0]['id'] ?? '' );
		}

		return array(
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Is any provider ready?
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		foreach ( array_keys( Registry::all() ) as $provider ) {
			if ( Store::configured( $provider ) ) {
				return true;
			}
		}

		return false;
	}
}
