<?php
/**
 * Uninstall — leave nothing behind.
 *
 * ⛔ `WP_UNINSTALL_PLUGIN` is checked FIRST and unconditionally. Without it this file is
 * directly requestable on a badly configured server, and it deletes data.
 *
 * @package ZinnChat
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-zinn-chat-settings.php';
require_once __DIR__ . '/includes/class-zinn-chat-sync.php';

delete_option( Zinn_Chat_Settings::OPTION );

// ⭐ The daily config refresh is a scheduled event and lives in `wp_options` too. A
// cron entry pointing at a hook whose plugin has been deleted fires for ever, does
// nothing, and is invisible on every screen a site owner ever opens.
Zinn_Chat_Sync::unschedule();
