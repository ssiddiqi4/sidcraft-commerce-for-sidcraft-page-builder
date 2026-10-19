<?php
/**
 * Ticket page (standalone). Opened from the ticket email, the PDF or by
 * scanning the QR code. Staff who can check in see a check-in panel.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/events/ticket-page.php
 *
 * @package SidcraftCommerce
 * @version 1.2.0
 *
 * @var \SidcraftCommerce\Events\Ticket     $ticket
 * @var \SidcraftCommerce\Events\Event|null $event
 * @var bool                                $staff
 * @var string                              $message Check-in result message.
 * @var string                              $result  ok | already | cancelled | undone | ''.
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Events\Barcode;
use SidcraftCommerce\Events\Integrations;
use SidcraftCommerce\Events\QR;
use function SidcraftCommerce\setting;

\SidcraftCommerce\Plugin::instance()->assets->register();
$sidcraft_commerce_status = $ticket->get_status();
$sidcraft_commerce_kind   = (string) setting( 'ev_code_type', 'qr' );
$sidcraft_commerce_valid  = 'cancelled' !== $sidcraft_commerce_status;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( ( $event ? $event->get_name() . ' – ' : '' ) . __( 'Ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></title>
	<?php wp_print_styles( array( 'sidccofo-commerce', 'sidccofo-events' ) ); ?>
</head>
<body class="sc-ev-ticket-body">
<main class="sc-unit sc-ev-ticket">
	<p class="sc-ev-ticket__site"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></p>

	<?php if ( $staff && '' !== $message ) : ?>
		<div class="sc-ev-scan-result sc-ev-scan-result--<?php echo esc_attr( $result ); ?>" role="status"><?php echo esc_html( $message ); ?></div>
	<?php endif; ?>

	<article class="sc-ev-ticket__card is-<?php echo esc_attr( $sidcraft_commerce_status ); ?>">
		<header class="sc-ev-ticket__head">
			<h1><?php echo esc_html( $event ? $event->get_name() : __( 'Event', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></h1>
			<?php if ( $event ) : ?>
				<p><?php echo esc_html( $event->get_date_label() . ( $event->is_online() && ! $event->is_all_day() ? ' (' . $event->get_timezone_label() . ')' : '' ) ); ?></p>
				<?php if ( $event->has_location() ) : ?>
					<p><a href="<?php echo esc_url( $event->get_directions_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $event->get_location_label() ); ?></a></p>
				<?php endif; ?>
			<?php endif; ?>
		</header>

		<div class="sc-ev-ticket__code">
			<?php if ( 'barcode' !== $sidcraft_commerce_kind ) : ?>
				<?php echo QR::svg( $ticket->get_qr_payload(), 260 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated SVG (numbers only). ?>
			<?php endif; ?>
			<?php if ( 'qr' !== $sidcraft_commerce_kind ) : ?>
				<?php echo Barcode::svg( $ticket->get_code(), 70, 2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated SVG (numbers only). ?>
			<?php endif; ?>
			<code><?php echo esc_html( $ticket->get_code() ); ?></code>
			<span class="sc-ev-status sc-ev-status--<?php echo esc_attr( $sidcraft_commerce_status ); ?>"><?php echo esc_html( $ticket->get_status_label() ); ?></span>
		</div>

		<dl class="sc-ev-ticket__facts">
			<div><dt><?php esc_html_e( 'Ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt><dd><?php echo esc_html( $ticket->get_type_name() . ( '' !== (string) $ticket->meta( 'attendee_type' ) && $ticket->meta( 'attendee_type' ) !== $ticket->get_type_name() ? ' · ' . $ticket->meta( 'attendee_type' ) : '' ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Attendee', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt><dd><?php echo esc_html( $ticket->get_attendee_name() ); ?></dd></div>
			<?php if ( $ticket->meta( 'order' ) ) : ?>
				<div><dt><?php esc_html_e( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt><dd>#<?php echo (int) $ticket->meta( 'order' ); ?></dd></div>
			<?php endif; ?>
			<?php if ( $ticket->get_checked_in() ) : ?>
				<div><dt><?php esc_html_e( 'Checked in', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt><dd><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ticket->get_checked_in() ) ); ?></dd></div>
			<?php endif; ?>
		</dl>

		<?php if ( $event && $sidcraft_commerce_valid && $event->is_online() && '' !== $event->get_join_url() && ! $event->is_past() ) : ?>
			<?php $sidcraft_commerce_zoom = $event->get_zoom(); ?>
			<div class="sc-ev-join">
				<a class="sc-btn sc-btn--primary sc-btn--lg" href="<?php echo esc_url( $event->get_join_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== $sidcraft_commerce_zoom['url'] ? __( 'Join on Zoom', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Join online', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></a>
				<?php if ( '' !== $sidcraft_commerce_zoom['id'] ) : ?>
					<p>
						<?php
						/* translators: %s: meeting ID. */
						echo esc_html( sprintf( __( 'Meeting ID: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_zoom['id'] ) );
						if ( '' !== $sidcraft_commerce_zoom['passcode'] ) {
							/* translators: %s: passcode. */
							echo ' · ' . esc_html( sprintf( __( 'Passcode: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_zoom['passcode'] ) );
						}
						?>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="sc-ev-ticket__actions">
			<a class="sc-btn sc-btn--ghost" href="<?php echo esc_url( $ticket->get_pdf_url() ); ?>"><?php esc_html_e( 'Download PDF', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			<?php if ( $event && $sidcraft_commerce_valid && ! $event->is_past() ) : ?>
				<a class="sc-btn sc-btn--ghost" href="<?php echo esc_url( $event->get_google_calendar_url( true ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Google Calendar', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
				<a class="sc-btn sc-btn--ghost" href="<?php echo esc_url( $event->get_ics_url() ); ?>"><?php esc_html_e( 'Apple / Outlook', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			<?php endif; ?>
			<a class="sc-btn sc-btn--ghost sc-ev-wa" href="<?php echo esc_url( Integrations::share_url( $ticket ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Share on WhatsApp', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			<?php if ( $event && $sidcraft_commerce_valid && '' !== $event->get_whatsapp_group() ) : ?>
				<a class="sc-btn sc-btn--ghost sc-ev-wa" href="<?php echo esc_url( $event->get_whatsapp_group() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Join the WhatsApp group', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			<?php endif; ?>
		</div>
	</article>

	<?php if ( $staff ) : ?>
		<section class="sc-ev-staff">
			<h2><?php esc_html_e( 'Staff check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'sidccofo_checkin_' . $ticket->get_id(), '_sidccofo_checkin' ); ?>
				<?php if ( 'checked_in' === $sidcraft_commerce_status ) : ?>
					<input type="hidden" name="sidccofo_action" value="undo">
					<button type="submit" class="sc-btn sc-btn--ghost sc-btn--lg"><?php esc_html_e( 'Undo check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
				<?php elseif ( 'valid' === $sidcraft_commerce_status ) : ?>
					<input type="hidden" name="sidccofo_action" value="checkin">
					<button type="submit" class="sc-btn sc-btn--primary sc-btn--lg sc-btn--block"><?php esc_html_e( 'Check in', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
				<?php else : ?>
					<p class="sc-notice sc-notice--error"><?php esc_html_e( 'This ticket was cancelled. Do not admit.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
				<?php endif; ?>
			</form>
			<?php if ( $ticket->get_fields() || $ticket->get_attendee_email() ) : ?>
				<dl class="sc-ev-ticket__facts">
					<?php if ( $ticket->get_attendee_email() ) : ?>
						<div><dt><?php esc_html_e( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt><dd><?php echo esc_html( $ticket->get_attendee_email() ); ?></dd></div>
					<?php endif; ?>
					<?php foreach ( $ticket->get_fields() as $sidcraft_commerce_row ) : ?>
						<div><dt><?php echo esc_html( $sidcraft_commerce_row['label'] ); ?></dt><dd><?php echo esc_html( $sidcraft_commerce_row['value'] ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sidccofo_event&page=sidccofo-checkin' ) ); ?>"><?php esc_html_e( 'Open the check-in scanner', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a></p>
		</section>
	<?php endif; ?>
</main>
</body>
</html>
