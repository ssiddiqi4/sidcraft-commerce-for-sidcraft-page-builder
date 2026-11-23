<?php
/**
 * Stock tools: low-stock emails to the owner, "Notify me when back in
 * stock", backorders, pre-orders and minimum / maximum / step quantities.
 *
 * Per product (box "Stock options" on the product screen):
 *   _sidccofo_backorders     no | yes | notify (allow, and tell the buyer)
 *   _sidccofo_low_stock      Warn me at or below this many (empty = store default)
 *   _sidccofo_min_qty / _max_qty / _qty_step   Quantity rules per order
 *   _sidccofo_preorder_date  Y-m-d: the product is a pre-order until then
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\rate_limit;
use function SidcraftCommerce\resolve_product;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Stock_Alerts
 */
class Stock_Alerts extends Tool {

	const WAITING = '_sidccofo_notify_list';

	/** {@inheritDoc} */
	public function hooks() {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		$this->settings(
			'stock',
			__( 'Stock alerts, backorders and pre-orders', $d ),
			array(
				'stock_low_default' => array( __( 'Low-stock warning at', $d ), 'text', __( 'Email the owner when a product that tracks stock falls to this many or fewer. 0 = off. A product can set its own number.', $d ) ),
				'stock_alert_email' => array( __( 'Send warnings to', $d ), 'email', __( 'Empty = the site admin email.', $d ) ),
				'stock_notify_me'   => array( __( '“Notify me when back in stock” form', $d ), 'checkbox', __( 'Shown on products that are out of stock. The email is used once and then deleted.', $d ) ),
			)
		);
		add_action( 'sidcraft_commerce_stock_changed', array( $this, 'changed' ), 10, 3 );
		add_action( 'add_meta_boxes_' . Post_Types::PRODUCT, array( $this, 'box' ) );
		add_action( 'save_post_' . Post_Types::PRODUCT, array( $this, 'save' ), 20 );
		add_filter( 'sidcraft_commerce_quantity_rules', array( $this, 'rules' ), 10, 2 );
		add_filter( 'sidcraft_commerce_validate_cart_item', array( $this, 'validate' ), 10, 3 );
		add_filter( 'sidcraft_commerce_button_text', array( $this, 'button' ), 10, 2 );
		add_filter( 'sidcraft_commerce_price_html', array( $this, 'price_note' ), 20, 2 );
		add_filter( 'sidcraft_commerce_stock_label', array( $this, 'label' ), 10, 3 );
		add_filter( 'sidcraft_commerce_stock_state', array( $this, 'state' ), 10, 2 );
		add_filter( 'sidcraft_commerce_render_sidccofo-stock-status', array( $this, 'notify_form' ), 10, 2 );
		add_action( 'sidcraft_commerce_order_created', array( $this, 'stamp' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_shortcode( 'sidccofo_notify_me', array( $this, 'shortcode' ) );
	}

	/*
	------------------------------------------------------------------
	 * Alerts
	 * ----------------------------------------------------------------
	 */

	/**
	 * Warn at or below this many.
	 *
	 * @param int $product_id Product.
	 * @return int
	 */
	protected function threshold( $product_id ) {
		$own = get_post_meta( $product_id, '_sidccofo_low_stock', true );
		return '' !== (string) $own && is_numeric( $own ) ? (int) $own : (int) setting( 'stock_low_default', 5 );
	}

	/**
	 * Stock moved: warn the owner, or tell the people waiting.
	 *
	 * @param int $product_id Product.
	 * @param int $new        New quantity.
	 * @param int $delta      Change.
	 */
	public function changed( $product_id, $new, $delta ) {
		$old = $new - $delta;
		if ( $delta < 0 ) {
			$t = $this->threshold( $product_id );
			if ( $t > 0 && $new <= $t && $old > $t ) {
				$to      = is_email( (string) setting( 'stock_alert_email', '' ) ) ? (string) setting( 'stock_alert_email', '' ) : get_option( 'admin_email' );
				$name    = wp_strip_all_tags( get_the_title( $product_id ) );
				/* translators: %s: product name. */
				$subject = sprintf( __( 'Low stock: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $name );
				/* translators: 1: product name, 2: units left, 3: edit link. */
				$body = sprintf( __( "“%1\$s” is down to %2\$d in stock.\n\nEdit it: %3\$s", 'sidcraft-commerce-for-sidcraft-page-builder' ), $name, $new, admin_url( 'post.php?post=' . (int) $product_id . '&action=edit' ) );
				wp_mail( $to, $subject, $body );
			}
		} elseif ( $delta > 0 && $old <= 0 && $new > 0 ) {
			self::notify_waiting( $product_id );
		}
	}

	/**
	 * Email everyone waiting for this product, then forget them.
	 *
	 * @param int $product_id Product.
	 */
	public static function notify_waiting( $product_id ) {
		$list = (array) get_post_meta( $product_id, self::WAITING, true );
		if ( ! $list ) {
			return;
		}
		delete_post_meta( $product_id, self::WAITING );
		$name = wp_strip_all_tags( get_the_title( $product_id ) );
		$url  = get_permalink( $product_id );
		foreach ( $list as $row ) {
			if ( empty( $row['email'] ) || ! is_email( $row['email'] ) ) {
				continue;
			}
			/* translators: %s: product name. */
			$subject = sprintf( __( '%s is back in stock', 'sidcraft-commerce-for-sidcraft-page-builder' ), $name );
			/* translators: 1: product name, 2: link. */
			$body = sprintf( __( "Good news: “%1\$s” is back in stock.\n\nGet it before it sells out again: %2\$s\n\nWe sent this once because you asked us to; your email address has been deleted from our waiting list.", 'sidcraft-commerce-for-sidcraft-page-builder' ), $name, $url );
			wp_mail( $row['email'], $subject, $body );
		}
	}

	/**
	 * Stock raised by hand on the product screen.
	 *
	 * @param int $post_id Product.
	 */
	public function save( $post_id ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( isset( $_POST['_sidccofo_stock_opts'] ) && current_user_can( 'edit_post', $post_id ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_stock_opts'] ) ), 'sidccofo_stock_opts' ) ) {
			$bo = isset( $_POST['sidccofo_backorders'] ) ? sanitize_key( wp_unslash( $_POST['sidccofo_backorders'] ) ) : 'no';
			update_post_meta( $post_id, '_sidccofo_backorders', in_array( $bo, array( 'yes', 'notify' ), true ) ? $bo : 'no' );
			foreach ( array( 'low_stock', 'min_qty', 'max_qty', 'qty_step' ) as $k ) {
				$v = isset( $_POST[ 'sidccofo_' . $k ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ 'sidccofo_' . $k ] ) ) ) : '';
				update_post_meta( $post_id, '_sidccofo_' . $k, ctype_digit( $v ) ? (string) $v : '' );
			}
			$date = isset( $_POST['sidccofo_preorder_date'] ) ? sanitize_text_field( wp_unslash( $_POST['sidccofo_preorder_date'] ) ) : '';
			update_post_meta( $post_id, '_sidccofo_preorder_date', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '' );
			Product::forget( $post_id );
			Product::sync_derived_meta( $post_id );
		}
		$p = Product::get( $post_id );
		if ( $p && $p->is_in_stock() ) {
			self::notify_waiting( $post_id );
		}
	}

	/*
	------------------------------------------------------------------
	 * Product screen
	 * ----------------------------------------------------------------
	 */

	/**
	 * Add the box.
	 */
	public function box() {
		add_meta_box( 'sidccofo-stock-options', __( 'Stock options', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_box' ), Post_Types::PRODUCT, 'side' );
	}

	/**
	 * Box contents.
	 *
	 * @param \WP_Post $post Product.
	 */
	public function render_box( $post ) {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		wp_nonce_field( 'sidccofo_stock_opts', '_sidccofo_stock_opts' );
		$m = static function ( $k ) use ( $post ) {
			return (string) get_post_meta( $post->ID, '_sidccofo_' . $k, true );
		};
		echo '<p><label>' . esc_html__( 'Backorders', $d ) . '<br><select name="sidccofo_backorders" class="widefat">';
		foreach ( array( 'no' => __( 'Do not allow', $d ), 'notify' => __( 'Allow, and tell the buyer', $d ), 'yes' => __( 'Allow', $d ) ) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $m( 'backorders' ) ?: 'no', $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label><br><small>' . esc_html__( 'Needs “Manage stock” on. Stock may then go below zero.', $d ) . '</small></p>';
		foreach ( array(
			'low_stock' => __( 'Low-stock warning at', $d ),
			'min_qty'   => __( 'Minimum per order', $d ),
			'max_qty'   => __( 'Maximum per order', $d ),
			'qty_step'  => __( 'Sell in multiples of', $d ),
		) as $k => $l ) {
			echo '<p><label>' . esc_html( $l ) . '<br><input type="number" min="0" step="1" class="widefat" name="sidccofo_' . esc_attr( $k ) . '" value="' . esc_attr( $m( $k ) ) . '"></label></p>';
		}
		echo '<p><label>' . esc_html__( 'Pre-order until', $d ) . '<br><input type="date" class="widefat" name="sidccofo_preorder_date" value="' . esc_attr( $m( 'preorder_date' ) ) . '"></label><br><small>' . esc_html__( 'Until this date the button says Pre-order and the page says when it ships.', $d ) . '</small></p>';
		$waiting = (array) get_post_meta( $post->ID, self::WAITING, true );
		if ( $waiting ) {
			/* translators: %d: number of people. */
			echo '<p>' . esc_html( sprintf( _n( '%d person is waiting for this product.', '%d people are waiting for this product.', count( $waiting ), $d ), count( $waiting ) ) ) . '</p>';
		}
	}

	/*
	------------------------------------------------------------------
	 * Storefront
	 * ----------------------------------------------------------------
	 */

	/**
	 * Quantity rules of a product.
	 *
	 * @param Product $product Product.
	 * @return array [ min, max, step ].
	 */
	protected function limits( $product ) {
		$id = $product->get_id();
		return array(
			'min'  => max( 1, (int) get_post_meta( $id, '_sidccofo_min_qty', true ) ),
			'max'  => (int) get_post_meta( $id, '_sidccofo_max_qty', true ),
			'step' => max( 1, (int) get_post_meta( $id, '_sidccofo_qty_step', true ) ),
		);
	}

	/**
	 * Quantity box limits.
	 *
	 * @param array   $rules   Rules.
	 * @param Product $product Product.
	 * @return array
	 */
	public function rules( $rules, $product ) {
		$l = $this->limits( $product );
		if ( $l['max'] > 0 ) {
			$rules['max'] = $rules['max'] > 0 ? min( $rules['max'], $l['max'] ) : $l['max'];
		}
		$rules['min']  = max( $rules['min'], $l['min'] );
		$rules['step'] = $l['step'];
		return $rules;
	}

	/**
	 * Enforce the limits in the cart.
	 *
	 * @param true|\WP_Error $error    Result so far.
	 * @param Product|null   $product  Product.
	 * @param int            $quantity Units wanted.
	 * @return true|\WP_Error
	 */
	public function validate( $error, $product, $quantity ) {
		if ( is_wp_error( $error ) || ! $product instanceof Product || $quantity < 1 ) {
			return $error;
		}
		$l    = $this->limits( $product );
		$name = wp_strip_all_tags( $product->get_name() );
		if ( $quantity < $l['min'] ) {
			/* translators: 1: product, 2: minimum. */
			return new \WP_Error( 'sidccofo_min_qty', sprintf( __( 'The minimum order of “%1$s” is %2$d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $name, $l['min'] ) );
		}
		if ( $l['max'] > 0 && $quantity > $l['max'] ) {
			/* translators: 1: product, 2: maximum. */
			return new \WP_Error( 'sidccofo_max_qty', sprintf( __( 'You can order at most %2$d of “%1$s”.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $name, $l['max'] ) );
		}
		if ( $l['step'] > 1 && 0 !== $quantity % $l['step'] ) {
			/* translators: 1: product, 2: multiple. */
			return new \WP_Error( 'sidccofo_qty_step', sprintf( __( '“%1$s” is sold in multiples of %2$d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $name, $l['step'] ) );
		}
		return $error;
	}

	/**
	 * Pre-order date in the future?
	 *
	 * @param Product $product Product.
	 * @return string Y-m-d or ''.
	 */
	public static function preorder_date( $product ) {
		$date = (string) get_post_meta( $product->get_id(), '_sidccofo_preorder_date', true );
		return '' !== $date && $date > gmdate( 'Y-m-d', current_time( 'timestamp' ) ) ? $date : ''; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Compared with a date typed in site time.
	}

	/**
	 * Button text.
	 *
	 * @param string  $text    Text.
	 * @param Product $product Product.
	 * @return string
	 */
	public function button( $text, $product ) {
		if ( ! $product instanceof Product || ! $product->is_in_stock() ) {
			return $text;
		}
		if ( '' !== self::preorder_date( $product ) ) {
			return __( 'Pre-order', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( $product->allows_backorders() && $product->get_stock_quantity() <= 0 ) {
			return __( 'Back-order', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		return $text;
	}

	/**
	 * "Ships on…" under the price.
	 *
	 * @param string  $html    Price HTML.
	 * @param Product $product Product.
	 * @return string
	 */
	public function price_note( $html, $product ) {
		if ( ! $product instanceof Product ) {
			return $html;
		}
		$date = self::preorder_date( $product );
		if ( '' !== $date ) {
			/* translators: %s: date. */
			$html .= ' <small class="sc-preorder">' . esc_html( sprintf( __( 'Pre-order: ships %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_date( get_option( 'date_format' ), strtotime( $date . ' 12:00:00' ) ) ) ) . '</small>';
		}
		return $html;
	}

	/**
	 * Stock label on backorder.
	 *
	 * @param string  $label   Label.
	 * @param string  $state   State.
	 * @param Product $product Product.
	 * @return string
	 */
	public function label( $label, $state, $product ) {
		unset( $state );
		if ( $product instanceof Product && $product->allows_backorders() && (int) $product->get_stock_quantity() <= 0 ) {
			return 'notify' === get_post_meta( $product->get_id(), '_sidccofo_backorders', true ) ? __( 'Available on backorder: this will ship when we have it again', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Available on backorder', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		return $label;
	}

	/**
	 * Keep the stock state "in" on backorder.
	 *
	 * @param string  $state   State.
	 * @param Product $product Product.
	 * @return string
	 */
	public function state( $state, $product ) {
		return $product instanceof Product && $product->allows_backorders() && 'out' === $state ? 'low' : $state;
	}

	/**
	 * Add the notify-me form under the stock label of a sold-out product.
	 *
	 * @param string $html     Unit HTML.
	 * @param array  $settings Settings.
	 * @return string
	 */
	public function notify_form( $html, $settings ) {
		$product = resolve_product( (array) $settings );
		if ( ! $product ) {
			return $html;
		}
		return preg_replace( '#</div>\s*$#', $this->form_html( $product ) . '</div>', $html, 1 );
	}

	/**
	 * The form.
	 *
	 * @param Product $product Product.
	 * @return string
	 */
	protected function form_html( $product ) {
		if ( ! setting( 'stock_notify_me', 1 ) || $product->is_in_stock() || ! $product->is_purchasable() ) {
			return '';
		}
		Extras::enqueue();
		$id = 'sc-nm-' . $product->get_id();
		return '<form class="sc-notify" data-sc-notify data-product="' . esc_attr( $product->get_id() ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Email me when it is back in stock', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><div class="sc-notify__row"><input type="email" id="' . esc_attr( $id ) . '" name="email" required autocomplete="email"><input type="text" name="website" tabindex="-1" autocomplete="off" class="sc-visually-hidden" aria-hidden="true"><button class="sc-btn" type="submit">' . esc_html__( 'Notify me', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></div><p class="sc-notify__msg" role="status" aria-live="polite"></p></form>';
	}

	/**
	 * [sidccofo_notify_me product_id="42"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'product_id' => 0 ), $atts, 'sidccofo_notify_me' );
		$product = Product::get( (int) $atts['product_id'] ? (int) $atts['product_id'] : get_the_ID() );
		return $product ? $this->form_html( $product ) : '';
	}

	/*
	------------------------------------------------------------------
	 * Sign-up route
	 * ----------------------------------------------------------------
	 */

	/**
	 * Register the route.
	 */
	public function routes() {
		register_rest_route(
			'sidcraft-commerce/v1',
			'/stock/notify',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'subscribe' ),
			)
		);
	}

	/**
	 * Add an email to a product's waiting list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function subscribe( \WP_REST_Request $request ) {
		$product = Product::get( absint( $request['product_id'] ) );
		$email   = sanitize_email( (string) $request['email'] );
		if ( ! $product || ! is_email( $email ) ) {
			return new \WP_Error( 'sidccofo_notify', __( 'Enter a valid email address.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		if ( '' !== (string) $request['website'] ) {
			return rest_ensure_response( array( 'message' => __( 'Thanks! We’ll email you.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) ); // Honeypot: say nothing.
		}
		if ( ! rate_limit( 'notify', 10, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'sidccofo_rate', __( 'Too many requests. Please try again later.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
		}
		$list = array_values( array_filter( (array) get_post_meta( $product->get_id(), self::WAITING, true ), 'is_array' ) );
		$have = wp_list_pluck( $list, 'email' );
		if ( ! in_array( strtolower( $email ), array_map( 'strtolower', $have ), true ) && count( $list ) < 500 ) {
			$list[] = array(
				'email' => $email,
				'time'  => time(),
			);
			update_post_meta( $product->get_id(), self::WAITING, $list );
		}
		return rest_ensure_response( array( 'message' => __( 'Thanks! We’ll email you once.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
	}

	/**
	 * Note pre-orders and backorders on the order.
	 *
	 * @param Order $order Order.
	 */
	public function stamp( $order ) {
		$notes = array();
		foreach ( $order->get_items() as $item ) {
			$p = Product::get( (int) $item['product_id'] );
			if ( ! $p ) {
				continue;
			}
			$date = self::preorder_date( $p );
			if ( '' !== $date ) {
				/* translators: 1: product, 2: date. */
				$notes[] = sprintf( __( 'Pre-order: %1$s ships %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_strip_all_tags( $p->get_name() ), $date );
				update_post_meta( $order->get_id(), '_sidccofo_preorder', $date );
			}
			if ( $p->allows_backorders() && (int) $p->get_stock_quantity() < 0 ) {
				/* translators: %s: product. */
				$notes[] = sprintf( __( 'On backorder: %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_strip_all_tags( $p->get_name() ) );
			}
		}
		foreach ( array_unique( $notes ) as $n ) {
			$order->add_note( $n );
		}
	}
}
