<?php
/**
 * Local pickup, rate tables and a handling fee, on top of the shipping zones.
 *
 * Settings (Store → Settings → Store tools → Local pickup and rate tables):
 *   ship_pickup            Offer local pickup at checkout.
 *   ship_pickup_label      Name of the option.
 *   ship_pickup_cost       What pickup costs (0 = free).
 *   ship_pickup_address    Where to collect (shown to the buyer and on the order).
 *   ship_table_basis       subtotal | weight | units: what the table is matched on.
 *   ship_table             Rows: from, to (empty = no limit), rate. When the table has rows
 *                          it replaces the zone's rate; a 0 rate row is free shipping.
 *   ship_handling          Added to shipping that is not free.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Catalog;
use SidcraftCommerce\Currencies;
use SidcraftCommerce\Data\Order;
use function SidcraftCommerce\format_price;
use function SidcraftCommerce\round_amount;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Shipping_Methods
 */
class Shipping_Methods extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		$this->settings(
			'shipping',
			__( 'Local pickup and rate tables', $d ),
			array(
				'ship_pickup'         => array( __( 'Offer local pickup', $d ), 'checkbox', __( 'Customers can collect their order instead of having it shipped.', $d ) ),
				'ship_pickup_label'   => array( __( 'Pickup option name', $d ), 'text' ),
				'ship_pickup_cost'    => array( __( 'Pickup cost', $d ), 'text', __( '0 = free.', $d ) ),
				'ship_pickup_address' => array( __( 'Pickup address and hours', $d ), 'textarea', __( 'Shown at checkout and kept on the order.', $d ) ),
				'ship_table_basis'    => array(
					__( 'Rate table is matched on', $d ),
					array(
						'subtotal' => __( 'Order subtotal', $d ),
						'weight'   => __( 'Order weight', $d ),
						'units'    => __( 'Number of items', $d ),
					),
				),
				'ship_table'          => array(
					__( 'Rate table', $d ),
					'table',
					__( 'The first row where From ≤ value < To is used (leave To empty for no upper limit) and replaces the zone’s rate. A rate of 0 is free shipping. Amounts are in the store currency.', $d ),
					array(
						'from' => array( __( 'From', $d ), 'amount', '0' ),
						'to'   => array( __( 'To (below)', $d ), 'amount', '' ),
						'rate' => array( __( 'Rate', $d ), 'amount', '5.00' ),
					),
				),
				'ship_handling'       => array( __( 'Handling fee', $d ), 'text', __( 'Added to any shipping that is not free. 0 = none.', $d ) ),
			)
		);

		add_filter( 'sidcraft_commerce_extra_keys', array( $this, 'keys' ) );
		add_filter( 'sidcraft_commerce_cart_shipping', array( $this, 'cost' ), 10, 2 );
		add_filter( 'sidcraft_commerce_checkout_fields', array( $this, 'fields' ) );
		add_action( 'sidcraft_commerce_checkout_after_fields', array( $this, 'choice' ) );
		add_action( 'sidcraft_commerce_checkout_input', array( $this, 'input' ), 10, 2 );
		add_action( 'sidcraft_commerce_order_created', array( $this, 'stamp' ) );
	}

	/**
	 * Is local pickup offered?
	 *
	 * @return bool
	 */
	public static function pickup_offered() {
		return (bool) setting( 'ship_pickup', 0 );
	}

	/**
	 * Is pickup the chosen delivery for this cart?
	 *
	 * @param Cart $cart Cart.
	 * @return bool
	 */
	public static function pickup_chosen( Cart $cart ) {
		return self::pickup_offered() && 'pickup' === $cart->extra( 'delivery', 'ship' ) && $cart->needs_shipping();
	}

	/**
	 * Whitelist the cart-extras key.
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public function keys( $keys ) {
		$keys['delivery'] = static function ( $value ) {
			return 'pickup' === $value && Shipping_Methods::pickup_offered() ? 'pickup' : '';
		};
		return $keys;
	}

	/**
	 * Rate table, cleaned.
	 *
	 * @return array[] from, to (null = open), rate.
	 */
	public static function table() {
		$rows = array();
		foreach ( (array) setting( 'ship_table', array() ) as $row ) {
			if ( ! is_numeric( $row['rate'] ?? '' ) ) {
				continue;
			}
			$rows[] = array(
				'from' => is_numeric( $row['from'] ?? '' ) ? (float) $row['from'] : 0.0,
				'to'   => is_numeric( $row['to'] ?? '' ) && '' !== (string) $row['to'] ? (float) $row['to'] : null,
				'rate' => max( 0.0, (float) $row['rate'] ),
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $a['from'] <=> $b['from'];
			}
		);
		return $rows;
	}

	/**
	 * Final shipping cost.
	 *
	 * @param float $cost Zone cost (already free for a free-shipping coupon).
	 * @param Cart  $cart Cart.
	 * @return float
	 */
	public function cost( $cost, $cart ) {
		if ( ! $cart instanceof Cart || ! $cart->needs_shipping() || ! setting( 'collect_address', 1 ) ) {
			return $cost;
		}
		if ( $cart->has_free_shipping_coupon() ) {
			return 0.0;
		}
		if ( self::pickup_chosen( $cart ) ) {
			return round_amount( Currencies::to_active( max( 0.0, (float) setting( 'ship_pickup_cost', 0 ) ) ) );
		}
		$table = self::table();
		if ( $table ) {
			switch ( (string) setting( 'ship_table_basis', 'subtotal' ) ) {
				case 'weight':
					$value = Catalog::cart_weight( $cart );
					break;
				case 'units':
					$value = (float) Catalog::cart_units( $cart );
					break;
				default:
					$value = Currencies::to_base( round_amount( $cart->get_goods_subtotal() - $cart->get_discount() ) );
			}
			$cost = null;
			foreach ( $table as $row ) {
				if ( $value >= $row['from'] && ( null === $row['to'] || $value < $row['to'] ) ) {
					$cost = Currencies::to_active( $row['rate'] );
					break;
				}
			}
			if ( null === $cost ) {
				$cost = Currencies::to_active( (float) end( $table )['rate'] ); // Above every row: the last rate.
			}
		}
		if ( $cost > 0 ) {
			$cost += Currencies::to_active( max( 0.0, (float) setting( 'ship_handling', 0 ) ) );
		}
		return round_amount( $cost );
	}

	/**
	 * Pickup buyers don't need a street address.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function fields( $fields ) {
		if ( ! self::pickup_offered() || is_admin() ) {
			return $fields;
		}
		if ( self::pickup_chosen( Cart::instance() ) ) {
			foreach ( array( 'address_1', 'city', 'postcode' ) as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$fields[ $key ][2] = false;
				}
			}
		}
		return $fields;
	}

	/**
	 * The delivery choice at checkout.
	 *
	 * @param Cart $cart Cart.
	 */
	public function choice( $cart ) {
		if ( ! self::pickup_offered() || ! $cart->needs_shipping() ) {
			return;
		}
		Extras::enqueue();
		$pickup = 'pickup' === $cart->extra( 'delivery', 'ship' );
		$cost   = (float) setting( 'ship_pickup_cost', 0 );
		$label  = '' !== trim( (string) setting( 'ship_pickup_label', '' ) ) ? (string) setting( 'ship_pickup_label', '' ) : __( 'Local pickup', 'sidcraft-commerce-for-sidcraft-page-builder' );
		?>
		<fieldset class="sc-choice" data-sc-delivery>
			<legend><?php esc_html_e( 'Delivery', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></legend>
			<label class="sc-choice__opt">
				<input type="radio" name="delivery" value="ship" data-sc-extra="delivery" <?php checked( ! $pickup ); ?>>
				<span><?php esc_html_e( 'Ship to me', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span>
			</label>
			<label class="sc-choice__opt">
				<input type="radio" name="delivery" value="pickup" data-sc-extra="delivery" <?php checked( $pickup ); ?>>
				<span>
					<?php echo esc_html( $label ); ?> (<?php echo esc_html( $cost > 0 ? format_price( Currencies::to_active( $cost ) ) : __( 'Free', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?>)
					<?php if ( '' !== trim( (string) setting( 'ship_pickup_address', '' ) ) ) : ?>
						<small><?php echo nl2br( esc_html( (string) setting( 'ship_pickup_address', '' ) ) ); ?></small>
					<?php endif; ?>
				</span>
			</label>
			<span class="sc-field__error" data-sc-extra-error></span>
		</fieldset>
		<?php
	}

	/**
	 * Use the delivery method that was submitted.
	 *
	 * @param array $input Checkout input.
	 * @param Cart  $cart  Cart.
	 */
	public function input( $input, $cart ) {
		if ( ! isset( $input['delivery'] ) ) {
			return;
		}
		$cart->set_extra( 'delivery', 'pickup' === $input['delivery'] && self::pickup_offered() ? 'pickup' : '' );
	}

	/**
	 * Put the pickup choice on the new order.
	 *
	 * @param Order $order Order.
	 */
	public function stamp( $order ) {
		$cart = Cart::instance();
		if ( ! self::pickup_chosen( $cart ) ) {
			return;
		}
		$label = '' !== trim( (string) setting( 'ship_pickup_label', '' ) ) ? (string) setting( 'ship_pickup_label', '' ) : __( 'Local pickup', 'sidcraft-commerce-for-sidcraft-page-builder' );
		update_post_meta( $order->get_id(), '_sidccofo_shipping_zone', $label );
		update_post_meta( $order->get_id(), '_sidccofo_delivery', 'pickup' );
		$order->add_note( sprintf( /* translators: %s: pickup address. */ __( 'Local pickup. %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), (string) setting( 'ship_pickup_address', '' ) ) );
	}
}
