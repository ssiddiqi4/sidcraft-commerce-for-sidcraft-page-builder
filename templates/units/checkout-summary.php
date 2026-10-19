<?php
/**
 * Checkout order summary (also refreshed as a fragment).
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/checkout-summary.php
 *
 * @package SidcraftCommerce
 * @version 1.6.0
 *
 * @var \SidcraftCommerce\Cart\Cart $cart
 */

defined( 'ABSPATH' ) || exit;

use function SidcraftCommerce\format_price;
use function SidcraftCommerce\totals_rows;
?>
<div class="sc-checkout-summary" data-total="<?php echo esc_attr( number_format( (float) $cart->get_total(), 2, '.', '' ) ); ?>">
	<h3 class="sc-checkout__heading"><?php esc_html_e( 'Your order', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h3>
	<ul class="sc-summary__items">
		<?php foreach ( $cart->get_items() as $sidcraft_commerce_line ) : ?>
			<li>
				<span class="sc-summary__name"><?php echo esc_html( $sidcraft_commerce_line['product']->get_name() ); ?> <span class="sc-summary__qty">× <?php echo esc_html( $sidcraft_commerce_line['quantity'] ); ?></span><?php echo \SidcraftCommerce\item_options_html( $sidcraft_commerce_line['options'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in item_options_html(). ?><?php echo \SidcraftCommerce\Subscriptions::line_html( $sidcraft_commerce_line ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in line_html(). ?></span>
				<span><?php echo esc_html( format_price( $sidcraft_commerce_line['total'] ) ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>
	<dl class="sc-summary__totals">
		<?php foreach ( totals_rows( $cart ) as $sidcraft_commerce_row ) : ?>
			<div class="<?php echo esc_attr( trim( 'sc-totals__' . $sidcraft_commerce_row['key'] . ' ' . $sidcraft_commerce_row['class'] ) ); ?>"><dt><?php echo esc_html( $sidcraft_commerce_row['label'] ); ?></dt><dd><?php echo esc_html( $sidcraft_commerce_row['value'] ); ?></dd></div>
		<?php endforeach; ?>
	</dl>
	<?php echo \SidcraftCommerce\Plugin::instance()->templates->render( 'units/coupon-form.php', array( 'cart' => $cart ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in template. ?>
	<?php echo \SidcraftCommerce\Plugin::instance()->templates->render( 'units/gift-card-redeem.php', array( 'cart' => $cart ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in template. ?>
	<?php echo \SidcraftCommerce\Plugin::instance()->templates->render( 'units/points-redeem.php', array( 'cart' => $cart ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in template. ?>
</div>
