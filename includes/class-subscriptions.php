<?php
/**
 * Subscriptions: products billed every day, week, month or year.
 *
 * How it works:
 *   - A product becomes a subscription on its edit screen (period, length,
 *     free trial, sign-up fee). Its price is what each period costs.
 *   - At checkout the first period (or only the sign-up fee during a free
 *     trial) is paid like any order. Each subscription line becomes a
 *     subscription record when the order is created, and starts when it is paid.
 *   - When a payment is due, a renewal order is created. A gateway that keeps
 *     a saved card (Stripe in Sidcraft Pro) charges it straight away; otherwise,
 *     or when that charge fails, the customer gets an email with a link to pay
 *     with any payment method. Until it is paid the subscription shows
 *     "Payment due"; unpaid after the grace period (Settings → Subscriptions),
 *     the renewal and the subscription are cancelled.
 *   - Customers see and cancel their subscriptions with the My Subscriptions
 *     Unit ([sidccofo_subscriptions]). Cancelling keeps access until the paid
 *     period ends.
 *
 * Gateways that charge renewals automatically return true from
 * supports( 'subscriptions' ), save their details with
 * Subscriptions::save_token() and implement charge_renewal().
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Checkout\Checkout;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use SidcraftCommerce\Data\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Class Subscriptions
 */
class Subscriptions {

	/** Admin-post action for paying a renewal. */
	const PAY_ACTION = 'sidccofo_pay_order';

