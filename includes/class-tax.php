<?php
/**
 * Sales tax / VAT by country and state.
 *
 * Settings (Store → Settings → Tax):
 *   tax_enabled         Turn tax on.
 *   tax_prices_include  'excl' (tax added at checkout) or 'incl' (prices
 *                       already contain tax; it is shown as "Includes …").
 *   tax_shipping        Tax shipping at the same rate.
 *   tax_label           Default label ("Tax", "VAT", "GST").
 *   tax_rates           Rows: country (ISO code or *), state (optional
 *                       code), rate %, label (optional), class (optional
 *                       tax class name; empty = standard rate).
 *   tax_classes         Extra classes, one name per line (e.g. Reduced rate,
 *                       Zero rate). A product picks one under Product data.
 *
 * The buyer's country/state picks the row: country + state, then country,
 * then *. No match means no tax. A product in a tax class uses that class's
 * rows; where the class has no row for the place, the standard rate applies
 * (add a 0% row for a zero-rated class).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tax
 */
class Tax {

	/**
	 * Enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) setting( 'tax_enabled', 0 );
	}

	/**
	 * Prices are entered including tax.
	 *
	 * @return bool Prices are entered including tax.
	 */
	public static function prices_include_tax() {
		return 'incl' === setting( 'tax_prices_include', 'excl' );
	}

