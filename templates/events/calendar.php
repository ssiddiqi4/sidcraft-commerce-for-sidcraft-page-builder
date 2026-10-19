<?php
/**
 * Month calendar of events.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/events/calendar.php
 *
 * @package SidcraftCommerce
 * @version 1.2.0
 *
 * @var array $data     month, label, prev, next, weeks, weekdays, events.
 * @var array $settings Unit settings.
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Events\Calendar;

$sidcraft_commerce_base = remove_query_arg( 'scev_month' );
?>
<div class="sc-ev-cal">
	<div class="sc-ev-cal__head">
		<a class="sc-btn sc-btn--ghost sc-btn--icon" href="<?php echo esc_url( add_query_arg( 'scev_month', $data['prev'], $sidcraft_commerce_base ) ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" rel="nofollow">‹</a>
		<h3 class="sc-ev-cal__title" aria-live="polite"><?php echo esc_html( $data['label'] ); ?></h3>
		<a class="sc-btn sc-btn--ghost sc-btn--icon" href="<?php echo esc_url( add_query_arg( 'scev_month', $data['next'], $sidcraft_commerce_base ) ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" rel="nofollow">›</a>
	</div>
	<table class="sc-ev-cal__grid">
		<thead><tr>
			<?php foreach ( $data['weekdays'] as $sidcraft_commerce_wd ) : ?>
				<th scope="col"><?php echo esc_html( $sidcraft_commerce_wd ); ?></th>
			<?php endforeach; ?>
		</tr></thead>
		<tbody>
			<?php foreach ( $data['weeks'] as $sidcraft_commerce_week ) : ?>
				<tr>
					<?php foreach ( $sidcraft_commerce_week as $sidcraft_commerce_day ) : ?>
						<td class="<?php echo esc_attr( trim( ( $sidcraft_commerce_day['in'] ? '' : 'is-out ' ) . ( $sidcraft_commerce_day['today'] ? 'is-today ' : '' ) . ( $sidcraft_commerce_day['events'] ? 'has-events' : '' ) ) ); ?>">
							<span class="sc-ev-cal__day"><?php echo (int) $sidcraft_commerce_day['day']; ?></span>
							<?php foreach ( $sidcraft_commerce_day['events'] as $sidcraft_commerce_e ) : ?>
								<a class="sc-ev-cal__event" href="<?php echo esc_url( $sidcraft_commerce_e->get_permalink() ); ?>" title="<?php echo esc_attr( $sidcraft_commerce_e->get_name() . ' · ' . $sidcraft_commerce_e->get_date_label() ); ?>">
									<?php if ( ! $sidcraft_commerce_e->is_all_day() && wp_date( 'Y-m-d', $sidcraft_commerce_e->get_start() ) === $sidcraft_commerce_day['date'] ) : ?>
										<span class="sc-ev-cal__time"><?php echo esc_html( wp_date( get_option( 'time_format' ), $sidcraft_commerce_e->get_start() ) ); ?></span>
									<?php endif; ?>
									<?php echo esc_html( $sidcraft_commerce_e->get_name() ); ?>
								</a>
							<?php endforeach; ?>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $settings['show_list'] ) : ?>
		<?php
		$sidcraft_commerce_month_events = array_filter(
			$data['events'],
			static function ( $e ) use ( $data ) {
				return wp_date( 'Y-m', $e->get_start() ) === $data['month'] || wp_date( 'Y-m', $e->get_end() ) === $data['month'];
			}
		);
		?>
		<ul class="sc-ev-cal__list">
			<?php if ( ! $sidcraft_commerce_month_events ) : ?>
				<li class="sc-empty"><?php esc_html_e( 'No events this month.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></li>
			<?php endif; ?>
			<?php foreach ( $sidcraft_commerce_month_events as $sidcraft_commerce_e ) : ?>
				<li>
					<a href="<?php echo esc_url( $sidcraft_commerce_e->get_permalink() ); ?>"><strong><?php echo esc_html( $sidcraft_commerce_e->get_name() ); ?></strong></a>
					<span><?php echo esc_html( $sidcraft_commerce_e->get_date_label() ); ?></span>
					<?php if ( '' !== $sidcraft_commerce_e->get_price_label() && ! $sidcraft_commerce_e->is_past() ) : ?>
						<span class="sc-ev-card__price"><?php echo esc_html( $sidcraft_commerce_e->get_price_label() ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $settings['show_subscribe'] ) : ?>
		<p class="sc-ev-cal__subscribe">
			<?php esc_html_e( 'Subscribe:', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
			<a href="<?php echo esc_url( Calendar::google_subscribe_url( (string) $settings['category'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Google Calendar', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			· <a href="<?php echo esc_url( Calendar::feed_url( (string) $settings['category'], true ), array( 'webcal', 'http', 'https' ) ); ?>"><?php esc_html_e( 'Apple / Outlook', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			· <a href="<?php echo esc_url( Calendar::feed_url( (string) $settings['category'] ) ); ?>"><?php esc_html_e( 'iCal feed', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
		</p>
	<?php endif; ?>
</div>
