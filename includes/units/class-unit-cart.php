<?php
/**
 * Cart Unit: line items with quantity steppers, remove, totals, checkout.
 * Per-visitor output: pages holding it are sent with no-cache headers.
 *
 * Shortcode fallback: [sidccofo_cart]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Cart\Cart as Store_Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cart
 */
class Cart extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-cart';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Cart', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'cart';
	}

	/** {@inheritDoc} */
	public function get_assets() {
		return array(
			'styles'  => array( 'sidccofo-commerce', 'sidccofo-checkout' ),
			'scripts' => array( 'sidccofo-cart', 'sidccofo-checkout' ),
		);
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'empty_text' => array(
				'type'    => 'text',
				'label'   => __( 'Empty-cart message', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Your cart is empty.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		$cart   = Store_Cart::instance();
		$notice = (string) $cart->session()->get( 'notice', '' );
		if ( $notice ) {
			$cart->session()->set( 'notice', '' ); // Show once (from no-JS add).
		}
		return $this->template(
			'units/cart-panel.php',
			array(
				'cart'       => $cart,
				'empty_text' => $settings['empty_text'],
				'notice'     => $notice,
			)
		);
	}
}
