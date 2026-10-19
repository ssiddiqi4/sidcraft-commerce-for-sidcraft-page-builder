<?php
/**
 * Stock Status.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/stock-status.php
 *
 * @package SidcraftCommerce
 * @version 0.2.0
 *
 * @var \SidcraftCommerce\Data\Product $product
 * @var string     $state   in | low | out
 * @var string     $label
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="sc-stock sc-stock--<?php echo esc_attr( $state ); ?>"><span class="sc-stock__dot" aria-hidden="true"></span><?php echo esc_html( $label ); ?></p>
