<?php
/**
 * Product Price Unit: regular price, or struck-through regular + sale price.
 *
 * Shortcode fallback: [sidccofo_product_price product_id="42"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use function SidcraftCommerce\resolve_product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Price
 */
class Product_Price extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-product-price';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Product Price', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'tag';
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'product_id' => array(
				'type'    => 'product',
				'label'   => __( 'Product', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => '',
			),
			'size'       => array(
				'type'    => 'select',
				'label'   => __( 'Size', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'style',
				'default' => 'lg',
				'options' => array(
					'md' => __( 'Medium', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'lg' => __( 'Large', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'xl' => __( 'Extra large', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
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
			return '';
		}
		return $this->template(
			'units/product-price.php',
			array(
				'product'  => $product,
				'settings' => $settings,
			)
		);
	}
}
