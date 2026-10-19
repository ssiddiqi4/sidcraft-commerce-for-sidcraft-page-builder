<?php
/**
 * Verified-customer ratings and reviews.
 *
 * Reviews are WordPress comments on the product with comment meta:
 *   sidccofo_rating   1–5 stars.
 *   sidccofo_verified 1 when tied to a paid order.
 *   sidccofo_order_id The order that verified it (one review per product per order).
 *
 * Only customers can review: the review popup (sc-reviews.js) asks for the
 * order number and the email used on that order, and POST /reviews checks the
 * order is paid and contains the product. The core comment form is closed on
 * products, and the theme's comment list is hidden there; the plugin's own
 * reviews section (templates/single/reviews.php, also the Product Reviews
 * Unit) shows the stars and reviews in the product details.
 *
 * The average and count are cached on the product for sorting ("Top rated")
 * and display.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Reviews
 */
class Reviews {

	/** Failed verification attempts allowed per IP per hour. */
	const MAX_FAILURES = 10;

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'wp_set_comment_status', array( $this, 'on_status' ) );
		add_action( 'edit_comment', array( $this, 'on_status' ) );
		add_action( 'deleted_comment', array( $this, 'on_status' ), 10, 2 );
		add_action( 'trashed_comment', array( $this, 'on_status' ), 10, 2 );
		add_action( 'untrashed_comment', array( $this, 'on_status' ), 10, 2 );
		add_filter( 'comment_text', array( $this, 'admin_comment_text' ), 10, 2 );

		// Reviews come only through the verified popup: close the core form on
		// products and keep the theme's comment list out of the way.
		add_filter( 'comments_open', array( $this, 'close_core_comments' ), 20, 2 );
		add_filter( 'get_comments_number', array( $this, 'hide_core_count' ), 20, 2 );
		add_filter( 'comments_template', array( $this, 'blank_comments_template' ), 20 );

		add_filter( 'the_content', array( $this, 'append_reviews' ), 30 );
		add_filter( 'sidcraft_commerce_detected_units', array( $this, 'detect_assets' ) );
	}

	/*
	------------------------------------------------------------------
	 * Front end
	 * ----------------------------------------------------------------
	 */

	/**
	 * A product page in the front end?
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	protected function is_front_product( $post_id ) {
		return ! is_admin() && Post_Types::PRODUCT === get_post_type( $post_id );
	}

	/**
	 * Close the core comment form on products.
	 *
	 * @param bool $open    Open.
	 * @param int  $post_id Post.
	 * @return bool
	 */
	public function close_core_comments( $open, $post_id ) {
		return Post_Types::PRODUCT === get_post_type( $post_id ) ? false : $open;
	}

	/**
	 * Report no core comments on products in the front end, so block themes
	 * skip their Comments block (reviews are shown by the plugin instead).
	 *
	 * @param int $count   Count.
	 * @param int $post_id Post.
	 * @return int
	 */
	public function hide_core_count( $count, $post_id ) {
		return $this->is_front_product( $post_id ) && setting( 'auto_reviews', 1 ) ? 0 : $count;
	}

	/**
	 * Classic themes: an empty comments template on products.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public function blank_comments_template( $template ) {
		if ( is_singular( Post_Types::PRODUCT ) && setting( 'auto_reviews', 1 ) ) {
			return SIDCRAFT_COMMERCE_PATH . 'templates/single/no-comments.php';
		}
		return $template;
	}

	/**
	 * Is the reviews section added automatically on this product page?
	 *
	 * @return bool
	 */
	protected function auto_enabled() {
		if ( ! is_singular( Post_Types::PRODUCT ) || ! setting( 'auto_reviews', 1 ) ) {
			return false;
		}
		// A Sidcraft layout that places the Product Reviews Unit itself wins.
		$placed = in_array( 'sidccofo-product-reviews', Plugin::instance()->assets->units_in_post( get_queried_object_id() ), true );

		/**
		 * Turn the automatic reviews section off for a product.
		 *
		 * @param bool $enabled Enabled.
		 * @param int  $post_id Product ID.
		 */
		return (bool) apply_filters( 'sidcraft_commerce_auto_reviews', ! $placed, get_queried_object_id() );
	}

	/**
	 * Load review assets on product pages.
	 *
	 * @param string[] $found Unit names.
	 * @return string[]
	 */
	public function detect_assets( $found ) {
		if ( $this->auto_enabled() ) {
			$found[] = 'sidccofo-product-reviews';
		}
		return $found;
	}

	/**
	 * Add the reviews section after the product description.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function append_reviews( $content ) {
		if ( ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() || ! $this->auto_enabled() ) {
			return $content;
		}
		$unit = Plugin::instance()->units->get( 'sidccofo-product-reviews' );
		return $unit ? $content . $unit->render( array( 'product_id' => get_the_ID() ) ) : $content;
	}

	/*
	------------------------------------------------------------------
	 * Reading reviews
	 * ----------------------------------------------------------------
	 */

	/**
	 * Approved reviews for a product, newest first.
	 *
	 * @param int $product_id Product.
	 * @param int $limit      Max reviews.
	 * @return array[] Each: id, rating, name, date, text, verified.
	 */
	public static function get_reviews( $product_id, $limit = 20 ) {
		$comments = get_comments(
			array(
				'post_id'  => (int) $product_id,
				'status'   => 'approve',
				'parent'   => 0,
				'number'   => max( 1, (int) $limit ),
				'orderby'  => 'comment_date_gmt',
				'order'    => 'DESC',
				'meta_key' => 'sidccofo_rating', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		$out      = array();
		foreach ( $comments as $c ) {
			$out[] = array(
				'id'       => (int) $c->comment_ID,
				'rating'   => max( 1, min( 5, (int) get_comment_meta( $c->comment_ID, 'sidccofo_rating', true ) ) ),
				'name'     => $c->comment_author,
				'date'     => get_comment_date( '', $c ),
				'text'     => $c->comment_content,
				'verified' => (bool) get_comment_meta( $c->comment_ID, 'sidccofo_verified', true ),
			);
		}
		return $out;
	}

	/**
	 * How many approved reviews gave each star value.
	 *
	 * @param int $product_id Product.
	 * @return int[] 5 => n, 4 => n, … 1 => n.
	 */
	public static function distribution( $product_id ) {
		$dist = array_fill_keys( array( 5, 4, 3, 2, 1 ), 0 );
		$ids  = get_comments(
			array(
				'post_id'  => (int) $product_id,
				'status'   => 'approve',
				'meta_key' => 'sidccofo_rating', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'fields'   => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			$r = (int) get_comment_meta( $id, 'sidccofo_rating', true );
			if ( isset( $dist[ $r ] ) ) {
				++$dist[ $r ];
			}
		}
		return $dist;
	}

	/*
	------------------------------------------------------------------
	 * Submitting (POST /reviews)
	 * ----------------------------------------------------------------
	 */

	/**
	 * Turn "#1042", "Order #1042" or "1042" into 1042.
	 *
	 * @param string $raw Typed order number.
	 * @return int
	 */
	public static function parse_order_number( $raw ) {
		return preg_match( '/(\d+)/', (string) $raw, $m ) ? absint( $m[1] ) : 0;
	}

	/**
	 * Visitor IP for rate limiting (hashed, never stored raw).
	 *
	 * @return string
	 */
	protected static function client_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'sidccofo_rv_fail_' . md5( $ip . wp_salt( 'nonce' ) );
	}

	/**
	 * Record a failed verification.
	 */
	protected static function record_failure() {
		$key = self::client_key();
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Validate and save a review.
	 *
	 * @param array $input product_id, order_number, email, rating, review, name.
	 * @return array|\WP_Error [ 'approved' => bool, 'message' => string ] or errors (data.fields).
	 */
	public static function submit( array $input ) {
		if ( (int) get_transient( self::client_key() ) >= self::MAX_FAILURES ) {
			return new \WP_Error( 'sidccofo_review_limited', __( 'Too many attempts. Please try again in an hour.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
		}

		$product = Product::get( absint( $input['product_id'] ?? 0 ) );
		if ( ! $product || ! $product->is_published() ) {
			return new \WP_Error( 'sidccofo_review_product', __( 'This product is not available.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
		}

		$rating   = absint( $input['rating'] ?? 0 );
		$order_no = self::parse_order_number( $input['order_number'] ?? '' );
		$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$text     = trim( sanitize_textarea_field( (string) ( $input['review'] ?? '' ) ) );
		$name     = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
		$min_len  = (int) apply_filters( 'sidcraft_commerce_review_min_length', 10 );

		$errors = array();
		if ( $rating < 1 || $rating > 5 ) {
			$errors['rating'] = __( 'Choose 1 to 5 stars.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( ! $order_no ) {
			$errors['order_number'] = __( 'Enter your order number (it’s in your confirmation email).', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( ! is_email( $email ) ) {
			$errors['email'] = __( 'Enter the email address you used for the order.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( mb_strlen( $text ) < $min_len ) {
			/* translators: %d: minimum characters. */
			$errors['review'] = sprintf( __( 'Please write at least %d characters.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $min_len );
		} elseif ( mb_strlen( $text ) > 5000 ) {
			$errors['review'] = __( 'Please keep your review under 5,000 characters.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( $errors ) {
			return self::error( $errors );
		}

		// Verify: the order exists, is paid, belongs to this email and contains the product.
		// The same message for "no such order" and "wrong email", so order numbers can't be probed.
		$order    = Order::get( $order_no );
		$verified = $order
			&& '' !== $order->get_email()
			&& strtolower( $order->get_email() ) === strtolower( $email );
		if ( ! $verified ) {
			self::record_failure();
			return self::error( array( 'order_number' => __( 'We couldn’t find an order with that number and email address.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
		}
		if ( ! $order->is_paid() ) {
			return self::error( array( 'order_number' => __( 'You can review this product once your order has been paid.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
		}
		$has_product = false;
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item['product_id'] === $product->get_id() ) {
				$has_product = true;
				break;
			}
		}
		if ( ! $has_product ) {
			self::record_failure();
			return self::error( array( 'order_number' => __( 'This order doesn’t include this product.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
		}
		$existing = get_comments(
			array(
				'post_id'    => $product->get_id(),
				'status'     => 'all',
				'count'      => true,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small, per-product lookup.
					array(
						'key'   => 'sidccofo_order_id',
						'value' => $order->get_id(),
					),
				),
			)
		);
		if ( $existing ) {
			return self::error( array( 'order_number' => __( 'You’ve already reviewed this product for this order. Thank you!', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
		}

		// Name shown publicly: what they typed, else "First L." from the order.
		if ( '' === $name ) {
			$billing = $order->get_billing();
			$last    = (string) ( $billing['last_name'] ?? '' );
			$name    = trim( (string) ( $billing['first_name'] ?? '' ) . ( '' !== $last ? ' ' . mb_substr( $last, 0, 1 ) . '.' : '' ) );
		}
		if ( '' === $name ) {
			$name = __( 'Verified customer', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		$name = mb_substr( $name, 0, 60 );

		/**
		 * Publish verified reviews at once (Store → Settings), or hold them for moderation.
		 *
		 * @param bool    $approve Approve.
		 * @param Product $product Product.
		 * @param Order   $order   Order.
		 */
		$approve = (bool) apply_filters( 'sidcraft_commerce_review_auto_approve', (bool) setting( 'reviews_approve', 1 ), $product, $order );

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product->get_id(),
				'comment_author'       => $name,
				'comment_author_email' => $email,
				'comment_author_IP'    => '',
				'comment_content'      => $text,
				'comment_type'         => 'comment',
				'comment_approved'     => $approve ? 1 : 0,
				'user_id'              => get_current_user_id(),
				'comment_agent'        => '',
			)
		);
		if ( ! $comment_id ) {
			return new \WP_Error( 'sidccofo_review_save', __( 'Something went wrong. Please try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 500 ) );
		}
		add_comment_meta( $comment_id, 'sidccofo_rating', $rating, true );
		add_comment_meta( $comment_id, 'sidccofo_verified', 1, true );
		add_comment_meta( $comment_id, 'sidccofo_order_id', $order->get_id(), true );
		self::recalculate( $product->get_id() );

		/* translators: 1: product name, 2: stars. */
		$order->add_note( sprintf( __( 'Customer reviewed “%1$s” (%2$d stars).', 'sidcraft-commerce-for-sidcraft-page-builder' ), $product->get_name(), $rating ) );

		/**
		 * Fires after a verified review is saved.
		 *
		 * @param int     $comment_id Comment ID.
		 * @param Product $product    Product.
		 * @param Order   $order      Order.
		 * @param int     $rating     Stars.
		 */
		do_action( 'sidcraft_commerce_review_added', $comment_id, $product, $order, $rating );

		return array(
			'approved' => $approve,
			'message'  => $approve
				? __( 'Thank you! Your review is now live.', 'sidcraft-commerce-for-sidcraft-page-builder' )
				: __( 'Thank you! Your review will appear once it has been approved.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Field errors as a WP_Error (first message on top, all in data.fields).
	 *
	 * @param array $errors field => message.
	 * @return \WP_Error
	 */
	protected static function error( array $errors ) {
		return new \WP_Error(
			'sidccofo_review_invalid',
			reset( $errors ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * Averages and admin
	 * ----------------------------------------------------------------
	 */

	/**
	 * Recalculate when a comment is approved, edited, trashed or deleted.
	 *
	 * @param int         $comment_id Comment ID.
	 * @param \WP_Comment $comment   Comment (on delete).
	 */
	public function on_status( $comment_id, $comment = null ) {
		$comment = $comment instanceof \WP_Comment ? $comment : get_comment( $comment_id );
		if ( $comment && Post_Types::PRODUCT === get_post_type( (int) $comment->comment_post_ID ) ) {
			self::recalculate( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Average of approved ratings.
	 *
	 * @param int $product_id Product.
	 */
	public static function recalculate( $product_id ) {
		$dist  = self::distribution( $product_id );
		$count = array_sum( $dist );
		$sum   = 0;
		foreach ( $dist as $stars => $n ) {
			$sum += $stars * $n;
		}
		update_post_meta( $product_id, '_sidccofo_rating_count', $count );
		update_post_meta( $product_id, '_sidccofo_rating_avg', $count ? round( $sum / $count, 2 ) : 0 );
		Product::copy_to_translations( $product_id, array( '_sidccofo_rating_count', '_sidccofo_rating_avg' ) );
		Product::forget( $product_id );
		Product::bump_query_version();
	}

	/**
	 * Admin Comments screen: stars and the verifying order above the text.
	 *
	 * @param string           $text    Comment text.
	 * @param \WP_Comment|null $comment Comment.
	 * @return string
	 */
	public function admin_comment_text( $text, $comment = null ) {
		if ( ! is_admin() || ! $comment instanceof \WP_Comment ) {
			return $text;
		}
		$rating = (int) get_comment_meta( $comment->comment_ID, 'sidccofo_rating', true );
		if ( ! $rating ) {
			return $text;
		}
		$meta     = star_markup( $rating );
		$order_id = (int) get_comment_meta( $comment->comment_ID, 'sidccofo_order_id', true );
		if ( $order_id ) {
			$link  = get_edit_post_link( $order_id );
			$meta .= ' <strong style="color:#1a7f37">' . esc_html__( 'Verified purchase', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</strong> · ';
			/* translators: %d: order number. */
			$label = sprintf( __( 'Order #%d', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order_id );
			$meta .= $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
		}
		return '<p class="sc-review-stars">' . $meta . '</p>' . $text;
	}
}
