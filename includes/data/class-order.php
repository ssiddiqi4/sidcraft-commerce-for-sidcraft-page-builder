<?php
/**
 * Order model on top of the sidccofo_order post type.
 *
 * Meta: _sidccofo_order_key, _sidccofo_items (array), _sidccofo_subtotal, _sidccofo_shipping,
 * _sidccofo_total, _sidccofo_discount, _sidccofo_coupon_code, _sidccofo_coupon_counted, _sidccofo_currency, _sidccofo_billing (array), _sidccofo_payment_method,
 * _sidccofo_transaction_id, _sidccofo_paypal_order (gateway-specific keys are added by gateways), _sidccofo_customer_id, _sidccofo_notes,
 * _sidccofo_stock_reduced, _sidccofo_sales_recorded, _sidccofo_paid_date, _sidccofo_log (array of notes).
 *
 * Stock is reserved when the order is created, with a conditional update so
 * only one order can take the last unit. Cancelled and Refunded put back the
 * units the order still holds. A pending order that is still unpaid after
 * 24 hours is cancelled and its stock is returned.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Data;

use function SidcraftCommerce\format_price;
use function SidcraftCommerce\round_amount;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order
 */
class Order {

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	protected $id;

	/**
	 * Guard against recursive status changes from save_post.
	 *
	 * @var bool
	 */
	public static $updating = false;

	/**
	 * Get an order.
	 *
	 * @param int $id Post ID.
	 * @return Order|null
	 */
	public static function get( $id ) {
		$post = get_post( absint( $id ) );
		return ( $post && Post_Types::ORDER === $post->post_type ) ? new static( $post->ID ) : null;
	}

	/**
	 * Constructor.
	 *
	 * @param int $id Post ID.
	 */
	public function __construct( $id ) {
		$this->id = (int) $id;
	}

