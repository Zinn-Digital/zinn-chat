<?php
/**
 * Zinn® Chat elements as Elementor widgets.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Builders;

use ZinnDigital\ZinnChat\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One widget class, one instance per element; the element slug travels in Elementor's own
 * default args, which Elementor passes back whenever it recreates the widget to render it.
 */
final class Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * The element slug.
	 *
	 * @return string
	 */
	private function element(): string {
		$args = $this->get_default_args();
		return (string) ( $args['zinn_chat_element'] ?? 'ticket-form' );
	}

	/**
	 * Element definition.
	 *
	 * @return array<string, mixed>
	 */
	private function definition(): array {
		return Blocks::elements()[ $this->element() ] ?? array(
			'title' => '',
			'atts'  => array(),
		);
	}

	/** {@inheritDoc} */
	public function get_name() {
		return 'zinn-chat-' . $this->element();
	}

	/** {@inheritDoc} */
	public function get_title() {
		return 'Zinn® Chat: ' . $this->definition()['title'];
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'eicon-comments';
	}

	/** {@inheritDoc} */
	public function get_categories() {
		return array( 'general' );
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'chat', 'support', 'ticket', 'help' );
	}

	/** {@inheritDoc} */
	protected function register_controls() {
		$atts = (array) $this->definition()['atts'];
		if ( ! $atts ) {
			return;
		}
		$this->start_controls_section( 'content', array( 'label' => __( 'Content', 'zinn-chat' ) ) );
		foreach ( $atts as $name => $spec ) {
			$this->add_control(
				$name,
				array(
					'label'   => (string) $spec[2],
					'type'    => \Elementor\Controls_Manager::TEXT,
					'default' => (string) $spec[1],
				)
			);
		}
		$this->end_controls_section();
	}

	/** {@inheritDoc} */
	protected function render() {
		echo Blocks::render( $this->element(), (array) $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the shortcode escapes its output.
	}
}
