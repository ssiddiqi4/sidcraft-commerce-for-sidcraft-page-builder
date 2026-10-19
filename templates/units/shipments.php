<?php
/**
 * Tracking on the order-received page.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/shipments.php
 *
 * @package SidcraftCommerce
 * @version 0.7.2
 *
 * @var array[] $shipments carrier_name, number, date, tracking_url.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="sc-shipments">
	<h3 class="sc-shipments__title"><?php echo esc_html( _n( 'Your shipment', 'Your shipments', count( $shipments ), 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></h3>
	<ul class="sc-shipments__list">
		<?php foreach ( $shipments as $sidcraft_commerce_s ) : ?>
			<li class="sc-shipment">
				<div class="sc-shipment__info">
					<strong class="sc-shipment__carrier"><?php echo esc_html( $sidcraft_commerce_s['carrier_name'] ); ?></strong>
					<span class="sc-shipment__number"><?php echo esc_html( $sidcraft_commerce_s['number'] ); ?></span>
					<span class="sc-shipment__date">
						<?php
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'Shipped %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), date_i18n( get_option( 'date_format' ), strtotime( $sidcraft_commerce_s['date'] ) ) ) );
						?>
					</span>
				</div>
				<?php if ( ! empty( $sidcraft_commerce_s['tracking_url'] ) ) : ?>
					<a class="sc-btn sc-btn--primary" href="<?php echo esc_url( $sidcraft_commerce_s['tracking_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Track package', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
