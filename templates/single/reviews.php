<?php
/**
 * Product reviews: summary, clickable stars, review list and the popup form
 * (a <template> that sc-reviews.js opens in a dialog).
 *
 * Override by copying to: yourtheme/sidcraft-commerce/single/reviews.php
 * Keep id="reviews", data-sc-reviews, data-sc-review-open, the
 * template[data-sc-review-form] and the field names / error ids inside it.
 *
 * @package SidcraftCommerce
 * @version 0.6.0
 *
 * @var \SidcraftCommerce\Data\Product $product
 * @var array                          $settings
 * @var array[]                        $reviews      id, rating, name, date, text, verified.
 * @var int[]                          $distribution 5 => n … 1 => n.
 */

defined( 'ABSPATH' ) || exit;

use function SidcraftCommerce\rate_stars_markup;
use function SidcraftCommerce\star_markup;

$sidcraft_commerce_count = $product->get_review_count();
$sidcraft_commerce_avg   = $product->get_average_rating();
?>
<section class="sc-reviews" id="reviews" data-sc-reviews data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
	<?php if ( '' !== $settings['title'] ) : ?>
		<h2 class="sc-reviews__title"><?php echo esc_html( $settings['title'] ); ?></h2>
	<?php endif; ?>

	<div class="sc-reviews__summary">
		<div class="sc-reviews__score">
			<span class="sc-reviews__avg"><?php echo esc_html( $sidcraft_commerce_count ? number_format_i18n( $sidcraft_commerce_avg, 1 ) : '–' ); ?></span>
			<?php echo star_markup( $sidcraft_commerce_avg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
			<span class="sc-reviews__based">
				<?php
				echo esc_html(
					$sidcraft_commerce_count
						/* translators: %s: number of reviews. */
						? sprintf( _n( 'Based on %s review', 'Based on %s reviews', $sidcraft_commerce_count, 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( $sidcraft_commerce_count ) )
						: __( 'No reviews yet', 'sidcraft-commerce-for-sidcraft-page-builder' )
				);
				?>
			</span>
		</div>

		<ul class="sc-reviews__bars" aria-label="<?php esc_attr_e( 'Rating breakdown', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
			<?php foreach ( $distribution as $sidcraft_commerce_stars => $sidcraft_commerce_n ) : ?>
				<?php $sidcraft_commerce_pct = $sidcraft_commerce_count ? round( 100 * $sidcraft_commerce_n / $sidcraft_commerce_count ) : 0; ?>
				<li>
					<?php /* translators: %d: stars. */ ?>
					<span class="sc-reviews__bar-label"><?php echo esc_html( sprintf( _n( '%d star', '%d stars', $sidcraft_commerce_stars, 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_stars ) ); ?></span>
					<span class="sc-reviews__bar" aria-hidden="true"><span style="width:<?php echo esc_attr( $sidcraft_commerce_pct ); ?>%"></span></span>
					<span class="sc-reviews__bar-count"><?php echo esc_html( number_format_i18n( $sidcraft_commerce_n ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="sc-reviews__cta">
			<p class="sc-reviews__cta-title"><?php esc_html_e( 'Bought this? Tap a star to rate it', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
			<?php echo rate_stars_markup( $product, 'lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
			<button type="button" class="sc-btn sc-btn--primary" data-sc-review-open data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"><?php esc_html_e( 'Write a review', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
			<p class="sc-reviews__note"><?php esc_html_e( 'Only verified customers can review. You’ll need your order number.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		</div>
	</div>

	<p class="sc-reviews__status" role="status" aria-live="polite"></p>

	<?php if ( $reviews ) : ?>
		<ol class="sc-reviews__list">
			<?php foreach ( $reviews as $sidcraft_commerce_r ) : ?>
				<li class="sc-review" id="review-<?php echo esc_attr( $sidcraft_commerce_r['id'] ); ?>">
					<div class="sc-review__head">
						<?php echo star_markup( $sidcraft_commerce_r['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
						<span class="sc-review__name"><?php echo esc_html( $sidcraft_commerce_r['name'] ); ?></span>
						<?php if ( $sidcraft_commerce_r['verified'] ) : ?>
							<span class="sc-review__verified">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><path d="M20 6 9 17l-5-5"/></svg>
								<?php esc_html_e( 'Verified purchase', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
							</span>
						<?php endif; ?>
						<span class="sc-review__date"><?php echo esc_html( $sidcraft_commerce_r['date'] ); ?></span>
					</div>
					<div class="sc-review__text"><?php echo wp_kses_post( wpautop( esc_html( $sidcraft_commerce_r['text'] ) ) ); ?></div>
					<?php
					/**
					 * After a review's text (photos).
					 *
					 * @param array $review Review: id, rating, name, date, text, verified.
					 */
					do_action( 'sidcraft_commerce_review_after_text', $sidcraft_commerce_r );
					?>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php else : ?>
		<p class="sc-reviews__empty"><?php esc_html_e( 'Be the first to review this product.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
	<?php endif; ?>

	<template data-sc-review-form>
		<form class="sc-rv-form" novalidate>
			<h3 class="sc-rv-form__title" id="sc-rv-title"><?php esc_html_e( 'Rate & review', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h3>
			<p class="sc-rv-form__product"><?php echo esc_html( $product->get_name() ); ?></p>
			<p class="sc-notice sc-notice--error" data-sc-rv-error role="alert" hidden></p>

			<input type="hidden" name="product_id" value="<?php echo esc_attr( $product->get_id() ); ?>">

			<fieldset class="sc-rv-stars">
				<legend><?php esc_html_e( 'Your rating', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <span class="sc-req" aria-hidden="true">*</span></legend>
				<div class="sc-rv-stars__row">
					<?php for ( $sidcraft_commerce_i = 1; $sidcraft_commerce_i <= 5; $sidcraft_commerce_i++ ) : ?>
						<input type="radio" id="sc-rv-star-<?php echo esc_attr( $sidcraft_commerce_i ); ?>" name="rating" value="<?php echo esc_attr( $sidcraft_commerce_i ); ?>">
						<?php /* translators: %d: stars. */ ?>
						<label for="sc-rv-star-<?php echo esc_attr( $sidcraft_commerce_i ); ?>" title="<?php echo esc_attr( sprintf( _n( '%d star', '%d stars', $sidcraft_commerce_i, 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_i ) ); ?>"><span aria-hidden="true">★</span><span class="sc-sr-only"><?php echo esc_html( sprintf( _n( '%d star', '%d stars', $sidcraft_commerce_i, 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidcraft_commerce_i ) ); ?></span></label>
					<?php endfor; ?>
				</div>
				<span class="sc-field__error" id="sc-rv-rating-error"></span>
			</fieldset>

			<div class="sc-fields">
				<p class="sc-field sc-field--half">
					<label for="sc-rv-order_number"><?php esc_html_e( 'Order number', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <span class="sc-req" aria-hidden="true">*</span></label>
					<input type="text" id="sc-rv-order_number" name="order_number" inputmode="numeric" autocomplete="off" placeholder="<?php esc_attr_e( 'e.g. 1042', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" required aria-describedby="sc-rv-order_number-error sc-rv-order-hint">
					<span class="sc-rv-hint" id="sc-rv-order-hint"><?php esc_html_e( 'In your order confirmation email.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span>
					<span class="sc-field__error" id="sc-rv-order_number-error"></span>
				</p>
				<p class="sc-field sc-field--half">
					<label for="sc-rv-email"><?php esc_html_e( 'Order email', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <span class="sc-req" aria-hidden="true">*</span></label>
					<input type="email" id="sc-rv-email" name="email" autocomplete="email" required aria-describedby="sc-rv-email-error sc-rv-email-hint">
					<span class="sc-rv-hint" id="sc-rv-email-hint"><?php esc_html_e( 'Used to verify your order. Never shown.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span>
					<span class="sc-field__error" id="sc-rv-email-error"></span>
				</p>
				<p class="sc-field sc-field--full">
					<label for="sc-rv-review"><?php esc_html_e( 'Your review', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <span class="sc-req" aria-hidden="true">*</span></label>
					<textarea id="sc-rv-review" name="review" rows="5" maxlength="5000" required aria-describedby="sc-rv-review-error" placeholder="<?php esc_attr_e( 'What did you like or dislike? How are you using it?', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></textarea>
					<span class="sc-field__error" id="sc-rv-review-error"></span>
				</p>
				<p class="sc-field sc-field--full">
					<label for="sc-rv-name"><?php esc_html_e( 'Name shown with your review (optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
					<input type="text" id="sc-rv-name" name="name" maxlength="60" autocomplete="nickname" placeholder="<?php esc_attr_e( 'Defaults to your first name and last initial', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
				</p>
				<?php
				/**
				 * More fields in the review form (photo upload).
				 *
				 * @param \SidcraftCommerce\Data\Product $product Product.
				 */
				do_action( 'sidcraft_commerce_review_form_fields', $product );
				?>
				<p class="sc-rv-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
			</div>

			<div class="sc-rv-form__actions">
				<button type="button" class="sc-btn sc-btn--ghost" data-sc-rv-cancel><?php esc_html_e( 'Cancel', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
				<button type="submit" class="sc-btn sc-btn--primary" data-sc-rv-submit><?php esc_html_e( 'Submit review', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
			</div>
		</form>
	</template>
</section>
