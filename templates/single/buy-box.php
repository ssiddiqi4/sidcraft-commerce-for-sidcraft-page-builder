<?php
/**
 * Buy box inserted above product content on single product pages
 * (Store → Settings → "Auto-insert buy box"). Built from the Units, so it
 * looks the same as a Sidcraft-built product layout.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/single/buy-box.php
 *
 * @package SidcraftCommerce
 * @version 0.2.0
 *
 * @var int $product_id
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Plugin;

$sidcraft_commerce_units = Plugin::instance()->units;
$sidcraft_commerce_ctx   = array( 'product_id' => $product_id );
?>
<div class="sc-buy-box">
	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Unit output is escaped in templates.
	foreach ( (array) apply_filters( 'sidcraft_commerce_buy_box_units', array( 'sidccofo-product-rating', 'sidccofo-product-price', 'sidccofo-stock-status', 'sidccofo-add-to-cart' ) ) as $sidcraft_commerce_name ) {
		$sidcraft_commerce_unit = $sidcraft_commerce_units->get( $sidcraft_commerce_name );
		echo $sidcraft_commerce_unit ? $sidcraft_commerce_unit->render( $sidcraft_commerce_ctx ) : '';
	}
	// phpcs:enable
	?>
</div>
