<?php
/**
 * Subscription email (cancelled, finished). Inline styles for email clients.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/emails/subscription.php
 *
 * @package SidcraftCommerce
 * @version 1.6.0
 *
 * @var \SidcraftCommerce\Data\Subscription $subscription
 * @var string                              $heading
 * @var string                              $message
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Subscriptions;

$sidcraft_commerce_billing = $subscription->get_billing();
$sidcraft_commerce_cell    = 'padding:8px 0;border-bottom:1px solid #eee;';
?>
<!doctype html>
<html><body style="margin:0;background:#f4f3f8;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1a1a1f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;">
	<tr><td style="background:#7c5cfc;color:#fff;padding:24px 32px;font-size:20px;font-weight:700;"><?php echo esc_html( $heading ); ?></td></tr>
	<tr><td style="padding:24px 32px;font-size:15px;line-height:1.5;">
		<p>
			<?php
			/* translators: %s: first name. */
			echo esc_html( sprintf( __( 'Hi %s,', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_billing['first_name'] ?? '' ) );
			?>
		</p>
		<p><?php echo esc_html( $message ); ?></p>
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;">
			<tr><td style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>color:#6b6a7a;"><?php esc_html_e( 'Subscription', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></td><td align="right" style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>">#<?php echo esc_html( (string) $subscription->get_id() ); ?></td></tr>
			<tr><td style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>color:#6b6a7a;"><?php esc_html_e( 'Product', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></td><td align="right" style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>"><?php echo esc_html( $subscription->get_name() ); ?></td></tr>
			<tr><td style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>color:#6b6a7a;"><?php esc_html_e( 'Price', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></td><td align="right" style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>"><?php echo esc_html( Subscriptions::recurring_text( $subscription ) ); ?></td></tr>
			<tr><td style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>color:#6b6a7a;"><?php esc_html_e( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></td><td align="right" style="<?php echo esc_attr( $sidcraft_commerce_cell ); ?>"><?php echo esc_html( $subscription->get_status_label() ); ?></td></tr>
		</table>
		<?php $sidcraft_commerce_page = \SidcraftCommerce\page_id( 'subscriptions' ) ? get_permalink( \SidcraftCommerce\page_id( 'subscriptions' ) ) : ''; ?>
		<?php if ( $sidcraft_commerce_page ) : ?>
			<p><a href="<?php echo esc_url( $sidcraft_commerce_page ); ?>" style="color:#7c5cfc;font-weight:700;"><?php esc_html_e( 'See your subscriptions', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a></p>
		<?php endif; ?>
	</td></tr>
</table>
</td></tr></table>
</body></html>
