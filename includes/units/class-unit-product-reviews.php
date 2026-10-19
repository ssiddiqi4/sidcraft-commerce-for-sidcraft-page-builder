<?php
/**
 * Product Reviews Unit: rating summary, clickable stars that open the
 * verified-review popup, and the list of reviews.
 *
 * Added automatically below the description on product pages (Store →
 * Settings → "Show reviews on product pages"); place it yourself in a
 * Sidcraft layout to control where it goes.
 *
 * Shortcode fallback: [sidccofo_product_reviews product_id="42" limit="20"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Reviews;
use function SidcraftCommerce\resolve_product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Reviews
 */
class Product_Reviews extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-product-reviews';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Product Reviews', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'testimonial';
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
			'product_id' => array(
				'type'    => 'product',
				'label'   => __( 'Product', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => '',
			),
			'title'      => array(
				'type'    => 'text',
				'label'   => __( 'Heading', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Customer reviews', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
			'limit'      => array(
				'type'    => 'number',
				'label'   => __( 'Reviews shown', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => 20,
				'min'     => 1,
				'max'     => 100,
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
			'single/reviews.php',
			array(
				'product'      => $product,
				'settings'     => $settings,
				'reviews'      => Reviews::get_reviews( $product->get_id(), (int) $settings['limit'] ),
				'distribution' => Reviews::distribution( $product->get_id() ),
			)
		);
	}
}
