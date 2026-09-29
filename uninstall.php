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

// ⭐ The Pro build's licence option and its own daily cron event. Written unconditionally
// rather than behind a build flag: `delete_option` on a key that was never created is a
// no-op, and one uninstall path that is correct in BOTH editions beats two that have to be
// kept in step — the second of which is always the one nobody edits.
delete_option( 'zinn_chat_licence' );
$zinn_chat_licence_cron = wp_next_scheduled( 'zinn_chat_licence_check' );
if ( $zinn_chat_licence_cron ) {
	wp_unschedule_event( $zinn_chat_licence_cron, 'zinn_chat_licence_check' );
}
