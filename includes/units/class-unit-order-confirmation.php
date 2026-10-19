<?php
/**
 * Order Confirmation Unit ("thank you" page). Shows an order only when the
 * URL carries its secret key: ?sidccofo_order=123&key=sc_…
 *
 * Shortcode fallback: [sidccofo_order_confirmation]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Cart\Cart as Store_Cart;
use SidcraftCommerce\Checkout\Checkout as Store_Checkout;
use SidcraftCommerce\Data\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Confirmation
 */
class Order_Confirmation extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-order-confirmation';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Order Confirmation', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'yes-alt';
	}

	/** {@inheritDoc} */
	public function get_assets() {
		return array(
			'styles'  => array( 'sidccofo-commerce', 'sidccofo-checkout' ),
			'scripts' => array(),
		);
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'heading' => array(
				'type'    => 'text',
				'label'   => __( 'Heading', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Thank you — your order is in.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Access is gated by the order key.
		$order = isset( $_GET['sidccofo_order'] ) ? Order::get( absint( $_GET['sidccofo_order'] ) ) : null;
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable

		if ( ! $order || ! $order->key_is_valid( $key ) ) {
			return current_user_can( 'edit_posts' )
				? '<p class="sc-empty">' . esc_html__( 'Order details appear here after checkout.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>'
				: '';
		}

		// Let the gateway confirm payment if its webhook hasn't arrived yet.
		$gateway = Store_Checkout::gateway( $order->get_payment_method() );
		if ( $gateway ) {
			$gateway->on_return( $order );
			$order = Order::get( $order->get_id() );
		}

		// This visitor's checkout is finished: empty their cart.
		$cart = Store_Cart::instance();
		if ( (int) $cart->session()->get( 'order_awaiting_payment', 0 ) === $order->get_id() && 'sidccofo-pending' !== $order->get_status() ) {
			$cart->clear();
			$cart->session()->set( 'order_awaiting_payment', 0 );
		}

		return $this->template(
			'units/order-confirmation.php',
			array(
				'order'    => $order,
				'settings' => $settings,
				'gateway'  => $gateway,
			)
		);
	}
}
