<?php
/**
 * Event Map Unit: interactive map of the venue (Google Maps or
 * OpenStreetMap) with a "Get directions" link.
 *
 * Shortcode: [sidccofo_event_map event_id="123" height="360"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Events\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Class Event_Map
 */
class Event_Map extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-event-map';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Event Map', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'location-alt';
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'event', 'map', 'venue', 'directions', 'google maps' );
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
			'event_id' => array(
				'type'    => 'number',
				'label'   => __( 'Event ID (0 = the event being viewed)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => 0,
				'min'     => 0,
			),
			'height'   => array(
				'type'    => 'number',
				'label'   => __( 'Height (px)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'style',
				'default' => 340,
				'min'     => 160,
				'max'     => 900,
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
		if ( ! $event || ! $event->has_location() || 'none' === \SidcraftCommerce\setting( 'ev_map_provider', 'google' ) ) {
			return '';
		}
		return $this->template(
			'events/event-map.php',
			array(
				'event'  => $event,
				'height' => (int) $settings['height'],
			)
		);
	}
}
