<?php
/**
 * Issued ticket: one sidccofo_ticket post per seat.
 *
 * The post title is the ticket code (e.g. K7QX-M4PZ-9RTW), the slug its
 * lower-case form (fast lookups) and post_parent the event.
 *
 * Meta (prefix _sidccofo_tk_): code, event, type, type_name, attendee_type,
 * product, order, name, email, phone, fields (rows: id, label, type, value,
 * file), status (valid|checked_in|cancelled), checked_in (ts),
 * checked_in_by (user ID), log (array).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use SidcraftCommerce\Data\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ticket
 */
class Ticket {

	/** Alphabet for codes: no 0/O, 1/I/L. */
	const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	/**
	 * Post.
	 *
	 * @var \WP_Post
	 */
	protected $post;

	/**
	 * Get a ticket by post ID.
	 *
	 * @param int $id ID.
	 * @return Ticket|null
	 */
	public static function get( $id ) {
		$post = get_post( absint( $id ) );
		return $post && Events::TICKET === $post->post_type ? new self( $post ) : null;
	}

	/**
	 * Find a ticket by its code (any case, with or without dashes), or by a
	 * scanned ticket URL.
	 *
	 * @param string $code Code or URL.
	 * @return Ticket|null
	 */
	public static function find( $code ) {
		$code = self::normalize( $code );
		if ( '' === $code ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'      => Events::TICKET,
				'post_status'    => 'any',
				'name'           => strtolower( self::format( $code ) ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return $ids ? self::get( (int) $ids[0] ) : null;
	}

	/**
	 * Clean a typed or scanned code: accepts the ticket URL from the QR code.
	 *
	 * @param string $raw Raw.
	 * @return string Upper-case code without dashes.
	 */
	public static function normalize( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '/sidccofo_ticket(?:_pdf|_qr)?=([A-Za-z0-9\-]+)/', $raw, $m ) ) {
			$raw = $m[1];
		}
		return substr( preg_replace( '/[^A-Z0-9]/', '', strtoupper( $raw ) ), 0, 24 );
	}

	/**
	 * Group a code in fours: K7QXM4PZ9RTW → K7QX-M4PZ-9RTW.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function format( $code ) {
		return implode( '-', str_split( self::normalize( $code ), 4 ) );
	}

	/**
	 * New unique code.
	 *
	 * @return string
	 */
	public static function generate_code() {
		do {
			$code = '';
			for ( $i = 0; $i < 12; $i++ ) {
				$code .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
			}
		} while ( self::find( $code ) );
		return self::format( $code );
	}

