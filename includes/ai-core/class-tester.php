<?php
/**
 * The "Save and test" check.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-tester.php by wp/bin/build-ai-core.php.
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
 * Proves a provider works end to end: the key reads the model list AND one tiny generation
 * succeeds.
 *
 * ⭐ Both halves, because they fail differently. Reading the model list is free and proves the
 * key is valid, but a key with no credit reads its model list perfectly well — the billing
 * problem only appears on a generation. A test that stopped at the list would tell a customer
 * "connected" and let their first real use fail.
 */
final class Tester {

	/**
	 * Run the test.
	 *
	 * @param string $provider Provider id.
	 * @return array{ok: bool, message: string, failure: Failure|null, model: string}
	 */
	public static function run( string $provider ): array {
		$label   = (string) ( Registry::get( $provider )['label'] ?? $provider );
		$adapter = Registry::adapter( $provider, Store::provider( $provider ) );
		if ( null === $adapter || ! Store::configured( $provider ) ) {
			return self::failed( Failure::refused( __( 'Save a key for this provider first.', 'zinn-chat' ) ) );
		}

		$models = Models::discovered( $provider, true );
		if ( $models instanceof Failure ) {
			return self::failed( $models );
		}

		$candidates = self::candidates( $provider, $models );
		if ( array() === $candidates ) {
			return self::failed( Failure::refused( __( 'Your key works, but the provider lists no text model for it. Add a model yourself below.', 'zinn-chat' ) ) );
		}

		// ⭐ A model the account's PLAN does not include fails as "model unavailable" (Mistral,
		// measured 2026-09-26: a funded account could use Ministral but not Small or Medium). That
		// says nothing about the key, so the test moves on to the next model the key can see
		// rather than reporting a working account as broken. Any other failure stops at once.
		$refused = false;
		$result  = null;
		$model   = '';
		foreach ( array_slice( $candidates, 0, 4 ) as $model ) {
			$result = $adapter->generate(
				(string) Store::key( $provider ),
				$model,
				array(
					array(
						'role'    => 'user',
						'content' => 'Reply with the single word OK.',
					),
				),
				array( 'max_tokens' => 512 )
			);
			if ( $result->ok() || null === $result->failure || Failure::MODEL !== $result->failure->kind ) {
				break;
			}
			$refused = true;
		}
		if ( null !== $result && ! $result->ok() && null !== $result->failure ) {
			return self::failed( $result->failure, $model );
		}

		self::adopt( $provider, $refused ? $model : '' );

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: AI provider name, 2: model name. */
				__( 'Connected. %1$s answered using %2$s. Your AI features are ready to use.', 'zinn-chat' ),
				$label,
				$model
			),
			'failure' => null,
			'model'   => $model,
		);
	}

	/**
	 * The models to test with, in order: the site's own choice, then the recommendations the key
	 * can see (cheapest first), then models added by hand, then anything the key can see.
	 *
	 * @param string                           $provider Provider id.
	 * @param array<int, array<string, mixed>> $models   Live list.
	 * @return array<int, string>
	 */
	private static function candidates( string $provider, array $models ): array {
		$live = array_map( static fn( array $row ): string => (string) $row['id'], $models );
		$out  = array();

		$current = Store::default_for( 'general' );
		if ( $current['provider'] === $provider && '' !== $current['model'] ) {
			$out[] = $current['model'];
		}
		$recommended = Catalogue::recommended( $provider, 'general' );
		foreach ( array( 'cheap', 'fast', 'best' ) as $tier ) {
			$id = $recommended[ $tier ] ?? '';
			// A recommendation the key cannot see would fail as "model unavailable" and mislead;
			// an empty live list (a server that hides it) trusts the recommendation.
			if ( '' !== $id && ( array() === $live || in_array( $id, $live, true ) ) ) {
				$out[] = $id;
			}
		}
		foreach ( Catalogue::models( $provider ) as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' !== $id && in_array( $id, $live, true ) ) {
				$out[] = $id;
			}
		}
		foreach ( Store::added_models( $provider ) as $row ) {
			$out[] = $row['id'];
		}
		if ( isset( $live[0] ) ) {
			$out[] = $live[0];
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * When this is the site's first working provider, make it the default for every task,
	 * using the catalogue's best model for each.
	 *
	 * ⭐ When the test had to skip models the account's plan excludes, every task gets the model
	 * that was PROVEN to answer instead: a default that is known to fail is worse than a cheaper
	 * one that works, and the site owner can pick a ★ model their plan includes afterwards.
	 *
	 * @param string $provider Provider id.
	 * @param string $proven   The model that answered, when a plan refusal was met on the way.
	 * @return void
	 */
	private static function adopt( string $provider, string $proven = '' ): void {
		$current = Store::default_for( 'general' );
		if ( '' !== $current['provider'] && Store::configured( $current['provider'] ) ) {
			return;
		}
		$defaults = array();
		foreach ( Store::TASKS as $task ) {
			$defaults[ $task ] = array(
				'provider' => $provider,
				'model'    => '' !== $proven ? $proven : (string) ( Catalogue::recommended( $provider, $task )['best'] ?? '' ),
			);
		}
		Store::update(
			static function ( array $record ) use ( $defaults ): array {
				$record['defaults'] = $defaults;
				return $record;
			}
		);
	}

	/**
	 * A failed outcome.
	 *
	 * @param Failure $failure Why.
	 * @param string  $model   The model tried.
	 * @return array{ok: bool, message: string, failure: Failure|null, model: string}
	 */
	private static function failed( Failure $failure, string $model = '' ): array {
		return array(
			'ok'      => false,
			'message' => $failure->message,
			'failure' => $failure,
			'model'   => $model,
		);
	}
}
