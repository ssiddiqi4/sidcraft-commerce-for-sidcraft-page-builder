<?php
/**
 * Newsletter signup form.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/newsletter.php
 *
 * @package SidcraftCommerce
 * @version 2.0.0
 *
 * @var array $settings Unit settings.
 */

defined( 'ABSPATH' ) || exit;

$sidcraft_commerce_uid = 'sc-nl-' . wp_unique_id();
?>
<form class="sc-newsletter" data-sc-newsletter data-api="<?php echo esc_url( rest_url( \SidcraftCommerce\Rest::NS . '/marketing/subscribe' ) ); ?>" data-error="<?php esc_attr_e( 'Something went wrong. Please try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" novalidate>
	<?php if ( '' !== $settings['title'] ) : ?>
		<h3 class="sc-newsletter__title"><?php echo esc_html( $settings['title'] ); ?></h3>
	<?php endif; ?>
	<?php if ( '' !== $settings['text'] ) : ?>
		<p class="sc-newsletter__text"><?php echo esc_html( $settings['text'] ); ?></p>
	<?php endif; ?>
	<div class="sc-newsletter__fields">
		<?php if ( $settings['show_name'] ) : ?>
			<label class="sc-sr-only" for="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-name"><?php esc_html_e( 'First name', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
			<input id="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-name" type="text" name="first_name" autocomplete="given-name" placeholder="<?php esc_attr_e( 'First name', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
		<?php endif; ?>
		<label class="sc-sr-only" for="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-email"><?php esc_html_e( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
		<input id="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-email" type="email" name="email" autocomplete="email" required placeholder="<?php esc_attr_e( 'Your email', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
		<button type="submit" class="sc-btn sc-btn--primary"><?php echo esc_html( $settings['button'] ); ?></button>
	</div>
	<p class="sc-newsletter__consent"><label><input type="checkbox" name="consent" value="1" required> <?php echo esc_html( $settings['consent'] ); ?></label></p>
	<?php /* Honeypot: hidden from people, filled by bots. */ ?>
	<p class="sc-newsletter__hp" aria-hidden="true"><label><?php esc_html_e( 'Website', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
	<input type="hidden" name="tags" value="<?php echo esc_attr( $settings['tags'] ); ?>">
	<p class="sc-newsletter__message" role="status" aria-live="polite"></p>
</form>
