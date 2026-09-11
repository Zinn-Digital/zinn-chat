<?php
/**
 * The settings screen: paste a key, tick a box, done.
 *
 * @package ZinnChat
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → Zinn® Chat.
 *
 * ⛔⛔ **THE SCREEN IS DELIBERATELY TINY AND THAT IS A PRODUCT DECISION.** Everything a
 * chat needs configuring — the greeting, the colour, business hours, canned replies, who
 * answers — is configured once in the Zinn® dashboard and applies to every site the
 * customer runs. Duplicating it here would give an agency with forty sites forty places
 * to change their opening hours, and would make this plugin the thing that has to be kept
 * in step with the product. What lives here is only what cannot live anywhere else: WHICH
 * chat this site is, and whether it is on.
 *
 * ⛔ No remote call from this screen. `admin_init` runs on every wp-admin page load, and a
 * plugin that reaches our API from there makes every admin page on a site we may not even
 * host depend on our uptime — the first thing a directory reviewer looks for.
 */
final class Zinn_Chat_Admin {

	private const PAGE  = 'zinn-chat';
	private const GROUP = 'zinn_chat';

	/**
	 * Register the admin hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( ZINN_CHAT_FILE ),
			array( __CLASS__, 'action_links' )
		);
	}

	/**
	 * Add a Settings link on the plugins screen.
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public static function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'zinn-chat' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Add the settings page.
	 */
	public static function menu(): void {
		add_options_page(
			/* translators: page title. */
			__( 'Zinn® Chat', 'zinn-chat' ),
			__( 'Zinn® Chat', 'zinn-chat' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * ⭐ Through `register_setting` rather than a hand-rolled `$_POST` handler: WordPress
	 * then owns the nonce, the capability check and the redirect, and a directory reviewer
	 * can see at a glance that all three are there.
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			Zinn_Chat_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Zinn_Chat_Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitise a submission.
	 *
	 * ⛔ Delegates to `Zinn_Chat_Settings::save()`'s cleaning rather than repeating it, so
	 * a value typed on this screen and one written by WP-CLI cannot be validated
	 * differently. ⚠️ It must NOT call `save()` itself: the Settings API writes the
	 * returned array, so doing both would write twice and the second write would win with
	 * whatever the first one had already stored.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$current = Zinn_Chat_Settings::all();
		$input   = is_array( $input ) ? $input : array();

		$key = isset( $input['public_key'] ) ? trim( sanitize_text_field( (string) $input['public_key'] ) ) : '';
		if ( '' !== $key && ! preg_match( '/^zc_[A-Za-z0-9_-]{8,64}$/', $key ) ) {
			add_settings_error(
				Zinn_Chat_Settings::OPTION,
				'zinn-chat-key',
				esc_html__( 'That does not look like a Zinn® Chat key. Copy it from the Live chat page of your Zinn Digital® dashboard — it starts with zc_.', 'zinn-chat' )
			);
			$key = '';
		}

		$enabled = ! empty( $input['enabled'] );
		if ( $enabled && '' === $key ) {
			add_settings_error(
				Zinn_Chat_Settings::OPTION,
				'zinn-chat-enable',
				esc_html__( 'Add your chat key before switching the chat on — without it there is nothing for the widget to connect to.', 'zinn-chat' )
			);
			$enabled = false;
		}

		return array(
			'enabled'    => $enabled,
			'public_key' => $key,
			'api_base'   => $current['api_base'],
			'config'     => is_array( $current['config'] ) ? $current['config'] : array(),
		);
	}

	/**
	 * Draw the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Zinn_Chat_Settings::all();
		$live     = Zinn_Chat_Settings::is_live();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zinn® Chat', 'zinn-chat' ); ?></h1>
			<p>
				<?php esc_html_e( 'Live chat for this site. Visitors get an answer straight away, you get anything you miss by email, and the whole thing is under 5 KB on the page.', 'zinn-chat' ); ?>
			</p>
			<p>
				<?php
				if ( $live ) {
					echo '<strong>' . esc_html__( 'The chat is live on this site.', 'zinn-chat' ) . '</strong>';
				} else {
					echo '<strong>' . esc_html__( 'The chat is switched off. Nothing is loaded on your site.', 'zinn-chat' ) . '</strong>';
				}
				?>
			</p>
			<form action="options.php" method="post">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="zinn-chat-key"><?php esc_html_e( 'Chat key', 'zinn-chat' ); ?></label>
						</th>
						<td>
							<input
								type="text" id="zinn-chat-key" class="regular-text" spellcheck="false"
								name="<?php echo esc_attr( Zinn_Chat_Settings::OPTION ); ?>[public_key]"
								value="<?php echo esc_attr( (string) $settings['public_key'] ); ?>"
								placeholder="zc_…" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the Zinn dashboard. */
									esc_html__( 'Copy this from the Live chat page of your %s.', 'zinn-chat' ),
									'<a href="https://app.zinndigital.com/live-chat" target="_blank" rel="noopener">'
										. esc_html__( 'Zinn Digital® dashboard', 'zinn-chat' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show the chat', 'zinn-chat' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox" value="1"
									name="<?php echo esc_attr( Zinn_Chat_Settings::OPTION ); ?>[enabled]"
									<?php checked( ! empty( $settings['enabled'] ) ); ?> />
								<?php esc_html_e( 'Show the chat window to visitors of this site', 'zinn-chat' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Until you tick this, the plugin adds nothing at all to your pages — no script, no cookie, no requests.', 'zinn-chat' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php
			// The shared Zinn® panel — hosting, the marketplace, Zinn Hub® and this plugin's
			// own guide (⚖️ owner, 2026-09-01). ⛔ A direct `::render_panel()` call rather
			// than a string callable: the generated file is global so either resolves, but
			// `scripts/wp-promo-check.py`'s P003 looks for the literal `::render_panel`, and
			// a string callable is invisible to it. A gate that cannot see the consumer
			// reports the panel as unrendered — a false alarm in the direction somebody acts
			// on (§2.44's twin).
			if ( class_exists( 'Zinn_Chat_Promo' ) ) {
				Zinn_Chat_Promo::render_panel();
			}
			?>
		</div>
		<?php
	}
}
