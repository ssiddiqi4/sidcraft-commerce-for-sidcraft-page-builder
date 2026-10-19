<?php
/**
 * Gift card purchase form (product page, quick view, Add to Cart Unit).
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/gift-card-form.php
 * Keep data-sc-gift-form, the field names and the #sc-gc-*-error elements.
 *
 * @package SidcraftCommerce
 * @version 0.7.3
 *
 * @var \SidcraftCommerce\Data\Product $product
 */

defined( 'ABSPATH' ) || exit;

use function SidcraftCommerce\currency_symbol;
use function SidcraftCommerce\format_price;

$sidcraft_commerce_amounts = $product->get_gift_card_amounts();
$sidcraft_commerce_custom  = $product->allows_custom_amount();
list( $sidcraft_commerce_min, $sidcraft_commerce_max ) = $product->get_custom_amount_range();
$sidcraft_commerce_uid = 'sc-gc-' . $product->get_id();
?>
<form class="sc-gift-form" data-sc-gift-form data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" novalidate>
	<fieldset class="sc-gift-form__amounts">
		<legend><?php esc_html_e( 'Amount', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></legend>
		<div class="sc-gift-form__chips">
			<?php foreach ( $sidcraft_commerce_amounts as $sidcraft_commerce_i => $sidcraft_commerce_amount ) : ?>
				<label class="sc-chip">
					<input type="radio" name="amount_choice" value="<?php echo esc_attr( $sidcraft_commerce_amount ); ?>" <?php checked( 0 === $sidcraft_commerce_i ); ?>>
					<span><?php echo esc_html( format_price( $sidcraft_commerce_amount ) ); ?></span>
				</label>
			<?php endforeach; ?>
			<?php if ( $sidcraft_commerce_custom ) : ?>
				<label class="sc-chip">
					<input type="radio" name="amount_choice" value="custom" <?php checked( ! $sidcraft_commerce_amounts ); ?>>
					<span><?php esc_html_e( 'Other amount', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span>
				</label>
			<?php endif; ?>
		</div>
		<?php if ( $sidcraft_commerce_custom ) : ?>
			<p class="sc-field sc-gift-form__custom" data-sc-gift-custom <?php echo $sidcraft_commerce_amounts ? 'hidden' : ''; ?>>
				<label for="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-custom"><?php echo esc_html( sprintf( /* translators: %s: currency symbol. */ __( 'Your amount (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), trim( currency_symbol() ) ) ); ?></label>
				<input type="number" id="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-custom" name="custom_amount" min="<?php echo esc_attr( $sidcraft_commerce_min ); ?>" <?php echo $sidcraft_commerce_max ? 'max="' . esc_attr( $sidcraft_commerce_max ) . '"' : ''; ?> step="0.01" inputmode="decimal">
				<span class="sc-gift-form__hint">
					<?php
					echo esc_html(
						$sidcraft_commerce_max
							/* translators: 1: minimum, 2: maximum. */
							? sprintf( __( 'Between %1$s and %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $sidcraft_commerce_min ), format_price( $sidcraft_commerce_max ) )
							/* translators: %s: minimum. */
							: sprintf( __( 'At least %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $sidcraft_commerce_min ) )
					);
					?>
				</span>
			</p>
		<?php endif; ?>
		<span class="sc-field__error" id="sc-gc-amount-error"></span>
	</fieldset>

	<div class="sc-gift-form__fields">
		<p class="sc-field">
			<label for="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-rname"><?php esc_html_e( 'Recipient name', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-rname" name="recipient_name" maxlength="80" autocomplete="off">
		</p>
		<p class="sc-field">
			<label for="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-remail"><?php esc_html_e( 'Recipient email', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <span class="sc-req" aria-hidden="true">*</span></label>
			<input type="email" id="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-remail" name="recipient_email" required autocomplete="off" aria-describedby="sc-gc-recipient_email-error">
			<span class="sc-field__error" id="sc-gc-recipient_email-error"></span>
		</p>
		<p class="sc-field">
			<label for="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-sender"><?php esc_html_e( 'From', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-sender" name="sender" maxlength="80" autocomplete="name" placeholder="<?php esc_attr_e( 'Your name', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
		</p>
		<p class="sc-field">
			<label for="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-date"><?php esc_html_e( 'Send on', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
			<input type="date" id="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-date" name="send_date" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" aria-describedby="sc-gc-send_date-error sc-gc-date-hint">
			<span class="sc-gift-form__hint" id="sc-gc-date-hint"><?php esc_html_e( 'Leave empty to send as soon as the order is paid.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span>
			<span class="sc-field__error" id="sc-gc-send_date-error"></span>
		</p>
		<p class="sc-field sc-field--full">
			<label for="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-msg"><?php esc_html_e( 'Message (optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
			<textarea id="<?php echo esc_attr( $sidcraft_commerce_uid ); ?>-msg" name="message" rows="3" maxlength="500"></textarea>
		</p>
	</div>

	<div class="sc-atc">
		<button type="submit" class="sc-btn sc-btn--primary sc-btn--lg" data-sc-gift-submit><?php esc_html_e( 'Add gift card to cart', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
		<p class="sc-atc__notice" role="status" aria-live="polite"></p>
	</div>
</form>
