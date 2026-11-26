<?php
/**
 * Offline payment methods: bank transfer, cheque and cash on delivery.
 *
 * The order is placed and its stock reserved; it waits "On hold" (cash on
 * delivery can go straight to "Processing") until the store owner marks it
 * paid. Payment instructions show at checkout, on the order-received page and
 * in the order email.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Payments\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Class Offline_Payments
 */
class Offline_Payments extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		add_filter( 'sidcraft_commerce_gateways', array( $this, 'register' ) );
		add_filter( 'sidcraft_commerce_email', array( $this, 'email_instructions' ), 10, 3 );
	}

	/**
	 * Add the three gateways (the classes load with the gateway framework).
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public function register( $gateways ) {
		if ( ! class_exists( __NAMESPACE__ . '\Offline_Gateway' ) ) {
			require_once __DIR__ . '/class-offline-gateway.php';
		}
		$gateways[] = new Gateway_Bacs();
		$gateways[] = new Gateway_Cheque();
		$gateways[] = new Gateway_Cod();
		return $gateways;
	}

	/**
	 * Put the payment instructions in the order email while the order waits for payment.
	 *
	 * @param array  $mail      [ to, subject, body, headers ].
	 * @param Order  $order     Order.
	 * @param string $recipient customer|admin.
	 * @return array
	 */
	public function email_instructions( $mail, $order, $recipient ) {
		if ( 'customer' !== $recipient || ! $order instanceof Order || 'sidccofo-on-hold' !== $order->get_status() ) {
			return $mail;
		}
		$gateway = \SidcraftCommerce\Checkout\Checkout::gateway( $order->get_payment_method() );
		if ( ! $gateway instanceof Offline_Gateway ) {
			return $mail;
		}
		$text = $gateway->instructions_text();
		if ( '' === $text || ! is_array( $mail ) || ! isset( $mail[2] ) ) {
			return $mail;
		}
		$box = '<div style="margin:16px 0;padding:12px 16px;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7"><strong>' . esc_html( $gateway->get_title() ) . '</strong><br>' . nl2br( esc_html( $text ) ) . '</div>';
		// Before the closing body tag when there is one, else at the end.
		$mail[2] = false !== stripos( (string) $mail[2], '</body>' ) ? preg_replace( '#</body>#i', $box . '</body>', (string) $mail[2], 1 ) : $mail[2] . $box;
		return $mail;
	}
}
