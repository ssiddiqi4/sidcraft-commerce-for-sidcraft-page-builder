<?php
/**
 * Cart panel (also re-rendered as a fragment after each change).
 * Keep the outer div.sc-cart if you override: JS swaps it in place.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/cart-panel.php
 *
 * @package SidcraftCommerce
 * @version 1.8.0
 *
 * @var \SidcraftCommerce\Cart\Cart $cart
 * @var string                      $empty_text Optional.
 * @var string                      $notice     Optional.
 */

defined( 'ABSPATH' ) || exit;

use function SidcraftCommerce\format_price;
use function SidcraftCommerce\page_url;
use function SidcraftCommerce\totals_rows;

$sidcraft_commerce_items = $cart->get_items();
$sidcraft_commerce_empty = isset( $empty_text ) && '' !== $empty_text ? $empty_text : __( 'Your cart is empty.', 'sidcraft-commerce-for-sidcraft-page-builder' );
?>
<div class="sc-cart" data-sc-cart>
	<?php if ( ! empty( $notice ) ) : ?>
		<p class="sc-notice sc-notice--error" role="alert"><?php echo esc_html( $notice ); ?></p>
	<?php endif; ?>
	<p class="sc-cart__status" role="status" aria-live="polite"></p>

	<?php if ( ! $sidcraft_commerce_items ) : ?>
		<div class="sc-cart__empty">
			<p><?php echo esc_html( $sidcraft_commerce_empty ); ?></p>
			<a class="sc-btn sc-btn--primary" href="<?php echo esc_url( page_url( 'shop' ) ); ?>"><?php esc_html_e( 'Continue shopping', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
		</div>
	<?php else : ?>
		<div class="sc-cart__layout">
			<ul class="sc-cart__items">
				<?php foreach ( $sidcraft_commerce_items as $sidcraft_commerce_line ) : ?>
					<?php
					$sidcraft_commerce_p   = $sidcraft_commerce_line['product'];
					$sidcraft_commerce_max = $sidcraft_commerce_p->get_max_purchase_quantity();
					?>
					<li class="sc-cart__item" data-product-id="<?php echo esc_attr( $sidcraft_commerce_line['product_id'] ); ?>" data-line-key="<?php echo esc_attr( $sidcraft_commerce_line['key'] ?? '' ); ?>">
						<a class="sc-cart__thumb" href="<?php echo esc_url( $sidcraft_commerce_p->get_permalink() ); ?>" tabindex="-1" aria-hidden="true"><?php echo ! empty( $sidcraft_commerce_line['image_id'] ) ? wp_get_attachment_image( (int) $sidcraft_commerce_line['image_id'], 'thumbnail' ) : $sidcraft_commerce_p->get_image( 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
						<div class="sc-cart__info">
							<a class="sc-cart__name" href="<?php echo esc_url( $sidcraft_commerce_p->get_permalink() ); ?>"><?php echo esc_html( $sidcraft_commerce_p->get_name() ); ?></a>
							<?php if ( ! empty( $sidcraft_commerce_line['gift_card'] ) ) : ?>
								<?php $sidcraft_commerce_g = $sidcraft_commerce_line['gift_card']; ?>
								<span class="sc-cart__gift">
									<?php
									/* translators: %s: recipient. */
									echo esc_html( sprintf( __( 'For %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), '' !== $sidcraft_commerce_g['recipient_name'] ? $sidcraft_commerce_g['recipient_name'] . ' (' . $sidcraft_commerce_g['recipient_email'] . ')' : $sidcraft_commerce_g['recipient_email'] ) );
									if ( '' !== $sidcraft_commerce_g['send_date'] ) {
										/* translators: %s: date. */
										echo ' · ' . esc_html( sprintf( __( 'sends %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), date_i18n( get_option( 'date_format' ), strtotime( $sidcraft_commerce_g['send_date'] ) ) ) );
									}
									?>
								</span>
							<?php endif; ?>
							<?php echo \SidcraftCommerce\item_options_html( $sidcraft_commerce_line['options'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in item_options_html(). ?>
							<?php echo \SidcraftCommerce\Subscriptions::line_html( $sidcraft_commerce_line ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in line_html(). ?>
							<span class="sc-cart__unit"><?php echo esc_html( format_price( $sidcraft_commerce_line['price'] ) ); ?><?php if ( $sidcraft_commerce_p->is_on_sale() && empty( $sidcraft_commerce_line['options'] ) && empty( $sidcraft_commerce_line['gift_card'] ) ) : ?><del aria-hidden="true"><?php echo esc_html( format_price( $sidcraft_commerce_p->get_display_regular_price() ) ); ?></del><?php endif; ?></span>
						</div>
						<div class="sc-qty sc-qty--sm">
							<button type="button" class="sc-qty__step" data-sc-qty="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">−</button>
							<input class="sc-qty__input" type="number" value="<?php echo esc_attr( $sidcraft_commerce_line['quantity'] ); ?>" min="0" <?php echo $sidcraft_commerce_max > 0 ? 'max="' . esc_attr( $sidcraft_commerce_max ) . '"' : ''; ?> data-sc-cart-qty aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product. */ __( 'Quantity of %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_p->get_name() ) ); ?>">
							<button type="button" class="sc-qty__step" data-sc-qty="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">+</button>
						</div>
						<span class="sc-cart__line"><?php echo esc_html( format_price( $sidcraft_commerce_line['total'] ) ); ?></span>
						<button type="button" class="sc-cart__remove" data-sc-remove aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product. */ __( 'Remove %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_p->get_name() ) ); ?>">×</button>
					</li>
				<?php endforeach; ?>
			</ul>

			<aside class="sc-cart__totals">
				<dl>
					<?php foreach ( totals_rows( $cart ) as $sidcraft_commerce_row ) : ?>
						<div class="<?php echo esc_attr( trim( 'sc-totals__' . $sidcraft_commerce_row['key'] . ' ' . $sidcraft_commerce_row['class'] ) ); ?>"><dt><?php echo esc_html( $sidcraft_commerce_row['label'] ); ?></dt><dd><?php echo esc_html( $sidcraft_commerce_row['value'] ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
				<?php echo \SidcraftCommerce\Plugin::instance()->templates->render( 'units/coupon-form.php', array( 'cart' => $cart ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in template. ?>
				<?php echo \SidcraftCommerce\Plugin::instance()->templates->render( 'units/gift-card-redeem.php', array( 'cart' => $cart ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in template. ?>
				<a class="sc-btn sc-btn--primary sc-btn--lg sc-btn--block" href="<?php echo esc_url( page_url( 'checkout' ) ); ?>"><?php esc_html_e( 'Proceed to checkout', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
				<a class="sc-cart__continue" href="<?php echo esc_url( page_url( 'shop' ) ); ?>"><?php esc_html_e( '← Continue shopping', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			</aside>
		</div>
		<?php
		// Suggestions from the cross-sells of what's in the cart.
		echo \SidcraftCommerce\Merchandising::products_section( \SidcraftCommerce\Merchandising::cart_cross_sells( $cart ), __( 'You may also like', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'cross-sells' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the grid templates.
		?>
	<?php endif; ?>
</div>
