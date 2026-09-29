<?php
/**
 * The licence: what it is, where it is kept, and how it is checked.
 *
 * ⛔⛔ **THIS FILE IS ONLY PRESENT IN THE PRO BUILD.** `wp/bin/build-plugin.php --pro`
 * ships `includes/pro/`; the free and wordpress.org builds drop the whole directory. There
 * is still exactly ONE source tree — see that script's header for why two trees was refused.
 *
 * @package ZinnChat
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the licence key, activates this site against it, and remembers the verdict.
 *
 * ⛔⛔ **THE CACHED VERDICT IS THE WHOLE DESIGN, AND IT IS NOT A PERFORMANCE TWEAK.** A Pro
 * feature that asked our API whether it may run would put a network round trip on the
 * critical path of a page a visitor is waiting for — and would switch the customer's
 * product off the moment our API had a bad minute, on a site we may not even host. So the
 * answer is fetched ONCE A DAY on WordPress's own cron and read from an option in between.
 *
 * ⛔ Which means the failure direction is chosen deliberately: an unreachable API leaves
 * the LAST KNOWN verdict in place rather than falling back to "unlicensed". A customer who
 * has paid must not lose their product because of our outage, and the daily check bounds
 * how long a genuinely lapsed licence keeps working — on top of the server-side grace.
 */
final class Zinn_Chat_Licence {

	public const OPTION = 'zinn_chat_licence';

	/** The cron hook that re-checks the licence. */
	public const CRON = 'zinn_chat_licence_check';

	/** The PRO plugin's slug, as the engine knows it. */
	public const PRODUCT = 'zinn-chat-pro';

