<?php
/**
 * Shared helpers: settings, money, product lookup, stars, sanitising.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Data\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Read a store setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $fallback Fallback.
 * @return mixed
 */
function setting( $key, $fallback = null ) {
	static $settings = null;
	if ( null === $settings || doing_action( 'update_option_sidcraft_commerce_settings' ) ) {
		$settings = (array) get_option( 'sidcraft_commerce_settings', array() );
	}
	$value = array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;

	/**
	 * Filter any store setting.
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Key.
	 */
	return apply_filters( 'sidcraft_commerce_setting', $value, $key );
}

/**
 * ID of a store page: shop, cart, checkout, received.
 *
 * @param string $key Page key.
 * @return int
 */
function page_id( $key ) {
	$pages = (array) get_option( 'sidcraft_commerce_pages', array() );
	$id    = absint( $pages[ $key ] ?? 0 );
	// The page in the shopper's language when it is translated.
	return $id && class_exists( Multilingual::class ) ? Multilingual::current_id( $id ) : $id;
}

/**
 * URL of a store page.
 *
 * @param string $key Page key.
 * @return string
 */
function page_url( $key ) {
	$id = page_id( $key );
	return $id ? get_permalink( $id ) : home_url( '/' );
}

/**
 * Name of one of the store's cookies. On a Multisite network every store
 * but the main site gets its own (…_3), so a shopper's cart, currency or
 * referral on one store never mixes with another's.
 *
 * @param string $base Base name.
 * @return string
 */
function cookie_name( $base ) {
	return is_multisite() && ! is_main_site() ? $base . '_' . get_current_blog_id() : $base;
}

/**
 * Currency amounts are in right now (ISO 4217, upper case): the shopper's
 * currency on the storefront, the store currency in admin, or the one an
 * order is being shown in (see Currencies).
 *
 * @return string
 */
function currency() {
	return class_exists( Currencies::class ) ? Currencies::active() : base_currency();
}

/**
 * The store currency: what prices and other amounts are typed in.
 *
 * @return string
 */
function base_currency() {
	return strtoupper( (string) setting( 'currency', 'USD' ) );
}

/**
 * A store-currency amount in the current currency (rounded to its decimals).
 *
 * @param float $amount Store-currency amount.
 * @return float
 */
function money( $amount ) {
	return class_exists( Currencies::class ) ? Currencies::to_active( (float) $amount ) : (float) $amount;
}

/**
 * Parse a typed price ("1,299.50", "12,5", "1.299.50") into a plain decimal
 * string, or '' when it isn't a non-negative number.
 *
 * @param mixed $raw Typed value.
 * @return string
 */
function parse_price( $raw ) {
	$raw = trim( str_replace( array( ' ', ',' ), array( '', '.' ), (string) $raw ) );
	// "1.299.50" (thousand dots) → keep the last dot as decimal point.
	if ( substr_count( $raw, '.' ) > 1 ) {
		$last = strrpos( $raw, '.' );
		$raw  = str_replace( '.', '', substr( $raw, 0, $last ) ) . substr( $raw, $last );
	}
	return is_numeric( $raw ) && (float) $raw >= 0 ? (string) round( (float) $raw, 4 ) : '';
}

/**
 * Currency symbol: the custom one from settings, else a lookup.
 *
 * @return string
 */
function currency_symbol() {
	$code   = currency();
	$custom = $code === base_currency() ? (string) setting( 'currency_symbol', '' ) : ( class_exists( Currencies::class ) ? Currencies::format_of( $code )['symbol'] : '' );
	if ( '' !== $custom ) {
		return $custom;
	}
	$symbols = array(
		'USD' => '$',
		'CAD' => 'CA$',
		'AUD' => 'A$',
		'EUR' => '€',
		'GBP' => '£',
		'INR' => '₹',
		'JPY' => '¥',
		'CNY' => '¥',
		'AED' => 'د.إ',
		'PKR' => '₨',
		'CHF' => 'CHF ',
		'SGD' => 'S$',
	);
	return $symbols[ $code ] ?? $code . ' ';
}

