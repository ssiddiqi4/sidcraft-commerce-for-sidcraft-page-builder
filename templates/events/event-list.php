<?php
/**
 * Event list (cards or rows).
 *
 * Override by copying to: yourtheme/sidcraft-commerce/events/event-list.php
 *
 * @package SidcraftCommerce
 * @version 1.2.0
 *
 * @var \SidcraftCommerce\Events\Event[] $events
 * @var array                            $settings
 */

defined( 'ABSPATH' ) || exit;

if ( ! $events ) {
	echo '<p class="sc-empty">' . esc_html( $settings['empty_text'] ) . '</p>';
	return;
}
$sidcraft_commerce_cols = max( 1, min( 4, (int) $settings['columns'] ) );
?>
<div class="sc-events sc-events--<?php echo esc_attr( $settings['layout'] ); ?>" style="--sc-ev-cols:<?php echo (int) $sidcraft_commerce_cols; ?>">
	<?php foreach ( $events as $sidcraft_commerce_event ) : ?>
		<?php
		$sidcraft_commerce_start = $sidcraft_commerce_event->get_start();
		$sidcraft_commerce_url   = $sidcraft_commerce_event->get_permalink();
		$sidcraft_commerce_state = '';
		if ( $sidcraft_commerce_event->is_past() ) {
			$sidcraft_commerce_state = __( 'Ended', 'sidcraft-commerce-for-sidcraft-page-builder' );
		} elseif ( $sidcraft_commerce_event->is_sold_out() ) {
			$sidcraft_commerce_state = __( 'Sold out', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		?>
		<article class="sc-ev-card<?php echo $sidcraft_commerce_event->is_past() ? ' is-past' : ''; ?>">
			<?php if ( $settings['show_image'] && 'grid' === $settings['layout'] ) : ?>
				<a class="sc-ev-card__media" href="<?php echo esc_url( $sidcraft_commerce_url ); ?>" tabindex="-1" aria-hidden="true">
					<?php
					if ( has_post_thumbnail( $sidcraft_commerce_event->get_id() ) ) {
						echo get_the_post_thumbnail( $sidcraft_commerce_event->get_id(), 'medium_large', array( 'loading' => 'lazy' ) );
					} else {
						echo '<span class="sc-placeholder"></span>';
					}
					?>
					<?php if ( '' !== $sidcraft_commerce_state ) : ?>
						<span class="sc-ev-card__state"><?php echo esc_html( $sidcraft_commerce_state ); ?></span>
					<?php endif; ?>
				</a>
			<?php endif; ?>
			<div class="sc-ev-card__body">
				<?php if ( $sidcraft_commerce_start ) : ?>
					<time class="sc-ev-date" datetime="<?php echo esc_attr( wp_date( 'c', $sidcraft_commerce_start ) ); ?>">
						<span class="sc-ev-date__m"><?php echo esc_html( wp_date( 'M', $sidcraft_commerce_start ) ); ?></span>
						<span class="sc-ev-date__d"><?php echo esc_html( wp_date( 'j', $sidcraft_commerce_start ) ); ?></span>
					</time>
				<?php endif; ?>
				<div class="sc-ev-card__main">
					<h3 class="sc-ev-card__title"><a href="<?php echo esc_url( $sidcraft_commerce_url ); ?>"><?php echo esc_html( $sidcraft_commerce_event->get_name() ); ?></a></h3>
					<p class="sc-ev-card__meta">
						<span><?php echo esc_html( $sidcraft_commerce_event->get_date_label() ); ?></span>
						<?php if ( $sidcraft_commerce_event->has_location() ) : ?>
							<span><?php echo esc_html( '' !== $sidcraft_commerce_event->get_venue() ? $sidcraft_commerce_event->get_venue() : $sidcraft_commerce_event->get_address() ); ?></span>
						<?php elseif ( $sidcraft_commerce_event->is_online() ) : ?>
							<span><?php esc_html_e( 'Online event', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( $settings['show_excerpt'] ) : ?>
						<p class="sc-ev-card__summary"><?php echo esc_html( $sidcraft_commerce_event->get_summary( 22 ) ); ?></p>
					<?php endif; ?>
					<div class="sc-ev-card__foot">
						<?php if ( $settings['show_price'] && '' !== $sidcraft_commerce_event->get_price_label() ) : ?>
							<span class="sc-ev-card__price"><?php echo esc_html( $sidcraft_commerce_event->get_price_label() ); ?></span>
						<?php endif; ?>
						<a class="sc-btn sc-btn--primary" href="<?php echo esc_url( $sidcraft_commerce_url ); ?>"><?php echo esc_html( '' !== $sidcraft_commerce_state ? __( 'View event', 'sidcraft-commerce-for-sidcraft-page-builder' ) : $settings['button_text'] ); ?></a>
					</div>
				</div>
			</div>
		</article>
	<?php endforeach; ?>
</div>
