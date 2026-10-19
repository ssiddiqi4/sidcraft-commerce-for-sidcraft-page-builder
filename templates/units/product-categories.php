<?php
/**
 * Product Categories: a grid of category cards.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/product-categories.php
 *
 * @package SidcraftCommerce
 * @version 1.8.0
 *
 * @var \WP_Term[] $terms
 * @var array      $settings
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Merchandising;
?>
<div class="sc-grid sc-cats" style="--sc-cols: <?php echo (int) $settings['columns']; ?>;">
	<?php foreach ( $terms as $sidcraft_commerce_term ) : ?>
		<?php
		$sidcraft_commerce_link  = get_term_link( $sidcraft_commerce_term );
		$sidcraft_commerce_image = Merchandising::term_image_id( $sidcraft_commerce_term->term_id );
		if ( is_wp_error( $sidcraft_commerce_link ) ) {
			continue;
		}
		?>
		<a class="sc-cat" href="<?php echo esc_url( $sidcraft_commerce_link ); ?>">
			<span class="sc-cat__media">
				<?php echo $sidcraft_commerce_image ? wp_get_attachment_image( $sidcraft_commerce_image, 'medium_large', false, array( 'loading' => 'lazy' ) ) : '<span class="sc-placeholder" aria-hidden="true"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core image HTML. ?>
			</span>
			<span class="sc-cat__name"><?php echo esc_html( $sidcraft_commerce_term->name ); ?></span>
			<?php if ( $settings['show_count'] ) : ?>
				<span class="sc-cat__count">
					<?php
					/* translators: %s: number of products. */
					echo esc_html( sprintf( _n( '%s product', '%s products', (int) $sidcraft_commerce_term->count, 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( (int) $sidcraft_commerce_term->count ) ) );
					?>
				</span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</div>
