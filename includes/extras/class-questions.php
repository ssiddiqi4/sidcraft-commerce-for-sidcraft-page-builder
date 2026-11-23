<?php
/**
 * Product questions and answers, and photos with reviews.
 *
 * Questions: a form on the product page; questions wait under Store →
 * Questions until you answer them, then show with the answer. A new question
 * emails the store.
 *
 * Photos: the review form gets a "Add photos" picker (up to three JPEG, PNG
 * or WebP pictures of 3 MB each); the photos show with the review once it is
 * approved. Uploads that never end up on a review are deleted after a day.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\client_key;
use function SidcraftCommerce\manage_cap;
use function SidcraftCommerce\rate_limit;
use function SidcraftCommerce\resolve_product;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Questions
 */
class Questions extends Tool {

	const TYPE = 'sidccofo_question';

	/**
	 * Photo ids sent with the review being submitted.
	 *
	 * @var int[]
	 */
	protected static $photo_ids = array();

	/** {@inheritDoc} */
	public function hooks() {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		$this->settings(
			'questions',
			__( 'Questions and photo reviews', $d ),
			array(
				'qa_on'     => array( __( 'Questions and answers on product pages', $d ), 'checkbox' ),
				'qa_photos' => array( __( 'Photos with reviews', $d ), 'checkbox' ),
				'qa_email'  => array( __( 'Email new questions to', $d ), 'email', __( 'Empty = the site admin email.', $d ) ),
			)
		);
		Simple_Unit::add( 'sidccofo-questions', __( 'Product Questions', $d ), array( 'product_id' => array( 'type' => 'product', 'label' => __( 'Product', $d ), 'section' => 'content', 'default' => '' ) ), array( $this, 'render' ), 'help' );
		add_filter( 'sidcraft_commerce_render_sidccofo-product-reviews', array( $this, 'after_reviews' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'capture' ), 10, 3 );
		add_action( 'sidcraft_commerce_review_added', array( $this, 'attach_photos' ), 10, 1 );
		add_action( 'sidcraft_commerce_review_form_fields', array( $this, 'form_field' ) );
		add_action( 'sidcraft_commerce_review_after_text', array( $this, 'show_photos' ) );
		add_action( 'sidcraft_commerce_cleanup', array( $this, 'cleanup' ) );
		add_action( 'admin_post_sidccofo_answer', array( $this, 'answer' ) );
		$this->admin_page( 'sidccofo-questions', __( 'Questions', $d ), array( $this, 'admin' ) );
	}

	/*
	------------------------------------------------------------------
	 * Q&A
	 * ----------------------------------------------------------------
	 */

	/**
	 * Questions with answers for a product.
	 *
	 * @param int $product_id Product.
	 * @return array[] question, name, answer.
	 */
	protected function answered( $product_id ) {
		$out = array();
		foreach ( get_comments( array( 'post_id' => $product_id, 'type' => self::TYPE, 'status' => 'approve', 'number' => 30, 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ) ) as $c ) {
			$out[] = array( 'q' => $c->comment_content, 'name' => $c->comment_author, 'a' => (string) get_comment_meta( $c->comment_ID, '_sidccofo_answer', true ) );
		}
		return $out;
	}

	/**
	 * The questions block.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public function render( $s ) {
		$p = resolve_product( (array) $s );
		if ( ! $p || ! setting( 'qa_on', 1 ) ) {
			return '';
		}
		Extras::enqueue();
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		$h = '<section class="sc-qa"><h3>' . esc_html__( 'Questions and answers', $d ) . '</h3>';
		$rows = $this->answered( $p->get_id() );
		if ( ! $rows ) {
			$h .= '<p class="sc-qa__empty">' . esc_html__( 'No questions yet.', $d ) . '</p>';
		}
		foreach ( $rows as $r ) {
			$h .= '<div class="sc-qa__item"><p><strong>' . esc_html__( 'Q:', $d ) . '</strong> ' . esc_html( $r['q'] ) . ' <small>— ' . esc_html( $r['name'] ) . '</small></p>' . ( '' !== $r['a'] ? '<p><strong>' . esc_html__( 'A:', $d ) . '</strong> ' . esc_html( $r['a'] ) . '</p>' : '' ) . '</div>';
		}
		return $h . '<form class="sc-qa__form" data-sc-ask data-product="' . esc_attr( $p->get_id() ) . '"><label>' . esc_html__( 'Ask a question', $d ) . '<br><textarea name="question" rows="3" required maxlength="1000"></textarea></label><p><label>' . esc_html__( 'Your name', $d ) . ' <input name="name" required maxlength="60"></label> <label>' . esc_html__( 'Email (not shown)', $d ) . ' <input type="email" name="email" required></label></p><input type="text" name="website" tabindex="-1" autocomplete="off" class="sc-visually-hidden" aria-hidden="true"><button class="sc-btn" type="submit">' . esc_html__( 'Send question', $d ) . '</button><p class="sc-qa__msg" role="status" aria-live="polite"></p></form></section>';
	}

	/**
	 * Add the block under the reviews.
	 *
	 * @param string $html     Unit HTML.
	 * @param array  $settings Settings.
	 * @return string
	 */
	public function after_reviews( $html, $settings ) {
		$block = $this->render( $settings );
		return '' === $block ? $html : preg_replace( '#</div>\s*$#', $block . '</div>', $html, 1 );
	}

