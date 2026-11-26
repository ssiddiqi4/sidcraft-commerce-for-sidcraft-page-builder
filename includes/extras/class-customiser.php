<?php
/**
 * Product personalisation: engraving or print text with a live preview, a
 * picture upload, and a size chart.
 *
 * Set it up in the "Personalise" box on a product. The text and picture are
 * ordinary item options, so they show in the cart, at checkout, in emails and
 * on the order. For a price add-on, also add a normal option such as
 * "Engraving [checkbox]: Yes +5" in Item options.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\rate_limit;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Public routes are rate limited and validated; admin forms verify their nonces.

/**
 * Class Customiser
 */
class Customiser extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		add_filter( 'sidcraft_commerce_product_options', array( $this, 'options' ), 20, 2 );
		add_action( 'add_meta_boxes_' . Post_Types::PRODUCT, array( $this, 'box' ) );
		add_action( 'save_post_' . Post_Types::PRODUCT, array( $this, 'save' ) );
		add_action( 'sidcraft_commerce_after_add_to_cart_button', array( $this, 'chart_link' ), 5 );
		add_action( 'sidcraft_commerce_before_add_to_cart_button', array( $this, 'preview' ), 5 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Add the text and picture groups.
	 *
	 * @param array   $groups  Groups.
	 * @param Product $product Product.
	 * @return array
	 */
	public function options( $groups, $product ) {
		$id = $product->get_id();
		if ( '' !== trim( (string) get_post_meta( $id, '_sidccofo_cust_text', true ) ) ) {
			$groups[] = array(
				'id'          => 'personal-text',
				'label'       => (string) get_post_meta( $id, '_sidccofo_cust_text', true ),
				'type'        => 'text',
				'required'    => (bool) get_post_meta( $id, '_sidccofo_cust_text_req', true ),
				'maxlength'   => max( 1, min( 200, (int) get_post_meta( $id, '_sidccofo_cust_text_max', true ) ?: 30 ) ),
				'placeholder' => __( 'Type your text', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'choices'     => array(),
			);
		}
		if ( '' !== trim( (string) get_post_meta( $id, '_sidccofo_cust_image', true ) ) ) {
			$groups[] = array(
				'id'          => 'personal-image',
				'label'       => (string) get_post_meta( $id, '_sidccofo_cust_image', true ),
				'type'        => 'text',
				'required'    => false,
				'maxlength'   => 300,
				'placeholder' => __( 'Upload a picture below', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'choices'     => array(),
			);
		}
		return $groups;
	}

	/**
	 * Live preview and upload field above the button.
	 *
	 * @param Product $product Product.
	 */
	public function preview( $product ) {
		if ( ! $product instanceof Product ) {
			return;
		}
		$id = $product->get_id();
		if ( '' === trim( (string) get_post_meta( $id, '_sidccofo_cust_text', true ) ) && '' === trim( (string) get_post_meta( $id, '_sidccofo_cust_image', true ) ) ) {
			return;
		}
		Extras::enqueue();
		echo '<div class="sc-cust" data-sc-cust>';
		if ( '' !== trim( (string) get_post_meta( $id, '_sidccofo_cust_text', true ) ) ) {
			echo '<div class="sc-cust__preview" data-sc-cust-preview aria-hidden="true"></div>';
		}
		if ( '' !== trim( (string) get_post_meta( $id, '_sidccofo_cust_image', true ) ) ) {
			echo '<p><label>' . esc_html( (string) get_post_meta( $id, '_sidccofo_cust_image', true ) ) . '<br><input type="file" accept="image/jpeg,image/png,image/webp" data-sc-cust-file></label> <span data-sc-cust-status></span></p>';
		}
		echo '</div>';
	}

	/**
	 * Size chart link and dialog.
	 *
	 * @param Product $product Product.
	 */
	public function chart_link( $product ) {
		if ( ! $product instanceof Product ) {
			return;
		}
		$chart = (string) get_post_meta( $product->get_id(), '_sidccofo_cust_chart', true );
		if ( '' === trim( $chart ) ) {
			return;
		}
		Extras::enqueue();
		echo '<p class="sc-chart"><button type="button" class="sc-link" data-sc-chart-open>' . esc_html__( 'Size chart', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></p><dialog class="sc-chart__dialog" data-sc-chart><div>' . wp_kses_post( $chart ) . '</div><form method="dialog"><button class="sc-btn">' . esc_html__( 'Close', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></form></dialog>';
	}

	/**
	 * Upload route (pictures only, from the product page).
	 */
	public function routes() {
		register_rest_route(
			'sidcraft-commerce/v1',
			'/customiser/upload',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'upload' ),
			)
		);
	}

	/**
	 * Take a picture.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload() {
		if ( ! rate_limit( 'cust_upload', 10, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'sidccofo_rate', __( 'Too many uploads. Please try again later.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
		}
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		if ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > 8 * MB_IN_BYTES ) {
			return new \WP_Error( 'sidccofo_cust', __( 'Choose a picture up to 8 MB.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
		if ( empty( $check['type'] ) || false === getimagesize( $file['tmp_name'] ) ) {
			return new \WP_Error( 'sidccofo_cust', __( 'Only JPEG, PNG or WebP pictures are accepted.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_sideload( array( 'name' => sanitize_file_name( 'custom-' . wp_generate_password( 6, false ) . '.' . ( $check['ext'] ? $check['ext'] : 'jpg' ) ), 'tmp_name' => $file['tmp_name'] ), 0 );
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'sidccofo_cust', __( 'The picture could not be saved.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'url' => (string) wp_get_attachment_url( $id ) ) );
	}

	/**
	 * Product box.
	 */
	public function box() {
		add_meta_box( 'sidccofo-personalise', __( 'Personalise', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_box' ), Post_Types::PRODUCT, 'normal' );
	}

	/**
	 * Box contents.
	 *
	 * @param \WP_Post $post Product.
	 */
	public function render_box( $post ) {
		wp_nonce_field( 'sidccofo_cust', '_sidccofo_cust' );
		$m = static function ( $k ) use ( $post ) {
			return (string) get_post_meta( $post->ID, '_sidccofo_' . $k, true );
		};
		echo '<p><label>' . esc_html__( 'Text field label (empty = none)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '<br><input class="widefat" name="sidccofo_cust_text" placeholder="Engraving text" value="' . esc_attr( $m( 'cust_text' ) ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Max characters', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' <input type="number" min="1" max="200" name="sidccofo_cust_text_max" value="' . esc_attr( $m( 'cust_text_max' ) ?: '30' ) . '"></label> <label><input type="checkbox" name="sidccofo_cust_text_req" value="1" ' . checked( '' !== $m( 'cust_text_req' ), true, false ) . '> ' . esc_html__( 'Required', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Picture upload label (empty = none)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '<br><input class="widefat" name="sidccofo_cust_image" placeholder="Your logo" value="' . esc_attr( $m( 'cust_image' ) ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Size chart (HTML table or text, empty = none)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '<br><textarea class="widefat" rows="5" name="sidccofo_cust_chart">' . esc_textarea( $m( 'cust_chart' ) ) . '</textarea></label></p>';
	}

	/**
	 * Save.
	 *
	 * @param int $post_id Product.
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST['_sidccofo_cust'] ) || ! current_user_can( 'edit_post', $post_id ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_cust'] ) ), 'sidccofo_cust' ) ) {
			return;
		}
		foreach ( array( 'cust_text', 'cust_image' ) as $k ) {
			update_post_meta( $post_id, '_sidccofo_' . $k, isset( $_POST[ 'sidccofo_' . $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'sidccofo_' . $k ] ) ) : '' );
		}
		update_post_meta( $post_id, '_sidccofo_cust_text_max', isset( $_POST['sidccofo_cust_text_max'] ) ? max( 1, min( 200, absint( $_POST['sidccofo_cust_text_max'] ) ) ) : 30 );
		update_post_meta( $post_id, '_sidccofo_cust_text_req', empty( $_POST['sidccofo_cust_text_req'] ) ? '' : '1' );
		update_post_meta( $post_id, '_sidccofo_cust_chart', isset( $_POST['sidccofo_cust_chart'] ) && current_user_can( 'unfiltered_html' ) ? wp_unslash( $_POST['sidccofo_cust_chart'] ) : ( isset( $_POST['sidccofo_cust_chart'] ) ? wp_kses_post( wp_unslash( $_POST['sidccofo_cust_chart'] ) ) : '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Output is passed through wp_kses_post().
		Product::forget( $post_id );
	}
}
