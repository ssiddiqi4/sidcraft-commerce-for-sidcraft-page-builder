<?php
/**
 * Front-end glue that isn't a Unit:
 *   - add to cart without JavaScript (?sidccofo-add-to-cart= / form POST)
 *   - no-cache headers on Cart, Checkout and Order-received pages
 *   - the "buy box" on single product pages (price, stock, add to cart)
 *   - badges / "Sold Out" on the single product page's featured image
 *   - hourly cleanup cron
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Cart\Session;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Class Frontend
 */
class Frontend {

	/** Units whose output is per-visitor: their pages must never be cached. */
	const DYNAMIC_UNITS = array( 'sidccofo-cart', 'sidccofo-checkout', 'sidccofo-order-confirmation', 'sidccofo-affiliate-dashboard' );

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'template_redirect', array( $this, 'no_js_add_to_cart' ), 5 );
		add_action( 'template_redirect', array( $this, 'maybe_no_cache' ), 6 );
		add_filter( 'the_content', array( $this, 'buy_box' ), 25 ); // After Sidcraft Page Builder renders its layout (20).
		add_filter( 'post_thumbnail_html', array( $this, 'badge_featured_image' ), 20, 2 );
		add_filter( 'sidcraft_commerce_detected_units', array( $this, 'product_page_units' ) );
		add_action( 'sidcraft_commerce_cleanup', array( $this, 'cleanup' ) );
	}

	/**
	 * Handle ?sidccofo-add-to-cart=ID (links) and POSTed add-to-cart forms.
	 */
	public function no_js_add_to_cart() {
		// phpcs:disable WordPress.Security.NonceVerification -- Cart changes are nonce-free by design; see Rest class.
		$id = 0;
		if ( isset( $_REQUEST['sidccofo-add-to-cart'] ) ) {
			$id = absint( $_REQUEST['sidccofo-add-to-cart'] );
		}
		if ( ! $id ) {
			return;
		}
		$qty     = isset( $_REQUEST['quantity'] ) ? max( 1, absint( $_REQUEST['quantity'] ) ) : 1;
		$options = isset( $_REQUEST['sidccofo_options'] ) && is_array( $_REQUEST['sidccofo_options'] )
			? \SidcraftCommerce\Data\Product_Options::from_request( wp_unslash( $_REQUEST['sidccofo_options'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by from_request().
			: array();
		// phpcs:enable

		$result = $options ? Cart::instance()->add_with_options( $id, $qty, $options ) : Cart::instance()->add( $id, $qty );
		Cart::instance()->session()->set( 'notice', is_wp_error( $result ) ? $result->get_error_message() : '' );
		Cart::instance()->session()->save();

		wp_safe_redirect( remove_query_arg( array( 'sidccofo-add-to-cart', 'quantity' ), page_url( 'cart' ) ) );
		exit;
	}

	/**
	 * Mark per-visitor pages as uncacheable. DONOTCACHEPAGE is honoured by
	 * WP Rocket, LiteSpeed, W3TC, WP Super Cache and most hosts.
	 */
	public function maybe_no_cache() {
		$dynamic = array_filter( array( page_id( 'cart' ), page_id( 'checkout' ), page_id( 'received' ) ) );
		$found   = is_singular() ? Plugin::instance()->assets->units_in_post( get_queried_object_id() ) : array();

		if ( is_page( $dynamic ) || array_intersect( self::DYNAMIC_UNITS, $found ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache flag.
			}
			nocache_headers();
		}
	}

	/**
	 * Product pages need the single-product Units' assets.
	 *
	 * @param string[] $found Detected Unit names.
	 * @return string[]
	 */
	public function product_page_units( $found ) {
		if ( is_singular( Post_Types::PRODUCT ) ) {
			// Base styles always: the featured image carries badges / "Sold Out".
			$found[] = 'sidccofo-product-price';
			if ( $this->buy_box_enabled() ) {
				$found[] = 'sidccofo-add-to-cart';
			}
		}
		return $found;
	}

	/**
	 * Whether to auto-insert the buy box.
	 *
	 * @return bool
	 */
	protected function buy_box_enabled() {
		$enabled = (bool) setting( 'auto_buy_box', 1 ) && ! get_post_meta( get_the_ID(), '_sidccofo_hide_buy_box', true );
		// A Sidcraft layout that places the Add to Cart Unit itself wins.
		if ( $enabled && in_array( 'sidccofo-add-to-cart', Plugin::instance()->assets->units_in_post( get_the_ID() ), true ) ) {
			$enabled = false;
		}

		/**
		 * Turn the automatic buy box off, e.g. when a Sidcraft layout places
		 * the product Units itself.
		 *
		 * @param bool $enabled Enabled.
		 * @param int  $post_id Product ID.
		 */
		return (bool) apply_filters( 'sidcraft_commerce_auto_buy_box', $enabled, get_the_ID() );
	}

	/**
	 * Prepend price, rating, stock and add-to-cart to product content, so any
	 * theme shows a working product page without a custom template.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function buy_box( $content ) {
		if ( ! is_singular( Post_Types::PRODUCT ) || ! in_the_loop() || ! is_main_query() || ! $this->buy_box_enabled() ) {
			return $content;
		}
		$box = Plugin::instance()->templates->render( 'single/buy-box.php', array( 'product_id' => get_the_ID() ) );
		return $box . $content;
	}

	/**
	 * Put the product's badge (HOT, POPULAR, Sale, Clearance) and "Sold Out"
	 * on its featured image on the single product page.
	 *
	 * @param string $html    Thumbnail HTML.
	 * @param int    $post_id Post the thumbnail belongs to.
	 * @return string
	 */
	public function badge_featured_image( $html, $post_id ) {
		if ( '' === $html || is_admin() || ! is_singular( Post_Types::PRODUCT ) || (int) $post_id !== (int) get_queried_object_id() ) {
			return $html;
		}

		/**
		 * Turn badges on the single product image off (e.g. when the theme
		 * already wraps the image in its own gallery).
		 *
		 * @param bool $enabled Enabled.
		 * @param int  $post_id Product ID.
		 */
		if ( ! apply_filters( 'sidcraft_commerce_badge_single_image', true, $post_id ) ) {
			return $html;
		}

		$product = \SidcraftCommerce\Data\Product::get( $post_id );
		$overlay = $product ? $product->get_image_overlay_html() : '';
		if ( '' === $overlay ) {
			return $html;
		}

		Plugin::instance()->assets->require_assets( array( 'styles' => array( 'sidccofo-commerce' ) ) );
		return sprintf(
			'<span class="sc-media-badged%1$s">%2$s%3$s</span>',
			$product->is_in_stock() ? '' : ' is-sold-out',
			$html,
			$overlay
		);
	}

	/**
	 * Hourly: delete expired sessions, cancel abandoned unpaid orders.
	 */
	public function cleanup() {
		Session::cleanup();

		$stale = get_posts(
			array(
				'post_type'      => Post_Types::ORDER,
				'post_status'    => 'sidccofo-pending',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'before' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
						'column' => 'post_date_gmt',
					),
				),
				// Subscription renewals wait for their own grace period (Subscriptions::run).
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded hourly batch.
					array(
						'key'     => '_sidccofo_renewal_of',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $stale as $id ) {
			$order = Order::get( $id );
			if ( $order ) {
				$order->set_status( 'sidccofo-cancelled', __( 'Unpaid for 2 hours.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			}
		}
	}
}
