<?php
/**
 * Loads Zinn® Chat: the start-up decision (Cli_Start), the background queue, the activation hooks
 * and uninstall cleanup; then starts it. The start itself — every part of the plugin — is in
 * start.php, which Cli_Start runs at once, or in a WP-CLI command right after WordPress has loaded
 * (stage PERF3: WP-CLI loads WordPress under the 128 MB limit and lifts it only afterwards).
 *
 * ⛔ Explicit `require_once __DIR__ . '…'` lines, never a constant-built path:
 * `scripts/wp-class-loader-check.py` reads these lines statically to prove that every class the
 * plugin calls is in a file it loads, and a path built at run time is invisible to it.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-autoload.php';
\ZinnDigital\ZinnChat\Autoload::register();
require_once __DIR__ . '/class-cli-start.php';
// Action Scheduler (the background queue WooCommerce uses). Its own loader picks the newest copy
// any active plugin bundles, so ours coexists with WooCommerce's. It must load before
// `plugins_loaded`, whenever Zinn® Chat starts.
require_once dirname( __DIR__ ) . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

// ⭐ Anything that must run WHILE WordPress loads even when the start is deferred goes above
// this line; the rest is in start.php.
\ZinnDigital\ZinnChat\Cli_Start::start(
	static function (): void {
		require_once __DIR__ . '/start.php';
	}
);

register_activation_hook( ZINN_CHAT_FILE, array( '\ZinnDigital\ZinnChat\Plugin', 'activate' ) );
register_deactivation_hook( ZINN_CHAT_FILE, array( '\ZinnDigital\ZinnChat\Plugin', 'deactivate' ) );

if ( ! function_exists( 'zinn_chat_uninstall' ) ) {
	/**
	 * Delete everything this plugin stored, when the owner deletes it and chose to remove data.
	 * Called by the licensing SDK's `after_uninstall` action (it refuses a root uninstall.php).
	 *
	 * @return void
	 */
	function zinn_chat_uninstall(): void {
		\ZinnDigital\ZinnChat\Plugin::uninstall();
	}
}
