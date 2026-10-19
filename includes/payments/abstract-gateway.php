<?php
/**
 * Payment gateway framework.
 *
 * Every payment method extends this class: the built-in PayPal Standard and
 * the gateways Sidcraft Builder Pro adds (PayPal Commerce, Stripe, Razorpay,
 * PayU, Mercado Pago, Square, Mollie, Authorize.net). It plays the role
 * WC_Payment_Gateway plays in WooCommerce: developers extend it to connect
 * a local bank or a proprietary payment system.
 *
 * Minimum: get_id(), get_default_title(), process_payment(). Everything
 * else has a working default:
 *
 *   - Settings: return fields from get_settings_fields(); they appear on
 *     Store → Settings → Payments and are read with get_option(). An
 *     "Enable" checkbox and a "Title" field are added for you.
 *   - URLs: return_url() and webhook_url() point at generic REST routes
 *     (/wp-json/sidcraft-commerce/v1/gateway/{id}/return and …/webhook) that
 *     call handle_return() and handle_webhook() on your gateway.
 *   - HTTP: remote_json() for JSON APIs, log() for order notes.
 *   - Paying: call $order->payment_complete( $transaction_id ) once the
 *     provider confirms payment (it is idempotent), or
 *     $order->set_status( 'sidccofo-on-hold' ) for delayed methods.
 *
 * Register it:
 *
 *     add_filter( 'sidcraft_commerce_gateways', function ( $gateways ) {
 *         $gateways[] = new My_Bank_Gateway();
 *         return $gateways;
 *     } );
 *
 * See docs/example-gateway.php for a complete, commented example.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Payments;

use SidcraftCommerce\Data\Order;
use function SidcraftCommerce\currency;
use function SidcraftCommerce\page_url;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Gateway
 */
abstract class Gateway {

	/*
	------------------------------------------------------------------
	 * Required
	 * ----------------------------------------------------------------
	 */

	/**
	 * Unique id (lower case, a–z, 0–9 and _), e.g. 'paypal'. Used in setting
	 * keys ("{id}_enabled"), order meta and the gateway REST routes.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Start payment for a freshly created order (status sidccofo-pending).
	 *
	 * Return one of:
	 *   [ 'redirect' => url ]                     Send the customer to a payment page (or the order-received page).
	 *   [ 'post' => [ 'url' => …, 'fields' => [] ] ] Auto-submit a POST form to the provider (hosted checkouts that need POST).
	 *   Anything else your own on-page button script expects (see has_inline_button()).
	 *   \WP_Error                                Show the message at checkout.
	 *
	 * @param Order $order Order.
	 * @return array|\WP_Error
	 */
	abstract public function process_payment( Order $order );

	/*
	------------------------------------------------------------------
	 * Display
	 * ----------------------------------------------------------------
	 */

	/**
	 * Title used when the admin leaves "Title at checkout" empty.
	 *
	 * @return string
	 */
	public function get_default_title() {
		return $this->get_id();
	}

	/**
	 * Title shown at checkout and in the admin.
	 *
	 * @return string
	 */
	public function get_title() {
		$title = trim( (string) $this->get_option( 'title', '' ) );
		return '' !== $title ? $title : $this->get_default_title();
	}

	/**
	 * Optional text under the option at checkout.
	 *
	 * @return string
	 */
	public function get_description() {
		return '';
	}

	/**
	 * Extra HTML under the option at checkout (shown while it is selected).
	 * Must be escaped.
	 *
	 * @return string
	 */
	public function payment_fields() {
		return '';
	}

	/**
	 * True when the gateway pays with its own on-page button instead of the
	 * "Place order" button (the checkout hides Place order while selected).
	 * Your script can use window.sidccofoCheckout.collect( form ) to read the
	 * form and POST it to /checkout itself.
	 *
	 * @return bool
	 */
	public function has_inline_button() {
		return false;
	}

	/**
	 * Script/style handles to enqueue on the checkout page when available.
	 *
	 * @return array{styles: string[], scripts: string[]}
	 */
	public function get_checkout_assets() {
		return array(
			'styles'  => array(),
			'scripts' => array(),
		);
	}

	/*
	------------------------------------------------------------------
	 * Availability and settings
	 * ----------------------------------------------------------------
	 */

