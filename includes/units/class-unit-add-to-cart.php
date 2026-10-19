<?php
/**
 * Add to Cart Unit — quantity stepper + button (ShopWave ProductDetail).
 *
 * Adds via the REST API without a reload; works as a plain form without JS.
 *
 * Shortcode fallback: [sidccofo_add_to_cart product_id="42" show_quantity="yes"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use function SidcraftCommerce\resolve_product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Add_To_Cart
 */
class Add_To_Cart extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-add-to-cart';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Add to Cart', 'sidcraft-commerce-for-sidcraft-page-builder' );
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
			'product_id'    => array(
				'type'        => 'product',
				'label'       => __( 'Product', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'description' => __( 'Leave empty to use the current product.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section'     => 'content',
				'default'     => '',
			),
			'show_quantity' => array(
				'type'    => 'switch',
				'label'   => __( 'Quantity selector', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
			'button_text'   => array(
				'type'    => 'text',
				'label'   => __( 'Button text', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => '',
			),
			'full_width'    => array(
				'type'    => 'switch',
				'label'   => __( 'Full width', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'style',
				'default' => false,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		$product = resolve_product( $settings );
		if ( ! $product ) {
			return current_user_can( 'edit_posts' )
				? '<p class="sc-empty">' . esc_html__( 'Choose a product, or place this Unit on a product page.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>'
				: '';
		}

		return $this->template(
			'units/add-to-cart.php',
			array(
				'product'  => $product,
				'settings' => $settings,
			)
		);
	}
}
