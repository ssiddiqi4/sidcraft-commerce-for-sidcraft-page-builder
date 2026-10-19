<?php
/**
 * Order emails, sent with wp_mail (works with any SMTP plugin).
 *
 * Triggers:
 *   pending → on-hold     customer "order received" + admin "new order"
 *   pending → processing  customer "order received" + admin "new order"
 *   on-hold → processing  customer "payment received"
 *   * → completed         customer "order complete" (skipped when a shipment email was just sent)
 *   shipment added        customer "your order has shipped" with tracking links (Shipments::add)
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Data\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Class Emails
 */
class Emails {

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'on_status_change' ), 10, 3 );
	}

	/**
	 * Decide which emails a transition triggers.
	 *
	 * @param Order  $order Order.
	 * @param string $old_status   Old status.
	 * @param string $new_status New status.
	 */
	public function on_status_change( Order $order, $old_status, $new_status ) {
		$placed = 'sidccofo-pending' === $old_status && in_array( $new_status, array( 'sidccofo-on-hold', 'sidccofo-processing' ), true );

		// The customer hears in the language they ordered in; the store in its own.
		Multilingual::for_order(
			$order,
			function () use ( $order, $old_status, $new_status, $placed ) {
				$this->customer_emails( $order, $old_status, $new_status, $placed );
			}
		);
		if ( $placed ) {
			$this->send( $order, (string) setting( 'admin_email', get_option( 'admin_email' ) ), __( 'New order', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'admin' );
		}
	}

	/**
	 * Customer emails a transition triggers.
	 *
	 * @param Order  $order      Order.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 * @param bool   $placed     The order was just placed.
	 */
	protected function customer_emails( Order $order, $old_status, $new_status, $placed ) {
		if ( $placed ) {
			$this->send( $order, $order->get_email(), __( 'We’ve received your order', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'customer' );
		} elseif ( 'sidccofo-on-hold' === $old_status && 'sidccofo-processing' === $new_status ) {
			$this->send( $order, $order->get_email(), __( 'Payment received', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'customer' );
		} elseif ( 'sidccofo-completed' === $new_status && ! Shipments::was_notified( $order ) ) {
			$this->send( $order, $order->get_email(), __( 'Your order is complete', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'customer' );
		} else {
			// Statuses registered with a customer_email (e.g. "Ready for pickup").
			$defs  = \SidcraftCommerce\Data\Post_Types::order_status_definitions();
			$email = $defs[ $new_status ]['customer_email'] ?? false;
			if ( $email ) {
				$this->send( $order, $order->get_email(), $email['heading'], 'customer', array( 'message' => $email['message'] ) );
			}
		}
	}

	/**
	 * "Your order has shipped" email with the tracking link(s).
	 *
	 * @param Order $order    Order.
	 * @param array $shipment The shipment just added (with tracking_url).
	 */
	public function send_shipment( Order $order, array $shipment ) {
		Multilingual::for_order(
			$order,
			function () use ( $order, $shipment ) {
				$this->send(
					$order,
					$order->get_email(),
					__( 'Your order has shipped', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'customer',
					array(
						'shipment'  => $shipment,
						'shipments' => Shipments::get( $order ),
					)
				);
			}
		);
	}

	/**
	 * An order email with a message of its own and an optional button
	 * (subscription renewal invoices).
	 *
	 * @param Order  $order   Order.
	 * @param string $to      Recipient.
	 * @param string $heading Heading.
	 * @param string $message Message.
	 * @param string $pay_url Link for a "Pay now" button ('' for none).
	 */
	public function send_custom( Order $order, $to, $heading, $message, $pay_url = '' ) {
		$this->send(
			$order,
			$to,
			$heading,
			'customer',
			array(
				'message' => $message,
				'pay_url' => $pay_url,
			)
		);
	}

	/**
	 * A subscription email (cancelled, finished).
	 *
	 * @param \SidcraftCommerce\Data\Subscription $sub     Subscription.
	 * @param string                             $heading Heading.
	 * @param string                             $message Message.
	 */
	public function send_subscription( $sub, $heading, $message ) {
		$to = $sub->get_email();
		if ( ! is_email( $to ) ) {
			return;
		}
		/* translators: 1: heading, 2: subscription number. */
		$subject = sprintf( __( '%1$s (#%2$d)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $heading, $sub->get_id() );
		$body    = Plugin::instance()->templates->render(
			'emails/subscription.php',
			array(
				'subscription' => $sub,
				'heading'      => $heading,
				'message'      => $message,
			)
		);
		$from    = sanitize_text_field( (string) setting( 'from_name', get_bloginfo( 'name' ) ) );
		wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . $from . ' <' . get_option( 'admin_email' ) . '>' ) );
	}

	/**
	 * Render and send one email.
	 *
	 * @param Order  $order     Order.
	 * @param string $to        Recipient.
	 * @param string $heading   Heading.
	 * @param string $recipient customer|admin.
	 * @param array  $extra     Extra template variables (shipment, shipments).
	 */
	protected function send( Order $order, $to, $heading, $recipient, array $extra = array() ) {
		if ( ! is_email( $to ) ) {
			return;
		}

		/* translators: 1: heading, 2: order number. */
		$subject = sprintf( __( '%1$s (#%2$d)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $heading, $order->get_id() );
		$body    = Plugin::instance()->templates->render(
			'emails/order.php',
			array_merge(
				array(
					'order'     => $order,
					'heading'   => $heading,
					'recipient' => $recipient,
					'shipment'  => null,
					'shipments' => Shipments::get( $order ),
					'message'   => '',
					'pay_url'   => '',
				),
				$extra
			)
		);

		$from    = sanitize_text_field( (string) setting( 'from_name', get_bloginfo( 'name' ) ) );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from . ' <' . get_option( 'admin_email' ) . '>',
		);

		/**
		 * Filter an email before sending; return false to skip it.
		 *
		 * @param array|false $mail      [ to, subject, body, headers ].
		 * @param Order       $order     Order.
		 * @param string      $recipient customer|admin.
		 */
		$mail = apply_filters( 'sidcraft_commerce_email', array( $to, $subject, $body, $headers ), $order, $recipient );
		if ( $mail ) {
			wp_mail( $mail[0], $mail[1], $mail[2], $mail[3] );
		}
	}
}
