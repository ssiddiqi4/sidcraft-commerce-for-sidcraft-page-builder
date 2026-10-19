<?php
/**
 * Add to Cart.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/add-to-cart.php
 *
 * @package SidcraftCommerce
 * @version 0.9.0
 *
 * @var \SidcraftCommerce\Data\Product $product
 * @var array                          $settings
 */

defined( 'ABSPATH' ) || exit;

// Gift cards: amount, recipient and message instead of a quantity box.
if ( $product->is_gift_card() && $product->is_purchasable() ) {
	echo \SidcraftCommerce\Plugin::instance()->templates->render( 'units/gift-card-form.php', array( 'product' => $product ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in template.
	return;
}

$sidcraft_commerce_groups = $product->get_options();
$sidcraft_commerce_can    = $product->can_add_to_cart() || ( $sidcraft_commerce_groups && $product->is_purchasable() && $product->is_in_stock() );

if ( ! $sidcraft_commerce_can ) {
	printf(
		'<p class="sc-atc sc-atc--unavailable"><button type="button" class="sc-btn sc-btn--primary sc-btn--lg" disabled>%s</button></p>',
		esc_html( $product->is_in_stock() ? __( 'Unavailable', 'sidcraft-commerce-for-sidcraft-page-builder' ) : $product->add_to_cart_text() )
	);
	return;
}

$sidcraft_commerce_max  = $product->get_max_purchase_quantity(); // -1 = unlimited.
$sidcraft_commerce_text = $settings['button_text'] ? $settings['button_text'] : __( 'Add to cart', 'sidcraft-commerce-for-sidcraft-page-builder' );
?>
<form class="sc-atc<?php echo $settings['full_width'] ? ' sc-atc--full' : ''; ?><?php echo $sidcraft_commerce_groups ? ' sc-atc--options' : ''; ?>" data-sc-atc-form action="<?php echo esc_url( \SidcraftCommerce\page_url( 'cart' ) ); ?>" method="post">
	<?php
	echo \SidcraftCommerce\Plugin::instance()->templates->render( 'units/price-tiers.php', array( 'product' => $product ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
	if ( $sidcraft_commerce_groups ) {
		$sidcraft_commerce_options = \SidcraftCommerce\Plugin::instance()->templates->render(
			'units/item-options.php',
			array(
				'product' => $product,
				'groups'  => $sidcraft_commerce_groups,
			)
		);
		echo $sidcraft_commerce_options; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
	}
	do_action( 'sidcraft_commerce_before_add_to_cart_button', $product, $settings );
	?>

	<?php if ( $settings['show_quantity'] ) : ?>
		<div class="sc-qty">
			<button type="button" class="sc-qty__step" data-sc-qty="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">−</button>
			<input class="sc-qty__input" type="number" name="quantity" value="1" min="1" <?php echo $sidcraft_commerce_max > 0 ? 'max="' . esc_attr( $sidcraft_commerce_max ) . '"' : ''; ?> step="1" inputmode="numeric" aria-label="<?php esc_attr_e( 'Quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
			<button type="button" class="sc-qty__step" data-sc-qty="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">+</button>
		</div>
	<?php else : ?>
		<input type="hidden" name="quantity" value="1">
	<?php endif; ?>

	<!-- Without JS this posts to the cart page, which adds and shows the cart. -->
	<button type="submit" name="sidccofo-add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" class="sc-btn sc-btn--primary sc-btn--lg" data-sc-add-to-cart data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
		<?php echo esc_html( $sidcraft_commerce_text ); ?>
	</button>

	<?php do_action( 'sidcraft_commerce_after_add_to_cart_button', $product, $settings ); ?>
	<p class="sc-atc__notice" role="status" aria-live="polite"></p>
</form>
