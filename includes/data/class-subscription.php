<?php
/**
 * A customer's subscription: one subscription product line, billed every period.
 *
 * Stored as a sidccofo_sub post. Meta:
 *   _sidccofo_sub_status        pending | active | on-hold | pending-cancel | cancelled | expired
 *   _sidccofo_sub_item          The line: product_id, name, sku, quantity, price (per unit, each
 *                               period, in the subscription's currency), options, selection.
 *   _sidccofo_sub_period        day | week | month | year
 *   _sidccofo_sub_interval      Every n periods.
 *   _sidccofo_sub_length        Payments in all (0 = until cancelled).
 *   _sidccofo_sub_paid          Periods paid so far.
 *   _sidccofo_sub_next          Next payment (timestamp; 0 = none).
 *   _sidccofo_sub_end           Access ends (timestamp; 0 = no end yet).
 *   _sidccofo_sub_trial_end     End of the free trial (timestamp; 0 = no trial).
 *   _sidccofo_sub_anchor_day    Day of the month payments fall on (keeps Jan 31 → Feb 28 → Mar 31).
 *   _sidccofo_sub_currency      Billing currency.
 *   _sidccofo_sub_customer      User id.
 *   _sidccofo_sub_billing       Billing fields from the first order.
 *   _sidccofo_sub_language      Language of the first order (emails).
 *   _sidccofo_sub_parent        First order.
 *   _sidccofo_sub_orders        Renewal order ids.
 *   _sidccofo_sub_renewal       Renewal order waiting for payment (0 = none).
 *   _sidccofo_sub_gateway       Gateway that charges renewals automatically ('' = pay link).
 *   _sidccofo_sub_token         That gateway's saved payment details.
 *   _sidccofo_sub_retry_at      Don't try to create a renewal before this time (stock ran out).
 *   _sidccofo_log               History.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Data;

use SidcraftCommerce\Subscriptions;

defined( 'ABSPATH' ) || exit;

/**
 * Class Subscription
 */
class Subscription {

	/**
	 * Post id.
	 *
	 * @var int
	 */
	protected $id;

	/**
	 * Load.
	 *
	 * @param int $id Post id.
	 * @return Subscription|null
	 */
	public static function get( $id ) {
		$id = absint( $id );
		if ( ! $id || Post_Types::SUBSCRIPTION !== get_post_type( $id ) ) {
			return null;
		}
		return new static( $id );
	}

	/**
	 * Constructor.
	 *
	 * @param int $id Post id.
	 */
	protected function __construct( $id ) {
		$this->id = (int) $id;
	}

