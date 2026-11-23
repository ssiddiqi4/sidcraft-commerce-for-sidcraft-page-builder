<?php
/**
 * Pay what you want, donations at checkout and "Request a quote".
 *
 * - Pay what you want: tick the box in the product's Price options (Store
 *   options box), set the least you accept and a suggested amount. The buyer
 *   types the price on the product page; the cart uses it.
 * - Donation box at checkout: preset amounts and a free amount, added to the
 *   order as a "Donation" line.
 * - Request a quote: the product shows a form instead of a price and button;
 *   requests arrive by email and under Store → Quote requests.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\format_price;
use function SidcraftCommerce\rate_limit;
use function SidcraftCommerce\round_amount;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Donations
 */
class Donations extends Tool {

	const QUOTE = 'sidccofo_quote';

	/** {@inheritDoc} */
	public function hooks() {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		$this->settings(
			'donations',
			__( 'Donations, pay what you want and quotes', $d ),
			array(
				'don_on'      => array( __( 'Donation box at checkout', $d ), 'checkbox' ),
				'don_label'   => array( __( 'Donation text', $d ), 'text', __( 'For example: Add a donation to our charity.', $d ) ),
				'don_presets' => array( __( 'Preset amounts', $d ), 'text', __( 'Separated by commas, in the store currency. For example 5, 10, 25.', $d ) ),
				'quote_to'    => array( __( 'Send quote requests to', $d ), 'email', __( 'Empty = the site admin email.', $d ) ),
			)
		);
		add_action( 'init', array( $this, 'register_quote_type' ) );
		add_filter( 'sidcraft_commerce_product_price', array( $this, 'price' ), 20, 3 );
		add_filter( 'sidcraft_commerce_is_purchasable', array( $this, 'quote_not_purchasable' ), 10, 2 );
		add_filter( 'sidcraft_commerce_extra_keys', array( $this, 'keys' ) );
		add_filter( 'sidcraft_commerce_cart_fees', array( $this, 'fees' ), 10, 2 );
		add_action( 'sidcraft_commerce_checkout_after_fields', array( $this, 'donation_box' ), 40 );
		add_action( 'sidcraft_commerce_before_add_to_cart_button', array( $this, 'pwyw_field' ), 10, 1 );
		add_filter( 'sidcraft_commerce_render_sidccofo-add-to-cart', array( $this, 'quote_form' ), 5, 2 );
		add_action( 'add_meta_boxes_' . Post_Types::PRODUCT, array( $this, 'box' ) );
		add_action( 'save_post_' . Post_Types::PRODUCT, array( $this, 'save' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Quote requests list.
	 */
	public function register_quote_type() {
		register_post_type(
			self::QUOTE,
			array(
				'labels'          => array(
					'name'          => __( 'Quote requests', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name' => __( 'Quote request', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . Post_Types::PRODUCT,
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor' ),
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * Pay what you want
	 * ----------------------------------------------------------------
	 */

	/**
	 * Is this a pay-what-you-want product?
	 *
	 * @param int $id Product.
	 * @return bool
	 */
	protected function is_pwyw( $id ) {
		return '1' === (string) get_post_meta( $id, '_sidccofo_pwyw', true );
	}

	/**
	 * The buyer's price in the cart.
	 *
	 * @param float   $price   Price (store currency).
	 * @param Product $product Product.
	 * @param int     $qty     Quantity.
	 * @return float
	 */
	public function price( $price, $product, $qty ) {
		unset( $qty );
		if ( ! $product instanceof Product || ! Cart::$building || ! $this->is_pwyw( $product->get_id() ) ) {
			return $price;
		}
		$chosen = (array) Cart::instance()->extra( 'pwyw', array() );
		$min    = max( 0.0, (float) get_post_meta( $product->get_id(), '_sidccofo_pwyw_min', true ) );
		if ( isset( $chosen[ $product->get_id() ] ) ) {
			return round_amount( max( $min, (float) $chosen[ $product->get_id() ] ) );
		}
		return round_amount( max( $min, $price ) );
	}

	/**
	 * Cart-extras keys.
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public function keys( $keys ) {
		$self = $this;
		// "12:7.5" = product 12 at 7.50 in the shopper's currency.
		$keys['pwyw']     = static function ( $value, $cart ) use ( $self ) {
			$all = (array) $cart->extra( 'pwyw', array() );
			if ( ! preg_match( '/^(\d+):([0-9]+(?:[.,][0-9]+)?)$/', trim( (string) $value ), $m ) ) {
				return $all;
			}
			$id = (int) $m[1];
			if ( ! $self->is_pwyw( $id ) ) {
				return $all;
			}
			$amount = \SidcraftCommerce\Currencies::to_base( (float) str_replace( ',', '.', $m[2] ) );
			$min    = max( 0.0, (float) get_post_meta( $id, '_sidccofo_pwyw_min', true ) );
			if ( $amount + 0.0001 < $min ) {
				return new \WP_Error( 'sidccofo_pwyw_min', sprintf( /* translators: %s: minimum price. */ __( 'The lowest price is %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( \SidcraftCommerce\Currencies::to_active( $min ) ) ), array( 'status' => 400 ) );
			}
			$all[ $id ] = round( min( $amount, 1000000 ), 2 );
			return $all;
		};
		$keys['donation'] = static function ( $value ) {
			$v = (float) str_replace( ',', '.', (string) $value );
			return $v > 0 ? (string) round( min( $v, 100000 ), 2 ) : '';
		};
		return $keys;
	}

	/**
	 * Price box on the product page.
	 *
	 * @param Product $product Product.
	 */
	public function pwyw_field( $product ) {
		if ( ! $product instanceof Product || ! $this->is_pwyw( $product->get_id() ) ) {
			return;
		}
		Extras::enqueue();
		$min     = max( 0.0, (float) get_post_meta( $product->get_id(), '_sidccofo_pwyw_min', true ) );
		$suggest = max( $min, (float) get_post_meta( $product->get_id(), '_sidccofo_pwyw_suggest', true ) );
		$cur     = \SidcraftCommerce\Currencies::to_active( $suggest );
		echo '<p class="sc-pwyw"><label>' . esc_html__( 'Your price', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' (' . esc_html( \SidcraftCommerce\currency_symbol() ) . ')<br><input type="number" step="0.01" min="' . esc_attr( (string) \SidcraftCommerce\Currencies::to_active( $min ) ) . '" value="' . esc_attr( number_format( $cur, 2, '.', '' ) ) . '" data-sc-pwyw="' . esc_attr( $product->get_id() ) . '"></label>' . ( $min > 0 ? '<small>' . esc_html( sprintf( /* translators: %s: minimum. */ __( 'Minimum %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( \SidcraftCommerce\Currencies::to_active( $min ) ) ) ) . '</small>' : '' ) . '</p>';
	}

	/*
	------------------------------------------------------------------
	 * Donation
	 * ----------------------------------------------------------------
	 */

	/**
	 * Donation as a cart line.
	 *
	 * @param array $fees Fees.
	 * @param Cart  $cart Cart.
	 * @return array
	 */
	public function fees( $fees, $cart ) {
		$amount = (float) $cart->extra( 'donation', 0 );
		if ( setting( 'don_on', 0 ) && $amount > 0 && ! $cart->is_empty() ) {
			$fees[] = array( 'id' => 'donation', 'label' => __( 'Donation', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'amount' => $amount );
		}
		return $fees;
	}

	/**
	 * Donation choices at checkout.
	 *
	 * @param Cart $cart Cart.
	 */
	public function donation_box( $cart ) {
		if ( ! setting( 'don_on', 0 ) ) {
			return;
		}
		Extras::enqueue();
		$cur    = (float) $cart->extra( 'donation', 0 );
		$label  = '' !== trim( (string) setting( 'don_label', '' ) ) ? (string) setting( 'don_label', '' ) : __( 'Add a donation', 'sidcraft-commerce-for-sidcraft-page-builder' );
		$preset = array_filter( array_map( 'floatval', preg_split( '/[\s,]+/', (string) setting( 'don_presets', '5, 10, 25' ) ) ) );
		echo '<fieldset class="sc-choice"><legend>' . esc_html( $label ) . '</legend><div class="sc-donation">';
		echo '<label class="sc-choice__opt"><input type="radio" name="donation_preset" value="" data-sc-extra="donation" ' . checked( $cur <= 0, true, false ) . '> ' . esc_html__( 'No thanks', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label>';
		foreach ( $preset as $p ) {
			$shown = \SidcraftCommerce\Currencies::to_active( $p );
			echo '<label class="sc-choice__opt"><input type="radio" name="donation_preset" value="' . esc_attr( (string) $shown ) . '" data-sc-extra="donation" ' . checked( abs( $cur - $shown ) < 0.001, true, false ) . '> ' . esc_html( format_price( $shown ) ) . '</label>';
		}
		echo '</div></fieldset>';
	}

	/*
	------------------------------------------------------------------
	 * Product screen
	 * ----------------------------------------------------------------
	 */

	/**
	 * Box.
	 */
	public function box() {
		add_meta_box( 'sidccofo-price-options', __( 'Price options', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_box' ), Post_Types::PRODUCT, 'side' );
	}

	/**
	 * Box contents.
	 *
	 * @param \WP_Post $post Product.
	 */
	public function render_box( $post ) {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		wp_nonce_field( 'sidccofo_price_opts', '_sidccofo_po' );
		$m = static function ( $k ) use ( $post ) {
			return (string) get_post_meta( $post->ID, '_sidccofo_' . $k, true );
		};
		echo '<p><label><input type="checkbox" name="sidccofo_pwyw" value="1" ' . checked( '1' === $m( 'pwyw' ), true, false ) . '> ' . esc_html__( 'Pay what you want', $d ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Lowest price', $d ) . '<br><input type="number" step="0.01" min="0" class="widefat" name="sidccofo_pwyw_min" value="' . esc_attr( $m( 'pwyw_min' ) ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Suggested price', $d ) . '<br><input type="number" step="0.01" min="0" class="widefat" name="sidccofo_pwyw_suggest" value="' . esc_attr( $m( 'pwyw_suggest' ) ) . '"></label></p><hr>';
		echo '<p><label><input type="checkbox" name="sidccofo_quote_only" value="1" ' . checked( '1' === $m( 'quote_only' ), true, false ) . '> ' . esc_html__( 'Request a quote instead of buying', $d ) . '</label></p>';
	}

	/**
	 * Save.
	 *
	 * @param int $post_id Product.
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST['_sidccofo_po'] ) || ! current_user_can( 'edit_post', $post_id ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_po'] ) ), 'sidccofo_price_opts' ) ) {
			return;
		}
		update_post_meta( $post_id, '_sidccofo_pwyw', empty( $_POST['sidccofo_pwyw'] ) ? '' : '1' );
		update_post_meta( $post_id, '_sidccofo_quote_only', empty( $_POST['sidccofo_quote_only'] ) ? '' : '1' );
		foreach ( array( 'pwyw_min', 'pwyw_suggest' ) as $k ) {
			$v = isset( $_POST[ 'sidccofo_' . $k ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ 'sidccofo_' . $k ] ) ) ) : '';
			update_post_meta( $post_id, '_sidccofo_' . $k, is_numeric( $v ) ? (string) max( 0, (float) $v ) : '' );
		}
		Product::forget( $post_id );
	}

	/*
	------------------------------------------------------------------
	 * Quotes
	 * ----------------------------------------------------------------
	 */

	/**
	 * A quote-only product can't go in the cart.
	 *
	 * @param bool    $ok      Purchasable.
	 * @param Product $product Product.
	 * @return bool
	 */
	public function quote_not_purchasable( $ok, $product ) {
		return $ok && $product instanceof Product && '1' === (string) get_post_meta( $product->get_id(), '_sidccofo_quote_only', true ) ? false : $ok;
	}

	/**
	 * The form in place of the button.
	 *
	 * @param string $html     Unit HTML.
	 * @param array  $settings Settings.
	 * @return string
	 */
	public function quote_form( $html, $settings ) {
		$p = \SidcraftCommerce\resolve_product( (array) $settings );
		if ( ! $p || '1' !== (string) get_post_meta( $p->get_id(), '_sidccofo_quote_only', true ) ) {
			return $html;
		}
		Extras::enqueue();
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		return '<div class="sc-unit sc-unit--sidccofo-add-to-cart"><form class="sc-quote" data-sc-quote data-product="' . esc_attr( $p->get_id() ) . '"><h3>' . esc_html__( 'Request a quote', $d ) . '</h3><p><label>' . esc_html__( 'Your name', $d ) . '<br><input name="name" required autocomplete="name"></label></p><p><label>' . esc_html__( 'Email', $d ) . '<br><input type="email" name="email" required autocomplete="email"></label></p><p><label>' . esc_html__( 'Quantity or details', $d ) . '<br><textarea name="message" rows="3" required></textarea></label></p><input type="text" name="website" tabindex="-1" autocomplete="off" class="sc-visually-hidden" aria-hidden="true"><button class="sc-btn sc-btn--primary" type="submit">' . esc_html__( 'Send request', $d ) . '</button><p class="sc-quote__msg" role="status" aria-live="polite"></p></form></div>';
	}

	/**
	 * Routes.
	 */
	public function routes() {
		register_rest_route(
			'sidcraft-commerce/v1',
			'/quote',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'request_quote' ),
			)
		);
	}

	/**
	 * Receive a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function request_quote( \WP_REST_Request $request ) {
		$p     = Product::get( absint( $request['product_id'] ) );
		$name  = sanitize_text_field( (string) $request['name'] );
		$email = sanitize_email( (string) $request['email'] );
		$msg   = sanitize_textarea_field( (string) $request['message'] );
		if ( ! $p || '' === $name || ! is_email( $email ) || '' === $msg ) {
			return new \WP_Error( 'sidccofo_quote', __( 'Please fill in every field with a valid email address.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		if ( '' !== (string) $request['website'] ) {
			return rest_ensure_response( array( 'message' => __( 'Thank you! We will get back to you.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
		}
		if ( ! rate_limit( 'quote', 5, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'sidccofo_rate', __( 'Too many requests. Please try again later.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => self::QUOTE,
				'post_status'  => 'publish',
				/* translators: 1: product, 2: name. */
				'post_title'   => sprintf( __( '%1$s: %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_strip_all_tags( $p->get_name() ), $name ),
				'post_content' => $name . ' <' . $email . ">\n\n" . $msg,
			)
		);
		$to = is_email( (string) setting( 'quote_to', '' ) ) ? (string) setting( 'quote_to', '' ) : get_option( 'admin_email' );
		wp_mail( $to, sprintf( /* translators: %s: product. */ __( 'Quote request: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_strip_all_tags( $p->get_name() ) ), $name . ' <' . $email . ">\n\n" . $msg . "\n\n" . get_permalink( $p->get_id() ), array( 'Reply-To: ' . $email ) );
		unset( $id );
		return rest_ensure_response( array( 'message' => __( 'Thank you! We will get back to you.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
	}
}
