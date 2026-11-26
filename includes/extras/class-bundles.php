<?php
/**
 * Bundles, "frequently bought together" and order bumps.
 *
 * Bundles are sets of existing products sold at a discount when they are
 * bought together. Because the real products go in the cart, their own
 * stock, tax and shipping rules all apply; the discount is a line in the
 * totals ("Bundle: Starter kit"). Set them up under Store → Settings →
 * Store tools → Bundles: one row per bundle with product ids and a percent
 * or fixed discount per set.
 *
 * A product page shows the bundles it belongs to with an "Add bundle" button.
 * The Bundle Unit / [sidccofo_bundle id="1"] shows one anywhere.
 *
 * The order bump is one extra product offered as a tick box at checkout, with
 * an optional discount.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\format_price;
use function SidcraftCommerce\round_amount;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Bundles
 */
class Bundles extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$this->settings(
			'bundles',
			__( 'Bundles and order bump', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'bundles'     => array(
					__( 'Bundles', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'table',
					__( 'Products: ids of the products in the set, separated by commas (find the id in the products list). Percent: off the set. Fixed: off per set. Use one or the other.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					array(
						'name'     => array( __( 'Bundle name', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'Starter kit' ),
						'products' => array( __( 'Products (ids)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', '12, 15, 18' ),
						'percent'  => array( __( 'Percent off', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'amount', '10' ),
						'fixed'    => array( __( 'Fixed off', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'amount', '' ),
					),
				),
				'bump_product' => array( __( 'Order bump product (id)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'A product offered as a tick box at checkout. Empty = no bump.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'bump_text'    => array( __( 'Order bump text', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'For example: Yes, add gift wrapping.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'bump_percent' => array( __( 'Order bump discount %', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Optional.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			)
		);
		Simple_Unit::add(
			'sidccofo-bundle',
			__( 'Product Bundle', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array( 'id' => array( 'type' => 'number', 'label' => __( 'Bundle number (row in Settings, starting at 1)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'section' => 'content', 'default' => 1, 'min' => 1, 'max' => 200 ) ),
			array( $this, 'render_unit' ),
			'package'
		);
		add_filter( 'sidcraft_commerce_cart_fees', array( $this, 'fees' ), 10, 2 );
		add_filter( 'sidcraft_commerce_render_sidccofo-add-to-cart', array( $this, 'after_add_to_cart' ), 10, 2 );
		add_action( 'sidcraft_commerce_checkout_after_fields', array( $this, 'bump_box' ), 20 );
		add_filter( 'sidcraft_commerce_extra_keys', array( $this, 'keys' ) );
	}

	/**
	 * Clean bundle rows.
	 *
	 * @return array[] name, products (ids), percent, fixed (store currency).
	 */
	public static function all() {
		$out = array();
		foreach ( array_values( (array) setting( 'bundles', array() ) ) as $i => $row ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) ( $row['products'] ?? '' ) ) ) ) ) );
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$out[ $i + 1 ] = array(
				'name'     => '' !== trim( (string) ( $row['name'] ?? '' ) ) ? (string) $row['name'] : __( 'Bundle', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'products' => $ids,
				'percent'  => is_numeric( $row['percent'] ?? '' ) ? min( 100.0, max( 0.0, (float) $row['percent'] ) ) : 0.0,
				'fixed'    => is_numeric( $row['fixed'] ?? '' ) ? max( 0.0, (float) $row['fixed'] ) : 0.0,
			);
		}
		return $out;
	}

	/**
	 * The discount a bundle earns in a cart.
	 *
	 * @param array $b    Bundle row.
	 * @param Cart  $cart Cart.
	 * @return float Amount (shopper currency), 0 when the set isn't complete.
	 */
	protected static function discount( array $b, Cart $cart ) {
		$sets = PHP_INT_MAX;
		$sum  = 0.0;
		foreach ( $b['products'] as $id ) {
			$qty = $cart->product_quantity( $id );
			if ( $qty < 1 ) {
				return 0.0;
			}
			$sets = min( $sets, $qty );
		}
		foreach ( $cart->get_items() as $line ) {
			if ( in_array( (int) $line['product_id'], $b['products'], true ) ) {
				$sum += $line['price'] * $sets; // One set's worth of each product.
			}
		}
		if ( $b['percent'] > 0 ) {
			return round_amount( $sum * $b['percent'] / 100 );
		}
		return round_amount( \SidcraftCommerce\Currencies::to_active( $b['fixed'] ) * $sets );
	}

	/**
	 * Bundle discounts as cart lines.
	 *
	 * @param array $fees Fees.
	 * @param Cart  $cart Cart.
	 * @return array
	 */
	public function fees( $fees, $cart ) {
		foreach ( self::all() as $n => $b ) {
			$off = self::discount( $b, $cart );
			if ( $off > 0 ) {
				/* translators: %s: bundle name. */
				$fees[] = array( 'id' => 'bundle_' . $n, 'label' => sprintf( __( 'Bundle: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $b['name'] ), 'amount' => -1 * $off );
			}
		}
		// Order bump discount.
		$bump = absint( setting( 'bump_product', 0 ) );
		$pct  = is_numeric( setting( 'bump_percent', '' ) ) ? (float) setting( 'bump_percent', 0 ) : 0.0;
		if ( $bump && $pct > 0 && $cart->extra( 'bump', '' ) && $cart->product_quantity( $bump ) > 0 ) {
			foreach ( $cart->get_items() as $line ) {
				if ( (int) $line['product_id'] === $bump ) {
					$fees[] = array( 'id' => 'bump', 'label' => __( 'Checkout offer', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'amount' => -1 * round_amount( $line['price'] * $pct / 100 ) );
					break;
				}
			}
		}
		return $fees;
	}

	/**
	 * One bundle's block.
	 *
	 * @param int $n Bundle number.
	 * @return string
	 */
	protected function block( $n ) {
		$all = self::all();
		if ( ! isset( $all[ $n ] ) ) {
			return '';
		}
		$b     = $all[ $n ];
		$items = '';
		$sum   = 0.0;
		foreach ( $b['products'] as $id ) {
			$p = Product::get( $id );
			if ( ! $p || ! $p->can_add_to_cart() || $p->has_options() ) {
				return ''; // Every part must be a simple, buyable product.
			}
			$sum    += $p->get_price();
			$items  .= '<li><a href="' . esc_url( $p->get_permalink() ) . '">' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . '</a> <span>' . wp_kses_post( $p->get_price_html() ) . '</span></li>';
		}
		$off = $b['percent'] > 0 ? round_amount( $sum * $b['percent'] / 100 ) : round_amount( \SidcraftCommerce\Currencies::to_active( $b['fixed'] ) );
		Extras::enqueue();
		return '<div class="sc-bundle"><h3 class="sc-bundle__title">' . esc_html( $b['name'] ) . '</h3><ul class="sc-bundle__items">' . $items . '</ul><p class="sc-bundle__sum">' . esc_html( sprintf( /* translators: 1: total of parts, 2: discount. */ __( 'Together %1$s, you save %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $sum ), format_price( $off ) ) ) . '</p><button type="button" class="sc-btn sc-btn--primary" data-sc-bundle="' . esc_attr( implode( ',', $b['products'] ) ) . '">' . esc_html__( 'Add bundle to cart', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></div>';
	}

	/**
	 * Unit.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public function render_unit( $s ) {
		return $this->block( (int) $s['id'] );
	}

	/**
	 * Show the bundles a product belongs to under its Add to cart box.
	 *
	 * @param string $html     Unit HTML.
	 * @param array  $settings Settings.
	 * @return string
	 */
	public function after_add_to_cart( $html, $settings ) {
		$p = \SidcraftCommerce\resolve_product( (array) $settings );
		if ( ! $p ) {
			return $html;
		}
		$extra = '';
		foreach ( self::all() as $n => $b ) {
			if ( in_array( $p->get_id(), $b['products'], true ) ) {
				$extra .= $this->block( $n );
			}
		}
		return '' === $extra ? $html : preg_replace( '#</div>\s*$#', $extra . '</div>', $html, 1 );
	}

	/**
	 * Cart-extras key for the order bump.
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public function keys( $keys ) {
		$keys['bump'] = static function ( $value, $cart ) {
			$id = absint( setting( 'bump_product', 0 ) );
			if ( ! $id ) {
				return '';
			}
			if ( '1' === (string) $value ) {
				if ( $cart->product_quantity( $id ) < 1 ) {
					$r = $cart->add( $id, 1 );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
				}
				return '1';
			}
			if ( $cart->product_quantity( $id ) > 0 ) {
				$cart->set_quantity( $id, 0 );
			}
			return '';
		};
		return $keys;
	}

	/**
	 * The tick box at checkout.
	 *
	 * @param Cart $cart Cart.
	 */
	public function bump_box( $cart ) {
		$id = absint( setting( 'bump_product', 0 ) );
		$p  = $id ? Product::get( $id ) : null;
		if ( ! $p || ! $p->can_add_to_cart() || $p->has_options() ) {
			return;
		}
		Extras::enqueue();
		$on   = $cart->product_quantity( $id ) > 0 && $cart->extra( 'bump', '' );
		$pct  = is_numeric( setting( 'bump_percent', '' ) ) ? (float) setting( 'bump_percent', 0 ) : 0.0;
		$text = '' !== trim( (string) setting( 'bump_text', '' ) ) ? (string) setting( 'bump_text', '' ) : sprintf( /* translators: %s: product name. */ __( 'Add %s to my order', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_strip_all_tags( $p->get_name() ) );
		echo '<div class="sc-bump"><label><input type="checkbox" value="1" data-sc-extra="bump" ' . checked( (bool) $on, true, false ) . '> <strong>' . esc_html( $text ) . '</strong> <span>' . esc_html( format_price( $p->get_price() ) ) . ( $pct > 0 ? ' · ' . esc_html( sprintf( /* translators: %s: percent. */ __( '%s%% off today', 'sidcraft-commerce-for-sidcraft-page-builder' ), rtrim( rtrim( number_format( $pct, 2 ), '0' ), '.' ) ) ) : '' ) . '</span></label><span class="sc-field__error" data-sc-extra-error></span></div>';
	}
}
