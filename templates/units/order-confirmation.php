<?php
/**
 * Order confirmation.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/order-confirmation.php
 *
 * @package SidcraftCommerce
 * @version 1.6.0
 *
 * @var \SidcraftCommerce\Data\Order          $order
 * @var array                                 $settings
 * @var \SidcraftCommerce\Payments\Gateway|null $gateway
 */

defined( 'ABSPATH' ) || exit;

use function SidcraftCommerce\format_price;
use function SidcraftCommerce\totals_rows;

$sidcraft_commerce_status = $order->get_status();
$sidcraft_commerce_pay    = \SidcraftCommerce\Subscriptions::pay_form( $order );
?>
<div class="sc-confirm">
	<?php if ( 'sidccofo-cancelled' === $sidcraft_commerce_status ) : ?>
		<h2 class="sc-confirm__heading"><?php esc_html_e( 'This order was cancelled.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h2>
	<?php elseif ( '' !== $sidcraft_commerce_pay ) : ?>
		<h2 class="sc-confirm__heading"><?php esc_html_e( 'Subscription renewal', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h2>
	<?php else : ?>
		<h2 class="sc-confirm__heading"><?php echo esc_html( $settings['heading'] ); ?></h2>
	<?php endif; ?>

	<?php if ( '' !== $sidcraft_commerce_pay ) : ?>
		<?php echo $sidcraft_commerce_pay; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in pay_form(). ?>
	<?php elseif ( 'sidccofo-pending' === $sidcraft_commerce_status ) : ?>
		<p class="sc-notice" role="status"><?php esc_html_e( 'We’re confirming your payment. This page will show it as paid within a minute — you’ll also get an email.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
	<?php elseif ( 'sidccofo-on-hold' === $sidcraft_commerce_status && $gateway ) : ?>
		<p class="sc-notice" role="status"><?php echo esc_html( $gateway->get_description() ); ?></p>
	<?php endif; ?>

	<ul class="sc-confirm__meta">
		<li><span><?php esc_html_e( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span><strong>#<?php echo esc_html( $order->get_id() ); ?></strong></li>
		<li><span><?php esc_html_e( 'Date', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span><strong><?php echo esc_html( $order->get_date() ); ?></strong></li>
		<li><span><?php esc_html_e( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span><strong class="sc-status sc-status--<?php echo esc_attr( $sidcraft_commerce_status ); ?>"><?php echo esc_html( $order->get_status_label() ); ?></strong></li>
		<li><span><?php esc_html_e( 'Total', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span><strong><?php echo esc_html( format_price( $order->get_total() ) ); ?></strong></li>
		<?php if ( '' !== $order->get_payment_method_title() ) : ?>
			<li><span><?php esc_html_e( 'Payment', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span><strong><?php echo esc_html( $order->get_payment_method_title() ); ?></strong></li>
		<?php endif; ?>
		<?php foreach ( \SidcraftCommerce\Checkout\Checkout::extra_fields( $order ) as $sidcraft_commerce_x ) : ?>
			<li><span><?php echo esc_html( $sidcraft_commerce_x['label'] ); ?></span><strong><?php echo esc_html( $sidcraft_commerce_x['value'] ); ?></strong></li>
		<?php endforeach; ?>
	</ul>

	<table class="sc-table">
		<thead><tr><th><?php esc_html_e( 'Product', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th><th class="sc-num"><?php esc_html_e( 'Total', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th></tr></thead>
		<tbody>
			<?php foreach ( $order->get_items() as $sidcraft_commerce_item ) : ?>
				<tr><td><?php echo esc_html( $sidcraft_commerce_item['name'] ); ?> × <?php echo esc_html( $sidcraft_commerce_item['quantity'] ); ?><?php echo \SidcraftCommerce\item_options_html( $sidcraft_commerce_item['options'] ?? array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in item_options_html(). ?><?php echo \SidcraftCommerce\Subscriptions::line_html( $sidcraft_commerce_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in line_html(). ?></td><td class="sc-num"><?php echo esc_html( format_price( $sidcraft_commerce_item['total'] ) ); ?></td></tr>
			<?php endforeach; ?>
		</tbody>
		<tfoot>
			<?php foreach ( totals_rows( $order ) as $sidcraft_commerce_row ) : ?>
				<tr class="<?php echo esc_attr( trim( 'sc-totals__' . $sidcraft_commerce_row['key'] . ' ' . $sidcraft_commerce_row['class'] ) ); ?>"><th<?php echo '' === $sidcraft_commerce_row['value'] ? ' colspan="2"' : ''; ?>><?php echo esc_html( $sidcraft_commerce_row['label'] ); ?></th>
				<?php
				if ( '' !== $sidcraft_commerce_row['value'] ) :
					?>
					<td class="sc-num"><?php echo esc_html( $sidcraft_commerce_row['value'] ); ?></td><?php endif; ?></tr>
			<?php endforeach; ?>
		</tfoot>
	</table>
	<?php
	// Digital downloads.
	if ( \SidcraftCommerce\Downloads\Downloads::order_has_downloads( $order ) && 'sidccofo-cancelled' !== $sidcraft_commerce_status ) {
		$sidcraft_commerce_downloads = \SidcraftCommerce\Plugin::instance()->templates->render(
			'units/downloads.php',
			array(
				'rows'       => \SidcraftCommerce\Downloads\Downloads::rows( $order ),
				'show_order' => false,
				'heading'    => __( 'Downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'empty'      => __( 'Your download links will appear here and in your email once the order is complete.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			)
		);
		echo $sidcraft_commerce_downloads; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
	}
	?>
	<?php do_action( 'sidcraft_commerce_order_confirmation_after', $order ); ?>
</div>
