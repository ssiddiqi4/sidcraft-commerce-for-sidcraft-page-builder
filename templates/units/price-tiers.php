<?php
/**
 * Price rules the shopper can use: the special running now (with its end
 * date) and quantity discounts ("Buy 10 or more: $18.00 each").
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/price-tiers.php
 *
 * @package SidcraftCommerce
 * @version 1.4.0
 *
 * @var \SidcraftCommerce\Data\Product $product
 */

defined( 'ABSPATH' ) || exit;

use function SidcraftCommerce\format_price;

$sidcraft_commerce_tiers = $product->get_quantity_tiers();
$sidcraft_commerce_rule  = $product->get_rule_price( 1 )['rule'];
$sidcraft_commerce_offer = '';
if ( $sidcraft_commerce_rule ) {
	$sidcraft_commerce_offer = $sidcraft_commerce_rule['label'];
	if ( $sidcraft_commerce_rule['until'] ) {
		/* translators: %s: date and time. */
		$sidcraft_commerce_ends  = sprintf( __( 'Ends %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sidcraft_commerce_rule['until'] ) );
		$sidcraft_commerce_offer = '' !== $sidcraft_commerce_offer ? $sidcraft_commerce_offer . ' · ' . $sidcraft_commerce_ends : $sidcraft_commerce_ends;
	}
}
if ( ! $sidcraft_commerce_tiers && '' === $sidcraft_commerce_offer ) {
	return;
}
?>
<div class="sc-tiers">
	<?php if ( '' !== $sidcraft_commerce_offer ) : ?>
		<p class="sc-tiers__offer"><?php echo esc_html( $sidcraft_commerce_offer ); ?></p>
	<?php endif; ?>
	<?php if ( $sidcraft_commerce_tiers ) : ?>
		<ul class="sc-tiers__list" aria-label="<?php esc_attr_e( 'Quantity discounts', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
			<?php foreach ( $sidcraft_commerce_tiers as $sidcraft_commerce_tier ) : ?>
				<li>
					<?php
					/* translators: 1: quantity, 2: unit price. */
					echo esc_html( sprintf( __( 'Buy %1$d or more: %2$s each', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_tier['qty'], format_price( $sidcraft_commerce_tier['price'] ) ) );
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
