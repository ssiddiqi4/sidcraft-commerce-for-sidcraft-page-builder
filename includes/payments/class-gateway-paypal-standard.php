<?php
/**
 * PayPal Standard: the buyer pays on PayPal's own payment page with a
 * PayPal account or a card; only a PayPal email address is needed.
 *
 * Flow:
 *   1. process_payment() returns a form that the checkout script POSTs to
 *      PayPal (www.paypal.com/cgi-bin/webscr) with the order total, built
 *      from the store order (never from browser input).
 *   2. PayPal sends an Instant Payment Notification (IPN) to
 *      /wp-json/sidcraft-commerce/v1/gateway/paypal_standard/webhook. Every
 *      IPN is sent back to PayPal for validation (cmd=_notify-validate); only a
 *      VERIFIED message for this store's PayPal account, with the order's
 *      amount and currency, marks the order paid. Refunds, reversals and
 *      failed payments are applied too.
 *   3. Optional: with a PDT identity token, the buyer's return to the store
 *      confirms the payment at once (cmd=_notify-synch), even before the IPN.
 *
 * More payment methods (PayPal Commerce with Pay Later and Venmo buttons,
 * Stripe, Razorpay, PayU, Mercado Pago, Square, Mollie, Authorize.net) come
 * with Sidcraft Builder Pro.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Payments;

use SidcraftCommerce\Data\Order;
use function SidcraftCommerce\decimals_for;
use function SidcraftCommerce\round_amount;

defined( 'ABSPATH' ) || exit;

/**
 * Class Gateway_PayPal_Standard
 */
class Gateway_PayPal_Standard extends Gateway {

	/** {@inheritDoc} */
	public function get_id() {
		return 'paypal_standard';
	}

