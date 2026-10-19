<?php
/**
 * Ticket rows (order-received page, My Tickets).
 *
 * Override by copying to: yourtheme/sidcraft-commerce/events/ticket-rows.php
 *
 * @package SidcraftCommerce
 * @version 1.2.0
 *
 * @var \SidcraftCommerce\Events\Ticket[] $tickets
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Events\QR;
?>
<ul class="sc-ev-ticket-rows">
	<?php foreach ( $tickets as $sidcraft_commerce_t ) : ?>
		<?php $sidcraft_commerce_e = $sidcraft_commerce_t->get_event(); ?>
		<li class="sc-ev-ticket-row is-<?php echo esc_attr( $sidcraft_commerce_t->get_status() ); ?>">
			<a class="sc-ev-ticket-row__qr" href="<?php echo esc_url( $sidcraft_commerce_t->get_url() ); ?>" aria-label="<?php esc_attr_e( 'Open ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
				<?php echo QR::svg( $sidcraft_commerce_t->get_qr_payload(), 72 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated SVG (numbers only). ?>
			</a>
			<div class="sc-ev-ticket-row__info">
				<strong><?php echo esc_html( $sidcraft_commerce_e ? $sidcraft_commerce_e->get_name() : '' ); ?></strong>
				<span><?php echo esc_html( $sidcraft_commerce_e ? $sidcraft_commerce_e->get_date_label() : '' ); ?></span>
				<span><?php echo esc_html( $sidcraft_commerce_t->get_type_name() . ' · ' . $sidcraft_commerce_t->get_attendee_name() ); ?></span>
				<code><?php echo esc_html( $sidcraft_commerce_t->get_code() ); ?></code>
				<?php if ( 'valid' !== $sidcraft_commerce_t->get_status() ) : ?>
					<span class="sc-ev-status sc-ev-status--<?php echo esc_attr( $sidcraft_commerce_t->get_status() ); ?>"><?php echo esc_html( $sidcraft_commerce_t->get_status_label() ); ?></span>
				<?php endif; ?>
			</div>
			<div class="sc-ev-ticket-row__actions">
				<a class="sc-btn sc-btn--ghost" href="<?php echo esc_url( $sidcraft_commerce_t->get_url() ); ?>"><?php esc_html_e( 'View', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
				<a class="sc-btn sc-btn--ghost" href="<?php echo esc_url( $sidcraft_commerce_t->get_pdf_url() ); ?>"><?php esc_html_e( 'PDF', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			</div>
		</li>
	<?php endforeach; ?>
</ul>
