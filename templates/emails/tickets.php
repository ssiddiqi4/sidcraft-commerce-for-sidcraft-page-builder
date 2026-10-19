<?php
/**
 * Tickets email (confirmation, attendee copy, reminder). Inline styles for email clients.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/emails/tickets.php
 *
 * @package SidcraftCommerce
 * @version 1.2.0
 *
 * @var \SidcraftCommerce\Data\Order|null $order
 * @var array                             $groups  event ID => [ event, tickets ].
 * @var string                            $heading
 * @var string                            $intro
 * @var string                            $kind    tickets | attendee | reminder.
 * @var array                             $images  ticket ID => image src (cid: or URL).
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Events\Ticketing;

$sidcraft_commerce_btn  = 'display:inline-block;background:#7c5cfc;color:#fff;text-decoration:none;font-weight:700;padding:10px 18px;border-radius:8px;font-size:14px;';
$sidcraft_commerce_link = 'color:#7c5cfc;text-decoration:none;font-weight:600;';
$sidcraft_commerce_all  = 0;
foreach ( $groups as $sidcraft_commerce_g ) {
	$sidcraft_commerce_all += count( $sidcraft_commerce_g['tickets'] );
}
?>
<!doctype html>
<html><body style="margin:0;background:#f4f3f8;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1a1a1f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;">
	<tr><td style="background:#7c5cfc;color:#fff;padding:24px 32px;font-size:20px;font-weight:700;"><?php echo esc_html( $heading ); ?></td></tr>
	<tr><td style="padding:24px 32px 8px;font-size:15px;line-height:1.5;">
		<?php if ( '' !== $intro ) : ?>
			<p style="margin:0 0 8px;"><?php echo esc_html( $intro ); ?></p>
		<?php endif; ?>
	</td></tr>
	<?php foreach ( $groups as $sidcraft_commerce_g ) : ?>
		<?php
		$sidcraft_commerce_e    = $sidcraft_commerce_g['event'];
		$sidcraft_commerce_zoom = $sidcraft_commerce_e->get_zoom();
		?>
		<tr><td style="padding:8px 32px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f3f8;border-radius:10px;">
				<tr><td style="padding:18px 20px 6px;">
					<div style="font-size:18px;font-weight:700;"><a href="<?php echo esc_url( $sidcraft_commerce_e->get_permalink() ); ?>" style="color:#1a1a1f;text-decoration:none;"><?php echo esc_html( $sidcraft_commerce_e->get_name() ); ?></a></div>
					<div style="font-size:14px;color:#4a4958;margin-top:4px;">🗓 <?php echo esc_html( $sidcraft_commerce_e->get_date_label() . ( $sidcraft_commerce_e->is_online() && ! $sidcraft_commerce_e->is_all_day() ? ' (' . $sidcraft_commerce_e->get_timezone_label() . ')' : '' ) ); ?></div>
					<?php if ( $sidcraft_commerce_e->has_location() ) : ?>
						<div style="font-size:14px;color:#4a4958;margin-top:2px;">📍 <a href="<?php echo esc_url( $sidcraft_commerce_e->get_directions_url() ); ?>" style="color:#4a4958;"><?php echo esc_html( $sidcraft_commerce_e->get_location_label() ); ?></a></div>
					<?php endif; ?>
				</td></tr>
				<?php if ( $sidcraft_commerce_e->is_online() && '' !== $sidcraft_commerce_e->get_join_url() ) : ?>
					<tr><td style="padding:10px 20px;">
						<a href="<?php echo esc_url( $sidcraft_commerce_e->get_join_url() ); ?>" style="<?php echo esc_attr( $sidcraft_commerce_btn ); ?>"><?php echo esc_html( '' !== $sidcraft_commerce_zoom['url'] ? __( 'Join on Zoom', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Join online', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></a>
						<?php if ( '' !== $sidcraft_commerce_zoom['id'] ) : ?>
							<div style="font-size:13px;color:#6b6a7a;margin-top:6px;">
								<?php
								/* translators: %s: meeting ID. */
								echo esc_html( sprintf( __( 'Meeting ID: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_zoom['id'] ) . ( '' !== $sidcraft_commerce_zoom['passcode'] ? ' · ' . sprintf( /* translators: %s: passcode. */ __( 'Passcode: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_zoom['passcode'] ) : '' ) );
								?>
							</div>
						<?php endif; ?>
					</td></tr>
				<?php endif; ?>
				<?php foreach ( $sidcraft_commerce_g['tickets'] as $sidcraft_commerce_t ) : ?>
					<tr><td style="padding:10px 20px;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;border:1px solid #e4e2ec;">
							<tr>
								<td width="150" style="padding:12px;" valign="top"><a href="<?php echo esc_url( $sidcraft_commerce_t->get_url() ); ?>"><img src="<?php echo esc_attr( $images[ $sidcraft_commerce_t->get_id() ] ?? $sidcraft_commerce_t->get_qr_url() ); ?>" width="140" alt="<?php echo esc_attr( $sidcraft_commerce_t->get_code() ); ?>" style="display:block;width:140px;max-width:140px;height:auto;border:0;"></a></td>
								<td style="padding:12px 12px 12px 0;font-size:14px;line-height:1.5;" valign="top">
									<div style="font-weight:700;font-size:15px;"><?php echo esc_html( $sidcraft_commerce_t->get_type_name() ); ?></div>
									<div><?php echo esc_html( $sidcraft_commerce_t->get_attendee_name() ); ?></div>
									<div style="font-family:Menlo,Consolas,monospace;font-size:15px;letter-spacing:.06em;margin:6px 0;"><?php echo esc_html( $sidcraft_commerce_t->get_code() ); ?></div>
									<?php if ( 'cancelled' === $sidcraft_commerce_t->get_status() ) : ?>
										<div style="color:#dc2626;font-weight:700;"><?php esc_html_e( 'Cancelled', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></div>
									<?php endif; ?>
									<a href="<?php echo esc_url( $sidcraft_commerce_t->get_url() ); ?>" style="<?php echo esc_attr( $sidcraft_commerce_link ); ?>"><?php esc_html_e( 'View ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
									&nbsp;·&nbsp;
									<a href="<?php echo esc_url( $sidcraft_commerce_t->get_pdf_url() ); ?>" style="<?php echo esc_attr( $sidcraft_commerce_link ); ?>"><?php esc_html_e( 'PDF', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
								</td>
							</tr>
						</table>
					</td></tr>
				<?php endforeach; ?>
				<tr><td style="padding:6px 20px 18px;font-size:13px;">
					<a href="<?php echo esc_url( $sidcraft_commerce_e->get_google_calendar_url( true ) ); ?>" style="<?php echo esc_attr( $sidcraft_commerce_link ); ?>"><?php esc_html_e( 'Add to Google Calendar', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( $sidcraft_commerce_e->get_ics_url() ); ?>" style="<?php echo esc_attr( $sidcraft_commerce_link ); ?>"><?php esc_html_e( 'Apple / Outlook', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
					<?php if ( '' !== $sidcraft_commerce_e->get_whatsapp_group() ) : ?>
						&nbsp;·&nbsp;
						<a href="<?php echo esc_url( $sidcraft_commerce_e->get_whatsapp_group() ); ?>" style="color:#16a34a;text-decoration:none;font-weight:600;"><?php esc_html_e( 'Join the WhatsApp group', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
					<?php endif; ?>
				</td></tr>
			</table>
		</td></tr>
	<?php endforeach; ?>
	<tr><td style="padding:12px 32px 28px;font-size:13px;color:#6b6a7a;line-height:1.5;">
		<?php if ( $order && 'attendee' !== $kind && $sidcraft_commerce_all > 1 ) : ?>
			<p style="margin:0 0 10px;"><a href="<?php echo esc_url( Ticketing::order_pdf_url( $order ) ); ?>" style="<?php echo esc_attr( $sidcraft_commerce_btn ); ?>"><?php esc_html_e( 'Download all tickets (PDF)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a></p>
		<?php endif; ?>
		<?php if ( $order ) : ?>
			<?php
			/* translators: %d: order number. */
			echo esc_html( sprintf( __( 'Order #%d', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
			?>
			·
		<?php endif; ?>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:#6b6a7a;"><?php echo esc_html( wp_strip_all_tags( get_bloginfo( 'name' ) ) ); ?></a>
	</td></tr>
</table>
</td></tr></table>
</body></html>