/**
 * Symbol position for the current currency: left or right.
 *
 * @return string
 */
function currency_position() {
	$code = currency();
	if ( $code !== base_currency() && class_exists( Currencies::class ) ) {
		$pos = Currencies::format_of( $code )['position'];
		if ( '' !== $pos ) {
			return $pos;
		}
	}
	return 'right' === setting( 'currency_position', 'left' ) ? 'right' : 'left';
}

/**
 * Number of decimals for the store currency.
 *
 * @return int
 */
function decimals() {
	return decimals_for( currency() );
}

/**
 * Decimal places for an ISO currency.
 *
 * Zero-decimal currencies (JPY, KRW, VND, and the rest of Stripe's set) use 0.
 * Three-decimal currencies (BHD, JOD, KWD, OMR, TND) use 3. Every other
 * currency uses the store setting when it is the store currency, otherwise
 * its Decimals under Store → Settings → Currencies, else 2.
 *
 * @param string $currency ISO code. Empty uses the store currency.
 * @return int
 */
function decimals_for( $currency = '' ) {
	$currency = strtoupper( (string) ( '' !== $currency ? $currency : currency() ) );
	if ( in_array( $currency, zero_decimal_currencies(), true ) ) {
		return 0;
	}
	if ( in_array( $currency, three_decimal_currencies(), true ) ) {
		return 3;
	}
	if ( $currency === base_currency() ) {
		return max( 0, min( 4, (int) setting( 'decimals', 2 ) ) );
	}
	if ( class_exists( Currencies::class ) ) {
		$own = Currencies::format_of( $currency )['decimals'];
		if ( null !== $own ) {
			return (int) $own;
		}
	}
	return 2;
}

/**
 * Stripe currencies with no minor unit.
 *
 * @return string[]
 */
function zero_decimal_currencies() {
	return array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
}

/**
 * Currencies whose minor unit is 1/1000 (Stripe and PayPal).
 *
 * @return string[]
 */
function three_decimal_currencies() {
	return array( 'BHD', 'JOD', 'KWD', 'OMR', 'TND' );
}

/**
 * Currencies Stripe treats as having no minor unit.
 *
 * @return bool
 */
function zero_decimal_currency() {
	return in_array( currency(), zero_decimal_currencies(), true );
}

/**
 * Store currency uses a 1/1000 minor unit (BHD, JOD, KWD, OMR, TND).
 *
 * @return bool
 */
function three_decimal_currency() {
	return in_array( currency(), three_decimal_currencies(), true );
}

/**
 * Multiplier from major units to the provider's minor unit.
 *
 * @param string $currency ISO code. Empty uses the store currency.
 * @return int 1, 100, or 1000.
 */
function minor_unit_factor( $currency = '' ) {
	$currency = strtoupper( (string) ( '' !== $currency ? $currency : currency() ) );
	if ( in_array( $currency, zero_decimal_currencies(), true ) ) {
		return 1;
	}
	if ( in_array( $currency, three_decimal_currencies(), true ) ) {
		return 1000;
	}
	return 100;
}

/**
 * Round an amount to the store's precision.
 *
 * @param float|string $amount Amount.
 * @return float
 */
function round_amount( $amount ) {
	return round( (float) $amount, decimals() );
}

/**
 * Round a store-currency amount (gift card balances, commissions) to the
 * store's precision, whatever currency the shopper is using.
 *
 * @param float|string $amount Amount.
 * @return float
 */
function round_base( $amount ) {
	return round( (float) $amount, decimals_for( base_currency() ) );
}

/**
 * Amount in the smallest currency unit (cents) for payment gateways.
 *
 * @param float $amount Amount.
 * @return int
 */
function to_minor_units( $amount, $currency = '' ) {
	return (int) round( (float) $amount * minor_unit_factor( $currency ) );
}

/**
 * Major units from a provider minor-unit integer.
 *
 * @param int    $amount   Minor units (cents, or fils for three-decimal currencies).
 * @param string $currency ISO code. Empty uses the store currency.
 * @return float
 */
