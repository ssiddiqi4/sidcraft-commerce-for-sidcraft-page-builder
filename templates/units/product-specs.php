<?php
/**
 * Product specifications table.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/product-specs.php
 *
 * @package SidcraftCommerce
 * @version 1.4.0
 *
 * @var \SidcraftCommerce\Data\Product $product
 * @var array                          $settings
 * @var array[]                        $rows     group, label, value, brands (optional: name, url).
 */

defined( 'ABSPATH' ) || exit;

$sidcraft_commerce_group = null;
?>
<section class="sc-specs">
	<?php if ( '' !== trim( (string) ( $settings['heading'] ?? '' ) ) ) : ?>
		<h3 class="sc-specs__title"><?php echo esc_html( $settings['heading'] ); ?></h3>
	<?php endif; ?>
	<table class="sc-specs__table">
		<?php foreach ( $rows as $sidcraft_commerce_row ) : ?>
			<?php if ( $sidcraft_commerce_row['group'] !== $sidcraft_commerce_group && '' !== $sidcraft_commerce_row['group'] ) : ?>
				<tr class="sc-specs__group"><th colspan="2" scope="colgroup"><?php echo esc_html( $sidcraft_commerce_row['group'] ); ?></th></tr>
			<?php endif; ?>
			<?php $sidcraft_commerce_group = $sidcraft_commerce_row['group']; ?>
			<tr>
				<th scope="row"><?php echo esc_html( $sidcraft_commerce_row['label'] ); ?></th>
				<td>
					<?php if ( ! empty( $sidcraft_commerce_row['brands'] ) ) : ?>
						<?php foreach ( $sidcraft_commerce_row['brands'] as $sidcraft_commerce_i => $sidcraft_commerce_brand ) : ?>
							<?php echo $sidcraft_commerce_i ? ', ' : ''; ?>
							<?php if ( '' !== $sidcraft_commerce_brand['url'] ) : ?>
								<a href="<?php echo esc_url( $sidcraft_commerce_brand['url'] ); ?>"><?php echo esc_html( $sidcraft_commerce_brand['name'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $sidcraft_commerce_brand['name'] ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php else : ?>
						<?php echo esc_html( $sidcraft_commerce_row['value'] ); ?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
</section>
