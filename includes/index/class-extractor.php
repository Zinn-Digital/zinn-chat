<?php
/**
 * What a visitor would read on each piece of content, whatever built it.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Index;

use ZinnDigital\ZinnChat\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an object (a post of any public type, a WooCommerce product, a forum topic, the shop's
 * shipping and returns rules) into `title`, `url`, `text`, `modified`, `language`, `kind`.
 *
 * ⭐ RENDERED content, not the raw editor data: an Elementor, Divi, Beaver Builder, Bricks or Page
 * Builder Sandwich page stores its words in builder data that means nothing as text. Each known
 * builder is rendered through its own API; everything else goes through `the_content` (Gutenberg
 * blocks, shortcodes, and every plugin that hooks it); and when that still yields almost nothing
 * the page can be fetched as a visitor would see it (Settings → Site index → "read pages as
 * visitors see them"). Custom fields (ACF) and WooCommerce data are added from their own APIs.
 *
 * Object types: `post` (any post type, incl. products and bbPress/BuddyBoss topics), `wpforo_topic`,
 * `woo_store` (one synthetic item: shipping zones and methods, payment methods, store address).
 * More can be added with the `zinn_chat_index_extract` filter.
 */
final class Extractor {

	/**
	 * Post types that are indexed.
	 *
	 * @return array<int, string>
	 */
	public static function post_types(): array {
		$chosen = (array) Settings::get( 'index_types', array() );
		if ( array() === $chosen ) {
			$chosen = array_values( get_post_types( array( 'public' => true ) ) );
		}
		$chosen = array_diff( $chosen, array( 'attachment', 'reply', 'product_variation', 'elementor_library', 'fl-builder-template', 'et_pb_layout', 'bricks_template', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ) );
		if ( ! Settings::get( 'index_woo', true ) ) {
			$chosen = array_diff( $chosen, array( 'product' ) );
		}
		if ( ! Settings::get( 'index_forums', true ) ) {
			$chosen = array_diff( $chosen, array( 'forum', 'topic' ) );
		}
		/**
		 * Filters the post types the assistant reads.
		 *
		 * @param array<int, string> $types Post types.
		 */
		return array_values( (array) apply_filters( 'zinn_chat_index_post_types', array_values( $chosen ) ) );
	}

	/**
	 * Extract one object. Null when it must not be indexed (unpublished, private, excluded, noindex).
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object id.
	 * @return array{title: string, url: string, text: string, modified: string, language: string, kind: string}|null
	 */
	public static function extract( string $type, int $id ): ?array {
		switch ( $type ) {
			case 'post':
				$out = self::post( $id );
				break;
			case 'wpforo_topic':
				$out = self::wpforo_topic( $id );
				break;
			case 'woo_store':
				$out = self::woo_store();
				break;
			default:
				$out = null;
		}
		/**
		 * Filters an extracted object (or supplies one for a custom object type).
		 *
		 * @param array<string, string>|null $out  Extracted fields, or null to skip.
		 * @param string                     $type Object type.
		 * @param int                        $id   Object id.
		 */
		$out = apply_filters( 'zinn_chat_index_extract', $out, $type, $id );
		if ( ! is_array( $out ) || '' === trim( (string) ( $out['text'] ?? '' ) ) ) {
			return null;
		}
		return array(
			'title'    => mb_substr( trim( (string) ( $out['title'] ?? '' ) ), 0, 400 ),
			'url'      => (string) ( $out['url'] ?? '' ),
			'text'     => (string) $out['text'],
			'modified' => (string) ( $out['modified'] ?? '' ),
			'language' => (string) ( $out['language'] ?? '' ),
			'kind'     => (string) ( $out['kind'] ?? $type ),
		);
	}

