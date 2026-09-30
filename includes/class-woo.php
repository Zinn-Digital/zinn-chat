<?php
/**
 * WooCommerce: the My Account support tab, and "where is my order?" for signed-in customers.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only active when WooCommerce is.
 *
 * The account tab ("Support", endpoint `support-tickets`) lists the customer's tickets, opens one,
 * and lets them start a new one about a chosen order. Each order page gets a "Get help with this
 * order" link to the same form.
 *
 * The assistant may read a signed-in customer's OWN recent orders (status, date, total, items,
 * tracking numbers from the common tracking plugins) so it can answer "where is my order?".
 * ⛔ Only ever the orders of the user the chat belongs to, looked up by that user's id on the
 * server; nothing in the request can name another customer.
 */
final class Woo {

	public const ENDPOINT = 'support-tickets';

	/**
	 * Hooks (after WooCommerce has loaded).
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) || Settings::connected() ) {
			return;
		}
		if ( Settings::get( 'woo_tab', true ) && Settings::get( 'tickets_enabled', true ) ) {
			add_action( 'init', array( self::class, 'endpoint' ) );
			add_filter( 'woocommerce_get_query_vars', array( self::class, 'query_vars' ) );
			add_filter( 'woocommerce_account_menu_items', array( self::class, 'menu' ) );
			add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( self::class, 'render' ) );
			add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( self::class, 'title' ) );
			add_action( 'woocommerce_order_details_after_order_table', array( self::class, 'order_link' ) );
			add_filter( 'zinn_chat_customer_ticket_url', array( self::class, 'customer_url' ), 10, 2 );
		}
	}

	/**
	 * Register the endpoint.
	 *
	 * @return void
	 */
	public static function endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		if ( get_option( 'zinn_chat_rewrite' ) !== self::ENDPOINT ) {
			flush_rewrite_rules( false );
			update_option( 'zinn_chat_rewrite', self::ENDPOINT, false );
		}
	}

	/**
	 * Tell WooCommerce about the query var.
	 *
	 * @param array<string, string> $vars Vars.
	 * @return array<string, string>
	 */
	public static function query_vars( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Add "Support" before "Log out".
	 *
	 * @param array<string, string> $items Menu.
	 * @return array<string, string>
	 */
	public static function menu( array $items ): array {
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out[ self::ENDPOINT ] = __( 'Support', 'zinn-chat' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Support', 'zinn-chat' );
		}
		return $out;
	}

	/**
	 * Tab title.
	 *
	 * @return string
	 */
	public static function title(): string {
		return __( 'Support', 'zinn-chat' );
	}

	/**
	 * The tab: a ticket (when ?ticket= is set), else the list and the new-ticket form.
	 *
	 * @return void
	 */
	public static function render(): void {
		echo Front_Tickets::account_view( get_current_user_id(), (string) wc_get_account_endpoint_url( self::ENDPOINT ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- account_view escapes everything it prints.
	}

	/**
	 * "Get help with this order" under an order's details.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public static function order_link( $order ): void {
		if ( ! is_object( $order ) || ! is_user_logged_in() || (int) $order->get_customer_id() !== get_current_user_id() ) {
			return;
		}
		$url = add_query_arg( 'order', (int) $order->get_id(), wc_get_account_endpoint_url( self::ENDPOINT ) ) . '#zc-new';
		echo '<p class="zc-order-help"><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Get help with this order', 'zinn-chat' ) . '</a></p>';
	}

	/**
	 * A customer with an account reads tickets in their account, not through a private link.
	 *
	 * @param string               $url    Private link.
	 * @param array<string, mixed> $ticket Ticket.
	 * @return string
	 */
	public static function customer_url( string $url, array $ticket ): string {
		if ( (int) $ticket['user_id'] > 0 && function_exists( 'wc_get_account_endpoint_url' ) ) {
			return add_query_arg( 'ticket', (int) $ticket['id'], wc_get_account_endpoint_url( self::ENDPOINT ) );
		}
		return $url;
	}

	/**
	 * The signed-in customer's own recent orders as plain text for the assistant ('' if none).
	 *
	 * @param int $user_id Customer (the chat's owner, from the server-side session).
	 * @return string
	 */
	public static function orders_context( int $user_id ): string {
		if ( ! $user_id || ! function_exists( 'wc_get_orders' ) || ! Settings::get( 'woo_orders', true ) ) {
			return '';
		}
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 5,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$lines  = array();
		foreach ( (array) $orders as $order ) {
			if ( ! is_object( $order ) || (int) $order->get_customer_id() !== $user_id ) {
				continue;
			}
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_quantity() . ' x ' . $item->get_name();
			}
			$line     = sprintf(
				'Order #%s placed %s: status "%s", total %s, items: %s. Order page: %s',
				$order->get_order_number(),
				$order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
				wc_get_order_status_name( $order->get_status() ),
				html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				implode( ', ', $items ),
				$order->get_view_order_url()
			);
			$tracking = self::tracking( $order );
			if ( '' !== $tracking ) {
				$line .= ' Tracking: ' . $tracking;
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Tracking numbers from the common shipment-tracking plugins.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private static function tracking( $order ): string {
		$out  = array();
		$meta = $order->get_meta( '_wc_shipment_tracking_items' );
		foreach ( is_array( $meta ) ? $meta : array() as $row ) {
			if ( is_array( $row ) && ! empty( $row['tracking_number'] ) ) {
				$out[] = trim( ( $row['tracking_provider'] ?? $row['custom_tracking_provider'] ?? '' ) . ' ' . $row['tracking_number'] );
			}
		}
		/**
		 * Filters the tracking information given to the assistant for an order.
		 *
		 * @param array<int, string> $out   Tracking lines.
		 * @param \WC_Order          $order Order.
		 */
		$out = (array) apply_filters( 'zinn_chat_order_tracking', $out, $order );
		return implode( '; ', array_filter( array_map( 'strval', $out ) ) );
	}

	/**
	 * The customer's orders for the ticket form's order picker.
	 *
	 * @param int $user_id Customer.
	 * @return array<int, string> order id => label.
	 */
	public static function order_choices( int $user_id ): array {
		if ( ! $user_id || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 20,
			)
		) as $order ) {
			if ( is_object( $order ) ) {
				/* translators: 1: order number, 2: order date. */
				$out[ (int) $order->get_id() ] = sprintf( __( 'Order #%1$s (%2$s)', 'zinn-chat' ), $order->get_order_number(), $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' );
			}
		}
		return $out;
	}
}
