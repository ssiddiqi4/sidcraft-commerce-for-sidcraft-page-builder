<?php
/**
 * Products designed in Sidcraft Page Builder.
 *
 * - Turns Sidcraft Page Builder on for products, so the product screen, the
 *   Products list and the admin bar get "Edit with Sidcraft Page Builder".
 * - "Add New Product" opens a new product straight in the builder.
 * - Inside the builder, a "Product data" panel edits price, sale price, SKU,
 *   stock, badge, categories, image and short description, so a product can
 *   be created and published without leaving the builder.
 *
 * Store → Settings → "Design products with Sidcraft Page Builder" turns all of
 * this off and products go back to the WordPress editor.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Integrations;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use SidcraftCommerce\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Class Builder_Products
 */
class Builder_Products {

	/** Product post type (duplicated so early() works before Post_Types loads). */
	const TYPE = 'sidccofo_product';

	/**
	 * Runs when the plugin file loads. Sidcraft Page Builder decides which
	 * post types it handles while its own file loads (before plugins_loaded),
	 * so this filter has to be in place by then.
	 */
	public static function early() {
		add_filter( 'sidcraft_page_builder_documents_post_types', array( __CLASS__, 'add_type' ) );
	}

	/**
	 * Whether products use the builder (Store → Settings, default on).
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = get_option( 'sidcraft_commerce_settings', array() );
		$enabled  = ! is_array( $settings ) || ! array_key_exists( 'builder_products', $settings ) || ! empty( $settings['builder_products'] );

		/**
		 * Turn Sidcraft Page Builder editing of products on or off.
		 *
		 * @param bool $enabled Enabled.
		 */
		return (bool) apply_filters( 'sidcraft_commerce_builder_products', $enabled );
	}

	/**
	 * Add products to the builder's post types.
	 *
	 * @param mixed $types Post types.
	 * @return mixed
	 */
	public static function add_type( $types ) {
		if ( is_array( $types ) && self::enabled() && ! in_array( self::TYPE, $types, true ) ) {
			$types[] = self::TYPE;
		}
		return $types;
	}

	/**
	 * Whether the current user may open the builder for products.
	 *
	 * @return bool
	 */
	public static function user_can_build() {
		if ( ! self::enabled() || ! function_exists( 'sidcraft_page_builder_supports_post_type' ) || ! sidcraft_page_builder_supports_post_type( self::TYPE ) ) {
			return false;
		}
		$type = get_post_type_object( self::TYPE );
		if ( ! $type || ! current_user_can( $type->cap->edit_posts ) ) {
			return false;
		}
		if ( class_exists( '\SidcraftPageBuilder\Settings\Roles' ) && method_exists( '\SidcraftPageBuilder\Settings\Roles', 'can_edit' ) ) {
			return (bool) \SidcraftPageBuilder\Settings\Roles::can_edit();
		}
		return true;
	}

	/**
	 * Builder URL for a product, or for a new one.
	 *
	 * @param int $post_id Product ID (0 = new product).
	 * @return string
	 */
	public static function builder_url( $post_id = 0 ) {
		$args = $post_id ? array( 'post_id' => absint( $post_id ) ) : array( 'post_type' => self::TYPE );
		return add_query_arg( array_merge( array( 'page' => 'sidcraft-page-builder' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * WordPress editor URL for a new product (skips the builder redirect).
	 *
	 * @return string
	 */
	public static function wp_new_url() {
		return add_query_arg(
			array(
				'post_type'        => self::TYPE,
				'sidccofo-wp-edit' => 1,
			),
			admin_url( 'post-new.php' )
		);
	}

	/**
	 * Attach hooks (from Plugin::boot()).
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'sidcraft_page_builder_editor_enqueue', array( $this, 'editor_assets' ) );
		if ( is_admin() ) {
			add_action( 'load-post-new.php', array( $this, 'redirect_new' ) );
			add_action( 'admin_menu', array( $this, 'menu' ), 99 );
			add_filter( 'views_edit-' . self::TYPE, array( $this, 'list_notice' ) );
		}
	}

	/**
	 * "Add New Product" (menu, list button, admin bar "+ New") opens the
	 * builder. ?sidccofo-wp-edit=1 keeps the WordPress editor.
	 */
	public function redirect_new() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		$keep = ! empty( $_GET['sidccofo-wp-edit'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( self::TYPE !== $type || $keep || ! self::user_can_build() ) {
			return;
		}
		wp_safe_redirect( self::builder_url() );
		exit;
	}

	/**
	 * Point the "Add New Product" submenu at the builder and add a
	 * WordPress-editor alternative.
	 */
	public function menu() {
		global $submenu;
		$parent = 'edit.php?post_type=' . self::TYPE;
		if ( empty( $submenu[ $parent ] ) || ! self::user_can_build() ) {
			return;
		}
		foreach ( $submenu[ $parent ] as $i => $item ) {
			if ( isset( $item[2] ) && 'post-new.php?post_type=' . self::TYPE === $item[2] ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Pointing our own submenu item at the builder.
				$submenu[ $parent ][ $i ][2] = self::builder_url();
			}
		}
	}

	/**
	 * Products list: say where new products open, with a link to the
	 * WordPress editor instead.
	 *
	 * @param array $views List views.
	 * @return array
	 */
	public function list_notice( $views ) {
		if ( self::user_can_build() ) {
			printf(
				'<p class="sc-builder-note">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'New products open in Sidcraft Page Builder; set the price and stock in its Product data panel.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				esc_url( self::wp_new_url() ),
				esc_html__( 'Add a product with the WordPress editor instead', 'sidcraft-commerce-for-sidcraft-page-builder' )
			);
		}
		return $views;
	}

	/*
	------------------------------------------------------------------
	 * Product data panel inside the builder
	 * ----------------------------------------------------------------
	 */

	/**
	 * Enqueue the Product data panel when the builder edits a product.
	 *
	 * @param int $post_id Post being edited (0 when the builder is creating one).
	 */
	public function editor_assets( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor screen routing.
		$requested = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$is_product = $post_id ? self::TYPE === get_post_type( $post_id ) : self::TYPE === $requested;
		if ( ! $is_product || ! self::enabled() ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'sidccofo-builder-product', SIDCRAFT_COMMERCE_URL . 'assets/css/sc-builder-product.css', array(), SIDCRAFT_COMMERCE_VERSION );
		wp_enqueue_script( 'sidccofo-builder-product', SIDCRAFT_COMMERCE_URL . 'assets/js/sc-builder-product.js', array( 'sidcraft-page-builder-editor' ), SIDCRAFT_COMMERCE_VERSION, true );

		$badges = array( '' => __( 'None (Sale shows automatically)', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		foreach ( Product::badge_options() as $key => $label ) {
			$badges[ $key ] = $label;
		}

		wp_localize_script(
			'sidccofo-builder-product',
			'sidccofoBuilderProduct',
			array(
				'route'    => esc_url_raw( rest_url( Rest::NS . '/builder/product/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'symbol'   => \SidcraftCommerce\currency_symbol(),
				'badges'   => $badges,
				'editUrl'  => admin_url( 'post.php?action=edit&post=' ),
				'settings' => admin_url( 'edit.php?post_type=' . self::TYPE . '&page=sidccofo-settings' ),
				'i18n'     => array(
					'button'       => __( 'Product data', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'title'        => __( 'Product data', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'close'        => __( 'Close', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'regular'      => __( 'Regular price', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'sale'         => __( 'Sale price', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'saleHelp'     => __( 'Optional. Must be lower than the regular price.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'sku'          => __( 'SKU', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'manageStock'  => __( 'Track stock quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'stock'        => __( 'Quantity in stock', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'stockStatus'  => __( 'Stock status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'inStock'      => __( 'In stock', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'outOfStock'   => __( 'Out of stock', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'badge'        => __( 'Image badge', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'featured'     => __( 'Featured product', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'hideBuyBox'   => __( 'Hide the automatic buy box (my layout has its own Add to Cart)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'categories'   => __( 'Categories', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'noCategories' => __( 'No categories yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'image'        => __( 'Product image', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'chooseImage'  => __( 'Choose image', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'removeImage'  => __( 'Remove', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'options'      => __( 'Item options', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'optionsHelp'  => \SidcraftCommerce\Data\Product_Options::help_text(),
					'optionsHint'  => "Size*: Small | Medium +2 | Large +4\nExtras [checkbox]: Cheese +1 | Bacon +2.50\nSpecial instructions [text]",
					'excerpt'      => __( 'Short description', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'excerptHelp'  => __( 'Shown in product grids and search results.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'giftCard'     => __( 'This product is a gift card. Its amounts are set in the WordPress editor.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'more'         => __( 'More settings (gift card, downloadable files, reviews) in the WordPress editor', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'virtual'      => __( 'Virtual — nothing to ship', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'downloadable' => __( 'Downloadable — buyers get access to files', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					/* translators: %d: number of files. */
					'files'        => __( 'Files: %d. Add or change them in the WordPress editor (link below).', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'save'         => __( 'Save product data', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'saving'       => __( 'Saving…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'saved'        => __( 'Saved.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'loading'      => __( 'Loading…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'error'        => __( 'Could not save. Check your connection and try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'needPrice'    => __( 'Add a price so customers can buy this product.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'unsaved'      => __( 'Product data has unsaved changes.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
			)
		);
	}

	/**
	 * REST: read and save a product's data from the builder.
	 */
	public function routes() {
		register_rest_route(
			Rest::NS,
			'/builder/product/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_get' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_save' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
			)
		);
	}

	/**
	 * Only people who may edit this product.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit( \WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		return $id && Post_Types::PRODUCT === get_post_type( $id ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * Current product data.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_get( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->data( absint( $request['id'] ) ) );
	}

	/**
	 * Panel data for one product.
	 *
	 * @param int $id Product ID.
	 * @return array
	 */
	protected function data( $id ) {
		$meta  = static function ( $key ) use ( $id ) {
			return get_post_meta( $id, '_sidccofo_' . $key, true );
		};
		$thumb = (int) get_post_thumbnail_id( $id );
		$terms = get_terms(
			array(
				'taxonomy'   => Post_Types::CATEGORY,
				'hide_empty' => false,
			)
		);
		$cats  = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$cats[] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
			);
		}
		$chosen = wp_get_object_terms( $id, Post_Types::CATEGORY, array( 'fields' => 'ids' ) );

		return array(
			'id'           => $id,
			'regular'      => (string) $meta( 'regular_price' ),
			'sale'         => (string) $meta( 'sale_price' ),
			'sku'          => (string) $meta( 'sku' ),
			'manage_stock' => (bool) $meta( 'manage_stock' ),
			'stock'        => (int) $meta( 'stock' ),
			'stock_status' => 'outofstock' === $meta( 'stock_status' ) ? 'outofstock' : 'instock',
			'badge'        => (string) $meta( 'badge' ),
			'featured'     => (bool) $meta( 'featured' ),
			'hide_buy_box' => (bool) $meta( 'hide_buy_box' ),
			'gift_card'    => (bool) $meta( 'gift_card' ),
			'virtual'      => (bool) $meta( 'virtual' ),
			'downloadable' => (bool) $meta( 'downloadable' ),
			'file_count'   => count( \SidcraftCommerce\Downloads\Downloads::stored_files( $id ) ),
			'options'      => (string) $meta( 'options' ),
			'categories'   => $cats,
			'category_ids' => is_array( $chosen ) ? array_map( 'intval', $chosen ) : array(),
			'image_id'     => $thumb,
			'image_url'    => $thumb ? (string) wp_get_attachment_image_url( $thumb, 'medium' ) : '',
			'excerpt'      => (string) get_post_field( 'post_excerpt', $id, 'raw' ),
		);
	}

	/**
	 * Save product data.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_save( \WP_REST_Request $request ) {
		$id      = absint( $request['id'] );
		$params  = (array) $request->get_json_params();
		$text    = static function ( $key ) use ( $params ) {
			return isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ? sanitize_text_field( (string) $params[ $key ] ) : '';
		};
		$regular = \SidcraftCommerce\parse_price( $text( 'regular' ) );
		$sale    = \SidcraftCommerce\parse_price( $text( 'sale' ) );
		$notice  = '';
		if ( '' !== $sale && ( '' === $regular || (float) $sale >= (float) $regular ) ) {
			$sale   = '';
			$notice = __( 'The sale price was removed because it was not lower than the regular price.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		$badge = sanitize_key( $text( 'badge' ) );

		// A translation's store data is its original's (one stock for every language).
		$store = \SidcraftCommerce\Multilingual::original_id( $id );
		update_post_meta( $store, '_sidccofo_regular_price', $regular );
		update_post_meta( $store, '_sidccofo_sale_price', $sale );
		update_post_meta( $store, '_sidccofo_sku', $text( 'sku' ) );
		update_post_meta( $store, '_sidccofo_manage_stock', empty( $params['manage_stock'] ) ? 0 : 1 );
		update_post_meta( $store, '_sidccofo_stock', max( 0, absint( $params['stock'] ?? 0 ) ) );
		update_post_meta( $store, '_sidccofo_stock_status', 'outofstock' === $text( 'stock_status' ) ? 'outofstock' : 'instock' );
		update_post_meta( $store, '_sidccofo_badge', array_key_exists( $badge, Product::badge_options() ) ? $badge : '' );
		update_post_meta( $store, '_sidccofo_featured', empty( $params['featured'] ) ? 0 : 1 );
		update_post_meta( $store, '_sidccofo_hide_buy_box', empty( $params['hide_buy_box'] ) ? 0 : 1 );
		if ( array_key_exists( 'virtual', $params ) ) {
			update_post_meta( $store, '_sidccofo_virtual', empty( $params['virtual'] ) ? 0 : 1 );
		}
		if ( array_key_exists( 'downloadable', $params ) ) {
			update_post_meta( $store, '_sidccofo_downloadable', empty( $params['downloadable'] ) ? 0 : 1 );
		}
		if ( isset( $params['options'] ) && is_scalar( $params['options'] ) ) {
			update_post_meta( $id, '_sidccofo_options', \SidcraftCommerce\Data\Product_Options::clean_text( sanitize_textarea_field( (string) $params['options'] ) ) );
		}

		$cats = array();
		foreach ( (array) ( $params['category_ids'] ?? array() ) as $cat ) {
			$cat = absint( $cat );
			if ( $cat && term_exists( $cat, Post_Types::CATEGORY ) ) {
				$cats[] = $cat;
			}
		}
		wp_set_object_terms( $id, $cats, Post_Types::CATEGORY );

		$image = absint( $params['image_id'] ?? 0 );
		if ( $image && wp_attachment_is_image( $image ) && current_user_can( 'read_post', $image ) ) {
			set_post_thumbnail( $id, $image );
		} elseif ( ! $image ) {
			delete_post_thumbnail( $id );
		}

		if ( isset( $params['excerpt'] ) && is_scalar( $params['excerpt'] ) ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_excerpt' => sanitize_textarea_field( (string) $params['excerpt'] ),
				)
			);
		}

		Product::forget( $store );
		Product::sync_derived_meta( $id );

		$data           = $this->data( $id );
		$data['notice'] = $notice;
		return rest_ensure_response( $data );
	}
}
