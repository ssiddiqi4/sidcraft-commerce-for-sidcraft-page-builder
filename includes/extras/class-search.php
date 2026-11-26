<?php
/**
 * Live search and product filters.
 *
 * [sidccofo_search]   A search box that lists matching products as you type
 *                     (name, SKU, short description). Enter goes to the shop
 *                     page with ?s=… for a full results grid.
 * [sidccofo_filters]  A filter form for the shop page: price range, category,
 *                     tag, rating and in stock. Product Grids on the same page
 *                     follow it (the values travel in the URL, so filtered
 *                     pages can be shared and the back button works).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\page_url;
use function SidcraftCommerce\rate_limit;

defined( 'ABSPATH' ) || exit;

/**
 * Class Search
 */
class Search extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		Simple_Unit::add(
			'sidccofo-search',
			__( 'Product Search', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'placeholder' => array(
					'type'    => 'text',
					'label'   => __( 'Placeholder', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'section' => 'content',
					'default' => __( 'Search products…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
			),
			array( $this, 'render_search' ),
			'search'
		);
		Simple_Unit::add(
			'sidccofo-filters',
			__( 'Product Filters', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'price'  => array( 'type' => 'switch', 'label' => __( 'Price range', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'section' => 'content', 'default' => true ),
				'cats'   => array( 'type' => 'switch', 'label' => __( 'Categories', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'section' => 'content', 'default' => true ),
				'tags'   => array( 'type' => 'switch', 'label' => __( 'Tags', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'section' => 'content', 'default' => false ),
				'rating' => array( 'type' => 'switch', 'label' => __( 'Rating', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'section' => 'content', 'default' => true ),
				'stock'  => array( 'type' => 'switch', 'label' => __( 'In stock only', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'section' => 'content', 'default' => true ),
			),
			array( $this, 'render_filters' ),
			'filter'
		);
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'sidcraft_commerce_product_grid_query_args', array( $this, 'apply_filters' ), 20 );
	}

	/**
	 * Register the live-search route.
	 */
	public function routes() {
		register_rest_route(
			'sidcraft-commerce/v1',
			'/search',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'search' ),
			)
		);
	}

	/**
	 * Products matching a query.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search( \WP_REST_Request $request ) {
		$q = trim( sanitize_text_field( (string) $request['q'] ) );
		if ( strlen( $q ) < 2 ) {
			return rest_ensure_response( array( 'results' => array() ) );
		}
		if ( ! rate_limit( 'search', 120, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'sidccofo_rate', __( 'Too many searches. Please wait a moment.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
		}
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::PRODUCT,
				'post_status'    => 'publish',
				'posts_per_page' => 8,
				's'              => $q,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		// SKU matches (exact start).
		$by_sku = get_posts(
			array(
				'post_type'      => Post_Types::PRODUCT,
				'post_status'    => 'publish',
				'posts_per_page' => 4,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_sidccofo_sku',
						'value'   => $q,
						'compare' => 'LIKE',
					),
				),
			)
		);
		$out = array();
		foreach ( array_unique( array_merge( $by_sku, $ids ) ) as $id ) {
			$p = Product::get( (int) $id );
			if ( ! $p ) {
				continue;
			}
			$thumb = get_the_post_thumbnail_url( $id, 'thumbnail' );
			$out[] = array(
				'id'    => (int) $id,
				'name'  => wp_strip_all_tags( $p->get_name() ),
				'url'   => $p->get_permalink(),
				'image' => $thumb ? $thumb : '',
				'price' => wp_strip_all_tags( $p->get_price_html() ),
			);
		}
		return rest_ensure_response( array( 'results' => array_slice( $out, 0, 8 ) ) );
	}

	/**
	 * Search box.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public function render_search( $s ) {
		Extras::enqueue();
		$id = wp_unique_id( 'sc-search-' );
		return '<form class="sc-search" role="search" data-sc-search action="' . esc_url( page_url( 'shop' ) ) . '" method="get"><label class="sc-visually-hidden" for="' . esc_attr( $id ) . '">' . esc_html__( 'Search products', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><input id="' . esc_attr( $id ) . '" type="search" name="s" autocomplete="off" placeholder="' . esc_attr( $s['placeholder'] ) . '" aria-controls="' . esc_attr( $id ) . '-list"><input type="hidden" name="post_type" value="' . esc_attr( Post_Types::PRODUCT ) . '"><ul class="sc-search__list" id="' . esc_attr( $id ) . '-list" role="listbox" hidden></ul></form>';
	}

	/**
	 * The filter form.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public function render_filters( $s ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters in the URL.
		$min  = isset( $_GET['sc_min'] ) ? sanitize_text_field( wp_unslash( $_GET['sc_min'] ) ) : '';
		$max  = isset( $_GET['sc_max'] ) ? sanitize_text_field( wp_unslash( $_GET['sc_max'] ) ) : '';
		$cat  = isset( $_GET['sc_cat'] ) ? array_map( 'sanitize_title', (array) wp_unslash( $_GET['sc_cat'] ) ) : array();
		$tag  = isset( $_GET['sc_tag'] ) ? array_map( 'sanitize_title', (array) wp_unslash( $_GET['sc_tag'] ) ) : array();
		$rate = isset( $_GET['sc_rating'] ) ? absint( $_GET['sc_rating'] ) : 0;
		$in   = ! empty( $_GET['sc_stock'] );
		// phpcs:enable
		$html = '<form class="sc-filters" method="get" action="">';
		foreach ( $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Keep the page's own query (page id, permalinks off).
			if ( is_scalar( $v ) && ! preg_match( '/^(sc_|paged$)/', (string) $k ) ) {
				$html .= '<input type="hidden" name="' . esc_attr( sanitize_key( (string) $k ) ) . '" value="' . esc_attr( sanitize_text_field( (string) $v ) ) . '">';
			}
		}
		if ( $s['price'] ) {
			$html .= '<fieldset><legend>' . esc_html__( 'Price', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</legend><div class="sc-filters__row"><input type="number" min="0" step="any" name="sc_min" value="' . esc_attr( $min ) . '" placeholder="' . esc_attr__( 'Min', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '" aria-label="' . esc_attr__( 'Minimum price', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '"><span>–</span><input type="number" min="0" step="any" name="sc_max" value="' . esc_attr( $max ) . '" placeholder="' . esc_attr__( 'Max', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '" aria-label="' . esc_attr__( 'Maximum price', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '"></div></fieldset>';
		}
		foreach ( array(
			'cats' => array( Post_Types::CATEGORY, 'sc_cat', __( 'Category', 'sidcraft-commerce-for-sidcraft-page-builder' ), $cat ),
			'tags' => array( Post_Types::TAG, 'sc_tag', __( 'Tag', 'sidcraft-commerce-for-sidcraft-page-builder' ), $tag ),
		) as $key => $def ) {
			if ( ! $s[ $key ] ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $def[0], 'hide_empty' => true, 'number' => 50 ) );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$html .= '<fieldset><legend>' . esc_html( $def[2] ) . '</legend>';
			foreach ( $terms as $t ) {
				$html .= '<label class="sc-filters__opt"><input type="checkbox" name="' . esc_attr( $def[1] ) . '[]" value="' . esc_attr( $t->slug ) . '"' . checked( in_array( $t->slug, $def[3], true ), true, false ) . '> ' . esc_html( $t->name ) . ' <small>(' . (int) $t->count . ')</small></label>';
			}
			$html .= '</fieldset>';
		}
		if ( $s['rating'] ) {
			$html .= '<fieldset><legend>' . esc_html__( 'Rating', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</legend><select name="sc_rating" aria-label="' . esc_attr__( 'Minimum rating', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '"><option value="0">' . esc_html__( 'Any', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</option>';
			foreach ( array( 4, 3, 2 ) as $r ) {
				/* translators: %d: stars. */
				$html .= '<option value="' . $r . '"' . selected( $rate, $r, false ) . '>' . esc_html( sprintf( __( '%d stars & up', 'sidcraft-commerce-for-sidcraft-page-builder' ), $r ) ) . '</option>';
			}
			$html .= '</select></fieldset>';
		}
		if ( $s['stock'] ) {
			$html .= '<label class="sc-filters__opt"><input type="checkbox" name="sc_stock" value="1"' . checked( $in, true, false ) . '> ' . esc_html__( 'In stock only', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label>';
		}
		return $html . '<p><button class="sc-btn sc-btn--primary" type="submit">' . esc_html__( 'Apply filters', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button> <a class="sc-btn" href="' . esc_url( remove_query_arg( array( 'sc_min', 'sc_max', 'sc_cat', 'sc_tag', 'sc_rating', 'sc_stock', 'paged' ) ) ) . '">' . esc_html__( 'Clear', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></p></form>';
	}

	/**
	 * Make Product Grids follow the filters in the URL.
	 *
	 * @param array $args WP_Query args.
	 * @return array
	 */
	public function apply_filters( $args ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters in the URL.
		$meta = (array) ( $args['meta_query'] ?? array() );
		$tax  = (array) ( $args['tax_query'] ?? array() );
		$min  = isset( $_GET['sc_min'] ) && is_numeric( $_GET['sc_min'] ) ? (float) $_GET['sc_min'] : null;
		$max  = isset( $_GET['sc_max'] ) && is_numeric( $_GET['sc_max'] ) ? (float) $_GET['sc_max'] : null;
		if ( null !== $min || null !== $max ) {
			$meta[] = array(
				'key'     => '_sidccofo_price',
				'value'   => array( null !== $min ? $min : 0, null !== $max ? $max : 99999999 ),
				'type'    => 'DECIMAL(12,2)',
				'compare' => 'BETWEEN',
			);
		}
		foreach ( array( 'sc_cat' => Post_Types::CATEGORY, 'sc_tag' => Post_Types::TAG ) as $param => $taxonomy ) {
			if ( ! empty( $_GET[ $param ] ) ) {
				$tax[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', (array) wp_unslash( $_GET[ $param ] ) ),
				);
			}
		}
		if ( ! empty( $_GET['sc_rating'] ) && absint( $_GET['sc_rating'] ) > 1 ) {
			$meta[] = array(
				'key'     => '_sidccofo_rating_avg',
				'value'   => absint( $_GET['sc_rating'] ),
				'type'    => 'DECIMAL(4,2)',
				'compare' => '>=',
			);
		}
		if ( ! empty( $_GET['sc_stock'] ) ) {
			$meta[] = array(
				'key'     => '_sidccofo_stock_status',
				'value'   => 'instock',
			);
		}
		$term = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		if ( '' !== $term ) {
			$args['s'] = $term;
		}
		// phpcs:enable
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( $tax ) {
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return $args;
	}
}
