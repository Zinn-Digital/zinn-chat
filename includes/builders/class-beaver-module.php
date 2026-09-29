<?php
/**
 * Zinn® Chat elements as Beaver Builder modules.
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
 * A module whose element is chosen by its class (Beaver identifies a module by its class name).
 */
abstract class Beaver_Module extends \FLBuilderModule {

	/** Element slug. */
	protected const ELEMENT = '';

	/** Constructor. */
	public function __construct() {
		$element = Blocks::elements()[ static::ELEMENT ] ?? array( 'title' => '' );
		parent::__construct(
			array(
				'name'            => 'Zinn® Chat: ' . $element['title'],
				'description'     => (string) $element['title'],
				'category'        => __( 'Zinn® Chat', 'zinn-chat' ),
				'dir'             => __DIR__ . '/',
				'url'             => plugins_url( '/', __FILE__ ),
				'partial_refresh' => true,
			)
		);
	}

	/**
	 * Output (Beaver calls this instead of a frontend.php file when it exists).
	 *
	 * @return void
	 */
	public function render_html(): void {
		echo Blocks::render( static::ELEMENT, (array) $this->settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the shortcode escapes its output.
	}

	/**
	 * Register one module per element.
	 *
	 * @return void
	 */
	public static function register_all(): void {
		$map = array(
			'support'     => Beaver_Support::class,
			'ticket-form' => Beaver_Ticket_Form::class,
			'my-tickets'  => Beaver_My_Tickets::class,
			'chat-button' => Beaver_Chat_Button::class,
		);
		foreach ( $map as $slug => $class ) {
			$fields = array();
			foreach ( (array) ( Blocks::elements()[ $slug ]['atts'] ?? array() ) as $name => $spec ) {
				$fields[ $name ] = array(
					'type'    => 'text',
					'label'   => (string) $spec[2],
					'default' => (string) $spec[1],
				);
			}
			\FLBuilder::register_module(
				$class,
				array(
					'general' => array(
						'title'    => __( 'Content', 'zinn-chat' ),
						'sections' => array(
							'general' => array(
								'title'  => '',
								'fields' => $fields,
							),
						),
					),
				)
			);
		}
	}
}

/** Support page. */
final class Beaver_Support extends Beaver_Module {
	protected const ELEMENT = 'support';
}

/** Ticket form. */
final class Beaver_Ticket_Form extends Beaver_Module {
	protected const ELEMENT = 'ticket-form';
}

/** My tickets. */
final class Beaver_My_Tickets extends Beaver_Module {
	protected const ELEMENT = 'my-tickets';
}

/** Chat button. */
final class Beaver_Chat_Button extends Beaver_Module {
	protected const ELEMENT = 'chat-button';
}
