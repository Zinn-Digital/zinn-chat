<?php
/**
 * Pro: deciding WHERE the chat appears.
 *
 * @package ZinnChat
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-page targeting rules, applied through the free plugin's own filter.
 *
 * ⛔⛔ **THIS IS THE ONE PRO FEATURE THAT GENUINELY CANNOT LIVE ON THE SERVER, WHICH IS WHY
 * IT IS THE ONE IN THE PLUGIN.** Everything else Pro unlocks — unlimited seats, forever
 * transcripts, branding removal, business hours — is decided by the engine, because it is
 * decided per WIDGET and a customer runs one widget across many sites. Where the widget
 * appears is a question only WordPress can answer: it is about post types, templates and
 * URLs that exist nowhere but on this site.
 *
 * ⭐ It hooks `zinn_chat_should_render`, which the free plugin already publishes. Nothing
 * here reaches inside the free code, so the free plugin keeps working unchanged and this
 * file could be deleted with no trace — which is exactly what the free build does.
 *
 * ⛔ Two rules and no more, deliberately. A visual rule builder with AND/OR groups is the
 * thing every plugin grows and nobody uses; "everywhere except these" and "only these"
 * covers the two real cases — hide it on checkout, or run it only on the support section.
 */
final class Zinn_Chat_Targeting {

	public const MODE_EVERYWHERE = 'everywhere';
	public const MODE_ONLY       = 'only';
	public const MODE_EXCEPT     = 'except';

	/**
	 * Register the hook.
	 */
	public static function init(): void {
		add_filter( 'zinn_chat_should_render', array( __CLASS__, 'decide' ), 10, 1 );
	}

	/**
	 * Defaults for the Pro half of the settings array.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'mode'           => self::MODE_EVERYWHERE,
			/** Post IDs the rule names. @var array<int, int> */
			'ids'            => array(),
			/** Post types the rule names. @var array<int, string> */
			'post_types'     => array(),
			/** Hide from signed-in users — the usual ask for a membership site. */
			'hide_for_users' => false,
		);
	}

	/**
	 * The stored rules, defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function rules(): array {
		$all   = Zinn_Chat_Settings::all();
		$rules = isset( $all['targeting'] ) && is_array( $all['targeting'] ) ? $all['targeting'] : array();
		return array_merge( self::defaults(), $rules );
	}

	/**
	 * Should the widget render on THIS request?
	 *
	 * ⛔⛔ **AN UNLICENSED SITE GETS THE FREE ANSWER, NOT THE STORED RULES.** A customer
	 * whose licence lapses must not discover that their chat has vanished from most of
	 * their site: withdrawing a Pro feature means going back to the free BEHAVIOUR, which
	 * is "everywhere", never leaving half-applied Pro configuration in force. This is the
	 * difference between a feature being switched off and a site being broken by it.
	 *
	 * @param bool $render What the free plugin decided.
	 * @return bool
	 */
	public static function decide( bool $render ): bool {
		if ( ! $render ) {
			// ⛔ Never widens. The free plugin has already said no — because the chat is
			// switched off, or another plugin's filter objected — and a targeting rule is
			// about narrowing, not about overriding somebody else's veto.
			return false;
		}
		if ( ! Zinn_Chat_Licence::is_active() ) {
			return true;
		}

		$rules = self::rules();
		if ( ! empty( $rules['hide_for_users'] ) && is_user_logged_in() ) {
			return false;
		}

		$mode = (string) $rules['mode'];
		if ( self::MODE_EVERYWHERE === $mode ) {
			return true;
		}
		$matched = self::matches( $rules );
		return self::MODE_ONLY === $mode ? $matched : ! $matched;
	}

	/**
	 * Does the current request match the rule's list?
	 *
	 * @param array<string, mixed> $rules Stored rules.
	 * @return bool
	 */
	private static function matches( array $rules ): bool {
		$ids   = array_map( 'intval', (array) $rules['ids'] );
		$types = array_map( 'strval', (array) $rules['post_types'] );

		if ( $types && is_singular( $types ) ) {
			return true;
		}
		// ⛔ `is_singular()` first and `get_queried_object_id()` second, not the other way
		// round: on an archive or a 404 the queried object id is a term or an author id, and
		// comparing that against a list of POST ids matches whichever happens to collide.
		if ( $ids && is_singular() && in_array( (int) get_queried_object_id(), $ids, true ) ) {
			return true;
		}
		// The front page has no post id when it is set to "your latest posts".
		return $ids && is_front_page() && in_array( 0, $ids, true );
	}

	/**
	 * Clean a submitted rule set.
	 *
	 * @param mixed $input Raw.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$mode  = isset( $input['mode'] ) ? (string) $input['mode'] : self::MODE_EVERYWHERE;
		if ( ! in_array( $mode, array( self::MODE_EVERYWHERE, self::MODE_ONLY, self::MODE_EXCEPT ), true ) ) {
			$mode = self::MODE_EVERYWHERE;
		}

		$ids = array();
		if ( isset( $input['ids'] ) ) {
			// Accepts the comma-separated list the textarea produces.
			$raw = is_array( $input['ids'] ) ? $input['ids'] : preg_split( '/[\s,]+/', (string) $input['ids'] );
			foreach ( (array) $raw as $one ) {
				$one = (int) $one;
				if ( $one > 0 ) {
					$ids[] = $one;
				}
			}
		}

		$types     = array();
		$available = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		foreach ( (array) ( $input['post_types'] ?? array() ) as $type ) {
			$type = sanitize_key( (string) $type );
			// ⛔ Checked against what this site actually has. Storing an arbitrary string
			// would let a stale rule silently match nothing for ever after a plugin that
			// registered that post type is removed — and nothing would say so.
			if ( in_array( $type, $available, true ) ) {
				$types[] = $type;
			}
		}

		return array(
			'mode'           => $mode,
			'ids'            => array_values( array_unique( $ids ) ),
			'post_types'     => array_values( array_unique( $types ) ),
			'hide_for_users' => ! empty( $input['hide_for_users'] ),
		);
	}
}
