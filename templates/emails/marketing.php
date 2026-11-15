<?php
/**
 * Marketing email layout (campaigns, welcome, cart reminders, follow-ups).
 * Inline styles for email clients.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/emails/marketing.php
 *
 * @package SidcraftCommerce
 * @version 2.0.0
 *
 * @var string $subject     Subject (also the heading).
 * @var string $content     Body HTML (already filtered).
 * @var bool   $footer      Show the unsubscribe footer.
 * @var string $unsubscribe Unsubscribe link.
 * @var string $address     Store postal address.
 * @var string $pixel       Open-tracking image URL ('' = none).
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title><?php echo esc_html( $subject ); ?></title></head>
<body style="margin:0;background:#f4f3f8;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1a1a1f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:12px;overflow:hidden;">
	<tr><td style="background:#7c5cfc;color:#fff;padding:20px 32px;font-size:18px;font-weight:700;"><?php echo esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?></td></tr>
	<tr><td style="padding:24px 32px;font-size:15px;line-height:1.6;">
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered with wp_kses_post / built from escaped parts in Mailer. ?>
	</td></tr>
</table>
<?php if ( $footer ) : ?>
	<p style="max-width:600px;margin:16px auto 0;font-size:12px;line-height:1.5;color:#6b6a7a;text-align:center;">
		<?php if ( '' !== $address ) : ?>
			<?php echo nl2br( esc_html( $address ) ); ?><br>
		<?php endif; ?>
		<?php esc_html_e( 'You get this email because you subscribed to our news.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
		<a href="<?php echo esc_url( $unsubscribe ); ?>" style="color:#6b6a7a;"><?php esc_html_e( 'Unsubscribe', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
	</p>
<?php endif; ?>
</td></tr></table>
<?php if ( $pixel ) : ?>
	<img src="<?php echo esc_url( $pixel ); ?>" width="1" height="1" alt="" style="display:block;border:0;">
<?php endif; ?>
</body></html>
