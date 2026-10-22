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
	 * @param array<string, mixed>                                                               $options `task`, `purpose` (for the usage log), `provider`, `model`, `schema`, `schema_name`, `max_tokens`, `temperature`, `system` (1.2.0: made by the site, not a person), `thinking` (1.3.0: `minimal` asks for the least reasoning the model accepts; adapters without the notion ignore it).
	 * @return Result
	 */
	public static function generate( array $messages, array $options = array() ): Result {
		$prepared = self::prepare( $options );
		if ( $prepared instanceof Result ) {
			return $prepared;
		}
		list( $provider, $model, $context, $adapter ) = $prepared;

		// The host's own hook point (Core::boot `messages`): Page Builder Sandwich adds the site's
		// brand kit here. Each copy calls only its own host, so no plugin changes another's requests.
		$messages = Core::messages( $messages, $context );

		$result = $adapter->generate( (string) Store::key( $provider ), $model, $messages, self::pass( $options, $context ) );
		Core::policy()->record( $context, $result );

		return $result;
	}

	/**
	 * Many independent requests with the same options (1.4.0): a bulk job's items.
	 *
	 * ⭐ Zinn® credits send them `Zinn::MAX_BATCH` per call; a provider with the site owner's own
	 * key answers each in turn. Either way every item is checked against the policy (limits,
	 * roles) and recorded in the usage log, exactly as `generate()` would. A refusal before
	 * anything is sent fails every item with the same reason, and spends nothing.
	 *
	 * @param array<int, array{id?: string, messages: array}> $items   Requests, in order.
	 * @param array<string, mixed>                            $options As `generate()`.
	 * @return array<int, Result> One per item, in input order, each with its `id`.
	 */
	public static function generate_many( array $items, array $options = array() ): array {
		$items    = array_values( $items );
		$prepared = self::prepare( $options );
		if ( $prepared instanceof Result ) {
			$out = array();
			foreach ( $items as $index => $item ) {
				$copy     = clone $prepared;
				$copy->id = (string) ( $item['id'] ?? (string) $index );
				$out[]    = $copy;
			}
			return $out;
		}
		list( $provider, $model, $context, $adapter ) = $prepared;
		$pass = self::pass( $options, $context );
		$out  = array();
		if ( $adapter instanceof Zinn ) {
			$batch = array();
			foreach ( $items as $index => $item ) {
				$batch[] = array(
					'id'       => (string) ( $item['id'] ?? (string) $index ),
					'messages' => Core::messages( is_array( $item['messages'] ?? null ) ? $item['messages'] : array(), $context ),
					'options'  => $pass,
				);
			}
			foreach ( $adapter->generate_batch( $batch, (string) $context['purpose'] ) as $result ) {
				Core::policy()->record( $context, $result );
				$out[] = $result;
			}
			return $out;
		}
		foreach ( $items as $index => $item ) {
			$refusal = $index > 0 ? Core::policy()->check( $context ) : null;
			if ( null !== $refusal ) {
				$result = Result::failed( $refusal, $provider, $model );
			} else {
				$result = $adapter->generate( (string) Store::key( $provider ), $model, Core::messages( is_array( $item['messages'] ?? null ) ? $item['messages'] : array(), $context ), $pass );
			}
			Core::policy()->record( $context, $result );
			$result->id = (string) ( $item['id'] ?? (string) $index );
			$out[]      = $result;
		}

		return $out;
	}

	/**
	 * Which provider and model a text request goes to (1.4.0): the caller's, then the host's own
	 * choice (Core::boot `choose`), then the site owner's default for the task.
	 *
	 * @param string               $task    Task id.
	 * @param array<string, mixed> $options `provider`, `model`.
	 * @return array{provider: string, model: string}
	 */
	public static function choice( string $task, array $options = array() ): array {
		$choice = Core::choose( $task ) ?? Store::default_for( $task );
		if ( isset( $options['provider'] ) ) {
			$provider = (string) $options['provider'];
			$model    = (string) ( $options['model'] ?? ( $provider === $choice['provider'] ? $choice['model'] : '' ) );
		} else {
			$provider = $choice['provider'];
			$model    = (string) ( $options['model'] ?? $choice['model'] );
		}
		if ( Zinn::ID === $provider ) {
			$model = Zinn::MODEL;
		}

		return array(
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Resolve and check a text request: the provider, model, context and adapter, or the Result
	 * that refuses it (recorded).
	 *
	 * @param array<string, mixed> $options See generate().
	 * @return Result|array{0: string, 1: string, 2: array<string, mixed>, 3: Provider}
	 */
	private static function prepare( array $options ) {
		$task     = in_array( $options['task'] ?? '', Store::TASKS, true ) ? (string) $options['task'] : 'general';
		$choice   = self::choice( $task, $options );
		$provider = $choice['provider'];
		$model    = $choice['model'];

		// Before the "not set up" test: a key that exists but cannot be opened IS set up, and the
		// site owner needs to hear why it stopped working, not that it was never there.
		$state = '' === $provider ? 'none' : Store::key_state( $provider );
		if ( 'newer' === $state || 'unreadable' === $state ) {
			$message = 'newer' === $state
				? __( 'Your AI key was saved by a newer version of one of your plugins. Update this plugin, or enter the key again in the AI settings.', 'zinn-chat' )
				: __( 'Your saved AI key can no longer be opened (the site\'s security keys changed). Enter it again in the AI settings.', 'zinn-chat' );
			return Result::failed( Failure::refused( $message, Core::settings_url() ), $provider );
		}

		if ( Zinn::ID === $provider && ! Zinn::ready() ) {
			return Result::failed( Zinn::not_ready(), $provider, $model );
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

		$adapter = Registry::adapter( $provider, Store::provider( $provider ) );
		if ( null === $adapter ) {
			return Result::failed( Failure::refused( __( 'That AI provider is not supported by this version of the plugin.', 'zinn-chat' ) ), $provider, $model );
		}

		return array( $provider, $model, $context, $adapter );
	}

	/**
	 * The generation options an adapter receives.
	 *
	 * @param array<string, mixed> $options The caller's options.
	 * @param array<string, mixed> $context The request context.
	 * @return array<string, mixed>
	 */
	private static function pass( array $options, array $context ): array {
		$generate = array();
		foreach ( array( 'schema', 'schema_name', 'max_tokens', 'temperature', 'thinking' ) as $name ) {
			if ( isset( $options[ $name ] ) ) {
				$generate[ $name ] = $options[ $name ];
			}
		}
		if ( Zinn::ID === $context['provider'] ) {
			// For the Zinn® account's own usage log; a vendor adapter never sees it.
			$generate['purpose'] = (string) $context['purpose'];
		}

		return $generate;
	}

	/**
	 * Will text requests for this task be paid with Zinn® credits (1.4.0)? Features that read
	 * images ask, because credits read text only.
	 *
	 * @param string               $task    Task id.
	 * @param array<string, mixed> $options `provider`.
	 * @return bool
	 */
	public static function on_credits( string $task = 'general', array $options = array() ): bool {
		return Zinn::ID === self::choice( in_array( $task, Store::TASKS, true ) ? $task : 'general', $options )['provider'];
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