	/**
	 * How long a verdict is trusted without re-asking.
	 *
	 * ⭐ Daily rather than hourly: the thing being asked changes at most once a month (a
	 * renewal) and the cost of asking is a request from every Pro site on the internet. At
	 * a hypothetical 10,000 Pro sites, daily is ~0.1 requests/second and hourly is 2.8 —
	 * for an answer that has not changed.
	 */
	private const RECHECK = DAY_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'refresh' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Make sure the daily check is scheduled.
	 *
	 * ⛔ Checked rather than scheduled on activation alone: a site restored from a backup,
	 * or migrated, arrives with its options intact and its cron table empty, and a licence
	 * that then never re-checks is a licence that never notices a cancellation.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Everything we know about this site's licence.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge(
			array(
				'key'           => '',
				'entitled'      => false,
				'reason'        => '',
				'expires_at'    => '',
				'sites_allowed' => 0,
				'sites_used'    => 0,
				'seat_holders'  => array(),
				'checked_at'    => 0,
			),
			$stored
		);
	}

	/**
	 * ⛔⛔ **THE ONE PREDICATE EVERY PRO FEATURE ASKS, AND IT IS ASKED AT THE FEATURE.**
	 * Not a constant set at boot: a licence that lapses mid-request, or a key cleared on
	 * the settings screen, must take effect on the next feature call rather than on the
	 * next page load — and one predicate is one place to get it right, against one place
	 * per feature to forget it (§2.46's shape, pointed at entitlement instead of money).
	 */
	public static function is_active(): bool {
		$state = self::state();
		return '' !== (string) $state['key'] && ! empty( $state['entitled'] );
	}

	/** The site URL a seat is counted against. */
	public static function site_url(): string {
		return (string) home_url( '/' );
	}

	/**
	 * Activate this site against `$key`, and store the verdict.
	 *
	 * @param string $key Licence key as the customer pasted it.
	 * @return array<string, mixed> The new state.
	 */
	public static function activate( string $key ): array {
		return self::talk( 'activate', $key );
	}

	/**
	 * Release this site's seat and forget the key.
	 *
	 * ⭐ The local state is cleared EVEN IF the remote call fails. A customer who has
	 * decided to stop using their key here must not be stuck with it because our API was
	 * down; the seat is also releasable from their dashboard, which is what that screen's
	 * release button exists for.
	 *
	 * @param string $key Licence key.
	 * @return array<string, mixed>
	 */
	public static function deactivate( string $key ): array {
		self::post(
			'deactivate',
			array(
				'key'  => $key,
				'site' => self::site_url(),
			)
		);
		delete_option( self::OPTION );
		return self::state();
	}

	/**
	 * The daily re-check. Also what the updater calls before offering an update.
	 *
	 * @return array<string, mixed>
	 */
	public static function refresh(): array {
		$key = (string) self::state()['key'];
		if ( '' === $key ) {
			return self::state();
		}
		return self::talk( 'status', $key );
	}

	/**
	 * The update descriptor the engine offered on the last status call, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function available_update(): ?array {
		$state = self::state();
		$fresh = is_array( $state['update'] ?? null ) ? $state['update'] : null;
		if ( null === $fresh || (int) $state['checked_at'] + self::RECHECK < time() ) {
			$state = self::refresh();
			$fresh = is_array( $state['update'] ?? null ) ? $state['update'] : null;
		}
		return $fresh;
	}

	/**
	 * One round trip, and store whatever came back.
	 *
	 * @param string $action `activate` or `status`.
	 * @param string $key    Licence key.
	 * @return array<string, mixed>
	 */
	private static function talk( string $action, string $key ): array {
		$body = self::post(
			$action,
			array(
				'key'     => $key,
				'slug'    => self::PRODUCT,
				'site'    => self::site_url(),
				'version' => ZINN_CHAT_VERSION,
			)
		);

		if ( null === $body ) {
			// ⛔⛔ UNREACHABLE IS NOT UNLICENSED. Writing `entitled => false` here would
			// switch a paying customer's product off during OUR outage — §2.57's boundary:
			// a failure we cannot classify is ours, and it must not be reported as the
			// customer's problem or acted on as one.
			$state                = self::state();
			$state['key']         = '' !== (string) $state['key'] ? $state['key'] : $key;
			$state['unreachable'] = true;
			update_option( self::OPTION, $state, false );
			return $state;
		}

		$state = array(
			'key'           => $key,
			'entitled'      => ! empty( $body['entitled'] ),
			'reason'        => isset( $body['reason'] ) ? (string) $body['reason'] : '',
			'expires_at'    => isset( $body['expires_at'] ) ? (string) $body['expires_at'] : '',
			'sites_allowed' => isset( $body['sites_allowed'] ) ? (int) $body['sites_allowed'] : 0,
			'sites_used'    => isset( $body['sites_used'] ) ? (int) $body['sites_used'] : 0,
			'seat_holders'  => isset( $body['seat_holders'] ) && is_array( $body['seat_holders'] )
				? array_map( 'strval', $body['seat_holders'] )
				: array(),
			'checked_at'    => time(),
			'unreachable'   => false,
		);
		if ( isset( $body['update'] ) && is_array( $body['update'] ) ) {
			$state['update'] = $body['update'];
		}
		// ⛔ `false` — do NOT autoload. This option is read on the settings screen and by
		// cron, never on a front-end request, and an autoloaded option is loaded on every
		// single page view of the site for ever.
		update_option( self::OPTION, $state, false );
		return $state;
	}

	/**
	 * POST to the licensing API.
	 *
	 * @param string               $action Endpoint suffix.
	 * @param array<string, mixed> $payload Body.
	 * @return array<string, mixed>|null Decoded body, or null when we could not ask.
	 */
	private static function post( string $action, array $payload ): ?array {
		$base = (string) Zinn_Chat_Settings::get( 'api_base', 'https://api.zinndigital.com' );
		$url  = rtrim( $base, '/' ) . '/v1/plugin-licences/' . $action;

		$response = wp_remote_post(
			$url,
			array(
				// ⛔ A bound on TIME. A hung endpoint with no timeout blocks wp-admin — or
				// worse, cron — for as long as the socket stays open.
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * A human sentence for a machine reason code.
	 *
	 * ⛔⛔ **THE ENGINE SENDS A CODE AND THIS FILE OWNS THE SENTENCE, AND THAT ORDERING IS
	 * §2.19.** A sentence minted server-side would be rendered verbatim inside a Japanese
	 * customer's wp-admin, because the engine has no idea what locale this site runs in.
	 * Translating here means the string goes through the plugin's own catalogues like every
	 * other line of copy.
	 *
	 * ⛔ The default is deliberately vague rather than confident. §2.57: a failure we cannot
	 * classify must not be reported as the customer's fault — a code we do not recognise is
	 * a newer engine, not a lapsed customer.
	 *
	 * @param string $reason Machine code from the API.
	 * @return string
	 */
	public static function explain( string $reason ): string {
		switch ( $reason ) {
			case 'ok':
				return __( 'Your licence is active.', 'zinn-chat' );
			case 'in_grace':
				return __( 'Your licence has run out but everything still works for a short while. Renew it to keep receiving updates.', 'zinn-chat' );
			case 'unknown_key':
				return __( 'We do not recognise that licence key. Copy it again from the Licences page of your Zinn Digital® dashboard.', 'zinn-chat' );
			case 'wrong_product':
				return __( 'That key is for a different Zinn® plugin. The one you want is on the Licences page of your dashboard, next to Zinn® Chat Pro.', 'zinn-chat' );
			case 'expired':
				return __( 'Your licence has expired. Renew it from your Zinn Digital® dashboard to switch the Pro features back on.', 'zinn-chat' );
			case 'cancelled':
				return __( 'This licence has been cancelled.', 'zinn-chat' );
			case 'suspended':
				return __( 'This licence is on hold. Please get in touch and we will sort it out.', 'zinn-chat' );
			case 'org_not_in_good_standing':
				return __( 'There is a billing problem on your Zinn Digital® account. Once it is settled the licence works again straight away.', 'zinn-chat' );
			case 'hosting_ended':
				return __( 'This licence came free with your Zinn® hosting, and that hosting has ended. Buy a licence, or start hosting with us again, to switch Pro back on.', 'zinn-chat' );
			case 'no_seats':
				return __( 'Every site on this licence is in use. Release one below, or upgrade to cover more sites.', 'zinn-chat' );
			default:
				return __( 'We could not confirm your licence just now. Nothing has changed on your site; we will try again shortly.', 'zinn-chat' );
		}
	}
}
