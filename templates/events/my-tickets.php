<?php
/**
 * The customer's tickets.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/events/my-tickets.php
 *
 * @package SidcraftCommerce
 * @version 1.2.0
 *
 * @var \SidcraftCommerce\Events\Ticket[] $tickets
 * @var array                             $settings
 */

defined( 'ABSPATH' ) || exit;

$sidcraft_commerce_heading = trim( (string) $settings['heading'] );
?>
<section class="sc-ev-mine">
	<?php if ( '' !== $sidcraft_commerce_heading ) : ?>
		<h3 class="sc-ev-tickets__title"><?php echo esc_html( $sidcraft_commerce_heading ); ?></h3>
	<?php endif; ?>
	<?php if ( ! is_user_logged_in() ) : ?>
		<?php $sidcraft_commerce_here = get_permalink(); ?>
		<p class="sc-empty"><?php esc_html_e( 'Log in to see your tickets.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <a href="<?php echo esc_url( wp_login_url( $sidcraft_commerce_here ? $sidcraft_commerce_here : home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log in', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a></p>
	<?php elseif ( ! $tickets ) : ?>
		<p class="sc-empty"><?php esc_html_e( 'You have no upcoming tickets.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
	<?php else : ?>
		<?php
		$sidcraft_commerce_rows_html = \SidcraftCommerce\Events\Events::template(
			'events/ticket-rows.php',
			array( 'tickets' => $tickets )
		);
		echo $sidcraft_commerce_rows_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
		?>
	<?php endif; ?>
</section>
