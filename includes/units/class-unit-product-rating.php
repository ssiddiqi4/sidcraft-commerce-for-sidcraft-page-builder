<?php
/**
 * Product Rating Unit: ShopWave-style stars plus review count.
 *
 * Shortcode fallback: [sidccofo_product_rating product_id="42"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use function SidcraftCommerce\resolve_product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Rating
 */
class Product_Rating extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-product-rating';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Product Rating', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'star-filled';
	}

	/** {@inheritDoc} */
	public function get_assets() {
		return array(
			'styles'  => array( 'sidccofo-commerce', 'sidccofo-reviews' ),
			'scripts' => array( 'sidccofo-cart', 'sidccofo-reviews' ),
		);
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'product_id'   => array(
				'type'    => 'product',
				'label'   => __( 'Product', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => '',
			),
			'show_count'   => array(
				'type'    => 'switch',
				'label'   => __( 'Review count', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
			'clickable'    => array(
				'type'    => 'switch',
				'label'   => __( 'Click a star to rate (verified customers)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
			'hide_if_none' => array(
				'type'    => 'switch',
				'label'   => __( 'Hide when there are no reviews', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
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
			return '';
		}
		if ( $settings['hide_if_none'] && ! $product->get_review_count() ) {
			return '';
		}
		return $this->template(
			'units/product-rating.php',
			array(
				'product'  => $product,
				'settings' => $settings,
			)
		);
	}
}
