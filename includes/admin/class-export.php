<?php
/**
 * Catalog export: products, categories, price, SKU, stock and item options.
 *
 * The file does not include Sidcraft Page Builder layout. That layout is
 * presentation. The product post and its commerce meta are the catalog.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Admin;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\manage_cap;

defined( 'ABSPATH' ) || exit;

/**
 * Class Export
 */
class Export {

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_sidccofo_export_catalog', array( $this, 'download' ) );
	}

	/**
	 * Store → Export catalog.
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Types::PRODUCT,
			__( 'Export catalog', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Export catalog', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			manage_cap(),
			'sidccofo-export',
			array( $this, 'page' )
		);
	}

	/**
	 * Explain the file and offer the download.
	 */
	public function page() {
		if ( ! current_user_can( manage_cap() ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=sidccofo_export_catalog' ), 'sidccofo_export_catalog' );
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Export catalog', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h1>';
		echo '<p>' . esc_html__( 'Downloads a JSON file of every product: title, slug, description, categories, regular price, sale price, SKU, stock and item options. Page-builder layout is not included. The same products are also in Tools → Export as the sidccofo_product post type.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Download catalog JSON', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Stream the catalog. Products are written in pages so a large store
	 * does not have to sit in memory at once.
	 */
	public function download() {
		if ( ! current_user_can( manage_cap() ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'sidccofo_export_catalog' ) ) {
			wp_die( esc_html__( 'You cannot export the catalog.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sidcraft-commerce-catalog-' . gmdate( 'Y-m-d' ) . '.json"' );

		echo '{"version":' . wp_json_encode( SIDCRAFT_COMMERCE_VERSION ) . ',"exported_at":' . wp_json_encode( gmdate( 'c' ) ) . ',"products":[';

		$paged = 1;
		$first = true;
		do {
			$query = new \WP_Query(
				array(
					'post_type'      => Post_Types::PRODUCT,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => 100,
					'paged'          => $paged,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => false,
				)
			);
			foreach ( $query->posts as $post ) {
				$product = Product::get( $post->ID );
				if ( ! $product ) {
					continue;
				}
				$categories = array();
				$terms      = get_the_terms( $post->ID, Post_Types::CATEGORY );
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						$categories[] = array(
							'slug' => $term->slug,
							'name' => $term->name,
						);
					}
				}
				$row = array(
					'id'             => $product->get_id(),
					'slug'           => $post->post_name,
					'status'         => $post->post_status,
					'title'          => $product->get_name(),
					'content'        => $post->post_content,
					'excerpt'        => $product->get_short_description(),
					'categories'     => $categories,
					'sku'            => $product->get_sku(),
					'regular_price'  => $product->get_regular_price(),
					'sale_price'     => $product->get_sale_price(),
					'manage_stock'   => $product->managing_stock(),
					'stock'          => $product->get_stock_quantity(),
					'stock_status'   => $product->managing_stock() ? ( $product->is_in_stock() ? 'instock' : 'outofstock' ) : (string) get_post_meta( $product->get_id(), '_sidccofo_stock_status', true ),
					'item_options'   => (string) get_post_meta( $product->get_id(), '_sidccofo_options', true ),
				);
				echo ( $first ? '' : ',' ) . wp_json_encode( $row );
				$first = false;
			}
			++$paged;
		} while ( $paged <= (int) $query->max_num_pages );

		echo ']}';
		exit;
	}
}
