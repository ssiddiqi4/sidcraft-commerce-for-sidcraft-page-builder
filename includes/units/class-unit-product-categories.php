<?php
/**
 * Product Categories Unit: a grid of category cards (image, name, number of
 * products) linking to each category's page.
 *
 * Shortcode fallback: [sidccofo_product_categories parent="clothing" columns="4"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Data\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Categories
 */
class Product_Categories extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-product-categories';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Product Categories', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'category';
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'store', 'categories', 'collections', 'shop', 'departments' );
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'parent'     => array(
				'type'    => 'terms',
				'taxonomy' => Post_Types::CATEGORY,
				'label'   => __( 'Sub-categories of (slug; empty = top level)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'query',
				'default' => '',
			),
			'limit'      => array(
				'type'    => 'number',
				'label'   => __( 'Number of categories', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'query',
				'default' => 8,
				'min'     => 1,
				'max'     => 48,
			),
			'hide_empty' => array(
				'type'    => 'switch',
				'label'   => __( 'Hide categories without products', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'query',
				'default' => true,
			),
			'columns'    => array(
				'type'       => 'number',
				'label'      => __( 'Columns (desktop)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section'    => 'layout',
				'default'    => 4,
				'min'        => 1,
				'max'        => 6,
				'responsive' => true,
			),
			'show_count' => array(
				'type'    => 'switch',
				'label'   => __( 'Number of products', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'layout',
				'default' => true,
			),
		);
	}

	/** {@inheritDoc} */
	protected function render_html( array $settings ) {
		$parent = 0;
		$slug   = sanitize_title( (string) $settings['parent'] );
		if ( '' !== $slug ) {
			$term   = get_term_by( 'slug', $slug, Post_Types::CATEGORY );
			$parent = $term ? (int) $term->term_id : -1;
		}
		$terms = -1 === $parent ? array() : get_terms(
			array(
				'taxonomy'   => Post_Types::CATEGORY,
				'parent'     => $parent,
				'hide_empty' => (bool) $settings['hide_empty'],
				'number'     => (int) $settings['limit'],
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return current_user_can( 'edit_posts' )
				? '<p class="sc-empty">' . esc_html__( 'No categories match these settings.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>'
				: '';
		}
		return $this->template(
			'units/product-categories.php',
			array(
				'terms'    => $terms,
				'settings' => $settings,
			)
		);
	}
}
