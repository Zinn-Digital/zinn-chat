<?php
/**
 * The one settings record every copy of the AI core shares.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-store.php by wp/bin/build-ai-core.php.
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
 * Shared store: keys, default models, added models, catalogue consent, Pro limits.
 *
 * ⛔⛔ ONE OPTION FOR EVERY PLUGIN THAT CARRIES THIS CORE, AND ITS NAME NEVER CHANGES. That is
 * the whole of feature ai-10 ("one key, both plugins"): Tranzly and Page Builder Sandwich each
 * ship their own prefixed copy of this code, possibly at different versions, and they agree by
 * reading and writing the same record.
 *
 * ⛔⛔ AND EVERY WRITE MERGES — IT NEVER REPLACES THE RECORD WITH WHAT THIS COPY KNOWS ABOUT.
 * An older copy saving a key must not erase a field a newer copy added. `update()` hands the
 * mutator the full stored array, unknown fields included, so a copy only ever changes the
 * fields it touches. `SCHEMA` records the newest version that has written here; an older copy
 * reads a newer record fine and simply ignores what it does not know.
 */
final class Store {

	/** The option. Shared by every copy; see the class comment before changing it. */
	public const OPTION = 'zinn_ai_core';

	/** The record layout this version writes. */
	public const SCHEMA = 1;

	/** Tasks a default model can be chosen for. Keep in step with the catalogue's `tasks`. */
	public const TASKS = array( 'general', 'write', 'translate', 'code' );

	/**
	 * The stored record.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$value = get_option( self::OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Change the record.
	 *
	 * @param callable $mutator Receives the FULL record and returns it changed.
	 * @return void
	 */
	public static function update( callable $mutator ): void {
		$before = self::get();
		$after  = $mutator( $before );
		// Never lower the recorded schema: a newer copy's marker stays when an older one writes.
		$after['schema'] = max( (int) ( $before['schema'] ?? 0 ), self::SCHEMA );
		if ( $after !== $before ) {
			update_option( self::OPTION, $after, false );
		}
	}

	/**
	 * A provider's saved settings (sealed key, hint, base URL, last test).
	 *
	 * @param string $provider Provider id.
	 * @return array<string, mixed>
	 */
	public static function provider( string $provider ): array {
		$providers = (array) ( self::get()['providers'] ?? array() );

		return is_array( $providers[ $provider ] ?? null ) ? $providers[ $provider ] : array();
	}

	/**
	 * Save part of a provider's settings (merged into what is there).
	 *
	 * @param string               $provider Provider id.
	 * @param array<string, mixed> $fields   Fields to set; a null value removes the field.
	 * @return void
	 */
	public static function set_provider( string $provider, array $fields ): void {
		self::update(
			static function ( array $record ) use ( $provider, $fields ): array {
				$providers = is_array( $record['providers'] ?? null ) ? $record['providers'] : array();
				$row       = is_array( $providers[ $provider ] ?? null ) ? $providers[ $provider ] : array();
				foreach ( $fields as $name => $value ) {
					if ( null === $value ) {
						unset( $row[ $name ] );
					} else {
						$row[ $name ] = $value;
					}
				}
				if ( array() === $row ) {
					unset( $providers[ $provider ] );
				} else {
					$providers[ $provider ] = $row;
				}
				$record['providers'] = $providers;

				return $record;
			}
		);
	}

	/**
	 * Save a key, sealed.
	 *
	 * @param string $provider Provider id.
	 * @param string $plain    Plain key.
	 * @return void
	 */
	public static function set_key( string $provider, string $plain ): void {
		self::set_provider(
			$provider,
			array(
				'key'  => Crypto::seal( $plain ),
				'hint' => Crypto::hint( $plain ),
			)
		);
	}

	/**
	 * The usable key for a provider.
	 *
	 * @param string $provider Provider id.
	 * @return string|null Null when none is saved or it cannot be opened (see key_state()).
	 */
	public static function key( string $provider ): ?string {
		$sealed = self::provider( $provider )['key'] ?? null;

		return is_string( $sealed ) ? Crypto::open( $sealed ) : null;
	}

