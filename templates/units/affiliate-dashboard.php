<?php
/**
 * Affiliate dashboard: join, or the referral link with earnings.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/affiliate-dashboard.php
 *
 * @package SidcraftCommerce
 * @version 1.4.0
 *
 * @var array  $settings
 * @var int    $user_id  0 = logged out.
 * @var string $status   '' | pending | approved | rejected.
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Affiliates;
use function SidcraftCommerce\format_base;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Message flag after a redirect.
$sidcraft_commerce_flag = isset( $_GET['sidccofo_aff'] ) ? sanitize_key( wp_unslash( $_GET['sidccofo_aff'] ) ) : '';
$sidcraft_commerce_post = admin_url( 'admin-post.php' );
?>
<div class="sc-aff">
	<?php if ( ! $user_id ) : ?>
		<p><?php echo esc_html( $settings['intro'] ); ?></p>
		<p><a class="sc-btn sc-btn--primary" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log in to join', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a></p>
	<?php elseif ( '' === $status ) : ?>
		<p><?php echo esc_html( $settings['intro'] ); ?></p>
		<?php /* translators: %s: commission percentage. */ ?>
		<p class="sc-aff__rate"><?php echo esc_html( sprintf( __( 'Commission: %s%% of the products in each order.', 'sidcraft-commerce-for-sidcraft-page-builder' ), Affiliates::rate( 0 ) ) ); ?></p>
		<form class="sc-aff__form" method="post" action="<?php echo esc_url( $sidcraft_commerce_post ); ?>">
			<input type="hidden" name="action" value="sidccofo_aff_apply">
			<?php wp_nonce_field( 'sidccofo_aff_apply', '_sidccofo_aff_nonce' ); ?>
			<p><label for="sc-aff-site"><?php esc_html_e( 'Your website or social profile (optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="url" id="sc-aff-site" name="website" class="sc-input"></p>
			<p><label for="sc-aff-pay"><?php esc_html_e( 'How should we pay you? (e.g. your PayPal email)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<textarea id="sc-aff-pay" name="payment" rows="2" class="sc-input"></textarea></p>
			<p><button type="submit" class="sc-btn sc-btn--primary"><?php esc_html_e( 'Join the affiliate program', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button></p>
		</form>
	<?php elseif ( 'pending' === $status ) : ?>
		<p class="sc-notice"><?php esc_html_e( 'Thanks for applying. We’ll review your application and you’ll find your link here once it’s approved.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
	<?php elseif ( 'rejected' === $status ) : ?>
		<p class="sc-notice"><?php esc_html_e( 'Your affiliate application wasn’t approved. Contact us if you have questions.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
	<?php else : ?>
		<?php $sidcraft_commerce_t = Affiliates::totals( $user_id ); ?>
		<?php if ( 'saved' === $sidcraft_commerce_flag ) : ?>
			<p class="sc-notice sc-notice--success"><?php esc_html_e( 'Saved.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		<?php endif; ?>
		<p><label for="sc-aff-link"><strong><?php esc_html_e( 'Your link', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></strong></label>
			<input type="text" id="sc-aff-link" class="sc-input" readonly value="<?php echo esc_attr( Affiliates::link( $user_id ) ); ?>" onclick="this.select()"></p>
		<?php /* translators: %s: commission percentage. */ ?>
		<p class="sc-aff__rate"><?php echo esc_html( sprintf( __( 'You earn %s%% of the products in orders placed through it.', 'sidcraft-commerce-for-sidcraft-page-builder' ), Affiliates::rate( $user_id ) ) ); ?></p>
		<dl class="sc-aff__stats">
			<div><dt><?php esc_html_e( 'Orders', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $sidcraft_commerce_t['orders'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Waiting for payment', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt><dd><?php echo esc_html( format_base( $sidcraft_commerce_t['pending'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Owed to you', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt><dd><?php echo esc_html( format_base( $sidcraft_commerce_t['unpaid'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Paid to you', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></dt><dd><?php echo esc_html( format_base( $sidcraft_commerce_t['paid'] ) ); ?></dd></div>
		</dl>
		<?php $sidcraft_commerce_ids = Affiliates::order_ids( $user_id, '', 20 ); ?>
		<?php if ( $sidcraft_commerce_ids ) : ?>
			<?php
			$sidcraft_commerce_labels = array(
				'pending' => __( 'Waiting for payment', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'unpaid'  => __( 'Owed', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'paid'    => __( 'Paid', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'void'    => __( 'Cancelled', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			);
			?>
			<table class="sc-aff__orders">
				<thead><tr><th><?php esc_html_e( 'Date', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th><th><?php esc_html_e( 'Commission', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th><th><?php esc_html_e( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $sidcraft_commerce_ids as $sidcraft_commerce_id ) : ?>
						<?php $sidcraft_commerce_s = (string) get_post_meta( $sidcraft_commerce_id, '_sidccofo_commission_status', true ); ?>
						<tr>
							<td><?php echo esc_html( get_the_date( '', $sidcraft_commerce_id ) ); ?></td>
							<td><?php echo esc_html( format_base( (float) get_post_meta( $sidcraft_commerce_id, '_sidccofo_commission', true ) ) ); ?></td>
							<td><?php echo esc_html( $sidcraft_commerce_labels[ $sidcraft_commerce_s ] ?? $sidcraft_commerce_s ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<form class="sc-aff__form" method="post" action="<?php echo esc_url( $sidcraft_commerce_post ); ?>">
			<input type="hidden" name="action" value="sidccofo_aff_details">
			<?php wp_nonce_field( 'sidccofo_aff_details', '_sidccofo_aff_nonce' ); ?>
			<p><label for="sc-aff-pay"><?php esc_html_e( 'Payout details', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<textarea id="sc-aff-pay" name="payment" rows="2" class="sc-input"><?php echo esc_textarea( (string) get_user_meta( $user_id, '_sidccofo_aff_payment', true ) ); ?></textarea></p>
			<p><button type="submit" class="sc-btn sc-btn--ghost"><?php esc_html_e( 'Save', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
