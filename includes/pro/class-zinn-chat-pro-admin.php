<?php
/**
 * Pro: the licence panel and the targeting controls, on the existing settings screen.
 *
 * @package ZinnChat
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything Pro adds to Settings → Zinn® Chat.
 *
 * ⛔ On the SAME screen, not a second menu entry. The free screen's own docstring argues
 * that a chat plugin's settings belong in the dashboard and only what cannot live there
 * belongs here; a Pro plugin that answers by adding a top-level menu has doubled the places
 * a customer looks without adding an answer.
 *
 * ⛔ No remote call from `admin_init` or from rendering. The licence verdict is read from
 * the option `Zinn_Chat_Licence` refreshes on cron; only pressing the button talks to us.
 */
final class Zinn_Chat_Pro_Admin {

	private const PAGE = 'zinn-chat';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_post_zinn_chat_licence', array( __CLASS__, 'handle' ) );
		add_action( 'zinn_chat_after_settings', array( __CLASS__, 'render' ) );
		add_filter( 'zinn_chat_sanitize', array( __CLASS__, 'sanitize' ), 10, 2 );
	}

	/**
	 * Keep the Pro half of the settings array through a save.
	 *
	 * @param array<string, mixed> $clean  What the free plugin produced.
	 * @param mixed                $input  The raw submission.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $clean, $input ): array {
		$raw                = is_array( $input ) ? $input : array();
		$clean['targeting'] = Zinn_Chat_Targeting::sanitize( $raw['targeting'] ?? array() );
		return $clean;
	}

	/**
	 * Activate or deactivate a licence key.
	 *
	 * ⛔ `admin_post` with a nonce and a capability check rather than handling `$_POST` on
	 * the settings page: this is not a setting, it is an action with a remote side effect,
	 * and the Settings API would replay it on every save.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage licences on this site.', 'zinn-chat' ) );
		}
		check_admin_referer( 'zinn_chat_licence' );

		$key    = isset( $_POST['licence_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['licence_key'] ) ) : '';
		$remove = isset( $_POST['remove'] );

		if ( $remove ) {
			Zinn_Chat_Licence::deactivate( (string) Zinn_Chat_Licence::state()['key'] );
		} elseif ( '' !== $key ) {
			Zinn_Chat_Licence::activate( $key );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Draw the licence panel and, when licensed, the targeting rules.
	 */
	public static function render(): void {
		$state  = Zinn_Chat_Licence::state();
		$active = Zinn_Chat_Licence::is_active();
		$key    = (string) $state['key'];
		$reason = (string) $state['reason'];
		?>
		<hr />
		<h2><?php esc_html_e( 'Your Pro licence', 'zinn-chat' ); ?></h2>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="zinn_chat_licence" />
			<?php wp_nonce_field( 'zinn_chat_licence' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="zinn-chat-licence"><?php esc_html_e( 'Licence key', 'zinn-chat' ); ?></label>
					</th>
					<td>
						<input
							type="text" id="zinn-chat-licence" class="regular-text" spellcheck="false"
							name="licence_key" value="<?php echo esc_attr( $key ); ?>"
							placeholder="ZINN-XXXXX-XXXXX-XXXXX-XXXXX" />
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to the dashboard licences page. */
								esc_html__( 'Copy it from the Licences page of your %s.', 'zinn-chat' ),
								'<a href="https://app.zinndigital.com/#/licences" target="_blank" rel="noopener">'
									. esc_html__( 'Zinn Digital® dashboard', 'zinn-chat' ) . '</a>'
							);
							?>
						</p>
						<?php if ( '' !== $reason ) : ?>
							<p class="description">
								<strong><?php echo esc_html( Zinn_Chat_Licence::explain( $reason ) ); ?></strong>
							</p>
						<?php endif; ?>
						<?php if ( $active && '' !== (string) $state['expires_at'] ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: a date. */
									esc_html__( 'Renews on %s.', 'zinn-chat' ),
									esc_html(
										date_i18n(
											(string) get_option( 'date_format' ),
											(int) strtotime( (string) $state['expires_at'] )
										)
									)
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( $active && (int) $state['sites_allowed'] > 0 ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: 1: sites in use, 2: sites the licence covers. */
									esc_html__( 'Using %1$d of %2$d sites.', 'zinn-chat' ),
									(int) $state['sites_used'],
									(int) $state['sites_allowed']
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( 'no_seats' === $reason && ! empty( $state['seat_holders'] ) ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: comma-separated list of hostnames. */
									esc_html__( 'In use on: %s. Release one from your dashboard, then try again.', 'zinn-chat' ),
									esc_html( implode( ', ', (array) $state['seat_holders'] ) )
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Save licence', 'zinn-chat' ); ?>
				</button>
				<?php if ( '' !== $key ) : ?>
					<button type="submit" name="remove" value="1" class="button">
						<?php esc_html_e( 'Remove it from this site', 'zinn-chat' ); ?>
					</button>
				<?php endif; ?>
			</p>
		</form>

		<?php
		// ⛔⛔ The targeting controls are shown ONLY when the licence is live. Rendering a
		// disabled Pro form to a lapsed customer is a screen that looks broken; telling them
		// plainly what is switched off, and why, is §2.57's rule pointed at our own product.
		if ( ! $active ) {
			return;
		}
		$rules = Zinn_Chat_Targeting::rules();
		$types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<hr />
		<h2><?php esc_html_e( 'Where the chat appears', 'zinn-chat' ); ?></h2>
		<form action="options.php" method="post">
			<?php settings_fields( 'zinn_chat' ); ?>
			<input
				type="hidden"
				name="<?php echo esc_attr( Zinn_Chat_Settings::OPTION ); ?>[public_key]"
				value="<?php echo esc_attr( (string) Zinn_Chat_Settings::get( 'public_key', '' ) ); ?>" />
			<?php if ( Zinn_Chat_Settings::get( 'enabled' ) ) : ?>
				<input type="hidden" name="<?php echo esc_attr( Zinn_Chat_Settings::OPTION ); ?>[enabled]" value="1" />
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Show it', 'zinn-chat' ); ?></th>
					<td>
						<?php
						$modes = array(
							Zinn_Chat_Targeting::MODE_EVERYWHERE => __( 'Everywhere on this site', 'zinn-chat' ),
							Zinn_Chat_Targeting::MODE_ONLY => __( 'Only on the pages I choose below', 'zinn-chat' ),
							Zinn_Chat_Targeting::MODE_EXCEPT => __( 'Everywhere except the pages I choose below', 'zinn-chat' ),
						);
						foreach ( $modes as $value => $label ) :
							?>
							<label style="display:block;margin-block-end:.35rem">
								<input
									type="radio" value="<?php echo esc_attr( $value ); ?>"
									name="<?php echo esc_attr( Zinn_Chat_Settings::OPTION ); ?>[targeting][mode]"
									<?php checked( (string) $rules['mode'], $value ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="zinn-chat-ids"><?php esc_html_e( 'Page or post IDs', 'zinn-chat' ); ?></label>
					</th>
					<td>
						<input
							type="text" id="zinn-chat-ids" class="regular-text"
							name="<?php echo esc_attr( Zinn_Chat_Settings::OPTION ); ?>[targeting][ids]"
							value="<?php echo esc_attr( implode( ', ', array_map( 'strval', (array) $rules['ids'] ) ) ); ?>" />
						<p class="description">
							<?php esc_html_e( 'Separate them with commas. The ID is in the address bar while you edit a page.', 'zinn-chat' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Whole content types', 'zinn-chat' ); ?></th>
					<td>
						<?php foreach ( $types as $type ) : ?>
							<label style="display:inline-block;margin-inline-end:1rem">
								<input
									type="checkbox" value="<?php echo esc_attr( $type->name ); ?>"
									name="<?php echo esc_attr( Zinn_Chat_Settings::OPTION ); ?>[targeting][post_types][]"
									<?php checked( in_array( $type->name, (array) $rules['post_types'], true ) ); ?> />
								<?php echo esc_html( $type->labels->name ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Signed-in visitors', 'zinn-chat' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox" value="1"
								name="<?php echo esc_attr( Zinn_Chat_Settings::OPTION ); ?>[targeting][hide_for_users]"
								<?php checked( ! empty( $rules['hide_for_users'] ) ); ?> />
							<?php esc_html_e( 'Hide the chat from people who are signed in to this site', 'zinn-chat' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save where it appears', 'zinn-chat' ) ); ?>
		</form>
		<?php
	}
}