	/** {@inheritDoc} */
	public function get_default_title() {
		return __( 'PayPal', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_settings_title() {
		return __( 'PayPal Standard', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_description() {
		return __( 'You will be taken to PayPal to pay securely with your PayPal account or a card.', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_settings_intro() {
		return sprintf(
			/* translators: %s: notification URL. */
			__( 'Buyers pay on PayPal’s payment page with a PayPal account or a card. PayPal confirms each payment with an Instant Payment Notification (IPN) to %s. It is sent automatically with every payment; if you turned IPN off in your PayPal account, turn it back on with this URL.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			$this->webhook_url()
		);
	}

	/** {@inheritDoc} */
	public function get_settings_fields() {
		return array(
			'email'          => array( __( 'PayPal email or Merchant ID', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'The PayPal account that receives the payments (a Business account is recommended).', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'sandbox'        => array( __( 'Sandbox (test) mode', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Use a sandbox business account from developer.paypal.com while testing. Turn off to take real payments.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'identity_token' => array( __( 'PDT identity token (optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'password', __( 'Confirms payments the moment buyers return to your store. In PayPal: Account Settings → Website payments → Website preferences: turn on Auto return and Payment data transfer, then copy the identity token.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'invoice_prefix' => array( __( 'Invoice prefix', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Added before the order number in PayPal (default SC-). Use a different prefix for each store that shares this PayPal account.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
		);
	}

	/** {@inheritDoc} */
	public function has_credentials() {
		return '' !== $this->receiver();
	}

	/**
	 * Currencies PayPal accepts for payments.
	 *
	 * @return string[]
	 */
	public function get_supported_currencies() {
		return (array) apply_filters(
			'sidcraft_commerce_paypal_standard_currencies',
			array( 'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD' )
		);
	}

	/**
	 * PayPal email or merchant ID that receives payments.
	 *
	 * @return string
	 */
	protected function receiver() {
		return trim( (string) $this->get_option( 'email', '' ) );
	}

	/**
	 * Sandbox mode.
	 *
	 * @return bool
	 */
	protected function sandbox() {
		return (bool) $this->get_option( 'sandbox', 0 );
	}

	/**
	 * Payment page URL.
	 *
	 * @return string
	 */
	protected function pay_url() {
		return $this->sandbox() ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';
	}

	/**
	 * IPN validation URL.
	 *
	 * @return string
	 */
	protected function ipn_url() {
		return $this->sandbox() ? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr' : 'https://ipnpb.paypal.com/cgi-bin/webscr';
	}

	/**
	 * Invoice prefix.
	 *
	 * @return string
	 */
	protected function prefix() {
		$prefix = preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $this->get_option( 'invoice_prefix', '' ) );
		return '' !== $prefix ? $prefix : 'SC-';
	}

	/**
	 * Amount as PayPal expects it (no decimals for JPY, HUF and TWD; at most two).
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	protected static function amount( $amount, $currency ) {
		$decimals = in_array( $currency, array( 'JPY', 'HUF', 'TWD' ), true ) ? 0 : min( 2, decimals_for( $currency ) );
		return number_format( (float) $amount, $decimals, '.', '' );
	}

	/** {@inheritDoc} */
	public function process_payment( Order $order ) {
		$names = array();
		foreach ( $order->get_items() as $item ) {
			$names[] = $item['name'] . ( (int) $item['quantity'] > 1 ? ' × ' . (int) $item['quantity'] : '' );
		}
		$store = wp_strip_all_tags( get_bloginfo( 'name' ) );
		/* translators: 1: store name, 2: order number. */
		$title   = sprintf( __( '%1$s order #%2$d', 'sidcraft-commerce-for-sidcraft-page-builder' ), $store, $order->get_id() );
		$billing = $order->get_billing();
		$fields  = array(
			'cmd'           => '_xclick',
			'business'      => $this->receiver(),
			'item_name'     => mb_substr( $title . ( $names ? ': ' . implode( ', ', $names ) : '' ), 0, 127 ),
			'item_number'   => (string) $order->get_id(),
			'amount'        => self::amount( $order->get_total(), $order->get_currency() ),
			'currency_code' => $order->get_currency(),
			'quantity'      => '1',
			'invoice'       => $this->prefix() . $order->get_id(),
			'custom'        => $order->get_id() . '|' . $order->get_key(),
			'return'        => $this->return_url( $order ),
			'cancel_return' => $this->cancel_url(),
			'notify_url'    => $this->webhook_url(),
			'no_shipping'   => '1',
			'no_note'       => '1',
			'rm'            => '2',
			'charset'       => 'utf-8',
			'bn'            => 'SidcraftCommerce_Cart',
			'email'         => (string) $order->get_email(),
			'first_name'    => (string) ( $billing['first_name'] ?? '' ),
			'last_name'     => (string) ( $billing['last_name'] ?? '' ),
		);

		/**
		 * Filter the fields posted to PayPal Standard.
		 *
		 * @param array $fields Fields.
		 * @param Order $order  Order.
		 */
		$fields = (array) apply_filters( 'sidcraft_commerce_paypal_standard_args', $fields, $order );
		$this->log( $order, __( 'Customer sent to PayPal.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		return array(
			'post' => array(
				'url'    => $this->pay_url(),
				'fields' => $fields,
			),
		);
	}

	/**
	 * Return from PayPal: confirm with PDT when a token is set, then show the
	 * order-received page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_return( \WP_REST_Request $request ) {
		$order = Order::get( absint( $request->get_param( 'order' ) ) );
		$token = trim( (string) $this->get_option( 'identity_token', '' ) );
		$tx    = sanitize_text_field( (string) ( $request->get_param( 'tx' ) ? $request->get_param( 'tx' ) : $request->get_param( 'txn_id' ) ) );
		if ( $order && $order->key_is_valid( (string) $request->get_param( 'key' ) ) && '' !== $token && '' !== $tx && 'sidccofo-pending' === $order->get_status() ) {
			$data = $this->pdt( $tx, $token );
			if ( is_array( $data ) ) {
				$this->apply( $data, $order );
			}
		}
		return parent::handle_return( $request );
	}

	/**
	 * Payment Data Transfer lookup.
	 *
	 * @param string $tx    Transaction ID.
	 * @param string $token Identity token.
	 * @return array|null Payment variables, or null.
	 */
	protected function pdt( $tx, $token ) {
		$response = wp_remote_post(
			$this->pay_url(),
			array(
				'timeout'     => 30,
				'httpversion' => '1.1',
				'user-agent'  => 'SidcraftCommerce/' . SIDCRAFT_COMMERCE_VERSION,
				'body'        => array(
					'cmd' => '_notify-synch',
					'tx'  => $tx,
					'at'  => $token,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$lines = preg_split( '/\R/', trim( (string) wp_remote_retrieve_body( $response ) ) );
		if ( 'SUCCESS' !== trim( (string) array_shift( $lines ) ) ) {
			return null;
		}
		$data = array();
		foreach ( $lines as $line ) {
			$pair = explode( '=', $line, 2 );
			if ( 2 === count( $pair ) ) {
				$data[ urldecode( $pair[0] ) ] = urldecode( $pair[1] );
			}
		}
		return $data;
	}

	/**
	 * IPN listener.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_webhook( \WP_REST_Request $request ) {
		$raw = (string) $request->get_body();
		if ( '' === $raw ) {
			return new \WP_REST_Response( array( 'error' => 'empty' ), 400 );
		}
		$response = wp_remote_post(
			$this->ipn_url(),
			array(
				'timeout'     => 30,
				'httpversion' => '1.1',
				'user-agent'  => 'SidcraftCommerce/' . SIDCRAFT_COMMERCE_VERSION,
				'headers'     => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'        => 'cmd=_notify-validate&' . $raw,
			)
		);
		if ( is_wp_error( $response ) || 'VERIFIED' !== trim( (string) wp_remote_retrieve_body( $response ) ) ) {
			return new \WP_REST_Response( array( 'error' => 'not verified' ), 400 ); // PayPal retries.
		}
		$data = array();
		parse_str( $raw, $data );
		$data = array_map(
			static function ( $value ) {
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			},
			$data
		);
		$this->apply( $data );
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Find the order a PayPal message is about.
	 *
	 * @param array $data Payment variables.
	 * @return Order|null
	 */
	protected function order_from( array $data ) {
		$custom = (string) ( $data['custom'] ?? '' );
		if ( false !== strpos( $custom, '|' ) ) {
			list( $id, $key ) = explode( '|', $custom, 2 );
			$order            = Order::get( absint( $id ) );
			if ( $order && $order->key_is_valid( $key ) ) {
				return $order;
			}
		}
		$invoice = (string) ( $data['invoice'] ?? '' );
		if ( '' !== $invoice && 0 === strpos( $invoice, $this->prefix() ) ) {
			$order = Order::get( absint( substr( $invoice, strlen( $this->prefix() ) ) ) );
			if ( $order && $this->get_id() === $order->get_payment_method() ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Is the message for this store's PayPal account?
	 *
	 * @param array $data Payment variables.
	 * @return bool
	 */
	protected function for_this_account( array $data ) {
		$mine = strtolower( $this->receiver() );
		foreach ( array( 'receiver_email', 'business', 'receiver_id' ) as $key ) {
			if ( isset( $data[ $key ] ) && strtolower( (string) $data[ $key ] ) === $mine ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Apply a verified IPN or PDT message to its order.
	 *
	 * @param array      $data  Payment variables.
	 * @param Order|null $order Order (when already known).
	 */
	protected function apply( array $data, $order = null ) {
		$order = $order instanceof Order ? $order : $this->order_from( $data );
		if ( ! $order ) {
			return;
		}
		if ( ! $this->for_this_account( $data ) ) {
			$this->log( $order, __( 'Ignored a PayPal message for a different PayPal account.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			return;
		}
		$status = strtolower( (string) ( $data['payment_status'] ?? '' ) );
		$txn    = (string) ( $data['txn_id'] ?? '' );
		$gross  = round_amount( (float) ( $data['mc_gross'] ?? 0 ) );

		switch ( $status ) {
			case 'completed':
				if ( ! in_array( $order->get_status(), array( 'sidccofo-pending', 'sidccofo-on-hold' ), true ) ) {
					return;
				}
				if ( strtoupper( (string) ( $data['mc_currency'] ?? '' ) ) !== strtoupper( $order->get_currency() ) || abs( $gross - round_amount( $order->get_total() ) ) > 0.01 ) {
					/* translators: 1: amount, 2: currency. */
					$note = sprintf( __( 'PayPal payment of %1$s %2$s does not match the order total. Check it in PayPal before shipping.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $gross, (string) ( $data['mc_currency'] ?? '' ) );
					if ( ! $order->set_status( 'sidccofo-on-hold', $note ) ) {
						$this->log( $order, $note );
					}
					return;
				}
				if ( ! empty( $data['payer_email'] ) ) {
					update_post_meta( $order->get_id(), '_sidccofo_paypal_payer', sanitize_email( (string) $data['payer_email'] ) );
				}
				$order->payment_complete( $txn );
				break;

			case 'pending':
				if ( 'sidccofo-pending' === $order->get_status() ) {
					/* translators: %s: PayPal's reason. */
					$order->set_status( 'sidccofo-on-hold', sprintf( __( 'PayPal payment pending (%s).', 'sidcraft-commerce-for-sidcraft-page-builder' ), (string) ( $data['pending_reason'] ?? '' ) ) );
				}
				break;

			case 'failed':
			case 'denied':
			case 'expired':
			case 'voided':
				if ( in_array( $order->get_status(), array( 'sidccofo-pending', 'sidccofo-on-hold' ), true ) ) {
					/* translators: %s: PayPal status. */
					$order->set_status( 'sidccofo-cancelled', sprintf( __( 'PayPal payment %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $status ) );
				}
				break;

			case 'refunded':
				/* translators: %s: transaction ID. */
				$order->record_refund( abs( $gross ), array(), 'ppstd-refund-' . $txn, sprintf( __( 'Refunded in PayPal (%s).', 'sidcraft-commerce-for-sidcraft-page-builder' ), $txn ) );
				break;

			case 'reversed':
				/* translators: %s: PayPal's reason. */
				$order->open_dispute( 'ppstd-reversal-' . $txn, sprintf( __( 'PayPal reversed the payment (%s).', 'sidcraft-commerce-for-sidcraft-page-builder' ), (string) ( $data['reason_code'] ?? '' ) ) );
				break;

			case 'canceled_reversal':
				$order->close_dispute( 'ppstd-cancel-reversal-' . $txn, false, __( 'PayPal cancelled the reversal; the payment is yours again.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
				break;
		}
	}
}