	/**
	 * Ready to take payments: enabled, configured and the store currency is
	 * supported. Override has_credentials() rather than this when you can.
	 *
	 * @return bool
	 */
	public function is_available() {
		$currencies = $this->get_supported_currencies();
		$ok         = (bool) $this->get_option( 'enabled', 0 )
			&& $this->has_credentials()
			&& ( ! $currencies || in_array( currency(), $currencies, true ) );

		/**
		 * Filter whether a gateway is offered at checkout.
		 *
		 * @param bool    $ok      Available.
		 * @param Gateway $gateway Gateway.
		 */
		return (bool) apply_filters( 'sidcraft_commerce_gateway_available', $ok, $this );
	}

	/**
	 * Optional features: 'subscriptions' = this gateway can save the payment
	 * details at checkout and charge subscription renewals without the
	 * customer (see charge_renewal() and Subscriptions::save_token()).
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	public function supports( $feature ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Overridden by gateways that support features.
		return false;
	}

	/**
	 * Charge a subscription renewal with saved payment details. Call
	 * $order->payment_complete() when the money is taken (or leave the order
	 * pending while the provider is still processing it).
	 *
	 * @param Order $order Renewal order (pending).
	 * @param array $token What this gateway saved with Subscriptions::save_token().
	 * @return true|\WP_Error An error makes the store email the customer a link to pay.
	 */
	public function charge_renewal( Order $order, array $token ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Overridden by gateways that support subscriptions.
		return new \WP_Error( 'sidccofo_not_supported', __( 'This payment method cannot charge renewals automatically.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
	}

	/**
	 * API keys are filled in.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return true;
	}

	/**
	 * ISO currency codes this gateway accepts. Empty = any.
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return array();
	}

	/**
	 * Settings fields for Store → Settings → Payments, keyed without the
	 * gateway prefix: [ 'api_key' => [ label, type, help ] ]. Types: text,
	 * password, checkbox, textarea, or an options array for a select. Stored
	 * as "{id}_{key}" in the store settings.
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		return array();
	}

	/**
	 * Intro text above this gateway's settings.
	 *
	 * @return string
	 */
	public function get_settings_intro() {
		return '';
	}

	/**
	 * Heading of this gateway's settings section.
	 *
	 * @return string
	 */
	public function get_settings_title() {
		return $this->get_default_title();
	}

	/**
	 * Section for the settings page: enable + title + the gateway's own fields.
	 *
	 * @return array
	 */
	public function settings_section() {
		$fields = array(
			/* translators: %s: payment method name. */
			$this->get_id() . '_enabled' => array( sprintf( __( 'Enable %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_default_title() ), 'checkbox' ),
			$this->get_id() . '_title'   => array( __( 'Title at checkout', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Leave empty for the default.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
		);
		foreach ( $this->get_settings_fields() as $key => $field ) {
			$fields[ $this->get_id() . '_' . $key ] = $field;
		}
		return array(
			'title'  => $this->get_settings_title(),
			'intro'  => $this->get_settings_intro(),
			'tab'    => 'payments',
			'fields' => $fields,
		);
	}

	/**
	 * Read one of this gateway's settings.
	 *
	 * @param string $key      Key without the gateway prefix.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public function get_option( $key, $fallback = '' ) {
		return setting( $this->get_id() . '_' . $key, $fallback );
	}

	/*
	------------------------------------------------------------------
	 * Returning customers and webhooks
	 * ----------------------------------------------------------------
	 */

	/**
	 * Called on the order-received page before it renders. Gateways can
	 * confirm a payment here as a fallback when a webhook is delayed.
	 *
	 * @param Order $order Order.
	 */
	public function on_return( Order $order ) {}

	/**
	 * URL the provider sends the customer back to. It calls handle_return(),
	 * which by default forwards to the order-received page.
	 *
	 * @param Order $order Order.
	 * @return string
	 */
	public function return_url( Order $order ) {
		return add_query_arg(
			array(
				'order' => $order->get_id(),
				'key'   => $order->get_key(),
			),
			rest_url( \SidcraftCommerce\Rest::NS . '/gateway/' . $this->get_id() . '/return' )
		);
	}

	/**
	 * URL for provider notifications (webhooks / IPN). Calls handle_webhook().
	 *
	 * @return string
	 */
	public function webhook_url() {
		return rest_url( \SidcraftCommerce\Rest::NS . '/gateway/' . $this->get_id() . '/webhook' );
	}

	/**
	 * Checkout URL with the "payment cancelled" notice.
	 *
	 * @return string
	 */
	public function cancel_url() {
		return add_query_arg( 'sidccofo_cancelled', 1, page_url( 'checkout' ) );
	}

	/**
	 * Where a customer who cancels on the provider's page goes back to: the
	 * checkout, or a subscription renewal's own page (where it can be paid).
	 *
	 * @param Order $order Order being paid.
	 * @return string
	 */
	public function cancel_url_for( Order $order ) {
		return \SidcraftCommerce\Subscriptions::renewal_subscription( $order ) ? $order->get_received_url() : $this->cancel_url();
	}

	/**
	 * GET/POST …/gateway/{id}/return. Default: verify the order key, then go
	 * to the order-received page (where on_return() runs).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_return( \WP_REST_Request $request ) {
		$order = Order::get( absint( $request->get_param( 'order' ) ) );
		if ( ! $order || ! $order->key_is_valid( (string) $request->get_param( 'key' ) ) ) {
			return self::redirect( page_url( 'checkout' ) );
		}
		return self::redirect( $order->get_received_url() );
	}

	/**
	 * POST …/gateway/{id}/webhook. Verify the request yourself (signature or
	 * by re-reading the payment from the provider's API) before trusting it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_webhook( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Overridden by gateways.
		return new \WP_REST_Response( array( 'error' => 'not supported' ), 404 );
	}

	/*
	------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------
	 */

	/**
	 * A 302 response for the REST return routes.
	 *
	 * @param string $url Destination.
	 * @return \WP_REST_Response
	 */
	public static function redirect( $url ) {
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', esc_url_raw( $url ) );
		return $response;
	}

	/**
	 * Call a JSON (or form-encoded) API.
	 *
	 * @param string $method  GET|POST|PUT|DELETE.
	 * @param string $url     URL.
	 * @param array  $args    headers, body (array → JSON unless 'form' => true), timeout.
	 * @return array|\WP_Error Decoded body; errors carry 'status' and 'body'.
	 */
	protected function remote_json( $method, $url, array $args = array() ) {
		$headers = array_merge( array( 'Accept' => 'application/json' ), (array) ( $args['headers'] ?? array() ) );
		$body    = $args['body'] ?? null;
		if ( is_array( $body ) ) {
			if ( ! empty( $args['form'] ) ) {
				$body                    = http_build_query( $body, '', '&' );
				$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			} else {
				$body                    = wp_json_encode( $body );
				$headers['Content-Type'] = 'application/json';
			}
		}
		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => (int) ( $args['timeout'] ?? 30 ),
				'headers' => $headers,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			return new \WP_Error(
				'sidccofo_gateway_http',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Payment provider error (HTTP %d).', 'sidcraft-commerce-for-sidcraft-page-builder' ), $code ),
				array(
					'status' => $code,
					'body'   => is_array( $data ) ? $data : wp_remote_retrieve_body( $response ),
				)
			);
		}
		return $data;
	}

	/**
	 * Add a note to the order, prefixed with the gateway title.
	 *
	 * @param Order  $order   Order.
	 * @param string $message Message.
	 */
	protected function log( Order $order, $message ) {
		$order->add_note( $this->get_default_title() . ': ' . $message );
	}

	/**
	 * Readable message from a WP_Error returned by remote_json().
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	protected static function error_detail( \WP_Error $error ) {
		$body = $error->get_error_data()['body'] ?? null;
		if ( is_array( $body ) ) {
			foreach ( array( array( 'error', 'message' ), array( 'error', 'description' ), array( 'message' ), array( 'error_description' ) ) as $path ) {
				$value = $body;
				foreach ( $path as $key ) {
					$value = is_array( $value ) && isset( $value[ $key ] ) ? $value[ $key ] : null;
				}
				if ( is_string( $value ) && '' !== $value ) {
					return $value;
				}
			}
		}
		return $error->get_error_message();
	}
}
