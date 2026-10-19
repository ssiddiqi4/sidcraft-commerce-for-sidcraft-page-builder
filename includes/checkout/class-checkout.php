<?php
/**
 * Checkout: field definitions, validation, order creation, gateway hand-off.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Checkout;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Coupon;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Locations;
use SidcraftCommerce\Tax;
use SidcraftCommerce\Payments\Gateway;
use SidcraftCommerce\Payments\Gateway_PayPal_Standard;
use function SidcraftCommerce\currency;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Checkout
 */
class Checkout {

	const NONCE = 'sidcraft_commerce_checkout';

	/**
	 * Gateways keyed by id.
	 *
	 * @var Gateway[]|null
	 */
	protected static $gateways = null;

	/**
	 * Raw input of the checkout being processed (for gateways that need a
	 * flag sent by their own button script).
	 *
	 * @var array
	 */
	protected static $input = array();

	/**
	 * Input of the checkout being processed.
	 *
	 * @return array
	 */
	public static function input() {
		return self::$input;
	}

	/**
	 * All registered gateways.
	 *
	 * @return Gateway[]
	 */
	public static function gateways() {
		if ( null === self::$gateways ) {
			$list = array(
				new Gateway_PayPal_Standard(),
			);

			/**
			 * Add or remove payment gateways. Sidcraft Builder Pro adds PayPal
			 * Commerce, Stripe, Razorpay, PayU, Mercado Pago and more here. Each must extend
			 * \SidcraftCommerce\Payments\Gateway (see docs/example-gateway.php).
			 * A gateway with the same id as a built-in one replaces it.
			 *
			 * @param Gateway[] $list Gateways.
			 */
			$list           = apply_filters( 'sidcraft_commerce_gateways', $list );
			self::$gateways = array();
			foreach ( $list as $gateway ) {
				if ( $gateway instanceof Gateway ) {
					self::$gateways[ $gateway->get_id() ] = $gateway;
				}
			}
		}
		return self::$gateways;
	}

	/**
	 * Gateways that can take payments right now.
	 *
	 * @return Gateway[]
	 */
	public static function available_gateways() {
		$available = array_filter(
			self::gateways(),
			static function ( Gateway $g ) {
				return $g->is_available();
			}
		);

		/**
		 * Filter the gateways offered at checkout.
		 *
		 * @param Gateway[] $available id => gateway.
		 */
		return (array) apply_filters( 'sidcraft_commerce_available_gateways', $available );
	}

	/**
	 * Forget the gateway list (after settings change or in tests).
	 */
	public static function reset_gateways() {
		self::$gateways = null;
	}

	/**
	 * One gateway.
	 *
	 * @param string $id Gateway id.
	 * @return Gateway|null
	 */
	public static function gateway( $id ) {
		$all = self::gateways();
		return $all[ $id ] ?? null;
	}

