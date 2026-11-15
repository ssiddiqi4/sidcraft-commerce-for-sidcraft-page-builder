<?php
/**
 * Coupon model on top of the sidccofo_coupon post type.
 *
 * The post title is the code customers type (matched case-insensitively).
 * Meta:
 *   _sidccofo_coupon_code         Normalised (upper-case) code, for lookups.
 *   _sidccofo_coupon_type         percent | fixed.
 *   _sidccofo_coupon_amount       Percentage (0–100) or a fixed amount in store currency.
 *   _sidccofo_coupon_min_spend    Minimum cart subtotal ('' = none).
 *   _sidccofo_coupon_expires      Y-m-d, last valid day in the site's timezone ('' = never).
 *   _sidccofo_coupon_usage_limit  Max paid orders that may use it (0 = unlimited).
 *   _sidccofo_coupon_usage_count  Orders that used it (counted when stock is reserved,
 *                           given back on cancel / refund).
 *   _sidccofo_coupon_per_customer Max orders one customer may use it on (0 = unlimited). A
 *                           customer is their account or their billing email, so
 *                           guests count too; orders count like the usage count.
 *   _sidccofo_coupon_free_shipping 0|1: the order ships free (alone or with a discount).
 *   _sidccofo_coupon_products     Product ids it applies to ([] = all).
 *   _sidccofo_coupon_categories   Product category ids it applies to, children included ([] = all).
 *   _sidccofo_coupon_logged_in    0|1: only for customers who are logged in.
 *   _sidccofo_coupon_starts       Y-m-d, first valid day in the site's timezone ('' = now).
 *   _sidccofo_coupon_max_spend    Highest cart subtotal it may be used on ('' = none).
 *   _sidccofo_coupon_exclude_products   Product ids it never applies to.
 *   _sidccofo_coupon_exclude_categories Category ids (children included) it never applies to.
 *   _sidccofo_coupon_emails       Customer emails allowed to use it ([] = everyone);
 *                           "*@example.com" allows a whole domain. Checked against
 *                           the account email and the billing email.
 *
 * With products or categories set, the discount is worked out on those cart
 * lines only; the minimum spend still counts the whole cart.
 *
 * Percentage coupons are a percentage of the cart subtotal, which is already
 * built from each product's active (sale) price.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Data;

use function SidcraftCommerce\format_price;
use function SidcraftCommerce\round_amount;

defined( 'ABSPATH' ) || exit;

/**
 * Class Coupon
 */
class Coupon {

	/**
	 * Uses per customer worked out this request (cleared when any order
	 * takes or gives back a use).
	 *
	 * @var int[]
	 */
	protected static $usage_memo = array();

	/**
	 * Name of the checkout lock this request holds ('' = none).
	 *
	 * @var string
	 */
	protected static $held_lock = '';

	/**
	 * Post.
	 *
	 * @var \WP_Post
	 */
	protected $post;

	/**
	 * Normalise a code the way it is stored.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	public static function normalize( $code ) {
		$code = strtoupper( trim( wp_strip_all_tags( (string) $code ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $code, 0, 60 ) : substr( $code, 0, 60 );
	}

	/**
	 * Get a coupon by post ID.
	 *
	 * @param int $id Post ID.
	 * @return Coupon|null
	 */
	public static function get( $id ) {
		$post = get_post( absint( $id ) );
		return ( $post && Post_Types::COUPON === $post->post_type ) ? new static( $post ) : null;
	}

