<?php
/**
 * Catalog details: the specifications table on product pages (brand,
 * attributes, weight, dimensions) and helpers for weights and measures.
 *
 * With "Show specifications on product pages" on (Store → Settings), the
 * table follows the description unless the layout places the Product
 * Specifications Unit itself or the product has nothing to list.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Catalog
 */
class Catalog {

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_filter( 'the_content', array( $this, 'append_specs' ), 26 ); // After the buy box (25).
		add_filter( 'sidcraft_commerce_detected_units', array( $this, 'detect' ) );
	}

	/**
	 * Is the automatic table on for the product being viewed?
	 *
	 * @return bool
	 */
	protected function auto_enabled() {
		if ( ! is_singular( Post_Types::PRODUCT ) || ! setting( 'auto_specs', 1 ) || get_post_meta( get_the_ID(), '_sidccofo_hide_buy_box', true ) ) {
			return false;
		}
		if ( in_array( 'sidccofo-product-specs', Plugin::instance()->assets->units_in_post( get_the_ID() ), true ) ) {
			return false;
		}
		/**
		 * Turn the automatic specifications table on or off for a product.
		 *
		 * @param bool $enabled Enabled.
		 * @param int  $post_id Product ID.
		 */
		return (bool) apply_filters( 'sidcraft_commerce_auto_specs', true, get_the_ID() );
	}

	/**
	 * Load the table's styles with the product page.
	 *
	 * @param string[] $found Unit names.
	 * @return string[]
	 */
	public function detect( $found ) {
		if ( $this->auto_enabled() ) {
			$found[] = 'sidccofo-product-specs';
		}
		return $found;
	}

	/**
	 * Add the table after the description.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function append_specs( $content ) {
		if ( ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() || ! $this->auto_enabled() ) {
			return $content;
		}
		$unit = Plugin::instance()->units->get( 'sidccofo-product-specs' );
		return $unit ? $content . $unit->render( array( 'product_id' => get_the_ID() ) ) : $content;
	}

	/**
	 * A number without trailing zeros, in the site's locale ("1.5", "12").
	 *
	 * @param float $value Value.
	 * @return string
	 */
	public static function number( $value ) {
		$value    = round( (float) $value, 3 );
		$decimals = 0;
		while ( $decimals < 3 && abs( $value - round( $value, $decimals ) ) > 0.0000001 ) {
			++$decimals;
		}
		return number_format_i18n( $value, $decimals );
	}

	/**
	 * Total weight of what needs shipping in a cart (store weight unit).
	 *
	 * @param \SidcraftCommerce\Cart\Cart $cart Cart.
	 * @return float
	 */
	public static function cart_weight( $cart ) {
		$sum = 0.0;
		foreach ( $cart->get_items() as $line ) {
			if ( ! empty( $line['gift_card'] ) || \SidcraftCommerce\Downloads\Downloads::is_virtual( (int) $line['product_id'] ) ) {
				continue;
			}
			$product = $line['product'] instanceof Product ? $line['product'] : Product::get( (int) $line['product_id'] );
			if ( $product ) {
				$sum += $product->get_weight() * (int) $line['quantity'];
			}
		}
		return round( $sum, 3 );
	}
}