	/**
	 * Create a subscription (status pending) for a line of a new order.
	 *
	 * @param Order $order Order.
	 * @param array $item  Order line with a 'subscription' entry.
	 * @return Subscription|null
	 */
	public static function create( Order $order, array $item ) {
		$terms = Subscriptions::normalize_terms( (array) $item['subscription'] );
		$id    = wp_insert_post(
			array(
				'post_type'   => Post_Types::SUBSCRIPTION,
				'post_status' => 'publish',
				'post_title'  => __( 'Subscription', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'post_author' => (int) get_post_meta( $order->get_id(), '_sidccofo_customer_id', true ),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return null;
		}
		wp_update_post(
			array(
				'ID'         => $id,
				/* translators: %d: subscription number. */
				'post_title' => sprintf( __( 'Subscription #%d', 'sidcraft-commerce-for-sidcraft-page-builder' ), $id ),
			)
		);
		$line = array(
			'product_id' => (int) $item['product_id'],
			'name'       => (string) $item['name'],
			'sku'        => (string) ( $item['sku'] ?? '' ),
			'quantity'   => max( 1, (int) $item['quantity'] ),
			'price'      => (float) $terms['recurring'],
			'options'    => (array) ( $item['options'] ?? array() ),
			'selection'  => (array) ( $item['selection'] ?? array() ),
		);
		$meta = array(
			'_sidccofo_sub_status'    => 'pending',
			'_sidccofo_sub_item'      => $line,
			'_sidccofo_sub_period'    => $terms['period'],
			'_sidccofo_sub_interval'  => $terms['interval'],
			'_sidccofo_sub_length'    => $terms['length'],
			'_sidccofo_sub_trial'     => $terms['trial'],
			'_sidccofo_sub_paid'      => 0,
			'_sidccofo_sub_next'      => 0,
			'_sidccofo_sub_end'       => 0,
			'_sidccofo_sub_trial_end' => 0,
			'_sidccofo_sub_currency'  => $order->get_currency(),
			'_sidccofo_sub_customer'  => (int) get_post_meta( $order->get_id(), '_sidccofo_customer_id', true ),
			'_sidccofo_sub_email'     => strtolower( $order->get_email() ),
			'_sidccofo_sub_billing'   => $order->get_billing(),
			'_sidccofo_sub_language'  => (string) get_post_meta( $order->get_id(), '_sidccofo_language', true ),
			'_sidccofo_sub_parent'    => $order->get_id(),
			'_sidccofo_sub_orders'    => array(),
			'_sidccofo_sub_renewal'   => 0,
			'_sidccofo_sub_gateway'   => '',
			'_sidccofo_sub_token'     => array(),
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		$sub = new static( $id );
		/* translators: %d: order number. */
		$sub->add_note( sprintf( __( 'Created from order #%d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
		return $sub;
	}

	/**
	 * Subscriptions started by an order.
	 *
	 * @param int $order_id Order id.
	 * @return Subscription[]
	 */
	public static function for_order( $order_id ) {
		$out = array();
		foreach ( (array) get_post_meta( (int) $order_id, '_sidccofo_subscriptions', true ) as $id ) {
			$sub = self::get( (int) $id );
			if ( $sub ) {
				$out[] = $sub;
			}
		}
		return $out;
	}

	/**
	 * A customer's subscriptions, newest first.
	 *
	 * @param int $user_id User id.
	 * @return Subscription[]
	 */
	public static function for_customer( $user_id ) {
		if ( (int) $user_id < 1 ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'        => Post_Types::SUBSCRIPTION,
				'post_status'      => 'any', // Status is kept in meta.
				'posts_per_page'   => 100,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_key'         => '_sidccofo_sub_customer', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One customer's subscriptions.
				'meta_value'       => (int) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One customer's subscriptions.
			)
		);
		return array_values( array_filter( array_map( array( self::class, 'get' ), $ids ) ) );
	}

	/**
	 * Subscription ids in some statuses, optionally filtered by a meta time.
	 *
	 * @param string[] $statuses Statuses.
	 * @param string   $time_key Meta key holding a timestamp, or ''.
	 * @param int      $before   Only those with 0 < time <= $before.
	 * @param int      $limit    Max.
	 * @param array    $extra    More meta_query clauses.
	 * @param string   $order_by Numeric meta key to sort by (ascending), or '' for the time key / id.
	 * @return int[]
	 */
	public static function find( array $statuses, $time_key = '', $before = 0, $limit = 50, array $extra = array(), $order_by = '' ) {
		$query = array(
			'relation' => 'AND',
			array(
				'key'     => '_sidccofo_sub_status',
				'value'   => $statuses,
				'compare' => 'IN',
			),
		);
		if ( '' !== $time_key ) {
			$query['due'] = array(
				'key'     => $time_key,
				'value'   => array( 1, (int) $before ),
				'compare' => 'BETWEEN',
				'type'    => 'NUMERIC',
			);
		}
		if ( '' !== $order_by ) {
			$query['sort'] = array(
				'key'  => $order_by,
				'type' => 'NUMERIC',
			);
		}
		foreach ( $extra as $clause ) {
			$query[] = $clause;
		}
		$sort = '' !== $order_by ? 'sort' : ( '' !== $time_key ? 'due' : 'ID' );
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => Post_Types::SUBSCRIPTION,
					'post_status'      => 'any', // Status is kept in meta.
					'posts_per_page'   => (int) $limit,
					'fields'           => 'ids',
					'orderby'          => array( $sort => 'ASC' ),
					'suppress_filters' => true,
					'meta_query'       => $query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded cron batch.
				)
			)
		);
	}

	/**
	 * Meta helper.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	protected function meta( $key ) {
		return get_post_meta( $this->id, $key, true );
	}

	/**
	 * Set meta.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function set( $key, $value ) {
		update_post_meta( $this->id, $key, $value );
	}

	/**
	 * Id.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Status (without prefix): pending, active, on-hold, pending-cancel, cancelled, expired.
	 *
	 * @return string
	 */
	public function get_status() {
		$status = (string) $this->meta( '_sidccofo_sub_status' );
		return array_key_exists( $status, self::statuses() ) ? $status : 'pending';
	}

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'pending'        => __( 'Waiting for first payment', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'active'         => __( 'Active', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'on-hold'        => __( 'Payment due', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'pending-cancel' => __( 'Cancelled at period end', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'cancelled'      => __( 'Cancelled', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'expired'        => __( 'Finished', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Status label.
	 *
	 * @return string
	 */
	public function get_status_label() {
		return self::statuses()[ $this->get_status() ];
	}

	/**
	 * Change status and fire hooks.
	 *
	 * @param string $status New status.
	 * @param string $note   Optional note.
	 * @return bool Changed.
	 */
	public function set_status( $status, $note = '' ) {
		$old = $this->get_status();
		if ( $old === $status || ! array_key_exists( $status, self::statuses() ) ) {
			return false;
		}
		$this->set( '_sidccofo_sub_status', $status );
		$labels = self::statuses();
		/* translators: 1: old status, 2: new status. */
		$this->add_note( trim( sprintf( __( 'Status changed from %1$s to %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $labels[ $old ], $labels[ $status ] ) . ' ' . $note ) );

		/**
		 * Fires after a subscription's status changes.
		 *
		 * @param Subscription $sub    Subscription.
		 * @param string       $old    Previous status.
		 * @param string       $status New status.
		 */
		do_action( 'sidcraft_commerce_subscription_status_changed', $this, $old, $status );
		return true;
	}

	/**
	 * The line billed every period.
	 *
	 * @return array product_id, name, sku, quantity, price, options, selection.
	 */
	public function get_item() {
		$item = $this->meta( '_sidccofo_sub_item' );
		return is_array( $item ) ? $item : array(
			'product_id' => 0,
			'name'       => '',
			'sku'        => '',
			'quantity'   => 1,
			'price'      => 0.0,
			'options'    => array(),
			'selection'  => array(),
		);
	}

	/**
	 * Product name × quantity.
	 *
	 * @return string
	 */
	public function get_name() {
		$item = $this->get_item();
		return (int) $item['quantity'] > 1 ? sprintf( '%s × %d', $item['name'], (int) $item['quantity'] ) : (string) $item['name'];
	}

	/**
	 * Billing terms.
	 *
	 * @return array period, interval, length, trial.
	 */
	public function get_terms() {
		return array(
			'period'   => (string) $this->meta( '_sidccofo_sub_period' ),
			'interval' => max( 1, (int) $this->meta( '_sidccofo_sub_interval' ) ),
			'length'   => (int) $this->meta( '_sidccofo_sub_length' ),
			'trial'    => (int) $this->meta( '_sidccofo_sub_trial' ),
		);
	}

	/**
	 * What each period costs before shipping and tax, in the subscription's currency.
	 *
	 * @return float
	 */
	public function get_recurring_subtotal() {
		$item = $this->get_item();
		return round( (float) $item['price'] * (int) $item['quantity'], \SidcraftCommerce\decimals_for( $this->get_currency() ) );
	}

	/**
	 * Billing currency.
	 *
	 * @return string
	 */
	public function get_currency() {
		$code = (string) $this->meta( '_sidccofo_sub_currency' );
		return '' !== $code ? $code : \SidcraftCommerce\base_currency();
	}

	/**
	 * Customer user id.
	 *
	 * @return int
	 */
	public function get_customer_id() {
		return (int) $this->meta( '_sidccofo_sub_customer' );
	}

	/**
	 * Customer email.
	 *
	 * @return string
	 */
	public function get_email() {
		return (string) $this->meta( '_sidccofo_sub_email' );
	}

	/**
	 * Billing fields.
	 *
	 * @return array
	 */
	public function get_billing() {
		$billing = $this->meta( '_sidccofo_sub_billing' );
		return is_array( $billing ) ? $billing : array();
	}

	/**
	 * Language of the first order ('' when not multilingual).
	 *
	 * @return string
	 */
	public function get_language() {
		return (string) $this->meta( '_sidccofo_sub_language' );
	}

	/**
	 * First order.
	 *
	 * @return int
	 */
	public function get_parent_id() {
		return (int) $this->meta( '_sidccofo_sub_parent' );
	}

	/**
	 * Renewal order ids, oldest first.
	 *
	 * @return int[]
	 */
	public function get_renewal_ids() {
		return array_map( 'intval', (array) $this->meta( '_sidccofo_sub_orders' ) );
	}

	/**
	 * Record a renewal order.
	 *
	 * @param int $order_id Order id.
	 */
	public function add_renewal( $order_id ) {
		$ids   = $this->get_renewal_ids();
		$ids[] = (int) $order_id;
		$this->set( '_sidccofo_sub_orders', array_values( array_unique( array_filter( $ids ) ) ) );
	}

	/**
	 * The renewal order waiting for payment.
	 *
	 * @return Order|null
	 */
	public function get_pending_renewal() {
		$order = Order::get( (int) $this->meta( '_sidccofo_sub_renewal' ) );
		return $order && in_array( $order->get_status(), array( 'sidccofo-pending', 'sidccofo-on-hold' ), true ) ? $order : null;
	}

	/**
	 * Next payment date (0 = none).
	 *
	 * @return int
	 */
	public function get_next_payment() {
		return (int) $this->meta( '_sidccofo_sub_next' );
	}

	/**
	 * When access ends (0 = not set).
	 *
	 * @return int
	 */
	public function get_end() {
		return (int) $this->meta( '_sidccofo_sub_end' );
	}

	/**
	 * End of the free trial (0 = none).
	 *
	 * @return int
	 */
	public function get_trial_end() {
		return (int) $this->meta( '_sidccofo_sub_trial_end' );
	}

	/**
	 * Periods paid.
	 *
	 * @return int
	 */
	public function get_paid_count() {
		return (int) $this->meta( '_sidccofo_sub_paid' );
	}

	/**
	 * Payments still to come (null = until cancelled).
	 *
	 * @return int|null
	 */
	public function get_payments_left() {
		$length = $this->get_terms()['length'];
		return $length > 0 ? max( 0, $length - $this->get_paid_count() ) : null;
	}

	/**
	 * Gateway that charges renewals automatically ('' = pay link by email).
	 *
	 * @return string
	 */
	public function get_gateway_id() {
		return (string) $this->meta( '_sidccofo_sub_gateway' );
	}

	/**
	 * Saved payment details for that gateway.
	 *
	 * @return array
	 */
	public function get_token() {
		$token = $this->meta( '_sidccofo_sub_token' );
		return is_array( $token ) ? $token : array();
	}

	/**
	 * Charge renewals automatically with this gateway and token.
	 *
	 * @param string $gateway_id Gateway id ('' to stop).
	 * @param array  $token      Gateway's saved payment details.
	 */
	public function set_token( $gateway_id, array $token ) {
		$this->set( '_sidccofo_sub_gateway', sanitize_key( $gateway_id ) );
		$this->set( '_sidccofo_sub_token', $token );
	}

	/**
	 * Add a history note.
	 *
	 * @param string $note Note.
	 */
	public function add_note( $note ) {
		$log   = $this->meta( '_sidccofo_log' );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time' => time(),
			'note' => wp_strip_all_tags( $note ),
		);
		$this->set( '_sidccofo_log', $log );
	}

	/**
	 * History.
	 *
	 * @return array
	 */
	public function get_notes() {
		$log = $this->meta( '_sidccofo_log' );
		return is_array( $log ) ? array_filter( $log, 'is_array' ) : array();
	}

	/**
	 * The customer can still cancel it.
	 *
	 * @return bool
	 */
	public function can_cancel() {
		return in_array( $this->get_status(), array( 'active', 'on-hold', 'pending' ), true );
	}

	/**
	 * The customer can undo a cancellation at period end.
	 *
	 * @return bool
	 */
	public function can_resume() {
		return 'pending-cancel' === $this->get_status();
	}
}
