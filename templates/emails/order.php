<?php
/**
 * Order email (customer and admin). Inline styles for email clients.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/emails/order.php
 *
 * @package SidcraftCommerce
 * @version 1.6.0
 *
 * @var \SidcraftCommerce\Data\Order $order
 * @var string                       $heading
 * @var string                       $recipient customer|admin
 * @var array|null                   $shipment  The shipment just added ("shipped" email), or null.
 * @var array[]                      $shipments All shipments on the order (each with tracking_url).
 * @var string                       $message   Optional message (e.g. from a status's customer_email).
 * @var string                       $pay_url   Optional "Pay now" link (subscription renewals).
 */

defined( 'ABSPATH' ) || exit;

use function SidcraftCommerce\format_price;
use function SidcraftCommerce\totals_rows;

$sidcraft_commerce_billing = $order->get_billing();
$sidcraft_commerce_cell    = 'padding:8px 0;border-bottom:1px solid #eee;';
$sidcraft_commerce_ship    = isset( $shipment ) && is_array( $shipment ) ? $shipment : null;
$sidcraft_commerce_ships   = isset( $shipments ) && is_array( $shipments ) ? $shipments : array();
$sidcraft_commerce_files   = 'customer' === $recipient && \SidcraftCommerce\Downloads\Downloads::get( 'email_links' ) ? \SidcraftCommerce\Downloads\Downloads::rows( $order ) : array();
$sidcraft_commerce_dl_page = \SidcraftCommerce\page_id( 'downloads' ) ? get_permalink( \SidcraftCommerce\page_id( 'downloads' ) ) : '';
?>
<!doctype html>
<html><body style="margin:0;background:#f4f3f8;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1a1a1f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;">
	<tr><td style="background:#7c5cfc;color:#fff;padding:24px 32px;font-size:20px;font-weight:700;"><?php echo esc_html( $heading ); ?></td></tr>
	<tr><td style="padding:24px 32px;font-size:15px;line-height:1.5;">
		<?php if ( 'admin' === $recipient ) : ?>
			<p>
				<?php
				/* translators: 1: order number, 2: customer name. */
				echo esc_html( sprintf( __( 'Order #%1$d from %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id(), trim( ( $sidcraft_commerce_billing['first_name'] ?? '' ) . ' ' . ( $sidcraft_commerce_billing['last_name'] ?? '' ) ) ) );
				?>
				<a href="<?php echo esc_url( admin_url( 'post.php?action=edit&post=' . $order->get_id() ) ); ?>"><?php esc_html_e( 'View in admin', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			</p>
		<?php else : ?>
			<p>
				<?php
				/* translators: %s: first name. */
				echo esc_html( sprintf( __( 'Hi %s,', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_billing['first_name'] ?? '' ) );
				?>
			</p>
			<?php if ( $sidcraft_commerce_ship ) : ?>
				<p>
					<?php
					/* translators: 1: order number, 2: carrier. */
					echo esc_html( sprintf( __( 'Good news: order #%1$d is on its way with %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id(), $sidcraft_commerce_ship['carrier_name'] ) );
					?>
				</p>
			<?php elseif ( ! empty( $message ) ) : ?>
				<p><?php echo esc_html( $message ); ?></p>
				<?php if ( ! empty( $pay_url ) ) : ?>
					<p><a href="<?php echo esc_url( $pay_url ); ?>" style="display:inline-block;background:#7c5cfc;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:8px;">
						<?php
						/* translators: %s: amount. */
						echo esc_html( sprintf( __( 'Pay %s now', 'sidcraft-commerce-for-sidcraft-page-builder' ), \SidcraftCommerce\format_price( $order->get_total() ) ) );
						?>
					</a></p>
				<?php endif; ?>
				<p style="color:#6b6a7a;font-size:13px;">
					<?php
					/* translators: 1: order number, 2: status. */
					echo esc_html( sprintf( __( 'Order #%1$d is now: %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id(), $order->get_status_label() ) );
					?>
				</p>
			<?php else : ?>
				<p>
					<?php
					/* translators: 1: order number, 2: status. */
					echo esc_html( sprintf( __( 'Order #%1$d is now: %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id(), $order->get_status_label() ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( $sidcraft_commerce_ships ) : ?>
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;background:#f4f3f8;border-radius:10px;">
					<tr><td style="padding:16px 20px 4px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b6a7a;"><?php echo esc_html__( 'Tracking', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></td></tr>
					<?php foreach ( $sidcraft_commerce_ships as $sidcraft_commerce_s ) : ?>
						<tr><td style="padding:8px 20px 16px;">
							<div style="font-size:15px;"><strong><?php echo esc_html( $sidcraft_commerce_s['carrier_name'] ); ?></strong> · <?php echo esc_html( $sidcraft_commerce_s['number'] ); ?></div>
							<div style="font-size:13px;color:#6b6a7a;margin:2px 0 10px;">
								<?php
								/* translators: %s: date. */
								echo esc_html( sprintf( __( 'Shipped %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), date_i18n( get_option( 'date_format' ), strtotime( $sidcraft_commerce_s['date'] ) ) ) );
								?>
							</div>
							<?php if ( ! empty( $sidcraft_commerce_s['tracking_url'] ) ) : ?>
								<a href="<?php echo esc_url( $sidcraft_commerce_s['tracking_url'] ); ?>" style="display:inline-block;background:#7c5cfc;color:#fff;text-decoration:none;font-weight:700;padding:10px 18px;border-radius:8px;"><?php esc_html_e( 'Track your package', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
							<?php endif; ?>
						</td></tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
			<?php $sidcraft_commerce_gateway = \SidcraftCommerce\Checkout\Checkout::gateway( $order->get_payment_method() ); ?>
			<?php if ( 'sidccofo-on-hold' === $order->get_status() && $sidcraft_commerce_gateway && $sidcraft_commerce_gateway->get_description() ) : ?>
				<p style="background:#f4f3f8;padding:12px;border-radius:8px;"><?php echo esc_html( $sidcraft_commerce_gateway->get_description() ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php $sidcraft_commerce_extra = \SidcraftCommerce\Checkout\Checkout::extra_fields( $order ); ?>
		<?php if ( $sidcraft_commerce_extra ) : ?>
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;font-size:14px;background:#f4f3f8;border-radius:10px;">
				<?php foreach ( $sidcraft_commerce_extra as $sidcraft_commerce_x ) : ?>
					<tr><td style="padding:8px 16px;color:#6b6a7a;"><?php echo esc_html( $sidcraft_commerce_x['label'] ); ?></td><td style="padding:8px 16px;font-weight:600;" align="right"><?php echo esc_html( $sidcraft_commerce_x['value'] ); ?></td></tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;font-size:14px;">
			<?php foreach ( $order->get_items() as $sidcraft_commerce_item ) : ?>
				<tr><td style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>"><?php echo esc_html( $sidcraft_commerce_item['name'] ); ?> × <?php echo esc_html( $sidcraft_commerce_item['quantity'] ); ?><?php echo \SidcraftCommerce\item_options_html( $sidcraft_commerce_item['options'] ?? array(), 'margin:4px 0 0;padding:0 0 0 16px;color:#6b6a7a;font-size:13px;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in item_options_html(). ?><?php echo \SidcraftCommerce\Subscriptions::line_html( $sidcraft_commerce_item, 'display:block;margin:4px 0 0;color:#6b6a7a;font-size:13px;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in line_html(). ?></td><td align="right" style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>"><?php echo esc_html( format_price( $sidcraft_commerce_item['total'] ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php foreach ( totals_rows( $order ) as $sidcraft_commerce_row ) : ?>
				<?php if ( 'total' === $sidcraft_commerce_row['key'] ) : ?>
					<tr><td style="padding:12px 0;font-weight:700;"><?php echo esc_html( $sidcraft_commerce_row['label'] ); ?></td><td align="right" style="padding:12px 0;font-weight:700;"><?php echo esc_html( $sidcraft_commerce_row['value'] ); ?></td></tr>
				<?php elseif ( '' === $sidcraft_commerce_row['value'] ) : ?>
					<tr><td colspan="2" align="right" style="padding:0 0 8px;color:#6b6a7a;font-size:13px;"><?php echo esc_html( $sidcraft_commerce_row['label'] ); ?></td></tr>
				<?php else : ?>
					<tr><td style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>"><?php echo esc_html( $sidcraft_commerce_row['label'] ); ?></td><td align="right" style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>"><?php echo esc_html( $sidcraft_commerce_row['value'] ); ?></td></tr>
				<?php endif; ?>
			<?php endforeach; ?>
		</table>

		<?php if ( $sidcraft_commerce_files ) : ?>
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 4px;background:#f4f3f8;border-radius:10px;">
				<tr><td colspan="2" style="padding:16px 20px 4px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b6a7a;"><?php esc_html_e( 'Your downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></td></tr>
				<?php foreach ( $sidcraft_commerce_files as $sidcraft_commerce_file ) : ?>
					<?php
					$sidcraft_commerce_meta = array();
					if ( $sidcraft_commerce_file['limited'] ) {
						/* translators: %s: number of downloads. */
						$sidcraft_commerce_meta[] = sprintf( __( 'Downloads left: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_file['remaining'] );
					}
					if ( $sidcraft_commerce_file['expiring'] ) {
						/* translators: %s: date. */
						$sidcraft_commerce_meta[] = sprintf( __( 'Expires %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_file['expires'] );
					}
					?>
					<tr>
						<td style="padding:10px 20px;font-size:14px;"><strong><?php echo esc_html( $sidcraft_commerce_file['name'] ); ?></strong>
							<?php if ( $sidcraft_commerce_meta ) : ?>
								<div style="color:#6b6a7a;font-size:12px;margin-top:2px;"><?php echo esc_html( implode( ' · ', $sidcraft_commerce_meta ) ); ?></div>
							<?php endif; ?>
						</td>
						<td align="right" style="padding:10px 20px;"><a href="<?php echo esc_url( $sidcraft_commerce_file['url'] ); ?>" style="display:inline-block;background:#7c5cfc;color:#fff;text-decoration:none;font-weight:700;padding:8px 16px;border-radius:8px;font-size:14px;"><?php esc_html_e( 'Download', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( $sidcraft_commerce_dl_page ) : ?>
					<tr><td colspan="2" style="padding:4px 20px 16px;font-size:13px;"><a href="<?php echo esc_url( $sidcraft_commerce_dl_page ); ?>" style="color:#7c5cfc;"><?php esc_html_e( 'All your downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a></td></tr>
				<?php endif; ?>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $sidcraft_commerce_billing['address_1'] ) ) : ?>
			<p style="margin-top:16px;color:#6b6a7a;font-size:13px;">
				<?php
				echo esc_html(
					implode(
						', ',
						array_filter(
							array(
								$sidcraft_commerce_billing['address_1'] ?? '',
								$sidcraft_commerce_billing['address_2'] ?? '',
								$sidcraft_commerce_billing['city'] ?? '',
								$sidcraft_commerce_billing['state'] ?? '',
								$sidcraft_commerce_billing['postcode'] ?? '',
								$sidcraft_commerce_billing['country'] ?? '',
							)
						)
					)
				);
				?>
			</p>
		<?php endif; ?>
	</td></tr>
</table>
</td></tr></table>
</body></html>
