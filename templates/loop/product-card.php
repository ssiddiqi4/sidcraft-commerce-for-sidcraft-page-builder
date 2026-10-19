<?php
/**
 * Product card — port of ShopWave's ProductCard.jsx.
 *
 * Override by copying to: yourtheme/sidcraft-commerce/loop/product-card.php
 *
 * @package SidcraftCommerce
 * @version 0.5.0
 *
 * @var \SidcraftCommerce\Data\Product $product
 * @var array      $settings
 */

defined( 'ABSPATH' ) || exit;

use function SidcraftCommerce\star_markup;

$sidcraft_commerce_link     = $product->get_permalink();
$sidcraft_commerce_category = $product->get_category_name();
$sidcraft_commerce_ajax     = $product->can_add_to_cart();

/**
 * Filter the badges shown on a card. Return [ 'class' => 'Label' ].
 * Defaults: the admin's badge (HOT, POPULAR, Sale, Clearance), else "Sale"
 * when the product is on sale. "Sold Out" is a separate overlay.
 *
 * @param array      $badges  Badges.
 * @param \SidcraftCommerce\Data\Product $product Product.
 */
$sidcraft_commerce_badges  = apply_filters( 'sidcraft_commerce_card_badges', $product->get_image_badges(), $product );
$sidcraft_commerce_soldout = ! $product->is_in_stock();
?>
<article class="sc-card<?php echo $sidcraft_commerce_soldout ? ' is-sold-out' : ''; ?>" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
	<a class="sc-card__media" href="<?php echo esc_url( $sidcraft_commerce_link ); ?>" tabindex="-1" aria-hidden="true">
		<?php echo $product->get_image( 'medium_large', array( 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-escaped image HTML. ?>
		<?php if ( $settings['show_category'] && $sidcraft_commerce_category ) : ?>
			<span class="sc-card__category"><?php echo esc_html( $sidcraft_commerce_category ); ?></span>
		<?php endif; ?>
		<?php if ( $sidcraft_commerce_badges ) : ?>
			<span class="sc-card__badges">
				<?php foreach ( $sidcraft_commerce_badges as $sidcraft_commerce_class => $sidcraft_commerce_label ) : ?>
					<span class="sc-badge sc-badge--<?php echo esc_attr( $sidcraft_commerce_class ); ?>"><?php echo esc_html( $sidcraft_commerce_label ); ?></span>
				<?php endforeach; ?>
			</span>
		<?php endif; ?>
		<?php if ( $sidcraft_commerce_soldout ) : ?>
			<span class="sc-soldout"><span class="sc-soldout__label"><?php esc_html_e( 'Sold Out', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></span>
		<?php endif; ?>
	</a>

	<div class="sc-card__body">
		<?php do_action( 'sidcraft_commerce_card_before_title', $product, $settings ); ?>

		<h3 class="sc-card__title"><a href="<?php echo esc_url( $sidcraft_commerce_link ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h3>

		<?php if ( $settings['show_rating'] && $product->get_review_count() ) : ?>
			<div class="sc-card__meta">
				<?php echo star_markup( $product->get_average_rating() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
				<span class="sc-card__reviews">(<?php echo esc_html( number_format_i18n( $product->get_review_count() ) ); ?>)</span>
			</div>
		<?php endif; ?>

		<div class="sc-card__price"><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Product::get_price_html(). ?></div>

		<?php if ( $settings['show_add_to_cart'] || $settings['show_quick_view'] ) : ?>
			<div class="sc-card__actions">
				<?php if ( $settings['show_add_to_cart'] ) : ?>
					<?php if ( $sidcraft_commerce_ajax ) : ?>
						<a href="<?php echo esc_url( $product->add_to_cart_url() ); ?>" rel="nofollow" role="button" class="sc-btn sc-btn--primary" data-sc-add-to-cart data-product-id="<?php echo esc_attr( $product->get_id() ); ?>" data-quantity="1">
							<?php echo esc_html( $product->add_to_cart_text() ); ?>
						</a>
					<?php else : ?>
						<a class="sc-btn <?php echo $product->is_in_stock() ? 'sc-btn--primary' : 'sc-btn--ghost sc-btn--muted'; ?>" href="<?php echo esc_url( $product->get_permalink() ); ?>">
							<?php echo esc_html( $product->add_to_cart_text() ); ?>
						</a>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $settings['show_quick_view'] ) : ?>
					<button type="button" class="sc-btn sc-btn--ghost sc-btn--icon" data-sc-quick-view="<?php echo esc_attr( $product->get_id() ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name. */ __( 'Quick view: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $product->get_name() ) ); ?>" title="<?php esc_attr_e( 'Quick view', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
					</button>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php do_action( 'sidcraft_commerce_card_after_actions', $product, $settings ); ?>
	</div>
</article>
