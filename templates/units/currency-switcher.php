<?php
/**
 * Currency switcher.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/units/currency-switcher.php
 *
 * Works without JavaScript: the form (or link) reloads the page with
 * ?sidccofo_currency=CODE, which is remembered in a cookie.
 *
 * @package SidcraftCommerce
 * @version 1.5.0
 *
 * @var array    $settings style (select|links), label.
 * @var string[] $codes    Currency codes, the store currency first.
 * @var string   $active   The shopper's currency.
 */

use SidcraftCommerce\Currencies;

defined( 'ABSPATH' ) || exit;

$sidcraft_commerce_label = trim( (string) ( $settings['label'] ?? '' ) );
$sidcraft_commerce_id    = 'sc-currency-' . wp_unique_id();
?>
<div class="sc-currency">
	<?php if ( 'links' === ( $settings['style'] ?? 'select' ) ) : ?>
		<nav class="sc-currency__links" aria-label="<?php echo esc_attr( '' !== $sidcraft_commerce_label ? $sidcraft_commerce_label : __( 'Currency', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?>">
			<?php foreach ( $codes as $sidcraft_commerce_code ) : ?>
				<a href="<?php echo esc_url( Currencies::switch_url( $sidcraft_commerce_code ) ); ?>" rel="nofollow"<?php echo $sidcraft_commerce_code === $active ? ' aria-current="true" class="is-active"' : ''; ?>><?php echo esc_html( $sidcraft_commerce_code ); ?></a>
			<?php endforeach; ?>
		</nav>
	<?php else : ?>
		<form class="sc-currency__form" method="get" action="">
			<?php
			// Keep the page's other query parameters.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Re-emitting the current page's query.
			foreach ( (array) wp_unslash( $_GET ) as $sidcraft_commerce_k => $sidcraft_commerce_v ) :
				if ( Currencies::PARAM === $sidcraft_commerce_k || ! is_scalar( $sidcraft_commerce_v ) ) {
					continue;
				}
				?>
				<input type="hidden" name="<?php echo esc_attr( sanitize_key( (string) $sidcraft_commerce_k ) ); ?>" value="<?php echo esc_attr( sanitize_text_field( (string) $sidcraft_commerce_v ) ); ?>">
			<?php endforeach; ?>
			<?php if ( '' !== $sidcraft_commerce_label ) : ?>
				<label for="<?php echo esc_attr( $sidcraft_commerce_id ); ?>"><?php echo esc_html( $sidcraft_commerce_label ); ?></label>
			<?php endif; ?>
			<select id="<?php echo esc_attr( $sidcraft_commerce_id ); ?>" name="<?php echo esc_attr( Currencies::PARAM ); ?>" onchange="this.form.submit()"<?php echo '' === $sidcraft_commerce_label ? ' aria-label="' . esc_attr__( 'Currency', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '"' : ''; ?>>
				<?php foreach ( $codes as $sidcraft_commerce_code ) : ?>
					<option value="<?php echo esc_attr( $sidcraft_commerce_code ); ?>" <?php selected( $sidcraft_commerce_code, $active ); ?>><?php echo esc_html( $sidcraft_commerce_code ); ?></option>
				<?php endforeach; ?>
			</select>
			<noscript><button type="submit" class="sc-btn sc-btn--ghost"><?php esc_html_e( 'Change', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button></noscript>
		</form>
	<?php endif; ?>
</div>
