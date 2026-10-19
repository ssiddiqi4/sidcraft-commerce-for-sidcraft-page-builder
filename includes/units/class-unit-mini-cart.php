<?php
/**
 * Mini Cart Unit: ShopWave's navbar cart badge. Renders a cache-safe
 * placeholder; the live count and subtotal arrive as cart fragments.
 *
 * Shortcode fallback: [sidccofo_mini_cart show_total="yes"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use function SidcraftCommerce\page_url;

defined( 'ABSPATH' ) || exit;

/**
 * Class Mini_Cart
 */
class Mini_Cart extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-mini-cart';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Mini Cart', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'cart';
	}

	/** {@inheritDoc} */
	public function get_assets() {
		return array(
			'styles'  => array( 'sidccofo-commerce' ),
			'scripts' => array( 'sidccofo-cart' ),
		);
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'show_total' => array(
				'type'    => 'switch',
				'label'   => __( 'Show subtotal', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => false,
			),
			'label'      => array(
				'type'    => 'text',
				'label'   => __( 'Accessible label', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Cart', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		return $this->template(
			'units/mini-cart.php',
			array(
				'settings' => $settings,
				'cart_url' => page_url( 'cart' ),
			)
		);
	}
}
