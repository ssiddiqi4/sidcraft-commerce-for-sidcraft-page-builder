<?php
/**
 * Tickets on the order-received page.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/events/order-tickets.php
 *
 * @package SidcraftCommerce
 * @version 1.2.0
 *
 * @var \SidcraftCommerce\Data\Order      $order
 * @var \SidcraftCommerce\Events\Ticket[] $tickets
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Events\Ticketing;
?>
<section class="sc-unit sc-ev-order-tickets">
	<h3 class="sc-ev-tickets__title"><?php echo esc_html( _n( 'Your ticket', 'Your tickets', max( 1, count( $tickets ) ), 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></h3>
	<?php if ( ! $tickets ) : ?>
		<p class="sc-notice">
			<?php
			echo esc_html(
				in_array( $order->get_status(), array( 'sidccofo-cancelled', 'sidccofo-refunded' ), true )
					? __( 'This order was cancelled, so no tickets were issued.', 'sidcraft-commerce-for-sidcraft-page-builder' )
					: __( 'Your tickets will appear here and arrive by email as soon as the payment is confirmed.', 'sidcraft-commerce-for-sidcraft-page-builder' )
			);
			?>
		</p>
	<?php else : ?>
		<p><?php esc_html_e( 'We’ve emailed your tickets too. Show the QR code at the entrance.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		<?php
		$sidcraft_commerce_rows_html = \SidcraftCommerce\Events\Events::template(
			'events/ticket-rows.php',
			array( 'tickets' => $tickets )
		);
		echo $sidcraft_commerce_rows_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
		?>
		<?php if ( count( $tickets ) > 1 ) : ?>
			<p><a class="sc-btn sc-btn--primary" href="<?php echo esc_url( Ticketing::order_pdf_url( $order ) ); ?>"><?php esc_html_e( 'Download all tickets (PDF)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a></p>
		<?php endif; ?>
	<?php endif; ?>
</section>
