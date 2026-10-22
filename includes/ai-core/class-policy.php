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
	 * Does connecting the site to Zinn® also turn on the daily recommended-models check?
	 *
	 * ⛔ Never in the free edition. It is the build WordPress.org reviews, and its rules (Detailed
	 * Plugin Guidelines 6 and 7) allow an external call only with the user's explicit consent to
	 * THAT call. Connecting is consent to the credits service the connect screens describe, not to
	 * a separate daily download they never mention, so the free edition fetches only when the
	 * owner ticks the box (Q209). Pro answers yes: it ships through Freemius, where the owner ruled
	 * the directory's rules do not apply (2026-10-13), and the request carries nothing about the site.
	 *
	 * @return bool
	 */
	public function fetches_when_connected(): bool {
		return false;
	}

	/**
	 * Remove Pro data when the last plugin carrying the core is uninstalled.
	 *
	 * @return void
	 */
	public function uninstall(): void {
	}
}
