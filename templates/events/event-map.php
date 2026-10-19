<?php
/**
 * Venue map.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/events/event-map.php
 *
 * @package SidcraftCommerce
 * @version 1.2.0
 *
 * @var \SidcraftCommerce\Events\Event $event
 * @var int                            $height
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="sc-ev-map">
	<iframe src="<?php echo esc_url( $event->get_map_embed_url() ); ?>" height="<?php echo (int) $height; ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="<?php echo esc_attr( sprintf( /* translators: %s: venue. */ __( 'Map: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $event->get_location_label() ) ); ?>" allowfullscreen></iframe>
	<p class="sc-ev-map__foot">
		<span><?php echo esc_html( $event->get_location_label() ); ?></span>
		<a class="sc-ev-link" href="<?php echo esc_url( $event->get_directions_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get directions', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
	</p>
</div>
