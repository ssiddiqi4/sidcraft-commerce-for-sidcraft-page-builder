<?php
/**
 * Event Tickets Unit: every ticket type with its price, availability and a
 * quantity picker; adds them to the cart in one go.
 *
 * Shortcode: [sidccofo_event_tickets event_id="123"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Events\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Class Event_Tickets
 */
class Event_Tickets extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-event-tickets';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Event Tickets', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'tickets-alt';
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'event', 'tickets', 'register', 'rsvp', 'buy' );
	}

	/** {@inheritDoc} */
	public function get_assets() {
		return array(
			'styles'  => array( 'sidccofo-commerce', 'sidccofo-events' ),
			'scripts' => array( 'sidccofo-cart', 'sidccofo-events' ),
		);
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'event_id'    => array(
				'type'    => 'number',
				'label'   => __( 'Event ID (0 = the event being viewed)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => 0,
				'min'     => 0,
			),
			'heading'     => array(
				'type'    => 'text',
				'label'   => __( 'Heading', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
			'button_text' => array(
				'type'    => 'text',
				'label'   => __( 'Button text', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Get tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		$event = Events::resolve( $settings );
		if ( ! $event ) {
			return current_user_can( 'edit_posts' ) ? '<p class="sc-empty">' . esc_html__( 'Choose an event (Event ID) or place this on an event page.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>' : '';
		}
		return $this->template(
			'events/ticket-selector.php',
			array(
				'event'    => $event,
				'settings' => $settings,
			)
		);
	}
}
