<?php
/**
 * Cache-safe cart fragments: selector → HTML pairs swapped in by JS.
 *
 * Pages render cart-free placeholders (badge "0", empty subtotal) so their
 * HTML is identical for every visitor and safe to page-cache. Real values
 * arrive with every cart response, or on page load from GET /cart — and only
 * when the sidccofo_items_in_cart cookie says there is something to show.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Cart\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Class Fragments
 */
class Fragments {

	/**
	 * Build all fragments.
	 *
	 * @param string $context '' | cart | checkout: also re-render that panel.
	 * @return array
	 */
	public static function collect( $context = '' ) {
		$cart  = Cart::instance();
		$count = $cart->get_count();

		$fragments = array(
			'span.sc-mini-cart__count' => sprintf(
				'<span class="sc-mini-cart__count" data-count="%1$d">%2$s</span>',
				$count,
				esc_html( number_format_i18n( $count ) )
			),
			'span.sc-mini-cart__total' => sprintf(
				'<span class="sc-mini-cart__total">%s</span>',
				$count ? esc_html( format_price( $cart->get_subtotal() ) ) : ''
			),
		);

		$templates = Plugin::instance()->templates;
		if ( 'cart' === $context ) {
			$fragments['div.sc-cart'] = $templates->render( 'units/cart-panel.php', array( 'cart' => $cart ) );
		} elseif ( 'checkout' === $context ) {
			$fragments['div.sc-checkout-summary'] = $templates->render( 'units/checkout-summary.php', array( 'cart' => $cart ) );
		}

		/**
		 * Filter cart fragments.
		 *
		 * @param array  $fragments Selector → HTML.
		 * @param Cart   $cart      Cart.
		 * @param string $context   Context.
		 */
		return (array) apply_filters( 'sidcraft_commerce_fragments', $fragments, $cart, $context );
	}
}
