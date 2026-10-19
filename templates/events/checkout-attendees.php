<?php
/**
 * Attendee details on the checkout (one box per ticket).
 *
 * Override by copying to: yourtheme/sidcraft-commerce/events/checkout-attendees.php
 * Keep the input names (see Attendees::name()), the sc-{name}-error slots
 * and data-scev-upload on file inputs.
 *
 * @package SidcraftCommerce
 * @version 1.2.0
 *
 * @var array[] $blocks Each: product, quantity, event, type.
 * @var array   $saved  product => n => [ name, email, phone ] (after a failed attempt).
 * @var array   $upload types (string[]), max_mb.
 */

defined( 'ABSPATH' ) || exit;

use SidcraftCommerce\Events\Attendees;

$sidcraft_commerce_first = true;
?>
<div class="sc-ev-attendees" data-sc-ev-attendees>
	<h3 class="sc-checkout__heading"><?php esc_html_e( 'Attendee details', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h3>
	<?php foreach ( $blocks as $sidcraft_commerce_b ) : ?>
		<?php
		$sidcraft_commerce_event = $sidcraft_commerce_b['event'];
		$sidcraft_commerce_mode  = $sidcraft_commerce_event->get_attendee_info();
		$sidcraft_commerce_pid   = (int) $sidcraft_commerce_b['product'];
		?>
		<?php for ( $sidcraft_commerce_n = 0; $sidcraft_commerce_n < $sidcraft_commerce_b['quantity']; $sidcraft_commerce_n++ ) : ?>
			<?php
			$sidcraft_commerce_prev = (array) ( $saved[ $sidcraft_commerce_pid ][ $sidcraft_commerce_n ] ?? array() );
			$sidcraft_commerce_in   = static function ( $key ) use ( $sidcraft_commerce_pid, $sidcraft_commerce_n ) {
				return Attendees::name( $sidcraft_commerce_pid, $sidcraft_commerce_n, $key );
			};
			?>
			<fieldset class="sc-ev-attendee">
				<legend>
					<?php
					/* translators: 1: ticket number, 2: ticket count, 3: ticket type, 4: event. */
					echo esc_html( sprintf( __( 'Ticket %1$d of %2$d · %3$s · %4$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_n + 1, $sidcraft_commerce_b['quantity'], $sidcraft_commerce_b['type']['name'], $sidcraft_commerce_event->get_name() ) );
					?>
				</legend>
				<?php if ( $sidcraft_commerce_first && 'buyer' !== $sidcraft_commerce_mode ) : ?>
					<button type="button" class="sc-ev-copy" data-sc-ev-copy><?php esc_html_e( 'Use my details', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
					<?php $sidcraft_commerce_first = false; ?>
				<?php endif; ?>
				<div class="sc-fields">
					<?php if ( 'buyer' !== $sidcraft_commerce_mode ) : ?>
						<p class="sc-field sc-field--half">
							<label for="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'name' ) ); ?>"><?php esc_html_e( 'Full name', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <span class="sc-req" aria-hidden="true">*</span></label>
							<input type="text" id="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'name' ) ); ?>" name="<?php echo esc_attr( $sidcraft_commerce_in( 'name' ) ); ?>" value="<?php echo esc_attr( (string) ( $sidcraft_commerce_prev['name'] ?? '' ) ); ?>" autocomplete="<?php echo 0 === $sidcraft_commerce_n ? 'name' : 'off'; ?>" required aria-required="true" aria-describedby="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'name' ) ); ?>-error" data-sc-ev-role="name">
							<span class="sc-field__error" id="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'name' ) ); ?>-error"></span>
						</p>
					<?php endif; ?>
					<?php if ( in_array( $sidcraft_commerce_mode, array( 'name_email', 'name_email_phone' ), true ) ) : ?>
						<p class="sc-field sc-field--half">
							<label for="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'email' ) ); ?>"><?php esc_html_e( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <span class="sc-req" aria-hidden="true">*</span></label>
							<input type="email" id="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'email' ) ); ?>" name="<?php echo esc_attr( $sidcraft_commerce_in( 'email' ) ); ?>" value="<?php echo esc_attr( (string) ( $sidcraft_commerce_prev['email'] ?? '' ) ); ?>" autocomplete="off" required aria-required="true" aria-describedby="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'email' ) ); ?>-error" data-sc-ev-role="email">
							<span class="sc-field__error" id="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'email' ) ); ?>-error"></span>
						</p>
					<?php endif; ?>
					<?php if ( 'name_email_phone' === $sidcraft_commerce_mode ) : ?>
						<p class="sc-field sc-field--half">
							<label for="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'phone' ) ); ?>"><?php esc_html_e( 'Phone (WhatsApp)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <span class="sc-req" aria-hidden="true">*</span></label>
							<input type="tel" id="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'phone' ) ); ?>" name="<?php echo esc_attr( $sidcraft_commerce_in( 'phone' ) ); ?>" value="<?php echo esc_attr( (string) ( $sidcraft_commerce_prev['phone'] ?? '' ) ); ?>" autocomplete="off" required aria-required="true" aria-describedby="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'phone' ) ); ?>-error" data-sc-ev-role="phone">
							<span class="sc-field__error" id="sc-<?php echo esc_attr( $sidcraft_commerce_in( 'phone' ) ); ?>-error"></span>
						</p>
					<?php endif; ?>

					<?php foreach ( $sidcraft_commerce_event->get_fields() as $sidcraft_commerce_f ) : ?>
						<?php
						$sidcraft_commerce_key  = 'f_' . $sidcraft_commerce_f['id'];
						$sidcraft_commerce_name = $sidcraft_commerce_in( $sidcraft_commerce_key );
						$sidcraft_commerce_err  = 'checkbox' === $sidcraft_commerce_f['type'] && $sidcraft_commerce_f['options'] ? $sidcraft_commerce_in( $sidcraft_commerce_key . '__0' ) : $sidcraft_commerce_name;
						$sidcraft_commerce_req  = $sidcraft_commerce_f['required'] ? ' required aria-required="true"' : '';
						$sidcraft_commerce_wide = in_array( $sidcraft_commerce_f['type'], array( 'textarea', 'radio', 'checkbox', 'file' ), true ) ? 'full' : 'half';
						?>
						<div class="sc-field sc-field--<?php echo esc_attr( $sidcraft_commerce_wide ); ?>">
							<?php if ( in_array( $sidcraft_commerce_f['type'], array( 'radio', 'checkbox' ), true ) && ( 'radio' === $sidcraft_commerce_f['type'] || $sidcraft_commerce_f['options'] ) ) : ?>
								<fieldset class="sc-ev-choices" aria-describedby="sc-<?php echo esc_attr( $sidcraft_commerce_err ); ?>-error">
									<legend><?php echo esc_html( $sidcraft_commerce_f['label'] ); ?><?php echo $sidcraft_commerce_f['required'] ? ' <span class="sc-req" aria-hidden="true">*</span>' : ''; ?></legend>
									<?php foreach ( $sidcraft_commerce_f['options'] as $sidcraft_commerce_i => $sidcraft_commerce_opt ) : ?>
										<label class="sc-ev-choice">
											<?php if ( 'radio' === $sidcraft_commerce_f['type'] ) : ?>
												<input type="radio" name="<?php echo esc_attr( $sidcraft_commerce_name ); ?>" value="<?php echo esc_attr( $sidcraft_commerce_opt ); ?>"<?php echo 0 === $sidcraft_commerce_i ? ' id="sc-' . esc_attr( $sidcraft_commerce_name ) . '"' : ''; ?>>
											<?php else : ?>
												<input type="checkbox" name="<?php echo esc_attr( $sidcraft_commerce_in( $sidcraft_commerce_key . '__' . $sidcraft_commerce_i ) ); ?>" value="1"<?php echo 0 === $sidcraft_commerce_i ? ' id="sc-' . esc_attr( $sidcraft_commerce_err ) . '"' : ''; ?>>
											<?php endif; ?>
											<?php echo esc_html( $sidcraft_commerce_opt ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
							<?php elseif ( 'checkbox' === $sidcraft_commerce_f['type'] ) : ?>
								<label class="sc-ev-choice" for="sc-<?php echo esc_attr( $sidcraft_commerce_name ); ?>">
									<input type="checkbox" id="sc-<?php echo esc_attr( $sidcraft_commerce_name ); ?>" name="<?php echo esc_attr( $sidcraft_commerce_name ); ?>" value="1"<?php echo $sidcraft_commerce_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute. ?>>
									<?php echo esc_html( $sidcraft_commerce_f['label'] ); ?><?php echo $sidcraft_commerce_f['required'] ? ' <span class="sc-req" aria-hidden="true">*</span>' : ''; ?>
								</label>
							<?php else : ?>
								<label for="sc-<?php echo esc_attr( $sidcraft_commerce_name ); ?>"><?php echo esc_html( $sidcraft_commerce_f['label'] ); ?><?php echo $sidcraft_commerce_f['required'] ? ' <span class="sc-req" aria-hidden="true">*</span>' : ''; ?></label>
								<?php if ( 'textarea' === $sidcraft_commerce_f['type'] ) : ?>
									<textarea id="sc-<?php echo esc_attr( $sidcraft_commerce_name ); ?>" name="<?php echo esc_attr( $sidcraft_commerce_name ); ?>" rows="3" aria-describedby="sc-<?php echo esc_attr( $sidcraft_commerce_name ); ?>-error"<?php echo $sidcraft_commerce_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>></textarea>
								<?php elseif ( 'select' === $sidcraft_commerce_f['type'] ) : ?>
									<select id="sc-<?php echo esc_attr( $sidcraft_commerce_name ); ?>" name="<?php echo esc_attr( $sidcraft_commerce_name ); ?>" aria-describedby="sc-<?php echo esc_attr( $sidcraft_commerce_name ); ?>-error"<?php echo $sidcraft_commerce_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
										<option value=""><?php esc_html_e( 'Select…', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></option>
										<?php foreach ( $sidcraft_commerce_f['options'] as $sidcraft_commerce_opt ) : ?>
											<option value="<?php echo esc_attr( $sidcraft_commerce_opt ); ?>"><?php echo esc_html( $sidcraft_commerce_opt ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php elseif ( 'file' === $sidcraft_commerce_f['type'] ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $sidcraft_commerce_name ); ?>" value="" data-scev-token>
									<input type="file" id="sc-<?php echo esc_attr( $sidcraft_commerce_name ); ?>" data-scev-upload accept="<?php echo esc_attr( '.' . implode( ',.', $upload['types'] ) ); ?>" data-max="<?php echo esc_attr( (string) $upload['max_mb'] ); ?>" aria-describedby="sc-<?php echo esc_attr( $sidcraft_commerce_name ); ?>-error">
									<span class="sc-ev-upload-status" data-scev-status></span>
								<?php else : ?>
									<input type="<?php echo esc_attr( $sidcraft_commerce_f['type'] ); ?>" id="sc-<?php echo esc_attr( $sidcraft_commerce_name ); ?>" name="<?php echo esc_attr( $sidcraft_commerce_name ); ?>" aria-describedby="sc-<?php echo esc_attr( $sidcraft_commerce_name ); ?>-error"<?php echo $sidcraft_commerce_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( '' !== $sidcraft_commerce_f['help'] ) : ?>
								<span class="sc-ev-help"><?php echo esc_html( $sidcraft_commerce_f['help'] ); ?></span>
							<?php endif; ?>
							<span class="sc-field__error" id="sc-<?php echo esc_attr( $sidcraft_commerce_err ); ?>-error"></span>
						</div>
					<?php endforeach; ?>
				</div>
			</fieldset>
		<?php endfor; ?>
	<?php endforeach; ?>
</div>
