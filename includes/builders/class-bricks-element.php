<?php
/**
 * Zinn® Chat elements as Bricks elements.
 *
 * @package ZinnDigital\ZinnChat
 */

declare( strict_types = 1 );

namespace ZinnDigital\ZinnChat\Builders;

use ZinnDigital\ZinnChat\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- the builder identifies a module by its CLASS, so each element is a one-line subclass; they belong together.

/**
 * Bricks registers an element from a file; each element below is its own class in this file.
 */
abstract class Bricks_Element extends \Bricks\Element {

	/** Element slug. */
	protected const ELEMENT = '';

	/**
	 * Bricks category.
	 *
	 * @var string
	 */
	public $category = 'general';

	/**
	 * Bricks icon.
	 *
	 * @var string
	 */
	public $icon = 'ti-comments';

	/**
	 * The file Bricks loads for an element ('' when unknown).
	 *
	 * @param string $slug Element slug.
	 * @return string
	 */
	public static function file_for( string $slug ): string {
		return isset( Blocks::elements()[ $slug ] ) ? __FILE__ : '';
	}

	/** {@inheritDoc} */
	public function get_label() {
		return 'Zinn® Chat: ' . ( Blocks::elements()[ static::ELEMENT ]['title'] ?? '' );
	}

	/** {@inheritDoc} */
	public function set_controls() {
		foreach ( (array) ( Blocks::elements()[ static::ELEMENT ]['atts'] ?? array() ) as $name => $spec ) {
			$this->controls[ $name ] = array(
				'tab'     => 'content',
				'label'   => (string) $spec[2],
				'type'    => 'text',
				'default' => (string) $spec[1],
			);
		}
	}

	/** {@inheritDoc} */
	public function render() {
		echo '<div ' . $this->render_attributes( '_root' ) . '>' . Blocks::render( static::ELEMENT, (array) $this->settings ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks escapes its attributes; the shortcode escapes its output.
	}
}

/** Support page. */
final class Bricks_Support extends Bricks_Element {
	protected const ELEMENT = 'support';

	/**
	 * Bricks name.
	 *
	 * @var string
	 */
	public $name = 'zinn-chat-support';
}

/** Ticket form. */
final class Bricks_Ticket_Form extends Bricks_Element {
	protected const ELEMENT = 'ticket-form';

	/**
	 * Bricks name.
	 *
	 * @var string
	 */
	public $name = 'zinn-chat-ticket-form';
}

/** My tickets. */
final class Bricks_My_Tickets extends Bricks_Element {
	protected const ELEMENT = 'my-tickets';

	/**
	 * Bricks name.
	 *
	 * @var string
	 */
	public $name = 'zinn-chat-my-tickets';
}

/** Chat button. */
final class Bricks_Chat_Button extends Bricks_Element {
	protected const ELEMENT = 'chat-button';

	/**
	 * Bricks name.
	 *
	 * @var string
	 */
	public $name = 'zinn-chat-chat-button';
}
