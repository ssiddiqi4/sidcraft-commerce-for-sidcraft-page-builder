<?php
/**
 * Quick view modal body (loaded over REST).
 *
 * Override by copying to: yourtheme/sidcraft-commerce/quick-view.php
 *
 * @package SidcraftCommerce
 * @version 1.8.0
 *
 * @var \SidcraftCommerce\Data\Product $product
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Plugin;

$sidcraft_commerce_units = Plugin::instance()->units;
$sidcraft_commerce_ctx   = array( 'product_id' => $product->get_id() );
?>
<div class="sc-qv">
	<div class="sc-qv__media sc-media-badged<?php echo $product->is_in_stock() ? '' : ' is-sold-out'; ?>" data-sc-product-image>
		<?php echo $product->get_image( 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core image HTML. ?>
		<?php echo $product->get_image_overlay_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Product. ?>
	</div>
	<div class="sc-qv__info">
		<h2 class="sc-qv__title" id="sc-qv-title"><?php echo esc_html( $product->get_name() ); ?></h2>
		<?php
		// Re-use the single-product Units so the modal matches the product page.
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Unit output is escaped in templates.
		foreach ( array( 'sidccofo-product-rating', 'sidccofo-product-price', 'sidccofo-stock-status', 'sidccofo-add-to-cart' ) as $sidcraft_commerce_name ) {
			$sidcraft_commerce_unit = $sidcraft_commerce_units->get( $sidcraft_commerce_name );
			if ( 'sidccofo-add-to-cart' === $sidcraft_commerce_name ) {
				echo '<div class="sc-qv__excerpt">' . wp_kses_post( wpautop( $product->get_short_description() ) ) . '</div>';
			}
			echo $sidcraft_commerce_unit ? $sidcraft_commerce_unit->render( $sidcraft_commerce_ctx ) : '';
		}
		// phpcs:enable
		?>
		<a class="sc-qv__more" href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php esc_html_e( 'View full details →', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
	</div>
</div>
