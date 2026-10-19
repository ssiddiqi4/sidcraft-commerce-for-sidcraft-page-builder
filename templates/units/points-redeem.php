<?php
/**
 * "Use your reward points" at checkout (logged-in customers with points).
 * sc-checkout.js handles the buttons; it isn't a <form> because it sits
 * inside the checkout form.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/points-redeem.php
 * Keep data-sc-points, data-sc-points-input, data-sc-points-apply,
 * data-sc-points-remove and #sc-points-msg.
 *
 * @package SidcraftCommerce
 * @version 1.4.0
 *
 * @var \SidcraftCommerce\Cart\Cart $cart
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Rewards;
use function SidcraftCommerce\format_price;

if ( ! Rewards::enabled() || ! is_user_logged_in() ) {
	return;
}
$sidcraft_commerce_balance = Rewards::balance( get_current_user_id() );
$sidcraft_commerce_used    = Rewards::requested( $cart ) > 0 ? \SidcraftCommerce\Plugin::instance()->rewards->for_cart( $cart ) : array( 'points' => 0, 'amount' => 0.0 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
if ( $sidcraft_commerce_balance < 1 && $sidcraft_commerce_used['points'] < 1 ) {
	return;
}
?>
<div class="sc-coupon sc-points" data-sc-points>
	<?php if ( $sidcraft_commerce_used['points'] > 0 ) : ?>
		<p class="sc-coupon__applied">
			<span class="sc-coupon__tag">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/></svg>
				<?php /* translators: 1: points, 2: amount. */ ?>
				<strong><?php echo esc_html( sprintf( __( '%1$s points: %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( $sidcraft_commerce_used['points'] ), format_price( -1 * $sidcraft_commerce_used['amount'] ) ) ); ?></strong>
			</span>
			<button type="button" class="sc-coupon__remove" data-sc-points-remove><?php esc_html_e( 'Remove', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
		</p>
	<?php elseif ( $cart->get_total() > 0 ) : ?>
		<label class="sc-coupon__label" for="sc-points-input">
			<?php
			/* translators: 1: points, 2: what they are worth. */
			echo esc_html( sprintf( __( 'You have %1$s reward points (worth %2$s).', 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( $sidcraft_commerce_balance ), format_price( \SidcraftCommerce\money( $sidcraft_commerce_balance * Rewards::point_value() ) ) ) );
			?>
		</label>
		<div class="sc-coupon__row">
			<input type="number" min="1" max="<?php echo esc_attr( (string) $sidcraft_commerce_balance ); ?>" step="1" id="sc-points-input" class="sc-coupon__input" data-sc-points-input value="<?php echo esc_attr( (string) $sidcraft_commerce_balance ); ?>" aria-describedby="sc-points-msg">
			<button type="button" class="sc-btn sc-btn--ghost sc-coupon__apply" data-sc-points-apply><?php esc_html_e( 'Use points', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
		</div>
	<?php endif; ?>
	<span class="sc-field__error sc-coupon__msg" id="sc-points-msg" role="status" aria-live="polite"></span>
</div>
