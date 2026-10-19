<?php
/**
 * Checkout Unit: customer details, payment method, order summary.
 * Per-visitor output: pages holding it are sent with no-cache headers.
 *
 * Shortcode fallback: [sidccofo_checkout]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Cart\Cart as Store_Cart;
use SidcraftCommerce\Checkout\Checkout as Store_Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * Class Checkout
 */
class Checkout extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-checkout';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Checkout', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'money-alt';
	}

	/** {@inheritDoc} */
	public function get_assets() {
		$assets = array(
			'styles'  => array( 'sidccofo-commerce', 'sidccofo-checkout' ),
			'scripts' => array( 'sidccofo-cart', 'sidccofo-checkout' ),
		);
		// Gateways with on-page buttons (e.g. PayPal) add theirs —
		// skipped for an empty cart, where no payment buttons are shown.
		$gateways = Store_Cart::instance()->is_empty() ? array() : Store_Checkout::available_gateways();
		foreach ( $gateways as $gateway ) {
			$extra             = $gateway->get_checkout_assets();
			$assets['styles']  = array_merge( $assets['styles'], (array) ( $extra['styles'] ?? array() ) );
			$assets['scripts'] = array_merge( $assets['scripts'], (array) ( $extra['scripts'] ?? array() ) );
		}
		return $assets;
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'button_text' => array(
				'type'    => 'text',
				'label'   => __( 'Button text', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Place order', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
			'show_notes'  => array(
				'type'    => 'switch',
				'label'   => __( 'Order notes field', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		$cart = Store_Cart::instance();
		$user = wp_get_current_user();

		// Prefill: last checkout in this session, else the logged-in user.
		$saved = (array) $cart->session()->get( 'customer', array() );
		if ( ! $saved && $user->exists() ) {
			$saved = array(
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
				'email'      => $user->user_email,
			);
		}
		// Country/state follow the cart's destination (what shipping and tax use).
		$location         = $cart->get_location();
		$saved['country'] = $location['country'];
		$saved['state']   = $location['state'];

		// Only countries the store ships to (all when shipping isn't collected).
		$countries = \SidcraftCommerce\Locations::countries();
		$ship_to   = \SidcraftCommerce\setting( 'collect_address', 1 ) && $cart->needs_shipping() ? \SidcraftCommerce\Shipping::ship_to_countries() : array();
		if ( $ship_to ) {
			$countries = array_intersect_key( $countries, array_flip( $ship_to ) );
		}

		return $this->template(
			'units/checkout.php',
			array(
				'cart'      => $cart,
				'settings'  => $settings,
				'fields'    => Store_Checkout::fields(),
				'gateways'  => Store_Checkout::available_gateways(),
				'values'    => $saved,
				'countries' => $countries,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag only.
				'cancelled' => isset( $_GET['sidccofo_cancelled'] ),
			)
		);
	}
}
