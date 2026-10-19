<?php
/**
 * Event details: date, place, online, organiser, calendar links.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/events/event-details.php
 *
 * @package SidcraftCommerce
 * @version 1.2.0
 *
 * @var \SidcraftCommerce\Events\Event $event
 * @var array                          $settings
 */

defined( 'ABSPATH' ) || exit;

$sidcraft_commerce_wa = $event->get_whatsapp_chat_url();
?>
<div class="sc-ev-details">
	<?php if ( $event->is_past() ) : ?>
		<p class="sc-notice"><?php esc_html_e( 'This event has ended.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
	<?php endif; ?>
	<dl class="sc-ev-details__list">
		<?php if ( $event->get_start() ) : ?>
			<div class="sc-ev-details__row">
				<dt><span class="sc-ev-icon" aria-hidden="true">🗓</span><?php esc_html_e( 'When', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt>
				<dd>
					<time datetime="<?php echo esc_attr( wp_date( 'c', $event->get_start() ) ); ?>"><?php echo esc_html( $event->get_date_label() ); ?></time>
					<?php if ( $event->is_online() && ! $event->is_all_day() ) : ?>
						<span class="sc-ev-muted">(<?php echo esc_html( $event->get_timezone_label() ); ?>)</span>
					<?php endif; ?>
				</dd>
			</div>
		<?php endif; ?>
		<?php if ( $event->has_location() ) : ?>
			<div class="sc-ev-details__row">
				<dt><span class="sc-ev-icon" aria-hidden="true">📍</span><?php esc_html_e( 'Where', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt>
				<dd>
					<?php if ( '' !== $event->get_venue() ) : ?>
						<strong><?php echo esc_html( $event->get_venue() ); ?></strong><br>
					<?php endif; ?>
					<?php echo esc_html( $event->get_address() ); ?>
					<a class="sc-ev-link" href="<?php echo esc_url( $event->get_directions_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get directions', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
				</dd>
			</div>
		<?php endif; ?>
		<?php if ( $event->is_online() ) : ?>
			<div class="sc-ev-details__row">
				<dt><span class="sc-ev-icon" aria-hidden="true">💻</span><?php esc_html_e( 'Online', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt>
				<dd><?php echo esc_html( '' !== $event->get_zoom()['url'] ? __( 'Join on Zoom. Your link arrives with your ticket.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'The join link arrives with your ticket.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></dd>
			</div>
		<?php endif; ?>
		<?php if ( $settings['show_organizer'] && ( '' !== $event->get_organizer() || '' !== $sidcraft_commerce_wa ) ) : ?>
			<div class="sc-ev-details__row">
				<dt><span class="sc-ev-icon" aria-hidden="true">👤</span><?php esc_html_e( 'Organiser', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt>
				<dd>
					<?php echo esc_html( $event->get_organizer() ); ?>
					<?php if ( '' !== $event->get_organizer_email() ) : ?>
						<a class="sc-ev-link" href="<?php echo esc_url( 'mailto:' . $event->get_organizer_email() ); ?>"><?php echo esc_html( $event->get_organizer_email() ); ?></a>
					<?php endif; ?>
					<?php if ( '' !== $sidcraft_commerce_wa ) : ?>
						<a class="sc-ev-wa" href="<?php echo esc_url( $sidcraft_commerce_wa ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Chat on WhatsApp', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
					<?php endif; ?>
				</dd>
			</div>
		<?php endif; ?>
	</dl>
	<?php if ( $settings['show_calendar'] && $event->get_start() && ! $event->is_past() ) : ?>
		<p class="sc-ev-addcal">
			<a class="sc-btn sc-btn--ghost" href="<?php echo esc_url( $event->get_google_calendar_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Add to Google Calendar', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			<a class="sc-btn sc-btn--ghost" href="<?php echo esc_url( $event->get_ics_url() ); ?>"><?php esc_html_e( 'Apple / Outlook (.ics)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
		</p>
	<?php endif; ?>
	<?php if ( ! empty( $settings['show_map'] ) && $event->shows_map() ) : ?>
		<?php
		$sidcraft_commerce_map_html = \SidcraftCommerce\Events\Events::template(
			'events/event-map.php',
			array(
				'event'  => $event,
				'height' => 300,
			)
		);
		echo $sidcraft_commerce_map_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
		?>
	<?php endif; ?>
</div>
