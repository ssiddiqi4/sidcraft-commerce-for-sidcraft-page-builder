<?php
/**
 * Ticket lifecycle and the public ticket REST routes.
 *
 *   - Paid order (Processing, Completed or any status marked paid): one
 *     ticket per seat, with the attendee details from checkout, then the
 *     tickets email (PDF + calendar file) and the optional WhatsApp message.
 *   - Cancelled or refunded: its tickets are cancelled (and restored if the
 *     order is reopened). A partial refund that names ticket quantities
 *     cancels that many tickets.
 *   - Hourly: reminder emails before events; old unclaimed uploads removed.
 *
 * REST (namespace sidcraft-commerce/v1):
 *   POST events/add      { event_id, tickets: { ticket type id: quantity } }
 *   POST events/checkin  { code, event_id?, mode: checkin|undo|lookup }   (staff)
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Fragments;
use SidcraftCommerce\Rest;
use function SidcraftCommerce\page_url;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ticketing
 */
class Ticketing {

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		( new Attendees() )->hooks();
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'on_status' ), 6, 3 );
		add_action( 'sidcraft_commerce_event_holds_changed', array( $this, 'on_holds_changed' ), 10, 3 );
		add_action( 'sidcraft_commerce_order_confirmation_after', array( $this, 'confirmation' ) );
		add_action( 'sidcraft_commerce_cleanup', array( $this, 'hourly' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/*
	------------------------------------------------------------------
	 * Orders
	 * ----------------------------------------------------------------
	 */

	/**
	 * Issue, cancel or restore tickets as the order moves.
	 *
	 * @param Order  $order      Order.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 */
	public function on_status( $order, $old_status, $new_status ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		if ( ! $order instanceof Order ) {
			return;
		}
		if ( in_array( $new_status, Post_Types::paid_statuses(), true ) ) {
			if ( get_post_meta( $order->get_id(), '_sidccofo_ev_issued', true ) ) {
				$this->restore( $order );
			} else {
				self::issue( $order );
			}
		} elseif ( in_array( $new_status, array( 'sidccofo-cancelled', 'sidccofo-refunded' ), true ) ) {
			$this->cancel( $order, 'sidccofo-refunded' === $new_status ? __( 'Order refunded.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Order cancelled.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
	}

	/**
	 * Create the tickets an order bought (once) and email them.
	 *
	 * @param Order $order Order.
	 * @param bool  $email Send the tickets email.
	 * @return Ticket[]
	 */
	public static function issue( Order $order, $email = true ) {
		if ( get_post_meta( $order->get_id(), '_sidccofo_ev_issued', true ) ) {
			return array();
		}
		// Claim first so a webhook and the return page can't both issue.
		if ( ! add_post_meta( $order->get_id(), '_sidccofo_ev_issued', 'pending', true ) ) {
			return array();
		}
		$attendees = Attendees::for_order( $order->get_id() );
		$held      = $order->quantities_held();
		$issued    = array();
		$tickets   = array();
		$counter   = array();

		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item['product_id'];
			if ( ! Placeholders::is_placeholder( $pid ) ) {
				continue;
			}
			$event = Event::get( Placeholders::event_id( $pid ) );
			if ( ! $event ) {
				continue;
			}
			$type_id = (string) get_post_meta( $pid, Placeholders::TYPE, true );
			$type    = $event->get_ticket_type( $type_id );
			$qty     = (int) $item['quantity'];
			// Never more than the order still holds (partial refunds before payment).
			if ( isset( $held[ $pid ] ) ) {
				$qty = min( $qty, max( 0, $held[ $pid ] - ( $counter[ $pid ] ?? 0 ) ) );
			}
			for ( $i = 0; $i < $qty; $i++ ) {
				$n      = $counter[ $pid ] ?? 0;
				$person = $attendees[ $pid ][ $n ] ?? array();
				$ticket = Ticket::create(
					array(
						'event'         => $event->get_id(),
						'type'          => $type_id,
						'type_name'     => $type ? $type['name'] : (string) $item['name'],
						'attendee_type' => $type ? $type['attendee_type'] : '',
						'product'       => $pid,
						'order'         => $order->get_id(),
						'name'          => (string) ( $person['name'] ?? '' ),
						'email'         => (string) ( $person['email'] ?? '' ),
						'phone'         => (string) ( $person['phone'] ?? '' ),
						'fields'        => Attendees::rows( $event, (array) ( $person['fields'] ?? array() ) ),
					)
				);
				$counter[ $pid ] = $n + 1;
				if ( is_wp_error( $ticket ) ) {
					$order->add_note( 'Ticket: ' . $ticket->get_error_message() );
					continue;
				}
				/* translators: %d: order number. */
				$ticket->log( sprintf( __( 'Issued for order #%d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
				$issued[]  = $ticket->get_id();
				$tickets[] = $ticket;
			}
		}

		if ( ! $issued ) {
			delete_post_meta( $order->get_id(), '_sidccofo_ev_issued' );
			return array();
		}
		update_post_meta( $order->get_id(), '_sidccofo_ev_issued', $issued );
		/* translators: %d: number of tickets. */
		$order->add_note( sprintf( _n( '%d ticket issued.', '%d tickets issued.', count( $issued ), 'sidcraft-commerce-for-sidcraft-page-builder' ), count( $issued ) ) );

		if ( $email ) {
			Mailer::send_order( $order, $tickets );
		}

		/**
		 * Fires after an order's tickets are issued.
		 *
		 * @param Order    $order   Order.
		 * @param Ticket[] $tickets Tickets.
		 */
		do_action( 'sidcraft_commerce_tickets_issued', $order, $tickets );
		return $tickets;
	}

	/**
	 * Cancel an order's tickets.
	 *
	 * @param Order  $order  Order.
	 * @param string $reason Note.
	 */
	protected function cancel( Order $order, $reason ) {
		$count = 0;
		foreach ( Ticket::for_order( $order->get_id() ) as $ticket ) {
			if ( 'cancelled' !== $ticket->get_status() ) {
				$ticket->cancel( $reason );
				update_post_meta( $ticket->get_id(), '_sidccofo_tk_cancelled_by_order', 1 );
				++$count;
			}
		}
		if ( $count ) {
			/* translators: %d: number of tickets. */
			$order->add_note( sprintf( _n( '%d ticket cancelled.', '%d tickets cancelled.', $count, 'sidcraft-commerce-for-sidcraft-page-builder' ), $count ) );
		}
	}

	/**
	 * Re-validate tickets cancelled with the order (order paid again).
	 *
	 * @param Order $order Order.
	 */
	protected function restore( Order $order ) {
		$count = 0;
		foreach ( Ticket::for_order( $order->get_id() ) as $ticket ) {
			if ( 'cancelled' === $ticket->get_status() && get_post_meta( $ticket->get_id(), '_sidccofo_tk_cancelled_by_order', true ) ) {
				$ticket->restore( __( 'Order reopened.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
				delete_post_meta( $ticket->get_id(), '_sidccofo_tk_cancelled_by_order' );
				++$count;
			}
		}
		if ( $count ) {
			/* translators: %d: number of tickets. */
			$order->add_note( sprintf( _n( '%d ticket is valid again.', '%d tickets are valid again.', $count, 'sidcraft-commerce-for-sidcraft-page-builder' ), $count ) );
		}
	}

	/**
	 * Partial refund of ticket quantities: cancel that many tickets
	 * (not-yet-checked-in ones first, newest first).
	 *
	 * @param Order $order  Order.
	 * @param array $now    product ID => seats now.
	 * @param array $before product ID => seats before.
	 */
	public function on_holds_changed( $order, $now, $before ) {
		if ( ! $order instanceof Order || ! $order->is_paid() || ! get_post_meta( $order->get_id(), '_sidccofo_ev_issued', true ) ) {
			return;
		}
		foreach ( $before as $pid => $qty ) {
			$drop = (int) $qty - (int) ( $now[ $pid ] ?? 0 );
			if ( $drop < 1 ) {
				continue;
			}
			$tickets = array_reverse(
				array_filter(
					Ticket::for_order( $order->get_id() ),
					static function ( Ticket $t ) use ( $pid ) {
						return (int) $t->meta( 'product' ) === (int) $pid && 'cancelled' !== $t->get_status();
					}
				)
			);
			usort(
				$tickets,
				static function ( Ticket $a, Ticket $b ) {
					return ( 'checked_in' === $a->get_status() ) <=> ( 'checked_in' === $b->get_status() );
				}
			);
			foreach ( array_slice( $tickets, 0, $drop ) as $ticket ) {
				$ticket->cancel( __( 'Refunded.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			}
		}
	}

	/**
	 * Tickets on the order-received page.
	 *
	 * @param Order $order Order.
	 */
	public function confirmation( $order ) {
		if ( ! $order instanceof Order ) {
			return;
		}
		$has = false;
		foreach ( $order->get_items() as $item ) {
			if ( Placeholders::is_placeholder( (int) $item['product_id'] ) ) {
				$has = true;
				break;
			}
		}
		if ( ! $has ) {
			return;
		}
		\SidcraftCommerce\Plugin::instance()->assets->require_assets( array( 'styles' => array( 'sidccofo-events' ) ) );
		$html = Events::template(
			'events/order-tickets.php',
			array(
				'order'   => $order,
				'tickets' => Ticket::for_order( $order->get_id() ),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
	}

	/**
	 * Signed link to a PDF with every ticket of an order.
	 *
	 * @param Order $order Order.
	 * @return string
	 */
	public static function order_pdf_url( Order $order ) {
		return add_query_arg(
			array(
				'sidccofo_tickets_pdf' => $order->get_id(),
				'key'                  => $order->get_key(),
			),
			home_url( '/' )
		);
	}

	/*
	------------------------------------------------------------------
	 * Hourly jobs
	 * ----------------------------------------------------------------
	 */

	/**
	 * Reminders and upload cleanup.
	 */
	public function hourly() {
		$hours = (float) setting( 'ev_reminder_hours', 24 );
		if ( $hours > 0 ) {
			$now    = time();
			$events = Events::query(
				array(
					'when'  => 'upcoming',
					'limit' => 50,
					'to'    => wp_date( 'Y-m-d H:i', (int) ( $now + $hours * HOUR_IN_SECONDS ) ),
				)
			);
			foreach ( $events as $event ) {
				$start = $event->get_start();
				if ( $start <= $now || (int) $event->meta( 'reminded' ) === $start ) {
					continue;
				}
				update_post_meta( $event->get_id(), '_sidccofo_ev_reminded', $start );
				Mailer::send_reminders( $event );
			}
		}
		if ( ! get_transient( 'sidccofo_ev_upload_cleanup' ) ) {
			set_transient( 'sidccofo_ev_upload_cleanup', 1, DAY_IN_SECONDS );
			Attendees::cleanup();
		}
	}

	/*
	------------------------------------------------------------------
	 * REST
	 * ----------------------------------------------------------------
	 */

	/**
	 * Routes.
	 */
	public function routes() {
		register_rest_route(
			Rest::NS,
			'/events/add',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // Cart actions are per-visitor and public.
				'callback'            => array( $this, 'rest_add' ),
				'args'                => array(
					'event_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'tickets'  => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			Rest::NS,
			'/events/checkin',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( Events::class, 'user_can_check_in' ),
				'callback'            => array( $this, 'rest_checkin' ),
				'args'                => array(
					'code'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'event_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'mode'     => array(
						'type'    => 'string',
						'default' => 'checkin',
						'enum'    => array( 'checkin', 'undo', 'lookup' ),
					),
				),
			)
		);
	}

	/**
	 * POST events/add: add several ticket types at once.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_add( \WP_REST_Request $request ) {
		$event = Event::get( (int) $request['event_id'] );
		if ( ! $event || ! $event->is_published() ) {
			return new \WP_Error( 'sidccofo_event_missing', __( 'This event is not available.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
		}
		$wanted = array();
		foreach ( (array) $request['tickets'] as $type_id => $qty ) {
			$type = $event->get_ticket_type( sanitize_key( (string) $type_id ) );
			$qty  = max( 0, min( 500, (int) $qty ) );
			if ( $type && $qty > 0 && $type['product_id'] ) {
				$wanted[ $type['product_id'] ] = $qty;
			}
		}
		if ( ! $wanted ) {
			return new \WP_Error( 'sidccofo_event_none', __( 'Choose at least one ticket.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}

		$cart  = Cart::instance();
		$total = 0;
		foreach ( $wanted as $pid => $qty ) {
			$check = $cart->validate( $pid, $cart->product_quantity( $pid ) + $qty );
			if ( is_wp_error( $check ) ) {
				$check->add_data( array( 'status' => 400 ) );
				return $check;
			}
			$total += $qty;
		}
		// Event-wide seats across all types being added together.
		$left = $event->get_remaining();
		if ( null !== $left ) {
			$in_cart = $total;
			foreach ( $event->get_ticket_types() as $type ) {
				if ( $type['product_id'] ) {
					$in_cart += $cart->product_quantity( $type['product_id'] );
				}
			}
			if ( $in_cart > $left ) {
				/* translators: %d: seats left. */
				return new \WP_Error( 'sidccofo_event_full', sprintf( _n( 'Only %d seat is left.', 'Only %d seats are left.', $left, 'sidcraft-commerce-for-sidcraft-page-builder' ), $left ), array( 'status' => 400 ) );
			}
		}
		foreach ( $wanted as $pid => $qty ) {
			$result = $cart->add( $pid, $qty );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 400 ) );
				return $result;
			}
		}

		$after = (string) setting( 'ev_after_add', 'checkout' );
		return rest_ensure_response(
			array(
				'fragments' => Fragments::collect( '' ),
				'cart_hash' => $cart->get_hash(),
				'count'     => $cart->get_count(),
				'total'     => $cart->get_total(),
				/* translators: %d: number of tickets. */
				'message'   => sprintf( _n( '%d ticket added to your cart.', '%d tickets added to your cart.', $total, 'sidcraft-commerce-for-sidcraft-page-builder' ), $total ),
				'redirect'  => 'stay' === $after ? '' : page_url( 'cart' === $after ? 'cart' : 'checkout' ),
			)
		);
	}

	/**
	 * Ticket data for the check-in screen.
	 *
	 * @param Ticket $ticket Ticket.
	 * @return array
	 */
	public static function ticket_data( Ticket $ticket ) {
		$event = $ticket->get_event();
		$order = $ticket->get_order();
		return array(
			'id'         => $ticket->get_id(),
			'code'       => $ticket->get_code(),
			'status'     => $ticket->get_status(),
			'statusText' => $ticket->get_status_label(),
			'name'       => $ticket->get_attendee_name(),
			'email'      => $ticket->get_attendee_email(),
			'type'       => $ticket->get_type_name(),
			'event'      => $event ? $event->get_name() : '',
			'eventId'    => $event ? $event->get_id() : 0,
			'date'       => $event ? $event->get_date_label() : '',
			'order'      => $order ? $order->get_id() : 0,
			'checkedIn'  => $ticket->get_checked_in() ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ticket->get_checked_in() ) : '',
			'fields'     => array_map(
				static function ( $row ) {
					return array(
						'label' => (string) $row['label'],
						'value' => (string) $row['value'],
					);
				},
				$ticket->get_fields()
			),
		);
	}

	/**
	 * POST events/checkin.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_checkin( \WP_REST_Request $request ) {
		$ticket = Ticket::find( (string) $request['code'] );
		if ( ! $ticket ) {
			return rest_ensure_response(
				array(
					'result'  => 'invalid',
					'message' => __( 'No ticket with this code.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				)
			);
		}
		$data     = self::ticket_data( $ticket );
		$event_id = (int) $request['event_id'];
		if ( $event_id && $data['eventId'] !== $event_id ) {
			return rest_ensure_response(
				array(
					'result'  => 'wrong_event',
					/* translators: %s: event name. */
					'message' => sprintf( __( 'This ticket is for another event: %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $data['event'] ),
					'ticket'  => $data,
				)
			);
		}
		$mode = (string) $request['mode'];
		if ( 'lookup' === $mode ) {
			return rest_ensure_response(
				array(
					'result'  => $ticket->get_status(),
					'message' => $ticket->get_status_label(),
					'ticket'  => $data,
				)
			);
		}
		if ( 'undo' === $mode ) {
			$ticket->undo_check_in();
			return rest_ensure_response(
				array(
					'result'  => 'undone',
					'message' => __( 'Check-in undone.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'ticket'  => self::ticket_data( $ticket ),
				)
			);
		}
		$result   = $ticket->check_in();
		$messages = array(
			'ok'        => __( 'Checked in. Welcome!', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			/* translators: %s: date and time. */
			'already'   => sprintf( __( 'Already checked in (%s).', 'sidcraft-commerce-for-sidcraft-page-builder' ), $data['checkedIn'] ),
			'cancelled' => __( 'This ticket was cancelled. Do not admit.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
		return rest_ensure_response(
			array(
				'result'  => $result,
				'message' => $messages[ $result ],
				'ticket'  => self::ticket_data( $ticket ),
			)
		);
	}
}
