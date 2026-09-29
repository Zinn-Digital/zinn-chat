<?php
/**
 * Who may use AI, and what gets recorded.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-policy.php by wp/bin/build-ai-core.php.
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
 * The policy every generation passes through.
 *
 * In the free edition it allows every request and records nothing. The Pro edition replaces it
 * with one that enforces per-role permissions and monthly limits and writes the usage log
 * (features ai-8, ai-9); that code ships only in the Pro package.
 */
class Policy {

	/**
	 * May this request run?
	 *
	 * @param array<string, mixed> $context `task`, `purpose`, `provider`, `model`, `user`.
	 * @return Failure|null A refusal, or null to allow.
	 */
	public function check( array $context ): ?Failure {
		unset( $context );
		return null;
	}

	/**
	 * Record a finished request.
	 *
	 * @param array<string, mixed> $context See check().
	 * @param Result               $result  What happened.
	 * @return void
	 */
	public function record( array $context, Result $result ): void {
		unset( $context, $result );
	}

	/**
	 * Print the Pro sections of the settings screen. Nothing in the free edition.
	 *
	 * @return void
	 */
	public function render_settings(): void {
	}

	/**
	 * Hook Pro-only handlers. Nothing in the free edition.
	 *
	 * @return void
	 */
	public function register(): void {
	}

	/**
	 * Remove Pro data when the last plugin carrying the core is uninstalled.
	 *
	 * @return void
	 */
	public function uninstall(): void {
	}
}
