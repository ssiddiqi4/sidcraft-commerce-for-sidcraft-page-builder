<?php
/**
 * Event List Unit: upcoming (or past) events as cards or a list.
 *
 * Shortcode: [sidccofo_event_list limit="6" layout="grid" category="music"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Events\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Class Event_List
 */
class Event_List extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-event-list';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Event List', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'list-view';
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'events', 'tickets', 'upcoming', 'list', 'grid' );
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
			'when'         => array(
				'type'    => 'select',
				'label'   => __( 'Events', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => 'upcoming',
				'options' => array(
					'upcoming' => __( 'Upcoming', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'past'     => __( 'Past', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'all'      => __( 'All', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
			),
			'category'     => array(
				'type'    => 'text',
				'label'   => __( 'Category slugs (comma-separated, optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => '',
			),
			'limit'        => array(
				'type'    => 'number',
				'label'   => __( 'Number of events', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => 6,
				'min'     => 1,
				'max'     => 100,
			),
			'layout'       => array(
				'type'    => 'select',
				'label'   => __( 'Layout', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => 'grid',
				'options' => array(
					'grid' => __( 'Cards', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'list' => __( 'List', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
			),
			'columns'      => array(
				'type'    => 'number',
				'label'   => __( 'Columns (cards)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => 3,
				'min'     => 1,
				'max'     => 4,
			),
			'show_image'   => array(
				'type'    => 'switch',
				'label'   => __( 'Show image', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
			'show_excerpt' => array(
				'type'    => 'switch',
				'label'   => __( 'Show summary', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
			'show_price'   => array(
				'type'    => 'switch',
				'label'   => __( 'Show price', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
			'button_text'  => array(
				'type'    => 'text',
				'label'   => __( 'Button text', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Get tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
			'empty_text'   => array(
				'type'    => 'text',
				'label'   => __( 'Text when there are no events', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'No upcoming events right now. Check back soon!', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		$events = Events::query(
			array(
				'when'     => $settings['when'],
				'category' => $settings['category'],
				'limit'    => (int) $settings['limit'],
			)
		);
		return $this->template(
			'events/event-list.php',
			array(
				'events'   => $events,
				'settings' => $settings,
			)
		);
	}
}
