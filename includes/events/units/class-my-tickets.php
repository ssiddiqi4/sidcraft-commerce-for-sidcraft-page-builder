<?php
/**
 * My Tickets Unit: the logged-in customer's tickets from all their orders.
 *
 * Shortcode: [sidccofo_my_tickets] or [sidccofo_my_tickets show_past="1"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Events\Ticket;

defined( 'ABSPATH' ) || exit;

/**
 * Class My_Tickets
 */
class My_Tickets extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-my-tickets';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'My Tickets', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'id-alt';
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'tickets', 'account', 'events', 'orders' );
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
			'heading'   => array(
				'type'    => 'text',
				'label'   => __( 'Heading', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'My tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
			'show_past' => array(
				'type'    => 'switch',
				'label'   => __( 'Also list past events', 'sidcraft-commerce-for-sidcraft-page-builder' ),
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
		$tickets = array();
		if ( is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$seen  = array();
			$order_ids = get_posts(
				array(
					'post_type'      => Post_Types::ORDER,
					'post_status'    => 'any',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One customer's orders, bounded.
						array(
							'key'   => '_sidccofo_customer_id',
							'value' => (int) $user->ID,
						),
						array(
							'key'     => '_sidccofo_ev_issued',
							'compare' => 'EXISTS',
						),
					),
				)
			);
			$found = array();
			foreach ( $order_ids as $order_id ) {
				$found = array_merge( $found, Ticket::for_order( (int) $order_id ) );
			}
			// Tickets bought for this person by someone else.
			if ( is_email( $user->user_email ) ) {
				$ids = get_posts(
					array(
						'post_type'      => \SidcraftCommerce\Events\Events::TICKET,
						'post_status'    => 'any',
						'posts_per_page' => 200,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_key'       => '_sidccofo_tk_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value'     => $user->user_email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);
				$found = array_merge( $found, array_filter( array_map( array( Ticket::class, 'get' ), $ids ) ) );
			}
			foreach ( $found as $ticket ) {
				$event = $ticket->get_event();
				if ( isset( $seen[ $ticket->get_id() ] ) || ! $event || ( ! $settings['show_past'] && $event->is_past() ) ) {
					continue;
				}
				$seen[ $ticket->get_id() ] = true;
				$tickets[]                 = $ticket;
			}
			usort(
				$tickets,
				static function ( $a, $b ) {
					return $a->get_event()->get_start() <=> $b->get_event()->get_start();
				}
			);
		}
		return $this->template(
			'events/my-tickets.php',
			array(
				'tickets'  => $tickets,
				'settings' => $settings,
			)
		);
	}
}
