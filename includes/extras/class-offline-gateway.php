<?php
/**
 * Base class and the three offline gateways.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Payments\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * An offline method: no provider, the owner confirms the money.
 */
abstract class Offline_Gateway extends Gateway {

	/**
	 * Default instructions.
	 *
	 * @return string
	 */
	abstract protected function default_instructions();

	/** {@inheritDoc} */
	public function get_settings_fields() {
		return array(
			'instructions' => array( __( 'Instructions', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'textarea', __( 'Shown at checkout, on the order-received page and in the order email.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
		) + $this->extra_fields();
	}

	/**
	 * More settings fields.
	 *
	 * @return array
	 */
	protected function extra_fields() {
		return array();
	}

	/**
	 * Instructions as plain text.
	 *
	 * @return string
	 */
	public function instructions_text() {
		$text = trim( (string) $this->get_option( 'instructions', '' ) );
		return '' !== $text ? $text : $this->default_instructions();
	}

	/** {@inheritDoc} */
	public function get_description() {
		return $this->instructions_text();
	}

	/** {@inheritDoc} */
	public function process_payment( Order $order ) {
		$status = $this->status_after_checkout();
		$order->set_status( $status, $this->order_note() );
		return array( 'redirect' => $order->get_received_url() );
	}

	/**
	 * Status an order gets when it is placed.
	 *
	 * @return string
	 */
	protected function status_after_checkout() {
		return 'sidccofo-on-hold';
	}

	/**
	 * Note for the order.
	 *
	 * @return string
	 */
	protected function order_note() {
		return __( 'Waiting for payment.', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}
}

/**
 * Bank transfer.
 */
class Gateway_Bacs extends Offline_Gateway {
	/** {@inheritDoc} */
	public function get_id() {
		return 'bacs';
	}

	/** {@inheritDoc} */
	public function get_default_title() {
		return __( 'Bank transfer', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	protected function default_instructions() {
		return __( 'Make your payment directly into our bank account. Use your order number as the payment reference. Your order ships when the money has arrived.', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	protected function extra_fields() {
		return array(
			'account_name' => array( __( 'Account name', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text' ),
			'account'      => array( __( 'Account number or IBAN', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text' ),
			'bank'         => array( __( 'Bank name', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text' ),
			'code'         => array( __( 'Sort code, routing number or BIC / SWIFT', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text' ),
		);
	}

	/** {@inheritDoc} */
	public function instructions_text() {
		$lines = array( parent::instructions_text() );
		foreach ( array(
			'account_name' => __( 'Account name', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'account'      => __( 'Account', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'bank'         => __( 'Bank', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'code'         => __( 'Code', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		) as $key => $label ) {
			$value = trim( (string) $this->get_option( $key, '' ) );
			if ( '' !== $value ) {
				$lines[] = $label . ': ' . $value;
			}
		}
		return implode( "\n", array_filter( $lines ) );
	}

	/** {@inheritDoc} */
	protected function order_note() {
		return __( 'Waiting for the bank transfer to arrive.', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}
}

/**
 * Cheque.
 */
class Gateway_Cheque extends Offline_Gateway {
	/** {@inheritDoc} */
	public function get_id() {
		return 'cheque';
	}

	/** {@inheritDoc} */
	public function get_default_title() {
		return __( 'Cheque', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	protected function default_instructions() {
		return __( 'Please send a cheque to the store address. Your order ships when the cheque has cleared.', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	protected function order_note() {
		return __( 'Waiting for the cheque.', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}
}

/**
 * Cash on delivery.
 */
class Gateway_Cod extends Offline_Gateway {
	/** {@inheritDoc} */
	public function get_id() {
		return 'cod';
	}

	/** {@inheritDoc} */
	public function get_default_title() {
		return __( 'Cash on delivery', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	protected function default_instructions() {
		return __( 'Pay with cash when your order is delivered.', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	protected function extra_fields() {
		return array(
			'status'    => array(
				__( 'Order status when placed', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				array(
					'sidccofo-processing' => __( 'Processing (ship it now, collect on delivery)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'sidccofo-on-hold'    => __( 'On hold (confirm the order first)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
			),
			'only_physical' => array( __( 'Only for orders that ship', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Hide it when the cart is only downloads, tickets or gift cards.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
		);
	}

	/** {@inheritDoc} */
	public function is_available() {
		$ok = parent::is_available();
		if ( $ok && $this->get_option( 'only_physical', 1 ) && ! \SidcraftCommerce\Cart\Cart::instance()->is_empty() && ! \SidcraftCommerce\Cart\Cart::instance()->needs_shipping() ) {
			return false;
		}
		return $ok;
	}

	/** {@inheritDoc} */
	protected function status_after_checkout() {
		return 'sidccofo-on-hold' === $this->get_option( 'status', 'sidccofo-processing' ) ? 'sidccofo-on-hold' : 'sidccofo-processing';
	}

	/** {@inheritDoc} */
	protected function order_note() {
		return __( 'Cash on delivery: collect the payment when the order is delivered.', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}
}
