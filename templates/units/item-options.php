<?php
/**
 * Item options inside the Add to Cart form (size, extras, instructions).
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/item-options.php
 * Field names must stay sidccofo_options[group id] (checkboxes: [group id][]).
 *
 * @package SidcraftCommerce
 * @version 1.8.0
 *
 * @var \SidcraftCommerce\Data\Product $product
 * @var array[]                        $groups  From Product::get_options().
 */

defined( 'ABSPATH' ) || exit;

use function SidcraftCommerce\format_price;

$sidcraft_commerce_uid    = 'sc-opt-' . $product->get_id() . '-' . wp_rand( 100, 999 );
$sidcraft_commerce_stocks = $product->has_choice_stock();
// Units left per choice (null = not tracked), and "sold out" text.
$sidcraft_commerce_left = static function ( $group, $choice ) use ( $product, $sidcraft_commerce_stocks ) {
	return $sidcraft_commerce_stocks ? $product->get_choice_stock( $group['id'], $choice['id'] ) : null;
};
$sidcraft_commerce_soldout = __( 'sold out', 'sidcraft-commerce-for-sidcraft-page-builder' );
// A choice's own SKU and image (swapped in by the script when it is picked).
$sidcraft_commerce_has_sku = false;
$sidcraft_commerce_media   = static function ( $group, $choice ) use ( $product, &$sidcraft_commerce_has_sku ) {
	$extra = $product->get_choice_extra( $group['id'], $choice['id'] );
	$attrs = '';
	if ( '' !== $extra['sku'] ) {
		$sidcraft_commerce_has_sku = true;
		$attrs                    .= ' data-sku="' . esc_attr( $extra['sku'] ) . '"';
	}
	if ( $extra['image'] ) {
		$src = wp_get_attachment_image_src( $extra['image'], 'large' );
		if ( $src ) {
			$attrs .= ' data-image="' . esc_url( $src[0] ) . '" data-image-srcset="' . esc_attr( (string) wp_get_attachment_image_srcset( $extra['image'], 'large' ) ) . '"';
		}
	}
	return $attrs;
};
?>
<div class="sc-options" data-sc-options>
	<?php foreach ( $groups as $sidcraft_commerce_group ) : ?>
		<?php
		$sidcraft_commerce_id   = $sidcraft_commerce_uid . '-' . $sidcraft_commerce_group['id'];
		$sidcraft_commerce_name = 'sidccofo_options[' . $sidcraft_commerce_group['id'] . ']';
		$sidcraft_commerce_req  = $sidcraft_commerce_group['required'];
		$sidcraft_commerce_err  = $sidcraft_commerce_id . '-error';
		$sidcraft_commerce_mark = $sidcraft_commerce_req ? ' <span class="sc-req" aria-hidden="true">*</span>' : '';
		// Required radio buttons start on the first choice still available.
		$sidcraft_commerce_first = null;
		foreach ( $sidcraft_commerce_group['choices'] as $sidcraft_commerce_i => $sidcraft_commerce_choice ) {
			if ( 0 !== $sidcraft_commerce_left( $sidcraft_commerce_group, $sidcraft_commerce_choice ) ) {
				$sidcraft_commerce_first = $sidcraft_commerce_i;
				break;
			}
		}
		?>
		<div class="sc-options__group sc-options__group--<?php echo esc_attr( $sidcraft_commerce_group['type'] ); ?>" data-sc-option="<?php echo esc_attr( $sidcraft_commerce_group['id'] ); ?>">
			<?php if ( in_array( $sidcraft_commerce_group['type'], array( 'radio', 'checkbox' ), true ) ) : ?>
				<fieldset aria-describedby="<?php echo esc_attr( $sidcraft_commerce_err ); ?>"<?php echo $sidcraft_commerce_req && 'radio' === $sidcraft_commerce_group['type'] ? ' aria-required="true"' : ''; ?>>
					<legend class="sc-options__label"><?php echo esc_html( $sidcraft_commerce_group['label'] ) . $sidcraft_commerce_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></legend>
					<div class="sc-options__choices">
						<?php foreach ( $sidcraft_commerce_group['choices'] as $sidcraft_commerce_i => $sidcraft_commerce_choice ) : ?>
							<?php $sidcraft_commerce_out = 0 === $sidcraft_commerce_left( $sidcraft_commerce_group, $sidcraft_commerce_choice ); ?>
							<label class="sc-chip<?php echo $sidcraft_commerce_out ? ' is-sold-out' : ''; ?>">
								<input type="<?php echo esc_attr( $sidcraft_commerce_group['type'] ); ?>" name="<?php echo esc_attr( $sidcraft_commerce_name . ( 'checkbox' === $sidcraft_commerce_group['type'] ? '[]' : '' ) ); ?>" value="<?php echo esc_attr( $sidcraft_commerce_choice['id'] ); ?>" data-price="<?php echo esc_attr( $sidcraft_commerce_choice['price'] ); ?>"<?php echo $sidcraft_commerce_media( $sidcraft_commerce_group, $sidcraft_commerce_choice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?><?php echo ( 'radio' === $sidcraft_commerce_group['type'] && $sidcraft_commerce_req && $sidcraft_commerce_first === $sidcraft_commerce_i ) ? ' checked' : ''; ?><?php echo $sidcraft_commerce_out ? ' disabled' : ''; ?>>
								<span><?php echo esc_html( $sidcraft_commerce_choice['label'] ); ?><?php if ( $sidcraft_commerce_out ) : ?> <small>(<?php echo esc_html( $sidcraft_commerce_soldout ); ?>)</small><?php endif; ?><?php if ( '' !== \SidcraftCommerce\option_price_label( $sidcraft_commerce_choice['price'] ) ) : ?> <small><?php echo esc_html( \SidcraftCommerce\option_price_label( $sidcraft_commerce_choice['price'] ) ); ?></small><?php endif; ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			<?php elseif ( 'select' === $sidcraft_commerce_group['type'] ) : ?>
				<label class="sc-options__label" for="<?php echo esc_attr( $sidcraft_commerce_id ); ?>"><?php echo esc_html( $sidcraft_commerce_group['label'] ) . $sidcraft_commerce_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></label>
				<select class="sc-select" id="<?php echo esc_attr( $sidcraft_commerce_id ); ?>" name="<?php echo esc_attr( $sidcraft_commerce_name ); ?>" aria-describedby="<?php echo esc_attr( $sidcraft_commerce_err ); ?>"<?php echo $sidcraft_commerce_req ? ' required' : ''; ?>>
					<option value="" data-price="0"><?php echo esc_html( $sidcraft_commerce_req ? __( 'Choose…', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'None', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></option>
					<?php foreach ( $sidcraft_commerce_group['choices'] as $sidcraft_commerce_choice ) : ?>
						<?php $sidcraft_commerce_out = 0 === $sidcraft_commerce_left( $sidcraft_commerce_group, $sidcraft_commerce_choice ); ?>
						<option value="<?php echo esc_attr( $sidcraft_commerce_choice['id'] ); ?>" data-price="<?php echo esc_attr( $sidcraft_commerce_choice['price'] ); ?>"<?php echo $sidcraft_commerce_media( $sidcraft_commerce_group, $sidcraft_commerce_choice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?><?php echo $sidcraft_commerce_out ? ' disabled' : ''; ?>><?php echo esc_html( $sidcraft_commerce_choice['label'] . ( '' !== \SidcraftCommerce\option_price_label( $sidcraft_commerce_choice['price'] ) ? ' (' . \SidcraftCommerce\option_price_label( $sidcraft_commerce_choice['price'] ) . ')' : '' ) . ( $sidcraft_commerce_out ? ' — ' . $sidcraft_commerce_soldout : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<label class="sc-options__label" for="<?php echo esc_attr( $sidcraft_commerce_id ); ?>"><?php echo esc_html( $sidcraft_commerce_group['label'] ) . $sidcraft_commerce_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></label>
				<input class="sc-input" type="text" id="<?php echo esc_attr( $sidcraft_commerce_id ); ?>" name="<?php echo esc_attr( $sidcraft_commerce_name ); ?>" maxlength="<?php echo esc_attr( $sidcraft_commerce_group['maxlength'] ); ?>" placeholder="<?php echo esc_attr( $sidcraft_commerce_group['placeholder'] ); ?>" aria-describedby="<?php echo esc_attr( $sidcraft_commerce_err ); ?>"<?php echo $sidcraft_commerce_req ? ' required' : ''; ?> autocomplete="off">
			<?php endif; ?>
			<p class="sc-field__error" id="<?php echo esc_attr( $sidcraft_commerce_err ); ?>" data-sc-option-error></p>
		</div>
	<?php endforeach; ?>
	<?php if ( $sidcraft_commerce_has_sku ) : ?>
		<p class="sc-options__sku"><?php esc_html_e( 'SKU:', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <span data-sc-sku data-base="<?php echo esc_attr( $product->get_sku() ); ?>"><?php echo esc_html( '' !== $product->get_sku() ? $product->get_sku() : '—' ); ?></span></p>
	<?php endif; ?>
	<p class="sc-options__total" data-sc-options-total data-base="<?php echo esc_attr( $product->get_price() ); ?>" hidden></p>
</div>
