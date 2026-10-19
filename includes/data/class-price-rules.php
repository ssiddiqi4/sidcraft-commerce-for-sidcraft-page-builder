<?php
/**
 * Price rules on a product: quantity discounts, specials that start and end
 * on a date, and prices for customer groups.
 *
 * Store owners type them under Product data → Price rules, one per line:
 *
 *     qty 10: 18                         each unit costs 18 when buying 10 or more
 *     10+: 18                            the same, shorter
 *     from 2026-11-27: 79 Black Friday   from that day on (label shown to buyers)
 *     until 2026-11-30 18:00: 79         until that moment
 *     from 2026-11-27 until 2026-11-30: 15% off
 *     group Wholesale: 70                customers in the Wholesale group
 *     group Wholesale qty 50: 60         conditions combine
 *
 * The price after the colon is a unit price, or "N% off" the normal price
 * (the sale price when there is one). Every rule whose conditions hold is a
 * candidate and the lowest price wins; rules never raise the price. The
 * quantity counts every unit of the product in the cart. Dates are in the
 * site's time zone. Groups are customer groups from Store → Settings (or
 * any WordPress role); "group guest" means shoppers who are not logged in.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Data;

use function SidcraftCommerce\parse_price;
use function SidcraftCommerce\round_amount;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Price_Rules
 */
class Price_Rules {

	/**
	 * Role slugs are prefixed with this for groups made in the settings.
	 */
	const ROLE_PREFIX = 'sidccofo_group_';

	/**
	 * Parse rule text.
	 *
	 * @param string $text Rules, one per line.
	 * @return array[] Each: qty, from, until (timestamps, 0 = open), group, price|null, percent|null, label, line.
	 */
	public static function parse( $text ) {
		$rules = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$rule = self::parse_line( $line );
			if ( $rule ) {
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * Lines that could not be understood (shown on the product screen).
	 *
	 * @param string $text Rules.
	 * @return string[]
	 */
	public static function invalid_lines( $text ) {
		$bad = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && 0 !== strpos( $line, '#' ) && ! self::parse_line( $line ) ) {
				$bad[] = $line;
			}
		}
		return $bad;
	}

