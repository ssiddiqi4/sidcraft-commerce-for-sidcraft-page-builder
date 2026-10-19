<?php
/**
 * Event Calendar Unit: a month grid with previous/next navigation and a
 * "subscribe" link (Google Calendar, Apple Calendar, Outlook).
 *
 * Shortcode: [sidccofo_event_calendar category="music"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Events\Calendar;

defined( 'ABSPATH' ) || exit;

/**
 * Class Event_Calendar
 */
class Event_Calendar extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-event-calendar';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Event Calendar', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'calendar-alt';
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'events', 'calendar', 'month', 'schedule' );
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
			'category'       => array(
				'type'    => 'text',
				'label'   => __( 'Category slugs (comma-separated, optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => '',
			),
			'month'          => array(
				'type'    => 'text',
				'label'   => __( 'Start month (YYYY-MM, empty = this month)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => '',
			),
			'show_subscribe' => array(
				'type'    => 'switch',
				'label'   => __( 'Show “Subscribe” links', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
			'show_list'      => array(
				'type'    => 'switch',
				'label'   => __( 'List the month’s events under the grid', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$month = isset( $_GET['scev_month'] ) ? sanitize_text_field( wp_unslash( $_GET['scev_month'] ) ) : (string) $settings['month'];
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = wp_date( 'Y-m' );
		}
		return $this->template(
			'events/calendar.php',
			array(
				'data'     => Calendar::month( $month, (string) $settings['category'] ),
				'settings' => $settings,
			)
		);
	}
}
