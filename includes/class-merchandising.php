<?php
/**
 * Merchandising: upsells, cross-sells, and category and brand images.
 *
 *   - Upsells (Product data → Linked products) show as "You may also like"
 *     below the product page (Store → Settings → Show upsells).
 *   - Cross-sells of the products in the cart show on the cart page
 *     (Store → Settings → Suggest cross-sells in the cart).
 *   - Categories and brands can have an image (term meta
 *     sidccofo_thumbnail_id): shown on their archive pages, in the admin
 *     list, and by the Product Categories Unit.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Merchandising
 */
class Merchandising {

	/** Term meta: a category's or brand's image (attachment id). */
	const TERM_IMAGE = 'sidccofo_thumbnail_id';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_filter( 'the_content', array( $this, 'append_upsells' ), 35 ); // After reviews (30).
		add_filter( 'sidcraft_commerce_detected_units', array( $this, 'detect' ) );
		// Classic themes (the_archive_description) and block themes (Term
		// Description block) both print the description through term_description().
		add_filter( 'term_description', array( $this, 'archive_image' ), 10, 4 );

		foreach ( array( Post_Types::CATEGORY, Post_Types::BRAND ) as $tax ) {
			add_action( $tax . '_add_form_fields', array( $this, 'add_image_field' ) );
			add_action( $tax . '_edit_form_fields', array( $this, 'edit_image_field' ) );
			add_action( 'created_' . $tax, array( $this, 'save_image' ) );
			add_action( 'edited_' . $tax, array( $this, 'save_image' ) );
			add_filter( 'manage_edit-' . $tax . '_columns', array( $this, 'image_column' ) );
			add_filter( 'manage_' . $tax . '_custom_column', array( $this, 'image_cell' ), 10, 3 );
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'term_assets' ) );
	}

	/*
	------------------------------------------------------------------
	 * Upsells and cross-sells
	 * ----------------------------------------------------------------
	 */

	/**
	 * Show upsells on this product page?
	 *
	 * @return int[] Upsell ids ([] = no section).
	 */
	protected function page_upsells() {
		if ( ! is_singular( Post_Types::PRODUCT ) || ! setting( 'auto_upsells', 1 ) ) {
			return array();
		}
		$product = Product::get( get_queried_object_id() );
		return $product ? $product->get_upsell_ids() : array();
	}

	/**
	 * "You may also like" below the product page.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function append_upsells( $content ) {
		if ( ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		$ids = $this->page_upsells();
		return $ids ? $content . self::products_section( $ids, __( 'You may also like', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'upsells' ) : $content;
	}

	/**
	 * Load the grid's styles in <head> when the page will show upsells.
	 *
	 * @param string[] $found Unit names.
	 * @return string[]
	 */
	public function detect( $found ) {
		if ( $this->page_upsells() ) {
			$found[] = 'sidccofo-product-grid';
		}
		return $found;
	}

	/**
	 * A titled grid of products (upsells, cross-sells).
	 *
	 * @param int[]  $ids     Product ids.
	 * @param string $title   Heading.
	 * @param string $context upsells|cross-sells (CSS class and filter context).
	 * @return string
	 */
	public static function products_section( array $ids, $title, $context ) {
		$unit = Plugin::instance()->units->get( 'sidccofo-product-grid' );
		if ( ! $unit || ! $ids ) {
			return '';
		}
		/**
		 * Filter the grid settings of an upsells or cross-sells section.
		 *
		 * @param array  $settings Product Grid settings.
		 * @param string $context  upsells|cross-sells.
		 */
		$settings = (array) apply_filters(
			'sidcraft_commerce_linked_products_settings',
			array(
				'source'          => 'ids',
				'ids'             => implode( ',', array_map( 'absint', $ids ) ),
				'limit'           => 'cross-sells' === $context ? 4 : 8,
				'columns'         => 4,
				'show_quick_view' => false,
			),
			$context
		);
		$grid = $unit->render( $settings );
		if ( '' === $grid || false !== strpos( $grid, 'sc-empty' ) ) {
			return '';
		}
		return '<section class="sc-linked sc-linked--' . esc_attr( $context ) . '"><h2 class="sc-linked__title">' . esc_html( $title ) . '</h2>' . $grid . '</section>';
	}

	/**
	 * Cross-sells of the products in a cart that aren't in it already.
	 *
	 * @param \SidcraftCommerce\Cart\Cart $cart Cart.
	 * @return int[]
	 */
	public static function cart_cross_sells( $cart ) {
		if ( ! setting( 'cart_cross_sells', 1 ) ) {
			return array();
		}
		$in  = array();
		$ids = array();
		foreach ( $cart->get_items() as $line ) {
			$in[ (int) $line['product_id'] ] = true;
			if ( $line['product'] instanceof Product ) {
				$ids = array_merge( $ids, $line['product']->get_cross_sell_ids() );
			}
		}
		$out = array();
		foreach ( array_unique( $ids ) as $id ) {
			$product = Product::get( $id );
			if ( ! isset( $in[ $id ] ) && $product && ( $product->is_purchasable() || $product->is_external() ) && $product->is_in_stock() ) {
				$out[] = (int) $id;
			}
		}
		return array_slice( $out, 0, 4 );
	}

	/*
	------------------------------------------------------------------
	 * Category and brand images
	 * ----------------------------------------------------------------
	 */

	/**
	 * A term's image id (0 = none).
	 *
	 * @param int $term_id Term.
	 * @return int
	 */
	public static function term_image_id( $term_id ) {
		$id = (int) get_term_meta( (int) $term_id, self::TERM_IMAGE, true );
		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * The image above a category's or brand's description on its archive page.
	 *
	 * @param string $description Description HTML.
	 * @param int    $term_id     Term.
	 * @param string $taxonomy    Taxonomy.
	 * @param string $context     Filter context.
	 * @return string
	 */
	public function archive_image( $description, $term_id = 0, $taxonomy = '', $context = 'display' ) {
		if ( is_admin() || 'display' !== $context || ! is_tax( array( Post_Types::CATEGORY, Post_Types::BRAND ) ) || (int) $term_id !== (int) get_queried_object_id() || ! apply_filters( 'sidcraft_commerce_archive_image', true ) ) {
			return $description;
		}
		$image = self::term_image_id( $term_id );
		if ( ! $image ) {
			return $description;
		}
		return '<figure class="sc-term-image">' . wp_get_attachment_image( $image, 'large', false, array( 'loading' => 'eager' ) ) . '</figure>' . $description;
	}

	/**
	 * The image field markup.
	 *
	 * @param int $image Attachment id.
	 */
	protected function image_field( $image ) {
		wp_nonce_field( 'sidccofo_term_image', '_sidccofo_term_image_nonce' );
		?>
		<div class="sc-term-image-field" data-sc-image-field>
			<input type="hidden" name="sidccofo_term_image" value="<?php echo esc_attr( $image ? (string) $image : '' ); ?>">
			<span class="sc-image-field__preview"><?php echo $image ? wp_get_attachment_image( $image, array( 60, 60 ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core image HTML. ?></span>
			<button type="button" class="button" data-sc-image-pick><?php esc_html_e( 'Choose image', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
			<button type="button" class="button-link" data-sc-image-clear<?php echo $image ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
		</div>
		<p class="description"><?php esc_html_e( 'Shown on its archive page and by the Product Categories Unit.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		<?php
	}

	/**
	 * Image field on "Add category / brand".
	 */
	public function add_image_field() {
		echo '<div class="form-field term-image-wrap"><label>' . esc_html__( 'Image', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label>';
		$this->image_field( 0 );
		echo '</div>';
	}

	/**
	 * Image field on "Edit category / brand".
	 *
	 * @param \WP_Term $term Term.
	 */
	public function edit_image_field( $term ) {
		echo '<tr class="form-field term-image-wrap"><th scope="row"><label>' . esc_html__( 'Image', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label></th><td>';
		$this->image_field( self::term_image_id( $term->term_id ) );
		echo '</td></tr>';
	}

	/**
	 * Save the image field.
	 *
	 * @param int $term_id Term.
	 */
	public function save_image( $term_id ) {
		if ( ! isset( $_POST['_sidccofo_term_image_nonce'], $_POST['sidccofo_term_image'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_term_image_nonce'] ) ), 'sidccofo_term_image' ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$image = absint( $_POST['sidccofo_term_image'] );
		if ( $image && wp_attachment_is_image( $image ) ) {
			update_term_meta( $term_id, self::TERM_IMAGE, $image );
		} else {
			delete_term_meta( $term_id, self::TERM_IMAGE );
		}
	}

	/**
	 * Image column in the category and brand lists.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function image_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'cb' === $key ) {
				$out['sidccofo_image'] = '<span class="screen-reader-text">' . esc_html__( 'Image', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span>';
			}
		}
		return $out;
	}

	/**
	 * Image cell.
	 *
	 * @param string $out     Cell HTML.
	 * @param string $column  Column.
	 * @param int    $term_id Term.
	 * @return string
	 */
	public function image_cell( $out, $column, $term_id ) {
		if ( 'sidccofo_image' !== $column ) {
			return $out;
		}
		$image = self::term_image_id( $term_id );
		return $image ? wp_get_attachment_image( $image, array( 40, 40 ) ) : '';
	}

	/**
	 * Media library and the image field script on category and brand screens.
	 *
	 * @param string $hook Screen hook.
	 */
	public function term_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) || ! in_array( $screen->taxonomy, array( Post_Types::CATEGORY, Post_Types::BRAND ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'sidccofo-admin', SIDCRAFT_COMMERCE_URL . 'assets/js/sc-admin.js', array(), SIDCRAFT_COMMERCE_VERSION, true );
		wp_enqueue_style( 'sidccofo-admin', SIDCRAFT_COMMERCE_URL . 'assets/css/sc-admin.css', array(), SIDCRAFT_COMMERCE_VERSION );
	}
}
