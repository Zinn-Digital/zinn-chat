<?php
/**
 * What Pro adds, shown inside the free plugin.
 *
 * @package ZinnChat
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Pro upsell on the free plugin's settings screen.
 *
 * ⚖️ **Owner, 2026-09-13:** the free plugin *"needs to really upsell the pro version"*.
 *
 * ⛔⛔ **IT IS PRESENT ONLY IN THE FREE BUILD, AND THAT IS NOT TIDINESS — IT IS THE
 * DIFFERENCE BETWEEN AN UPSELL AND AN INSULT.** `wp/bin/build-plugin.php --pro` drops this
 * file, so a customer who has paid never sees a panel asking them to buy what they already
 * own. The check is structural (the file is absent) rather than a runtime `if`, because a
 * runtime check is a thing somebody can get wrong once and never notice.
 *
 * ⛔ **Honest about what Pro actually changes, and specifically honest about the hosting
 * grant.** Zinn® hosting includes Pro (⚖️ owner ruling 2026-09-11), so the panel says so —
 * selling somebody a licence for something their hosting already gives them is the kind of
 * upsell that costs a customer rather than earning one.
 *
 * ⛔ **No remote call, no image loaded from us, no tracking pixel.** The same rule the
 * shared Zinn® panel one file over ships under: this renders inside somebody else's
 * wp-admin, possibly on a site we do not host, and a panel that fetched anything would put
 * our uptime on their admin's critical path. It is also the first thing a WordPress.org
 * reviewer looks for.
 *
 * ⛔ Admin only. A promotional line rendered on the public site would be a FOOTPRINT event
 * before it was a branding one (§2.14, `docs/203` §8) — the signature a footprint audit
 * hunts across a network.
 */
final class Zinn_Chat_Upsell {

	/**
	 * The paid edition's name, isolated so it is not on the translation surface at all.
	 *
	 * ⛔⛤ **THIS WAS `esc_html_e( 'Zinn® Chat Pro', … )` AND TWO INDEPENDENT GUARDS REFUSED IT
	 * IN TWO LANGUAGES** (W43-84). `he` detached the ® from the mark under RTL
	 * (`MalformedMarkError`), and `tg` answered in Latin script (`TranslationRejectedError`).
	 * Both refusals were correct, and retrying cleared neither — because the model was being
	 * asked to translate something that **must not be translated**: a registered mark plus an
	 * edition word (§2.15). A human translation was refused too, by a different guard, for
	 * being identical to the English — which it has to be.
	 *
	 * ⭐ So the string leaves the POT rather than being argued with, exactly as
	 * `Zinn_Chat_Promo::NAME` does one file over and for the same measured reason. The
	 * isolates are `U+2068 FIRST STRONG ISOLATE` / `U+2069 POP DIRECTIONAL ISOLATE`: without
	 * them the neutral run `® ` sits between opposite strong types in an RTL paragraph and
	 * lays out backwards, putting the symbol on the wrong side of the mark.
	 */
	private const NAME = "\u{2068}Zinn® Chat Pro\u{2069}";

	/** Where somebody goes to buy or to read more. */
	private const PRODUCT_URL = 'https://zinndigital.com/wordpress-plugins/zinn-chat-pro';

	/** Where a Zinn® hosting customer finds the licence they already have. */
	private const LICENCES_URL = 'https://app.zinndigital.com/#/licences';

	/**
	 * Register the hook.
	 */
	public static function init(): void {
		add_action( 'zinn_chat_after_settings', array( __CLASS__, 'render' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( ZINN_CHAT_FILE ),
			array( __CLASS__, 'action_link' ),
			20
		);
	}

	/**
	 * A "Go Pro" link beside Settings on the Plugins screen.
	 *
	 * ⚖️ Owner ruling 2026-09-28 (spec rule 2): promote Pro from the plugins list as well as
	 * from the plugin's own screen. ⭐ Inside the WordPress.org guidelines by construction:
	 * guideline 11 allows upgrade prompts that are *"contextual, or only on the plugin's
	 * settings page"* — an action link sits on this plugin's own row and nowhere else, is not a
	 * notice, needs no dismissing and nags nobody — and guideline 5 is untouched, because the
	 * free plugin locks nothing behind it.
	 *
	 * ⛔ Registered HERE, in the file the Pro build drops, not in `Zinn_Chat_Admin` beside the
	 * Settings link. That makes "a paying customer is never asked to buy what they own"
	 * structural for this link too — it cannot exist in a build that has no upsell class. The
	 * priority (20) puts it after Settings, which `Zinn_Chat_Admin` adds at the default 10.
	 *
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public static function action_link( array $links ): array {
		$links['zinn_chat_go_pro'] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener" style="font-weight:600">%2$s</a>',
			esc_url( self::PRODUCT_URL ),
			esc_html__( 'Go Pro', 'zinn-chat' )
		);
		return $links;
	}

	/**
	 * Draw the panel.
	 */
	public static function render(): void {
		?>
		<hr />
		<div class="card" style="max-width:48rem;padding-block:1rem;padding-inline:1.25rem">
			<h2 style="margin-block-start:0"><?php echo esc_html( self::NAME ); ?></h2>
			<p>
				<?php esc_html_e( 'Everything here keeps working for nothing, for ever. Pro is for when one person answering, and a month of history, stops being enough.', 'zinn-chat' ); ?>
			</p>
			<ul style="list-style:disc;padding-inline-start:1.25rem">
				<li><?php esc_html_e( 'As many colleagues answering at once as you like — the free plugin allows one.', 'zinn-chat' ); ?></li>
				<li><?php esc_html_e( 'Every conversation kept for ever, instead of thirty days.', 'zinn-chat' ); ?></li>
				<li><?php esc_html_e( 'Your own name on the chat window, with ours removed.', 'zinn-chat' ); ?></li>
				<li><?php esc_html_e( 'Saved replies, transfer a conversation to a colleague, and business hours.', 'zinn-chat' ); ?></li>
				<li><?php esc_html_e( '5,000 AI answers a month rather than 100.', 'zinn-chat' ); ?></li>
				<li><?php esc_html_e( 'Choose exactly which pages the chat appears on — or hide it from the checkout.', 'zinn-chat' ); ?></li>
				<li><?php esc_html_e( 'One licence covers three of your sites; ten-site licences are available.', 'zinn-chat' ); ?></li>
			</ul>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::PRODUCT_URL ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'See what Pro costs', 'zinn-chat' ); ?>
				</a>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the customer's licences page. */
					esc_html__( 'Already host a site with Zinn Digital®? Pro is included free — your key is on the %s.', 'zinn-chat' ),
					'<a href="' . esc_url( self::LICENCES_URL ) . '" target="_blank" rel="noopener">'
						. esc_html__( 'Licences page of your dashboard', 'zinn-chat' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
