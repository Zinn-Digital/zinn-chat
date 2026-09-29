<?php
/**
 * Boots the AI core inside its host plugin and agrees with any other copy on the site.
 *
 * Generated from wp/packages/zinn-ai-core/src/class-core.php by wp/bin/build-ai-core.php.
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
 * The AI core's entry point.
 *
 * ⛔⛔ SEVERAL COPIES OF THIS CLASS CAN BE LOADED AT ONCE, AT DIFFERENT VERSIONS, AND THAT IS
 * THE DESIGN. Each plugin ships its own copy under its own namespace (`ZinnDigital\Tranzly\
 * AiCore`, `ZinnDigital\PBS\AiCore`, rendered by `wp/bin/build-ai-core.php`), so two copies can
 * never collide on a class name — that is what makes "both plugins active with different core
 * versions" safe by construction instead of by luck.
 *
 * ⭐ The copies agree WITHOUT a single global function, hook or variable of their own: each one
 * reads the classes PHP has declared, finds every `…\AiCore\Core` of this FAMILY, and ranks
 * them the same way (Pro usable first, then the newest version, then the class name). Exactly
 * one copy is elected, and only it draws the settings screen and runs the daily catalogue check.
 * Every copy reads and writes the same shared record (`Store`), so they share one set of keys.
 */
final class Core {

	/** The core's version. Bump on every change: the newest copy on a site wins the election. */
	public const VERSION = '1.2.0';

	/** Marks the classes of this family, so the election never mistakes another plugin's class. */
	public const FAMILY = 'zinn-ai-core';

	/** The settings screen's slug, the same in every copy. */
	public const PAGE = 'zinn-ai';

	/** Daily catalogue check, run by the elected copy only. */
	public const CRON = 'zinn_ai_core_catalogue_refresh';

	/**
	 * The host plugin: `slug`, `name`, `pro` (bool).
	 *
	 * @var array{slug: string, name: string, pro: bool}|null
	 */
	private static ?array $host = null;

	/**
	 * The policy in force for this copy.
	 *
	 * @var Policy|null
	 */
	private static ?Policy $policy = null;

	/**
	 * Cached election outcome for this request.
	 *
	 * @var bool|null
	 */
	private static ?bool $elected = null;

	/**
	 * Start the core. Called once by the host plugin's bootstrap.
	 *
	 * @param array{slug: string, name: string, pro?: callable(): bool, messages?: callable} $host The host plugin. `messages`, optional:
	 *        `fn( array $messages, array $context ): array`, called on every request THIS copy sends
	 *        (the host's own hook point — each host names its own, prefixed, filter).
	 * @return void
	 */
	public static function boot( array $host ): void {
		$pro        = isset( $host['pro'] ) && is_callable( $host['pro'] ) && (bool) ( $host['pro'] )();
		$pro_entry  = __DIR__ . '/pro_' . '_premium_only/class-pro-policy.php'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- the premium-only token must not appear in a free file.
		$pro_loaded = false;
		if ( $pro && is_readable( $pro_entry ) ) {
			require_once $pro_entry;
			$class = __NAMESPACE__ . '\\Pro\\Pro_Policy';
			if ( class_exists( $class ) ) {
				self::$policy = new $class();
				$pro_loaded   = true;
			}
		}
		self::$host = array(
			'slug'     => (string) $host['slug'],
			'name'     => (string) $host['name'],
			'pro'      => $pro_loaded,
			'messages' => isset( $host['messages'] ) && is_callable( $host['messages'] ) ? $host['messages'] : null,
		);

		add_action( 'plugins_loaded', array( self::class, 'wire' ), 20 );
	}