	/** Admin-post action for the customer's cancel / resume buttons. */
	const CUSTOMER_ACTION = 'sidccofo_subscription';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'sidcraft_commerce_order_created', array( $this, 'order_created' ), 20 );
		add_action( 'sidcraft_commerce_order_customer_assigned', array( $this, 'customer_assigned' ), 10, 2 );
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'order_status_changed' ), 20, 3 );
		add_action( 'sidcraft_commerce_cleanup', array( self::class, 'run' ) );
		add_filter( 'sidcraft_commerce_price_html', array( $this, 'price_html' ), 10, 2 );
		add_filter( 'sidcraft_commerce_checkout_errors', array( $this, 'checkout_errors' ), 10, 3 );
		add_action( 'admin_post_' . self::PAY_ACTION, array( $this, 'handle_pay' ) );
		add_action( 'admin_post_nopriv_' . self::PAY_ACTION, array( $this, 'handle_pay' ) );
		add_action( 'admin_post_' . self::CUSTOMER_ACTION, array( $this, 'handle_customer_action' ) );
		add_action( 'sidcraft_commerce_subscription_status_changed', array( $this, 'status_email' ), 10, 3 );
	}

	/*
	------------------------------------------------------------------
	 * Terms and wording
	 * ----------------------------------------------------------------
	 */

	/**
	 * Billing periods: key => label.
	 *
	 * @return array
	 */
	public static function periods() {
		return array(
			'day'   => __( 'Day', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'week'  => __( 'Week', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'month' => __( 'Month', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'year'  => __( 'Year', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Terms with every key present and in range.
	 *
	 * @param array $terms period, interval, length, trial, fee, recurring.
	 * @return array
	 */
	public static function normalize_terms( array $terms ) {
		$period = (string) ( $terms['period'] ?? 'month' );
		return array(
			'period'    => array_key_exists( $period, self::periods() ) ? $period : 'month',
			'interval'  => max( 1, min( 365, (int) ( $terms['interval'] ?? 1 ) ) ),
			'length'    => max( 0, min( 999, (int) ( $terms['length'] ?? 0 ) ) ),
			'trial'     => max( 0, min( 365, (int) ( $terms['trial'] ?? 0 ) ) ),
			'fee'       => max( 0.0, (float) ( $terms['fee'] ?? 0 ) ),
			'recurring' => max( 0.0, (float) ( $terms['recurring'] ?? 0 ) ),
		);
	}

	/**
	 * A product's terms (fee in the store currency, no recurring price).
	 *
	 * @param Product $product Product.
	 * @return array
	 */
	public static function product_terms( Product $product ) {
		$id = $product->get_id();
		return self::normalize_terms(
			array(
				'period'   => get_post_meta( $id, '_sidccofo_sub_period', true ),
				'interval' => get_post_meta( $id, '_sidccofo_sub_interval', true ),
				'length'   => get_post_meta( $id, '_sidccofo_sub_length', true ),
				'trial'    => get_post_meta( $id, '_sidccofo_sub_trial', true ),
				'fee'      => get_post_meta( $id, '_sidccofo_sub_fee', true ),
			)
		);
	}

	/**
	 * "month", "3 months", "week"…
	 *
	 * @param string $period   Period.
	 * @param int    $interval Every n.
	 * @return string
	 */
	public static function period_text( $period, $interval ) {
		$interval = max( 1, (int) $interval );
		switch ( $period ) {
			case 'day':
				/* translators: %d: number of days. */
				return 1 === $interval ? __( 'day', 'sidcraft-commerce-for-sidcraft-page-builder' ) : sprintf( _n( '%d day', '%d days', $interval, 'sidcraft-commerce-for-sidcraft-page-builder' ), $interval );
			case 'week':
				/* translators: %d: number of weeks. */
				return 1 === $interval ? __( 'week', 'sidcraft-commerce-for-sidcraft-page-builder' ) : sprintf( _n( '%d week', '%d weeks', $interval, 'sidcraft-commerce-for-sidcraft-page-builder' ), $interval );
			case 'year':
				/* translators: %d: number of years. */
				return 1 === $interval ? __( 'year', 'sidcraft-commerce-for-sidcraft-page-builder' ) : sprintf( _n( '%d year', '%d years', $interval, 'sidcraft-commerce-for-sidcraft-page-builder' ), $interval );
			default:
				/* translators: %d: number of months. */
				return 1 === $interval ? __( 'month', 'sidcraft-commerce-for-sidcraft-page-builder' ) : sprintf( _n( '%d month', '%d months', $interval, 'sidcraft-commerce-for-sidcraft-page-builder' ), $interval );
		}
	}

	/**
	 * "$10 / month", "$25 every 3 months".
	 *
	 * @param string $amount   Formatted amount.
	 * @param string $period   Period.
	 * @param int    $interval Every n.
	 * @return string
	 */
	public static function price_per_period( $amount, $period, $interval ) {
		if ( (int) $interval > 1 ) {
			/* translators: 1: amount, 2: period such as "3 months". */
			return sprintf( __( '%1$s every %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $amount, self::period_text( $period, $interval ) );
		}
		/* translators: 1: amount, 2: period such as "month". */
		return sprintf( __( '%1$s / %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $amount, self::period_text( $period, 1 ) );
	}

	/**
	 * The extras after the price: "12 payments · 7-day free trial · $5 sign-up fee".
	 *
	 * @param array  $terms Terms.
	 * @param string $fee   Formatted sign-up fee ('' for none).
	 * @return string[]
	 */
	public static function terms_parts( array $terms, $fee = '' ) {
		$parts = array();
		if ( $terms['length'] > 0 ) {
			/* translators: %d: number of payments. */
			$parts[] = sprintf( _n( '%d payment', '%d payments', $terms['length'], 'sidcraft-commerce-for-sidcraft-page-builder' ), $terms['length'] );
		}
		if ( $terms['trial'] > 0 ) {
			/* translators: %d: number of days. */
			$parts[] = sprintf( _n( '%d-day free trial', '%d-day free trial', $terms['trial'], 'sidcraft-commerce-for-sidcraft-page-builder' ), $terms['trial'] );
		}
		if ( '' !== $fee ) {
			/* translators: %s: amount. */
			$parts[] = sprintf( __( '%s sign-up fee', 'sidcraft-commerce-for-sidcraft-page-builder' ), $fee );
		}
		return $parts;
	}

	/**
	 * Product price with its period and terms.
	 *
	 * @param string  $html    Price HTML (escaped).
	 * @param Product $product Product.
	 * @return string
	 */
	public function price_html( $html, $product ) {
		if ( ! $product instanceof Product || ! $product->is_subscription() || '' === $html ) {
			return $html;
		}
		$terms = self::product_terms( $product );
		$per   = 1 === $terms['interval'] ? '/ ' . self::period_text( $terms['period'], 1 ) : sprintf( /* translators: %s: period such as "3 months". */ __( 'every %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), self::period_text( $terms['period'], $terms['interval'] ) );
		$html .= ' <span class="sc-price__period">' . esc_html( $per ) . '</span>';
		$parts = self::terms_parts( $terms, $terms['fee'] > 0 ? format_price( round_amount( Currencies::to_active( $terms['fee'] ) ) ) : '' );
		if ( $parts ) {
			$html .= '<span class="sc-price__terms">' . esc_html( implode( ' · ', $parts ) ) . '</span>';
		}
		return $html;
	}

	/**
	 * Set a subscription product's cart line up: what is paid now (the first
	 * period, or nothing during a trial, plus the sign-up fee) and what each
	 * period costs after.
	 *
	 * @param array   $line    Cart line (price = one period, per unit).
	 * @param Product $product Product.
	 * @return array
	 */
	public static function price_line( array $line, Product $product ) {
		$terms              = self::product_terms( $product );
		$terms['recurring'] = (float) $line['price'];
		$fee                = round_amount( Currencies::to_active( $terms['fee'] ) );
		$terms['fee']       = $fee;
		$first              = round_amount( ( $terms['trial'] > 0 ? 0.0 : $terms['recurring'] ) + $fee );
		$line['price']        = $first;
		$line['total']        = round_amount( $first * (int) $line['quantity'] );
		$line['subscription'] = $terms;
		return $line;
	}

	/**
	 * "Then $10 / month · 12 payments" under a cart or order line.
	 *
	 * @param array  $line  Cart line or order item.
	 * @param string $style Inline style (emails).
	 * @return string Escaped HTML ('' for other lines).
	 */
	public static function line_html( $line, $style = '' ) {
		if ( empty( $line['subscription'] ) || ! is_array( $line['subscription'] ) ) {
			return '';
		}
		$terms = self::normalize_terms( $line['subscription'] );
		$each  = round_amount( $terms['recurring'] * max( 1, (int) ( $line['quantity'] ?? 1 ) ) );
		/* translators: %s: price per period, e.g. "$10 / month". */
		$text  = sprintf( __( 'Then %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), self::price_per_period( format_price( $each ), $terms['period'], $terms['interval'] ) );
		$parts = self::terms_parts( $terms, $terms['fee'] > 0 ? format_price( $terms['fee'] ) : '' );
		if ( $parts ) {
			$text .= ' · ' . implode( ' · ', $parts );
		}
		return '<span class="sc-item-subscription"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>' . esc_html( $text ) . '</span>';
	}

	/**
	 * A subscription's price per period, formatted in its currency.
	 *
	 * @param Subscription $sub Subscription.
	 * @return string
	 */
	public static function recurring_text( Subscription $sub ) {
		$terms = $sub->get_terms();
		return self::price_per_period( format_in( $sub->get_recurring_subtotal(), $sub->get_currency() ), $terms['period'], $terms['interval'] );
	}

	/**
	 * Add billing periods to a time, in the site's time zone. Months keep
	 * their day (Jan 31 → Feb 28/29 → Mar 31 with anchor day 31).
	 *
	 * @param int    $time       Timestamp.
	 * @param string $period     day|week|month|year.
	 * @param int    $interval   Every n.
	 * @param int    $anchor_day Day of the month to aim for (0 = the day of $time).
	 * @return int
	 */
	public static function add_period( $time, $period, $interval, $anchor_day = 0 ) {
		$date     = ( new \DateTimeImmutable( '@' . (int) $time ) )->setTimezone( wp_timezone() );
		$interval = max( 1, (int) $interval );
		if ( 'day' === $period || 'week' === $period ) {
			$days = 'week' === $period ? 7 * $interval : $interval;
			return $date->modify( '+' . $days . ' days' )->getTimestamp();
		}
		$months = 'year' === $period ? 12 * $interval : $interval;
		$day    = $anchor_day > 0 ? (int) $anchor_day : (int) $date->format( 'j' );
		$y      = (int) $date->format( 'Y' );
		$m      = (int) $date->format( 'n' ) + $months;
		$y     += intdiv( $m - 1, 12 );
		$m      = ( ( $m - 1 ) % 12 ) + 1;
		$last   = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $y, $m ), wp_timezone() ) )->format( 't' );
		return $date->setDate( $y, $m, min( $day, $last ) )->getTimestamp();
	}

	/**
	 * Date for display.
	 *
	 * @param int $time Timestamp.
	 * @return string
	 */
	public static function date( $time ) {
		return $time > 0 ? wp_date( get_option( 'date_format' ), (int) $time ) : '—';
	}

	/*
	------------------------------------------------------------------
	 * Cart and checkout
	 * ----------------------------------------------------------------
	 */

	/**
	 * Does the cart hold a subscription?
	 *
	 * @param Cart $cart Cart.
	 * @return bool
	 */
	public static function cart_has( Cart $cart ) {
		foreach ( $cart->get_items() as $line ) {
			if ( ! empty( $line['subscription'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Available gateways that can charge renewals automatically.
	 *
	 * @return \SidcraftCommerce\Payments\Gateway[]
	 */
	public static function auto_gateways() {
		return array_filter(
			Checkout::available_gateways(),
			static function ( $gateway ) {
				return $gateway->supports( 'subscriptions' );
			}
		);
	}

	/**
	 * Nothing is due today (free trial), but a card can be saved now so
	 * renewals are charged automatically.
	 *
	 * @param Cart $cart Cart.
	 * @return bool
	 */
	public static function cart_can_save_card( Cart $cart ) {
		return $cart->get_total() <= 0 && self::cart_has( $cart ) && (bool) self::auto_gateways();
	}

	/**
	 * Subscribers need an account, so they can manage their subscriptions.
	 *
	 * @param array $errors  Errors.
	 * @param array $billing Fields.
	 * @param array $input   Raw input.
	 * @return array
	 */
	public function checkout_errors( $errors, $billing, $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Filter signature.
		if ( ! is_user_logged_in() && empty( $input['create_account'] ) && self::cart_has( Cart::instance() ) && ! isset( $errors['create_account'] ) ) {
			$errors['create_account'] = __( 'Your cart has a subscription: tick “Create an account” (or log in) so you can manage it later.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		return $errors;
	}

	/*
	------------------------------------------------------------------
	 * Lifecycle
	 * ----------------------------------------------------------------
	 */

	/**
	 * A new order with subscription lines: one subscription per line.
	 *
	 * @param Order $order Order.
	 */
	public function order_created( $order ) {
		if ( ! $order instanceof Order || self::renewal_subscription( $order ) ) {
			return;
		}
		$ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! empty( $item['subscription'] ) && is_array( $item['subscription'] ) ) {
				$sub = Subscription::create( $order, $item );
				if ( $sub ) {
					$ids[] = $sub->get_id();
				}
			}
		}
		if ( $ids ) {
			update_post_meta( $order->get_id(), '_sidccofo_subscriptions', $ids );
		}
	}

	/**
	 * An account made at checkout (or a claimed guest order): its
	 * subscriptions belong to that account too.
	 *
	 * @param Order $order   Order.
	 * @param int   $user_id User ID.
	 */
	public function customer_assigned( $order, $user_id ) {
		foreach ( Subscription::for_order( $order->get_id() ) as $sub ) {
			if ( $sub->get_customer_id() < 1 ) {
				$sub->set( '_sidccofo_sub_customer', (int) $user_id );
				wp_update_post(
					array(
						'ID'          => $sub->get_id(),
						'post_author' => (int) $user_id,
					)
				);
			}
		}
	}

	/**
	 * The subscription a renewal order belongs to.
	 *
	 * @param Order $order Order.
	 * @return Subscription|null
	 */
	public static function renewal_subscription( Order $order ) {
		return Subscription::get( (int) get_post_meta( $order->get_id(), '_sidccofo_renewal_of', true ) );
	}

	/**
	 * Every subscription an order pays for (its own, or the one it renews).
	 *
	 * @param Order $order Order.
	 * @return Subscription[]
	 */
	public static function for_order( Order $order ) {
		$renews = self::renewal_subscription( $order );
		return $renews ? array( $renews ) : Subscription::for_order( $order->get_id() );
	}

	/**
	 * Gateways: save payment details now, for automatic renewals?
	 *
	 * @param Order $order Order.
	 * @return bool
	 */
	public static function order_needs_token( Order $order ) {
		return (bool) self::for_order( $order );
	}

	/**
	 * Gateways: charge this order's subscriptions automatically from now on.
	 *
	 * @param Order  $order      Order just paid (or set up) with the details.
	 * @param string $gateway_id Gateway id.
	 * @param array  $token      What the gateway needs to charge again.
	 */
	public static function save_token( Order $order, $gateway_id, array $token ) {
		foreach ( self::for_order( $order ) as $sub ) {
			$sub->set_token( $gateway_id, $token );
			$gateway = Checkout::gateway( $gateway_id );
			/* translators: %s: payment method. */
			$sub->add_note( sprintf( __( 'Renewals will be charged automatically with %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $gateway ? $gateway->get_title() : $gateway_id ) );
		}
	}

	/**
	 * Orders paid or cancelled: start, renew or cancel subscriptions.
	 *
	 * @param Order  $order      Order.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 */
	public function order_status_changed( $order, $old_status, $new_status ) {
		$paid = Post_Types::paid_statuses();
		if ( in_array( $new_status, $paid, true ) && ! get_post_meta( $order->get_id(), '_sidccofo_sub_counted', true ) ) {
			$renews = self::renewal_subscription( $order );
			$subs   = $renews ? array( $renews ) : Subscription::for_order( $order->get_id() );
			if ( ! $subs ) {
				return;
			}
			update_post_meta( $order->get_id(), '_sidccofo_sub_counted', 1 );
			foreach ( $subs as $sub ) {
				if ( $renews ) {
					self::renewed( $sub, $order );
				} else {
					self::start( $sub, $order );
				}
			}
			return;
		}

		if ( in_array( $new_status, array( 'sidccofo-cancelled', 'sidccofo-refunded' ), true ) && ! in_array( $old_status, $paid, true ) ) {
			// Never paid: the subscription it would have started or renewed ends.
			$renews = self::renewal_subscription( $order );
			if ( $renews ) {
				if ( (int) get_post_meta( $renews->get_id(), '_sidccofo_sub_renewal', true ) === $order->get_id() ) {
					$renews->set( '_sidccofo_sub_renewal', 0 );
					/* translators: %d: order number. */
					$renews->set_status( 'cancelled', sprintf( __( 'Renewal order #%d was not paid.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
				}
				return;
			}
			foreach ( Subscription::for_order( $order->get_id() ) as $sub ) {
				if ( 'pending' === $sub->get_status() ) {
					/* translators: %d: order number. */
					$sub->set_status( 'cancelled', sprintf( __( 'Order #%d was cancelled before payment.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
				}
			}
			return;
		}

		if ( in_array( $new_status, array( 'sidccofo-cancelled', 'sidccofo-refunded' ), true ) && in_array( $old_status, $paid, true ) && ! self::renewal_subscription( $order ) ) {
			// The first order was paid, then refunded or cancelled: stop charging for what was given back.
			foreach ( Subscription::for_order( $order->get_id() ) as $sub ) {
				if ( in_array( $sub->get_status(), array( 'active', 'on-hold', 'pending-cancel' ), true ) ) {
					/* translators: %d: order number. */
					$sub->set_status( 'cancelled', sprintf( __( 'Its first order #%d was refunded or cancelled.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
					/* translators: 1: subscription number, 2: order number. */
					self::alert_store( sprintf( __( 'Subscription #%1$d was cancelled because its first order #%2$d was refunded or cancelled. Reactivate it if the customer should keep it.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sub->get_id(), $order->get_id() ), $sub );
				}
			}
		}
	}

	/**
	 * First order paid: the subscription starts.
	 *
	 * @param Subscription $sub   Subscription.
	 * @param Order        $order First order.
	 */
	public static function start( Subscription $sub, Order $order ) {
		if ( 'pending' !== $sub->get_status() ) {
			if ( 'cancelled' === $sub->get_status() ) {
				/* translators: 1: order number, 2: subscription number. */
				self::alert_store( sprintf( __( 'Order #%1$d was paid after subscription #%2$d was cancelled. Refund the order or reactivate the subscription.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id(), $sub->get_id() ), $sub );
			}
			return;
		}
		$terms = $sub->get_terms();
		$now   = time();
		if ( $terms['trial'] > 0 ) {
			$next = $now + $terms['trial'] * DAY_IN_SECONDS;
			$sub->set( '_sidccofo_sub_trial_end', $next );
			$paid = 0;
		} else {
			$next = self::add_period( $now, $terms['period'], $terms['interval'] );
			$paid = 1;
		}
		$sub->set( '_sidccofo_sub_anchor_day', (int) wp_date( 'j', $next ) );
		$sub->set( '_sidccofo_sub_paid', $paid );
		self::schedule( $sub, $next );
		/* translators: %d: order number. */
		$sub->set_status( 'active', sprintf( __( 'Order #%d was paid.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
	}

	/**
	 * A renewal was paid: one more period.
	 *
	 * @param Subscription $sub   Subscription.
	 * @param Order        $order Renewal order.
	 */
	public static function renewed( Subscription $sub, Order $order ) {
		if ( in_array( $sub->get_status(), array( 'cancelled', 'expired' ), true ) ) {
			/* translators: 1: order number, 2: subscription number. */
			self::alert_store( sprintf( __( 'Renewal order #%1$d was paid after subscription #%2$d ended. Refund the order or reactivate the subscription.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id(), $sub->get_id() ), $sub );
			return;
		}
		$terms = $sub->get_terms();
		$due   = $sub->get_next_payment();
		$next  = self::add_period( $due > 0 ? $due : time(), $terms['period'], $terms['interval'], (int) get_post_meta( $sub->get_id(), '_sidccofo_sub_anchor_day', true ) );
		if ( $next <= time() ) {
			// Paid more than a period late: the new period starts now.
			$next = self::add_period( time(), $terms['period'], $terms['interval'] );
			$sub->set( '_sidccofo_sub_anchor_day', (int) wp_date( 'j', $next ) );
		}
		$sub->set( '_sidccofo_sub_paid', $sub->get_paid_count() + 1 );
		$sub->set( '_sidccofo_sub_renewal', 0 );
		$sub->set( '_sidccofo_sub_retry_at', 0 );
		self::schedule( $sub, $next );
		/* translators: %d: order number. */
		$note = sprintf( __( 'Renewal order #%d was paid.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() );
		if ( ! $sub->set_status( 'active', $note ) ) {
			$sub->add_note( $note );
		}
	}

	/**
	 * Note something the store must act on, and email the store.
	 *
	 * @param string       $message Message.
	 * @param Subscription $sub     Subscription.
	 */
	protected static function alert_store( $message, Subscription $sub ) {
		$sub->add_note( $message );
		$to = (string) setting( 'admin_email', get_option( 'admin_email' ) );
		if ( is_email( $to ) ) {
			/* translators: %d: subscription number. */
			wp_mail( $to, sprintf( __( 'Subscription #%d needs attention', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sub->get_id() ), $message . "\n\n" . admin_url( 'post.php?action=edit&post=' . $sub->get_id() ) );
		}
	}

	/**
	 * Set the next payment, or the end date once every payment is made.
	 *
	 * @param Subscription $sub  Subscription.
	 * @param int          $next Next payment time.
	 */
	protected static function schedule( Subscription $sub, $next ) {
		$left = $sub->get_payments_left();
		if ( null !== $left && $left < 1 ) {
			$sub->set( '_sidccofo_sub_next', 0 );
			$sub->set( '_sidccofo_sub_end', (int) $next );
		} else {
			$sub->set( '_sidccofo_sub_next', (int) $next );
			$sub->set( '_sidccofo_sub_end', 0 );
		}
	}

	/**
	 * Create the renewal order for the period that is due, then charge the
	 * saved card or email the customer a link to pay.
	 *
	 * @param Subscription $sub Subscription.
	 * @return Order|\WP_Error
	 */
	public static function renew( Subscription $sub ) {
		if ( $sub->get_pending_renewal() || ! self::claim( $sub ) ) {
			return new \WP_Error( 'sidccofo_renewal_exists', __( 'A renewal is already waiting for payment.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		self::keep_currency( $sub );
		$order = Currencies::using(
			$sub->get_currency(),
			static function () use ( $sub ) {
				$lang = $sub->get_language();
				if ( '' !== $lang ) {
					Multilingual::switch_language( $lang );
				}
				try {
					return self::create_renewal_order( $sub );
				} finally {
					if ( '' !== $lang ) {
						Multilingual::restore_language();
					}
				}
			}
		);
		if ( is_wp_error( $order ) ) {
			$sub->set( '_sidccofo_sub_renewal', 0 );
			/* translators: %s: reason. */
			$sub->add_note( sprintf( __( 'The renewal order could not be created: %s We’ll try again tomorrow.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_error_message() ) );
			$sub->set( '_sidccofo_sub_retry_at', time() + DAY_IN_SECONDS );
			return $order;
		}

		if ( $order->get_total() <= 0 ) {
			$order->payment_complete( 'free' );
			return $order;
		}

		$result = self::charge( $sub, $order );
		if ( 'paid' === $result ) {
			return $order;
		}
		if ( 'pending' === $result || 'unknown' === $result ) {
			$sub->set_status(
				'on-hold',
				'pending' === $result
					? __( 'The automatic payment is still processing.', 'sidcraft-commerce-for-sidcraft-page-builder' )
					: __( 'The payment provider didn’t answer. We’ll check the automatic payment again within the hour.', 'sidcraft-commerce-for-sidcraft-page-builder' )
			);
			return $order;
		}
		$sub->set_status( 'on-hold', __( 'Renewal payment due.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		self::send_invoice( $order, 'failed' === $result );
		return $order;
	}

	/**
	 * Take the renewal slot, so two runs can't both create a renewal for the
	 * same period. The slot holds "claim:{time}" until the order exists.
	 *
	 * @param Subscription $sub Subscription.
	 * @return bool
	 */
	protected static function claim( Subscription $sub ) {
		global $wpdb;
		$id      = $sub->get_id();
		$key     = '_sidccofo_sub_renewal';
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $id, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-swap needs the stored value.
		$claim   = 'claim:' . time();
		if ( null === $current ) {
			return (bool) add_post_meta( $id, $key, $claim, true );
		}
		if ( 0 === strpos( (string) $current, 'claim:' ) && (int) substr( (string) $current, 6 ) > time() - 15 * MINUTE_IN_SECONDS ) {
			return false; // Another run is creating it right now.
		}
		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND meta_value = %s", $claim, $id, $key, (string) $current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic claim.
		wp_cache_delete( $id, 'post_meta' );
		return 1 === (int) $rows;
	}

	/**
	 * Charge a renewal with the saved payment details, if there are any.
	 *
	 * @param Subscription $sub   Subscription.
	 * @param Order        $order Renewal order.
	 * @return string paid | pending (provider still processing) | unknown (no answer: try again) | failed | none (no saved card).
	 */
	protected static function charge( Subscription $sub, Order $order ) {
		$gateway = Checkout::gateway( $sub->get_gateway_id() );
		$token   = $sub->get_token();
		if ( ! $gateway || ! $token || ! $gateway->supports( 'subscriptions' ) ) {
			return 'none';
		}
		update_post_meta( $order->get_id(), '_sidccofo_payment_method', $gateway->get_id() );
		$result = Currencies::using(
			$order->get_currency(),
			static function () use ( $gateway, $order, $token ) {
				return $gateway->charge_renewal( $order, $token );
			}
		);
		$order = Order::get( $order->get_id() );
		if ( $order->is_paid() ) {
			delete_post_meta( $order->get_id(), '_sidccofo_charge_tries' );
			return 'paid';
		}
		if ( ! is_wp_error( $result ) ) {
			delete_post_meta( $order->get_id(), '_sidccofo_charge_tries' );
			return 'pending';
		}
		if ( 'sidccofo_charge_unknown' === $result->get_error_code() ) {
			$tries = (int) get_post_meta( $order->get_id(), '_sidccofo_charge_tries', true ) + 1;
			update_post_meta( $order->get_id(), '_sidccofo_charge_tries', $tries );
			/* translators: %s: reason. */
			$order->add_note( sprintf( __( 'Automatic payment not confirmed yet: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $result->get_error_message() ) );
			if ( $tries < 3 ) {
				return 'unknown';
			}
		}
		delete_post_meta( $order->get_id(), '_sidccofo_charge_tries' );
		/* translators: %s: reason. */
		$order->add_note( sprintf( __( 'Automatic payment failed: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $result->get_error_message() ) );
		/* translators: %s: reason. */
		$sub->add_note( sprintf( __( 'Automatic payment failed: %s The customer was sent a link to pay.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $result->get_error_message() ) );
		return 'failed';
	}

	/**
	 * A billing currency the store no longer offers: continue in the store
	 * currency at the rate the first order used.
	 *
	 * @param Subscription $sub Subscription.
	 */
	protected static function keep_currency( Subscription $sub ) {
		$code = $sub->get_currency();
		if ( in_array( $code, Currencies::codes(), true ) ) {
			return;
		}
		$parent = Order::get( $sub->get_parent_id() );
		$rate   = $parent ? $parent->get_exchange_rate() : 1.0;
		$item   = $sub->get_item();
		$base   = base_currency();
		$item['price'] = round( (float) $item['price'] / ( $rate > 0 ? $rate : 1.0 ), decimals_for( $base ) );
		$sub->set( '_sidccofo_sub_item', $item );
		$sub->set( '_sidccofo_sub_currency', $base );
		/* translators: 1: old currency, 2: store currency. */
		$sub->add_note( sprintf( __( '%1$s is no longer offered, so renewals continue in %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $code, $base ) );
	}

	/**
	 * Build the renewal order (in the subscription's currency and language).
	 *
	 * @param Subscription $sub Subscription.
	 * @return Order|\WP_Error
	 */
	protected static function create_renewal_order( Subscription $sub ) {
		$item     = $sub->get_item();
		$qty      = max( 1, (int) $item['quantity'] );
		$total    = $sub->get_recurring_subtotal();
		// A credit from switching plans (see the Subscription extras): used once, on the next renewal.
		$credit   = min( $total, max( 0.0, (float) get_post_meta( $sub->get_id(), '_sidccofo_sub_credit', true ) ) );
		$billing  = $sub->get_billing();
		$location = array(
			'country' => (string) ( $billing['country'] ?? '' ),
			'state'   => (string) ( $billing['state'] ?? '' ),
		);
		$product  = Product::get( (int) $item['product_id'] );
		$shipping = 0.0;
		$zone     = '';
		if ( $product && setting( 'collect_address', 1 ) && ! Downloads\Downloads::is_virtual( $product->get_id() ) && '' !== $location['country'] ) {
			$quote = Shipping::quote( $total, $location['country'], $product->get_weight() * $qty, $qty );
			if ( $quote['available'] ) {
				$shipping = round_amount( (float) $quote['cost'] );
				$zone     = (string) $quote['zone'];
			}
		}
		$tax       = Tax::calculate( array( max( 0.0, $total - $credit ) ), $shipping, $location, array( $product ? $product->get_tax_class() : '' ) );
		$inclusive = Tax::prices_include_tax();
		// Created as the customer (also when the store clicks "renew now").
		$admin = get_current_user_id();
		wp_set_current_user( $sub->get_customer_id() );
		$order = Order::create(
			array(
				'items'          => array(
					array(
						'product_id' => (int) $item['product_id'],
						'name'       => (string) $item['name'],
						'sku'        => (string) $item['sku'],
						'price'      => (float) $item['price'],
						'quantity'   => $qty,
						'total'      => $total,
						'options'    => (array) $item['options'],
						'selection'  => (array) $item['selection'],
					),
				),
				'subtotal'       => $total,
				'discount'       => $credit,
				'shipping'       => $shipping,
				'shipping_zone'  => $zone,
				'tax'            => (float) $tax['total'],
				'tax_label'      => (string) $tax['label'],
				'tax_rate'       => (float) $tax['rate'],
				'tax_inclusive'  => $inclusive,
				'total'          => round_amount( $total - $credit + $shipping + ( $inclusive ? 0.0 : (float) $tax['total'] ) ),
				'currency'       => $sub->get_currency(),
				'billing'        => $billing,
				'payment_method' => '',
				'notes'          => '',
			)
		);
		wp_set_current_user( $admin );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		update_post_meta( $order->get_id(), '_sidccofo_renewal_of', $sub->get_id() );
		if ( $credit > 0 ) {
			delete_post_meta( $sub->get_id(), '_sidccofo_sub_credit' );
		}
		$sub->add_renewal( $order->get_id() );
		$sub->set( '_sidccofo_sub_renewal', $order->get_id() );
		/* translators: %d: subscription number. */
		$order->add_note( sprintf( __( 'Renewal of subscription #%d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sub->get_id() ) );
		/* translators: %d: order number. */
		$sub->add_note( sprintf( __( 'Renewal order #%d created.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
		return Order::get( $order->get_id() );
	}

	/**
	 * Email the customer a link to pay a renewal.
	 *
	 * @param Order $order  Renewal order.
	 * @param bool  $failed The saved card was declined.
	 */
	public static function send_invoice( Order $order, $failed = false ) {
		$message = $failed
			? __( 'We couldn’t charge your saved payment method for your subscription renewal. Please pay with the link below to keep your subscription active.', 'sidcraft-commerce-for-sidcraft-page-builder' )
			: __( 'Your subscription renewal is due. Please pay with the link below to keep your subscription active.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		Multilingual::for_order(
			$order,
			static function () use ( $order, $message ) {
				Plugin::instance()->emails->send_custom( $order, $order->get_email(), __( 'Your subscription renewal is due', 'sidcraft-commerce-for-sidcraft-page-builder' ), $message, $order->get_received_url() );
			}
		);
	}

	/**
	 * Hourly: create due renewals, retry unanswered automatic payments, end
	 * finished subscriptions and cancel renewals left unpaid after the grace
	 * period.
	 */
	public static function run() {
		global $wpdb;
		$lock  = 'sidccofo_subscriptions_lock';
		$owner = time() . '|' . wp_generate_password( 12, false );
		// One run at a time (an INSERT either wins or doesn't); a lock left by a crashed run expires.
		$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lock.
		if ( '' !== $held && (int) $held < time() - 30 * MINUTE_IN_SECONDS ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock, $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Stale lock.
		}
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $lock, $owner ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lock.
			return;
		}
		try {
			$now   = time();
			$ready = array(
				'relation' => 'OR',
				array(
					'key'     => '_sidccofo_sub_retry_at',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_sidccofo_sub_retry_at',
					'value'   => $now,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			);
			$fresh = array(); // Renewed in this run: their next try waits for the next run.
			foreach ( Subscription::find( array( 'active' ), '_sidccofo_sub_next', $now, 50, array( $ready ) ) as $id ) {
				$sub = Subscription::get( $id );
				if ( $sub ) {
					self::renew( $sub );
					$fresh[] = $id;
				}
			}
			foreach ( Subscription::find( array( 'active', 'pending-cancel' ), '_sidccofo_sub_end', $now ) as $id ) {
				$sub = Subscription::get( $id );
				if ( $sub ) {
					$sub->set_status( 'active' === $sub->get_status() ? 'expired' : 'cancelled', __( 'The last paid period ended.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
				}
			}
			foreach ( Subscription::find( array( 'pending-cancel' ), '_sidccofo_sub_next', $now ) as $id ) {
				$sub = Subscription::get( $id );
				if ( $sub ) {
					$sub->set( '_sidccofo_sub_next', 0 );
					$sub->set_status( 'cancelled', __( 'The paid period ended.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
				}
			}
			// Payment due: retry automatic payments that got no answer, then the grace period (oldest renewals first).
			$grace = max( 1, (int) setting( 'subscription_grace_days', 7 ) );
			foreach ( Subscription::find( array( 'on-hold' ), '', 0, 200, array(), '_sidccofo_sub_renewal' ) as $id ) {
				$sub     = Subscription::get( $id );
				$renewal = $sub ? $sub->get_pending_renewal() : null;
				if ( ! $renewal || in_array( $id, $fresh, true ) ) {
					continue;
				}
				if ( 'sidccofo-pending' === $renewal->get_status() && (int) get_post_meta( $renewal->get_id(), '_sidccofo_charge_tries', true ) > 0 ) {
					$result = self::charge( $sub, $renewal );
					if ( 'failed' === $result ) {
						self::send_invoice( $renewal, true );
					}
					if ( 'unknown' === $result || 'paid' === $result ) {
						continue;
					}
				}
				$renewal = Order::get( $renewal->get_id() );
				if ( $renewal && in_array( $renewal->get_status(), array( 'sidccofo-pending', 'sidccofo-on-hold' ), true ) && (int) get_post_time( 'U', true, $renewal->get_id() ) + $grace * DAY_IN_SECONDS <= $now ) {
					/* translators: %d: number of days. */
					$renewal->set_status( 'sidccofo-cancelled', sprintf( _n( 'Not paid within %d day.', 'Not paid within %d days.', $grace, 'sidcraft-commerce-for-sidcraft-page-builder' ), $grace ) );
				}
			}
		} finally {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock, $owner ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release our own lock only.
		}
	}

	/*
	------------------------------------------------------------------
	 * Customer actions
	 * ----------------------------------------------------------------
	 */

	/**
	 * Cancel: at the end of the paid period, or now when nothing is paid ahead.
	 *
	 * @param Subscription $sub Subscription.
	 * @param string       $by  Who ("the customer", "the store").
	 * @return bool
	 */
	public static function cancel( Subscription $sub, $by ) {
		/* translators: %s: who cancelled. */
		$note = sprintf( __( 'Cancelled by %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $by );
		switch ( $sub->get_status() ) {
			case 'active':
				return $sub->set_status( 'pending-cancel', $note );
			case 'on-hold':
				$renewal = $sub->get_pending_renewal();
				if ( $renewal ) {
					$sub->set( '_sidccofo_sub_renewal', 0 );
					$renewal->set_status( 'sidccofo-cancelled', $note );
				}
				return $sub->set_status( 'cancelled', $note );
			case 'pending':
				return $sub->set_status( 'cancelled', $note );
		}
		return false;
	}

	/**
	 * The customer's Cancel / Keep buttons (My Subscriptions).
	 */
	public function handle_customer_action() {
		$id  = absint( $_POST['subscription'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
		$do  = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
		$sub = Subscription::get( $id );
		if ( ! $sub || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::CUSTOMER_ACTION . '_' . $id ) || $sub->get_customer_id() !== get_current_user_id() || ! get_current_user_id() ) {
			wp_die( esc_html__( 'This link has expired. Go back, refresh the page and try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		if ( 'cancel' === $do && $sub->can_cancel() ) {
			self::cancel( $sub, __( 'the customer', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} elseif ( 'resume' === $do && $sub->can_resume() ) {
			$sub->set_status( 'active', __( 'The customer kept the subscription.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		exit;
	}

	/**
	 * Pay a renewal order from its page (any available payment method).
	 */
	public function handle_pay() {
		$order = Order::get( absint( $_POST['order'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The order key is the credential, as on the order page.
		$key   = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
		if ( ! $order || ! $order->key_is_valid( $key ) || ! self::renewal_subscription( $order ) ) {
			wp_die( esc_html__( 'This payment link is not valid.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		$back = $order->get_received_url();
		if ( 'sidccofo-pending' !== $order->get_status() ) {
			wp_safe_redirect( $back );
			exit;
		}
		$method = sanitize_key( wp_unslash( $_POST['payment_method'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
		$result = Currencies::using(
			$order->get_currency(),
			static function () use ( $order, $method ) {
				$gateway = Checkout::available_gateways()[ $method ] ?? null;
				if ( ! $gateway ) {
					return new \WP_Error( 'sidccofo_payment_method', __( 'Choose a payment method.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
				}
				update_post_meta( $order->get_id(), '_sidccofo_payment_method', $gateway->get_id() );
				return $gateway->process_payment( $order );
			}
		);
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'sidccofo_pay_error', rawurlencode( $result->get_error_message() ), $back ) );
			exit;
		}
		if ( ! empty( $result['post']['url'] ) ) {
			// Hosted pages that need a POST: auto-submit.
			echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__( 'Redirecting to payment…', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</title><form id="sc-pay" method="post" action="' . esc_url( $result['post']['url'] ) . '">';
			foreach ( (array) ( $result['post']['fields'] ?? array() ) as $name => $value ) {
				echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			}
			echo '<noscript><button type="submit">' . esc_html__( 'Continue to payment', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></noscript></form><script>document.getElementById("sc-pay").submit();</script>';
			exit;
		}
		$to = ! empty( $result['redirect'] ) ? (string) $result['redirect'] : $back;
		wp_redirect( $to ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The payment provider's hosted page.
		exit;
	}

	/**
	 * Pay form for a pending renewal (order page).
	 *
	 * @param Order $order Order.
	 * @return string Escaped HTML ('' when there is nothing to pay).
	 */
	public static function pay_form( Order $order ) {
		if ( 'sidccofo-pending' !== $order->get_status() || ! self::renewal_subscription( $order ) ) {
			return '';
		}
		// Only methods that pay on their own page: this form has no card fields or buttons.
		$gateways = array_filter(
			Currencies::using( $order->get_currency(), array( Checkout::class, 'available_gateways' ) ),
			static function ( $gateway ) {
				return ! $gateway->has_inline_button() && '' === $gateway->payment_fields();
			}
		);
		$error    = isset( $_GET['sidccofo_pay_error'] ) ? sanitize_text_field( wp_unslash( $_GET['sidccofo_pay_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$out      = '<form class="sc-pay-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out     .= '<p class="sc-notice" role="status">' . esc_html__( 'This subscription renewal is waiting for payment.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		if ( '' !== $error ) {
			$out .= '<p class="sc-notice sc-notice--error" role="alert">' . esc_html( $error ) . '</p>';
		}
		if ( ! $gateways ) {
			return $out . '<p class="sc-notice sc-notice--error">' . esc_html__( 'This store is not accepting payments yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p></form>';
		}
		$out  .= '<input type="hidden" name="action" value="' . esc_attr( self::PAY_ACTION ) . '"><input type="hidden" name="order" value="' . esc_attr( (string) $order->get_id() ) . '"><input type="hidden" name="key" value="' . esc_attr( $order->get_key() ) . '">';
		$out  .= '<fieldset class="sc-gateways"><legend class="sc-checkout__heading">' . esc_html__( 'Payment method', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</legend>';
		$first = true;
		foreach ( $gateways as $gateway ) {
			$out  .= '<label class="sc-gateway"><input type="radio" name="payment_method" value="' . esc_attr( $gateway->get_id() ) . '"' . ( $first ? ' checked' : '' ) . '> <span class="sc-gateway__title">' . esc_html( $gateway->get_title() ) . '</span>'
				. ( $gateway->get_description() ? '<span class="sc-gateway__desc">' . esc_html( $gateway->get_description() ) . '</span>' : '' ) . '</label>';
			$first = false;
		}
		/* translators: %s: amount. */
		$out .= '</fieldset><button type="submit" class="sc-btn sc-btn--primary sc-btn--lg">' . esc_html( sprintf( __( 'Pay %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_in( $order->get_total(), $order->get_currency() ) ) ) . '</button></form>';
		return $out;
	}

	/**
	 * Tell the customer when a subscription ends.
	 *
	 * @param Subscription $sub    Subscription.
	 * @param string       $old    Old status.
	 * @param string       $status New status.
	 */
	public function status_email( $sub, $old, $status ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		if ( ! in_array( $status, array( 'pending-cancel', 'cancelled', 'expired' ), true ) || ( 'cancelled' === $status && 'pending-cancel' === $old ) || 'pending' === $old ) {
			return;
		}
		$lang = $sub->get_language();
		if ( '' !== $lang ) {
			Multilingual::switch_language( $lang );
		}
		try {
			if ( 'pending-cancel' === $status ) {
				$heading = __( 'Your subscription is cancelled', 'sidcraft-commerce-for-sidcraft-page-builder' );
				/* translators: %s: date. */
				$message = sprintf( __( 'You can keep using it until %s. You won’t be charged again.', 'sidcraft-commerce-for-sidcraft-page-builder' ), self::date( $sub->get_next_payment() ? $sub->get_next_payment() : $sub->get_end() ) );
			} elseif ( 'expired' === $status ) {
				$heading = __( 'Your subscription has finished', 'sidcraft-commerce-for-sidcraft-page-builder' );
				$message = __( 'Every payment has been made and the last period has ended. Thank you!', 'sidcraft-commerce-for-sidcraft-page-builder' );
			} else {
				$heading = __( 'Your subscription is cancelled', 'sidcraft-commerce-for-sidcraft-page-builder' );
				$message = __( 'Your subscription has ended and you won’t be charged again.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			}
			Plugin::instance()->emails->send_subscription( $sub, $heading, $message );
		} finally {
			if ( '' !== $lang ) {
				Multilingual::restore_language();
			}
		}
	}
}