	/**
	 * What state is a provider's key in?
	 *
	 * @param string $provider Provider id.
	 * @return string `none`, `ok`, `newer` (written by a newer plugin version) or `unreadable` (the site's salts changed).
	 */
	public static function key_state( string $provider ): string {
		$sealed = self::provider( $provider )['key'] ?? null;
		if ( ! is_string( $sealed ) || '' === $sealed ) {
			return 'none';
		}
		if ( ! Crypto::understood( $sealed ) ) {
			return 'newer';
		}

		return null === Crypto::open( $sealed ) ? 'unreadable' : 'ok';
	}

	/**
	 * Is a provider ready to use?
	 *
	 * @param string $provider Provider id.
	 * @return bool
	 */
	public static function configured( string $provider ): bool {
		$spec = Registry::get( $provider );
		if ( array() === $spec ) {
			return false;
		}
		if ( Registry::CUSTOM === $provider ) {
			return '' !== (string) ( self::provider( $provider )['base_url'] ?? '' );
		}

		return 'ok' === self::key_state( $provider ) || ! empty( $spec['key_optional'] );
	}

	/**
	 * The site owner's choice of provider and model for images (1.1.0); empty = automatic.
	 *
	 * @return array{provider: string, model: string}
	 */
	public static function image_default(): array {
		$row = (array) ( self::get()['images'] ?? array() );

		return array(
			'provider' => (string) ( $row['provider'] ?? '' ),
			'model'    => (string) ( $row['model'] ?? '' ),
		);
	}

	/**
	 * The site owner's choice of provider and model for embeddings / site search (1.2.0); empty = automatic.
	 *
	 * @return array{provider: string, model: string}
	 */
	public static function embed_default(): array {
		$row = (array) ( self::get()['embeddings'] ?? array() );

		return array(
			'provider' => (string) ( $row['provider'] ?? '' ),
			'model'    => (string) ( $row['model'] ?? '' ),
		);
	}

	/**
	 * The default choice for a task: provider and model.
	 *
	 * @param string $task Task id.
	 * @return array{provider: string, model: string}
	 */
	public static function default_for( string $task ): array {
		$defaults = (array) ( self::get()['defaults'] ?? array() );
		$row      = $defaults[ $task ] ?? $defaults['general'] ?? array();

		return array(
			'provider' => is_array( $row ) ? (string) ( $row['provider'] ?? '' ) : '',
			'model'    => is_array( $row ) ? (string) ( $row['model'] ?? '' ) : '',
		);
	}

	/**
	 * Models the site owner added by hand (feature ai-4), for one provider or all.
	 *
	 * @param string $provider Provider id, or '' for all.
	 * @return array<int, array{provider: string, id: string, label: string}>
	 */
	public static function added_models( string $provider = '' ): array {
		$out = array();
		foreach ( (array) ( self::get()['added_models'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['provider'], $row['id'] ) ) {
				continue;
			}
			if ( '' !== $provider && $row['provider'] !== $provider ) {
				continue;
			}
			$out[] = array(
				'provider' => (string) $row['provider'],
				'id'       => (string) $row['id'],
				'label'    => (string) ( $row['label'] ?? $row['id'] ),
			);
		}

		return $out;
	}

	/**
	 * Record a plugin that carries this core, so uninstall knows when the last one has gone.
	 *
	 * @param array<int, string> $slugs Host plugin slugs present now.
	 * @return void
	 */
	public static function remember_hosts( array $slugs ): void {
		$known = array_map( 'strval', (array) ( self::get()['hosts'] ?? array() ) );
		$all   = array_values( array_unique( array_merge( $known, $slugs ) ) );
		sort( $all );
		if ( $all === $known ) {
			return;
		}
		self::update(
			static function ( array $record ) use ( $all ): array {
				$record['hosts'] = $all;
				return $record;
			}
		);
	}

	/**
	 * Forget a host; report whether any remain.
	 *
	 * @param string $slug Host plugin slug.
	 * @return bool True when other hosts still use the store.
	 */
	public static function forget_host( string $slug ): bool {
		$remaining = array_values( array_diff( array_map( 'strval', (array) ( self::get()['hosts'] ?? array() ) ), array( $slug ) ) );
		self::update(
			static function ( array $record ) use ( $remaining ): array {
				$record['hosts'] = $remaining;
				return $record;
			}
		);

		return array() !== $remaining;
	}
}
