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

delete_option( Zinn_Chat_Settings::OPTION );
