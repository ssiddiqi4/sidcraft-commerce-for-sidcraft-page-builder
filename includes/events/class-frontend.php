<?php
/**
 * Event pages and ticket endpoints.
 *
 *   - Single event pages get the date/venue summary above the description
 *     and the ticket selector and map below it. Each part the layout already
 *     has as a Unit is skipped, so a layout with only Event details still
 *     gets the ticket selector ("Hide the automatic details and tickets"
 *     turns all of it off).
 *   - ?sidccofo_ticket=CODE&k=SIG          ticket page (also what the QR holds;
 *                                           staff who open it can check in)
 *   - ?sidccofo_ticket_pdf=CODE&k=SIG      PDF of one ticket
 *   - ?sidccofo_ticket_qr=CODE&k=SIG       QR image (PNG) for emails
 *   - ?sidccofo_tickets_pdf=ORDER&key=KEY  PDF of every ticket in an order
 *   - ?sidccofo_event_ics=ID               calendar file for one event
 *   - ?sidccofo_events_ics=1[&cat=slug]    subscription feed of all events
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Frontend
 */
class Frontend {

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'template_redirect', array( $this, 'endpoints' ), 1 );
		add_filter( 'the_content', array( $this, 'single_content' ), 25 ); // After Sidcraft Page Builder renders its layout (20).
		add_filter( 'sidcraft_commerce_detected_units', array( $this, 'detected' ) );
		add_action( 'wp_head', array( $this, 'schema' ) );
	}

	/**
	 * Event Units added automatically to this event's page: details, tickets
	 * and map, minus any the layout already places itself.
	 *
	 * @param int $post_id Event.
	 * @return string[]
	 */
	protected function auto_units( $post_id ) {
		if ( get_post_meta( $post_id, '_sidccofo_ev_hide_auto', true ) || ! apply_filters( 'sidcraft_commerce_event_auto_content', true, $post_id ) ) {
			return array();
		}
		$placed = Plugin::instance()->assets->units_in_post( $post_id );
		$auto   = array_diff( array( 'sidccofo-event-details', 'sidccofo-event-tickets', 'sidccofo-event-map' ), $placed );
		// The map belongs to the automatic layout: a designed details block keeps its own map choice.
		if ( in_array( 'sidccofo-event-details', $placed, true ) ) {
			$auto = array_diff( $auto, array( 'sidccofo-event-map' ) );
		}
		return array_values( $auto );
	}

	/**
	 * Load the event Units' assets in <head> on event pages.
	 *
	 * @param string[] $found Unit names.
	 * @return string[]
	 */
	public function detected( $found ) {
		if ( is_singular( Events::EVENT ) ) {
			$found = array_merge( $found, array_diff( $this->auto_units( get_queried_object_id() ), array( 'sidccofo-event-map' ) ) );
		}
		return $found;
	}

	/**
	 * Add event details and tickets around the event description.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function single_content( $content ) {
		if ( ! is_singular( Events::EVENT ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		$auto = $this->auto_units( get_the_ID() );
		if ( ! $auto ) {
			return $content;
		}
		// Once per page: a theme template's Post Content Unit runs this filter
		// again inside the outer content.
		static $busy = false;
		static $done = array();
		if ( $busy || isset( $done[ get_the_ID() ] ) ) {
			return $content;
		}
		$busy  = true;
		$units = Plugin::instance()->units;
		$id    = get_the_ID();
		$top   = in_array( 'sidccofo-event-details', $auto, true ) && $units->get( 'sidccofo-event-details' ) ? $units->get( 'sidccofo-event-details' )->render(
			array(
				'event_id' => $id,
				'show_map' => '0',
			)
		) : '';
		$tix   = in_array( 'sidccofo-event-tickets', $auto, true ) && $units->get( 'sidccofo-event-tickets' ) ? $units->get( 'sidccofo-event-tickets' )->render( array( 'event_id' => $id ) ) : '';
		$map   = '';
		$event = Event::get( $id );
		if ( in_array( 'sidccofo-event-map', $auto, true ) && $event && $event->shows_map() && $units->get( 'sidccofo-event-map' ) ) {
			$map = $units->get( 'sidccofo-event-map' )->render( array( 'event_id' => $id ) );
		}
		$busy        = false;
		$done[ $id ] = true;
		return $top . $content . $tix . $map;
	}

	/**
	 * schema.org Event data on event pages.
	 */
	public function schema() {
		if ( ! is_singular( Events::EVENT ) ) {
			return;
		}
		$event = Event::get( get_queried_object_id() );
		if ( ! $event || ! $event->get_start() ) {
			return;
		}
		$data = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => wp_strip_all_tags( $event->get_name() ),
			'startDate'           => wp_date( 'c', $event->get_start() ),
			'endDate'             => wp_date( 'c', $event->get_end() ),
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => $event->is_online() ? ( $event->has_location() ? 'https://schema.org/MixedEventAttendanceMode' : 'https://schema.org/OnlineEventAttendanceMode' ) : 'https://schema.org/OfflineEventAttendanceMode',
			'description'         => $event->get_summary( 50 ),
			'url'                 => $event->get_permalink(),
		);
		if ( has_post_thumbnail( $event->get_id() ) ) {
			$data['image'] = array( (string) get_the_post_thumbnail_url( $event->get_id(), 'large' ) );
		}
		$location = array();
		if ( $event->has_location() ) {
			$location[] = array(
				'@type'   => 'Place',
				'name'    => '' !== $event->get_venue() ? $event->get_venue() : $event->get_address(),
				'address' => $event->get_address(),
			);
		}
		if ( $event->is_online() ) {
			$location[] = array(
				'@type' => 'VirtualLocation',
				'url'   => $event->get_permalink(),
			);
		}
		if ( $location ) {
			$data['location'] = 1 === count( $location ) ? $location[0] : $location;
		}
		$offers = array();
		foreach ( $event->get_ticket_types() as $type ) {
			if ( '' === trim( $type['price'] ) ) {
				continue;
			}
			$left     = $event->get_type_remaining( $type );
			$open     = '' === $event->type_unavailable_reason( $type );
			$offers[] = array(
				'@type'         => 'Offer',
				'name'          => $type['name'],
				// In the store currency: the same for every visitor and crawler.
				'price'         => (string) \SidcraftCommerce\Currencies::in_base(
					static function () use ( $event, $type ) {
						return Pricing::current( $event, $type )['price'];
					}
				),
				'priceCurrency' => \SidcraftCommerce\base_currency(),
				'availability'  => null !== $left && $left < 1 ? 'https://schema.org/SoldOut' : ( $open ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock' ),
				'url'           => $event->get_permalink(),
			);
		}
		if ( $offers ) {
			$data['offers'] = $offers;
		}
		if ( '' !== $event->get_organizer() ) {
			$data['organizer'] = array(
				'@type' => 'Organization',
				'name'  => $event->get_organizer(),
			);
		}
		echo '<script type="application/ld+json">' . wp_json_encode( apply_filters( 'sidcraft_commerce_event_schema', $data, $event ) ) . "</script>\n";
	}

	/**
	 * Route the query-string endpoints.
	 */
	public function endpoints() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed links; check-in POST is nonce-checked below.
		if ( isset( $_GET['sidccofo_events_ics'] ) ) {
			$this->send( Calendar::feed( isset( $_GET['cat'] ) ? sanitize_title( wp_unslash( $_GET['cat'] ) ) : '' ), 'text/calendar; charset=utf-8', 'events.ics', true );
		}
		if ( isset( $_GET['sidccofo_event_ics'] ) ) {
			$event = Event::get( absint( $_GET['sidccofo_event_ics'] ) );
			if ( ! $event || ( ! $event->is_published() && ! current_user_can( 'edit_post', $event->get_id() ) ) ) {
				$this->not_found();
			}
			$this->send( Calendar::event_ics( $event ), 'text/calendar; charset=utf-8', sanitize_title( $event->get_name() ) . '.ics' );
		}
		if ( isset( $_GET['sidccofo_tickets_pdf'], $_GET['key'] ) ) {
			$order = Order::get( absint( $_GET['sidccofo_tickets_pdf'] ) );
			if ( ! $order || ! $order->key_is_valid( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) ) {
				$this->not_found();
			}
			$tickets = Ticket::for_order( $order->get_id() );
			if ( ! $tickets ) {
				$this->not_found();
			}
			$this->send( Ticket_PDF::render( $tickets ), 'application/pdf', 'tickets-' . $order->get_id() . '.pdf' );
		}
		foreach ( array( 'sidccofo_ticket_pdf', 'sidccofo_ticket_qr', 'sidccofo_ticket' ) as $param ) {
			if ( ! isset( $_GET[ $param ] ) ) {
				continue;
			}
			$code   = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
			$sig    = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
			$ticket = Ticket::verify( $code, $sig ) ? Ticket::find( $code ) : null;
			if ( ! $ticket && Events::user_can_check_in() ) {
				$ticket = Ticket::find( $code ); // Staff may open any code.
			}
			if ( ! $ticket ) {
				$this->not_found();
			}
			if ( 'sidccofo_ticket_pdf' === $param ) {
				$this->send( Ticket_PDF::render( array( $ticket ) ), 'application/pdf', 'ticket-' . strtolower( $ticket->get_code() ) . '.pdf' );
			}
			if ( 'sidccofo_ticket_qr' === $param ) {
				$png = Mailer::code_png( $ticket );
				$this->send( $png, 'image/png', 'ticket.png', false, 'inline' );
			}
			$this->ticket_page( $ticket );
		}
		// phpcs:enable
	}

	/**
	 * Output a file and stop.
	 *
	 * @param string $body        Content.
	 * @param string $type        MIME type.
	 * @param string $filename    File name.
	 * @param bool   $cacheable   Allow short caching (feeds).
	 * @param string $disposition attachment|inline.
	 */
	protected function send( $body, $type, $filename, $cacheable = false, $disposition = 'attachment' ) {
		if ( $cacheable ) {
			header( 'Cache-Control: public, max-age=900' );
		} else {
			nocache_headers();
		}
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file / calendar data.
		exit;
	}

	/**
	 * 404 and stop.
	 */
	protected function not_found() {
		status_header( 404 );
		nocache_headers();
		wp_die( esc_html__( 'This ticket link is not valid.', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_html__( 'Not found', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'response' => 404 ) );
	}

	/**
	 * Standalone ticket page (with check-in for staff).
	 *
	 * @param Ticket $ticket Ticket.
	 */
	protected function ticket_page( Ticket $ticket ) {
		$staff   = Events::user_can_check_in();
		$message = '';
		$result  = '';
		if ( $staff && isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['_sidccofo_checkin'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_checkin'] ) ), 'sidccofo_checkin_' . $ticket->get_id() ) ) {
			$action = isset( $_POST['sidccofo_action'] ) ? sanitize_key( wp_unslash( $_POST['sidccofo_action'] ) ) : 'checkin';
			if ( 'undo' === $action ) {
				$ticket->undo_check_in();
				$result  = 'undone';
				$message = __( 'Check-in undone.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			} else {
				$result   = $ticket->check_in();
				$messages = array(
					'ok'        => __( 'Checked in. Welcome!', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'already'   => __( 'This ticket was already checked in.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'cancelled' => __( 'This ticket was cancelled. Do not admit.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				);
				$message  = $messages[ $result ];
			}
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		$html = Events::template(
			'events/ticket-page.php',
			array(
				'ticket'  => $ticket,
				'event'   => $ticket->get_event(),
				'staff'   => $staff,
				'message' => $message,
				'result'  => $result,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
		exit;
	}
}