	/**
	 * Create a ticket.
	 *
	 * @param array $args event, type, type_name, attendee_type, product, order, name, email, phone, fields.
	 * @return Ticket|\WP_Error
	 */
	public static function create( array $args ) {
		$code = self::generate_code();
		$id   = wp_insert_post(
			array(
				'post_type'   => Events::TICKET,
				'post_status' => 'publish',
				'post_title'  => $code,
				'post_name'   => strtolower( $code ),
				'post_parent' => (int) $args['event'],
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$meta = array(
			'code'          => $code,
			'event'         => (int) $args['event'],
			'type'          => (string) ( $args['type'] ?? '' ),
			'type_name'     => (string) ( $args['type_name'] ?? '' ),
			'attendee_type' => (string) ( $args['attendee_type'] ?? '' ),
			'product'       => (int) ( $args['product'] ?? 0 ),
			'order'         => (int) ( $args['order'] ?? 0 ),
			'name'          => (string) ( $args['name'] ?? '' ),
			'email'         => (string) ( $args['email'] ?? '' ),
			'phone'         => (string) ( $args['phone'] ?? '' ),
			'fields'        => (array) ( $args['fields'] ?? array() ),
			'status'        => 'valid',
			'checked_in'    => 0,
			'checked_in_by' => 0,
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, '_sidccofo_tk_' . $key, $value );
		}
		return self::get( $id );
	}

	/**
	 * Constructor.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function __construct( \WP_Post $post ) {
		$this->post = $post;
	}

	/**
	 * Meta.
	 *
	 * @param string $key Key without prefix.
	 * @return mixed
	 */
	public function meta( $key ) {
		return get_post_meta( $this->post->ID, '_sidccofo_tk_' . $key, true );
	}

	/**
	 * ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return (int) $this->post->ID;
	}

	/**
	 * Code.
	 *
	 * @return string
	 */
	public function get_code() {
		return (string) $this->post->post_title;
	}

	/**
	 * Event.
	 *
	 * @return Event|null
	 */
	public function get_event() {
		return Event::get( (int) $this->meta( 'event' ) );
	}

	/**
	 * Order.
	 *
	 * @return Order|null
	 */
	public function get_order() {
		return Order::get( (int) $this->meta( 'order' ) );
	}

	/**
	 * Ticket type name (as bought).
	 *
	 * @return string
	 */
	public function get_type_name() {
		return (string) $this->meta( 'type_name' );
	}

	/**
	 * Attendee name (falls back to the buyer).
	 *
	 * @return string
	 */
	public function get_attendee_name() {
		$name = (string) $this->meta( 'name' );
		if ( '' === $name && $this->get_order() ) {
			$billing = $this->get_order()->get_billing();
			$name    = trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) );
		}
		return $name;
	}

	/**
	 * Attendee email (falls back to the buyer).
	 *
	 * @return string
	 */
	public function get_attendee_email() {
		$email = (string) $this->meta( 'email' );
		if ( '' === $email && $this->get_order() ) {
			$email = $this->get_order()->get_email();
		}
		return $email;
	}

	/**
	 * Attendee phone (falls back to the buyer).
	 *
	 * @return string
	 */
	public function get_attendee_phone() {
		$phone = (string) $this->meta( 'phone' );
		if ( '' === $phone && $this->get_order() ) {
			$phone = (string) ( $this->get_order()->get_billing()['phone'] ?? '' );
		}
		return $phone;
	}

	/**
	 * Custom field answers.
	 *
	 * @return array[] Each: id, label, type, value, file.
	 */
	public function get_fields() {
		$fields = $this->meta( 'fields' );
		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Status: valid | checked_in | cancelled.
	 *
	 * @return string
	 */
	public function get_status() {
		$status = (string) $this->meta( 'status' );
		return in_array( $status, array( 'valid', 'checked_in', 'cancelled' ), true ) ? $status : 'valid';
	}

	/**
	 * Status label.
	 *
	 * @return string
	 */
	public function get_status_label() {
		$labels = array(
			'valid'      => __( 'Valid', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'checked_in' => __( 'Checked in', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'cancelled'  => __( 'Cancelled', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
		return $labels[ $this->get_status() ];
	}

	/**
	 * Check-in time (0 = not checked in).
	 *
	 * @return int
	 */
	public function get_checked_in() {
		return (int) $this->meta( 'checked_in' );
	}

	/**
	 * Add to the ticket's history.
	 *
	 * @param string $note Note.
	 */
	public function log( $note ) {
		$log   = $this->meta( 'log' );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time' => time(),
			'user' => get_current_user_id(),
			'note' => wp_strip_all_tags( (string) $note ),
		);
		update_post_meta( $this->get_id(), '_sidccofo_tk_log', array_slice( $log, -50 ) );
	}

	/**
	 * History.
	 *
	 * @return array[]
	 */
	public function get_log() {
		$log = $this->meta( 'log' );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Check in. Returns 'ok', 'already' or 'cancelled'.
	 *
	 * @return string
	 */
	public function check_in() {
		$status = $this->get_status();
		if ( 'cancelled' === $status ) {
			return 'cancelled';
		}
		if ( 'checked_in' === $status ) {
			return 'already';
		}
		update_post_meta( $this->get_id(), '_sidccofo_tk_status', 'checked_in' );
		update_post_meta( $this->get_id(), '_sidccofo_tk_checked_in', time() );
		update_post_meta( $this->get_id(), '_sidccofo_tk_checked_in_by', get_current_user_id() );
		$this->log( __( 'Checked in.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );

		/**
		 * Fires after a ticket is checked in.
		 *
		 * @param Ticket $ticket Ticket.
		 */
		do_action( 'sidcraft_commerce_ticket_checked_in', $this );
		return 'ok';
	}

	/**
	 * Undo a check-in.
	 */
	public function undo_check_in() {
		if ( 'checked_in' !== $this->get_status() ) {
			return;
		}
		update_post_meta( $this->get_id(), '_sidccofo_tk_status', 'valid' );
		update_post_meta( $this->get_id(), '_sidccofo_tk_checked_in', 0 );
		$this->log( __( 'Check-in undone.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
	}

	/**
	 * Cancel (order cancelled or refunded).
	 *
	 * @param string $reason Note.
	 */
	public function cancel( $reason = '' ) {
		if ( 'cancelled' === $this->get_status() ) {
			return;
		}
		update_post_meta( $this->get_id(), '_sidccofo_tk_status', 'cancelled' );
		$this->log( '' !== $reason ? $reason : __( 'Cancelled.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		do_action( 'sidcraft_commerce_ticket_cancelled', $this );
	}

	/**
	 * Make a cancelled ticket valid again (order reopened).
	 *
	 * @param string $reason Note.
	 */
	public function restore( $reason = '' ) {
		if ( 'cancelled' !== $this->get_status() ) {
			return;
		}
		update_post_meta( $this->get_id(), '_sidccofo_tk_status', $this->get_checked_in() ? 'checked_in' : 'valid' );
		$this->log( '' !== $reason ? $reason : __( 'Valid again.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
	}

	/*
	------------------------------------------------------------------
	 * Signed links (no login needed; the signature can't be guessed)
	 * ----------------------------------------------------------------
	 */

	/**
	 * Signature for a code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function sign( $code ) {
		return substr( hash_hmac( 'sha256', 'sidccofo-ticket|' . self::normalize( $code ), wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Does a signature match?
	 *
	 * @param string $code Code.
	 * @param string $sig  Signature.
	 * @return bool
	 */
	public static function verify( $code, $sig ) {
		return hash_equals( self::sign( $code ), (string) $sig );
	}

	/**
	 * Ticket page (also the QR content: staff who scan it can check in).
	 *
	 * @return string
	 */
	public function get_url() {
		return add_query_arg(
			array(
				'sidccofo_ticket' => $this->get_code(),
				'k'               => self::sign( $this->get_code() ),
			),
			home_url( '/' )
		);
	}

	/**
	 * PDF download.
	 *
	 * @return string
	 */
	public function get_pdf_url() {
		return add_query_arg(
			array(
				'sidccofo_ticket_pdf' => $this->get_code(),
				'k'                   => self::sign( $this->get_code() ),
			),
			home_url( '/' )
		);
	}

	/**
	 * QR image (PNG), for emails that link images.
	 *
	 * @return string
	 */
	public function get_qr_url() {
		return add_query_arg(
			array(
				'sidccofo_ticket_qr' => $this->get_code(),
				'k'                  => self::sign( $this->get_code() ),
			),
			home_url( '/' )
		);
	}

	/**
	 * What the QR code holds.
	 *
	 * @return string
	 */
	public function get_qr_payload() {
		return (string) apply_filters( 'sidcraft_commerce_ticket_qr_payload', $this->get_url(), $this );
	}

	/**
	 * Tickets of an order.
	 *
	 * @param int $order_id Order.
	 * @return Ticket[]
	 */
	public static function for_order( $order_id ) {
		$ids = get_posts(
			array(
				'post_type'      => Events::TICKET,
				'post_status'    => 'any',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => '_sidccofo_tk_order', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Per-order lookup.
				'meta_value'     => (int) $order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_values( array_filter( array_map( array( __CLASS__, 'get' ), $ids ) ) );
	}

	/**
	 * Tickets of an event.
	 *
	 * @param int   $event_id Event.
	 * @param array $args     Extra get_posts args.
	 * @return Ticket[]
	 */
	public static function for_event( $event_id, array $args = array() ) {
		$ids = get_posts(
			array_merge(
				array(
					'post_type'      => Events::TICKET,
					'post_status'    => 'any',
					'post_parent'    => (int) $event_id,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				),
				$args
			)
		);
		return array_values( array_filter( array_map( array( __CLASS__, 'get' ), $ids ) ) );
	}
}
