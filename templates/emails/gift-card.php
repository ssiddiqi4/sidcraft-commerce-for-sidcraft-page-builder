<?php
/**
 * Gift card email to the recipient. Inline styles for email clients.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/emails/gift-card.php
 *
 * @package SidcraftCommerce
 * @version 0.7.3
 *
 * @var \SidcraftCommerce\Data\Gift_Card $card
 * @var string                           $heading
 */

defined( 'ABSPATH' ) || exit;

use function SidcraftCommerce\format_base;
use function SidcraftCommerce\page_url;

$sidcraft_commerce_name    = (string) $card->meta( 'recipient_name' );
$sidcraft_commerce_message = (string) $card->meta( 'message' );
$sidcraft_commerce_sender  = (string) $card->meta( 'sender' );
?>
<!doctype html>
<html><body style="margin:0;background:#f4f3f8;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1a1a1f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;">
	<tr><td style="background:#7c5cfc;color:#fff;padding:24px 32px;font-size:20px;font-weight:700;"><?php echo esc_html( $heading ); ?></td></tr>
	<tr><td style="padding:24px 32px;font-size:15px;line-height:1.5;">
		<?php if ( '' !== $sidcraft_commerce_name ) : ?>
			<p>
				<?php
				/* translators: %s: recipient name. */
				echo esc_html( sprintf( __( 'Hi %s,', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_name ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( '' !== $sidcraft_commerce_message ) : ?>
			<div style="margin:0 0 20px;padding:16px 20px;border-left:4px solid #7c5cfc;background:#f4f3f8;border-radius:0 8px 8px 0;font-style:italic;">
				<?php echo wp_kses_post( nl2br( esc_html( $sidcraft_commerce_message ) ) ); ?>
				<?php if ( '' !== $sidcraft_commerce_sender ) : ?>
					<div style="margin-top:8px;font-style:normal;color:#6b6a7a;">— <?php echo esc_html( $sidcraft_commerce_sender ); ?></div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 20px;background:linear-gradient(135deg,#7c5cfc,#c084fc);background-color:#7c5cfc;border-radius:14px;">
			<tr><td style="padding:28px 24px;color:#fff;text-align:center;">
				<div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;"><?php echo esc_html( wp_strip_all_tags( get_bloginfo( 'name' ) ) ); ?> · <?php esc_html_e( 'Gift card', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></div>
				<div style="font-size:40px;font-weight:800;margin:8px 0 14px;"><?php echo esc_html( format_base( $card->get_balance() ) ); ?></div>
				<div style="display:inline-block;background:#fff;color:#1a1a1f;border-radius:8px;padding:10px 16px;font-family:Menlo,Consolas,monospace;font-size:20px;font-weight:700;letter-spacing:.08em;"><?php echo esc_html( $card->get_code() ); ?></div>
			</td></tr>
		</table>

		<p><?php esc_html_e( 'Use this code in the cart or at checkout. Anything you don’t spend stays on the card for next time.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		<?php if ( $card->get_expires() ) : ?>
			<p style="color:#6b6a7a;font-size:13px;">
				<?php
				/* translators: %s: date. */
				echo esc_html( sprintf( __( 'Valid until %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), date_i18n( get_option( 'date_format' ), strtotime( $card->get_expires() ) ) ) );
				?>
			</p>
		<?php endif; ?>
		<p style="margin-top:20px;"><a href="<?php echo esc_url( page_url( 'shop' ) ); ?>" style="display:inline-block;background:#7c5cfc;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:8px;"><?php esc_html_e( 'Start shopping', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a></p>
	</td></tr>
</table>
</td></tr></table>
</body></html>
