<?php
/**
 * Product Rating: clickable stars (each opens the verified-review popup)
 * and the review count.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/product-rating.php
 *
 * @package SidcraftCommerce
 * @version 0.6.0
 *
 * @var \SidcraftCommerce\Data\Product $product
 * @var array      $settings
 */

defined( 'ABSPATH' ) || exit;

use function SidcraftCommerce\rate_stars_markup;
use function SidcraftCommerce\star_markup;

$sidcraft_commerce_count = $product->get_review_count();
?>
<div class="sc-rating">
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helpers.
	echo $settings['clickable'] ? rate_stars_markup( $product ) : star_markup( $product->get_average_rating() );
	?>
	<?php if ( $settings['show_count'] ) : ?>
		<a class="sc-rating__count" href="<?php echo esc_url( $product->get_permalink() . '#reviews' ); ?>">
			<?php
			echo esc_html(
				$sidcraft_commerce_count
					/* translators: %s: number of reviews. */
					? sprintf( _n( '%s review', '%s reviews', $sidcraft_commerce_count, 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( $sidcraft_commerce_count ) )
					: __( 'Be the first to review', 'sidcraft-commerce-for-sidcraft-page-builder' )
			);
			?>
		</a>
	<?php endif; ?>
</div>
