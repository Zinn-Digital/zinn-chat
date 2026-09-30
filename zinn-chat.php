<?php

/**
 * Plugin Name:       Zinn® Chat
 * Plugin URI:        https://zinndigital.com/wordpress-plugins/zinn-chat
 * Description:       A complete help desk and AI assistant that runs on your own WordPress: an AI that answers from your own pages with links, live chat, and support tickets in wp-admin, on a submit-a-ticket page and in the WooCommerce account area. Under 10 KB on the page and no requests at all until a visitor opens it.
 * Version:           2.7.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Neil Lock — CEO, Zinn Digital® Ltd
 * Author URI:        https://zinndigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zinn-chat
 * Domain Path:       /languages
 *
 * @package ZinnDigital\ZinnChat
 *
 * ⛔⛔ THIS FILE IS WRITTEN IN THE LICENSING SERVICE'S OWN PRINT, NOT IN WPCS STYLE. Freemius
 * re-prints the file that calls fs_dynamic_init() (four-space indent, no blank lines between
 * statements, `!$x`); every other file reaches its free package byte-for-byte (docs/adr/0031).
 * Keep it to headers, constants, the SDK init and one require; everything else lives in includes/.
 *
 * ⛔ No `Update URI` header and no secret key in this SOURCE. Freemius adds the header to the
 * premium download only. The SDK needs only the PUBLIC key below.
 *
 * ⭐ `has_paid_plans` is true since 2.7.0, the release that ships Zinn® Chat Pro (lane CHAT-PRO,
 * together with `Admin\Upsell::LIVE`): the SDK adds the Upgrade menu and the trial offer.
 *
 * ⛔⛔ ACTIVE ONLY WHEN THE SITE OWNER SWITCHES IT ON. It is preinstalled on every site Zinn hosts,
 * so it does NOTHING on the front end until the owner turns the chat on: no script, no markup, no
 * request, no cookie. A chat window that talks to somebody's visitors unasked is a consent problem.
 */
defined( 'ABSPATH' ) || exit;
if ( function_exists( 'zinn_chat_fs' ) ) {
    zinn_chat_fs()->set_basename( false, __FILE__ );
    return;
}
define( 'ZINN_CHAT_VERSION', '2.7.1' );
define( 'ZINN_CHAT_FILE', __FILE__ );
define( 'ZINN_CHAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZINN_CHAT_URL', plugin_dir_url( __FILE__ ) );
if ( !function_exists( 'zinn_chat_fs' ) ) {
    /**
     * The licensing SDK instance for this plugin (Freemius product 40436).
     *
     * @return Freemius
     */
    function zinn_chat_fs() {
        global $zinn_chat_fs;
        if ( !isset( $zinn_chat_fs ) ) {
            require_once __DIR__ . '/vendor/freemius/start.php';
            $zinn_chat_fs = fs_dynamic_init( array(
                'id'               => '40436',
                'slug'             => 'zinn-chat',
                'premium_slug'     => 'zinn-chat-premium',
                'type'             => 'plugin',
                'public_key'       => 'pk_a4fcbff8e8c9d1c7c756694c0311e',
                'is_premium'       => false,
                'premium_suffix'   => 'Pro',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'trial'            => array(
                    'days'               => 14,
                    'is_require_payment' => false,
                ),
                'is_org_compliant' => true,
                'menu'             => array(
                    'slug'       => 'zinn-chat',
                    'first-path' => 'admin.php?page=zinn-chat-setup',
                    'contact'    => false,
                    'support'    => false,
                ),
                'anonymous_mode'   => defined( 'ZINN_SITE_EVENTS_URL' ),
                'is_live'          => true,
            ) );
        }
        return $zinn_chat_fs;
    }

    zinn_chat_fs();
    zinn_chat_fs()->add_action( 'after_uninstall', 'zinn_chat_uninstall' );
    // The SDK's screens show THIS icon (the WordPress.org one, wp/dotorg-assets/zinn-chat). Without
    // a local icon the SDK downloads one from the licensing service on a non-hosted install, before
    // any consent (wp/tests/e2e/zinn-chat/no-http-before-consent.sh).
    zinn_chat_fs()->add_filter( 'plugin_icon', static fn() => __DIR__ . '/assets/icon-256x256.png' );
    do_action( 'zinn_chat_fs_loaded' );
}
require_once __DIR__ . '/includes/bootstrap.php';