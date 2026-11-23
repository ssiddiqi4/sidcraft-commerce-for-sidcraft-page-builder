<?php
/**
 * Delivery or pickup date and time slots.
 *
 * Settings: how many days ahead, the time windows (one per line, for example
 * 09:00-12:00), closed weekdays and dates, the order cut-off hour for
 * next-day slots, how many orders a slot can take and whether a slot is
 * required. The choice is kept on the order, shown on the order screen and in
 * the order emails.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Delivery_Slots
 */
class Delivery_Slots extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$this->settings(
			'slots',
			__( 'Delivery date and time slots', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'slot_label'    => array( __( 'Heading', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'For example: Delivery date, or Pickup time.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'slot_days'     => array( __( 'Days ahead', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'How far ahead a slot can be booked (default 14).', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'slot_lead'     => array( __( 'Earliest: days from today', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( '0 = today (if before the cut-off), 1 = tomorrow…', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'slot_cutoff'   => array( __( 'Cut-off hour for same-day slots', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( '0–23, site time. Orders after it can’t pick today.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'slot_times'    => array( __( 'Time windows', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'textarea', __( 'One per line, for example 09:00-12:00. Empty = date only.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'slot_closed'   => array( __( 'Closed days', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Weekdays (Sun, Mon…) and dates (2026-12-25), separated by commas.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'slot_capacity' => array( __( 'Orders per slot', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( '0 = no limit. With time windows the limit is per window, otherwise per day.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'slot_required' => array( __( 'A slot must be chosen', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
			)
		);
		add_filter( 'sidcraft_commerce_extra_keys', array( $this, 'keys' ) );
		add_action( 'sidcraft_commerce_checkout_after_fields', array( $this, 'choice' ), 15 );
		add_action( 'sidcraft_commerce_checkout_input', array( $this, 'input' ), 10, 2 );
		add_filter( 'sidcraft_commerce_checkout_errors', array( $this, 'errors' ), 10, 3 );
		add_action( 'sidcraft_commerce_order_created', array( $this, 'stamp' ) );
		add_filter( 'sidcraft_commerce_email', array( $this, 'email_note' ), 16, 2 );
		add_action( 'add_meta_boxes_' . Post_Types::ORDER, array( $this, 'box' ) );
	}

	/**
	 * Time windows.
	 *
	 * @return string[]
	 */
	public static function times() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) setting( 'slot_times', '' ) ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}$/', $line ) ) {
				$out[] = preg_replace( '/\s+/', '', $line );
			}
		}
		return $out;
	}

	/**
	 * Is a date closed?
	 *
	 * @param string $date Y-m-d.
	 * @return bool
	 */
	protected static function closed( $date ) {
		$list = array_filter( array_map( 'trim', explode( ',', strtolower( (string) setting( 'slot_closed', '' ) ) ) ) );
		return in_array( $date, $list, true ) || in_array( strtolower( gmdate( 'D', strtotime( $date . ' 12:00:00' ) ) ), array_map( static function ( $x ) { return substr( $x, 0, 3 ); }, $list ), true );
	}

	/**
	 * Orders already holding a slot.
	 *
	 * @param string $date Date.
	 * @param string $time Window ('' = whole day).
	 * @return int
	 */
	protected static function taken( $date, $time ) {
		$meta = array(
			'relation' => 'AND',
			array( 'key' => '_sidccofo_slot_date', 'value' => $date ),
		);
		if ( '' !== $time ) {
			$meta[] = array( 'key' => '_sidccofo_slot_time', 'value' => $time );
		}
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ORDER,
				'post_status'    => array( 'sidccofo-pending', 'sidccofo-on-hold', 'sidccofo-processing', 'sidccofo-completed', 'sidccofo-disputed' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return count( $ids );
	}

	/**
	 * Dates that can be booked.
	 *
	 * @return string[] Y-m-d.
	 */
	public static function dates() {
		$days  = max( 1, min( 90, (int) setting( 'slot_days', 14 ) ) );
		$lead  = max( 0, (int) setting( 'slot_lead', 1 ) );
		$today = wp_date( 'Y-m-d' );
		$hour  = (int) wp_date( 'G' );
		$cut   = (int) setting( 'slot_cutoff', 14 );
		if ( 0 === $lead && $hour >= $cut ) {
			$lead = 1;
		}
		$out = array();
		for ( $i = $lead; $i <= $lead + $days; $i++ ) {
			$d = gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 +' . $i . ' days' ) );
			if ( ! self::closed( $d ) ) {
				$out[] = $d;
			}
		}
		return $out;
	}

	/**
	 * Is a slot bookable right now?
	 *
	 * @param string $date Date.
	 * @param string $time Window.
	 * @return true|\WP_Error
	 */
	public static function check( $date, $time ) {
		$bad = new \WP_Error( 'sidccofo_slot', __( 'Choose an available date and time.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		if ( ! in_array( $date, self::dates(), true ) ) {
			return $bad;
		}
		$times = self::times();
		if ( $times && ! in_array( $time, $times, true ) ) {
			return $bad;
		}
		$cap = (int) setting( 'slot_capacity', 0 );
		if ( $cap > 0 && self::taken( $date, $times ? $time : '' ) >= $cap ) {
			return new \WP_Error( 'sidccofo_slot_full', __( 'That slot is full. Please choose another.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Keys.
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public function keys( $keys ) {
		$keys['slot_date'] = static function ( $v ) {
			$v = sanitize_text_field( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		$keys['slot_time'] = static function ( $v ) {
			$v = sanitize_text_field( (string) $v );
			return preg_match( '/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/', $v ) ? $v : '';
		};
		return $keys;
	}

	/**
	 * The pickers.
	 *
	 * @param Cart $cart Cart.
	 */
	public function choice( $cart ) {
		if ( $cart->is_empty() ) {
			return;
		}
		Extras::enqueue();
		$label = '' !== trim( (string) setting( 'slot_label', '' ) ) ? (string) setting( 'slot_label', '' ) : __( 'Delivery date', 'sidcraft-commerce-for-sidcraft-page-builder' );
		$date  = (string) $cart->extra( 'slot_date', '' );
		$time  = (string) $cart->extra( 'slot_time', '' );
		echo '<fieldset class="sc-choice"><legend>' . esc_html( $label ) . '</legend><p class="sc-field sc-field--half"><label for="sc-slot-date">' . esc_html__( 'Date', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><select id="sc-slot-date" name="slot_date" data-sc-extra="slot_date"><option value="">' . esc_html__( 'Select…', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</option>';
		foreach ( self::dates() as $d ) {
			echo '<option value="' . esc_attr( $d ) . '"' . selected( $date, $d, false ) . '>' . esc_html( wp_date( 'D j M Y', strtotime( $d . ' 12:00:00' ) ) ) . '</option>';
		}
		echo '</select></p>';
		if ( self::times() ) {
			echo '<p class="sc-field sc-field--half"><label for="sc-slot-time">' . esc_html__( 'Time', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><select id="sc-slot-time" name="slot_time" data-sc-extra="slot_time"><option value="">' . esc_html__( 'Select…', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</option>';
			foreach ( self::times() as $t ) {
				echo '<option value="' . esc_attr( $t ) . '"' . selected( $time, $t, false ) . '>' . esc_html( str_replace( '-', ' – ', $t ) ) . '</option>';
			}
			echo '</select></p>';
		}
		echo '<span class="sc-field__error" data-sc-extra-error></span></fieldset>';
	}

	/**
	 * Use the submitted slot.
	 *
	 * @param array $input Input.
	 * @param Cart  $cart  Cart.
	 */
	public function input( $input, $cart ) {
		foreach ( array( 'slot_date', 'slot_time' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$v = (string) $input[ $k ];
				$cart->set_extra( $k, 'slot_date' === $k ? ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '' ) : ( preg_match( '/^\d{1,2}:\d{2}-\d{1,2}:\d{2}$/', $v ) ? $v : '' ) );
			}
		}
	}

	/**
	 * Refuse a missing, closed or full slot.
	 *
	 * @param array $errors Errors.
	 * @return array
	 */
	public function errors( $errors ) {
		$cart = Cart::instance();
		$date = (string) $cart->extra( 'slot_date', '' );
		$time = (string) $cart->extra( 'slot_time', '' );
		if ( '' === $date && '' === $time ) {
			if ( setting( 'slot_required', 0 ) ) {
				$errors['slot_date'] = __( 'Choose a date.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			}
			return $errors;
		}
		$ok = self::check( $date, $time );
		if ( is_wp_error( $ok ) ) {
			$errors['slot_date'] = $ok->get_error_message();
		}
		return $errors;
	}

	/**
	 * Save on the order.
	 *
	 * @param Order $order Order.
	 */
	public function stamp( $order ) {
		$cart = Cart::instance();
		$date = (string) $cart->extra( 'slot_date', '' );
		if ( '' === $date ) {
			return;
		}
		update_post_meta( $order->get_id(), '_sidccofo_slot_date', $date );
		update_post_meta( $order->get_id(), '_sidccofo_slot_time', (string) $cart->extra( 'slot_time', '' ) );
		/* translators: %s: slot. */
		$order->add_note( sprintf( __( 'Requested slot: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), self::label( $order->get_id() ) ) );
	}

	/**
	 * Slot as text.
	 *
	 * @param int $order_id Order.
	 * @return string
	 */
	public static function label( $order_id ) {
		$date = (string) get_post_meta( $order_id, '_sidccofo_slot_date', true );
		if ( '' === $date ) {
			return '';
		}
		$time = (string) get_post_meta( $order_id, '_sidccofo_slot_time', true );
		return wp_date( 'D j M Y', strtotime( $date . ' 12:00:00' ) ) . ( '' !== $time ? ', ' . str_replace( '-', ' – ', $time ) : '' );
	}

	/**
	 * Put the slot in order emails.
	 *
	 * @param array $mail  Mail.
	 * @param Order $order Order.
	 * @return array
	 */
	public function email_note( $mail, $order ) {
		if ( ! $mail || ! $order instanceof Order || ! isset( $mail[2] ) || '' === self::label( $order->get_id() ) ) {
			return $mail;
		}
		$box     = '<p style="margin:16px 0"><strong>' . esc_html( ( '' !== trim( (string) setting( 'slot_label', '' ) ) ? (string) setting( 'slot_label', '' ) : __( 'Delivery date', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) . ': ' . self::label( $order->get_id() ) ) . '</strong></p>';
		$mail[2] = false !== stripos( (string) $mail[2], '</body>' ) ? preg_replace( '#</body>#i', $box . '</body>', (string) $mail[2], 1 ) : $mail[2] . $box;
		return $mail;
	}

	/**
	 * Order box.
	 */
	public function box() {
		add_meta_box(
			'sidccofo-slot',
			__( 'Delivery slot', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			static function ( $post ) {
				$l = Delivery_Slots::label( $post->ID );
				echo '<p>' . esc_html( '' !== $l ? $l : __( 'No slot chosen.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) . '</p>';
			},
			Post_Types::ORDER,
			'side'
		);
	}
}