	/**
	 * Checkout fields: key => [ label, type, required, autocomplete, width ].
	 *
	 * @return array
	 */
	public static function fields() {
		$fields = array(
			'first_name' => array( __( 'First name', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', true, 'given-name', 'half' ),
			'last_name'  => array( __( 'Last name', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', true, 'family-name', 'half' ),
			'email'      => array( __( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'email', true, 'email', 'half' ),
			'phone'      => array( __( 'Phone', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'tel', false, 'tel', 'half' ),
		);

		if ( setting( 'collect_address', 1 ) && self::cart_needs_address() ) {
			$fields += array(
				'address_1' => array( __( 'Street address', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', true, 'address-line1', 'full' ),
				'address_2' => array( __( 'Apartment, suite (optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', false, 'address-line2', 'full' ),
				'city'      => array( __( 'City', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', true, 'address-level2', 'half' ),
				'postcode'  => array( __( 'ZIP / Postal code', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', true, 'postal-code', 'half' ),
				'country'   => array( __( 'Country', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'country', true, 'country', 'half' ),
				'state'     => array( __( 'State / Province', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'state', false, 'address-level1', 'half' ),
			);
		} elseif ( Tax::enabled() ) {
			// No shipping address, but tax still depends on where the buyer is.
			$fields += array(
				'country' => array( __( 'Country', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'country', true, 'country', 'half' ),
				'state'   => array( __( 'State / Province', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'state', false, 'address-level1', 'half' ),
			);
		}

		/**
		 * Add, remove or relabel checkout fields. Types: text, email, tel,
		 * date, time, number, textarea, select (choices as the 6th item:
		 * value => label), country, state. Width: half or full. Values are
		 * saved on the order and shown in the admin, the emails and the
		 * order-received page.
		 *
		 *     add_filter( 'sidcraft_commerce_checkout_fields', function ( $fields ) {
		 *         $fields['pickup_time'] = array( 'Pickup time', 'select', true, 'off', 'half',
		 *             array( '18:00' => '6:00 pm', '18:30' => '6:30 pm' ) );
		 *         return $fields;
		 *     } );
		 *
		 * @param array $fields key => [ label, type, required, autocomplete, width, choices ].
		 */
		return (array) apply_filters( 'sidcraft_commerce_checkout_fields', $fields );
	}

	/**
	 * Whether the shopper's cart needs a shipping address. A cart of virtual
	 * products (downloads, services) and gift cards doesn't. The admin, cron
	 * and emails always get the full field list.
	 *
	 * @return bool
	 */
	public static function cart_needs_address() {
		if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() ) {
			return true;
		}
		$cart = Cart::instance();
		return $cart->is_empty() || $cart->needs_shipping();
	}

	/**
	 * Checkout fields added by add-ons (not the built-in contact and address
	 * fields), with the values an order was placed with.
	 *
	 * @param \SidcraftCommerce\Data\Order $order Order.
	 * @return array[] Each: key, label, value (choice labels resolved).
	 */
	public static function extra_fields( $order ) {
		$core    = array( 'first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state' );
		$billing = $order->get_billing();
		$out     = array();
		foreach ( self::fields() as $key => $field ) {
			if ( in_array( $key, $core, true ) || ! isset( $billing[ $key ] ) || '' === (string) $billing[ $key ] ) {
				continue;
			}
			$value = (string) $billing[ $key ];
			if ( 'select' === $field[1] && isset( $field[5][ $value ] ) ) {
				$value = (string) $field[5][ $value ];
			}
			$out[] = array(
				'key'   => $key,
				'label' => (string) $field[0],
				'value' => $value,
			);
		}
		return $out;
	}

	/**
	 * Validate the submission, create the order and start payment.
	 *
	 * @param array $input Unslashed request params.
	 * @return array|\WP_Error [ 'redirect' => url ] or errors (data.fields).
	 */
	public static function process( array $input ) {
		$cart = Cart::instance();
		// One checkout at a time per coupon, so usage limits hold under load.
		$lock = Coupon::lock( $cart->get_coupon_code() );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$cart->forget_totals();
		try {
			return self::place( $input, $cart );
		} finally {
			Coupon::unlock();
		}
	}

	/**
	 * The checkout itself (see process()).
	 *
	 * @param array $input Unslashed request params.
	 * @param Cart  $cart  Cart.
	 * @return array|\WP_Error
	 */
	protected static function place( array $input, Cart $cart ) {
		self::$input = $input;

		if ( $cart->is_empty() ) {
			return new \WP_Error( 'sidccofo_empty_cart', __( 'Your cart is empty.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}

		// 1. Fields.
		$billing = array();
		$errors  = array();
		foreach ( self::fields() as $key => $field ) {
			$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? ( 'textarea' === $field[1] ? sanitize_textarea_field( (string) $input[ $key ] ) : sanitize_text_field( (string) $input[ $key ] ) ) : '';
			if ( 'select' === $field[1] && '' !== $value && ! array_key_exists( $value, (array) ( $field[5] ?? array() ) ) ) {
				/* translators: %s: field label. */
				$errors[ $key ] = sprintf( __( 'Choose an option for %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $field[0] );
				$value          = '';
			}
			if ( 'email' === $field[1] && '' !== $value ) {
				if ( ! is_email( $value ) ) {
					$errors[ $key ] = __( 'Enter a valid email address.', 'sidcraft-commerce-for-sidcraft-page-builder' );
				}
				$value = sanitize_email( $value );
			}
			if ( $field[2] && '' === $value && empty( $errors[ $key ] ) ) {
				/* translators: %s: field label. */
				$errors[ $key ] = sprintf( __( '%s is required.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $field[0] );
			}
			$billing[ $key ] = $value;
		}

		if ( ! empty( $input['create_account'] ) && ! is_user_logged_in() ) {
			$email = sanitize_email( (string) ( $billing['email'] ?? '' ) );
			if ( '' !== $email && email_exists( $email ) ) {
				$errors['create_account'] = __( 'An account already uses this email. Log in, and this order will be saved to that account.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			}
		}

		// Country/state: store codes; they decide shipping and tax.
		if ( isset( $billing['country'] ) ) {
			$code = Locations::normalize_country( $billing['country'] );
			if ( '' === $code && '' !== $billing['country'] ) {
				$errors['country'] = __( 'Choose a country from the list.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			} elseif ( '' !== $code ) {
				$billing['country'] = $code;
				$billing['state']   = Locations::normalize_state( $code, $billing['state'] ?? '' );
				$cart->set_location( $code, $billing['state'] );
				if ( ! $cart->can_ship() ) {
					/* translators: %s: country. */
					$errors['country'] = sprintf( __( 'Sorry, we don’t ship to %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), Locations::country_name( $code ) );
				}
				if ( Locations::states( $code ) && empty( $errors['state'] ) && ! isset( Locations::states( $code )[ $billing['state'] ] ) ) {
					$errors['state'] = __( 'Choose a state / province from the list.', 'sidcraft-commerce-for-sidcraft-page-builder' );
				}
			}
		}

		// 2. Payment method.
		$method        = isset( $input['payment_method'] ) ? sanitize_key( $input['payment_method'] ) : '';
		$gateway       = self::available_gateways()[ $method ] ?? null;
		$paid_by_cards = $cart->get_total() <= 0; // Gift cards (or a 100% coupon) cover everything.
		if ( $paid_by_cards && $gateway && $gateway->supports( 'subscriptions' ) && \SidcraftCommerce\Subscriptions::cart_has( $cart ) ) {
			$paid_by_cards = false; // Free trial: the gateway saves the card for the renewals.
		}
		if ( ! $gateway && ! $paid_by_cards ) {
			$errors['payment_method'] = __( 'Choose a payment method.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}

		// 3. Every line still purchasable in this quantity (stock may have moved).
		$items = array();
		foreach ( $cart->product_quantities() as $product_id => $quantity ) {
			$check = $cart->validate( $product_id, $quantity );
			if ( is_wp_error( $check ) ) {
				$errors['cart'] = $check->get_error_message();
				break;
			}
		}
		if ( ! isset( $errors['cart'] ) ) {
			$check = $cart->validate_all_choices();
			if ( is_wp_error( $check ) ) {
				$errors['cart'] = $check->get_error_message();
			}
		}
		foreach ( $cart->get_items() as $line ) {
			$gift = $line['gift_card'] ?? null;
			$item = array(
				'product_id' => $line['product_id'],
				'name'       => $line['product']->get_name(),
				'sku'        => (string) ( $line['sku'] ?? $line['product']->get_sku() ),
				'price'      => $line['price'],
				'quantity'   => $line['quantity'],
				'total'      => $line['total'],
			);
			if ( ! empty( $line['options'] ) ) {
				$item['options'] = $line['options']; // Rows: label, value, price.
			}
			if ( ! empty( $line['selection'] ) ) {
				$item['selection'] = $line['selection']; // Choice ids, for choice stock.
			}
			if ( ! empty( $line['subscription'] ) ) {
				$item['subscription'] = $line['subscription']; // Terms and the price of each period.
			}
			if ( $gift ) {
				/* translators: 1: product name, 2: recipient. */
				$item['name']      = sprintf( __( '%1$s for %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $line['product']->get_name(), '' !== $gift['recipient_name'] ? $gift['recipient_name'] : $gift['recipient_email'] );
				$item['gift_card'] = array(
					'amount'          => (float) $gift['amount'], // Store currency, like every card balance.
					'recipient_name'  => $gift['recipient_name'],
					'recipient_email' => $gift['recipient_email'],
					'sender'          => $gift['sender'],
					'message'         => $gift['message'],
					'send_date'       => $gift['send_date'],
				);
			}
			$items[] = $item;
		}

		// Gift cards still valid (balance used elsewhere, expired, disabled).
		foreach ( $cart->get_applied_gift_cards() as $applied ) {
			if ( '' !== $applied['error'] ) {
				/* translators: 1: masked code, 2: reason. */
				$errors['gift_card'] = sprintf( __( 'Gift card %1$s: %2$s Remove it to continue.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $applied['card']->get_masked_code(), $applied['error'] );
				break;
			}
		}

		// 4. Coupon still valid (it may have expired or hit its limit since it was applied).
		if ( '' !== $cart->get_coupon_code() && ! $cart->get_coupon() ) {
			$errors['coupon'] = $cart->get_coupon_error();
		} elseif ( $cart->get_coupon() ) {
			// Uses per customer, now that the billing email is known (guests included).
			$user   = wp_get_current_user();
			$emails = array( (string) ( $billing['email'] ?? '' ) );
			if ( $user->ID ) {
				$emails[] = $user->user_email;
			}
			$mine = $cart->get_coupon()->validate_for_customer( $user->ID, $emails, (int) $cart->session()->get( 'order_awaiting_payment', 0 ) );
			if ( is_wp_error( $mine ) ) {
				$errors['coupon'] = $mine->get_error_message();
			}
		}

		/**
		 * Final say on checkout errors (terms checkbox, address rules…).
		 *
		 * @param array $errors  field => message.
		 * @param array $billing Sanitized fields.
		 * @param array $input   Raw input.
		 */
		$errors = (array) apply_filters( 'sidcraft_commerce_checkout_errors', $errors, $billing, $input );

		if ( $errors ) {
			return new \WP_Error(
				'sidccofo_checkout_invalid',
				reset( $errors ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		// 5. Cancel this visitor's previous unpaid attempt, if any.
		$previous = Order::get( (int) $cart->session()->get( 'order_awaiting_payment', 0 ) );
		if ( $previous && 'sidccofo-pending' === $previous->get_status() ) {
			$previous->set_status( 'sidccofo-cancelled', __( 'Replaced by a new checkout attempt.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}

		// 6. Create the order from server-side prices.
		$order = Order::create(
			array(
				'items'          => $items,
				'subtotal'       => $cart->get_subtotal(),
				'discount'       => $cart->get_discount(),
				'coupon_code'    => ( $cart->get_discount() > 0 || $cart->has_free_shipping_coupon() ) ? $cart->get_coupon_code() : '',
				'shipping'       => $cart->get_shipping(),
				'total'           => $cart->get_total(),
				'gift_card_total' => $cart->get_gift_card_total(),
				'tax'            => $cart->get_tax(),
				'tax_label'      => $cart->get_tax_details()['label'],
				'tax_rate'       => $cart->get_tax_details()['rate'],
				'tax_inclusive'  => $cart->prices_include_tax(),
				'shipping_zone'  => $cart->get_shipping_quote()['zone'],
				'currency'       => currency(),
				'billing'        => $billing,
				'payment_method' => $paid_by_cards ? ( $cart->get_gift_card_total() > 0 ? 'gift_card' : 'free' ) : $gateway->get_id(),
				'notes'          => isset( $input['order_notes'] ) ? sanitize_textarea_field( (string) $input['order_notes'] ) : '',
			)
		);
		// The order holds its coupon use now: the next checkout may check.
		Coupon::unlock();
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		// An add-on cancelled the new order on creation (e.g. reward points spent elsewhere meanwhile).
		$problem = (string) get_post_meta( $order->get_id(), '_sidccofo_checkout_problem', true );
		if ( '' !== $problem ) {
			return new \WP_Error( 'sidccofo_checkout_invalid', $problem, array( 'status' => 409 ) );
		}
		if ( $cart->has_free_shipping_coupon() ) {
			update_post_meta( $order->get_id(), '_sidccofo_coupon_free_shipping', 1 );
		}

		if ( ! empty( $input['create_account'] ) && ! is_user_logged_in() ) {
			$user_id = self::create_customer( $billing );
			if ( is_wp_error( $user_id ) ) {
				$order->set_status( 'sidccofo-cancelled', __( 'The account could not be created, so the order was cancelled and its stock was returned.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
				return $user_id;
			}
			$order->assign_customer( $user_id );
			wp_new_user_notification( $user_id, null, 'user' );
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id );
		}

		$cart->session()->set( 'order_awaiting_payment', $order->get_id() );
		$cart->session()->set( 'customer', $billing ); // Prefill next time.

		// Take the gift card amounts now (given back if the order is cancelled).
		$used = array();
		foreach ( $cart->get_applied_gift_cards() as $applied ) {
			if ( $applied['amount'] > 0 ) {
				// Cards hold store currency; the order shows what they paid in its own.
				$base   = \SidcraftCommerce\Currencies::to_base( $applied['amount'] );
				$base   = min( $applied['card']->get_balance(), \SidcraftCommerce\round_base( $base ) );
				/* translators: %d: order number. */
				$taken  = $applied['card']->debit( $base, $order->get_id(), sprintf( __( 'Used for order #%d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
				$used[] = array(
					'id'     => $applied['card']->get_id(),
					'code'   => $applied['card']->get_masked_code(),
					'amount' => $taken >= $base ? $applied['amount'] : \SidcraftCommerce\round_amount( \SidcraftCommerce\Currencies::to_active( $taken ) ),
					'base'   => $taken,
				);
			}
		}
		if ( $used ) {
			update_post_meta( $order->get_id(), '_sidccofo_gift_cards_used', $used );
			/* translators: %s: amount. */
			$order->add_note( sprintf( __( 'Paid %s with gift cards.', 'sidcraft-commerce-for-sidcraft-page-builder' ), \SidcraftCommerce\format_price( array_sum( wp_list_pluck( $used, 'amount' ) ) ) ) );
		}

		// Fully paid by gift cards: nothing to collect.
		if ( $paid_by_cards ) {
			$order->payment_complete( $cart->get_gift_card_total() > 0 ? 'gift-card' : 'free' );
			$cart->clear();
			do_action( 'sidcraft_commerce_checkout_processed', $order, null );
			return array( 'redirect' => $order->get_received_url() );
		}

		// 7. Hand off to the gateway.
		$result = $gateway->process_payment( $order );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Offline (and any gateway that finishes synchronously): empty now.
		if ( ! in_array( $order->get_status(), array( 'sidccofo-pending' ), true ) ) {
			$cart->clear();
		}

		do_action( 'sidcraft_commerce_checkout_processed', $order, $gateway );
		return $result;
	}

	/**
	 * Create a customer from the checkout email. The account email is the
	 * address WordPress will use to confirm the account.
	 *
	 * @param array $billing Sanitized checkout fields.
	 * @return int|\WP_Error User ID.
	 */
	protected static function create_customer( array $billing ) {
		$email = sanitize_email( (string) ( $billing['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'sidccofo_account_email', __( 'Enter a valid email address to create an account.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		if ( email_exists( $email ) ) {
			return new \WP_Error( 'sidccofo_account_exists', __( 'An account already uses this email. Log in, and this order will be saved to that account.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}

		$local = strstr( $email, '@', true );
		$login = sanitize_user( (string) $local, true );
		if ( '' === $login ) {
			$login = 'customer';
		}
		$base = $login;
		$n    = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $n;
			++$n;
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 24 ),
				'first_name' => (string) ( $billing['first_name'] ?? '' ),
				'last_name'  => (string) ( $billing['last_name'] ?? '' ),
				'role'       => get_role( 'customer' ) ? 'customer' : 'subscriber',
			)
		);
		return $user_id;
	}
}
