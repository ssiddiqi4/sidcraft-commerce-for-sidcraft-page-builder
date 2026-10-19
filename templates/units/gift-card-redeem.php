<?php
/**
 * Gift card code field for the cart panel and the checkout summary (not a
 * <form>: the checkout summary sits inside the checkout form). Shown once
 * the store has at least one gift card.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/gift-card-redeem.php
 * Keep data-sc-redeem, data-sc-redeem-input, data-sc-redeem-apply,
 * data-sc-redeem-remove and #sc-redeem-error.
 *
 * @package SidcraftCommerce
 * @version 1.7.0
 *
 * @var \SidcraftCommerce\Cart\Cart $cart
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Data\Post_Types;
use function SidcraftCommerce\format_price;

if ( ! \SidcraftCommerce\Modules::enabled( 'giftcards' ) ) {
	return;
}
$sidcraft_commerce_counts  = wp_count_posts( Post_Types::GIFTCARD );
$sidcraft_commerce_applied = $cart->get_applied_gift_cards();
if ( empty( $sidcraft_commerce_counts->publish ) && ! $sidcraft_commerce_applied ) {
	return;
}
?>
<div class="sc-coupon sc-redeem" data-sc-redeem>
	<?php foreach ( $sidcraft_commerce_applied as $sidcraft_commerce_a ) : ?>
		<p class="sc-coupon__applied<?php echo '' !== $sidcraft_commerce_a['error'] ? ' is-invalid' : ''; ?>">
			<span class="sc-coupon__tag">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="3" y="8" width="18" height="13" rx="2"/><path d="M12 8v13M3 12h18M12 8c-2-4-6-4-6-1.5S9 8 12 8zm0 0c2-4 6-4 6-1.5S15 8 12 8z"/></svg>
				<strong><?php echo esc_html( $sidcraft_commerce_a['card']->get_masked_code() ); ?></strong>
				<span class="sc-coupon__offer">
					<?php
					echo esc_html(
						'' !== $sidcraft_commerce_a['error']
							? $sidcraft_commerce_a['error']
							/* translators: 1: amount used, 2: balance. */
							: sprintf( __( '%1$s of %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $sidcraft_commerce_a['amount'] ), format_price( \SidcraftCommerce\money( $sidcraft_commerce_a['card']->get_balance() ) ) )
					);
					?>
				</span>
			</span>
			<button type="button" class="sc-coupon__remove" data-sc-redeem-remove="<?php echo esc_attr( $sidcraft_commerce_a['card']->get_code() ); ?>"><?php esc_html_e( 'Remove', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
		</p>
	<?php endforeach; ?>
	<?php if ( $cart->get_total() > 0 ) : ?>
		<label class="sc-coupon__label" for="sc-redeem-code"><?php esc_html_e( 'Have a gift card?', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
		<div class="sc-coupon__row">
			<input type="text" id="sc-redeem-code" class="sc-coupon__input" data-sc-redeem-input autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX" aria-describedby="sc-redeem-error">
			<button type="button" class="sc-btn sc-btn--ghost sc-coupon__apply" data-sc-redeem-apply><?php esc_html_e( 'Apply', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
		</div>
	<?php endif; ?>
	<span class="sc-field__error sc-coupon__msg" id="sc-redeem-error" role="status" aria-live="polite"></span>
</div>