	/**
	 * May this post be indexed?
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function indexable( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return false;
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return false;
		}
		if ( in_array( (int) $post->ID, self::excluded_ids(), true ) ) {
			return false;
		}
		// Application pages (cart, checkout, account, a forum's front page) have no words of their
		// own, and rendering them outside a visitor request makes their plugins misbehave.
		if ( 1 === preg_match( '/\[(woocommerce_(cart|checkout|my_account)|wpforo|bbp-forum-index)\b|wp:woocommerce\/(cart|checkout)\b/', (string) $post->post_content ) ) {
			return false;
		}
		// Pages the owner told search engines not to index are not something to quote to visitors.
		if ( '1' === (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return false;
		}
		$rank_math = get_post_meta( $post->ID, 'rank_math_robots', true );
		if ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) {
			return false;
		}
		if ( 'yes' === (string) get_post_meta( $post->ID, '_seopress_robots_index', true ) ) {
			return false;
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product && 'hidden' === $product->get_catalog_visibility() ) {
				return false;
			}
		}
		/**
		 * Filters whether a post is indexed.
		 *
		 * @param bool     $indexable Default decision.
		 * @param \WP_Post $post      Post.
		 */
		return (bool) apply_filters( 'zinn_chat_indexable', true, $post );
	}

	/**
	 * Post ids the owner excluded (Settings → Site index), plus the ticket page itself.
	 *
	 * @return array<int, int>
	 */
	public static function excluded_ids(): array {
		$ids   = array_map( 'intval', preg_split( '/[\s,]+/', (string) Settings::get( 'index_exclude', '' ) ) );
		$ids[] = (int) Settings::get( 'ticket_page_id', 0 );
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$ids[] = (int) wc_get_page_id( $page );
			}
		}
		return array_values( array_filter( $ids ) );
	}

	/**
	 * A post of any type.
	 *
	 * @param int $id Post id.
	 * @return array<string, string>|null
	 */
	private static function post( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! self::indexable( $post ) ) {
			return null;
		}
		$url  = (string) get_permalink( $post );
		$text = self::rendered( $post, $url );

		$extras = array();
		if ( '' !== trim( (string) $post->post_excerpt ) && false === strpos( $text, trim( (string) $post->post_excerpt ) ) ) {
			$extras[] = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
		}
		if ( 'product' === $post->post_type ) {
			$extras[] = self::product_facts( $id );
		}
		if ( 'topic' === $post->post_type ) {
			$extras[] = self::bbpress_replies( $id );
		}
		$extras[] = self::custom_fields( $id );
		$extras[] = self::terms( $post );
		foreach ( array_filter( $extras ) as $extra ) {
			$text .= "\n\n" . $extra;
		}

		return array(
			'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'url'      => $url,
			'text'     => trim( $text ),
			'modified' => (string) $post->post_modified_gmt,
			'language' => self::language( $post ),
			'kind'     => (string) $post->post_type,
		);
	}

	/**
	 * The post's content as a visitor reads it.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $url  Its address (for a visitor-view fetch).
	 * @return string
	 */
	public static function rendered( \WP_Post $post, string $url ): string {
		$html = self::builder_html( $post );
		if ( null === $html ) {
			$html = self::the_content( $post );
		}
		$text = Html_Text::convert( $html );
		// Almost nothing came out (a builder we cannot render off-screen, a template-driven page):
		// read the page the way a visitor would, when the owner allowed it.
		if ( mb_strlen( $text ) < 200 && Settings::get( 'index_fetch', false ) && '' !== $url ) {
			$fetched = self::fetch( $url );
			if ( mb_strlen( $fetched ) > mb_strlen( $text ) ) {
				$text = $fetched;
			}
		}
		return $text;
	}

	/**
	 * Builder-rendered HTML, when a builder we know built this post. Null otherwise.
	 *
	 * @param \WP_Post $post Post.
	 * @return string|null
	 */
	private static function builder_html( \WP_Post $post ): ?string {
		$id = (int) $post->ID;
		// Elementor.
		if ( 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) && class_exists( '\Elementor\Plugin' ) ) {
			$plugin = \Elementor\Plugin::$instance;
			if ( isset( $plugin->frontend ) && method_exists( $plugin->frontend, 'get_builder_content_for_display' ) ) {
				$html = (string) $plugin->frontend->get_builder_content_for_display( $id, false );
				if ( '' !== trim( $html ) ) {
					return $html;
				}
			}
		}
		// Beaver Builder.
		if ( get_post_meta( $id, '_fl_builder_enabled', true ) && class_exists( '\FLBuilder' ) && method_exists( '\FLBuilder', 'render_content_by_id' ) ) {
			ob_start();
			\FLBuilder::render_content_by_id( $id );
			$html = (string) ob_get_clean();
			if ( '' !== trim( $html ) ) {
				return $html;
			}
		}
		// Bricks.
		$bricks = get_post_meta( $id, '_bricks_page_content_2', true );
		if ( is_array( $bricks ) && $bricks && class_exists( '\Bricks\Frontend' ) && method_exists( '\Bricks\Frontend', 'render_data' ) ) {
			$html = (string) \Bricks\Frontend::render_data( $bricks );
			if ( '' !== trim( $html ) ) {
				return $html;
			}
		}
		// Divi (shortcode builder): make sure its modules are registered off the front end.
		if ( false !== strpos( (string) $post->post_content, '[et_pb_' ) && function_exists( 'et_builder_add_main_elements' ) && ! shortcode_exists( 'et_pb_section' ) ) {
			et_builder_add_main_elements();
		}
		return null;
	}

	/**
	 * `the_content` for a post, run as if it were the page being viewed.
	 *
	 * Gutenberg, Page Builder Sandwich, Divi and every plugin that renders through `the_content`
	 * come out as HTML here. Shortcodes nobody registered are unwrapped to their inner text rather
	 * than left as literal "[tag]" noise.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function the_content( \WP_Post $post ): string {
		global $wp_query;
		$previous_post   = $GLOBALS['post'] ?? null;
		$previous_query  = $wp_query;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below; the_content filters read the global.
		setup_postdata( $post );
		// Some plugins only add their content on a singular main query.
		if ( class_exists( '\WP_Query' ) ) {
			$wp_query = new \WP_Query( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
				array(
					'p'         => $post->ID,
					'post_type' => $post->post_type,
				)
			); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		}
		$content = (string) $post->post_content;
		try {
			$html = (string) apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		} catch ( \Throwable $e ) {
			$html = do_blocks( $content );
		}
		$wp_query        = $previous_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
		$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
		if ( $previous_post instanceof \WP_Post ) {
			setup_postdata( $previous_post );
		} else {
			wp_reset_postdata();
		}
		return self::unwrap_shortcodes( $html );
	}

	/**
	 * Remove leftover shortcode tags, keeping what is between them.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function unwrap_shortcodes( string $html ): string {
		return (string) preg_replace( '/\[\/?[a-zA-Z][a-zA-Z0-9_-]*(?:\s[^\]]*)?\]/', ' ', $html );
	}

	/**
	 * Read a page as a visitor sees it (loopback request) and keep its main content.
	 *
	 * @param string $url Page URL on this site.
	 * @return string
	 */
	public static function fetch( string $url ): string {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $home ) {
			return '';
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'ZinnChat-Indexer/' . ( defined( 'ZINN_CHAT_VERSION' ) ? ZINN_CHAT_VERSION : '2' ) . '; ' . home_url(),
				'sslverify'  => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook for loopback requests.
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		return Html_Text::convert( (string) wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * WooCommerce product facts: price, stock, SKU, attributes, variations, dimensions, ratings.
	 *
	 * @param int $id Product id.
	 * @return string
	 */
	public static function product_facts( int $id ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return '';
		}
		$lines   = array();
		$price   = self::money( (string) $product->get_price() );
		$regular = self::money( (string) $product->get_regular_price() );
		if ( '' !== $price ) {
			/* translators: %s: price. */
			$lines[] = sprintf( __( 'Price: %s', 'zinn-chat' ), $price ) . ( $product->is_on_sale() && '' !== $regular && $regular !== $price ? ' ' . sprintf( /* translators: %s: regular price. */ __( '(on sale, normally %s)', 'zinn-chat' ), $regular ) : '' );
		}
		if ( $product->get_sku() ) {
			/* translators: %s: SKU. */
			$lines[] = sprintf( __( 'SKU: %s', 'zinn-chat' ), $product->get_sku() );
		}
		$lines[] = self::stock_line( $product );
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! method_exists( $attribute, 'get_visible' ) || ! $attribute->get_visible() ) {
				continue;
			}
			$values  = $attribute->is_taxonomy() ? wc_get_product_terms( $id, $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options();
			$lines[] = wc_attribute_label( $attribute->get_name() ) . ': ' . implode( ', ', array_map( 'strval', (array) $values ) );
		}
		if ( $product->has_weight() ) {
			/* translators: %s: weight with unit. */
			$lines[] = sprintf( __( 'Weight: %s', 'zinn-chat' ), wc_format_weight( $product->get_weight() ) );
		}
		if ( $product->has_dimensions() ) {
			/* translators: %s: dimensions. */
			$lines[] = sprintf( __( 'Dimensions: %s', 'zinn-chat' ), wc_format_dimensions( $product->get_dimensions( false ) ) );
		}
		if ( $product->get_review_count() > 0 ) {
			/* translators: 1: average rating, 2: number of reviews. */
			$lines[] = sprintf( __( 'Rated %1$s out of 5 from %2$d reviews', 'zinn-chat' ), number_format_i18n( (float) $product->get_average_rating(), 1 ), (int) $product->get_review_count() );
		}
		if ( $product->is_type( 'external' ) && method_exists( $product, 'get_product_url' ) ) {
			/* translators: %s: URL where the product is sold. */
			$lines[] = sprintf( __( 'Sold at: %s', 'zinn-chat' ), $product->get_product_url() );
		}
		if ( $product->is_type( 'variable' ) ) {
			$count = 0;
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation || ! $variation->variation_is_visible() ) {
					continue;
				}
				$parts = array();
				foreach ( $variation->get_variation_attributes() as $key => $value ) {
					$taxonomy = str_replace( 'attribute_', '', (string) $key );
					$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', (string) $value, $taxonomy ) : null;
					$parts[]  = wc_attribute_label( $taxonomy ) . ' ' . ( $term ? $term->name : ( '' === (string) $value ? __( 'any', 'zinn-chat' ) : (string) $value ) );
				}
				$line = __( 'Option', 'zinn-chat' ) . ' ' . implode( ', ', $parts ) . ': ' . self::money( (string) $variation->get_price() ) . ', ' . self::stock_line( $variation );
				if ( $variation->get_sku() ) {
					$line .= ', SKU ' . $variation->get_sku();
				}
				$lines[] = $line;
				if ( ++$count >= 100 ) {
					break;
				}
			}
		}
		return implode( "\n", array_filter( $lines ) );
	}

	/**
	 * "In stock (12)", "Out of stock", "Available on backorder".
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private static function stock_line( $product ): string {
		switch ( $product->get_stock_status() ) {
			case 'outofstock':
				return __( 'Out of stock', 'zinn-chat' );
			case 'onbackorder':
				return __( 'Available on backorder', 'zinn-chat' );
		}
		$qty = $product->managing_stock() ? $product->get_stock_quantity() : null;
		/* translators: %d: items in stock. */
		return null !== $qty ? sprintf( __( 'In stock (%d)', 'zinn-chat' ), (int) $qty ) : __( 'In stock', 'zinn-chat' );
	}

	/**
	 * A price as plain text in the shop currency.
	 *
	 * @param string $amount Amount.
	 * @return string
	 */
	private static function money( string $amount ): string {
		if ( '' === $amount || ! function_exists( 'wc_price' ) ) {
			return '';
		}
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * The shop's own rules as one searchable item: shipping zones and methods, payment methods,
	 * store address and currency. Refund and terms pages are ordinary pages and are indexed as such.
	 *
	 * @return array<string, string>|null
	 */
	private static function woo_store(): ?array {
		if ( ! function_exists( 'WC' ) || ! Settings::get( 'index_woo', true ) ) {
			return null;
		}
		$lines = array();
		if ( class_exists( '\WC_Shipping_Zones' ) ) {
			$zones   = \WC_Shipping_Zones::get_zones();
			$zones[] = array(
				'zone_name'               => __( 'Everywhere else', 'zinn-chat' ),
				'shipping_methods'        => ( new \WC_Shipping_Zone( 0 ) )->get_shipping_methods( true ),
				'formatted_zone_location' => '',
			);
			foreach ( $zones as $zone ) {
				$methods = array();
				foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) {
					if ( ! is_object( $method ) || 'yes' !== ( $method->enabled ?? 'yes' ) ) {
						continue;
					}
					$detail = '';
					if ( isset( $method->instance_settings['cost'] ) && '' !== (string) $method->instance_settings['cost'] ) {
						$detail = ' (' . self::money( (string) $method->instance_settings['cost'] ) . ')';
					}
					if ( isset( $method->instance_settings['min_amount'] ) && '' !== (string) $method->instance_settings['min_amount'] ) {
						/* translators: %s: minimum order amount. */
						$detail = ' (' . sprintf( __( 'orders over %s', 'zinn-chat' ), self::money( (string) $method->instance_settings['min_amount'] ) ) . ')';
					}
					$methods[] = wp_strip_all_tags( (string) $method->get_title() ) . $detail;
				}
				if ( $methods ) {
					$where   = trim( (string) ( $zone['formatted_zone_location'] ?? '' ) );
					$lines[] = sprintf(
						/* translators: 1: shipping zone name, 2: where it applies, 3: shipping methods. */
						__( 'Shipping to %1$s%2$s: %3$s', 'zinn-chat' ),
						(string) ( $zone['zone_name'] ?? '' ),
						'' !== $where ? ' (' . wp_strip_all_tags( $where ) . ')' : '',
						implode( '; ', $methods )
					);
				}
			}
		}
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$titles = array();
			foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gateway ) {
				$titles[] = wp_strip_all_tags( (string) $gateway->get_title() );
			}
			if ( $titles ) {
				/* translators: %s: payment methods. */
				$lines[] = sprintf( __( 'Payment methods: %s', 'zinn-chat' ), implode( ', ', $titles ) );
			}
		}
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			/* translators: %s: currency code. */
			$lines[] = sprintf( __( 'Prices are in %s.', 'zinn-chat' ), get_woocommerce_currency() );
		}
		$address = array_filter( array( get_option( 'woocommerce_store_address' ), get_option( 'woocommerce_store_city' ), get_option( 'woocommerce_store_postcode' ) ) );
		if ( $address ) {
			/* translators: %s: store address. */
			$lines[] = sprintf( __( 'Store address: %s', 'zinn-chat' ), implode( ', ', array_map( 'strval', $address ) ) );
		}
		$returns = (int) get_option( 'woocommerce_refund_returns_page_id', 0 );
		if ( $returns && 'publish' === get_post_status( $returns ) ) {
			/* translators: %s: URL of the refund and returns policy. */
			$lines[] = sprintf( __( 'Refund and returns policy: %s', 'zinn-chat' ), get_permalink( $returns ) );
		}
		if ( ! $lines ) {
			return null;
		}
		$shop = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' );
		return array(
			'title'    => __( 'Shipping, payment and store information', 'zinn-chat' ),
			'url'      => $shop,
			'text'     => implode( "\n", $lines ),
			'modified' => gmdate( 'Y-m-d H:i:s' ),
			'language' => '',
			'kind'     => 'woo_store',
		);
	}

	/**
	 * A hash of what woo_store() would produce, so the sweep re-indexes it only when it changed.
	 *
	 * @return string
	 */
	public static function woo_store_hash(): string {
		$out = self::woo_store();
		return $out ? sha1( $out['text'] ) : '';
	}

	/**
	 * Replies of a bbPress/BuddyBoss topic (the answers are usually in the replies).
	 *
	 * @param int $topic_id Topic.
	 * @return string
	 */
	private static function bbpress_replies( int $topic_id ): string {
		$replies = get_posts(
			array(
				'post_type'      => 'reply',
				'post_parent'    => $topic_id,
				'post_status'    => 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- one topic's replies, read in the background.
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
		$out     = array();
		foreach ( $replies as $reply ) {
			$text = Html_Text::convert( (string) apply_filters( 'the_content', (string) $reply->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
			if ( '' !== $text ) {
				$out[] = __( 'Reply:', 'zinn-chat' ) . ' ' . $text;
			}
		}
		return implode( "\n\n", $out );
	}

	/**
	 * A wpForo topic with its posts.
	 *
	 * @param int $topic_id Topic id.
	 * @return array<string, string>|null
	 */
	private static function wpforo_topic( int $topic_id ): ?array {
		if ( ! function_exists( 'WPF' ) || ! Settings::get( 'index_forums', true ) ) {
			return null;
		}
		$wpf   = WPF();
		$topic = isset( $wpf->topic ) ? $wpf->topic->get_topic( $topic_id ) : null;
		if ( ! is_array( $topic ) || ! empty( $topic['private'] ) || ( isset( $topic['status'] ) && 0 !== (int) $topic['status'] ) ) {
			return null;
		}
		$posts = isset( $wpf->post ) ? $wpf->post->get_posts(
			array(
				'topicid'   => $topic_id,
				'orderby'   => 'created',
				'order'     => 'ASC',
				'row_count' => 200,
			)
		) : array();
		$text  = array();
		foreach ( (array) $posts as $post ) {
			if ( is_array( $post ) && isset( $post['body'] ) && empty( $post['private'] ) && 0 === (int) ( $post['status'] ?? 0 ) ) {
				$text[] = Html_Text::convert( (string) $post['body'] );
			}
		}
		$url = function_exists( 'wpforo_topic' ) ? (string) wpforo_topic( $topic_id, 'url' ) : (string) ( $topic['url'] ?? '' );
		return array(
			'title'    => (string) ( $topic['title'] ?? '' ),
			'url'      => $url,
			'text'     => implode( "\n\n", array_filter( $text ) ),
			'modified' => (string) ( $topic['modified'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'language' => '',
			'kind'     => 'forum',
		);
	}

	/**
	 * Visible custom field values (ACF), labelled.
	 *
	 * @param int $id Post id.
	 * @return string
	 */
	private static function custom_fields( int $id ): string {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return '';
		}
		$fields = get_field_objects( $id );
		$lines  = array();
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			$line = self::acf_value( $field );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * One ACF field as "Label: value" (recursing into groups and repeaters).
	 *
	 * @param array<string, mixed> $field Field object.
	 * @return string
	 */
	private static function acf_value( array $field ): string {
		$label = trim( (string) ( $field['label'] ?? '' ) );
		$value = $field['value'] ?? null;
		$type  = (string) ( $field['type'] ?? '' );
		if ( in_array( $type, array( 'password', 'image', 'file', 'gallery', 'google_map', 'color_picker', 'oembed' ), true ) || null === $value || '' === $value || false === $value ) {
			return '';
		}
		if ( 'true_false' === $type ) {
			return $label . ': ' . ( $value ? __( 'yes', 'zinn-chat' ) : __( 'no', 'zinn-chat' ) );
		}
		if ( is_scalar( $value ) ) {
			$text = trim( Html_Text::convert( (string) $value ) );
			return '' === $text ? '' : ( '' !== $label ? $label . ': ' : '' ) . $text;
		}
		$parts = array();
		array_walk_recursive(
			$value,
			static function ( $item ) use ( &$parts ) {
				if ( is_scalar( $item ) && '' !== trim( (string) $item ) && ! is_numeric( $item ) ) {
					$parts[] = trim( wp_strip_all_tags( (string) $item ) );
				} elseif ( $item instanceof \WP_Post ) {
					$parts[] = $item->post_title;
				} elseif ( $item instanceof \WP_Term ) {
					$parts[] = $item->name;
				}
			}
		);
		return $parts ? ( '' !== $label ? $label . ': ' : '' ) . implode( ', ', array_slice( array_unique( $parts ), 0, 50 ) ) : '';
	}

	/**
	 * Category/tag names (they often carry the answer's vocabulary).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function terms( \WP_Post $post ): string {
		$names = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) || 'post_format' === $taxonomy->name || 0 === strpos( $taxonomy->name, 'pa_' ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( is_array( $terms ) && $terms ) {
				$names[] = $taxonomy->labels->name . ': ' . implode( ', ', wp_list_pluck( $terms, 'name' ) );
			}
		}
		return implode( "\n", $names );
	}

	/**
	 * The language of a post on a multilingual site (Tranzly, WPML, Polylang), '' when unknown.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function language( \WP_Post $post ): string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			return (string) pll_get_post_language( $post->ID, 'locale' );
		}
		$wpml = apply_filters( 'wpml_post_language_details', null, $post->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public filter.
		if ( is_array( $wpml ) && ! empty( $wpml['locale'] ) ) {
			return (string) $wpml['locale'];
		}
		$meta = function_exists( 'tranzly_get_post_language' ) ? tranzly_get_post_language( (int) $post->ID ) : get_post_meta( $post->ID, '_tranzly_language', true );
		/**
		 * Filters the language (locale) of an indexed post.
		 *
		 * @param string   $language Locale or ''.
		 * @param \WP_Post $post     Post.
		 */
		return (string) apply_filters( 'zinn_chat_index_language', is_string( $meta ) ? $meta : '', $post );
	}
}
