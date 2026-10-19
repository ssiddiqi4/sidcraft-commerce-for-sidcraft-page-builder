<?php
/**
 * Ticket selector: each ticket type with price, availability and quantity.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/events/ticket-selector.php
 * Keep data-sc-ev-form, data-sc-ev-qty, data-sc-ev-total and the input names t[ID].
 *
 * @package SidcraftCommerce
 * @version 1.2.0
 *
 * @var \SidcraftCommerce\Events\Event $event
 * @var array                          $settings
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Events\Pricing;
use function SidcraftCommerce\format_price;

$sidcraft_commerce_types = $event->get_ticket_types();
$sidcraft_commerce_open  = false;
?>
<section class="sc-ev-tickets" id="tickets">
	<?php if ( '' !== trim( (string) $settings['heading'] ) ) : ?>
		<h3 class="sc-ev-tickets__title"><?php echo esc_html( $settings['heading'] ); ?></h3>
	<?php endif; ?>

	<?php if ( ! $sidcraft_commerce_types ) : ?>
		<p class="sc-empty"><?php esc_html_e( 'Tickets are not available yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
	<?php else : ?>
		<form class="sc-ev-form" data-sc-ev-form data-event-id="<?php echo (int) $event->get_id(); ?>" action="<?php echo esc_url( $event->get_permalink() ); ?>#tickets" method="post">
			<p class="sc-notice sc-notice--error" data-sc-ev-error role="alert" hidden></p>
			<ul class="sc-ev-types">
				<?php foreach ( $sidcraft_commerce_types as $sidcraft_commerce_type ) : ?>
					<?php
					$sidcraft_commerce_reason = $event->type_unavailable_reason( $sidcraft_commerce_type );
					$sidcraft_commerce_price  = Pricing::shopper( $event, $sidcraft_commerce_type );
					$sidcraft_commerce_left   = $event->get_type_remaining( $sidcraft_commerce_type );
					$sidcraft_commerce_max    = $sidcraft_commerce_type['max'] ? $sidcraft_commerce_type['max'] : 20;
					if ( null !== $sidcraft_commerce_left ) {
						$sidcraft_commerce_max = min( $sidcraft_commerce_max, $sidcraft_commerce_left );
					}
					$sidcraft_commerce_rates = array();
					foreach ( Pricing::shopper_group_rates( $sidcraft_commerce_type ) as $sidcraft_commerce_rate ) {
						$sidcraft_commerce_rates[] = array( $sidcraft_commerce_rate['n'], $sidcraft_commerce_rate['price'] );
					}
					if ( '' === $sidcraft_commerce_reason ) {
						$sidcraft_commerce_open = true;
					}
					$sidcraft_commerce_id = 'sc-ev-' . $event->get_id() . '-' . $sidcraft_commerce_type['id'];
					?>
					<li class="sc-ev-type<?php echo '' !== $sidcraft_commerce_reason ? ' is-unavailable' : ''; ?>">
						<div class="sc-ev-type__info">
							<label class="sc-ev-type__name" for="<?php echo esc_attr( $sidcraft_commerce_id ); ?>"><?php echo esc_html( $sidcraft_commerce_type['name'] ); ?></label>
							<?php if ( '' !== $sidcraft_commerce_type['attendee_type'] ) : ?>
								<span class="sc-ev-badge"><?php echo esc_html( $sidcraft_commerce_type['attendee_type'] ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $sidcraft_commerce_type['description'] ) : ?>
								<p class="sc-ev-type__desc"><?php echo esc_html( $sidcraft_commerce_type['description'] ); ?></p>
							<?php endif; ?>
							<p class="sc-ev-type__meta">
								<?php if ( '' !== $sidcraft_commerce_reason ) : ?>
									<span class="sc-ev-type__reason"><?php echo esc_html( $sidcraft_commerce_reason ); ?></span>
								<?php else : ?>
									<?php if ( null !== $sidcraft_commerce_left && $event->shows_remaining() ) : ?>
										<span class="sc-ev-type__left<?php echo $sidcraft_commerce_left <= 10 ? ' is-low' : ''; ?>">
											<?php
											/* translators: %d: tickets left. */
											echo esc_html( sprintf( _n( '%d left', '%d left', $sidcraft_commerce_left, 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_left ) );
											?>
										</span>
									<?php endif; ?>
									<?php if ( $sidcraft_commerce_price['next'] ) : ?>
										<span class="sc-ev-type__next">
											<?php
											/* translators: 1: next price, 2: date. */
											echo esc_html( sprintf( __( '%1$s from %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_price['next']['price'] > 0 ? format_price( $sidcraft_commerce_price['next']['price'] ) : __( 'Free', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_date( get_option( 'date_format' ), $sidcraft_commerce_price['next']['when'] ) ) );
											?>
										</span>
									<?php endif; ?>
									<?php foreach ( Pricing::shopper_group_rates( $sidcraft_commerce_type ) as $sidcraft_commerce_rate ) : ?>
										<span class="sc-ev-type__group">
											<?php
											/* translators: 1: quantity, 2: price each. */
											echo esc_html( sprintf( __( '%1$d+ tickets: %2$s each', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_rate['n'], format_price( $sidcraft_commerce_rate['price'] ) ) . ( '' !== $sidcraft_commerce_rate['label'] ? ' (' . $sidcraft_commerce_rate['label'] . ')' : '' ) );
											?>
										</span>
									<?php endforeach; ?>
									<?php if ( $sidcraft_commerce_type['min'] > 1 ) : ?>
										<span>
											<?php
											/* translators: %d: minimum. */
											echo esc_html( sprintf( __( 'Minimum %d per order', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_type['min'] ) );
											?>
										</span>
									<?php endif; ?>
								<?php endif; ?>
							</p>
						</div>
						<div class="sc-ev-type__price">
							<?php if ( $sidcraft_commerce_price['price'] < $sidcraft_commerce_price['base'] ) : ?>
								<del aria-hidden="true"><?php echo esc_html( format_price( $sidcraft_commerce_price['base'] ) ); ?></del>
							<?php endif; ?>
							<strong><?php echo esc_html( $sidcraft_commerce_price['price'] > 0 ? format_price( $sidcraft_commerce_price['price'] ) : __( 'Free', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></strong>
							<?php if ( '' !== $sidcraft_commerce_price['label'] ) : ?>
								<span class="sc-ev-type__label"><?php echo esc_html( $sidcraft_commerce_price['label'] ); ?></span>
							<?php endif; ?>
						</div>
						<div class="sc-ev-type__qty">
							<?php if ( '' === $sidcraft_commerce_reason ) : ?>
								<div class="sc-qty" data-sc-ev-stepper>
									<button type="button" class="sc-qty__step" data-sc-ev-step="-1" aria-label="<?php esc_attr_e( 'Fewer', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">−</button>
									<input type="number" id="<?php echo esc_attr( $sidcraft_commerce_id ); ?>" name="t[<?php echo esc_attr( $sidcraft_commerce_type['id'] ); ?>]" value="0" min="0" max="<?php echo (int) $sidcraft_commerce_max; ?>" step="1" inputmode="numeric" class="sc-qty__input" data-sc-ev-qty data-price="<?php echo esc_attr( (string) $sidcraft_commerce_price['price'] ); ?>" data-min="<?php echo (int) $sidcraft_commerce_type['min']; ?>" data-rates="<?php echo esc_attr( wp_json_encode( $sidcraft_commerce_rates ) ); ?>">
									<button type="button" class="sc-qty__step" data-sc-ev-step="1" aria-label="<?php esc_attr_e( 'More', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">+</button>
								</div>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $sidcraft_commerce_open ) : ?>
				<div class="sc-ev-form__foot">
					<span class="sc-ev-form__total" data-sc-ev-total aria-live="polite"></span>
					<button type="submit" class="sc-btn sc-btn--primary sc-btn--lg" data-sc-ev-submit><?php echo esc_html( $settings['button_text'] ); ?></button>
				</div>
			<?php endif; ?>
		</form>
	<?php endif; ?>
</section>