function from_minor_units( $amount, $currency = '' ) {
	$factor = minor_unit_factor( $currency );
	return round( ( (float) $amount ) / $factor, decimals_for( $currency ) );
}

/**
 * Format a price for display, e.g. "$1,299.00".
 *
 * @param float|string $amount Amount.
 * @return string Plain text (escape on output).
 */
function format_price( $amount ) {
	$number = number_format(
		abs( (float) $amount ),
		decimals(),
		(string) setting( 'decimal_sep', '.' ),
		(string) setting( 'thousand_sep', ',' )
	);
	$symbol = currency_symbol();
	$out    = 'right' === currency_position() ? $number . $symbol : $symbol . $number;
	$out    = ( (float) $amount < 0 ? '-' : '' ) . $out;

	/**
	 * Filter a formatted price.
	 *
	 * @param string $out    Formatted.
	 * @param float  $amount Raw amount.
	 */
	return (string) apply_filters( 'sidcraft_commerce_format_price', $out, (float) $amount );
}

/**
 * Format a store-currency amount (commissions, gift card balances in admin)
 * in the store currency, whatever currency the shopper is using.
 *
 * @param float|string $amount Amount.
 * @return string Plain text (escape on output).
 */
function format_base( $amount ) {
	return Currencies::in_base(
		static function () use ( $amount ) {
			return format_price( $amount );
		}
	);
}

/**
 * Format an amount in a given currency (e.g. an order's).
 *
 * @param float|string $amount Amount.
 * @param string       $code   Currency code.
 * @return string Plain text (escape on output).
 */
function format_in( $amount, $code ) {
	return Currencies::using(
		(string) $code,
		static function () use ( $amount ) {
			return format_price( $amount );
		}
	);
}

/**
 * Resolve the product a single-product Unit should show.
 * Order: explicit product_id setting → current product page.
 *
 * @param array $settings Unit settings.
 * @return Product|null
 */
function resolve_product( array $settings = array() ) {
	$product = null;

	if ( ! empty( $settings['product_id'] ) ) {
		$product = Product::get( absint( $settings['product_id'] ) );
	}

	if ( ! $product ) {
		$post = get_post();
		if ( $post && Data\Post_Types::PRODUCT === $post->post_type ) {
			$product = Product::get( $post->ID );
		}
	}

	/**
	 * Filter the product resolved for a single-product Unit.
	 *
	 * @param Product|null $product  Product or null.
	 * @param array        $settings Unit settings.
	 */
	$product = apply_filters( 'sidcraft_commerce_resolve_product', $product, $settings );

	return $product instanceof Product ? $product : null;
}

/**
 * Star markup (★★★★☆) like ShopWave, with an accessible label.
 *
 * @param float $rating Average rating 0–5.
 * @return string HTML.
 */