	/**
	 * Tax classes besides the standard rate: slug => name.
	 *
	 * @return array
	 */
	public static function classes() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) setting( 'tax_classes', '' ) ) as $name ) {
			$name = trim( sanitize_text_field( $name ) );
			if ( '' !== $name && '' !== sanitize_title( $name ) ) {
				$out[ sanitize_title( $name ) ] = $name;
			}
		}
		/**
		 * Filter the tax classes: slug => name.
		 *
		 * @param array $out Classes.
		 */
		return (array) apply_filters( 'sidcraft_commerce_tax_classes', $out );
	}

	/**
	 * Clean rate rows.
	 *
	 * @return array[] [ country, state, rate (float %), label, class (slug, '' = standard) ].
	 */
	public static function rates() {
		$rows = array();
		foreach ( (array) setting( 'tax_rates', array() ) as $row ) {
			$country = strtoupper( trim( (string) ( $row['country'] ?? '' ) ) );
			if ( '' === $country || ! is_numeric( $row['rate'] ?? '' ) ) {
				continue;
			}
			$rows[] = array(
				'country' => $country,
				'state'   => strtoupper( trim( (string) ( $row['state'] ?? '' ) ) ),
				'rate'    => max( 0.0, (float) $row['rate'] ),
				'label'   => trim( (string) ( $row['label'] ?? '' ) ),
				'class'   => sanitize_title( (string) ( $row['class'] ?? '' ) ),
			);
		}
		/**
		 * Filter tax rate rows (e.g. load them from a tax service).
		 *
		 * @param array $rows Rows.
		 */
		return (array) apply_filters( 'sidcraft_commerce_tax_rates', $rows );
	}

	/**
	 * Rate for a location.
	 *
	 * @param string $country Country code.
	 * @param string $state   State code.
	 * @param string $class   Tax class slug ('' = standard). A class with no
	 *                        row for the place falls back to the standard rate.
	 * @return array [ 'rate' => float %, 'label' => string ].
	 */
	public static function rate_for( $country, $state = '', $class = '' ) {
		$country = strtoupper( (string) $country );
		$state   = strtoupper( (string) $state );
		$class   = sanitize_title( (string) $class );
		$best    = null;
		$score   = -1;
		foreach ( self::rates() as $row ) {
			if ( ( $row['class'] ?? '' ) !== $class ) {
				continue;
			}
			if ( $row['country'] === $country && '' !== $row['state'] && $row['state'] === $state ) {
				$s = 3;
			} elseif ( $row['country'] === $country && '' === $row['state'] ) {
				$s = 2;
			} elseif ( '*' === $row['country'] ) {
				$s = 1;
			} else {
				continue;
			}
			if ( $s > $score ) {
				$best  = $row;
				$score = $s;
			}
		}
		if ( ! $best && '' !== $class ) {
			return self::rate_for( $country, $state );
		}
		$label = $best && '' !== $best['label'] ? $best['label'] : (string) setting( 'tax_label', '' );
		return array(
			'rate'  => $best ? $best['rate'] : 0.0,
			'label' => '' !== $label ? $label : __( 'Tax', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Tax contained in (inclusive) or added to (exclusive) an amount.
	 *
	 * @param float $amount    Amount.
	 * @param float $rate      Rate in %.
	 * @param bool  $inclusive Amount already includes tax.
	 * @return float
	 */
	public static function portion( $amount, $rate, $inclusive ) {
		$r = (float) $rate / 100;
		if ( $r <= 0 || $amount <= 0 ) {
			return 0.0;
		}
		return round_amount( $inclusive ? $amount - $amount / ( 1 + $r ) : $amount * $r );
	}

	/**
	 * Tax for a set of line totals plus shipping.
	 *
	 * Shipping is taxed at the standard rate. With lines in several classes
	 * the result's rate is 0 (the label then shows no single percentage).
	 *
	 * @param float[]  $lines    Line totals.
	 * @param float    $shipping Shipping amount.
	 * @param array    $location [ country, state ].
	 * @param string[] $classes  Tax class of each line (same keys as $lines; missing = standard).
	 * @return array [ items, shipping, total, rate, label, inclusive ].
	 */
	public static function calculate( array $lines, $shipping, array $location, array $classes = array() ) {
		$inclusive = self::prices_include_tax();
		$out       = array(
			'items'     => 0.0,
			'shipping'  => 0.0,
			'total'     => 0.0,
			'rate'      => 0.0,
			'label'     => '',
			'inclusive' => $inclusive,
		);
		if ( ! self::enabled() ) {
			return $out;
		}
		$found        = self::rate_for( $location['country'] ?? '', $location['state'] ?? '' );
		$out['rate']  = $found['rate'];
		$out['label'] = $found['label'];
		$rates = array();
		foreach ( $lines as $k => $line ) {
			$rate = '' !== (string) ( $classes[ $k ] ?? '' ) ? self::rate_for( $location['country'] ?? '', $location['state'] ?? '', $classes[ $k ] )['rate'] : $found['rate'];
			if ( (float) $line > 0 ) {
				$rates[ (string) $rate ] = true;
			}
			$out['items'] += self::portion( (float) $line, $rate, $inclusive ); // Rounded per line.
		}
		if ( count( $rates ) > 1 ) {
			$out['rate'] = 0.0; // Mixed rates: no single percentage to show.
		} elseif ( 1 === count( $rates ) ) {
			$out['rate'] = (float) key( $rates ); // Every taxed line at one rate (perhaps a class's).
		}
		if ( setting( 'tax_shipping', 0 ) ) {
			$out['shipping'] = self::portion( (float) $shipping, $found['rate'], $inclusive );
		}
		$out['items'] = round_amount( $out['items'] );
		$out['total'] = round_amount( $out['items'] + $out['shipping'] );

		/**
		 * Filter the calculated tax.
		 *
		 * @param array $out      Result.
		 * @param array $lines    Line totals.
		 * @param float $shipping Shipping.
		 * @param array $location Location.
		 */
		return (array) apply_filters( 'sidcraft_commerce_tax', $out, $lines, $shipping, $location );
	}

	/**
	 * Display label with rate, e.g. "VAT (20%)".
	 *
	 * @param string $label Label.
	 * @param float  $rate  Rate %.
	 * @return string
	 */
	public static function label_with_rate( $label, $rate ) {
		if ( $rate <= 0 ) {
			return (string) $label;
		}
		/* translators: 1: tax label, 2: rate. */
		return sprintf( __( '%1$s (%2$s%%)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $label, rtrim( rtrim( number_format( (float) $rate, 3, '.', '' ), '0' ), '.' ) );
	}
}
