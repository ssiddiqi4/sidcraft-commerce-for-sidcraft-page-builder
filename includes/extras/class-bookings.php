<?php
/**
 * Bookings: services and rentals sold by the time slot or by the night.
 *
 * On a product (box "Booking") choose Time slots or Nights, then set opening
 * hours ("Mon-Fri 09:00-17:00; Sat 10:00-14:00"), the length of a slot, how
 * many bookings a slot (or night) can take and how far ahead people may
 * book. The product page shows a date picker and the free slots; the choice
 * rides in the cart and is checked again at checkout. Each booking is saved
 * under Store → Bookings and follows its order: a cancelled or refunded
 * order frees the slot. Customers get an .ics calendar file.
 *
 * Time slots: quantity = seats. Nights: quantity = number of nights, price =
 * product price per night.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Bookings
 */
class Bookings extends Tool {

	const TYPE = 'sidccofo_booking';

	/** {@inheritDoc} */
	public function hooks() {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		$this->settings(
			'bookings',
			__( 'Bookings', $d ),
			array(
				'book_notify' => array( __( 'Email new bookings to', $d ), 'email', __( 'Empty = the site admin email.', $d ) ),
			)
		);
		add_action( 'init', array( $this, 'register_type' ) );
		add_filter( 'sidcraft_commerce_extra_keys', array( $this, 'keys' ) );
		add_filter( 'sidcraft_commerce_validate_cart_item', array( $this, 'validate' ), 20, 3 );
		add_filter( 'sidcraft_commerce_checkout_errors', array( $this, 'errors' ) );
		add_action( 'sidcraft_commerce_before_add_to_cart_button', array( $this, 'picker' ) );
		add_action( 'sidcraft_commerce_order_created', array( $this, 'create' ) );
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'follow_order' ), 10, 3 );
		add_action( 'add_meta_boxes_' . Post_Types::PRODUCT, array( $this, 'box' ) );
		add_action( 'save_post_' . Post_Types::PRODUCT, array( $this, 'save' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'init', array( $this, 'maybe_ics' ) );
		add_filter( 'sidcraft_commerce_render_sidccofo-order-confirmation', array( $this, 'received_link' ), 25 );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
	}

	/**
	 * The bookings list.
	 */
	public function register_type() {
		register_post_type(
			self::TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Bookings', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name' => __( 'Booking', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . Post_Types::PRODUCT,
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title' ),
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * Availability
	 * ----------------------------------------------------------------
	 */

	/**
	 * Booking mode of a product.
	 *
	 * @param int $id Product.
	 * @return string slot | night | ''.
	 */
	public static function mode( $id ) {
		$m = (string) get_post_meta( $id, '_sidccofo_booking', true );
		return in_array( $m, array( 'slot', 'night' ), true ) ? $m : '';
	}

	/**
	 * Opening windows for a weekday, from "Mon-Fri 09:00-17:00; Sat 10:00-14:00".
	 *
	 * @param int    $id  Product.
	 * @param string $dow Mon..Sun.
	 * @return array[] [ start, end ] in minutes from midnight.
	 */
	protected static function windows( $id, $dow ) {
		$days = array( 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7 );
		$want = $days[ strtolower( substr( $dow, 0, 3 ) ) ] ?? 0;
		$out  = array();
		foreach ( explode( ';', (string) get_post_meta( $id, '_sidccofo_book_hours', true ) ) as $part ) {
			if ( ! preg_match( '/^\s*([A-Za-z]{3})(?:\s*-\s*([A-Za-z]{3}))?\s+(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $part, $m ) ) {
				continue;
			}
			$from = $days[ strtolower( $m[1] ) ] ?? 0;
			$to   = '' !== $m[2] ? ( $days[ strtolower( $m[2] ) ] ?? 0 ) : $from;
			if ( $from && $to && ( ( $from <= $to && $want >= $from && $want <= $to ) || ( $from > $to && ( $want >= $from || $want <= $to ) ) ) ) {
				$out[] = array( (int) $m[3] * 60 + (int) $m[4], (int) $m[5] * 60 + (int) $m[6] );
			}
		}
		return $out;
	}

	/**
	 * Bookings that hold capacity on a date for a product.
	 *
	 * @param int    $id   Product.
	 * @param string $date Y-m-d.
	 * @return array[] [ time, end, qty, date_end ].
	 */
	protected static function held( $id, $date ) {
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_sidccofo_bk_product', 'value' => $id ),
					array( 'key' => '_sidccofo_bk_status', 'value' => 'confirmed' ),
					array( 'key' => '_sidccofo_bk_date', 'value' => $date, 'compare' => '<=' ),
					array( 'key' => '_sidccofo_bk_date_end', 'value' => $date, 'compare' => '>=' ),
				),
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'time'     => (string) get_post_meta( $p->ID, '_sidccofo_bk_time', true ),
				'qty'      => max( 1, (int) get_post_meta( $p->ID, '_sidccofo_bk_qty', true ) ),
				'date'     => (string) get_post_meta( $p->ID, '_sidccofo_bk_date', true ),
				'date_end' => (string) get_post_meta( $p->ID, '_sidccofo_bk_date_end', true ),
			);
		}
		return $out;
	}

	/**
	 * Free slots on a date.
	 *
	 * @param int    $id   Product.
	 * @param string $date Y-m-d.
	 * @return array[] [ time (HH:MM), end (HH:MM), left ].
	 */
	public static function slots( $id, $date ) {
		if ( 'slot' !== self::mode( $id ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return array();
		}
		$ahead = max( 1, (int) get_post_meta( $id, '_sidccofo_book_days', true ) ?: 60 );
		$today = wp_date( 'Y-m-d' );
		if ( $date < $today || $date > gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 +' . $ahead . ' days' ) ) ) {
			return array();
		}
		$len   = max( 5, (int) get_post_meta( $id, '_sidccofo_book_duration', true ) ?: 60 );
		$cap   = max( 1, (int) get_post_meta( $id, '_sidccofo_book_capacity', true ) ?: 1 );
		$notice = (int) get_post_meta( $id, '_sidccofo_book_notice', true );
		$held  = self::held( $id, $date );
		$out   = array();
		foreach ( self::windows( $id, gmdate( 'D', strtotime( $date . ' 12:00:00' ) ) ) as $w ) {
			for ( $t = $w[0]; $t + $len <= $w[1]; $t += $len ) {
				$start = sprintf( '%02d:%02d', intdiv( $t, 60 ), $t % 60 );
				$end   = sprintf( '%02d:%02d', intdiv( $t + $len, 60 ), ( $t + $len ) % 60 );
				$at = ( new \DateTimeImmutable( $date . ' ' . $start . ':00', wp_timezone() ) )->getTimestamp();
				if ( $at < time() + $notice * HOUR_IN_SECONDS ) {
					continue;
				}
				$used = 0;
				foreach ( $held as $h ) {
					if ( $h['time'] === $start ) {
						$used += $h['qty'];
					}
				}
				if ( $cap - $used > 0 ) {
					$out[] = array( 'time' => $start, 'end' => $end, 'left' => $cap - $used );
				}
			}
		}
		return $out;
	}

	/**
	 * Are the nights from $from (inclusive) to $to (exclusive) free?
	 *
	 * @param int    $id   Product.
	 * @param string $from Check-in.
	 * @param string $to   Check-out.
	 * @return true|\WP_Error
	 */
	public static function nights_free( $id, $from, $to ) {
		$cap    = max( 1, (int) get_post_meta( $id, '_sidccofo_book_capacity', true ) ?: 1 );
		$ahead  = max( 1, (int) get_post_meta( $id, '_sidccofo_book_days', true ) ?: 60 );
		$bad    = new \WP_Error( 'sidccofo_booking', __( 'Those dates are not available.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		$a      = strtotime( $from . ' 12:00:00 UTC' );
		$b      = strtotime( $to . ' 12:00:00 UTC' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from . $to ) && ! ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) ) {
			return $bad;
		}
		$nights = (int) round( ( $b - $a ) / DAY_IN_SECONDS );
		if ( $nights < 1 || $nights > 60 || $from < wp_date( 'Y-m-d' ) || $a > time() + $ahead * DAY_IN_SECONDS ) {
			return $bad;
		}
		for ( $i = 0; $i < $nights; $i++ ) {
			$day  = gmdate( 'Y-m-d', $a + $i * DAY_IN_SECONDS );
			$used = 0;
			foreach ( self::held( $id, $day ) as $h ) {
				// A night is taken from the check-in date up to, not including, the check-out date.
				if ( $h['date'] <= $day && $h['date_end'] > $day ) {
					$used += $h['qty'];
				}
			}
			if ( $used >= $cap ) {
				return new \WP_Error( 'sidccofo_booking_full', __( 'Sorry, those dates are already booked.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 409 ) );
			}
		}
		return true;
	}

	/*
	------------------------------------------------------------------
	 * Cart
	 * ----------------------------------------------------------------
	 */

	/**
	 * Cart-extras key: "{product}|{date}|{time or checkout date}".
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public function keys( $keys ) {
		$keys['booking'] = static function ( $value, $cart ) {
			$all = (array) $cart->extra( 'booking', array() );
			if ( ! preg_match( '/^(\d+)\|(\d{4}-\d{2}-\d{2})\|(\d{2}:\d{2}|\d{4}-\d{2}-\d{2})$/', trim( (string) $value ), $m ) ) {
				return $all;
			}
			$id   = (int) $m[1];
			$mode = Bookings::mode( $id );
			if ( 'slot' === $mode ) {
				$ok = false;
				foreach ( Bookings::slots( $id, $m[2] ) as $s ) {
					$ok = $ok || $s['time'] === $m[3];
				}
				if ( ! $ok ) {
					return new \WP_Error( 'sidccofo_booking', __( 'That time is no longer free.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 409 ) );
				}
				$all[ $id ] = array( 'date' => $m[2], 'time' => $m[3], 'date_end' => $m[2] );
			} elseif ( 'night' === $mode ) {
				$free = Bookings::nights_free( $id, $m[2], $m[3] );
				if ( is_wp_error( $free ) ) {
					return $free;
				}
				$all[ $id ] = array( 'date' => $m[2], 'time' => '', 'date_end' => $m[3] );
			} else {
				return $all;
			}
			return $all;
		};
		return $keys;
	}

	/**
	 * A booking product needs a chosen slot before it can be added.
	 *
	 * @param true|\WP_Error $error    Result.
	 * @param Product|null   $product  Product.
	 * @param int            $quantity Quantity.
	 * @return true|\WP_Error
	 */
	public function validate( $error, $product, $quantity ) {
		if ( is_wp_error( $error ) || ! $product instanceof Product || ! self::mode( $product->get_id() ) ) {
			return $error;
		}
		$choice = (array) Cart::instance()->extra( 'booking', array() );
		if ( empty( $choice[ $product->get_id() ] ) ) {
			return new \WP_Error( 'sidccofo_booking', 'slot' === self::mode( $product->get_id() ) ? __( 'Choose a date and time first.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Choose your dates first.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		if ( 'night' === self::mode( $product->get_id() ) ) {
			$c      = $choice[ $product->get_id() ];
			$nights = (int) round( ( strtotime( $c['date_end'] . ' 12:00:00 UTC' ) - strtotime( $c['date'] . ' 12:00:00 UTC' ) ) / DAY_IN_SECONDS );
			if ( (int) $quantity !== $nights ) {
				return new \WP_Error( 'sidccofo_booking', __( 'The number of nights must match your dates.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			}
		}
		return $error;
	}

	/**
	 * Check every booking in the cart again at checkout.
	 *
	 * @param array $errors Errors.
	 * @return array
	 */
	public function errors( $errors ) {
		$cart   = Cart::instance();
		$choice = (array) $cart->extra( 'booking', array() );
		foreach ( $cart->product_quantities() as $pid => $qty ) {
			$mode = self::mode( (int) $pid );
			if ( ! $mode ) {
				continue;
			}
			$c = $choice[ $pid ] ?? null;
			if ( ! $c ) {
				$errors['cart'] = __( 'A booking in your cart has no date. Remove it and book again.', 'sidcraft-commerce-for-sidcraft-page-builder' );
				continue;
			}
			if ( 'slot' === $mode ) {
				$left = 0;
				foreach ( self::slots( (int) $pid, $c['date'] ) as $s ) {
					if ( $s['time'] === $c['time'] ) {
						$left = $s['left'];
					}
				}
				if ( $left < $qty ) {
					$errors['cart'] = __( 'A time slot in your cart is no longer available. Please choose another.', 'sidcraft-commerce-for-sidcraft-page-builder' );
				}
			} else {
				$free = self::nights_free( (int) $pid, $c['date'], $c['date_end'] );
				if ( is_wp_error( $free ) ) {
					$errors['cart'] = $free->get_error_message();
				}
			}
		}
		return $errors;
	}

	/**
	 * The picker on the product page.
	 *
	 * @param Product $product Product.
	 */
	public function picker( $product ) {
		if ( ! $product instanceof Product || ! self::mode( $product->get_id() ) ) {
			return;
		}
		Extras::enqueue();
		$d     = 'sidcraft-commerce-for-sidcraft-page-builder';
		$mode  = self::mode( $product->get_id() );
		$ahead = max( 1, (int) get_post_meta( $product->get_id(), '_sidccofo_book_days', true ) ?: 60 );
		$min   = wp_date( 'Y-m-d' );
		$max   = gmdate( 'Y-m-d', strtotime( $min . ' 12:00:00 +' . $ahead . ' days' ) );
		echo '<div class="sc-booking" data-sc-booking="' . esc_attr( $product->get_id() ) . '" data-mode="' . esc_attr( $mode ) . '">';
		if ( 'slot' === $mode ) {
			echo '<label>' . esc_html__( 'Date', $d ) . ' <input type="date" data-sc-book-date min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '"></label><div class="sc-booking__slots" data-sc-book-slots aria-live="polite"></div>';
		} else {
			echo '<label>' . esc_html__( 'Check-in', $d ) . ' <input type="date" data-sc-book-from min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '"></label> <label>' . esc_html__( 'Check-out', $d ) . ' <input type="date" data-sc-book-to min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '"></label><p class="sc-booking__nights" data-sc-book-nights></p>';
		}
		echo '<p class="sc-booking__msg" data-sc-book-msg role="status"></p></div>';
	}

	/**
	 * Slots route.
	 */
	public function routes() {
		register_rest_route(
			'sidcraft-commerce/v1',
			'/bookings/slots',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( \WP_REST_Request $r ) {
					return rest_ensure_response( array( 'slots' => Bookings::slots( absint( $r['product'] ), sanitize_text_field( (string) $r['date'] ) ) ) );
				},
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * Orders
	 * ----------------------------------------------------------------
	 */

	/**
	 * Save a booking for each booked line of a new order.
	 *
	 * @param Order $order Order.
	 */
	public function create( $order ) {
		$choice = (array) Cart::instance()->extra( 'booking', array() );
		$b      = $order->get_billing();
		$made   = array();
		foreach ( $order->get_items() as $item ) {
			$pid  = (int) $item['product_id'];
			$mode = self::mode( $pid );
			if ( ! $mode || empty( $choice[ $pid ] ) ) {
				continue;
			}
			$c  = $choice[ $pid ];
			$id = wp_insert_post(
				array(
					'post_type'   => self::TYPE,
					'post_status' => 'publish',
					/* translators: 1: product, 2: date, 3: order. */
					'post_title'  => sprintf( __( '%1$s, %2$s (order #%3$d)', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_strip_all_tags( $item['name'] ), $c['date'] . ( '' !== $c['time'] ? ' ' . $c['time'] : '' ), $order->get_id() ),
				)
			);
			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, '_sidccofo_bk_product', $pid );
			update_post_meta( $id, '_sidccofo_bk_order', $order->get_id() );
			update_post_meta( $id, '_sidccofo_bk_date', $c['date'] );
			update_post_meta( $id, '_sidccofo_bk_date_end', $c['date_end'] );
			update_post_meta( $id, '_sidccofo_bk_time', $c['time'] );
			update_post_meta( $id, '_sidccofo_bk_qty', 'slot' === $mode ? (int) $item['quantity'] : 1 );
			update_post_meta( $id, '_sidccofo_bk_status', 'confirmed' );
			update_post_meta( $id, '_sidccofo_bk_name', trim( ( $b['first_name'] ?? '' ) . ' ' . ( $b['last_name'] ?? '' ) ) );
			update_post_meta( $id, '_sidccofo_bk_email', $order->get_email() );
			$made[] = $id;
		}
		if ( $made ) {
			$to = is_email( (string) setting( 'book_notify', '' ) ) ? (string) setting( 'book_notify', '' ) : get_option( 'admin_email' );
			/* translators: %d: order number. */
			wp_mail( $to, sprintf( __( 'New booking (order #%d)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ), implode( "\n", array_map( 'get_the_title', $made ) ) );
		}
	}

	/**
	 * A cancelled or refunded order frees its bookings; a reopened one holds them again.
	 *
	 * @param Order  $order Order.
	 * @param string $old   Old status.
	 * @param string $new   New status.
	 */
	public function follow_order( $order, $old, $new ) {
		unset( $old );
		$status = in_array( $new, array( 'sidccofo-cancelled', 'sidccofo-refunded' ), true ) ? 'cancelled' : 'confirmed';
		$posts  = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_sidccofo_bk_order', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $order->get_id(), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $posts as $id ) {
			update_post_meta( $id, '_sidccofo_bk_status', $status );
		}
	}

	/*
	------------------------------------------------------------------
	 * Calendar file
	 * ----------------------------------------------------------------
	 */

	/**
	 * Link to the .ics on the order-received page.
	 *
	 * @param string $html Unit HTML.
	 * @return string
	 */
	public function received_link( $html ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Access by the order key.
		$id  = isset( $_GET['sidccofo_order'] ) ? absint( $_GET['sidccofo_order'] ) : 0;
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		$order = $id ? Order::get( $id ) : null;
		if ( ! $order || ! $order->key_is_valid( $key ) || ! $this->for_order( $id ) ) {
			return $html;
		}
		$url = add_query_arg( array( 'sidccofo_ics' => $id, 'key' => $key ), home_url( '/' ) );
		return preg_replace( '#</div>\s*$#', '<p class="sc-ics"><a class="sc-btn" href="' . esc_url( $url ) . '">' . esc_html__( 'Add to calendar (.ics)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></p></div>', $html, 1 );
	}

	/**
	 * Bookings of an order.
	 *
	 * @param int $order_id Order.
	 * @return int[]
	 */
	protected function for_order( $order_id ) {
		return get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_sidccofo_bk_order', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $order_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	/**
	 * Serve the .ics.
	 */
	public function maybe_ics() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Access by the order key.
		if ( empty( $_GET['sidccofo_ics'] ) ) {
			return;
		}
		$id  = absint( $_GET['sidccofo_ics'] );
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		$order = Order::get( $id );
		if ( ! $order || ! $order->key_is_valid( $key ) ) {
			return;
		}
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Sidcraft Commerce//Bookings//EN\r\n";
		foreach ( $this->for_order( $id ) as $bid ) {
			$date  = (string) get_post_meta( $bid, '_sidccofo_bk_date', true );
			$time  = (string) get_post_meta( $bid, '_sidccofo_bk_time', true );
			$end   = (string) get_post_meta( $bid, '_sidccofo_bk_date_end', true );
			$pid   = (int) get_post_meta( $bid, '_sidccofo_bk_product', true );
			$title = str_replace( array( "\r", "\n", ',', ';' ), array( '', ' ', '\,', '\;' ), wp_strip_all_tags( get_the_title( $pid ) ) );
			$ics  .= "BEGIN:VEVENT\r\nUID:booking-" . $bid . '@' . wp_parse_url( home_url(), PHP_URL_HOST ) . "\r\nDTSTAMP:" . gmdate( 'Ymd\THis\Z' ) . "\r\nSUMMARY:" . $title . "\r\n";
			if ( '' !== $time ) {
				$len   = max( 5, (int) get_post_meta( $pid, '_sidccofo_book_duration', true ) ?: 60 );
				$start = strtotime( $date . ' ' . $time . ':00' );
				$ics  .= 'DTSTART:' . gmdate( 'Ymd\THis', $start ) . "\r\nDTEND:" . gmdate( 'Ymd\THis', $start + $len * 60 ) . "\r\n";
			} else {
				$ics .= 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $date ) ) . "\r\nDTEND;VALUE=DATE:" . gmdate( 'Ymd', strtotime( $end ) ) . "\r\n";
			}
			$ics .= "END:VEVENT\r\n";
		}
		Extras::send_file( 'booking-' . $id . '.ics', 'text/calendar; charset=utf-8', $ics . "END:VCALENDAR\r\n" );
	}

	/*
	------------------------------------------------------------------
	 * Admin
	 * ----------------------------------------------------------------
	 */

	/**
	 * Product box.
	 */
	public function box() {
		add_meta_box( 'sidccofo-booking', __( 'Booking', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_box' ), Post_Types::PRODUCT, 'normal' );
	}

	/**
	 * Box contents.
	 *
	 * @param \WP_Post $post Product.
	 */
	public function render_box( $post ) {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		wp_nonce_field( 'sidccofo_booking', '_sidccofo_bk' );
		$m = static function ( $k ) use ( $post ) {
			return (string) get_post_meta( $post->ID, '_sidccofo_' . $k, true );
		};
		echo '<p><label>' . esc_html__( 'Sold as', $d ) . ' <select name="sidccofo_booking">';
		foreach ( array( '' => __( 'A normal product', $d ), 'slot' => __( 'Time slots (appointments, classes)', $d ), 'night' => __( 'Nights (rentals)', $d ) ) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $m( 'booking' ), $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label></p><p><label>' . esc_html__( 'Opening hours (time slots)', $d ) . '<br><input class="widefat" name="sidccofo_book_hours" placeholder="Mon-Fri 09:00-17:00; Sat 10:00-14:00" value="' . esc_attr( $m( 'book_hours' ) ) . '"></label></p>';
		foreach ( array(
			'book_duration' => __( 'Slot length (minutes)', $d ),
			'book_capacity' => __( 'Bookings per slot or night', $d ),
			'book_days'     => __( 'Bookable days ahead', $d ),
			'book_notice'   => __( 'Minimum notice (hours)', $d ),
		) as $k => $l ) {
			echo '<p style="display:inline-block;margin-right:16px"><label>' . esc_html( $l ) . '<br><input type="number" min="0" name="sidccofo_' . esc_attr( $k ) . '" value="' . esc_attr( $m( $k ) ) . '"></label></p>';
		}
	}

	/**
	 * Save.
	 *
	 * @param int $post_id Product.
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST['_sidccofo_bk'] ) || ! current_user_can( 'edit_post', $post_id ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_bk'] ) ), 'sidccofo_booking' ) ) {
			return;
		}
		$mode = isset( $_POST['sidccofo_booking'] ) ? sanitize_key( wp_unslash( $_POST['sidccofo_booking'] ) ) : '';
		update_post_meta( $post_id, '_sidccofo_booking', in_array( $mode, array( 'slot', 'night' ), true ) ? $mode : '' );
		update_post_meta( $post_id, '_sidccofo_book_hours', isset( $_POST['sidccofo_book_hours'] ) ? sanitize_text_field( wp_unslash( $_POST['sidccofo_book_hours'] ) ) : '' );
		foreach ( array( 'book_duration', 'book_capacity', 'book_days', 'book_notice' ) as $k ) {
			update_post_meta( $post_id, '_sidccofo_' . $k, isset( $_POST[ 'sidccofo_' . $k ] ) ? absint( $_POST[ 'sidccofo_' . $k ] ) : 0 );
		}
	}

	/**
	 * Columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function columns( $cols ) {
		return array(
			'cb'      => $cols['cb'] ?? '',
			'title'   => __( 'Booking', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'when'    => __( 'When', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'qty'     => __( 'Qty', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'who'     => __( 'Customer', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'bstatus' => __( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Column content.
	 *
	 * @param string $col Column.
	 * @param int    $id  Booking.
	 */
	public function column( $col, $id ) {
		switch ( $col ) {
			case 'when':
				$t = (string) get_post_meta( $id, '_sidccofo_bk_time', true );
				echo esc_html( get_post_meta( $id, '_sidccofo_bk_date', true ) . ( '' !== $t ? ' ' . $t : ' → ' . get_post_meta( $id, '_sidccofo_bk_date_end', true ) ) );
				break;
			case 'qty':
				echo esc_html( (string) get_post_meta( $id, '_sidccofo_bk_qty', true ) );
				break;
			case 'who':
				echo esc_html( get_post_meta( $id, '_sidccofo_bk_name', true ) . ' <' . get_post_meta( $id, '_sidccofo_bk_email', true ) . '>' );
				break;
			case 'bstatus':
				echo esc_html( ucfirst( (string) get_post_meta( $id, '_sidccofo_bk_status', true ) ) );
				break;
		}
	}
}
