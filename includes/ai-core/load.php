<?php
/**
 * Loads the AI core. The host plugin requires this file, then calls `Core::boot()`.
 *
 * ⛔ Explicit requires, no autoloader: every copy of the core on a site lives in its own
 * namespace, and a shared autoloader is exactly the kind of global that would make two copies
 * interfere. The Pro policy is required by `Core::boot()` only when it ships AND is licensed.
 *
 * Generated from wp/packages/zinn-ai-core/src/load.php by wp/bin/build-ai-core.php.
 * Edit the package, never this copy: `--check` refuses a copy that differs.
 *
 * @package ZinnDigital\ZinnChat\AiCore
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-http.php';
require_once __DIR__ . '/class-registry.php';
require_once __DIR__ . '/class-failure.php';
require_once __DIR__ . '/class-result.php';
require_once __DIR__ . '/class-json.php';
require_once __DIR__ . '/class-provider.php';
require_once __DIR__ . '/class-openai-compatible.php';
require_once __DIR__ . '/class-anthropic.php';
require_once __DIR__ . '/class-gemini.php';
require_once __DIR__ . '/class-crypto.php';
require_once __DIR__ . '/class-store.php';
require_once __DIR__ . '/class-catalogue.php';
require_once __DIR__ . '/class-models.php';
require_once __DIR__ . '/class-policy.php';
require_once __DIR__ . '/class-client.php';
require_once __DIR__ . '/class-tester.php';
require_once __DIR__ . '/class-rest.php';
require_once __DIR__ . '/class-admin.php';
require_once __DIR__ . '/class-core.php';
