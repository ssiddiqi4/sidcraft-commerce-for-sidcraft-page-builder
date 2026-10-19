<?php
/**
 * Checkout form.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/checkout.php
 * Keep field names, data-sc-checkout, data-sc-states, data-sc-country /
 * data-sc-state and .sc-field__error elements.
 *
 * @package SidcraftCommerce
 * @version 1.6.0
 *
 * @var \SidcraftCommerce\Cart\Cart              $cart
 * @var array                                    $settings
 * @var array                                    $fields   key => [ label, type, required, autocomplete, width, choices (select) ].
 * @var \SidcraftCommerce\Payments\Gateway[]     $gateways
 * @var array                                    $values   Prefill.
 * @var bool                                     $cancelled Returned from a cancelled card payment.
 * @var array                                    $countries Countries offered (code => name).
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Checkout\Checkout;
use SidcraftCommerce\Locations;
use SidcraftCommerce\Plugin;
use function SidcraftCommerce\page_url;

if ( $cart->is_empty() ) {
	printf(
		'<div class="sc-cart__empty"><p>%1$s</p><a class="sc-btn sc-btn--primary" href="%2$s">%3$s</a></div>',
		esc_html__( 'Your cart is empty.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		esc_url( page_url( 'shop' ) ),
		esc_html__( 'Continue shopping', 'sidcraft-commerce-for-sidcraft-page-builder' )
	);
	return;
}
?>
<?php
// Subscriptions with a free trial: nothing is due today, but a card can be saved for the renewals.
$sidcraft_commerce_save_card = \SidcraftCommerce\Subscriptions::cart_has( $cart ) && \SidcraftCommerce\Subscriptions::auto_gateways();
?>
<form class="sc-checkout" data-sc-checkout<?php echo $sidcraft_commerce_save_card ? ' data-sc-save-card' : ''; ?> data-sc-states="<?php echo esc_attr( wp_json_encode( Locations::all_states() ) ); ?>" novalidate>
	<?php wp_nonce_field( Checkout::NONCE, '_sidccofo_nonce', false ); ?>

	<div class="sc-checkout__main">
		<?php if ( $cancelled ) : ?>
			<p class="sc-notice" role="status"><?php esc_html_e( 'Card payment was cancelled. Your cart is saved — you can try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		<?php endif; ?>
		<p class="sc-notice sc-notice--error" data-sc-checkout-error role="alert" hidden></p>

		<h3 class="sc-checkout__heading"><?php esc_html_e( 'Your details', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h3>
		<div class="sc-fields">
			<?php foreach ( $fields as $sidcraft_commerce_key => $sidcraft_commerce_f ) : ?>
				<p class="sc-field sc-field--<?php echo esc_attr( $sidcraft_commerce_f[4] ); ?>">
					<label for="sc-<?php echo esc_attr( $sidcraft_commerce_key ); ?>">
						<?php echo esc_html( $sidcraft_commerce_f[0] ); ?><?php echo $sidcraft_commerce_f[2] ? ' <span class="sc-req" aria-hidden="true">*</span>' : ''; ?>
					</label>
					<?php
					$sidcraft_commerce_value   = (string) ( $values[ $sidcraft_commerce_key ] ?? '' );
					$sidcraft_commerce_attrs   = sprintf(
						'id="sc-%1$s" name="%1$s" autocomplete="%2$s" aria-describedby="sc-%1$s-error"%3$s',
						esc_attr( $sidcraft_commerce_key ),
						esc_attr( $sidcraft_commerce_f[3] ),
						$sidcraft_commerce_f[2] ? ' required aria-required="true"' : ''
					);
					$sidcraft_commerce_options = 'country' === $sidcraft_commerce_f[1] ? $countries : ( 'state' === $sidcraft_commerce_f[1] ? Locations::states( $values['country'] ?? '' ) : ( 'select' === $sidcraft_commerce_f[1] ? (array) ( $sidcraft_commerce_f[5] ?? array() ) : array() ) );
					?>
					<?php if ( 'textarea' === $sidcraft_commerce_f[1] ) : ?>
						<textarea <?php echo $sidcraft_commerce_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?> rows="3"><?php echo esc_textarea( $sidcraft_commerce_value ); ?></textarea>
					<?php elseif ( 'country' === $sidcraft_commerce_f[1] || 'select' === $sidcraft_commerce_f[1] || ( 'state' === $sidcraft_commerce_f[1] && $sidcraft_commerce_options ) ) : ?>
						<select <?php echo $sidcraft_commerce_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?><?php echo 'select' === $sidcraft_commerce_f[1] ? '' : ' data-sc-' . esc_attr( $sidcraft_commerce_f[1] ); ?>>
							<option value=""><?php esc_html_e( 'Select…', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></option>
							<?php foreach ( $sidcraft_commerce_options as $sidcraft_commerce_code => $sidcraft_commerce_name ) : ?>
								<option value="<?php echo esc_attr( $sidcraft_commerce_code ); ?>" <?php selected( $sidcraft_commerce_value, (string) $sidcraft_commerce_code ); ?>><?php echo esc_html( $sidcraft_commerce_name ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<input <?php echo $sidcraft_commerce_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?> type="<?php echo esc_attr( 'state' === $sidcraft_commerce_f[1] ? 'text' : $sidcraft_commerce_f[1] ); ?>" value="<?php echo esc_attr( $sidcraft_commerce_value ); ?>"<?php echo 'state' === $sidcraft_commerce_f[1] ? ' data-sc-state' : ''; ?>>
					<?php endif; ?>
					<span class="sc-field__error" id="sc-<?php echo esc_attr( $sidcraft_commerce_key ); ?>-error"></span>
				</p>
			<?php endforeach; ?>

			<?php if ( ! is_user_logged_in() ) : ?>
				<p class="sc-field sc-field--full">
					<label for="sc-create_account">
						<input type="checkbox" id="sc-create_account" name="create_account" value="1">
						<?php esc_html_e( 'Create an account with this email', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
					</label>
					<span class="sc-field__error" id="sc-create_account-error"></span>
				</p>
			<?php endif; ?>

			<?php if ( $settings['show_notes'] ) : ?>
				<p class="sc-field sc-field--full">
					<label for="sc-order_notes"><?php esc_html_e( 'Order notes (optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
					<textarea id="sc-order_notes" name="order_notes" rows="3"></textarea>
				</p>
			<?php endif; ?>
		</div>

		<?php
		/**
		 * After the customer's details, before payment (event attendee details hook in here).
		 *
		 * @param \SidcraftCommerce\Cart\Cart $cart Cart.
		 */
		do_action( 'sidcraft_commerce_checkout_after_fields', $cart );
		?>

		<?php
		$sidcraft_commerce_zero  = $cart->get_total() <= 0;
		$sidcraft_commerce_trial = $sidcraft_commerce_zero && $sidcraft_commerce_save_card;
		$sidcraft_commerce_free  = $sidcraft_commerce_zero && ! $sidcraft_commerce_save_card;
		?>
		<p class="sc-notice" data-sc-paid-by-cards <?php echo $sidcraft_commerce_free ? '' : 'hidden'; ?>><?php esc_html_e( 'Nothing left to pay: no payment method is needed.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		<div class="sc-payment-section" data-sc-payment-section <?php echo $sidcraft_commerce_free ? 'hidden' : ''; ?>>
		<h3 class="sc-checkout__heading"><?php esc_html_e( 'Payment', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h3>
		<?php if ( $sidcraft_commerce_save_card ) : ?>
			<p class="sc-notice" data-sc-trial-note <?php echo $sidcraft_commerce_trial ? '' : 'hidden'; ?>><?php esc_html_e( 'Nothing to pay today. Save a card to renew automatically, or pay each renewal from a link we email you.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		<?php endif; ?>
		<?php if ( ! $gateways && \SidcraftCommerce\currency() !== \SidcraftCommerce\base_currency() ) : ?>
			<p class="sc-notice sc-notice--error">
				<?php
				/* translators: 1: shopper's currency, 2: link to switch to the store currency. */
				echo wp_kses_post( sprintf( __( 'Payments in %1$s aren’t available. %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_html( \SidcraftCommerce\currency() ), '<a href="' . esc_url( \SidcraftCommerce\Currencies::switch_url( \SidcraftCommerce\base_currency() ) ) . '">' . esc_html( sprintf( /* translators: %s: store currency. */ __( 'Pay in %s instead', 'sidcraft-commerce-for-sidcraft-page-builder' ), \SidcraftCommerce\base_currency() ) ) . '</a>' ) );
				?>
			</p>
		<?php elseif ( ! $gateways ) : ?>
			<p class="sc-notice sc-notice--error"><?php esc_html_e( 'This store is not accepting payments yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		<?php else : ?>
			<fieldset class="sc-gateways">
				<legend class="sc-sr-only"><?php esc_html_e( 'Payment method', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></legend>
				<?php $sidcraft_commerce_first = true; ?>
				<?php foreach ( $gateways as $sidcraft_commerce_gateway ) : ?>
					<?php $sidcraft_commerce_auto = $sidcraft_commerce_gateway->supports( 'subscriptions' ); ?>
					<label class="sc-gateway"<?php echo $sidcraft_commerce_auto ? ' data-sc-auto' : ''; ?><?php echo $sidcraft_commerce_trial && ! $sidcraft_commerce_auto ? ' hidden' : ''; ?>>
						<input type="radio" name="payment_method" value="<?php echo esc_attr( $sidcraft_commerce_gateway->get_id() ); ?>" <?php checked( $sidcraft_commerce_trial ? ( $sidcraft_commerce_auto && ! isset( $sidcraft_commerce_auto_seen ) ) : $sidcraft_commerce_first ); ?> data-sc-inline="<?php echo $sidcraft_commerce_gateway->has_inline_button() ? '1' : '0'; ?>">
						<span class="sc-gateway__title"><?php echo esc_html( $sidcraft_commerce_gateway->get_title() ); ?></span>
						<?php if ( $sidcraft_commerce_gateway->get_description() ) : ?>
							<span class="sc-gateway__desc"><?php echo esc_html( $sidcraft_commerce_gateway->get_description() ); ?></span>
						<?php endif; ?>
					</label>
					<?php
					$sidcraft_commerce_fields = $sidcraft_commerce_gateway->payment_fields();
					if ( $sidcraft_commerce_fields ) {
						printf( '<div class="sc-gateway__fields" data-sc-gateway-fields="%1$s">%2$s</div>', esc_attr( $sidcraft_commerce_gateway->get_id() ), $sidcraft_commerce_fields ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Gateway-escaped markup.
					}
					?>
					<?php
					$sidcraft_commerce_first = false;
					if ( $sidcraft_commerce_auto ) {
						$sidcraft_commerce_auto_seen = true;
					}
					?>
				<?php endforeach; ?>
				<?php if ( $sidcraft_commerce_save_card ) : ?>
					<label class="sc-gateway" data-sc-no-card <?php echo $sidcraft_commerce_trial ? '' : 'hidden'; ?>>
						<input type="radio" name="payment_method" value="none" data-sc-inline="0">
						<span class="sc-gateway__title"><?php esc_html_e( 'No card now', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span>
						<span class="sc-gateway__desc"><?php esc_html_e( 'We’ll email you a link to pay when each renewal is due.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span>
					</label>
				<?php endif; ?>
				<span class="sc-field__error" id="sc-payment_method-error"></span>
			</fieldset>
		<?php endif; ?>

		</div>

		<?php do_action( 'sidcraft_commerce_checkout_before_submit', $cart ); ?>

		<button type="submit" class="sc-btn sc-btn--primary sc-btn--lg sc-btn--block" data-sc-place-order <?php disabled( ! $gateways ); ?>>
			<?php echo esc_html( $settings['button_text'] ); ?>
		</button>
	</div>

	<aside class="sc-checkout__side">
		<?php
		echo Plugin::instance()->templates->render( 'units/checkout-summary.php', array( 'cart' => $cart ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in template.
		?>
	</aside>
</form>
