<?php
/**
 * The Pro card in the free edition.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Admin;

use ZinnDigital\ZinnChat\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One card on the Setup screen, never a nag: what Pro adds, the price, and the free trial.
 *
 * ⛔ PRICES are whole US dollars and MUST equal wp/freemius-plans.json (the owner-approved
 * source); ZinnChatUpsellTest fails when they drift. Checkout and trial links come from the
 * licensing SDK, so the plan ids are never typed here.
 */
final class Upsell {

	/**
	 * ⭐ True since 2.7.0, the release that ships Zinn® Chat Pro on Freemius (lane CHAT-PRO). While
	 * it was false the card stayed hidden: selling features a customer could not yet receive is a
	 * placeholder claim (CLAUDE.md §2.41).
	 */
	public const LIVE = true;

	/** Monthly and annual price per plan, in US dollars (see class comment). */
	public const PRICES = array(
		'personal' => array( 19, 189, 1 ),
		'business' => array( 39, 389, 5 ),
		'agency'   => array( 89, 889, 0 ),
	);

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'zinn_chat_setup_after', array( self::class, 'card' ) );
	}

	/**
	 * The card (free edition only).
	 *
	 * @return void
	 */
	public static function card(): void {
		if ( ! self::LIVE || Plugin::is_pro() || ! function_exists( 'zinn_chat_fs' ) ) {
			return;
		}
		$fs       = zinn_chat_fs();
		$features = array(
			__( 'Unlimited agents, with a Support agent role that sees only chats and tickets', 'zinn-chat' ),
			__( 'Reply to tickets by email', 'zinn-chat' ),
			__( 'Knowledge base: articles, categories, search, and a KB section in the chat', 'zinn-chat' ),
			__( 'Saved replies, business hours, priorities and SLAs', 'zinn-chat' ),
			__( 'Suggested replies, thread summaries and auto-tagging by AI', 'zinn-chat' ),
			__( 'Knowledge-gap report: the questions your site could not answer', 'zinn-chat' ),
			__( 'Push notifications and an installable agent app for your phone', 'zinn-chat' ),
			__( 'Proactive messages, reports, webhooks, Slack and Telegram alerts', 'zinn-chat' ),
		);
		echo '<div class="zc-pro-card"><h2>' . esc_html__( 'Zinn® Chat Pro', 'zinn-chat' ) . '</h2><ul>';
		foreach ( $features as $feature ) {
			echo '<li>' . esc_html( $feature ) . '</li>';
		}
		echo '</ul><p>';
		/* translators: 1: monthly price in US dollars, 2: yearly price in US dollars. */
		echo esc_html( sprintf( __( 'From $%1$d a month or $%2$d a year for one site.', 'zinn-chat' ), self::PRICES['personal'][0], self::PRICES['personal'][1] ) );
		echo '</p><p><a class="button button-primary" href="' . esc_url( $fs->get_trial_url() ) . '">' . esc_html__( 'Try Pro free for 14 days', 'zinn-chat' ) . '</a> <a class="button" href="' . esc_url( $fs->get_upgrade_url() ) . '">' . esc_html__( 'See plans', 'zinn-chat' ) . '</a></p><p class="description">' . esc_html__( 'No card needed for the trial.', 'zinn-chat' ) . '</p></div>';
	}
}