	/**
	 * Create an order from priced line items.
	 *
	 * @param array $args {
	 *     Order data.
	 *
	 *     @type array  $items    [ [ product_id, name, sku, price, quantity, total ], … ].
	 *     @type float  $subtotal Sum of line totals.
	 *     @type float  $discount Coupon discount (subtracted from the subtotal).
	 *     @type string $coupon_code Coupon code used.
	 *     @type float  $shipping Shipping amount.
	 *     @type float  $total    Grand total.
	 *     @type float  $tax      Tax (added, or included when tax_inclusive).
	 *     @type string $tax_label     Tax label, e.g. "VAT".
	 *     @type float  $tax_rate      Rate %.
	 *     @type bool   $tax_inclusive Prices included tax.
	 *     @type string $shipping_zone Shipping zone name.
	 *     @type string $currency ISO code.
	 *     @type array  $billing  Customer fields.
	 *     @type string $payment_method Gateway id.
	 *     @type string $notes    Customer note.
	 * }
	 * @return Order|\WP_Error
	 */
	public static function create( array $args ) {
		$id = wp_insert_post(
			array(
				'post_type'   => Post_Types::ORDER,
				'post_status' => 'sidccofo-pending',
				'post_title'  => __( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		wp_update_post(
			array(
				'ID'         => $id,
				/* translators: %d: order number. */
				'post_title' => sprintf( __( 'Order #%d', 'sidcraft-commerce-for-sidcraft-page-builder' ), $id ),
			)
		);

		$meta = array(
			'_sidccofo_order_key'      => 'sidccofo_' . wp_generate_password( 20, false ),
			'_sidccofo_items'          => $args['items'],
			'_sidccofo_subtotal'       => (string) $args['subtotal'],
			'_sidccofo_discount'       => (string) ( $args['discount'] ?? 0 ),
			'_sidccofo_coupon_code'    => (string) ( $args['coupon_code'] ?? '' ),
			'_sidccofo_shipping'       => (string) $args['shipping'],
			'_sidccofo_gift_card_total' => (string) ( $args['gift_card_total'] ?? 0 ),
			'_sidccofo_total'          => (string) $args['total'],
			'_sidccofo_tax'            => (string) ( $args['tax'] ?? 0 ),
			'_sidccofo_tax_label'      => (string) ( $args['tax_label'] ?? '' ),
			'_sidccofo_tax_rate'       => (string) ( $args['tax_rate'] ?? 0 ),
			'_sidccofo_tax_inclusive'  => empty( $args['tax_inclusive'] ) ? 0 : 1,
			'_sidccofo_shipping_zone'  => (string) ( $args['shipping_zone'] ?? '' ),
			'_sidccofo_currency'       => $args['currency'],
			'_sidccofo_exchange_rate'  => (string) ( $args['exchange_rate'] ?? \SidcraftCommerce\Currencies::rate( (string) $args['currency'] ) ),
			'_sidccofo_billing'        => $args['billing'],
			'_sidccofo_payment_method' => $args['payment_method'],
			'_sidccofo_customer_id'    => get_current_user_id(),
			'_sidccofo_billing_email'  => strtolower( sanitize_email( (string) ( $args['billing']['email'] ?? '' ) ) ),
			'_sidccofo_notes'          => $args['notes'] ?? '',
			'_sidccofo_stock_reduced'  => 0,
			'_sidccofo_refunded_amount' => '0',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		$order = new static( $id );
		$order->add_note( __( 'Order created.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );

		// Reserve stock before the customer is sent to pay. The second buyer of
		// the last unit fails here, and a later cancel or refund puts it back.
		if ( ! $order->reserve_stock() ) {
			wp_delete_post( $id, true );
			return new \WP_Error( 'sidccofo_not_enough_stock', __( 'Not enough stock is left to place this order.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}

		/**
		 * Fires after an order is created (status: sidccofo-pending).
		 *
		 * @param Order $order Order.
		 */
		do_action( 'sidcraft_commerce_order_created', $order );
		return $order;
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
	 * Array meta helper: '' (missing) becomes [] instead of [ '' ].
	 *
	 * @param string $key Key.
	 * @return array
	 */
	protected function meta_array( $key ) {
		$value = $this->meta( $key );
		return is_array( $value ) ? $value : array();
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
	 * Status.
	 *
	 * @return string
	 */
	public function get_status() {
		return (string) get_post_status( $this->id );
	}

	/**
	 * Status label.
	 *
	 * @return string
	 */
	public function get_status_label() {
		$all = Post_Types::order_statuses();
		return $all[ $this->get_status() ] ?? $this->get_status();
	}

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function get_key() {
		return (string) $this->meta( '_sidccofo_order_key' );
	}

	/**
	 * Constant-time key check for the order-received page.
	 *
	 * @param string $key Key from the URL.
	 * @return bool
	 */
	public function key_is_valid( $key ) {
		return '' !== $this->get_key() && hash_equals( $this->get_key(), (string) $key );
	}

	/**
	 * Items.
	 *
	 * @return array
	 */
	public function get_items() {
		return $this->meta_array( '_sidccofo_items' );
	}

	/**
	 * Subtotal.
	 *
	 * @return float
	 */
	public function get_subtotal() {
		return (float) $this->meta( '_sidccofo_subtotal' );
	}

	/**
	 * Coupon discount.
	 *
	 * @return float
	 */
	public function get_discount() {
		return (float) $this->meta( '_sidccofo_discount' );
	}

	/**
	 * Amount paid with gift cards (not included in get_total()).
	 *
	 * @return float
	 */
	public function get_gift_card_total() {
		return (float) $this->meta( '_sidccofo_gift_card_total' );
	}

	/**
	 * Gift cards used: [ [ id, code (masked), amount ], … ].
	 *
	 * @return array[]
	 */
	public function get_gift_cards_used() {
		return $this->meta_array( '_sidccofo_gift_cards_used' );
	}

	/**
	 * Coupon code used ('' = none).
	 *
	 * @return string
	 */
	public function get_coupon_code() {
		return (string) $this->meta( '_sidccofo_coupon_code' );
	}

	/**
	 * Shipping.
	 *
	 * @return float
	 */
	public function get_shipping() {
		return (float) $this->meta( '_sidccofo_shipping' );
	}

	/**
	 * Tax (included in the total either way).
	 *
	 * @return float Tax (included in the total either way).
	 */
	public function get_tax() {
		return (float) $this->meta( '_sidccofo_tax' );
	}

	/**
	 * Tax label.
	 *
	 * @return string
	 */
	public function get_tax_label() {
		$label = (string) $this->meta( '_sidccofo_tax_label' );
		return '' !== $label ? $label : __( 'Tax', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/**
	 * Tax rate.
	 *
	 * @return float
	 */
	public function get_tax_rate() {
		return (float) $this->meta( '_sidccofo_tax_rate' );
	}

	/**
	 * Prices included tax (tax shown as "Includes …").
	 *
	 * @return bool Prices included tax (tax shown as "Includes …").
	 */
	public function prices_include_tax() {
		return (bool) $this->meta( '_sidccofo_tax_inclusive' );
	}

	/**
	 * Shipping zone.
	 *
	 * @return string
	 */
	public function get_shipping_zone() {
		return (string) $this->meta( '_sidccofo_shipping_zone' );
	}

	/**
	 * Total.
	 *
	 * @return float
	 */
	public function get_total() {
		return (float) $this->meta( '_sidccofo_total' );
	}

	/**
	 * Currency.
	 *
	 * @return string
	 */
	public function get_currency() {
		$code = (string) $this->meta( '_sidccofo_currency' );
		return '' !== $code ? $code : \SidcraftCommerce\base_currency();
	}

	/**
	 * Units of the order's currency per 1 of the store currency, when it was
	 * placed (1 for orders in the store currency).
	 *
	 * @return float
	 */
	public function get_exchange_rate() {
		$rate = (float) $this->meta( '_sidccofo_exchange_rate' );
		return $rate > 0 ? $rate : 1.0;
	}

	/**
	 * An amount of this order in the store currency.
	 *
	 * @param float $amount Amount in the order's currency.
	 * @return float
	 */
	public function to_base( $amount ) {
		return \SidcraftCommerce\round_base( (float) $amount / $this->get_exchange_rate() );
	}

	/**
	 * Billing.
	 *
	 * @return array
	 */
	public function get_billing() {
		return $this->meta_array( '_sidccofo_billing' );
	}

	/**
	 * Email.
	 *
	 * @return string
	 */
	public function get_email() {
		$billing = $this->get_billing();
		return (string) ( $billing['email'] ?? '' );
	}

	/**
	 * Payment method.
	 *
	 * @return string
	 */
	public function get_payment_method() {
		return (string) $this->meta( '_sidccofo_payment_method' );
	}

	/**
	 * Payment method name for people: the gateway's title, or "Gift card".
	 *
	 * @return string
	 */
	public function get_payment_method_title() {
		$id = $this->get_payment_method();
		if ( 'gift_card' === $id ) {
			return __( 'Gift card', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( 'free' === $id ) {
			return __( 'No payment needed', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		$gateway = \SidcraftCommerce\Checkout\Checkout::gateway( $id );
		return $gateway ? $gateway->get_title() : $id;
	}

	/**
	 * Transaction id.
	 *
	 * @return string
	 */
	public function get_transaction_id() {
		return (string) $this->meta( '_sidccofo_transaction_id' );
	}

	/**
	 * Date.
	 *
	 * @return string
	 */
	public function get_date() {
		return (string) get_the_date( '', $this->id );
	}

	/**
	 * Is the order paid (Processing, Completed or a status registered as paid)?
	 *
	 * @return bool
	 */
	public function is_paid() {
		return in_array( $this->get_status(), Post_Types::paid_statuses(), true );
	}

	/**
	 * Order-received URL including the secret key.
	 *
	 * @return string
	 */
	public function get_received_url() {
		// The order-received page in the language the order was placed in
		// (payment providers send shoppers back without one).
		$page = \SidcraftCommerce\Multilingual::for_order(
			$this,
			static function () {
				return \SidcraftCommerce\page_url( 'received' );
			}
		);
		return add_query_arg(
			array(
				'sidccofo_order' => $this->id,
				'key'      => $this->get_key(),
			),
			$page
		);
	}

	/**
	 * Append an internal note to the order log.
	 *
	 * @param string $note Note.
	 */
	public function add_note( $note ) {
		$log   = $this->meta_array( '_sidccofo_log' );
		$log[] = array(
			'time' => time(),
			'note' => wp_strip_all_tags( $note ),
		);
		update_post_meta( $this->id, '_sidccofo_log', $log );
	}

	/**
	 * Notes.
	 *
	 * @return array
	 */
	public function get_notes() {
		return array_filter( $this->meta_array( '_sidccofo_log' ), 'is_array' );
	}

	/**
	 * Change status, adjust stock and fire hooks.
	 *
	 * @param string $new_status New status (sc-*).
	 * @param string $note Optional note.
	 * @return bool True when the status changed.
	 */
	public function set_status( $new_status, $note = '' ) {
		$old = $this->get_status();
		if ( $old === $new_status || ! array_key_exists( $new_status, Post_Types::order_statuses() ) ) {
			return false;
		}

		self::$updating = true;
		wp_update_post(
			array(
				'ID'          => $this->id,
				'post_status' => $new_status,
			)
		);
		self::$updating = false;

		// Stock is reserved when the order is created. Older orders that have
		// not reserved yet still reserve on the first holding status. Cancel
		// and Refunded put back only the units this order still holds.
		if ( in_array( $new_status, Post_Types::stock_statuses(), true ) && ! $this->meta( '_sidccofo_stock_reduced' ) ) {
			if ( ! $this->reserve_stock() ) {
				self::$updating = true;
				wp_update_post(
					array(
						'ID'          => $this->id,
						'post_status' => $old,
					)
				);
				self::$updating = false;
				$this->add_note( __( 'Not enough stock is left to reserve for this order. The status was not changed.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
				return false;
			}
		} elseif ( in_array( $new_status, array( 'sidccofo-cancelled', 'sidccofo-refunded' ), true ) && $this->meta( '_sidccofo_stock_reduced' ) ) {
			$this->adjust_stock( 1 );
			update_post_meta( $this->id, '_sidccofo_stock_reduced', 0 );
			if ( 'sidccofo-refunded' === $new_status ) {
				update_post_meta( $this->id, '_sidccofo_refunded_amount', (string) round_amount( $this->get_total() ) );
			}
		}

		// Coupon usage: counted with the stock reservation, given back with it.
		if ( '' !== $this->get_coupon_code() ) {
			$coupon = Coupon::get_by_code( $this->get_coupon_code() );
			if ( $coupon && $this->meta( '_sidccofo_stock_reduced' ) && ! $this->meta( '_sidccofo_coupon_counted' ) ) {
				$coupon->adjust_usage( 1 );
				update_post_meta( $this->id, '_sidccofo_coupon_counted', 1 );
			} elseif ( $coupon && ! $this->meta( '_sidccofo_stock_reduced' ) && $this->meta( '_sidccofo_coupon_counted' ) ) {
				$coupon->adjust_usage( -1 );
				update_post_meta( $this->id, '_sidccofo_coupon_counted', 0 );
			}
		}

		// Units sold (for "Best selling"): once, when the order counts as paid,
		// whether via a gateway or the owner marking an offline order paid.
		if ( in_array( $new_status, Post_Types::paid_statuses(), true ) && ! $this->meta( '_sidccofo_sales_recorded' ) ) {
			foreach ( $this->get_items() as $item ) {
				$product = Product::get( $item['product_id'] );
				if ( $product ) {
					$product->record_sale( $item['quantity'] );
				}
			}
			update_post_meta( $this->id, '_sidccofo_sales_recorded', 1 );
			Product::bump_query_version();
		}

		$labels = Post_Types::order_statuses();
		$this->add_note(
			trim(
				sprintf(
					/* translators: 1: old status, 2: new status. */
					__( 'Status changed from %1$s to %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					$labels[ $old ] ?? $old,
					$labels[ $new_status ]
				) . ' ' . $note
			)
		);

		/**
		 * Fires after an order status changes. Emails hook in here.
		 *
		 * @param Order  $order Order.
		 * @param string $old   Previous status.
		 * @param string $new_status New status.
		 */
		do_action( 'sidcraft_commerce_order_status_changed', $this, $old, $new_status );
		do_action( "sidcraft_commerce_order_status_{$new_status}", $this, $old );
		return true;
	}

	/**
	 * Mark paid. Idempotent: a webhook and the return page may both call it.
	 *
	 * @param string $transaction_id Gateway reference.
	 * @return bool True if this call completed the payment.
	 */
	public function payment_complete( $transaction_id = '' ) {
		if ( ! in_array( $this->get_status(), array( 'sidccofo-pending', 'sidccofo-on-hold' ), true ) ) {
			return false;
		}
		if ( $transaction_id ) {
			update_post_meta( $this->id, '_sidccofo_transaction_id', sanitize_text_field( $transaction_id ) );
		}
		update_post_meta( $this->id, '_sidccofo_paid_date', time() );

		if ( ! $this->set_status( 'sidccofo-processing', __( 'Payment received.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) ) {
			return false;
		}
		do_action( 'sidcraft_commerce_payment_complete', $this );
		return true;
	}

	/**
	 * Move every line's stock by direction × quantity.
	 *
	 * @param int $direction -1 to sell, 1 to restore.
	 */
	protected function adjust_stock( $direction ) {
		$direction = (int) $direction;
		if ( $direction < 0 ) {
			$taken = array();
			foreach ( $this->get_items() as $item ) {
				$product = Product::get( $item['product_id'] );
				$qty     = (int) $item['quantity'];
				if ( ! $product || ! $product->managing_stock() || $qty < 1 ) {
					continue;
				}
				if ( ! $product->adjust_stock( -1 * $qty ) ) {
					foreach ( $taken as $product_id => $taken_qty ) {
						$restore = Product::get( $product_id );
						if ( $restore ) {
							$restore->adjust_stock( $taken_qty );
						}
					}
					return false;
				}
				$taken[ $product->get_id() ] = ( $taken[ $product->get_id() ] ?? 0 ) + $qty;
			}
			// Option choices with their own stock count, all or nothing too.
			if ( ! Choice_Stock::take_for_order( $this->id, $this->get_items() ) ) {
				foreach ( $taken as $product_id => $taken_qty ) {
					$restore = Product::get( $product_id );
					if ( $restore ) {
						$restore->adjust_stock( $taken_qty );
					}
				}
				return false;
			}
			return true;
		}

		if ( $direction > 0 ) {
			foreach ( $this->quantities_held() as $product_id => $qty ) {
				if ( $qty < 1 ) {
					continue;
				}
				$product = Product::get( $product_id );
				if ( $product ) {
					$product->adjust_stock( $qty );
				}
			}
			Choice_Stock::release_for_order( $this->id );
			update_post_meta( $this->id, '_sidccofo_refunded_qty', $this->ordered_quantities() );
		}
		return true;
	}

	/**
	 * Reserve this order's units. Safe to call again.
	 *
	 * @return bool False when any tracked product no longer has the quantity.
	 */
	public function reserve_stock() {
		if ( $this->meta( '_sidccofo_stock_reduced' ) ) {
			return true;
		}
		if ( ! $this->adjust_stock( -1 ) ) {
			return false;
		}
		// Every unit was taken again (e.g. a cancelled order reopened): nothing is refunded any more.
		delete_post_meta( $this->id, '_sidccofo_refunded_qty' );
		update_post_meta( $this->id, '_sidccofo_stock_reduced', 1 );
		if ( '' !== $this->get_coupon_code() && ! $this->meta( '_sidccofo_coupon_counted' ) ) {
			$coupon = Coupon::get_by_code( $this->get_coupon_code() );
			if ( $coupon ) {
				$coupon->adjust_usage( 1 );
				update_post_meta( $this->id, '_sidccofo_coupon_counted', 1 );
			}
		}
		return true;
	}

	/**
	 * Units ordered, summed by product.
	 *
	 * @return array<int,int>
	 */
	public function ordered_quantities() {
		$map = array();
		foreach ( $this->get_items() as $item ) {
			$product_id           = (int) $item['product_id'];
			$map[ $product_id ] = ( $map[ $product_id ] ?? 0 ) + (int) $item['quantity'];
		}
		return $map;
	}

	/**
	 * Units already returned to stock by a partial or full refund.
	 *
	 * @return array<int,int>
	 */
	public function refunded_quantities() {
		$raw = $this->meta( '_sidccofo_refunded_qty' );
		$map = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $product_id => $qty ) {
				$map[ (int) $product_id ] = max( 0, (int) $qty );
			}
		}
		return $map;
	}

	/**
	 * Units this order still holds.
	 *
	 * @return array<int,int>
	 */
	public function quantities_held() {
		$refunded = $this->refunded_quantities();
		$held     = array();
		foreach ( $this->ordered_quantities() as $product_id => $qty ) {
			$held[ $product_id ] = max( 0, $qty - (int) ( $refunded[ $product_id ] ?? 0 ) );
		}
		return $held;
	}

	/**
	 * Amount already refunded, in major units.
	 *
	 * @return float
	 */
	public function get_refunded_amount() {
		return round_amount( (float) $this->meta( '_sidccofo_refunded_amount' ) );
	}

	/**
	 * Remember a provider event. A repeat of the same id does nothing.
	 *
	 * @param string $event_id Provider event id. Empty always claims.
	 * @return bool False when this event was already applied.
	 */
	public function claim_event( $event_id ) {
		$event_id = sanitize_text_field( (string) $event_id );
		if ( '' === $event_id ) {
			return true;
		}
		$key = '_sidccofo_evt_' . md5( $event_id );
		return false !== add_post_meta( $this->id, $key, $event_id, true );
	}

	/**
	 * Attach the order to a WordPress user.
	 *
	 * @param int $user_id User ID.
	 */
	public function assign_customer( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return;
		}
		update_post_meta( $this->id, '_sidccofo_customer_id', $user_id );
		wp_update_post(
			array(
				'ID'          => $this->id,
				'post_author' => $user_id,
			)
		);

		/**
		 * Fires when an order is given to a customer account (an account made
		 * at checkout, or a guest order claimed later).
		 *
		 * @param Order $order   Order.
		 * @param int   $user_id User ID.
		 */
		do_action( 'sidcraft_commerce_order_customer_assigned', $this, $user_id );
	}

	/**
	 * Record a refund. A provider event id is applied once.
	 *
	 * When $cumulative is true, $amount is the total refunded so far (Stripe).
	 * Otherwise $amount is this refund only (PayPal, or the order screen).
	 * Named quantities are returned to stock. An amount that covers the order
	 * total marks the order Refunded, which returns whatever stock is still held.
	 *
	 * @param float $amount      Major units.
	 * @param array $quantities  product id => units to return.
	 * @param string $event_id   Provider event id, or a unique manual id.
	 * @param string $note       History note.
	 * @param bool  $cumulative  $amount is the running total.
	 * @return bool False when the event was already applied.
	 */
	public function record_refund( $amount, array $quantities, $event_id, $note, $cumulative = false ) {
		if ( ! $this->claim_event( $event_id ) ) {
			return false;
		}

		$total   = round_amount( $this->get_total() );
		$already = $this->get_refunded_amount();
		$amount  = round_amount( (float) $amount );
		if ( $cumulative ) {
			$target = round_amount( min( $total, max( $already, $amount ) ) );
		} else {
			$target = round_amount( min( $total, $already + max( 0, $amount ) ) );
		}
		$delta = round_amount( $target - $already );

		if ( $quantities ) {
			$this->restore_item_quantities( $quantities );
		}

		$full = $total > 0 && ( $target + 0.00001 ) >= $total && 'sidccofo-refunded' !== $this->get_status();
		if ( $full ) {
			$this->set_status( 'sidccofo-refunded', $note );
			update_post_meta( $this->id, '_sidccofo_refunded_amount', (string) $total );
			return true;
		}

		update_post_meta( $this->id, '_sidccofo_refunded_amount', (string) $target );
		if ( $delta > 0 ) {
			/**
			 * Part of an order was refunded (the order stays open).
			 *
			 * @param Order $order    Order.
			 * @param float $refunded Amount refunded in total so far.
			 * @param float $total    Order total.
			 */
			do_action( 'sidcraft_commerce_order_partial_refund', $this, $target, $total );
		}
		if ( $delta > 0 || $quantities ) {
			$this->add_note(
				trim(
					$note . ' ' . sprintf(
						/* translators: 1: amount refunded this time, 2: amount refunded in total. */
						__( 'Partial refund %1$s (%2$s refunded in total). Stock is returned only for the quantities named on the refund.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						\SidcraftCommerce\format_in( $delta, $this->get_currency() ),
						\SidcraftCommerce\format_in( $target, $this->get_currency() )
					)
				)
			);
		}
		return true;
	}

	/**
	 * Return named units to stock, never more than this order still holds.
	 *
	 * @param array $quantities product id => units.
	 */
	public function restore_item_quantities( array $quantities ) {
		$held = $this->quantities_held();
		$map  = $this->refunded_quantities();
		foreach ( $quantities as $product_id => $qty ) {
			$product_id = (int) $product_id;
			$qty        = min( max( 0, (int) $qty ), (int) ( $held[ $product_id ] ?? 0 ) );
			if ( $qty < 1 ) {
				continue;
			}
			$product = Product::get( $product_id );
			if ( $product && $product->managing_stock() ) {
				$product->adjust_stock( $qty );
			}
			Choice_Stock::release_units( $this->id, $product_id, $qty );
			$map[ $product_id ] = (int) ( $map[ $product_id ] ?? 0 ) + $qty;
			$held[ $product_id ] = max( 0, (int) ( $held[ $product_id ] ?? 0 ) - $qty );
		}
		update_post_meta( $this->id, '_sidccofo_refunded_qty', $map );
	}

	/**
	 * Open a payment dispute. Stock stays reserved while the dispute is open.
	 *
	 * @param string $event_id Provider event id.
	 * @param string $note     History note.
	 * @return bool
	 */
	public function open_dispute( $event_id, $note ) {
		if ( ! $this->claim_event( $event_id ) ) {
			return false;
		}
		if ( in_array( $this->get_status(), array( 'sidccofo-disputed', 'sidccofo-refunded', 'sidccofo-cancelled' ), true ) ) {
			$this->add_note( $note );
			return false;
		}
		update_post_meta( $this->id, '_sidccofo_status_before_dispute', $this->get_status() );
		return $this->set_status( 'sidccofo-disputed', $note );
	}

	/**
	 * Close a dispute. A lost dispute refunds the order and returns stock.
	 * A won dispute goes back to the status from before the dispute.
	 *
	 * @param string $event_id Provider event id.
	 * @param bool   $lost     Buyer won the dispute.
	 * @param string $note     History note.
	 * @return bool
	 */
	public function close_dispute( $event_id, $lost, $note ) {
		if ( ! $this->claim_event( $event_id ) ) {
			return false;
		}
		if ( $lost ) {
			if ( 'sidccofo-refunded' === $this->get_status() ) {
				$this->add_note( $note );
				return true;
			}
			return $this->set_status( 'sidccofo-refunded', $note );
		}
		$back = (string) $this->meta( '_sidccofo_status_before_dispute' );
		if ( ! array_key_exists( $back, Post_Types::order_statuses() ) || in_array( $back, array( 'sidccofo-disputed', 'sidccofo-refunded', 'sidccofo-cancelled', 'sidccofo-pending' ), true ) ) {
			$back = 'sidccofo-processing';
		}
		if ( $this->get_status() === $back ) {
			$this->add_note( $note );
			return true;
		}
		return $this->set_status( $back, $note );
	}

	/**
	 * Cancel pending orders whose stock has been held for a day.
	 */
	public static function release_abandoned() {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ORDER,
				'post_status'    => 'sidccofo-pending',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'column' => 'post_date_gmt',
						'before' => '24 hours ago',
					),
				),
				// Subscription renewals wait for their own grace period (Subscriptions::run).
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded hourly batch.
					array(
						'key'     => '_sidccofo_renewal_of',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$order = self::get( (int) $id );
			if ( $order && 'sidccofo-pending' === $order->get_status() ) {
				$order->set_status( 'sidccofo-cancelled', __( 'Payment was not completed within 24 hours. Reserved stock was returned.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			}
		}
	}
}
