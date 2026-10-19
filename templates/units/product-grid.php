<?php
/**
 * Product Grid.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/product-grid.php
 *
 * @package SidcraftCommerce
 * @version 0.2.0
 *
 * @var \SidcraftCommerce\Data\Product[] $products
 * @var array        $settings
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Plugin;
?>
<div class="sc-grid" style="--sc-cols: <?php echo (int) $settings['columns']; ?>;">
	<?php do_action( 'sidcraft_commerce_before_product_grid', $products, $settings ); ?>

	<?php foreach ( $products as $sidcraft_commerce_product ) : ?>
		<?php
		// Each card is its own overridable template: loop/product-card.php.
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output is escaped inside.
		echo Plugin::instance()->templates->render(
			'loop/product-card.php',
			array(
				'product'  => $sidcraft_commerce_product,
				'settings' => $settings,
			)
		);
		// phpcs:enable
		?>
	<?php endforeach; ?>

	<?php do_action( 'sidcraft_commerce_after_product_grid', $products, $settings ); ?>
</div>