	/**
	 * Find a published coupon by its code.
	 *
	 * @param string $code Code as typed.
	 * @return Coupon|null
	 */
	public static function get_by_code( $code ) {
		static $memo = array();
		$code = self::normalize( $code );
		if ( '' === $code ) {
			return null;
		}
		$slot = get_current_blog_id() . ':' . $code; // Each store has its own coupons.
		if ( array_key_exists( $slot, $memo ) && ! is_admin() ) {
			return $memo[ $slot ] ? self::get( $memo[ $slot ] ) : null;
		}
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::COUPON,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// The original coupon wins if a duplicate ever slips through (it holds the usage count).
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Few coupons; exact-match lookup.
					array(
						'key'   => '_sidccofo_coupon_code',
						'value' => $code,
					),
				),
			)
		);
		$memo[ $slot ] = $ids ? (int) $ids[0] : 0;
		return $ids ? self::get( $ids[0] ) : null;
	}

	/**
	 * Constructor.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function __construct( \WP_Post $post ) {
		$this->post = $post;
	}

	/**
	 * Meta helper.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	protected function meta( $key ) {
		return get_post_meta( $this->post->ID, $key, true );
	}

	/**
	 * Id.
	 *
	 * @return int
	 */
	public function get_id() {
		return (int) $this->post->ID;
	}

	/**
	 * Code (upper case).
	 *
	 * @return string
	 */
	public function get_code() {
		$code = (string) $this->meta( '_sidccofo_coupon_code' );
		return '' !== $code ? $code : self::normalize( $this->post->post_title );
	}

	/**
	 * Discount type: percent | fixed.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'fixed' === $this->meta( '_sidccofo_coupon_type' ) ? 'fixed' : 'percent';
	}

	/**
	 * Amount: percentage or currency amount.
	 *
	 * @return float
	 */
	public function get_amount() {
		$amount = max( 0.0, (float) $this->meta( '_sidccofo_coupon_amount' ) );
		// Fixed amounts are typed in the store currency; shoppers get them in theirs.
		return 'percent' === $this->get_type() ? min( 100.0, $amount ) : round_amount( \SidcraftCommerce\Currencies::to_active( $amount ) );
	}

	/**
	 * Minimum subtotal (0 = none).
	 *
	 * @return float
	 */
	public function get_min_spend() {
		return round_amount( \SidcraftCommerce\Currencies::to_active( max( 0.0, (float) $this->meta( '_sidccofo_coupon_min_spend' ) ) ) );
	}

	/**
	 * Highest subtotal it may be used on (0 = none).
	 *
	 * @return float
	 */
	public function get_max_spend() {
		return round_amount( \SidcraftCommerce\Currencies::to_active( max( 0.0, (float) $this->meta( '_sidccofo_coupon_max_spend' ) ) ) );
	}

	/**
	 * Products it never applies to.
	 *
	 * @return int[]
	 */
	public function get_excluded_product_ids() {
		return array_values( array_filter( array_map( 'absint', (array) $this->meta( '_sidccofo_coupon_exclude_products' ) ) ) );
	}

	/**
	 * Categories it never applies to (children included).
	 *
	 * @return int[]
	 */
	public function get_excluded_category_ids() {
		return array_values( array_filter( array_map( 'absint', (array) $this->meta( '_sidccofo_coupon_exclude_categories' ) ) ) );
	}

	/**
	 * Emails allowed to use it ([] = everyone). Lower case; may hold "*@domain".
	 *
	 * @return string[]
	 */
	public function get_allowed_emails() {
		return array_values( array_filter( array_map( 'strtolower', array_map( 'trim', (array) $this->meta( '_sidccofo_coupon_emails' ) ) ) ) );
	}

	/**
	 * Is one of these emails allowed to use it?
	 *
	 * @param string[] $emails Account and billing emails.
	 * @return bool
	 */
	public function allows_emails( array $emails ) {
		$allowed = $this->get_allowed_emails();
		if ( ! $allowed ) {
			return true;
		}
		foreach ( array_filter( array_map( 'strtolower', array_map( 'trim', $emails ) ) ) as $email ) {
			foreach ( $allowed as $rule ) {
				if ( $rule === $email || ( 0 === strpos( $rule, '*@' ) && substr( $email, -strlen( $rule ) + 1 ) === substr( $rule, 1 ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Expiry date Y-m-d or ''.
	 *
	 * @return string
	 */
	public function get_expires() {
		$date = (string) $this->meta( '_sidccofo_coupon_expires' );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	/**
	 * Usage limit (0 = unlimited).
	 *
	 * @return int
	 */
	public function get_usage_limit() {
		return max( 0, (int) $this->meta( '_sidccofo_coupon_usage_limit' ) );
	}

	/**
	 * Times used.
	 *
	 * @return int
	 */
	public function get_usage_count() {
		return max( 0, (int) $this->meta( '_sidccofo_coupon_usage_count' ) );
	}

	/**
	 * Uses allowed per customer (0 = unlimited).
	 *
	 * @return int
	 */
	public function get_per_customer_limit() {
		return max( 0, (int) $this->meta( '_sidccofo_coupon_per_customer' ) );
	}

	/**
	 * Orders a customer has used this coupon on: orders placed from their
	 * account or with one of their email addresses, counted while they hold
	 * the coupon (cancelled and refunded orders give the use back).
	 *
	 * @param int      $user_id User (0 = guest).
	 * @param string[] $emails  Email addresses.
	 * @param int      $exclude Order not to count (the customer's own unpaid attempt, replaced at checkout).
	 * @return int
	 */
	public function customer_usage( $user_id, array $emails, $exclude = 0 ) {
		$user_id = (int) $user_id;
		$emails  = array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'sanitize_email', $emails ) ) ) ) );
		if ( ! $user_id && ! $emails ) {
			return 0;
		}
		$key = implode( '|', array( get_current_blog_id(), $this->get_id(), $user_id, implode( ',', $emails ), (int) $exclude ) );
		if ( isset( self::$usage_memo[ $key ] ) ) {
			return self::$usage_memo[ $key ];
		}
		$who = array( 'relation' => 'OR' );
		if ( $user_id ) {
			$who[] = array(
				'key'   => '_sidccofo_customer_id',
				'value' => $user_id,
			);
		}
		if ( $emails ) {
			$who[] = array(
				'key'     => '_sidccofo_billing_email',
				'value'   => $emails,
				'compare' => 'IN',
			);
		}
		$ids          = get_posts(
			array(
				'post_type'        => Post_Types::ORDER,
				'post_status'      => 'any',
				'posts_per_page'   => $this->get_per_customer_limit() + 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'cache_results'    => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'post__not_in'     => $exclude ? array( (int) $exclude ) : array(),
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only when a per-customer limit is set.
				'meta_query'       => array(
					array(
						'key'   => '_sidccofo_coupon_code',
						'value' => $this->get_code(),
					),
					array(
						'key'   => '_sidccofo_coupon_counted',
						'value' => '1',
					),
					$who,
				),
			)
		);
		self::$usage_memo[ $key ] = count( $ids );
		return self::$usage_memo[ $key ];
	}

	/**
	 * Has this customer used up their share of the coupon?
	 *
	 * @param int      $user_id User (0 = guest).
	 * @param string[] $emails  Email addresses.
	 * @param int      $exclude Order not to count.
	 * @return true|\WP_Error
	 */
	public function validate_for_customer( $user_id, array $emails, $exclude = 0 ) {
		if ( ! $this->allows_emails( $emails ) ) {
			/* translators: %s: coupon code. */
			return new \WP_Error( 'sidccofo_coupon_email', sprintf( __( 'Coupon “%s” isn’t valid for your email address.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_code() ) );
		}
		$limit = $this->get_per_customer_limit();
		if ( $limit && $this->customer_usage( $user_id, $emails, $exclude ) >= $limit ) {
			return new \WP_Error(
				'sidccofo_coupon_customer_limit',
				1 === $limit
					/* translators: %s: coupon code. */
					? sprintf( __( 'You have already used coupon “%s”.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_code() )
					/* translators: 1: coupon code, 2: times. */
					: sprintf( __( 'You have already used coupon “%1$s” %2$d times, its limit per customer.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_code(), $limit )
			);
		}
		return true;
	}

	/**
	 * Free shipping with this coupon?
	 *
	 * @return bool
	 */
	public function gives_free_shipping() {
		return (bool) $this->meta( '_sidccofo_coupon_free_shipping' );
	}

	/**
	 * Only for logged-in customers?
	 *
	 * @return bool
	 */
	public function requires_login() {
		return (bool) $this->meta( '_sidccofo_coupon_logged_in' );
	}

	/**
	 * First valid day Y-m-d or ''.
	 *
	 * @return string
	 */
	public function get_starts() {
		$date = (string) $this->meta( '_sidccofo_coupon_starts' );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	/**
	 * Not valid yet (before the start day, site timezone)?
	 *
	 * @return bool
	 */
	public function is_scheduled() {
		$date = $this->get_starts();
		return '' !== $date && wp_date( 'Y-m-d' ) < $date;
	}

	/**
	 * Products it applies to ([] = all).
	 *
	 * @return int[]
	 */
	public function get_product_ids() {
		return array_values( array_filter( array_map( 'absint', (array) $this->meta( '_sidccofo_coupon_products' ) ) ) );
	}

	/**
	 * Product categories it applies to ([] = all).
	 *
	 * @return int[]
	 */
	public function get_category_ids() {
		return array_values( array_filter( array_map( 'absint', (array) $this->meta( '_sidccofo_coupon_categories' ) ) ) );
	}

	/**
	 * Limited to some products or categories?
	 *
	 * @return bool
	 */
	public function is_restricted() {
		return $this->get_product_ids() || $this->get_category_ids() || $this->get_excluded_product_ids() || $this->get_excluded_category_ids();
	}

	/**
	 * Is a product in one of these categories (or their children)?
	 *
	 * @param int   $product_id Product.
	 * @param int[] $cats       Category ids.
	 * @return bool
	 */
	protected static function in_categories( $product_id, array $cats ) {
		if ( ! $cats ) {
			return false;
		}
		$terms = get_the_terms( (int) $product_id, Post_Types::CATEGORY );
		foreach ( ( $terms && ! is_wp_error( $terms ) ) ? $terms : array() as $term ) {
			$ids = array_merge( array( (int) $term->term_id ), array_map( 'intval', get_ancestors( (int) $term->term_id, Post_Types::CATEGORY, 'taxonomy' ) ) );
			if ( array_intersect( $ids, $cats ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does the discount apply to this product?
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public function applies_to( $product_id ) {
		if ( ! $this->is_restricted() ) {
			return true;
		}
		$product_id = (int) $product_id;
		// Exclusions win over everything else.
		if ( in_array( $product_id, $this->get_excluded_product_ids(), true ) || self::in_categories( $product_id, $this->get_excluded_category_ids() ) ) {
			return false;
		}
		if ( ! $this->get_product_ids() && ! $this->get_category_ids() ) {
			return true; // Only exclusions set: everything else.
		}
		return in_array( $product_id, $this->get_product_ids(), true ) || self::in_categories( $product_id, $this->get_category_ids() );
	}

	/**
	 * Expired (after the end of the expiry day, site timezone)?
	 *
	 * @return bool
	 */
	public function is_expired() {
		$date = $this->get_expires();
		return '' !== $date && wp_date( 'Y-m-d' ) > $date;
	}

	/**
	 * Human label for the offer: "20% off" / "$10.00 off".
	 *
	 * @return string
	 */
	public function get_offer_label() {
		if ( $this->get_amount() <= 0 ) {
			return $this->gives_free_shipping() ? __( 'Free shipping', 'sidcraft-commerce-for-sidcraft-page-builder' ) : '';
		}
		if ( 'percent' === $this->get_type() ) {
			/* translators: %s: percentage. */
			$label = sprintf( __( '%s%% off', 'sidcraft-commerce-for-sidcraft-page-builder' ), rtrim( rtrim( number_format( $this->get_amount(), 2, '.', '' ), '0' ), '.' ) );
		} else {
			/* translators: %s: amount. */
			$label = sprintf( __( '%s off', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $this->get_amount() ) );
		}
		if ( $this->gives_free_shipping() ) {
			/* translators: %s: discount, e.g. "10% off". */
			$label = sprintf( __( '%s + free shipping', 'sidcraft-commerce-for-sidcraft-page-builder' ), $label );
		}
		return $label;
	}

	/**
	 * Can this coupon be used on a cart with this subtotal?
	 *
	 * @param float $subtotal Cart subtotal.
	 * @return true|\WP_Error
	 */
	public function validate( $subtotal ) {
		if ( 'publish' !== $this->post->post_status ) {
			$error = new \WP_Error( 'sidccofo_coupon_invalid', __( 'This coupon code is not valid.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} elseif ( $this->is_scheduled() ) {
			/* translators: 1: coupon code, 2: date. */
			$error = new \WP_Error( 'sidccofo_coupon_scheduled', sprintf( __( 'Coupon “%1$s” can be used from %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_code(), date_i18n( get_option( 'date_format' ), strtotime( $this->get_starts() ) ) ) );
		} elseif ( $this->requires_login() && ! is_user_logged_in() ) {
			/* translators: %s: coupon code. */
			$error = new \WP_Error( 'sidccofo_coupon_login', sprintf( __( 'Log in to use coupon “%s”.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_code() ) );
		} elseif ( $this->is_expired() ) {
			/* translators: %s: coupon code. */
			$error = new \WP_Error( 'sidccofo_coupon_expired', sprintf( __( 'Coupon “%s” has expired.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_code() ) );
		} elseif ( $this->get_usage_limit() && $this->get_usage_count() >= $this->get_usage_limit() ) {
			/* translators: %s: coupon code. */
			$error = new \WP_Error( 'sidccofo_coupon_used_up', sprintf( __( 'Coupon “%s” has reached its usage limit.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_code() ) );
		} elseif ( $this->get_min_spend() > 0 && (float) $subtotal < $this->get_min_spend() ) {
			$error = new \WP_Error(
				'sidccofo_coupon_min_spend',
				/* translators: 1: coupon code, 2: minimum amount. */
				sprintf( __( 'Coupon “%1$s” needs a subtotal of at least %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_code(), format_price( $this->get_min_spend() ) )
			);
		} elseif ( $this->get_max_spend() > 0 && (float) $subtotal > $this->get_max_spend() ) {
			$error = new \WP_Error(
				'sidccofo_coupon_max_spend',
				/* translators: 1: coupon code, 2: maximum amount. */
				sprintf( __( 'Coupon “%1$s” can be used on a subtotal of up to %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_code(), format_price( $this->get_max_spend() ) )
			);
		} elseif ( $this->get_amount() <= 0 && ! $this->gives_free_shipping() ) {
			$error = new \WP_Error( 'sidccofo_coupon_invalid', __( 'This coupon code is not valid.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} else {
			$error = true;
		}

		/**
		 * Filter coupon validation. Return a WP_Error to refuse the coupon.
		 *
		 * @param true|\WP_Error $error    Result.
		 * @param Coupon         $coupon   Coupon.
		 * @param float          $subtotal Cart subtotal.
		 */
		return apply_filters( 'sidcraft_commerce_validate_coupon', $error, $this, (float) $subtotal );
	}

	/**
	 * Can this coupon be used on this cart? Adds the product and category
	 * limits to validate().
	 *
	 * @param \SidcraftCommerce\Cart\Cart $cart Cart.
	 * @return true|\WP_Error
	 */
	public function validate_for_cart( $cart ) {
		$valid = $this->validate( $cart->get_goods_subtotal() );
		if ( true === $valid && $this->is_restricted() && ! $this->applies_to_any( $cart ) ) {
			/* translators: %s: coupon code. */
			$valid = new \WP_Error( 'sidccofo_coupon_not_applicable', sprintf( __( 'Coupon “%s” doesn’t apply to anything in your cart.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_code() ) );
		}
		// Logged-in shoppers learn at once; guests when checkout has their email.
		if ( true === $valid && ( $this->get_per_customer_limit() || $this->get_allowed_emails() ) && is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$valid = $this->validate_for_customer( $user->ID, array( $user->user_email ), (int) $cart->session()->get( 'order_awaiting_payment', 0 ) );
		}
		return $valid;
	}

	/**
	 * Let one checkout at a time use a coupon code, from checking its limits
	 * until the new order has counted its use; without this, two checkouts
	 * sent at the same moment could both pass a "last use" check. Uses the
	 * database's named locks (MySQL / MariaDB GET_LOCK), which are freed when
	 * the request ends even if it dies. On a database without them,
	 * checkout carries on unlocked, as before.
	 *
	 * @param string $code Coupon code ('' = nothing to lock).
	 * @return true|\WP_Error Error when another checkout held it too long.
	 */
	public static function lock( $code ) {
		global $wpdb;
		$code = self::normalize( $code );
		if ( '' === $code || '' !== self::$held_lock ) {
			return true;
		}
		$name = 'sidccofo_cpn_' . md5( $wpdb->prefix . $code );
		/**
		 * Seconds a checkout waits for another one using the same coupon.
		 *
		 * @param int    $seconds Wait.
		 * @param string $code    Coupon code.
		 */
		$wait = max( 1, (int) apply_filters( 'sidcraft_commerce_coupon_lock_wait', 10, $code ) );
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Named lock, nothing to cache.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $wait ) );
		$wpdb->suppress_errors( $suppress );
		if ( '1' === (string) $got ) {
			self::$held_lock = $name;
			// Anything read before the wait may be out of date now.
			self::$usage_memo = array();
			$coupon           = self::get_by_code( $code );
			if ( $coupon ) {
				wp_cache_delete( $coupon->get_id(), 'post_meta' );
			}
			return true;
		}
		if ( '0' === (string) $got ) {
			return new \WP_Error( 'sidccofo_coupon_busy', __( 'Lots of orders are using this coupon right now. Please try again in a moment.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 503 ) );
		}
		return true; // No named locks on this database.
	}

	/**
	 * Free the checkout lock, if this request holds one.
	 */
	public static function unlock() {
		global $wpdb;
		if ( '' === self::$held_lock ) {
			return;
		}
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Named lock.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$held_lock ) );
		$wpdb->suppress_errors( $suppress );
		self::$held_lock = '';
	}

	/**
	 * Is anything in the cart one of the coupon's products or categories?
	 * (Free shipping from a limited coupon needs one too.)
	 *
	 * @param \SidcraftCommerce\Cart\Cart $cart Cart.
	 * @return bool
	 */
	public function applies_to_any( $cart ) {
		foreach ( $cart->get_items() as $line ) {
			if ( empty( $line['gift_card'] ) && $this->applies_to( (int) $line['product_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Discount for a subtotal, never more than the subtotal.
	 *
	 * @param float $subtotal Cart subtotal.
	 * @return float
	 */
	public function discount_for( $subtotal ) {
		$subtotal = max( 0.0, (float) $subtotal );
		$discount = 'percent' === $this->get_type() ? $subtotal * $this->get_amount() / 100 : $this->get_amount();
		$discount = round_amount( min( $subtotal, $discount ) );

		/**
		 * Filter the discount a coupon gives.
		 *
		 * @param float  $discount Discount.
		 * @param Coupon $coupon   Coupon.
		 * @param float  $subtotal Subtotal.
		 */
		return round_amount( min( $subtotal, max( 0.0, (float) apply_filters( 'sidcraft_commerce_coupon_discount', $discount, $this, $subtotal ) ) ) );
	}

	/**
	 * Change the usage counter in one update, so two orders counted at the
	 * same moment (or a cancel and a new order) can't overwrite each other.
	 *
	 * @param int $delta +1 or -1.
	 */
	public function adjust_usage( $delta ) {
		global $wpdb;
		self::$usage_memo = array();
		$delta            = (int) $delta;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic counter, cache cleared below.
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = GREATEST( 0, CAST(meta_value AS SIGNED) + %d ) WHERE post_id = %d AND meta_key = '_sidccofo_coupon_usage_count'", $delta, $this->get_id() ) );
		wp_cache_delete( $this->get_id(), 'post_meta' );
		if ( ! $done && '' === get_post_meta( $this->get_id(), '_sidccofo_coupon_usage_count', true ) ) {
			// Coupons made in code without the counter yet.
			add_post_meta( $this->get_id(), '_sidccofo_coupon_usage_count', max( 0, $delta ), true );
		}
	}
}
