<?php
/**
 * The assistant screen (rendered by assets/js/console.js).
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Admin;

use ZinnDigital\ZinnChat\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A mount point; the console script draws the screen from the agent REST API.
 */
final class Assistant_Page {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! Capabilities::can_answer() ) {
			return;
		}
		echo '<div class="wrap zinn-chat-admin"><h1 class="screen-reader-text">' . esc_html( __( 'Assistant', 'zinn-chat' ) ) . '</h1><div id="zinn-chat-app" data-view="assistant"><p class="zc-loading">' . esc_html__( 'Loading…', 'zinn-chat' ) . '</p><noscript>' . esc_html__( 'This screen needs JavaScript.', 'zinn-chat' ) . '</noscript></div></div>';
	}
}
