<?php
/**
 * Product Grid Unit — ShopWave's product listing as a Sidcraft Unit.
 *
 * Shortcode fallback: [sidccofo_product_grid source="on_sale" limit="8" columns="4"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Grid
 */
class Product_Grid extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-product-grid';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Product Grid', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'grid-view';
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'store', 'products', 'grid', 'shop', 'loop' );
	}

	/** {@inheritDoc} */
	public function get_assets() {
		return array(
			'styles'  => array( 'sidccofo-commerce', 'sidccofo-quick-view' ),
			'scripts' => array( 'sidccofo-cart', 'sidccofo-quick-view' ),
		);
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'source'           => array(
				'type'    => 'select',
				'label'   => __( 'Products', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'query',
				'default' => 'recent',
				'options' => array(
					'recent'       => __( 'Newest', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'featured'     => __( 'Featured', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'on_sale'      => __( 'On sale', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'best_selling' => __( 'Best selling', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'top_rated'    => __( 'Top rated', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'category'     => __( 'By category', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'tag'          => __( 'By tag', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'ids'          => __( 'Hand-picked', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
			),
			'category'         => array(
				'type'      => 'terms',
				'taxonomy'  => Post_Types::CATEGORY,
				'label'     => __( 'Category slugs (comma-separated)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section'   => 'query',
				'default'   => '',
				'condition' => array( 'source' => 'category' ),
			),
			'tag'              => array(
				'type'      => 'terms',
				'taxonomy'  => Post_Types::TAG,
				'label'     => __( 'Tag slugs (comma-separated)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section'   => 'query',
				'default'   => '',
				'condition' => array( 'source' => 'tag' ),
			),
			'ids'              => array(
				'type'      => 'text',
				'label'     => __( 'Product IDs (comma-separated)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section'   => 'query',
				'default'   => '',
				'condition' => array( 'source' => 'ids' ),
			),
			'limit'            => array(
				'type'    => 'number',
				'label'   => __( 'Number of products', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'query',
				'default' => 8,
				'min'     => 1,
				'max'     => 48,
			),
			'columns'          => array(
				'type'       => 'number',
				'label'      => __( 'Columns (desktop)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section'    => 'layout',
				'default'    => 4,
				'min'        => 1,
				'max'        => 6,
				'responsive' => true,
			),
			'show_category'    => array(
				'type'    => 'switch',
				'label'   => __( 'Category pill', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'layout',
				'default' => true,
			),
			'show_rating'      => array(
				'type'    => 'switch',
				'label'   => __( 'Rating', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'layout',
				'default' => true,
			),
			'show_add_to_cart' => array(
				'type'    => 'switch',
				'label'   => __( 'Add to cart button', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'layout',
				'default' => true,
			),
			'show_quick_view'  => array(
				'type'    => 'switch',
				'label'   => __( 'Quick view button', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'layout',
				'default' => true,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		$ids = $this->query_ids( $settings );

		if ( empty( $ids ) ) {
			// Only editors see the empty state; visitors see nothing.
			return current_user_can( 'edit_posts' )
				? '<p class="sc-empty">' . esc_html__( 'No products match these settings.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>'
				: '';
		}

		// Prime caches: one query for all products instead of one each.
		_prime_post_caches( $ids );
		$products = array_filter( array_map( array( Product::class, 'get' ), $ids ) );

		return $this->template(
			'units/product-grid.php',
			array(
				'products' => $products,
				'settings' => $settings,
			)
		);
	}

	/**
	 * Run (or fetch from cache) the product ID query.
	 *
	 * Cached as a transient whose key includes the store's query version,
	 * which is bumped whenever a product, price, stock level or rating
	 * changes — so the cache invalidates itself with no manual purging.
	 *
	 * @param array $settings Settings.
	 * @return int[]
	 */
	protected function query_ids( array $settings ) {
		$args = $this->query_args( $settings );

		/**
		 * Filter the WP_Query args for the product grid.
		 *
		 * @param array $args     WP_Query args.
		 * @param array $settings Unit settings.
		 */
		$args = apply_filters( 'sidcraft_commerce_product_grid_query_args', $args, $settings );

		$version   = Product::query_version();
		$cache_key = 'sidccofo_grid_' . md5( wp_json_encode( $args ) . $version );
		$ids       = get_transient( $cache_key );

		if ( false === $ids ) {
			$query = new \WP_Query( $args );
			$ids   = array_map( 'absint', $query->posts );
			set_transient( $cache_key, $ids, DAY_IN_SECONDS );
		}

		return (array) $ids;
	}

	/**
	 * Translate settings into WP_Query args.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	protected function query_args( array $settings ) {
		$args = array(
			'post_type'           => Post_Types::PRODUCT,
			'post_status'         => 'publish',
			'posts_per_page'      => (int) $settings['limit'],
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'meta_query'          => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		if ( setting( 'hide_out_of_stock', 0 ) ) {
			$args['meta_query'][] = array(
				'key'   => '_sidccofo_stock_status',
				'value' => 'instock',
			);
		}

		switch ( $settings['source'] ) {
			case 'featured':
				$args['meta_query'][] = array(
					'key'   => '_sidccofo_featured',
					'value' => '1',
				);
				break;

			case 'on_sale':
				$args['meta_query'][] = array(
					'key'   => '_sidccofo_on_sale',
					'value' => '1',
				);
				break;

			case 'best_selling':
				$args['meta_key'] = '_sidccofo_total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = array(
					'meta_value_num' => 'DESC',
					'date'           => 'DESC',
				);
				break;

			case 'top_rated':
				$args['meta_key'] = '_sidccofo_rating_avg'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = array(
					'meta_value_num' => 'DESC',
					'date'           => 'DESC',
				);
				break;

			case 'category':
				$slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) $settings['category'] ) ) );
				if ( $slugs ) {
					$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => Post_Types::CATEGORY,
							'field'    => 'slug',
							'terms'    => $slugs,
						),
					);
				}
				break;

			case 'tag':
				$slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) $settings['tag'] ) ) );
				if ( $slugs ) {
					$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => Post_Types::TAG,
							'field'    => 'slug',
							'terms'    => $slugs,
						),
					);
				}
				break;
			case 'ids':
				$ids              = array_filter( array_map( 'absint', explode( ',', (string) $settings['ids'] ) ) );
				$args['post__in'] = $ids ? $ids : array( 0 );
				$args['orderby']  = 'post__in';
				break;
		}

		return $args;
	}
}
