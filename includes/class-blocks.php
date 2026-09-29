<?php
/**
 * The front-end elements as blocks and as page-builder modules.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Four elements, one renderer each (the shortcodes in Front_Tickets), offered to every editor:
 *
 *   Support page, ticket form, my tickets, chat button.
 *
 * - Gutenberg blocks (server-rendered, no build step). Page Builder Sandwich is built on the block
 *   editor, so the same blocks are its modules; Kadence, Spectra and GenerateBlocks likewise.
 * - Elementor widgets, Beaver Builder modules, Bricks elements and Divi modules, each registered
 *   only when that builder is active, each a thin wrapper around the same shortcode.
 *
 * `zinn_chat_elements` lets Pro add its own (knowledge base search, article lists, …) and have them
 * appear in every builder the same way.
 */
final class Blocks {

	/**
	 * The elements: slug => [title, shortcode, attributes (name => [type, default, label])].
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function elements(): array {
		$elements = array(
			'support'     => array(
				'title'     => __( 'Support page', 'zinn-chat' ),
				'shortcode' => 'zinn_chat_support',
				'icon'      => 'sos',
				'atts'      => array(),
			),
			'ticket-form' => array(
				'title'     => __( 'Submit a ticket form', 'zinn-chat' ),
				'shortcode' => 'zinn_chat_ticket_form',
				'icon'      => 'email-alt',
				'atts'      => array(
					'title'        => array( 'string', '', __( 'Heading', 'zinn-chat' ) ),
					'subject'      => array( 'string', '', __( 'Default subject', 'zinn-chat' ) ),
					'button'       => array( 'string', '', __( 'Button text', 'zinn-chat' ) ),
					'show_subject' => array( 'string', 'yes', __( 'Show the subject field (yes or no)', 'zinn-chat' ) ),
				),
			),
			'my-tickets'  => array(
				'title'     => __( 'My support requests', 'zinn-chat' ),
				'shortcode' => 'zinn_chat_my_tickets',
				'icon'      => 'list-view',
				'atts'      => array(),
			),
			'chat-button' => array(
				'title'     => __( 'Chat button', 'zinn-chat' ),
				'shortcode' => 'zinn_chat_button',
				'icon'      => 'format-chat',
				'atts'      => array(
					'label' => array( 'string', __( 'Chat with us', 'zinn-chat' ), __( 'Button text', 'zinn-chat' ) ),
				),
			),
		);
		/**
		 * Filters the front-end elements offered as blocks and builder modules.
		 *
		 * @param array<string, array<string, mixed>> $elements Elements.
		 */
		return (array) apply_filters( 'zinn_chat_elements', $elements );
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'elementor/widgets/register', array( self::class, 'elementor' ) );
		add_action( 'init', array( self::class, 'beaver' ), 20 );
		add_action( 'init', array( self::class, 'bricks' ), 11 );
		add_action( 'et_builder_ready', array( self::class, 'divi' ) );
	}

	/**
	 * Render an element through its shortcode.
	 *
	 * @param string               $slug Element.
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public static function render( string $slug, array $atts ): string {
		$element = self::elements()[ $slug ] ?? null;
		if ( ! $element ) {
			return '';
		}
		$pairs = '';
		foreach ( (array) $element['atts'] as $name => $spec ) {
			$value = $atts[ $name ] ?? $atts[ lcfirst( str_replace( '_', '', ucwords( (string) $name, '_' ) ) ) ] ?? null;
			if ( null !== $value && '' !== (string) $value ) {
				$pairs .= ' ' . $name . '="' . esc_attr( str_replace( array( '"', ']' ), '', (string) $value ) ) . '"';
			}
		}
		return do_shortcode( '[' . $element['shortcode'] . $pairs . ']' );
	}

	/**
	 * Gutenberg (and every block-based builder).
	 *
	 * @return void
	 */
	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'zinn-chat-blocks', ZINN_CHAT_URL . 'assets/js/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), ZINN_CHAT_VERSION, true );
		wp_set_script_translations( 'zinn-chat-blocks', 'zinn-chat', ZINN_CHAT_DIR . 'languages' );
		$data = array();
		foreach ( self::elements() as $slug => $element ) {
			$attributes = array();
			foreach ( (array) $element['atts'] as $name => $spec ) {
				$attributes[ $name ] = array(
					'type'    => 'string',
					'default' => (string) $spec[1],
				);
			}
			register_block_type(
				'zinn-chat/' . $slug,
				array(
					'api_version'     => 2,
					'title'           => $element['title'],
					'category'        => 'widgets',
					'icon'            => $element['icon'],
					'attributes'      => $attributes,
					'editor_script'   => 'zinn-chat-blocks',
					'render_callback' => static function ( $atts ) use ( $slug ): string {
						return self::render( $slug, (array) $atts );
					},
					'supports'        => array(
						'html'  => false,
						'align' => array( 'wide', 'full' ),
					),
				)
			);
			$data[ $slug ] = array(
				'title' => $element['title'],
				'icon'  => $element['icon'],
				'atts'  => array_map(
					static function ( $spec ): array {
						return array(
							'default' => (string) $spec[1],
							'label'   => (string) $spec[2],
						);
					},
					(array) $element['atts']
				),
			);
		}
		wp_add_inline_script( 'zinn-chat-blocks', 'window.zinnChatBlocks = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Elementor widgets.
	 *
	 * @param object $manager Elementor widgets manager.
	 * @return void
	 */
	public static function elementor( $manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) || ! method_exists( $manager, 'register' ) ) {
			return;
		}
		require_once __DIR__ . '/builders/class-elementor-widget.php';
		foreach ( self::elements() as $slug => $element ) {
			$manager->register( new Builders\Elementor_Widget( array(), array( 'zinn_chat_element' => $slug ) ) );
		}
	}

	/**
	 * Beaver Builder modules.
	 *
	 * @return void
	 */
	public static function beaver(): void {
		if ( ! class_exists( '\FLBuilder' ) || ! class_exists( '\FLBuilderModule' ) ) {
			return;
		}
		require_once __DIR__ . '/builders/class-beaver-module.php';
		Builders\Beaver_Module::register_all();
	}

	/**
	 * Bricks elements.
	 *
	 * @return void
	 */
	public static function bricks(): void {
		if ( ! class_exists( '\Bricks\Elements' ) || ! class_exists( '\Bricks\Element' ) ) {
			return;
		}
		require_once __DIR__ . '/builders/class-bricks-element.php';
		$map = array(
			'support'     => Builders\Bricks_Support::class,
			'ticket-form' => Builders\Bricks_Ticket_Form::class,
			'my-tickets'  => Builders\Bricks_My_Tickets::class,
			'chat-button' => Builders\Bricks_Chat_Button::class,
		);
		foreach ( $map as $slug => $class ) {
			$file = Builders\Bricks_Element::file_for( $slug );
			if ( '' !== $file ) {
				\Bricks\Elements::register_element( $file, 'zinn-chat-' . $slug, $class );
			}
		}
	}

	/**
	 * Divi modules.
	 *
	 * @return void
	 */
	public static function divi(): void {
		if ( ! class_exists( '\ET_Builder_Module' ) ) {
			return;
		}
		require_once __DIR__ . '/builders/class-divi-module.php';
		foreach ( array_keys( self::elements() ) as $slug ) {
			Builders\Divi_Module::make( $slug );
		}
	}
}
