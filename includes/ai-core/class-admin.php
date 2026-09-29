<?php
/**
 * Settings → AI providers: the setup wizard and the settings screen.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-admin.php by wp/bin/build-ai-core.php.
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
 * The screen where a site owner connects an AI provider (ai-5), picks models (ai-2..ai-4) and,
 * in Pro, sees usage and sets limits and roles (ai-8, ai-9).
 *
 * ⭐ Server-rendered on purpose, with WordPress's own admin markup: it works with no JavaScript,
 * it reads right-to-left with no extra stylesheet, and every string goes through the host
 * plugin's text domain like the rest of its PHP.
 *
 * ⛔ Every form posts to `admin-post.php` with its own nonce, and every handler checks
 * `manage_options` itself — the screen being hidden from other users is not the control.
 * ⛔ A saved key is never printed back, not even into a `value` attribute: the screen shows
 * the last four characters only.
 */
final class Admin {

	/** Capability for everything on this screen. */
	private const CAP = 'manage_options';

	/** Form actions, each with its own nonce. */
	private const ACTIONS = array( 'save_key', 'remove_key', 'test', 'defaults', 'add_model', 'remove_model', 'catalogue', 'refresh_models' );

	/**
	 * Hook the menu and the form handlers (elected copy only).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_zinn_ai_' . $action, array( self::class, 'handle_' . $action ) );
		}
	}

	/**
	 * Settings → AI providers.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_options_page(
			__( 'AI providers', 'zinn-chat' ),
			__( 'AI providers', 'zinn-chat' ),
			self::CAP,
			Core::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Plain-English help for each provider (ai-5).
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function describe( string $provider ): string {
		switch ( $provider ) {
			case 'openai':
				return __( 'The makers of ChatGPT. A good all-rounder.', 'zinn-chat' );
			case 'anthropic':
				return __( 'The makers of Claude. Strong at writing and careful reasoning.', 'zinn-chat' );
			case 'gemini':
				return __( 'Google\'s Gemini models. Fast and inexpensive.', 'zinn-chat' );
			case 'mistral':
				return __( 'A European AI company with fast, inexpensive models.', 'zinn-chat' );
			case 'deepseek':
				return __( 'Low-cost models that are good at writing and code.', 'zinn-chat' );
			case 'openrouter':
				return __( 'One key for models from many different providers.', 'zinn-chat' );
			case Registry::CUSTOM:
				return __( 'Any service that works like OpenAI\'s API, including a model running on your own computer or server.', 'zinn-chat' );
		}

		return '';
	}

	/**
	 * Task names for the default-model picker.
	 *
	 * @return array<string, string>
	 */
	private static function tasks(): array {
		return array(
			'general'   => __( 'General (used when nothing more specific is set)', 'zinn-chat' ),
			'write'     => __( 'Writing and rewriting', 'zinn-chat' ),
			'translate' => __( 'Translation', 'zinn-chat' ),
			'code'      => __( 'Code and CSS', 'zinn-chat' ),
		);
	}

