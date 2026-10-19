<?php
/**
 * Public REST endpoints under /wp-json/sidcraft-commerce/v1/.
 *
 *   GET  cart              fragments + hash (fills cached placeholders)
 *   POST cart/add          product_id, quantity, options (group id => choice id(s) or text)
 *   POST cart/update       product_id, quantity (0 removes), context=cart|checkout
 *   POST cart/coupon       code, context=cart|checkout (apply)
 *   POST cart/coupon/remove context=cart|checkout
 *   GET  quick-view/{id}   modal HTML
 *   POST reviews           product_id, rating, order_number, email, review, name
 *                          (verified against a paid order containing the product)
 *   POST checkout          fields, payment_method, _sidccofo_nonce
 *   GET|POST gateway/{id}/return   Customer back from a payment page → Gateway::handle_return()
 *   POST gateway/{id}/webhook      Provider notifications → Gateway::handle_webhook()
 *                                  (PayPal Standard IPN: gateway/paypal_standard/webhook)
 * Gateways verify these requests themselves (signatures, order keys, or by
 * re-reading the payment from the provider's API).
 *
 * The cart is keyed by our own sidccofo_session cookie, not by WordPress login, so
 * these work for guests without a REST nonce. Cart changes skip CSRF nonces
 * on purpose (cached pages would serve stale nonces; a cart only affects the
 * visitor's own session). Checkout, which creates an order, does verify one:
 * the checkout page is never cached, so its nonce is always fresh.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Checkout\Checkout;
use SidcraftCommerce\Data\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rest
 */
class Rest {

