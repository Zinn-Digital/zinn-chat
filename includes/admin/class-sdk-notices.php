<?php
/**
 * The licensing SDK's promotional notices stay on Zinn Chat's own screens.
 *
 * ⛔ The SDK adds its trial offer as a STICKY admin notice of type `promotion`, and a sticky
 * notice prints on every wp-admin screen until it is dismissed: on 2026-11-05 the Zinn Chat
 * trial offer was seen inside the Zinn Migrate plugin's own page (Arabic wp-admin, a preview
 * site). A plugin's offer on another plugin's screen breaks the WordPress.org guideline on
 * admin notices and is noise for the site owner.
 *
 * So: a `promotion` notice (the trial offer, the affiliate offer) prints only on a screen whose
 * id carries one of our page slugs (Admin::screen()). The match is on the slug, which WordPress
 * appends untranslated, so it holds in every language (the `<menu title>_page_` prefix of a
 * sub-page is translated and is never matched). Every other kind of SDK notice (an activation
 * result, a licence error) is left exactly as the SDK decided.
 *
 * `show_trial` is asked at `admin_init`, before any screen exists. With no screen to judge it
 * leaves the SDK's answer alone, so the offer can still be stored and then shown on our screens;
 * the render filter is what keeps it off everyone else's.
 *
 * @package ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scopes the SDK's promotional notices to our screens.
 */
final class Sdk_Notices {

	/**
	 * Hook the SDK's notice filters.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'zinn_chat_fs' ) ) {
			return;
		}
		$fs = zinn_chat_fs();
		if ( ! is_object( $fs ) || ! method_exists( $fs, 'add_filter' ) ) {
			return;
		}
		$fs->add_filter( 'show_admin_notice', array( self::class, 'filter_sdk_notice' ), 10, 2 );
		$fs->add_filter( 'show_trial', array( self::class, 'filter_trial' ) );
		$fs->add_filter( 'show_affiliate_program_notice', array( self::class, 'filter_trial' ) );
	}

	/**
	 * The current admin screen's id, or null when there is no screen yet.
	 *
	 * @return string|null
	 */
	public static function current_id(): ?string {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}
		$screen = get_current_screen();
		return is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : null;
	}

	/**
	 * Is this screen id one of ours?
	 *
	 * @param string $id A screen id.
	 * @return bool
	 */
	public static function is_ours( string $id ): bool {
		return '' !== $id && '' !== Admin::screen( $id );
	}

	/**
	 * `show_admin_notice`: a promotion shows on our screens only; other notices are untouched.
	 *
	 * @param bool                 $show Whether the SDK would show it.
	 * @param array<string, mixed> $msg  The SDK's message, with `type`.
	 * @return bool
	 */
	public static function filter_sdk_notice( $show, $msg ): bool {
		$type = is_array( $msg ) ? (string) ( $msg['type'] ?? '' ) : '';
		if ( 'promotion' !== $type ) {
			return (bool) $show;
		}
		return (bool) $show && self::is_ours( (string) self::current_id() );
	}

	/**
	 * `show_trial` / `show_affiliate_program_notice`: no on another plugin's screen.
	 *
	 * @param bool $show The SDK's default.
	 * @return bool
	 */
	public static function filter_trial( $show ): bool {
		$id = self::current_id();
		if ( null === $id ) {
			return (bool) $show;
		}
		return (bool) $show && self::is_ours( $id );
	}
}