	/**
	 * Print the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'zinn-chat' ), 403 );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'AI providers', 'zinn-chat' ) . '</h1>';
		self::print_notice();

		$names = array_map(
			static fn( array $copy ): string => (string) apply_filters( 'zinn_ai_core_host_name', $copy['name'], $copy['host'] ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the core's own hook family (zinn_ai_core_*), shared by both plugins.
			Core::copies()
		);
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: list of plugin names, e.g. "Tranzly, Page Builder Sandwich". */
				__( 'These settings are shared by every plugin that uses them: %s.', 'zinn-chat' ),
				implode( ', ', array_unique( $names ) )
			)
		) . ' ' . esc_html__( 'Your keys are encrypted in this site\'s database and are only ever sent to the provider you chose — never to us.', 'zinn-chat' ) . '</p>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation between wizard steps only; nothing is changed.
		$step = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
		if ( ! Client::ready() ) {
			self::render_wizard( Registry::exists( $step ) ? $step : '' );
			echo '</div>';
			return;
		}

		self::render_providers();
		self::render_defaults();
		self::render_add_model();
		self::render_catalogue();
		Core::policy()->render_settings();
		echo '</div>';
	}

	/**
	 * First run: pick a provider, then get and paste a key.
	 *
	 * @param string $provider The chosen provider, or '' for step 1.
	 * @return void
	 */
	private static function render_wizard( string $provider ): void {
		echo '<div class="card" style="max-width:48rem">';
		if ( '' === $provider ) {
			echo '<h2>' . esc_html__( 'Step 1 of 2: choose an AI provider', 'zinn-chat' ) . '</h2>';
			echo '<p>' . esc_html__( 'You bring your own key, so you pay the provider directly for what you use. Not sure? Any of these works well; you can add more later.', 'zinn-chat' ) . '</p>';
			echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '">';
			echo '<input type="hidden" name="page" value="' . esc_attr( Core::PAGE ) . '">';
			echo '<fieldset>';
			$first = true;
			foreach ( Registry::all() as $id => $spec ) {
				echo '<p><label><input type="radio" name="provider" value="' . esc_attr( $id ) . '"' . ( $first ? ' checked' : '' ) . '> <strong>' . esc_html( (string) ( $spec['label'] ?? $id ) ) . '</strong> — ' . esc_html( self::describe( $id ) ) . '</label></p>';
				$first = false;
			}
			echo '</fieldset>';
			submit_button( __( 'Next', 'zinn-chat' ), 'primary', '', false );
			echo '</form></div>';
			return;
		}

		$spec = Registry::get( $provider );
		echo '<h2>' . esc_html(
			sprintf(
				/* translators: %s: AI provider name. */
				__( 'Step 2 of 2: connect %s', 'zinn-chat' ),
				(string) ( $spec['label'] ?? $provider )
			)
		) . '</h2>';
		if ( Registry::CUSTOM === $provider ) {
			echo '<p>' . esc_html__( 'Enter the address of your OpenAI-compatible service, ending in /v1. A key is only needed if the service asks for one.', 'zinn-chat' ) . '</p>';
		} else {
			echo '<ol>';
			echo '<li>' . sprintf(
				/* translators: %s: link to the provider's API key page. */
				esc_html__( 'Open %s and sign in (or create a free account).', 'zinn-chat' ),
				'<a href="' . esc_url( (string) ( $spec['key_url'] ?? '' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html(
					sprintf(
						/* translators: %s: AI provider name. */
						__( 'the %s API keys page', 'zinn-chat' ),
						(string) ( $spec['label'] ?? $provider )
					)
				) . '</a>'
			) . '</li>';
			echo '<li>' . esc_html__( 'Create a new key and copy it.', 'zinn-chat' ) . '</li>';
			echo '<li>' . esc_html__( 'Paste it below and press "Save and test". We send one tiny request to check it works.', 'zinn-chat' ) . '</li>';
			echo '</ol>';
			if ( '' !== (string) ( $spec['billing_url'] ?? '' ) ) {
				echo '<p class="description">' . sprintf(
					/* translators: %s: link to the provider's billing page. */
					esc_html__( 'Most providers need a little credit on the account before a key works: %s.', 'zinn-chat' ),
					'<a href="' . esc_url( (string) $spec['billing_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'add credit', 'zinn-chat' ) . '</a>'
				) . '</p>';
			}
		}
		self::key_form( $provider, true );
		echo '<p><a href="' . esc_url( Core::settings_url() ) . '">' . esc_html__( '← Choose a different provider', 'zinn-chat' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * The save-key form for one provider.
	 *
	 * @param string $provider Provider id.
	 * @param bool   $wizard   Wider fields and a primary button.
	 * @return void
	 */
	private static function key_form( string $provider, bool $wizard ): void {
		$saved = Store::provider( $provider );
		$spec  = Registry::get( $provider );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'zinn_ai_save_key' );
		echo '<input type="hidden" name="action" value="zinn_ai_save_key"><input type="hidden" name="provider" value="' . esc_attr( $provider ) . '">';
		if ( Registry::CUSTOM === $provider ) {
			echo '<p><label>' . esc_html__( 'Address', 'zinn-chat' ) . '<br><input type="url" class="regular-text" name="base_url" placeholder="http://localhost:11434/v1" value="' . esc_attr( (string) ( $saved['base_url'] ?? '' ) ) . '" required></label></p>';
		}
		$placeholder = '' !== (string) ( $saved['hint'] ?? '' )
			/* translators: %s: the last four characters of the saved key. */
			? sprintf( __( 'Saved key ends in …%s — paste a new one to replace it', 'zinn-chat' ), (string) $saved['hint'] )
			: (string) ( $spec['key_prefix'] ?? '' );
		echo '<p><label>' . esc_html( Registry::CUSTOM === $provider ? __( 'Key (optional)', 'zinn-chat' ) : __( 'API key', 'zinn-chat' ) );
		echo '<br><input type="password" class="' . ( $wizard ? 'large-text' : 'regular-text' ) . '" name="key" autocomplete="off" spellcheck="false" placeholder="' . esc_attr( $placeholder ) . '"' . ( $wizard && Registry::CUSTOM !== $provider ? ' required' : '' ) . '></label></p>';
		submit_button( __( 'Save and test', 'zinn-chat' ), $wizard ? 'primary' : 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Every provider with its state and actions.
	 *
	 * @return void
	 */
	private static function render_providers(): void {
		echo '<h2>' . esc_html__( 'Providers', 'zinn-chat' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Provider', 'zinn-chat' ) . '</th><th>' . esc_html__( 'Status', 'zinn-chat' ) . '</th><th>' . esc_html__( 'Key', 'zinn-chat' ) . '</th></tr></thead><tbody>';
		foreach ( Registry::all() as $id => $spec ) {
			$saved = Store::provider( $id );
			echo '<tr><td><strong>' . esc_html( (string) ( $spec['label'] ?? $id ) ) . '</strong><br><span class="description">' . esc_html( self::describe( $id ) ) . '</span>';
			if ( '' !== (string) ( $spec['key_url'] ?? '' ) ) {
				echo '<br><a href="' . esc_url( (string) $spec['key_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Get a key', 'zinn-chat' ) . '</a>';
			}
			echo '</td><td>' . esc_html( self::status_text( $id, $saved ) );
			if ( Store::configured( $id ) ) {
				echo '<br>';
				self::small_form( 'test', array( 'provider' => $id ), __( 'Test', 'zinn-chat' ) );
				self::small_form( 'remove_key', array( 'provider' => $id ), __( 'Remove', 'zinn-chat' ) );
			}
			echo '</td><td>';
			self::key_form( $id, false );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * One provider's state, in words.
	 *
	 * @param string               $provider Provider id.
	 * @param array<string, mixed> $saved    Its saved settings.
	 * @return string
	 */
	private static function status_text( string $provider, array $saved ): string {
		switch ( Store::key_state( $provider ) ) {
			case 'newer':
				return __( 'Saved by a newer plugin version: update this plugin, or enter the key again.', 'zinn-chat' );
			case 'unreadable':
				return __( 'The saved key can no longer be opened (the site\'s security keys changed). Enter it again.', 'zinn-chat' );
		}
		if ( ! Store::configured( $provider ) ) {
			return __( 'Not connected', 'zinn-chat' );
		}
		$test = is_array( $saved['test'] ?? null ) ? $saved['test'] : array();
		if ( ! empty( $test['ok'] ) ) {
			return sprintf(
				/* translators: %s: date and time of the last successful test. */
				__( 'Connected — last test passed %s', 'zinn-chat' ),
				wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) ( $test['at'] ?? 0 ) )
			);
		}
		if ( isset( $test['message'] ) ) {
			/* translators: %s: why the last test failed. */
			return sprintf( __( 'Last test failed: %s', 'zinn-chat' ), (string) $test['message'] );
		}

		return __( 'Connected — not tested yet', 'zinn-chat' );
	}

	/**
	 * Default model per task.
	 *
	 * @return void
	 */
	private static function render_defaults(): void {
		echo '<h2>' . esc_html__( 'Which model to use', 'zinn-chat' ) . '</h2>';
		echo '<p>' . esc_html__( '★ marks our recommendation for that task. The list includes every model your key can use, read live from the provider.', 'zinn-chat' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'zinn_ai_defaults' );
		echo '<input type="hidden" name="action" value="zinn_ai_defaults"><table class="form-table" role="presentation"><tbody>';
		foreach ( self::tasks() as $task => $label ) {
			$current = Store::default_for( $task );
			echo '<tr><th scope="row"><label for="zinn-ai-task-' . esc_attr( $task ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<select id="zinn-ai-task-' . esc_attr( $task ) . '" name="defaults[' . esc_attr( $task ) . ']">';
			echo '<option value="">' . esc_html__( '— Recommended for the provider —', 'zinn-chat' ) . '</option>';
			foreach ( Registry::all() as $id => $spec ) {
				if ( ! Store::configured( $id ) ) {
					continue;
				}
				$marked = Catalogue::recommended( $id, $task );
				echo '<optgroup label="' . esc_attr( (string) ( $spec['label'] ?? $id ) ) . '">';
				foreach ( Models::choices( $id ) as $choice ) {
					$value = $id . '|' . $choice['id'];
					$star  = in_array( $choice['id'], $marked, true ) ? '★ ' : '';
					$tag   = array_search( $choice['id'], $marked, true );
					$note  = is_string( $tag ) ? ' — ' . self::tier( $tag ) : '';
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $current['provider'] . '|' . $current['model'], $value, false ) . '>' . esc_html( $star . $choice['label'] . $note ) . '</option>';
				}
				echo '</optgroup>';
			}
			echo '</select></td></tr>';
		}
		self::render_image_row();
		self::render_embedding_row();
		echo '</tbody></table>';
		submit_button( __( 'Save models', 'zinn-chat' ), 'primary', '', false );
		echo '</form>';
		self::small_form( 'refresh_models', array(), __( 'Refresh the model lists from the providers', 'zinn-chat' ) );
	}

	/**
	 * The image model row (1.1.0): providers that can make images, their image models newest first.
	 *
	 * @return void
	 */
	private static function render_image_row(): void {
		$current = Store::image_default();
		$groups  = array();
		foreach ( Registry::all() as $id => $spec ) {
			if ( ! Store::configured( $id ) || empty( $spec['images'] ) ) {
				continue;
			}
			$groups[ $id ] = array(
				'label'  => (string) ( $spec['label'] ?? $id ),
				'models' => Models::image_choices( $id ),
			);
		}
		echo '<tr><th scope="row"><label for="zinn-ai-task-images">' . esc_html__( 'Images', 'zinn-chat' ) . '</label></th><td>';
		if ( ! $groups ) {
			echo '<p>' . esc_html__( 'Connect OpenAI or Google Gemini to make images.', 'zinn-chat' ) . '</p></td></tr>';
			return;
		}
		echo '<select id="zinn-ai-task-images" name="defaults[images]">';
		echo '<option value="">' . esc_html__( '— The newest image model of the first connected provider —', 'zinn-chat' ) . '</option>';
		foreach ( $groups as $id => $group ) {
			echo '<optgroup label="' . esc_attr( $group['label'] ) . '">';
			foreach ( $group['models'] as $model ) {
				$value = $id . '|' . $model['id'];
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $current['provider'] . '|' . $current['model'], $value, false ) . '>' . esc_html( $model['label'] ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select></td></tr>';
	}

	/**
	 * The embedding model row (1.2.0): site search / semantic index, newest model first.
	 *
	 * @return void
	 */
	private static function render_embedding_row(): void {
		$current = Store::embed_default();
		$groups  = array();
		foreach ( Registry::all() as $id => $spec ) {
			if ( ! Store::configured( $id ) || empty( $spec['embeddings'] ) ) {
				continue;
			}
			$groups[ $id ] = array(
				'label'  => (string) ( $spec['label'] ?? $id ),
				'models' => Models::embedding_choices( $id ),
			);
		}
		echo '<tr><th scope="row"><label for="zinn-ai-task-embeddings">' . esc_html__( 'Site search (embeddings)', 'zinn-chat' ) . '</label></th><td>';
		if ( ! $groups ) {
			echo '<p>' . esc_html__( 'Connect Google Gemini, OpenAI, Mistral or OpenRouter to build a search index of your site.', 'zinn-chat' ) . '</p></td></tr>';
			return;
		}
		echo '<select id="zinn-ai-task-embeddings" name="defaults[embeddings]">';
		echo '<option value="">' . esc_html__( '— The newest embedding model of the first connected provider —', 'zinn-chat' ) . '</option>';
		foreach ( $groups as $id => $group ) {
			echo '<optgroup label="' . esc_attr( $group['label'] ) . '">';
			foreach ( $group['models'] as $model ) {
				$value = $id . '|' . $model['id'];
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $current['provider'] . '|' . $current['model'], $value, false ) . '>' . esc_html( $model['label'] ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select><p class="description">' . esc_html__( 'Changing this rebuilds the search index of every plugin that uses it.', 'zinn-chat' ) . '</p></td></tr>';
	}

	/**
	 * Translate a catalogue tier.
	 *
	 * @param string $tier `best`, `cheap` or `fast`.
	 * @return string
	 */
	private static function tier( string $tier ): string {
		switch ( $tier ) {
			case 'best':
				return __( 'best quality', 'zinn-chat' );
			case 'cheap':
				return __( 'lowest cost', 'zinn-chat' );
			case 'fast':
				return __( 'fastest', 'zinn-chat' );
		}

		return $tier;
	}

	/**
	 * Add a model by hand (ai-4).
	 *
	 * @return void
	 */
	private static function render_add_model(): void {
		echo '<h2>' . esc_html__( 'Add a model yourself', 'zinn-chat' ) . '</h2>';
		echo '<p>' . esc_html__( 'A model came out today? Paste its exact name to use it straight away.', 'zinn-chat' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'zinn_ai_add_model' );
		echo '<input type="hidden" name="action" value="zinn_ai_add_model"><p>';
		echo '<label>' . esc_html__( 'Provider', 'zinn-chat' ) . ' <select name="provider">';
		foreach ( Registry::all() as $id => $spec ) {
			if ( Store::configured( $id ) ) {
				echo '<option value="' . esc_attr( $id ) . '">' . esc_html( (string) ( $spec['label'] ?? $id ) ) . '</option>';
			}
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Model name', 'zinn-chat' ) . ' <input type="text" name="model" class="regular-text" required spellcheck="false"></label> ';
		submit_button( __( 'Add model', 'zinn-chat' ), 'secondary', '', false );
		echo '</p></form>';

		$added = Store::added_models();
		if ( array() !== $added ) {
			echo '<ul>';
			foreach ( $added as $row ) {
				echo '<li>' . esc_html( (string) ( Registry::get( $row['provider'] )['label'] ?? $row['provider'] ) . ': ' . $row['id'] ) . ' ';
				self::small_form(
					'remove_model',
					array(
						'provider' => $row['provider'],
						'model'    => $row['id'],
					),
					__( 'Remove', 'zinn-chat' )
				);
				echo '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * The recommendations list and the daily-update consent (ai-3).
	 *
	 * @return void
	 */
	private static function render_catalogue(): void {
		$record    = (array) ( Store::get()['catalogue'] ?? array() );
		$published = Catalogue::published();
		echo '<h2>' . esc_html__( 'Recommended models list', 'zinn-chat' ) . '</h2>';
		echo '<p>' . esc_html(
			0 === $published
				? __( 'The recommended models list could not be verified, so no recommendations are shown. Reinstall the plugin, or turn on daily updates below.', 'zinn-chat' )
				: sprintf(
					/* translators: %s: publication date. */
					__( 'The list in use was published on %s. Each plugin update brings a fresh one.', 'zinn-chat' ),
					wp_date( (string) get_option( 'date_format' ), $published )
				)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'zinn_ai_catalogue' );
		echo '<input type="hidden" name="action" value="zinn_ai_catalogue">';
		echo '<p><label><input type="checkbox" name="remote" value="1"' . checked( ! empty( $record['remote'] ), true, false ) . '> ' . esc_html__( 'Also check Zinn Digital® once a day for an updated list.', 'zinn-chat' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Only a plain download request is sent: no key, no site address, nothing about your content. The list is signed, and a list that fails the signature check is ignored.', 'zinn-chat' ) . '</p>';
		if ( isset( $record['checked_at'] ) ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: date and time, 2: result such as "updated". */
					__( 'Last checked %1$s: %2$s.', 'zinn-chat' ),
					wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), (int) $record['checked_at'] ),
					self::refresh_word( (string) ( $record['last_result'] ?? '' ) )
				)
			) . '</p>';
		}
		submit_button( __( 'Save, and check now if turned on', 'zinn-chat' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * A refresh result in words.
	 *
	 * @param string $result Result code.
	 * @return string
	 */
	private static function refresh_word( string $result ): string {
		switch ( $result ) {
			case 'updated':
				return __( 'a newer list was installed', 'zinn-chat' );
			case 'unchanged':
				return __( 'already up to date', 'zinn-chat' );
			case 'refused':
				return __( 'the download failed the signature check and was ignored', 'zinn-chat' );
			case 'failed':
				return __( 'Zinn Digital® could not be reached; the current list stays in use', 'zinn-chat' );
		}

		return __( 'turned off', 'zinn-chat' );
	}

	/**
	 * A one-button form.
	 *
	 * @param string                $action Action name without the prefix.
	 * @param array<string, string> $fields Hidden fields.
	 * @param string                $label  Button text.
	 * @return void
	 */
	private static function small_form( string $action, array $fields, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'zinn_ai_' . $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( 'zinn_ai_' . $action ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		submit_button( $label, 'small', '', false );
		echo '</form> ';
	}

	/**
	 * Show and clear the last action's notice.
	 *
	 * @return void
	 */
	private static function print_notice(): void {
		$key    = 'zinn_ai_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$class = ! empty( $notice['ok'] ) ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) );
		if ( '' !== (string) ( $notice['link'] ?? '' ) ) {
			echo ' <a href="' . esc_url( (string) $notice['link'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) ( $notice['link_text'] ?? __( 'Open', 'zinn-chat' ) ) ) . '</a>';
		}
		if ( '' !== (string) ( $notice['detail'] ?? '' ) ) {
			echo '<br><code>' . esc_html( (string) $notice['detail'] ) . '</code>';
		}
		echo '</p></div>';
	}

	/**
	 * Remember a notice and go back to the screen.
	 *
	 * @param bool         $ok      Whether it worked.
	 * @param string       $message Sentence.
	 * @param Failure|null $failure A failure to explain, if any.
	 * @param string       $query   Extra query string for the redirect (wizard step).
	 * @return void
	 */
	private static function done( bool $ok, string $message, ?Failure $failure = null, string $query = '' ): void {
		$notice = array(
			'ok'      => $ok,
			'message' => $message,
		);
		if ( null !== $failure ) {
			$notice['message']   = $failure->message;
			$notice['link']      = $failure->link;
			$notice['link_text'] = self::link_text( $failure->kind );
			$notice['detail']    = $failure->detail;
		}
		set_transient( 'zinn_ai_notice_' . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( Core::settings_url() . $query );
		exit;
	}

	/**
	 * The words on a failure's link.
	 *
	 * @param string $kind Failure class.
	 * @return string
	 */
	private static function link_text( string $kind ): string {
		switch ( $kind ) {
			case Failure::OUTAGE:
			case Failure::UNREACHABLE:
				return __( 'Check their status page', 'zinn-chat' );
			case Failure::BILLING:
			case Failure::RATE_LIMITED:
				return __( 'Open your billing page', 'zinn-chat' );
			case Failure::INVALID_KEY:
				return __( 'Open your API keys page', 'zinn-chat' );
		}

		return __( 'Open the AI settings', 'zinn-chat' );
	}

	/**
	 * Common guard for every handler.
	 *
	 * @param string $action Action name without the prefix.
	 * @return void
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'zinn-chat' ), 403 );
		}
		check_admin_referer( 'zinn_ai_' . $action );
	}

	/**
	 * A posted provider id, or '' when unknown.
	 *
	 * @return string
	 */
	private static function posted_provider(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller ran guard() first.
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

		return Registry::exists( $provider ) ? $provider : '';
	}

	/**
	 * Save a key (and a custom address), then test it.
	 *
	 * @return void
	 */
	public static function handle_save_key(): void {
		self::guard( 'save_key' );
		$provider = self::posted_provider();
		if ( '' === $provider ) {
			self::done( false, __( 'Unknown provider.', 'zinn-chat' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		// A key is an opaque token: trim it, never "sanitise" characters out of it.
		$key = isset( $_POST['key'] ) ? trim( (string) wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above; stored sealed, never output.
		if ( Registry::CUSTOM === $provider ) {
			$base = isset( $_POST['base_url'] ) ? trim( esc_url_raw( wp_unslash( $_POST['base_url'] ), array( 'http', 'https' ) ) ) : '';
			if ( '' === $base ) {
				self::done( false, __( 'Enter the address of your service, for example http://localhost:11434/v1.', 'zinn-chat' ), null, '&provider=' . $provider );
			}
			Store::set_provider( $provider, array( 'base_url' => untrailingslashit( $base ) ) );
		}
		// phpcs:enable
		if ( '' !== $key ) {
			if ( 1 === preg_match( '/\s/', $key ) || strlen( $key ) > 512 ) {
				self::done( false, __( 'That does not look like an API key. Copy it again, without spaces.', 'zinn-chat' ), null, '&provider=' . $provider );
			}
			Store::set_key( $provider, $key );
		} elseif ( Registry::CUSTOM !== $provider && 'ok' !== Store::key_state( $provider ) ) {
			self::done( false, __( 'Paste your API key first.', 'zinn-chat' ), null, '&provider=' . $provider );
		}
		delete_transient( Models::transient( $provider ) );
		self::run_test( $provider );
	}

	/**
	 * Test a saved provider.
	 *
	 * @return void
	 */
	public static function handle_test(): void {
		self::guard( 'test' );
		$provider = self::posted_provider();
		if ( '' === $provider ) {
			self::done( false, __( 'Unknown provider.', 'zinn-chat' ) );
		}
		self::run_test( $provider );
	}

	/**
	 * Test, record, and report.
	 *
	 * @param string $provider Provider id.
	 * @return void
	 */
	private static function run_test( string $provider ): void {
		$outcome = Tester::run( $provider );
		Store::set_provider(
			$provider,
			array(
				'test' => array(
					'ok'      => $outcome['ok'],
					'at'      => time(),
					'message' => null === $outcome['failure'] ? '' : $outcome['failure']->message,
				),
			)
		);
		if ( $outcome['ok'] ) {
			self::done( true, $outcome['message'] );
		}
		self::done( false, '', $outcome['failure'] );
	}

	/**
	 * Remove a key.
	 *
	 * @return void
	 */
	public static function handle_remove_key(): void {
		self::guard( 'remove_key' );
		$provider = self::posted_provider();
		if ( '' !== $provider ) {
			Store::set_provider(
				$provider,
				array(
					'key'      => null,
					'hint'     => null,
					'test'     => null,
					'base_url' => null,
				)
			);
			delete_transient( Models::transient( $provider ) );
		}
		self::done( true, __( 'Key removed.', 'zinn-chat' ) );
	}

	/**
	 * Save the default model per task.
	 *
	 * @return void
	 */
	public static function handle_defaults(): void {
		self::guard( 'defaults' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() verified; each value is split and validated below.
		$posted   = isset( $_POST['defaults'] ) && is_array( $_POST['defaults'] ) ? wp_unslash( $_POST['defaults'] ) : array();
		$defaults = array();
		foreach ( Store::TASKS as $task ) {
			$value = isset( $posted[ $task ] ) ? sanitize_text_field( (string) $posted[ $task ] ) : '';
			$parts = explode( '|', $value, 2 );
			if ( 2 === count( $parts ) && Store::configured( $parts[0] ) && '' !== $parts[1] ) {
				$defaults[ $task ] = array(
					'provider' => $parts[0],
					'model'    => $parts[1],
				);
			}
		}
		$image = isset( $posted['images'] ) ? explode( '|', sanitize_text_field( (string) $posted['images'] ), 2 ) : array();
		$image = 2 === count( $image ) && Store::configured( $image[0] ) && '' !== $image[1]
			? array(
				'provider' => $image[0],
				'model'    => $image[1],
			)
			: array();
		$embed = isset( $posted['embeddings'] ) ? explode( '|', sanitize_text_field( (string) $posted['embeddings'] ), 2 ) : array();
		$embed = 2 === count( $embed ) && Store::configured( $embed[0] ) && '' !== $embed[1]
			? array(
				'provider' => $embed[0],
				'model'    => $embed[1],
			)
			: array();
		Store::update(
			static function ( array $record ) use ( $defaults, $image, $embed ): array {
				$record['defaults']   = $defaults;
				$record['images']     = $image;
				$record['embeddings'] = $embed;
				return $record;
			}
		);
		self::done( true, __( 'Models saved.', 'zinn-chat' ) );
	}

	/**
	 * Add a model by name.
	 *
	 * @return void
	 */
	public static function handle_add_model(): void {
		self::guard( 'add_model' );
		$provider = self::posted_provider();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$model = isset( $_POST['model'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['model'] ) ) ) : '';
		if ( '' === $provider || '' === $model || 1 !== preg_match( '#^[A-Za-z0-9._:/@+\-]{1,200}$#', $model ) ) {
			self::done( false, __( 'Enter the model\'s exact name, for example as the provider\'s documentation writes it.', 'zinn-chat' ) );
		}
		Store::update(
			static function ( array $record ) use ( $provider, $model ): array {
				$added = is_array( $record['added_models'] ?? null ) ? $record['added_models'] : array();
				foreach ( $added as $row ) {
					if ( is_array( $row ) && ( $row['provider'] ?? '' ) === $provider && ( $row['id'] ?? '' ) === $model ) {
						return $record;
					}
				}
				$added[]                = array(
					'provider' => $provider,
					'id'       => $model,
					'label'    => $model,
				);
				$record['added_models'] = $added;

				return $record;
			}
		);
		self::done(
			true,
			sprintf(
				/* translators: %s: model name. */
				__( 'Added %s. Choose it under "Which model to use".', 'zinn-chat' ),
				$model
			)
		);
	}

	/**
	 * Remove a hand-added model.
	 *
	 * @return void
	 */
	public static function handle_remove_model(): void {
		self::guard( 'remove_model' );
		$provider = self::posted_provider();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		Store::update(
			static function ( array $record ) use ( $provider, $model ): array {
				$added                  = is_array( $record['added_models'] ?? null ) ? $record['added_models'] : array();
				$record['added_models'] = array_values(
					array_filter(
						$added,
						static fn( $row ): bool => ! ( is_array( $row ) && ( $row['provider'] ?? '' ) === $provider && ( $row['id'] ?? '' ) === $model )
					)
				);

				return $record;
			}
		);
		self::done( true, __( 'Model removed.', 'zinn-chat' ) );
	}

	/**
	 * Save the catalogue consent, and check now when it is on.
	 *
	 * @return void
	 */
	public static function handle_catalogue(): void {
		self::guard( 'catalogue' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$remote = ! empty( $_POST['remote'] );
		Store::update(
			static function ( array $record ) use ( $remote ): array {
				$row           = is_array( $record['catalogue'] ?? null ) ? $record['catalogue'] : array();
				$row['remote'] = $remote;
				if ( ! $remote ) {
					unset( $row['envelope'] );
				}
				$record['catalogue'] = $row;

				return $record;
			}
		);
		Catalogue::reset();
		Core::maintain();
		if ( ! $remote ) {
			self::done( true, __( 'Daily updates are off. The list that came with the plugin is used.', 'zinn-chat' ) );
		}
		$result = Catalogue::refresh();
		self::done(
			'refused' !== $result && 'failed' !== $result,
			sprintf(
				/* translators: %s: result of the check, such as "already up to date". */
				__( 'Checked: %s.', 'zinn-chat' ),
				self::refresh_word( $result )
			)
		);
	}

	/**
	 * Re-read every connected provider's model list.
	 *
	 * @return void
	 */
	public static function handle_refresh_models(): void {
		self::guard( 'refresh_models' );
		foreach ( array_keys( Registry::all() ) as $provider ) {
			if ( ! Store::configured( $provider ) ) {
				continue;
			}
			$models = Models::discovered( $provider, true );
			if ( $models instanceof Failure ) {
				self::done( false, '', $models );
			}
			Models::image_choices( $provider, true );
			Models::embedding_choices( $provider, true );
		}
		self::done( true, __( 'Model lists refreshed.', 'zinn-chat' ) );
	}
}