function star_markup( $rating ) {
	$rating = max( 0, min( 5, (float) $rating ) );
	$full   = (int) round( $rating );
	$label  = sprintf(
		/* translators: %s: average rating. */
		__( 'Rated %s out of 5', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		number_format_i18n( $rating, 1 )
	);

	return sprintf(
		'<span class="sc-stars" role="img" aria-label="%1$s"><span aria-hidden="true">%2$s%3$s</span></span>',
		esc_attr( $label ),
		str_repeat( '★', $full ),
		str_repeat( '☆', 5 - $full )
	);
}

/**
 * Clickable stars: they show the average rating and each one opens the
 * verified-review popup with that many stars picked (sc-reviews.js). Without
 * JavaScript they link to the product's reviews section.
 *
 * @param \SidcraftCommerce\Data\Product $product Product.
 * @param string                         $size    sm | md | lg.
 * @return string HTML.
 */
function rate_stars_markup( $product, $size = 'md' ) {
	$avg   = max( 0, min( 5, (float) $product->get_average_rating() ) );
	$full  = (int) round( $avg );
	$label = $product->get_review_count()
		/* translators: %s: average rating. */
		? sprintf( __( 'Rated %s out of 5. Choose a star to write a review.', 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( $avg, 1 ) )
		: __( 'No ratings yet. Choose a star to write a review.', 'sidcraft-commerce-for-sidcraft-page-builder' );
	$url   = $product->get_permalink() . '#reviews';

	$html = '<span class="sc-rate sc-rate--' . esc_attr( $size ) . '" role="group" aria-label="' . esc_attr( $label ) . '">';
	for ( $i = 1; $i <= 5; $i++ ) {
		$html .= sprintf(
			'<a class="sc-rate__star%1$s" href="%2$s" data-sc-review-open data-product-id="%3$d" data-rating="%4$d" aria-label="%5$s">★</a>',
			$i <= $full ? ' is-on' : '',
			esc_url( $url ),
			(int) $product->get_id(),
			$i,
			/* translators: %d: stars. */
			esc_attr( sprintf( _n( 'Rate %d star', 'Rate %d stars', $i, 'sidcraft-commerce-for-sidcraft-page-builder' ), $i ) )
		);
	}
	return $html . '</span>';
}

/**
 * Turn raw Unit settings into validated, defaulted settings.
 *
 * @param array $settings Raw settings.
 * @param array $controls Control schema from Unit::get_controls().
 * @return array
 */
function sanitize_settings( array $settings, array $controls ) {
	$clean = array();
	foreach ( $controls as $key => $control ) {
		$value = array_key_exists( $key, $settings ) ? $settings[ $key ] : ( $control['default'] ?? '' );

		switch ( $control['type'] ?? 'text' ) {
			case 'number':
				$value = is_numeric( $value ) ? 0 + $value : ( $control['default'] ?? 0 );
				if ( isset( $control['min'] ) ) {
					$value = max( $control['min'], $value );
				}
				if ( isset( $control['max'] ) ) {
					$value = min( $control['max'], $value );
				}
				break;
			case 'switch':
				$value = in_array( $value, array( true, 1, '1', 'yes', 'true', 'on' ), true );
				break;
			case 'select':
				$options = array_keys( $control['options'] ?? array() );
				$value   = in_array( (string) $value, array_map( 'strval', $options ), true ) ? $value : ( $control['default'] ?? '' );
				break;
			case 'color':
				$value = sanitize_hex_color( (string) $value ) ? sanitize_hex_color( (string) $value ) : '';
				break;
			default:
				$value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( (string) $value );
		}

		$clean[ $key ] = $value;
	}
	return $clean;
}

/**
 * Capability needed to manage orders and store settings.
 *
 * @return string
 */
function manage_cap() {
	return (string) apply_filters( 'sidcraft_commerce_manage_capability', 'manage_options' );
}

/**
 * Totals rows for a cart or an order, shared by the cart panel, checkout
 * summary, confirmation page, emails and the admin order screen.
 *
 * Each row: [ 'key', 'label', 'value', 'class' ]. Keys: subtotal, discount,
 * coupon_notice, shipping, tax, gift_card, gift_card_notice, total,
 * tax_included, free_shipping_hint.
 *
 * @param \SidcraftCommerce\Cart\Cart|\SidcraftCommerce\Data\Order $source Cart or order.
 * @return array[]
 */
function totals_rows( $source ) {
	if ( $source instanceof \SidcraftCommerce\Data\Order ) {
		// In the currency the order was placed in.
		return Currencies::using(
			$source->get_currency(),
			static function () use ( $source ) {
				return totals_rows_for( $source );
			}
		);
	}
	return totals_rows_for( $source );
}

/**
 * totals_rows() in the current currency.
 *
 * @param \SidcraftCommerce\Cart\Cart|\SidcraftCommerce\Data\Order $source Cart or order.
 * @return array[]
 */
function totals_rows_for( $source ) {
	$is_cart = $source instanceof \SidcraftCommerce\Cart\Cart;
	$rows    = array(
		array(
			'key'   => 'subtotal',
			'label' => __( 'Subtotal', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'value' => format_price( $source->get_subtotal() ),
			'class' => '',
		),
	);

	$discount = (float) $source->get_discount();
	if ( $discount > 0 ) {
		$code   = $source->get_coupon_code();
		$rows[] = array(
			'key'   => 'discount',
			/* translators: %s: coupon code. */
			'label' => '' !== $code ? sprintf( __( 'Coupon: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $code ) : __( 'Discount', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'value' => format_price( -1 * $discount ),
			'class' => 'sc-totals__discount',
		);
	} elseif ( $is_cart ? $source->has_free_shipping_coupon() : ( '' !== $source->get_coupon_code() && get_post_meta( $source->get_id(), '_sidccofo_coupon_free_shipping', true ) ) ) {
		// A coupon whose only benefit is free shipping.
		$rows[] = array(
			'key'   => 'discount',
			/* translators: %s: coupon code. */
			'label' => sprintf( __( 'Coupon: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $source->get_coupon_code() ),
			'value' => __( 'Free shipping', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'class' => 'sc-totals__discount',
		);
	} elseif ( $is_cart && '' !== $source->get_coupon_error() ) {
		$rows[] = array(
			'key'   => 'coupon_notice',
			'label' => $source->get_coupon_error(),
			'value' => '',
			'class' => 'sc-totals__note sc-totals__coupon-error',
		);
	}

	$ships = $is_cart ? ( (bool) setting( 'collect_address', 1 ) && $source->needs_shipping() ) : ( $source->get_shipping() > 0 || '' !== $source->get_shipping_zone() );
	if ( $ships ) {
		$label = __( 'Shipping', 'sidcraft-commerce-for-sidcraft-page-builder' );
		if ( $is_cart ) {
			$quote = $source->get_shipping_quote();
			$loc   = $source->get_location();
			/* translators: %s: country. */
			$label = sprintf( __( 'Shipping to %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), \SidcraftCommerce\Locations::country_name( $loc['country'] ) );
			if ( ! $quote['available'] ) {
				$value = __( 'Not available', 'sidcraft-commerce-for-sidcraft-page-builder' );
			} else {
				$value = $quote['cost'] > 0 ? format_price( $quote['cost'] ) : __( 'Free', 'sidcraft-commerce-for-sidcraft-page-builder' );
			}
		} else {
			$value = $source->get_shipping() > 0 ? format_price( $source->get_shipping() ) : __( 'Free', 'sidcraft-commerce-for-sidcraft-page-builder' );
			if ( '' !== $source->get_shipping_zone() ) {
				$label .= ' (' . $source->get_shipping_zone() . ')';
			}
		}
		$rows[] = array(
			'key'   => 'shipping',
			'label' => $label,
			'value' => $value,
			'class' => '',
		);
	}

	if ( $is_cart ) {
		$details = $source->get_tax_details();
		$tax     = (float) $details['total'];
		$tlabel  = Tax::label_with_rate( $details['label'], $details['rate'] );
	} else {
		$tax    = $source->get_tax();
		$tlabel = Tax::label_with_rate( $source->get_tax_label(), $source->get_tax_rate() );
	}
	$inclusive = $source->prices_include_tax();
	if ( $tax > 0 && ! $inclusive ) {
		$rows[] = array(
			'key'   => 'tax',
			'label' => $tlabel,
			'value' => format_price( $tax ),
			'class' => '',
		);
	}

	// Gift cards pay part (or all) of the total.
	if ( $is_cart ) {
		foreach ( $source->get_applied_gift_cards() as $applied ) {
			if ( '' !== $applied['error'] ) {
				$rows[] = array(
					'key'   => 'gift_card_notice',
					/* translators: 1: masked code, 2: reason. */
					'label' => sprintf( __( 'Gift card %1$s: %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $applied['card']->get_masked_code(), $applied['error'] ),
					'value' => '',
					'class' => 'sc-totals__note sc-totals__coupon-error',
				);
				continue;
			}
			$rows[] = array(
				'key'   => 'gift_card',
				/* translators: %s: masked code. */
				'label' => sprintf( __( 'Gift card %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $applied['card']->get_masked_code() ),
				'value' => format_price( -1 * $applied['amount'] ),
				'class' => 'sc-totals__discount',
			);
		}
	} elseif ( $source->get_gift_card_total() > 0 ) {
		$rows[] = array(
			'key'   => 'gift_card',
			'label' => _n( 'Gift card', 'Gift cards', max( 1, count( $source->get_gift_cards_used() ) ), 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'value' => format_price( -1 * $source->get_gift_card_total() ),
			'class' => 'sc-totals__discount',
		);
	}

	$rows[] = array(
		'key'   => 'total',
		'label' => __( 'Total', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		'value' => format_price( $source->get_total() ),
		'class' => 'sc-cart__grand',
	);

	if ( $tax > 0 && $inclusive ) {
		$rows[] = array(
			'key'   => 'tax_included',
			/* translators: 1: amount, 2: tax label. */
			'label' => sprintf( __( 'Includes %1$s %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $tax ), $tlabel ),
			'value' => '',
			'class' => 'sc-totals__note',
		);
	}

	if ( $is_cart && $ships ) {
		$quote = $source->get_shipping_quote();
		if ( $quote['available'] && $quote['remaining'] > 0 ) {
			$rows[] = array(
				'key'   => 'free_shipping_hint',
				/* translators: %s: amount. */
				'label' => sprintf( __( 'Add %s more for free shipping.', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $quote['remaining'] ) ),
				'value' => '',
				'class' => 'sc-totals__note sc-totals__hint',
			);
		}
	}

	/**
	 * Filter totals rows.
	 *
	 * @param array $rows   Rows.
	 * @param mixed $source Cart or Order.
	 */
	return (array) apply_filters( 'sidcraft_commerce_totals_rows', $rows, $source );
}

/**
 * A weight or length with its unit: "1.5 kg", "250 g".
 *
 * @param float  $value Value.
 * @param string $unit  Unit (kg, g, lb, oz, cm, mm, in).
 * @return string
 */
function format_measure( $value, $unit ) {
	return Catalog::number( (float) $value ) . ' ' . $unit;
}

/**
 * A choice's effect on the unit price: "+$2.00", "−$1.50", or '' for none.
 *
 * @param float $price Price change per unit.
 * @return string Plain text.
 */
function option_price_label( $price ) {
	$price = round_amount( (float) $price );
	if ( 0.0 === (float) $price ) {
		return '';
	}
	return ( $price > 0 ? '+' : "\u{2212}" ) . format_price( abs( $price ) );
}

/**
 * A cart or order line's chosen options as HTML (escaped).
 *
 * @param array  $rows  Rows: label, value, price (per unit).
 * @param string $style Optional inline style for the list (emails).
 * @return string
 */
function item_options_html( $rows, $style = '' ) {
	if ( empty( $rows ) || ! is_array( $rows ) ) {
		return '';
	}
	$out = '<ul class="sc-item-options"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';
	foreach ( $rows as $row ) {
		$price = (float) ( $row['price'] ?? 0 );
		$out  .= '<li><span class="sc-item-options__label">' . esc_html( (string) ( $row['label'] ?? '' ) ) . ':</span> '
			. esc_html( (string) ( $row['value'] ?? '' ) )
			. ( 0.0 !== round_amount( $price ) ? ' <span class="sc-item-options__price">(' . esc_html( option_price_label( $price ) ) . ')</span>' : '' )
			. '</li>';
	}
	return $out . '</ul>';
}

/**
 * A line's chosen options as one line of plain text ("Size: Large; Extras: Cheese").
 *
 * @param array $rows Rows: label, value, price.
 * @return string
 */
function item_options_text( $rows ) {
	$parts = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$parts[] = ( $row['label'] ?? '' ) . ': ' . ( $row['value'] ?? '' );
	}
	return implode( '; ', $parts );
}
