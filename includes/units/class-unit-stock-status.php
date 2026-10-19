<?php
/**
 * Stock Status Unit: "In stock", "Only 3 left", "Out of stock".
 *
 * Shortcode fallback: [sidccofo_stock_status product_id="42" low_stock="5"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use function SidcraftCommerce\resolve_product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Stock_Status
 */
class Stock_Status extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-stock-status';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Stock Status', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'archive';
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
			'low_stock'  => array(
				'type'        => 'number',
				'label'       => __( 'Show "only N left" at or below', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'description' => __( 'Creates urgency. 0 turns it off.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section'     => 'content',
				'default'     => 5,
				'min'         => 0,
				'max'         => 100,
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

		$qty = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;

		if ( ! $product->is_in_stock() ) {
			$state = 'out';
			$label = __( 'Out of stock', 'sidcraft-commerce-for-sidcraft-page-builder' );
		} elseif ( null !== $qty && $settings['low_stock'] > 0 && $qty <= $settings['low_stock'] ) {
			$state = 'low';
			/* translators: %d: units left in stock. */
			$label = sprintf( _n( 'Only %d left', 'Only %d left', $qty, 'sidcraft-commerce-for-sidcraft-page-builder' ), $qty );
		} else {
			$state = 'in';
			$label = __( 'In stock', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}

		return $this->template(
			'units/stock-status.php',
			array(
				'product'  => $product,
				'settings' => $settings,
				'state'    => apply_filters( 'sidcraft_commerce_stock_state', $state, $product ),
				'label'    => apply_filters( 'sidcraft_commerce_stock_label', $label, $state, $product ),
			)
		);
	}
}
