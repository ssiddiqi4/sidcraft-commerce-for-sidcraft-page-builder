<?php
/**
 * Product Specifications Unit: brand, attributes, weight and dimensions as
 * a table.
 *
 * Shortcode fallback: [sidccofo_product_specs product_id="42"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use function SidcraftCommerce\resolve_product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Specs
 */
class Product_Specs extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-product-specs';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Product Specifications', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'list';
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
			'heading'    => array(
				'type'    => 'text',
				'label'   => __( 'Heading', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Specifications', 'sidcraft-commerce-for-sidcraft-page-builder' ),
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
		$rows = $product->get_specs();
		if ( ! $rows ) {
			return '';
		}
		return $this->template(
			'units/product-specs.php',
			array(
				'product'  => $product,
				'settings' => $settings,
				'rows'     => $rows,
			)
		);
	}
}
