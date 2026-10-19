<?php
/**
 * Coupon field for the cart panel and the checkout summary. It is not a
 * <form> (the checkout summary already sits inside the checkout form);
 * sc-checkout.js handles the Apply / Remove buttons and the Enter key.
 *
 * Shown only when the store has at least one published coupon.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/coupon-form.php
 * Keep data-sc-coupon, data-sc-coupon-input, data-sc-coupon-apply,
 * data-sc-coupon-remove and #sc-coupon-error.
 *
 * @package SidcraftCommerce
 * @version 1.7.0
 *
 * @var \SidcraftCommerce\Cart\Cart $cart
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Data\Post_Types;

if ( ! \SidcraftCommerce\Modules::enabled( 'coupons' ) ) {
	return;
}
$sidcraft_commerce_counts = wp_count_posts( Post_Types::COUPON );
if ( empty( $sidcraft_commerce_counts->publish ) && '' === $cart->get_coupon_code() ) {
	return;
}
$sidcraft_commerce_code   = $cart->get_coupon_code();
$sidcraft_commerce_coupon = $cart->get_coupon();
?>
<div class="sc-coupon" data-sc-coupon>
	<?php if ( '' !== $sidcraft_commerce_code ) : ?>
		<p class="sc-coupon__applied<?php echo $sidcraft_commerce_coupon ? '' : ' is-invalid'; ?>">
			<span class="sc-coupon__tag">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>
				<strong><?php echo esc_html( $sidcraft_commerce_code ); ?></strong>
				<?php if ( $sidcraft_commerce_coupon ) : ?>
					<span class="sc-coupon__offer"><?php echo esc_html( $sidcraft_commerce_coupon->get_offer_label() ); ?></span>
				<?php endif; ?>
			</span>
			<button type="button" class="sc-coupon__remove" data-sc-coupon-remove><?php esc_html_e( 'Remove', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
		</p>
	<?php else : ?>
		<label class="sc-coupon__label" for="sc-coupon-code"><?php esc_html_e( 'Have a coupon?', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
		<div class="sc-coupon__row">
			<input type="text" id="sc-coupon-code" class="sc-coupon__input" data-sc-coupon-input autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="<?php esc_attr_e( 'Coupon code', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" aria-describedby="sc-coupon-error">
			<button type="button" class="sc-btn sc-btn--ghost sc-coupon__apply" data-sc-coupon-apply><?php esc_html_e( 'Apply', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
		</div>
	<?php endif; ?>
	<span class="sc-field__error sc-coupon__msg" id="sc-coupon-error" role="status" aria-live="polite"></span>
</div>
