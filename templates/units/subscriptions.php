<?php
/**
 * My Subscriptions table.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/subscriptions.php
 *
 * @package SidcraftCommerce
 * @version 1.6.0
 *
 * @var \SidcraftCommerce\Data\Subscription[] $subscriptions The customer's subscriptions, newest first.
 * @var string                                $heading       Heading ('' for none).
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Subscriptions;
?>
<section class="sc-subscriptions">
	<?php if ( '' !== $heading ) : ?>
		<h3 class="sc-subscriptions__title"><?php echo esc_html( $heading ); ?></h3>
	<?php endif; ?>
	<?php if ( ! $subscriptions ) : ?>
		<p class="sc-empty"><?php esc_html_e( 'You have no subscriptions yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
	<?php else : ?>
		<table class="sc-table sc-subscriptions__table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Subscription', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Next payment', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $subscriptions as $sidcraft_commerce_sub ) : ?>
					<?php
					$sidcraft_commerce_status  = $sidcraft_commerce_sub->get_status();
					$sidcraft_commerce_renewal = $sidcraft_commerce_sub->get_pending_renewal();
					$sidcraft_commerce_left    = $sidcraft_commerce_sub->get_payments_left();
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Subscription', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
							<strong><?php echo esc_html( $sidcraft_commerce_sub->get_name() ); ?></strong>
							<span class="sc-subscriptions__meta"><?php echo esc_html( Subscriptions::recurring_text( $sidcraft_commerce_sub ) ); ?></span>
							<?php if ( null !== $sidcraft_commerce_left && in_array( $sidcraft_commerce_status, array( 'active', 'on-hold' ), true ) ) : ?>
								<span class="sc-subscriptions__meta">
									<?php
									/* translators: %d: payments left. */
									echo esc_html( sprintf( _n( '%d payment left', '%d payments left', $sidcraft_commerce_left, 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_left ) );
									?>
								</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"><span class="sc-status sc-status--sub-<?php echo esc_attr( $sidcraft_commerce_status ); ?>"><?php echo esc_html( $sidcraft_commerce_sub->get_status_label() ); ?></span></td>
						<td data-label="<?php esc_attr_e( 'Next payment', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
							<?php
							if ( 'active' === $sidcraft_commerce_status && $sidcraft_commerce_sub->get_next_payment() ) {
								echo esc_html( Subscriptions::date( $sidcraft_commerce_sub->get_next_payment() ) );
							} elseif ( 'pending-cancel' === $sidcraft_commerce_status || ( 'active' === $sidcraft_commerce_status && $sidcraft_commerce_sub->get_end() ) ) {
								/* translators: %s: date. */
								echo esc_html( sprintf( __( 'Ends %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), Subscriptions::date( $sidcraft_commerce_sub->get_next_payment() ? $sidcraft_commerce_sub->get_next_payment() : $sidcraft_commerce_sub->get_end() ) ) );
							} elseif ( $sidcraft_commerce_renewal ) {
								esc_html_e( 'Due now', 'sidcraft-commerce-for-sidcraft-page-builder' );
							} else {
								echo '—';
							}
							?>
						</td>
						<td class="sc-subscriptions__actions">
							<?php if ( $sidcraft_commerce_renewal && 'sidccofo-pending' === $sidcraft_commerce_renewal->get_status() ) : ?>
								<a class="sc-btn sc-btn--primary" href="<?php echo esc_url( $sidcraft_commerce_renewal->get_received_url() ); ?>"><?php esc_html_e( 'Pay now', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
							<?php endif; ?>
							<?php foreach ( array( 'cancel' => $sidcraft_commerce_sub->can_cancel(), 'resume' => $sidcraft_commerce_sub->can_resume() ) as $sidcraft_commerce_do => $sidcraft_commerce_allowed ) : ?>
								<?php if ( $sidcraft_commerce_allowed ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo 'cancel' === $sidcraft_commerce_do ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Cancel this subscription?', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) ) . ');"' : ''; ?>>
										<input type="hidden" name="action" value="<?php echo esc_attr( Subscriptions::CUSTOMER_ACTION ); ?>">
										<input type="hidden" name="subscription" value="<?php echo esc_attr( (string) $sidcraft_commerce_sub->get_id() ); ?>">
										<input type="hidden" name="do" value="<?php echo esc_attr( $sidcraft_commerce_do ); ?>">
										<?php wp_nonce_field( Subscriptions::CUSTOMER_ACTION . '_' . $sidcraft_commerce_sub->get_id() ); ?>
										<button type="submit" class="sc-btn"><?php echo 'cancel' === $sidcraft_commerce_do ? esc_html__( 'Cancel', 'sidcraft-commerce-for-sidcraft-page-builder' ) : esc_html__( 'Keep subscription', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
									</form>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php
							/**
							 * More buttons for a subscription in My Subscriptions (pause, switch plan…).
							 *
							 * @param \SidcraftCommerce\Data\Subscription $sub Subscription.
							 */
							do_action( 'sidcraft_commerce_subscription_actions', $sidcraft_commerce_sub );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
