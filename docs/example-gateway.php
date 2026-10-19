<?php
/**
 * EXAMPLE — a custom payment gateway for Sidcraft Commerce.
 *
 * This file is documentation: the plugin never loads it. Copy it into your
 * own plugin, rename the class and the id, and replace the "bank" calls with
 * your provider's API (use your own namespace and prefix). Load it after Sidcraft Commerce has booted, so the
 * Gateway base class exists:
 *
 *     add_action( 'sidcraft_commerce_loaded', function () {
 *         require __DIR__ . '/class-my-bank-gateway.php';
 *     } );
 *
 * It shows the common shape of a local-bank / hosted-page integration:
 *   1. process_payment(): ask the bank for a payment page, redirect there.
 *   2. The bank sends the customer back to return_url() → handle_return().
 *   3. The bank notifies webhook_url() → handle_webhook().
 *   4. Both confirm the payment with the bank's API (never trust the request
 *      alone), then call $order->payment_complete( $transaction_id ).
 *
 * Settings declared in get_settings_fields() appear automatically under
 * Store → Settings → Payments, with an "Enable" checkbox and a "Title".
 *
 * Also available to gateways:
 *   - payment_fields() / has_inline_button() / get_checkout_assets() for an
 *     on-page widget; your script can use window.sidccofoCheckout.collect(),
 *     .showErrors() and .postTo().
 *   - Return [ 'post' => [ 'url' => …, 'fields' => [ … ] ] ] from
 *     process_payment() when the bank needs a form POST instead of a redirect.
 *   - $order->set_status( 'sidccofo-on-hold', 'note' ) for payments that
 *     settle later (bank transfer, cash vouchers); stock is reserved.
 *   - Filters: sidcraft_commerce_gateways (add/replace gateways),
 *     sidcraft_commerce_available_gateways (hide per cart/country),
 *     sidcraft_commerce_gateway_available (per gateway).
 *   - Actions: sidcraft_commerce_payment_complete, sidcraft_commerce_order_status_changed.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Examples;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Payments\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Example: "Local Bank Pay".
 */
class Example_Local_Bank_Gateway extends Gateway {

	/**
	 * Unique id: settings are stored as localbank_enabled, localbank_api_key …
	 * and the routes are /wp-json/sidcraft-commerce/v1/gateway/localbank/return|webhook.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'localbank';
	}

	/**
	 * Name in the admin and the default checkout title.
	 *
	 * @return string
	 */
	public function get_default_title() {
		return 'Local Bank Pay';
	}

	/**
	 * Text under the option at checkout.
	 *
	 * @return string
	 */
	public function get_description() {
		return 'Pay from your bank account on Local Bank’s secure page.';
	}

	/**
	 * Only offer it in the bank's currency.
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return array( 'INR' );
	}

	/**
	 * Admin fields (stored as localbank_merchant_id, localbank_api_key …).
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		return array(
			'merchant_id' => array( 'Merchant ID', 'text' ),
			'api_key'     => array( 'API key', 'password' ),
			'sandbox'     => array( 'Sandbox mode', 'checkbox', 'Use the bank’s test environment.' ),
		);
	}

	/**
	 * Offered only once the keys are filled in.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== $this->get_option( 'merchant_id' ) && '' !== $this->get_option( 'api_key' );
	}

	/**
	 * Bank API base URL.
	 *
	 * @return string
	 */
	protected function api_base() {
		return $this->get_option( 'sandbox', 1 ) ? 'https://sandbox.bank.example/api/' : 'https://api.bank.example/';
	}

	/**
	 * 1. Create the bank payment and send the customer to it.
	 *
	 * @param Order $order Freshly created order (status sidccofo-pending).
	 * @return array|\WP_Error
	 */
	public function process_payment( Order $order ) {
		$payment = $this->remote_json(
			'POST',
			$this->api_base() . 'payments',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $this->get_option( 'api_key' ) ),
				'body'    => array(
					'merchant_id' => $this->get_option( 'merchant_id' ),
					'reference'   => (string) $order->get_id(),
					'amount'      => number_format( $order->get_total(), 2, '.', '' ),
					'currency'    => $order->get_currency(),
					'email'       => $order->get_email(),
					'return_url'  => $this->return_url( $order ),   // → handle_return().
					'notify_url'  => $this->webhook_url(),          // → handle_webhook().
					'cancel_url'  => $this->cancel_url(),
				),
			)
		);
		if ( is_wp_error( $payment ) || empty( $payment['payment_url'] ) ) {
			$this->log( $order, 'could not create payment: ' . ( is_wp_error( $payment ) ? self::error_detail( $payment ) : 'no URL' ) );
			return new \WP_Error( 'sidccofo_example_bank_failed', 'We could not reach the bank. Please try again.' );
		}
		update_post_meta( $order->get_id(), '_sidccofo_example_bank_payment', sanitize_text_field( $payment['id'] ) );
		return array( 'redirect' => esc_url_raw( $payment['payment_url'] ) );
	}

	/**
	 * 2. Customer is back. The base class checks the order key and sends the
	 * customer to the order-received page; confirm first.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_return( \WP_REST_Request $request ) {
		$order = Order::get( absint( $request->get_param( 'order' ) ) );
		if ( $order && $order->key_is_valid( (string) $request->get_param( 'key' ) ) ) {
			$this->confirm( $order );
		}
		return parent::handle_return( $request );
	}

	/**
	 * 3. Bank notification: it only tells us which payment changed; read the
	 * real status from the bank's API.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_webhook( \WP_REST_Request $request ) {
		$body  = json_decode( $request->get_body(), true );
		$order = Order::get( absint( $body['reference'] ?? 0 ) );
		if ( $order ) {
			$this->confirm( $order );
		}
		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Fallback when the order-received page loads before the webhook.
	 *
	 * @param Order $order Order.
	 */
	public function on_return( Order $order ) {
		if ( 'sidccofo-pending' === $order->get_status() ) {
			$this->confirm( $order );
		}
	}

	/**
	 * 4. Ask the bank; mark paid only when the amount and currency match.
	 *
	 * @param Order $order Order.
	 * @return bool
	 */
	protected function confirm( Order $order ) {
		$id = (string) get_post_meta( $order->get_id(), '_sidccofo_example_bank_payment', true );
		if ( '' === $id || $order->is_paid() ) {
			return $order->is_paid();
		}
		$payment = $this->remote_json(
			'GET',
			$this->api_base() . 'payments/' . rawurlencode( $id ),
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $this->get_option( 'api_key' ) ) )
		);
		if ( is_wp_error( $payment ) ) {
			return false;
		}
		$ok = 'paid' === ( $payment['status'] ?? '' )
			&& (string) $order->get_id() === (string) ( $payment['reference'] ?? '' )
			&& number_format( $order->get_total(), 2, '.', '' ) === (string) ( $payment['amount'] ?? '' )
			&& $order->get_currency() === ( $payment['currency'] ?? '' );
		return $ok ? $order->payment_complete( $id ) : false;
	}
}

// Register it.
add_filter(
	'sidcraft_commerce_gateways',
	static function ( $gateways ) {
		$gateways[] = new Example_Local_Bank_Gateway();
		return $gateways;
	}
);
