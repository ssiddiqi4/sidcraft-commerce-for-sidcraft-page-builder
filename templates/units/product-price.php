<?php
/**
 * Product Price.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/product-price.php
 *
 * @package SidcraftCommerce
 * @version 0.2.0
 *
 * @var \SidcraftCommerce\Data\Product $product
 * @var array      $settings
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="sc-price sc-price--<?php echo esc_attr( $settings['size'] ); ?>"><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Product. ?></p>
