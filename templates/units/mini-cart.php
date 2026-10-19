<?php
/**
 * Mini Cart — cache-safe placeholder. Count and subtotal are replaced by
 * cart fragments (see includes/class-fragments.php). Keep the
 * span.sc-mini-cart__count / span.sc-mini-cart__total elements if you override.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/mini-cart.php
 *
 * @package SidcraftCommerce
 * @version 0.2.0
 *
 * @var array  $settings
 * @var string $cart_url
 */

defined( 'ABSPATH' ) || exit;
?>
<a class="sc-mini-cart" href="<?php echo esc_url( $cart_url ); ?>" aria-label="<?php echo esc_attr( $settings['label'] ); ?>">
	<svg class="sc-mini-cart__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/><circle cx="10" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/></svg>
	<span class="sc-mini-cart__count" data-count="0">0</span>
	<?php if ( $settings['show_total'] ) : ?>
		<span class="sc-mini-cart__total"></span>
	<?php endif; ?>
</a>
