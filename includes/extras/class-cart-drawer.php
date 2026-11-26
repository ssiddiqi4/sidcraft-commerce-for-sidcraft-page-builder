<?php
/**
 * Cart drawer and sticky add-to-cart bar.
 *
 * - The drawer slides in from the right after a product is added (or when
 *   the mini cart is clicked), shows the lines with quantity boxes, the
 *   subtotal and the Cart and Checkout buttons. Its contents ride along with
 *   the normal cart fragments, so they are always current and pages stay
 *   cacheable (the markup is the same for everyone).
 * - On a product page, a bar with the name, price and Add to cart button
 *   appears when the real button scrolls out of view.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\format_price;
use function SidcraftCommerce\page_url;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cart_Drawer
 */
class Cart_Drawer extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$this->settings(
			'drawer',
			__( 'Cart drawer and sticky add-to-cart', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'drawer_open'   => array( __( 'Open the drawer after adding', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
				'drawer_mini'   => array( __( 'Open the drawer from the mini cart', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Clicking the mini-cart icon opens the drawer instead of going to the cart page.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'sticky_atc'    => array( __( 'Sticky add-to-cart bar on product pages', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
			)
		);
		add_action( 'wp_footer', array( $this, 'markup' ), 5 );
		add_filter( 'sidcraft_commerce_fragments', array( $this, 'fragment' ), 10, 2 );
		add_filter( 'sidcraft_commerce_js_config', array( $this, 'config' ) );
	}

	/**
	 * Settings for the script.
	 *
	 * @param array $config Config.
	 * @return array
	 */
	public function config( $config ) {
		$config['drawer'] = array(
			'open'   => (bool) setting( 'drawer_open', 1 ),
			'mini'   => (bool) setting( 'drawer_mini', 1 ),
			'sticky' => (bool) setting( 'sticky_atc', 1 ),
		);
		return $config;
	}

	/**
	 * Print the (empty) drawer and the sticky bar on store pages.
	 */
	public function markup() {
		if ( is_admin() || ! wp_script_is( 'sidccofo-cart', 'enqueued' ) ) {
			return;
		}
		$onCart = is_page( \SidcraftCommerce\page_id( 'cart' ) ) || is_page( \SidcraftCommerce\page_id( 'checkout' ) );
		Extras::enqueue();
		if ( ! $onCart ) {
			echo '<div class="sc-drawer" id="sc-drawer" hidden><div class="sc-drawer__scrim" data-sc-drawer-close></div><aside class="sc-drawer__panel" role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'Your cart', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '"><header class="sc-drawer__head"><strong>' . esc_html__( 'Your cart', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</strong><button type="button" class="sc-drawer__close" data-sc-drawer-close aria-label="' . esc_attr__( 'Close', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '">×</button></header><div class="sc-drawer__body"></div></aside></div>';
		}
		if ( setting( 'sticky_atc', 1 ) && is_singular( Post_Types::PRODUCT ) ) {
			$p = Product::get( get_the_ID() );
			if ( $p && $p->can_add_to_cart() && ! $p->has_options() && ! $p->is_gift_card() ) {
				echo '<div class="sc-sticky" data-sc-sticky hidden><span class="sc-sticky__name">' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . '</span><span class="sc-sticky__price">' . wp_kses_post( $p->get_price_html() ) . '</span><button type="button" class="sc-btn sc-btn--primary" data-sc-sticky-add data-product-id="' . esc_attr( $p->get_id() ) . '">' . esc_html( $p->add_to_cart_text() ) . '</button></div>';
			}
		}
	}

	/**
	 * The drawer body, kept current with the other fragments.
	 *
	 * @param array $fragments Fragments.
	 * @param Cart  $cart      Cart.
	 * @return array
	 */
	public function fragment( $fragments, $cart ) {
		$html = '<div class="sc-drawer__body">';
		if ( $cart->is_empty() ) {
			$html .= '<p class="sc-drawer__empty">' . esc_html__( 'Your cart is empty.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p><a class="sc-btn" href="' . esc_url( page_url( 'shop' ) ) . '">' . esc_html__( 'Continue shopping', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a>';
		} else {
			$html .= '<ul class="sc-drawer__items">';
			foreach ( $cart->get_items() as $line ) {
				$p     = $line['product'];
				$thumb = ! empty( $line['image_id'] ) ? wp_get_attachment_image( (int) $line['image_id'], 'thumbnail' ) : $p->get_image( 'thumbnail' );
				$html .= '<li class="sc-drawer__item" data-product-id="' . esc_attr( $line['product_id'] ) . '" data-line-key="' . esc_attr( $line['key'] ?? '' ) . '"><span class="sc-drawer__thumb">' . $thumb . '</span><span class="sc-drawer__info"><a href="' . esc_url( $p->get_permalink() ) . '">' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . '</a>' . \SidcraftCommerce\item_options_html( $line['options'] ?? array() ) . '<span class="sc-drawer__sum">' . esc_html( format_price( $line['price'] ) ) . '</span></span>';
				$html .= '<span class="sc-qty sc-qty--sm"><button type="button" class="sc-qty__step" data-sc-drawer-qty="-1" aria-label="' . esc_attr__( 'Decrease quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '">−</button><span class="sc-drawer__qty">' . (int) $line['quantity'] . '</span><button type="button" class="sc-qty__step" data-sc-drawer-qty="1" aria-label="' . esc_attr__( 'Increase quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '">+</button></span>';
				$html .= '<button type="button" class="sc-drawer__remove" data-sc-drawer-remove aria-label="' . esc_attr( sprintf( /* translators: %s: product. */ __( 'Remove %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_strip_all_tags( $p->get_name() ) ) ) . '">×</button></li>';
			}
			$html .= '</ul><div class="sc-drawer__foot"><div class="sc-drawer__subtotal"><span>' . esc_html__( 'Subtotal', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span><strong>' . esc_html( format_price( $cart->get_subtotal() ) ) . '</strong></div><a class="sc-btn" href="' . esc_url( page_url( 'cart' ) ) . '">' . esc_html__( 'View cart', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a> <a class="sc-btn sc-btn--primary" href="' . esc_url( page_url( 'checkout' ) ) . '">' . esc_html__( 'Checkout', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></div>';
		}
		$fragments['div.sc-drawer__body'] = $html . '</div>';
		return $fragments;
	}
}
