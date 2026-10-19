<?php
/**
 * Download links table (order-received page and the My Downloads Unit).
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/downloads.php
 *
 * @package SidcraftCommerce
 * @version 1.1.0
 *
 * @var array[] $rows       From Downloads::rows(): name, product, url, remaining, expires, usable, reason, order_id.
 * @var bool    $show_order Add an Order column.
 * @var string  $heading    Heading ('' for none).
 * @var string  $empty      Text when there are no rows.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="sc-downloads">
	<?php if ( '' !== $heading ) : ?>
		<h3 class="sc-downloads__title"><?php echo esc_html( $heading ); ?></h3>
	<?php endif; ?>
	<?php if ( ! $rows ) : ?>
		<p class="sc-empty"><?php echo esc_html( $empty ); ?></p>
	<?php else : ?>
		<table class="sc-table sc-downloads__table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'File', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<?php if ( $show_order ) : ?>
						<th scope="col"><?php esc_html_e( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'Downloads left', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expires', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Download', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $sidcraft_commerce_row ) : ?>
					<tr class="<?php echo $sidcraft_commerce_row['usable'] ? '' : 'is-unavailable'; ?>">
						<td data-label="<?php esc_attr_e( 'File', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
							<strong><?php echo esc_html( $sidcraft_commerce_row['name'] ); ?></strong>
							<?php if ( '' !== $sidcraft_commerce_row['product'] && $sidcraft_commerce_row['product'] !== $sidcraft_commerce_row['name'] ) : ?>
								<span class="sc-downloads__product"><?php echo esc_html( $sidcraft_commerce_row['product'] ); ?></span>
							<?php endif; ?>
						</td>
						<?php if ( $show_order ) : ?>
							<td data-label="<?php esc_attr_e( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">#<?php echo esc_html( (string) $sidcraft_commerce_row['order_id'] ); ?></td>
						<?php endif; ?>
						<td data-label="<?php esc_attr_e( 'Downloads left', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"><?php echo esc_html( $sidcraft_commerce_row['remaining'] ); ?></td>
						<td data-label="<?php esc_attr_e( 'Expires', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"><?php echo esc_html( $sidcraft_commerce_row['expires'] ); ?></td>
						<td class="sc-downloads__action">
							<?php if ( $sidcraft_commerce_row['usable'] ) : ?>
								<a class="sc-btn sc-btn--primary" href="<?php echo esc_url( $sidcraft_commerce_row['url'] ); ?>" rel="nofollow"><?php esc_html_e( 'Download', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
							<?php else : ?>
								<span class="sc-downloads__reason"><?php echo esc_html( $sidcraft_commerce_row['reason'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