	/**
	 * Hook the elected copy's screen and schedule. Runs once every plugin is loaded, so every
	 * copy on the site is visible to the election.
	 *
	 * @return void
	 */
	public static function wire(): void {
		if ( ! self::elected() ) {
			return;
		}
		Admin::register();
		self::policy()->register();
		add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );
		add_action( self::CRON, array( Catalogue::class, 'refresh' ) );
		add_action( 'admin_init', array( self::class, 'maintain' ) );
	}

	/**
	 * Keep the host list and the daily schedule right. Admin requests only, and it writes only
	 * when something changed.
	 *
	 * @return void
	 */
	public static function maintain(): void {
		Store::remember_hosts( array_map( static fn( array $copy ): string => $copy['host'], self::copies() ) );

		$wants = ! empty( ( (array) ( Store::get()['catalogue'] ?? array() ) )['remote'] );
		$next  = wp_next_scheduled( self::CRON );
		if ( $wants && false === $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		} elseif ( ! $wants && false !== $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * This copy, described for the election.
	 *
	 * @return array{family: string, version: string, host: string, name: string, pro: bool, class: string}|null Null before the host booted it.
	 */
	public static function descriptor(): ?array {
		if ( null === self::$host ) {
			return null;
		}

		return array(
			'family'  => self::FAMILY,
			'version' => self::VERSION,
			'host'    => self::$host['slug'],
			'name'    => self::$host['name'],
			'pro'     => self::$host['pro'],
			'class'   => self::class,
		);
	}

	/**
	 * Every booted copy on the site, in election order (the winner first).
	 *
	 * @return array<int, array{family: string, version: string, host: string, name: string, pro: bool, class: string}>
	 */
	public static function copies(): array {
		$copies = array();
		foreach ( get_declared_classes() as $class ) {
			if ( ! str_ends_with( $class, '\\AiCore\\Core' ) || ! defined( $class . '::FAMILY' ) || self::FAMILY !== constant( $class . '::FAMILY' ) || ! is_callable( array( $class, 'descriptor' ) ) ) {
				continue;
			}
			$descriptor = call_user_func( array( $class, 'descriptor' ) );
			if ( is_array( $descriptor ) && isset( $descriptor['version'], $descriptor['host'], $descriptor['class'] ) ) {
				$copies[] = $descriptor;
			}
		}
		usort( $copies, array( self::class, 'rank' ) );

		return $copies;
	}

	/**
	 * Election order: Pro usable first, then the newest version, then the class name.
	 *
	 * ⭐ Pro first because the Pro screen is a superset of the free one: electing a free copy
	 * would hide the usage log from a customer who paid for it, just because the free plugin
	 * beside it happened to be a patch release newer.
	 *
	 * @param array<string, mixed> $a A copy.
	 * @param array<string, mixed> $b Another copy.
	 * @return int
	 */
	public static function rank( array $a, array $b ): int {
		if ( (bool) $a['pro'] !== (bool) $b['pro'] ) {
			return $a['pro'] ? -1 : 1;
		}
		$version = version_compare( (string) $b['version'], (string) $a['version'] );
		if ( 0 !== $version ) {
			return $version;
		}

		return strcmp( (string) $a['class'], (string) $b['class'] );
	}

	/**
	 * Is this copy the one that draws the screen?
	 *
	 * @return bool
	 */
	public static function elected(): bool {
		if ( null === self::$elected ) {
			$copies        = self::copies();
			self::$elected = null !== self::$host && array() !== $copies && self::class === $copies[0]['class'];
		}

		return self::$elected;
	}

	/**
	 * The policy in force for requests made through this copy.
	 *
	 * @return Policy
	 */
	public static function policy(): Policy {
		if ( null === self::$policy ) {
			self::$policy = new Policy();
		}

		return self::$policy;
	}

	/**
	 * The messages of a request, after the host's `messages` callback (when it gave one).
	 *
	 * @param array<int, array{role: string, content: string}> $messages Conversation.
	 * @param array<string, mixed>                             $context  Request context.
	 * @return array<int, array{role: string, content: string}>
	 */
	public static function messages( array $messages, array $context ): array {
		$callback = self::$host['messages'] ?? null;
		if ( ! is_callable( $callback ) ) {
			return $messages;
		}
		$out = $callback( $messages, $context );

		return is_array( $out ) ? $out : $messages;
	}

	/**
	 * The host plugin's slug.
	 *
	 * @return string
	 */
	public static function host(): string {
		return null === self::$host ? '' : self::$host['slug'];
	}

	/**
	 * The settings screen, the same URL whichever copy draws it.
	 *
	 * @return string
	 */
	public static function settings_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * Called from the host's uninstall. Removes the shared data only when no other plugin that
	 * carries the core remains installed — uninstalling Tranzly must not delete the key Page
	 * Builder Sandwich is still using.
	 *
	 * @param string $slug The host being uninstalled.
	 * @return void
	 */
	public static function uninstall( string $slug ): void {
		global $wpdb;

		if ( Store::forget_host( $slug ) ) {
			return;
		}
		self::policy()->uninstall();
		foreach ( array_keys( Registry::all() ) as $provider ) {
			delete_transient( Models::transient( $provider ) );
		}
		wp_clear_scheduled_hook( self::CRON );
		delete_option( Store::OPTION );
		// The Pro usage log, if a Pro copy ever created it. Dropped here too so that a site which
		// ends on the free edition still leaves nothing behind.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'zinn_ai_usage' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- a fixed table name on uninstall.
	}
}
