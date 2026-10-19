<?php
/**
 * Shipping zones by country, each with a flat rate, plus free shipping over
 * an amount.
 *
 * Settings (Store → Settings → Shipping):
 *   shipping_zones      Rows: name, countries (ISO codes, comma-separated,
 *                       or * for everywhere else), rate, per weight (optional:
 *                       added per unit of weight, e.g. per kg), free over (optional).
 *   shipping_free_over  Free shipping when the subtotal reaches this (0 = off).
 *                       A zone's own "free over" wins.
 *   shipping_flat       Rate for everywhere when no zones are set.
 *
 * With zones and no * row, countries outside every zone can't check out.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Class Shipping
 */
class Shipping {

	/**
	 * Clean zone rows.
	 *
	 * @return array[] [ name, countries[], rate, per_weight, free_over|null ].
	 */
	public static function zones() {
		$zones = array();
		foreach ( (array) setting( 'shipping_zones', array() ) as $row ) {
			$countries = array_filter( array_map( 'trim', explode( ',', strtoupper( (string) ( $row['countries'] ?? '' ) ) ) ) );
			if ( ! $countries ) {
				continue;
			}
			$free    = trim( (string) ( $row['free_over'] ?? '' ) );
			$zones[] = array(
				'name'      => trim( (string) ( $row['name'] ?? '' ) ),
				'countries' => array_values( $countries ),
				'rate'       => max( 0.0, (float) ( $row['rate'] ?? 0 ) ),
				'per_weight' => max( 0.0, (float) ( $row['per_weight'] ?? 0 ) ),
				'free_over'  => is_numeric( $free ) ? max( 0.0, (float) $free ) : null,
			);
		}
		/**
		 * Filter shipping zones.
		 *
		 * @param array $zones Zones.
		 */
		return (array) apply_filters( 'sidcraft_commerce_shipping_zones', $zones );
	}

	/**
	 * Zone for a country: an exact match first, then the * zone. With no
	 * zones configured, one zone (flat rate) covers everywhere.
	 *
	 * @param string $country Country code.
	 * @return array|null Null when the store doesn't ship there.
	 */
	public static function zone_for( $country ) {
		$zones = self::zones();
		if ( ! $zones ) {
			return array(
				'name'      => '',
				'countries' => array( '*' ),
				'rate'       => max( 0.0, (float) setting( 'shipping_flat', 0 ) ),
				'per_weight' => 0.0,
				'free_over'  => null,
			);
		}
		$country  = strtoupper( (string) $country );
		$fallback = null;
		foreach ( $zones as $zone ) {
			if ( in_array( $country, $zone['countries'], true ) ) {
				return $zone;
			}
			if ( null === $fallback && in_array( '*', $zone['countries'], true ) ) {
				$fallback = $zone;
			}
		}
		return $fallback;
	}

	/**
	 * Countries the store ships to ([] = everywhere).
	 *
	 * @return string[]
	 */
	public static function ship_to_countries() {
		$zones = self::zones();
		$codes = array();
		foreach ( $zones as $zone ) {
			if ( in_array( '*', $zone['countries'], true ) ) {
				return array();
			}
			$codes = array_merge( $codes, $zone['countries'] );
		}
		return array_values( array_unique( $codes ) );
	}

	/**
	 * Price shipping for a subtotal, destination and weight.
	 *
	 * Zone rates are typed in the store currency; the quote is in the
	 * shopper's (the subtotal is too).
	 *
	 * @param float  $subtotal Order subtotal (in the shopper's currency).
	 * @param string $country  Country code.
	 * @param float  $weight   Weight of what ships (store unit), for per-weight rates.
	 * @return array [ available, cost, zone, free, free_over, remaining ].
	 */
	public static function quote( $subtotal, $country, $weight = 0.0 ) {
		$zone = self::zone_for( $country );
		if ( ! $zone ) {
			return array(
				'available' => false,
				'cost'      => 0.0,
				'zone'      => '',
				'free'      => false,
				'free_over' => 0.0,
				'remaining' => 0.0,
			);
		}
		$free_over = null !== $zone['free_over'] ? $zone['free_over'] : (float) setting( 'shipping_free_over', 0 );
		$free_over = Currencies::to_active( (float) $free_over );
		$cost      = Currencies::to_active( (float) $zone['rate'] + (float) ( $zone['per_weight'] ?? 0 ) * max( 0.0, (float) $weight ) );
		$free      = $cost <= 0 || ( $free_over > 0 && $subtotal >= $free_over );
		return array(
			'available' => true,
			'cost'      => $free ? 0.0 : round_amount( $cost ),
			'zone'      => $zone['name'],
			'free'      => $free,
			'free_over' => (float) $free_over,
			'remaining' => ( ! $free && $free_over > 0 ) ? round_amount( $free_over - $subtotal ) : 0.0,
		);
	}
}
