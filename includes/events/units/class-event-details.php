<?php
/**
 * Event Details Unit: date and time, venue, online details, organiser and
 * "add to calendar" links.
 *
 * Shortcode: [sidccofo_event_details event_id="123"] (event_id defaults to the current event page)
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Events\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Class Event_Details
 */
class Event_Details extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-event-details';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Event Details', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'clock';
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'event', 'date', 'venue', 'location', 'calendar' );
	}

	/** {@inheritDoc} */
	public function get_assets() {
		return array(
			'styles'  => array( 'sidccofo-commerce', 'sidccofo-events' ),
			'scripts' => array(),
		);
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'event_id'       => array(
				'type'    => 'number',
				'label'   => __( 'Event ID (0 = the event being viewed)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => 0,
				'min'     => 0,
			),
			'show_calendar'  => array(
				'type'    => 'switch',
				'label'   => __( 'Show “Add to calendar” links', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
			'show_organizer' => array(
				'type'    => 'switch',
				'label'   => __( 'Show organiser', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
			'show_map'       => array(
				'type'    => 'switch',
				'label'   => __( 'Show map', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => false,
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
			'events/event-details.php',
			array(
				'event'    => $event,
				'settings' => $settings,
			)
		);
	}
}
