<?php
/**
 * Tickets on the site: the support page, the submit-a-ticket form, "my tickets", guest links,
 * shortcodes, and the menu items.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain HTML forms that post to admin-post.php, so they work with no JavaScript at all, on any
 * theme and with any page builder, and cost the page nothing until a visitor submits one.
 *
 * Shortcodes (each also a block and a page-builder module, see class-blocks.php):
 *   [zinn_chat_support]      the whole support page: a ticket from a private link, the signed-in
 *                            user's tickets, or the form, whichever applies
 *   [zinn_chat_ticket_form]  just the form. Attributes: title, subject, button, show_subject (yes|no)
 *   [zinn_chat_my_tickets]   the signed-in user's tickets (sign-in link otherwise)
 *   [zinn_chat_button]       a button that opens the chat. Attributes: label
 */
final class Front_Tickets {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'zinn_chat_support', array( self::class, 'support' ) );
		add_shortcode( 'zinn_chat_ticket_form', array( self::class, 'form_shortcode' ) );
		add_shortcode( 'zinn_chat_my_tickets', array( self::class, 'my_tickets' ) );
		add_shortcode( 'zinn_chat_button', array( self::class, 'chat_button' ) );
		foreach ( array( 'zinn_chat_ticket', 'zinn_chat_ticket_reply', 'zinn_chat_ticket_link' ) as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle_' . str_replace( 'zinn_chat_', '', $action ) ) );
			add_action( 'admin_post_nopriv_' . $action, array( self::class, 'handle_' . str_replace( 'zinn_chat_', '', $action ) ) );
		}
		add_action( 'admin_head-nav-menus.php', array( self::class, 'menu_box' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue) the small stylesheet; it is enqueued only where a form renders.
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		wp_register_style( 'zinn-chat-front', ZINN_CHAT_URL . 'assets/css/front.css', array(), ZINN_CHAT_VERSION );
	}

	/**
	 * The support page URL ('' when there is none).
	 *
	 * @return string
	 */
	public static function page_url(): string {
		$id = (int) Settings::get( 'ticket_page_id', 0 );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}
		return '';
	}

	/**
	 * Create the support page (Setup screen button) and remember it.
	 *
	 * @return int Page id.
	 */
	public static function create_page(): int {
		$existing = (int) Settings::get( 'ticket_page_id', 0 );
		if ( $existing && get_post( $existing ) ) {
			return $existing;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Support', 'zinn-chat' ),
				'post_content' => '<!-- wp:shortcode -->[zinn_chat_support]<!-- /wp:shortcode -->',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			Settings::save( array( 'ticket_page_id' => (int) $id ) );
			return (int) $id;
		}
		return 0;
	}

	/**
	 * Enqueue the form styles.
	 *
	 * @return void
	 */
	private static function assets(): void {
		if ( ! wp_style_is( 'zinn-chat-front', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'zinn-chat-front' );
	}

	/**
	 * A one-line notice after a redirect (?zc_notice=code).
	 *
	 * @return string
	 */
	private static function notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display flag set by our own redirect.
		$code = isset( $_GET['zc_notice'] ) ? sanitize_key( wp_unslash( $_GET['zc_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$id       = isset( $_GET['zc_id'] ) ? (int) $_GET['zc_id'] : 0;
		$messages = array(
			/* translators: %d: ticket number. */
			'sent'    => sprintf( __( 'Thank you. Your request is ticket #%d. We have emailed you a link to follow it.', 'zinn-chat' ), $id ),
			'replied' => __( 'Your reply has been sent.', 'zinn-chat' ),
			'link'    => __( 'If there is a request under that address, we have emailed you a new link.', 'zinn-chat' ),
			'error'   => __( 'Something went wrong. Please check the form and try again.', 'zinn-chat' ),
			'spam'    => __( 'Your message could not be sent.', 'zinn-chat' ),
			'limit'   => __( 'You are sending messages too quickly. Please wait a little and try again.', 'zinn-chat' ),
			'consent' => __( 'Please agree to the privacy notice to send your request.', 'zinn-chat' ),
			'robot'   => __( 'Please complete the check to show you are not a robot.', 'zinn-chat' ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return '';
		}
		$class = in_array( $code, array( 'sent', 'replied', 'link' ), true ) ? 'zc-ok' : 'zc-err';
		return '<div class="zc-notice ' . $class . '" role="status">' . esc_html( $messages[ $code ] ) . '</div>';
	}

	/**
	 * [zinn_chat_support].
	 *
	 * @return string
	 */
	public static function support(): string {
		self::assets();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a private link token, verified against its hash.
		$token = isset( $_GET['zc_ticket'] ) ? sanitize_text_field( wp_unslash( $_GET['zc_ticket'] ) ) : '';
		if ( '' !== $token ) {
			return '<div class="zc">' . self::notice() . self::guest_view( $token ) . '</div>';
		}
		if ( is_user_logged_in() ) {
			return self::account_view( get_current_user_id(), self::current_url() );
		}
		return '<div class="zc">' . self::notice() . self::form( array() ) . '</div>';
	}

	/**
	 * [zinn_chat_ticket_form].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public static function form_shortcode( $atts ): string {
		self::assets();
		$atts = shortcode_atts(
			array(
				'title'        => '',
				'subject'      => '',
				'button'       => '',
				'show_subject' => 'yes',
			),
			is_array( $atts ) ? $atts : array(),
			'zinn_chat_ticket_form'
		);
		return '<div class="zc">' . self::notice() . self::form( $atts ) . '</div>';
	}

	/**
	 * [zinn_chat_my_tickets].
	 *
	 * @return string
	 */
	public static function my_tickets(): string {
		self::assets();
		if ( ! is_user_logged_in() ) {
			return '<div class="zc"><p><a href="' . esc_url( wp_login_url( self::current_url() ) ) . '">' . esc_html__( 'Sign in to see your support requests.', 'zinn-chat' ) . '</a></p></div>';
		}
		return self::account_view( get_current_user_id(), self::current_url() );
	}

	/**
	 * [zinn_chat_button].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public static function chat_button( $atts ): string {
		$atts = shortcode_atts( array( 'label' => __( 'Chat with us', 'zinn-chat' ) ), is_array( $atts ) ? $atts : array(), 'zinn_chat_button' );
		if ( ! Settings::is_live() ) {
			return '';
		}
		return '<a class="zinn-chat-open wp-element-button" href="#zinn-chat">' . esc_html( (string) $atts['label'] ) . '</a>';
	}

	/**
	 * The current page URL without our own query arguments.
	 *
	 * @return string
	 */
	private static function current_url(): string {
		$url = (string) get_permalink();
		if ( '' === $url ) {
			$url = home_url( add_query_arg( array() ) );
		}
		return remove_query_arg( array( 'zc_notice', 'zc_id', 'ticket', 'zc_ticket', 'zc_draft' ), $url );
	}

	/**
	 * What the visitor typed before a refusal (see back()), or empty values.
	 *
	 * @return array<string, string>
	 */
	private static function draft(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a random id naming a short-lived draft; nothing is changed.
		$id    = isset( $_GET['zc_draft'] ) ? sanitize_key( wp_unslash( $_GET['zc_draft'] ) ) : '';
		$draft = '' !== $id ? get_transient( 'zinn_chat_draft_' . $id ) : false;
		return array_merge(
			array(
				'name'    => '',
				'email'   => '',
				'subject' => '',
				'body'    => '',
			),
			is_array( $draft ) ? array_map( 'strval', $draft ) : array()
		);
	}

	/**
	 * The submit-a-ticket form.
	 *
	 * @param array<string, string> $atts  Options.
	 * @param int                   $order Pre-selected order.
	 * @param string                $back  Where to return after sending ('' = this page).
	 * @return string
	 */
	public static function form( array $atts, int $order = 0, string $back = '' ): string {
		if ( ! Settings::get( 'tickets_enabled', true ) || Settings::connected() ) {
			return '';
		}
		$user  = wp_get_current_user();
		$draft = self::draft();
		$out   = '<form class="zc-form" id="zinn-chat-new" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		if ( ! empty( $atts['title'] ) ) {
			$out .= '<h3>' . esc_html( $atts['title'] ) . '</h3>';
		}
		$out .= '<input type="hidden" name="action" value="zinn_chat_ticket">';
		$out .= wp_nonce_field( 'zinn_chat_ticket', '_zc', true, false );
		$out .= '<input type="hidden" name="back" value="' . esc_attr( '' !== $back ? $back : self::current_url() ) . '">';
		$out .= '<input type="hidden" name="language" value="' . esc_attr( determine_locale() ) . '">';
		if ( ! $user->exists() ) {
			$out .= self::input( 'name', __( 'Your name', 'zinn-chat' ), 'text', $draft['name'], false );
			$out .= self::input( 'email', __( 'Your email', 'zinn-chat' ), 'email', $draft['email'], true );
		}
		if ( 'no' !== ( $atts['show_subject'] ?? 'yes' ) ) {
			$out .= self::input( 'subject', __( 'Subject', 'zinn-chat' ), 'text', '' !== $draft['subject'] ? $draft['subject'] : (string) ( $atts['subject'] ?? '' ), false );
		} elseif ( ! empty( $atts['subject'] ) ) {
			$out .= '<input type="hidden" name="subject" value="' . esc_attr( $atts['subject'] ) . '">';
		}
		$orders = $user->exists() ? Woo::order_choices( (int) $user->ID ) : array();
		if ( $orders ) {
			$out .= '<p><label for="zc-order">' . esc_html__( 'Which order is this about?', 'zinn-chat' ) . '</label><select id="zc-order" name="order_id"><option value="0">' . esc_html__( 'Not about an order', 'zinn-chat' ) . '</option>';
			foreach ( $orders as $id => $label ) {
				$out .= '<option value="' . (int) $id . '"' . selected( $order, $id, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$out .= '</select></p>';
		}
		$out .= '<p><label for="zc-body">' . esc_html__( 'How can we help?', 'zinn-chat' ) . '</label><textarea id="zc-body" name="body" rows="6" required maxlength="' . (int) Settings::get( 'max_chars', 2000 ) * 5 . '">' . esc_textarea( $draft['body'] ) . '</textarea></p>';
		$out .= '<p class="zc-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>';
		if ( ! $user->exists() && Settings::get( 'consent_required', true ) ) {
			$privacy = Settings::text( 'privacy_url' );
			$out    .= '<p class="zc-consent"><label><input type="checkbox" name="consent" value="1" required> ' . esc_html( Settings::text( 'consent_text' ) ) . '</label>'
				. ( '' !== $privacy ? ' <a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy policy', 'zinn-chat' ) . '</a>' : '' ) . '</p>';
		}
		if ( ! $user->exists() ) {
			/**
			 * Filters extra markup at the end of a guest ticket form (Pro's human check goes here).
			 *
			 * @param string $html Markup (already escaped by whoever adds it).
			 */
			$out .= (string) apply_filters( 'zinn_chat_ticket_form_extra', '' );
		}
		$button = ! empty( $atts['button'] ) ? (string) $atts['button'] : __( 'Send request', 'zinn-chat' );
		$out   .= '<p><button type="submit" class="zc-button wp-element-button">' . esc_html( $button ) . '</button></p></form>';
		return $out;
	}

	/**
	 * One labelled input.
	 *
	 * @param string $name     Name.
	 * @param string $label    Label.
	 * @param string $type     Type.
	 * @param string $value    Value.
	 * @param bool   $required Required.
	 * @return string
	 */
	private static function input( string $name, string $label, string $type, string $value, bool $required ): string {
		return '<p><label for="zc-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><input id="zc-' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . '></p>';
	}

	/**
	 * A signed-in customer's area: one ticket (?ticket=ID) or the list + a new-ticket form.
	 *
	 * @param int    $user_id Customer.
	 * @param string $base    URL of the area.
	 * @return string
	 */
	public static function account_view( int $user_id, string $base ): string {
		self::assets();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selecting a ticket to view; ownership is checked.
		$ticket_id = isset( $_GET['ticket'] ) ? (int) $_GET['ticket'] : 0;
		$out       = '<div class="zc">' . self::notice();
		if ( $ticket_id ) {
			$ticket = Tickets::get( $ticket_id );
			if ( $ticket && Tickets::owned_by( $ticket, $user_id ) ) {
				$out .= '<p><a href="' . esc_url( $base ) . '">&larr; ' . esc_html__( 'All requests', 'zinn-chat' ) . '</a></p>';
				$out .= self::thread( $ticket, array( 'ticket_id' => (string) $ticket_id ), $base );
				return $out . '</div>';
			}
		}
		$found = Tickets::query(
			array(
				'status'   => 'all',
				'user_id'  => $user_id,
				'email'    => (string) wp_get_current_user()->user_email,
				'per_page' => 50,
			)
		);
		$out  .= '<h3>' . esc_html__( 'Your support requests', 'zinn-chat' ) . '</h3>';
		if ( ! $found['rows'] ) {
			$out .= '<p>' . esc_html__( 'You have no support requests yet.', 'zinn-chat' ) . '</p>';
		} else {
			$out .= '<table class="zc-table"><thead><tr><th>#</th><th>' . esc_html__( 'Subject', 'zinn-chat' ) . '</th><th>' . esc_html__( 'Status', 'zinn-chat' ) . '</th><th>' . esc_html__( 'Updated', 'zinn-chat' ) . '</th></tr></thead><tbody>';
			foreach ( $found['rows'] as $row ) {
				$link = add_query_arg( 'ticket', (int) $row['id'], $base );
				$out .= '<tr><td>' . (int) $row['id'] . '</td><td><a href="' . esc_url( $link ) . '">' . esc_html( (string) $row['subject'] ) . '</a></td><td>' . esc_html( Tickets::status_label( (string) $row['status'] ) ) . '</td><td>' . esc_html( mysql2date( get_option( 'date_format' ), get_date_from_gmt( (string) $row['updated_at'] ) ) ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pre-selecting an order in the form.
		$order = isset( $_GET['order'] ) ? (int) $_GET['order'] : 0;
		$out  .= '<h3>' . esc_html__( 'New request', 'zinn-chat' ) . '</h3>' . self::form( array(), $order, $base );
		return $out . '</div>';
	}

	/**
	 * A ticket reached by a private link.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function guest_view( string $token ): string {
		$found = Tickets::by_token( $token );
		if ( ! $found['ticket'] || $found['expired'] ) {
			$out  = '<p>' . esc_html( $found['expired'] ? __( 'This link has expired. Enter your email address and we will send you a new one.', 'zinn-chat' ) : __( 'This link is not valid. Enter your email address and we will send you a link to your requests.', 'zinn-chat' ) ) . '</p>';
			$out .= '<form class="zc-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="zinn_chat_ticket_link">'
				. wp_nonce_field( 'zinn_chat_ticket_link', '_zc', true, false )
				. '<input type="hidden" name="token" value="' . esc_attr( $token ) . '"><input type="hidden" name="back" value="' . esc_attr( self::current_url() ) . '">'
				. self::input( 'email', __( 'Your email', 'zinn-chat' ), 'email', '', true )
				. '<p><button type="submit" class="zc-button wp-element-button">' . esc_html__( 'Email me a new link', 'zinn-chat' ) . '</button></p></form>';
			return $out;
		}
		return self::thread( $found['ticket'], array( 'token' => $token ) );
	}

	/**
	 * A ticket's thread and the reply form.
	 *
	 * @param array<string, mixed>  $ticket Ticket.
	 * @param array<string, string> $auth   `token` or `ticket_id` carried by the reply form.
	 * @param string                $back   Where to return after replying ('' = this page).
	 * @return string
	 */
	private static function thread( array $ticket, array $auth, string $back = '' ): string {
		/* translators: 1: ticket number, 2: subject. */
		$out  = '<h3>' . esc_html( sprintf( __( 'Request #%1$d: %2$s', 'zinn-chat' ), (int) $ticket['id'], (string) $ticket['subject'] ) ) . '</h3>';
		$out .= '<p class="zc-status">' . esc_html__( 'Status:', 'zinn-chat' ) . ' <strong>' . esc_html( Tickets::status_label( (string) $ticket['status'] ) ) . '</strong></p><ol class="zc-thread">';
		foreach ( self::public_replies( (int) $ticket['id'] ) as $reply ) {
			$out .= '<li class="zc-' . esc_attr( $reply['author'] ) . '"><div class="zc-meta">' . esc_html( $reply['name'] ) . ' &middot; ' . esc_html( $reply['when'] ) . '</div><div class="zc-body">' . $reply['html'] . '</div></li>';
		}
		$out .= '</ol>';
		$out .= '<form class="zc-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="zinn_chat_ticket_reply">'
			. wp_nonce_field( 'zinn_chat_ticket_reply', '_zc', true, false )
			. '<input type="hidden" name="back" value="' . esc_attr( '' !== $back ? $back : self::current_url() ) . '">';
		foreach ( $auth as $key => $value ) {
			$out .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		$out .= '<p><label for="zc-reply">' . esc_html__( 'Your reply', 'zinn-chat' ) . '</label><textarea id="zc-reply" name="body" rows="5" required>' . esc_textarea( self::draft()['body'] ) . '</textarea></p>'
			. '<p class="zc-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>'
			. '<p><button type="submit" class="zc-button wp-element-button">' . esc_html__( 'Send reply', 'zinn-chat' ) . '</button></p></form>';
		return $out;
	}

	/**
	 * Replies as the customer may see them (no internal notes).
	 *
	 * @param int $ticket_id Ticket.
	 * @return array<int, array<string, string>>
	 */
	public static function public_replies( int $ticket_id ): array {
		$ticket = Tickets::get( $ticket_id );
		$out    = array();
		foreach ( Tickets::replies( $ticket_id ) as $reply ) {
			if ( 'customer' === $reply['author'] ) {
				$name = '' !== (string) ( $ticket['name'] ?? '' ) ? (string) $ticket['name'] : __( 'You', 'zinn-chat' );
			} else {
				$user = (int) $reply['user_id'] ? get_userdata( (int) $reply['user_id'] ) : null;
				$name = $user ? (string) $user->display_name : Settings::text( 'from_name' );
			}
			$out[] = array(
				'author' => (string) $reply['author'],
				'name'   => $name,
				'when'   => mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( (string) $reply['created_at'] ) ),
				'html'   => Util::render_text( (string) $reply['body'] ),
			);
		}
		return $out;
	}

	/**
	 * The posted form's single-line fields, sanitised, for a human check to read.
	 *
	 * @return array<string, string>
	 */
	private static function posted_strings(): array {
		$out = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the callers verified the nonce.
		foreach ( array_keys( $_POST ) as $key ) {
			$key = sanitize_key( (string) $key );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the callers verified the nonce.
			if ( '' !== $key && isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the callers verified the nonce.
				$out[ $key ] = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
			}
		}
		return $out;
	}

	/**
	 * Redirect back with a notice.
	 *
	 * @param string $code   Notice code.
	 * @param int    $id     Ticket id for the notice.
	 * @param string $anchor Fragment.
	 * @return void
	 */
	private static function back( string $code, int $id = 0, string $anchor = '' ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the callers verified the nonce.
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : home_url( '/' );
		$back = wp_validate_redirect( $back, home_url( '/' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the callers verified the nonce.
		$keep = array_filter(
			array(
				'zc_ticket' => isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
				'ticket'    => isset( $_POST['ticket_id'] ) ? (int) $_POST['ticket_id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
			)
		);
		if ( 'link' === $code ) {
			unset( $keep['zc_ticket'] );
		}
		// A refused form must not throw away what the visitor typed: keep it for ten minutes under
		// a random id carried by the redirect, and fill the form in again from it.
		if ( ! in_array( $code, array( 'sent', 'replied', 'link' ), true ) ) {
			$draft = array();
			foreach ( array( 'name', 'email', 'subject' ) as $field ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the callers verified the nonce.
				$draft[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the callers verified the nonce.
			$draft['body']    = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
			$draft_id         = wp_generate_password( 12, false );
			$keep['zc_draft'] = $draft_id;
			set_transient( 'zinn_chat_draft_' . $draft_id, $draft, 10 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					$keep,
					array(
						'zc_notice' => $code,
						'zc_id'     => $id,
					)
				),
				$back
			) . $anchor
		);
		exit;
	}

	/**
	 * Common abuse checks for the forms (the REST API does the same for the widget).
	 *
	 * @param string $action Bucket.
	 * @param string $text   Text.
	 * @param string $email  Email.
	 * @return string '' when fine, else a notice code.
	 */
	private static function abuse( string $action, string $text, string $email ): string {
		$ip = Util::client_ip();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the callers verified the nonce.
		if ( Spam::honeypot_tripped( wp_unslash( $_POST ) ) || Spam::blocked( $ip, $email, $text ) ) {
			return 'spam';
		}
		if ( Spam::over_limit( $action, '' !== $ip ? $ip : 'unknown' ) ) {
			return 'limit';
		}
		return '';
	}

	/**
	 * Form handler: a new ticket.
	 *
	 * @return void
	 */
	public static function handle_ticket(): void {
		if ( ! isset( $_POST['_zc'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_zc'] ) ), 'zinn_chat_ticket' ) ) {
			self::back( 'error' );
		}
		$user  = wp_get_current_user();
		$email = $user->exists() ? (string) $user->user_email : sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$body  = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
		$abuse = self::abuse( 'ticket', $body, $email );
		if ( '' !== $abuse ) {
			self::back( $abuse );
		}
		if ( ! $user->exists() ) {
			if ( Settings::get( 'consent_required', true ) && empty( $_POST['consent'] ) ) {
				self::back( 'consent' );
			}
			if ( ! Spam::challenge_ok( self::posted_strings(), Util::client_ip(), 'ticket_form' ) ) {
				self::back( 'robot' );
			}
		}
		$order = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		if ( $order && ! array_key_exists( $order, Woo::order_choices( (int) $user->ID ) ) ) {
			$order = 0;
		}
		$made = Tickets::create(
			array(
				'subject'  => sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ),
				'body'     => $body,
				'name'     => $user->exists() ? $user->display_name : sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'email'    => $email,
				'user_id'  => (int) $user->ID,
				'order_id' => $order,
				'channel'  => $user->exists() ? 'account' : 'form',
				'language' => sanitize_text_field( wp_unslash( $_POST['language'] ?? '' ) ),
				'ip'       => Util::client_ip(),
			)
		);
		if ( is_wp_error( $made ) ) {
			self::back( 'error' );
		}
		self::back( 'sent', (int) $made['id'] );
	}

	/**
	 * Form handler: a customer reply.
	 *
	 * @return void
	 */
	public static function handle_ticket_reply(): void {
		if ( ! isset( $_POST['_zc'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_zc'] ) ), 'zinn_chat_ticket_reply' ) ) {
			self::back( 'error' );
		}
		$token  = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$ticket = null;
		if ( '' !== $token ) {
			$found  = Tickets::by_token( $token );
			$ticket = ( $found['ticket'] && ! $found['expired'] ) ? $found['ticket'] : null;
		} else {
			$candidate = Tickets::get( isset( $_POST['ticket_id'] ) ? absint( wp_unslash( $_POST['ticket_id'] ) ) : 0 );
			$ticket    = ( $candidate && Tickets::owned_by( $candidate, get_current_user_id() ) ) ? $candidate : null;
		}
		if ( ! $ticket ) {
			self::back( 'error' );
		}
		$body  = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
		$abuse = self::abuse( 'message', $body, (string) $ticket['email'] );
		if ( '' !== $abuse ) {
			self::back( $abuse );
		}
		$made = Tickets::reply( (int) $ticket['id'], 'customer', $body, get_current_user_id() );
		self::back( is_wp_error( $made ) ? 'error' : 'replied', (int) $ticket['id'] );
	}

	/**
	 * Form handler: email a fresh link.
	 *
	 * @return void
	 */
	public static function handle_ticket_link(): void {
		if ( ! isset( $_POST['_zc'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_zc'] ) ), 'zinn_chat_ticket_link' ) ) {
			self::back( 'error' );
		}
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$abuse = self::abuse( 'ticket', '', $email );
		if ( '' !== $abuse ) {
			self::back( $abuse );
		}
		if ( is_email( $email ) ) {
			$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
			$found = '' !== $token ? Tickets::by_token( $token ) : array( 'ticket' => null );
			$rows  = ( $found['ticket'] && 0 === strcasecmp( (string) $found['ticket']['email'], $email ) )
				? array( $found['ticket'] )
				: Tickets::query(
					array(
						'status'   => 'all',
						'email'    => $email,
						'per_page' => 5,
					)
				)['rows'];
			foreach ( $rows as $row ) {
				Tickets::refresh_token( (int) $row['id'] );
				Mailer::fresh_link( (array) Tickets::get( (int) $row['id'] ) );
			}
		}
		self::back( 'link' );
	}

	/**
	 * Appearance → Menus: a "Zinn Chat" box with the support page and a "Chat with us" link.
	 *
	 * @return void
	 */
	public static function menu_box(): void {
		add_meta_box( 'zinn-chat-menu', __( 'Zinn® Chat', 'zinn-chat' ), array( self::class, 'render_menu_box' ), 'nav-menus', 'side', 'default' );
	}

	/**
	 * The menu box (uses core's custom-link markup so "Add to Menu" works unchanged).
	 *
	 * @return void
	 */
	public static function render_menu_box(): void {
		$items = array();
		$page  = self::page_url();
		if ( '' !== $page ) {
			$items[] = array( __( 'Submit a ticket', 'zinn-chat' ), $page );
		}
		$items[] = array( __( 'Chat with us', 'zinn-chat' ), '#zinn-chat' );
		echo '<div id="zinn-chat-menu-items" class="posttypediv"><div class="tabs-panel tabs-panel-active"><ul class="categorychecklist form-no-clear">';
		foreach ( $items as $i => $item ) {
			$n = -1 - $i;
			echo '<li><label class="menu-item-title"><input type="checkbox" class="menu-item-checkbox" name="menu-item[' . (int) $n . '][menu-item-object-id]" value="' . (int) $n . '"> ' . esc_html( $item[0] ) . '</label>'
				. '<input type="hidden" class="menu-item-type" name="menu-item[' . (int) $n . '][menu-item-type]" value="custom">'
				. '<input type="hidden" class="menu-item-title" name="menu-item[' . (int) $n . '][menu-item-title]" value="' . esc_attr( $item[0] ) . '">'
				. '<input type="hidden" class="menu-item-url" name="menu-item[' . (int) $n . '][menu-item-url]" value="' . esc_attr( $item[1] ) . '"></li>';
		}
		echo '</ul></div><p class="button-controls"><span class="add-to-menu"><input type="submit" class="button submit-add-to-menu right" value="' . esc_attr__( 'Add to Menu', 'zinn-chat' ) . '" name="add-zinn-chat-menu-item" id="submit-zinn-chat-menu-items"><span class="spinner"></span></span></p></div>';
		if ( '' === $page ) {
			echo '<p>' . esc_html__( 'Create the support page on the Zinn® Chat setup screen to add a "Submit a ticket" link.', 'zinn-chat' ) . '</p>';
		}
	}
}
