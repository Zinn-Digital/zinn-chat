<?php
/**
 * Zinn® Chat elements as Divi modules.
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
 * A Divi module per element. The Visual Builder shows it as a placeholder and the page renders it
 * (`vb_support: partial`), which needs no JavaScript build.
 */
abstract class Divi_Module extends \ET_Builder_Module {

	/** Element slug. */
	protected const ELEMENT = '';

	/**
	 * Divi VB support level.
	 *
	 * @var string
	 */
	public $vb_support = 'partial';

	/**
	 * Instantiate the module for an element (Divi registers a module when it is constructed).
	 *
	 * @param string $slug Element slug.
	 * @return void
	 */
	public static function make( string $slug ): void {
		$map = array(
			'support'     => Divi_Support::class,
			'ticket-form' => Divi_Ticket_Form::class,
			'my-tickets'  => Divi_My_Tickets::class,
			'chat-button' => Divi_Chat_Button::class,
		);
		if ( isset( $map[ $slug ] ) ) {
			new $map[ $slug ]();
		}
	}

	/** {@inheritDoc} */
	public function init() {
		$this->slug = 'zinn_chat_' . str_replace( '-', '_', static::ELEMENT );
		$this->name = 'Zinn® Chat: ' . ( Blocks::elements()[ static::ELEMENT ]['title'] ?? '' );
	}

	/** {@inheritDoc} */
	public function get_fields() {
		$fields = array();
		foreach ( (array) ( Blocks::elements()[ static::ELEMENT ]['atts'] ?? array() ) as $name => $spec ) {
			$fields[ $name ] = array(
				'label'       => (string) $spec[2],
				'type'        => 'text',
				'default'     => (string) $spec[1],
				'toggle_slug' => 'main_content',
			);
		}
		return $fields;
	}

	/**
	 * Render.
	 *
	 * @param array<string, mixed> $attrs       Divi attributes (unused: props are read).
	 * @param string|null          $content     Inner content.
	 * @param string               $render_slug Module slug.
	 * @return string
	 */
	public function render( $attrs, $content = null, $render_slug = '' ) {
		unset( $attrs, $content, $render_slug );
		return Blocks::render( static::ELEMENT, (array) $this->props );
	}
}

/** Support page. */
final class Divi_Support extends Divi_Module {
	protected const ELEMENT = 'support';
}

/** Ticket form. */
final class Divi_Ticket_Form extends Divi_Module {
	protected const ELEMENT = 'ticket-form';
}

/** My tickets. */
final class Divi_My_Tickets extends Divi_Module {
	protected const ELEMENT = 'my-tickets';
}

/** Chat button. */
final class Divi_Chat_Button extends Divi_Module {
	protected const ELEMENT = 'chat-button';
}