	/**
	 * Routes.
	 */
	public function routes() {
		register_rest_route( 'sidcraft-commerce/v1', '/questions', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'ask' ) ) );
		register_rest_route( 'sidcraft-commerce/v1', '/reviews/photo', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'upload' ) ) );
	}

	/**
	 * Take a question.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ask( \WP_REST_Request $r ) {
		$p     = Product::get( absint( $r['product_id'] ) );
		$q     = sanitize_textarea_field( (string) $r['question'] );
		$name  = sanitize_text_field( (string) $r['name'] );
		$email = sanitize_email( (string) $r['email'] );
		if ( ! $p || '' === $q || '' === $name || ! is_email( $email ) ) {
			return new \WP_Error( 'sidccofo_qa', __( 'Please fill in every field.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		if ( '' !== (string) $r['website'] ) {
			return rest_ensure_response( array( 'message' => __( 'Thanks! We will answer soon.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
		}
		if ( ! rate_limit( 'ask', 5, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'sidccofo_rate', __( 'Too many questions. Please try again later.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
		}
		$id = wp_insert_comment( array( 'comment_post_ID' => $p->get_id(), 'comment_type' => self::TYPE, 'comment_content' => $q, 'comment_author' => $name, 'comment_author_email' => $email, 'comment_approved' => 0 ) );
		$to = is_email( (string) setting( 'qa_email', '' ) ) ? (string) setting( 'qa_email', '' ) : get_option( 'admin_email' );
		wp_mail( $to, sprintf( /* translators: %s: product. */ __( 'New question about %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_strip_all_tags( $p->get_name() ) ), $q . "\n\n" . $name . ' <' . $email . ">\n" . $this->page_url( 'sidccofo-questions' ), array( 'Reply-To: ' . $email ) );
		unset( $id );
		return rest_ensure_response( array( 'message' => __( 'Thanks! We will answer soon.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
	}

	/**
	 * Admin list and answer form.
	 */
	public function admin() {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		echo '<div class="wrap"><h1>' . esc_html__( 'Questions', $d ) . '</h1>';
		$rows = get_comments( array( 'type' => self::TYPE, 'status' => 'all', 'number' => 100, 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ) );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No questions yet.', $d ) . '</p>';
		}
		foreach ( $rows as $c ) {
			echo '<div style="background:#fff;border:1px solid #dcdcde;padding:12px 16px;margin:10px 0;max-width:800px"><p><strong>' . esc_html( wp_strip_all_tags( get_the_title( (int) $c->comment_post_ID ) ) ) . '</strong> · ' . esc_html( $c->comment_author . ' <' . $c->comment_author_email . '>' ) . ' · ' . ( '1' === $c->comment_approved ? esc_html__( 'Published', $d ) : esc_html__( 'Waiting', $d ) ) . '</p><p>' . esc_html( $c->comment_content ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sidccofo_answer"><input type="hidden" name="id" value="' . esc_attr( (string) $c->comment_ID ) . '">';
			wp_nonce_field( 'sidccofo_answer_' . $c->comment_ID );
			echo '<textarea name="answer" rows="3" class="widefat" placeholder="' . esc_attr__( 'Your answer (shown with the question)', $d ) . '">' . esc_textarea( (string) get_comment_meta( $c->comment_ID, '_sidccofo_answer', true ) ) . '</textarea><p><button class="button button-primary" name="do" value="publish">' . esc_html__( 'Publish', $d ) . '</button> <button class="button" name="do" value="delete" onclick="return confirm(\'' . esc_js( __( 'Delete this question?', $d ) ) . '\')">' . esc_html__( 'Delete', $d ) . '</button></p></form></div>';
		}
		echo '</div>';
	}

	/**
	 * Save an answer.
	 */
	public function answer() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( manage_cap() ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sidccofo_answer_' . $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		$c = get_comment( $id );
		if ( $c && self::TYPE === $c->comment_type ) {
			if ( isset( $_POST['do'] ) && 'delete' === $_POST['do'] ) {
				wp_delete_comment( $id, true );
			} else {
				update_comment_meta( $id, '_sidccofo_answer', isset( $_POST['answer'] ) ? sanitize_textarea_field( wp_unslash( $_POST['answer'] ) ) : '' );
				wp_set_comment_status( $id, 'approve' );
			}
		}
		wp_safe_redirect( $this->page_url( 'sidccofo-questions' ) );
		exit;
	}

	/*
	------------------------------------------------------------------
	 * Photos
	 * ----------------------------------------------------------------
	 */

	/**
	 * The upload field in the review form.
	 */
	public function form_field() {
		if ( ! setting( 'qa_photos', 1 ) ) {
			return;
		}
		echo '<p class="sc-rv-photos"><label>' . esc_html__( 'Add photos (optional, up to 3)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '<br><input type="file" accept="image/jpeg,image/png,image/webp" multiple data-sc-photo></label><input type="hidden" name="photo_ids" value=""><span class="sc-rv-photos__list" data-sc-photo-list></span></p>';
	}

	/**
	 * Take a photo upload.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload( \WP_REST_Request $r ) {
		unset( $r );
		if ( ! setting( 'qa_photos', 1 ) ) {
			return new \WP_Error( 'sidccofo_photo', __( 'Photos are turned off.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 403 ) );
		}
		if ( ! rate_limit( 'photo', 6, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'sidccofo_rate', __( 'Too many uploads. Please try again later.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
		}
		$file = isset( $_FILES['photo'] ) && is_array( $_FILES['photo'] ) ? $_FILES['photo'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		if ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > 3 * MB_IN_BYTES ) {
			return new \WP_Error( 'sidccofo_photo', __( 'Choose a picture up to 3 MB.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'], array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) || false === getimagesize( $file['tmp_name'] ) ) {
			return new \WP_Error( 'sidccofo_photo', __( 'Only JPEG, PNG or WebP pictures are accepted.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_sideload( array( 'name' => sanitize_file_name( 'review-' . wp_generate_password( 6, false ) . '.' . ( $check['ext'] ? $check['ext'] : 'jpg' ) ), 'tmp_name' => $file['tmp_name'] ), 0 );
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'sidccofo_photo', __( 'The picture could not be saved.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 500 ) );
		}
		update_post_meta( $id, '_sidccofo_review_photo', 'pending:' . client_key( 'photo' ) );
		return rest_ensure_response( array( 'id' => (int) $id, 'url' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ) ) );
	}

	/**
	 * Remember the photo ids sent with a review.
	 *
	 * @param mixed            $response Response.
	 * @param array            $handler  Handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function capture( $response, $handler, $request ) {
		unset( $handler );
		if ( 'POST' === $request->get_method() && '/sidcraft-commerce/v1/reviews' === $request->get_route() ) {
			self::$photo_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $request['photo_ids'] ) ) ) ) ), 0, 3 );
		}
		return $response;
	}

	/**
	 * Attach the sender's own uploads to the new review.
	 *
	 * @param int $comment_id Review.
	 */
	public function attach_photos( $comment_id ) {
		if ( ! setting( 'qa_photos', 1 ) || ! self::$photo_ids ) {
			return;
		}
		$mine = array();
		foreach ( self::$photo_ids as $id ) {
			if ( 'pending:' . client_key( 'photo' ) === (string) get_post_meta( $id, '_sidccofo_review_photo', true ) ) {
				$mine[] = $id;
				update_post_meta( $id, '_sidccofo_review_photo', 'used' );
			}
		}
		if ( $mine ) {
			update_comment_meta( $comment_id, '_sidccofo_photos', $mine );
		}
		self::$photo_ids = array();
	}

	/**
	 * Photos under a review.
	 *
	 * @param array $review Review.
	 */
	public function show_photos( $review ) {
		$ids = (array) get_comment_meta( (int) $review['id'], '_sidccofo_photos', true );
		if ( ! array_filter( $ids ) ) {
			return;
		}
		echo '<p class="sc-review__photos">';
		foreach ( array_filter( array_map( 'absint', $ids ) ) as $id ) {
			$full = wp_get_attachment_image_url( $id, 'large' );
			if ( $full ) {
				echo '<a href="' . esc_url( $full ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $id, 'thumbnail', false, array( 'loading' => 'lazy' ) ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress image markup.
			}
		}
		echo '</p>';
	}

	/**
	 * Delete uploads that never joined a review.
	 */
	public function cleanup() {
		$old = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 50, 'fields' => 'ids', 'no_found_rows' => true, 'date_query' => array( array( 'before' => '1 day ago' ) ), 'meta_query' => array( array( 'key' => '_sidccofo_review_photo', 'value' => 'pending:', 'compare' => 'LIKE' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $old as $id ) {
			wp_delete_attachment( $id, true );
		}
	}
}