	/**
	 * Parse one line.
	 *
	 * @param string $line Line.
	 * @return array|null
	 */
	public static function parse_line( $line ) {
		$line = trim( (string) $line );
		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			return null;
		}
		$date  = '\d{4}-\d{2}-\d{2}(?:[ T]\d{1,2}:\d{2})?';
		$token = '(?:\s*(?:(?:qty|quantity)\s+\d+\+?|\d+\+|from\s+' . $date . '|until\s+' . $date . '|(?:group|role)\s+[^:]+?))';
		if ( ! preg_match( '/^(' . $token . '+)\s*:\s*(\d[\d.,]*)\s*(%\s*(?:off)?)?(?:\s+(.*))?$/i', $line, $m ) ) {
			return null;
		}
		$head = $m[1];
		$rule = array(
			'qty'     => 1,
			'from'    => 0,
			'until'   => 0,
			'group'   => '',
			'price'   => null,
			'percent' => null,
			'label'   => sanitize_text_field( (string) ( $m[4] ?? '' ) ),
			'line'    => $line,
		);
		if ( preg_match( '/(?:(?:qty|quantity)\s+(\d+)\+?|(?:^|\s)(\d+)\+)/i', $head, $q ) ) {
			$rule['qty'] = max( 1, (int) ( '' !== $q[1] ? $q[1] : $q[2] ) );
		}
		if ( preg_match( '/from\s+(\d{4}-\d{2}-\d{2})(?:[ T](\d{1,2}:\d{2}))?/i', $head, $d ) ) {
			$rule['from'] = self::to_ts( $d[1], $d[2] ?? '', false );
			if ( ! $rule['from'] ) {
				return null;
			}
		}
		if ( preg_match( '/until\s+(\d{4}-\d{2}-\d{2})(?:[ T](\d{1,2}:\d{2}))?/i', $head, $d ) ) {
			$rule['until'] = self::to_ts( $d[1], $d[2] ?? '', true );
			if ( ! $rule['until'] ) {
				return null;
			}
		}
		if ( preg_match( '/(?:group|role)\s+(.+?)(?=\s+(?:qty|quantity|from|until)\s|\s+\d+\+|$)/i', $head, $g ) ) {
			$rule['group'] = trim( $g[1] );
		}
		$amount = parse_price( $m[2] );
		if ( '' === $amount ) {
			return null;
		}
		if ( ! empty( $m[3] ) ) {
			$rule['percent'] = min( 100.0, (float) $amount );
		} else {
			$rule['price'] = (float) $amount;
		}
		return $rule;
	}

	/**
	 * Local date (and optional time) to a timestamp. "until" a day without a
	 * time lasts through that whole day.
	 *
	 * @param string $date  Y-m-d.
	 * @param string $time  H:i or ''.
	 * @param bool   $until End of day when no time is given.
	 * @return int 0 when invalid.
	 */
	protected static function to_ts( $date, $time, $until ) {
		$time   = '' !== $time ? str_pad( $time, 5, '0', STR_PAD_LEFT ) : ( $until ? '23:59:59' : '00:00:00' );
		$format = strlen( $time ) > 5 ? 'Y-m-d H:i:s' : 'Y-m-d H:i';
		$dt     = \DateTimeImmutable::createFromFormat( $format, $date . ' ' . $time, wp_timezone() );
		// Reject dates PHP would roll over (2026-13-45, 25:00).
		return $dt && $dt->format( $format ) === $date . ' ' . $time ? $dt->getTimestamp() : 0;
	}

	/**
	 * The shopper's groups: role slugs and role names (lower case), or
	 * "guest" when nobody is logged in.
	 *
	 * @return string[]
	 */
	public static function current_groups() {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return array( 'guest' );
		}
		return self::user_groups( wp_get_current_user() );
	}

	/**
	 * A user's groups (role slugs and names, lower case).
	 *
	 * @param \WP_User $user User.
	 * @return string[]
	 */
	public static function user_groups( $user ) {
		$out = array();
		if ( ! $user instanceof \WP_User || ! $user->exists() ) {
			return array( 'guest' );
		}
		$names = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
		foreach ( (array) $user->roles as $role ) {
			$out[] = strtolower( $role );
			if ( 0 === strpos( $role, self::ROLE_PREFIX ) ) {
				$out[] = strtolower( substr( $role, strlen( self::ROLE_PREFIX ) ) );
			}
			if ( isset( $names[ $role ] ) ) {
				$out[] = strtolower( translate_user_role( $names[ $role ] ) );
				$out[] = strtolower( $names[ $role ] );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Does a rule's group match?
	 *
	 * @param string   $group  Rule group ('' = everyone).
	 * @param string[] $groups Shopper's groups.
	 * @return bool
	 */
	protected static function group_matches( $group, array $groups ) {
		if ( '' === $group ) {
			return true;
		}
		$want = strtolower( trim( $group ) );
		return in_array( $want, $groups, true ) || in_array( sanitize_title( $want ), $groups, true ) || in_array( str_replace( '-', '_', sanitize_title( $want ) ), $groups, true );
	}

	/**
	 * Lowest price the rules give.
	 *
	 * @param array[]       $rules  Parsed rules.
	 * @param float         $base   Normal price (sale price when on sale).
	 * @param int           $qty    Units of the product in the cart.
	 * @param string[]|null $groups Shopper's groups (null = current shopper; [] = nobody's, for stored prices).
	 * @param int|null      $now    Time (null = now).
	 * @return array [ price (float), rule (array|null: the winning rule) ].
	 */
	public static function evaluate( array $rules, $base, $qty = 1, $groups = null, $now = null ) {
		$base   = (float) $base;
		$qty    = max( 1, (int) $qty );
		$groups = null === $groups ? self::current_groups() : $groups;
		$now    = null === $now ? time() : (int) $now;
		$best   = round_amount( $base );
		$winner = null;
		foreach ( $rules as $rule ) {
			if ( $qty < $rule['qty'] || ( $rule['from'] && $now < $rule['from'] ) || ( $rule['until'] && $now > $rule['until'] ) ) {
				continue;
			}
			if ( '' !== $rule['group'] && ( ! $groups || ! self::group_matches( $rule['group'], $groups ) ) ) {
				continue;
			}
			$price = null !== $rule['percent'] ? $base * ( 100 - $rule['percent'] ) / 100 : (float) $rule['price'];
			$price = round_amount( max( 0.0, $price ) );
			if ( $price < $best ) {
				$best   = $price;
				$winner = $rule;
			}
		}
		return array(
			'price' => $best,
			'rule'  => $winner,
		);
	}

	/**
	 * Quantity steps for the shopper right now: [ qty, price ] ascending, only
	 * steps that lower the price compared with buying fewer.
	 *
	 * @param array[] $rules Parsed rules.
	 * @param float   $base  Normal price.
	 * @return array[]
	 */
	public static function tiers( array $rules, $base ) {
		$steps = array();
		foreach ( $rules as $rule ) {
			if ( $rule['qty'] > 1 ) {
				$steps[ $rule['qty'] ] = true;
			}
		}
		if ( ! $steps ) {
			return array();
		}
		ksort( $steps );
		$out  = array();
		$last = self::evaluate( $rules, $base, 1 )['price'];
		foreach ( array_keys( $steps ) as $qty ) {
			$price = self::evaluate( $rules, $base, $qty )['price'];
			if ( $price < $last ) {
				$out[] = array(
					'qty'   => (int) $qty,
					'price' => $price,
				);
				$last  = $price;
			}
		}
		return $out;
	}

	/**
	 * Has any rule a start or end date? (Their stored prices are refreshed
	 * hourly so sorting and filters follow the specials.)
	 *
	 * @param array[] $rules Parsed rules.
	 * @return bool
	 */
	public static function has_dates( array $rules ) {
		foreach ( $rules as $rule ) {
			if ( $rule['from'] || $rule['until'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hourly: refresh the stored price of products whose specials start or
	 * end on a date, so sorting, "on sale" filters and badges follow them.
	 */
	public static function refresh_dated() {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::PRODUCT,
				'post_status'    => 'any',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_sidccofo_dated_rules', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $ids as $id ) {
			Product::sync_derived_meta( (int) $id );
		}
	}

	/**
	 * One-line explanation for the product screen.
	 *
	 * @return string
	 */
	public static function help_text() {
		return __( 'One rule per line: conditions, a colon, then a unit price or “N% off”, then an optional label. Conditions: qty 10 (or 10+), from 2026-11-27, until 2026-11-30 18:00, group Wholesale. The lowest matching price wins. Example: “group Wholesale qty 50: 60”.', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/*
	------------------------------------------------------------------
	 * Customer groups (WordPress roles made from Store → Settings)
	 * ----------------------------------------------------------------
	 */

	/**
	 * Group names typed in the settings.
	 *
	 * @return string[]
	 */
	public static function group_names() {
		$names = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) setting( 'customer_groups', '' ) ) as $name ) {
			$name = trim( sanitize_text_field( $name ) );
			if ( '' !== $name && '' !== sanitize_title( $name ) ) {
				$names[] = $name;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Role slug for a group name.
	 *
	 * @param string $name Group name.
	 * @return string
	 */
	public static function role_for( $name ) {
		return self::ROLE_PREFIX . str_replace( '-', '_', sanitize_title( $name ) );
	}

	/**
	 * Make a role for each group in the settings (they show in Users → Role).
	 * Roles for groups taken off the list stay, so nobody loses theirs.
	 */
	public static function sync_roles() {
		$names = self::group_names();
		$hash  = md5( wp_json_encode( $names ) );
		if ( get_option( 'sidcraft_commerce_groups_hash' ) === $hash ) {
			return;
		}
		foreach ( $names as $name ) {
			$role = self::role_for( $name );
			if ( ! get_role( $role ) ) {
				add_role( $role, $name, array( 'read' => true ) );
			}
		}
		update_option( 'sidcraft_commerce_groups_hash', $hash, true );
	}
}
