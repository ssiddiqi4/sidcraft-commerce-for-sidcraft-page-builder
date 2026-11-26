<?php
/**
 * Add to Cart.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/add-to-cart.php
 *
 * @package SidcraftCommerce
 * @version 1.8.0
 *
 * @var \SidcraftCommerce\Data\Product $product
 * @var array                          $settings
 */

defined( 'ABSPATH' ) || exit;

// External / affiliate products are bought on another site.
if ( $product->is_external() ) {
	printf(
		'<p class="sc-atc sc-atc--external"><a class="sc-btn sc-btn--primary sc-btn--lg" href="%1$s" target="_blank" rel="nofollow noopener sponsored">%2$s</a></p>',
		esc_url( $product->get_external_url() ),
		esc_html( $product->get_button_text() )
	);
	return;
}

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
/**
 * Quantity limits for the product page's quantity box: min, max (0 = none) and step.
 *
 * @param array   $rules   [ min, max, step ].
 * @param Product $product Product.
 */
$sidcraft_commerce_rules = (array) apply_filters( 'sidcraft_commerce_quantity_rules', array( 'min' => 1, 'max' => max( 0, $sidcraft_commerce_max ), 'step' => 1 ), $product );
$sidcraft_commerce_min   = max( 1, (int) ( $sidcraft_commerce_rules['min'] ?? 1 ) );
$sidcraft_commerce_max   = (int) ( $sidcraft_commerce_rules['max'] ?? 0 );
$sidcraft_commerce_step  = max( 1, (int) ( $sidcraft_commerce_rules['step'] ?? 1 ) );
$sidcraft_commerce_text = $settings['button_text'] ? $settings['button_text'] : (string) apply_filters( 'sidcraft_commerce_button_text', __( 'Add to cart', 'sidcraft-commerce-for-sidcraft-page-builder' ), $product );
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
			<input class="sc-qty__input" type="number" name="quantity" value="<?php echo esc_attr( $sidcraft_commerce_min ); ?>" min="<?php echo esc_attr( $sidcraft_commerce_min ); ?>" <?php echo $sidcraft_commerce_max > 0 ? 'max="' . esc_attr( $sidcraft_commerce_max ) . '"' : ''; ?> step="<?php echo esc_attr( $sidcraft_commerce_step ); ?>" inputmode="numeric" aria-label="<?php esc_attr_e( 'Quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
			<button type="button" class="sc-qty__step" data-sc-qty="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">+</button>
		</div>
	<?php else : ?>
		<input type="hidden" name="quantity" value="<?php echo esc_attr( $sidcraft_commerce_min ); ?>">
	<?php endif; ?>

	<!-- Without JS this posts to the cart page, which adds and shows the cart. -->
	<button type="submit" name="sidccofo-add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" class="sc-btn sc-btn--primary sc-btn--lg" data-sc-add-to-cart data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
		<?php echo esc_html( $sidcraft_commerce_text ); ?>
	</button>

	<?php do_action( 'sidcraft_commerce_after_add_to_cart_button', $product, $settings ); ?>
	<p class="sc-atc__notice" role="status" aria-live="polite"></p>
</form>