	const NS = 'sidcraft-commerce/v1';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'no_cache' ), 10, 3 );
	}

	/**
	 * Register routes.
	 */
	public function routes() {
		$this->gateway_routes();
		$public = '__return_true';
		$id_arg = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		);
		$qty    = array(
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => 'absint',
		);

		register_rest_route(
			self::NS,
			'/cart',
			array(
				'methods'             => 'GET',
				'permission_callback' => $public,
				'callback'            => array( $this, 'get_cart' ),
			)
		);
		register_rest_route(
			self::NS,
			'/cart/add',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public,
				'callback'            => array( $this, 'add' ),
				'args'                => array(
					'product_id' => $id_arg,
					'quantity'   => $qty,
					'options'    => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/cart/update',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public,
				'callback'            => array( $this, 'update' ),
				'args'                => array(
					'product_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'line'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'quantity'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'context'    => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', 'cart', 'checkout' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/cart/location',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public,
				'callback'            => array( $this, 'location' ),
				'args'                => array(
					'country' => array(
						'type'     => 'string',
						'required' => true,
					),
					'state'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'context' => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', 'cart', 'checkout' ),
					),
				),
			)
		);
		$context = array(
			'type'    => 'string',
			'default' => '',
			'enum'    => array( '', 'cart', 'checkout' ),
		);
		register_rest_route(
			self::NS,
			'/cart/coupon',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public,
				'callback'            => array( $this, 'apply_coupon' ),
				'args'                => array(
					'code'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context' => $context,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/cart/coupon/remove',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public,
				'callback'            => array( $this, 'remove_coupon' ),
				'args'                => array(
					'context' => $context,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/quick-view/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $public,
				'callback'            => array( $this, 'quick_view' ),
			)
		);
		register_rest_route(
			self::NS,
			'/reviews',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public, // Verified by order number + email inside Reviews::submit().
				'callback'            => array( $this, 'review' ),
			)
		);
		register_rest_route(
			self::NS,
			'/checkout',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public,
				'callback'            => array( $this, 'checkout' ),
			)
		);
	}

	/**
	 * Generic per-gateway return and webhook routes.
	 */
	protected function gateway_routes() {
		$public = '__return_true'; // Public by design: payment providers and returning customers call these; each gateway verifies the request.
		foreach ( array( 'return', 'webhook' ) as $action ) {
			register_rest_route(
				self::NS,
				'/gateway/(?P<gateway>[a-z0-9_]+)/' . $action,
				array(
					'methods'             => 'return' === $action ? array( 'GET', 'POST' ) : 'POST',
					'permission_callback' => $public,
					'callback'            => static function ( \WP_REST_Request $request ) use ( $action ) {
						$gateway = Checkout::gateway( sanitize_key( (string) $request['gateway'] ) );
						if ( ! $gateway ) {
							return new \WP_REST_Response( array( 'error' => 'unknown gateway' ), 404 );
						}
						return 'return' === $action ? $gateway->handle_return( $request ) : $gateway->handle_webhook( $request );
					},
				)
			);
		}
	}

	/**
	 * Never let a CDN or browser cache our responses.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public function no_cache( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . self::NS ) && $response instanceof \WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		}
		return $response;
	}

	/**
	 * Standard cart payload.
	 *
	 * @param string $context '' | cart | checkout (adds panel fragments).
	 * @return array
	 */
	protected function payload( $context = '' ) {
		$cart = Cart::instance();
		return array(
			'fragments' => Fragments::collect( $context ),
			'cart_hash' => $cart->get_hash(),
			'count'     => $cart->get_count(),
			'total'     => $cart->get_total(),
		);
	}

	/**
	 * GET cart.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_cart() {
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * POST cart/add.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add( \WP_REST_Request $request ) {
		$options = is_array( $request['options'] ) ? $request['options'] : array();
		$result  = $options
			? Cart::instance()->add_with_options( (int) $request['product_id'], max( 1, (int) $request['quantity'] ), $options )
			: Cart::instance()->add( (int) $request['product_id'], max( 1, (int) $request['quantity'] ) );
		if ( is_wp_error( $result ) ) {
			$data           = (array) $result->get_error_data();
			$data['status'] = 400;
			$result->add_data( $data );
			return $result;
		}
		$product = Product::get( (int) $request['product_id'] );
		$data    = $this->payload();

		/* translators: %s: product name. */
		$data['message'] = sprintf( __( '“%s” has been added to your cart.', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_strip_all_tags( $product->get_name() ) );

		return rest_ensure_response( apply_filters( 'sidcraft_commerce_add_to_cart_response', $data, $product ) );
	}

	/**
	 * POST cart/update.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$line = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $request['line'] );
		if ( 0 === strpos( $line, 'gc_' ) ) {
			// Gift card line.
			Cart::instance()->set_gift_quantity( substr( $line, 3 ), (int) $request['quantity'] );
			return rest_ensure_response( $this->payload( (string) $request['context'] ) );
		}
		if ( 0 === strpos( $line, 'op_' ) ) {
			// Line with item options.
			$result = Cart::instance()->set_option_quantity( substr( $line, 3 ), (int) $request['quantity'] );
			if ( is_wp_error( $result ) ) {
				$data            = $this->payload( (string) $request['context'] );
				$data['message'] = $result->get_error_message();
				return new \WP_REST_Response( $data, 400 );
			}
			return rest_ensure_response( $this->payload( (string) $request['context'] ) );
		}
		$result = Cart::instance()->set_quantity( (int) $request['product_id'], (int) $request['quantity'] );
		if ( is_wp_error( $result ) ) {
			$data            = $this->payload( (string) $request['context'] );
			$data['message'] = $result->get_error_message();
			return new \WP_REST_Response( $data, 400 );
		}
		return rest_ensure_response( $this->payload( (string) $request['context'] ) );
	}

	/**
	 * POST cart/location: the checkout's country/state changed; shipping and
	 * tax are recalculated and the panel comes back as a fragment.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function location( \WP_REST_Request $request ) {
		if ( ! Cart::instance()->set_location( (string) $request['country'], (string) $request['state'] ) ) {
			return new \WP_Error( 'sidccofo_bad_country', __( 'Choose a country from the list.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		$data             = $this->payload( (string) $request['context'] );
		$data['can_ship'] = Cart::instance()->can_ship();
		return rest_ensure_response( $data );
	}

	/**
	 * POST cart/coupon: apply a code. Errors still return fresh fragments.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function apply_coupon( \WP_REST_Request $request ) {
		$result = Cart::instance()->apply_coupon( (string) $request['code'] );
		$data   = $this->payload( (string) $request['context'] );
		if ( is_wp_error( $result ) ) {
			$data['message'] = $result->get_error_message();
			return new \WP_REST_Response( $data, 400 );
		}
		/* translators: 1: coupon code, 2: offer, e.g. "20% off". */
		$data['message'] = sprintf( __( 'Coupon “%1$s” applied: %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $result->get_code(), $result->get_offer_label() );
		return rest_ensure_response( $data );
	}

	/**
	 * POST cart/coupon/remove.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function remove_coupon( \WP_REST_Request $request ) {
		Cart::instance()->remove_coupon();
		$data            = $this->payload( (string) $request['context'] );
		$data['message'] = __( 'Coupon removed.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		return rest_ensure_response( $data );
	}

	/**
	 * GET quick-view/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function quick_view( \WP_REST_Request $request ) {
		$product = Product::get( (int) $request['id'] );
		if ( ! $product || ! $product->is_published() || post_password_required( $product->get_post() ) ) {
			return new \WP_Error( 'sidccofo_not_found', __( 'This product is not available.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'html' => Plugin::instance()->templates->render( 'quick-view.php', array( 'product' => $product ) ),
			)
		);
	}

	/**
	 * POST reviews: save a verified review and return the refreshed section.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function review( \WP_REST_Request $request ) {
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new \WP_Error( 'sidccofo_review_invalid', __( 'Something went wrong. Please try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) ); // Honeypot.
		}
		$result = Reviews::submit( (array) $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$unit           = Plugin::instance()->units->get( 'sidccofo-product-reviews' );
		$result['html'] = $unit ? $unit->render( array( 'product_id' => absint( $request->get_param( 'product_id' ) ) ) ) : '';
		return rest_ensure_response( $result );
	}

	/**
	 * POST checkout.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function checkout( \WP_REST_Request $request ) {
		$nonce = (string) $request->get_param( '_sidccofo_nonce' );
		if ( ! wp_verify_nonce( $nonce, Checkout::NONCE ) ) {
			return new \WP_Error( 'sidccofo_bad_nonce', __( 'Your session expired. Please refresh the page and try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 403 ) );
		}
		$result = Checkout::process( (array) $request->get_params() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
