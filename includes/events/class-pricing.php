<?php
/**
 * Ticket pricing: a base price plus optional rules for early-bird, late,
 * demand-based and group prices.
 *
 * Rules are typed one per line on the ticket type. The last line that
 * applies sets the price; an optional label after the price is shown to
 * buyers:
 *
 *     until 2026-11-01: 20 Early bird        price until the end of that day
 *     until 2026-11-01 18:00: 20             … or until that time
 *     from 2026-12-10: 40 Last minute        price from that day on
 *     after 75%: 35                          once 75% of the seats are taken
 *     after 100 sold: 35                     once 100 tickets of this type are taken
 *     qty 5: 18 Group rate                   each ticket when buying 5 or more
 *
 * Prices are applied on the server through the sidcraft_commerce_product_price
 * filter, so the cart, checkout, order and payment always agree.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use function SidcraftCommerce\parse_price;
use function SidcraftCommerce\round_amount;

defined( 'ABSPATH' ) || exit;

/**
 * Class Pricing
 */
class Pricing {

	/**
	 * Parse rule text.
	 *
	 * @param string $text Rules.
	 * @return array[] Each: kind (until|from|pct|sold|qty), at (ts) or n, price, label, line.
	 */
	public static function parse( $text ) {
		$rules = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( preg_match( '/^(until|from)\s+(\d{4}-\d{2}-\d{2})(?:[ T](\d{1,2}:\d{2}))?\s*[:=]\s*([\d.,]+)\s*(.*)$/i', $line, $m ) ) {
				$kind = strtolower( $m[1] );
				$time = '' !== $m[3] ? $m[3] : ( 'until' === $kind ? '23:59' : '00:00' );
				$at   = Event::to_ts( $m[2] . ' ' . str_pad( $time, 5, '0', STR_PAD_LEFT ) );
				if ( 'until' === $kind && '' === $m[3] ) {
					$at += 59; // Through the last second of the day.
				}
				$rule = array(
					'kind' => $kind,
					'at'   => $at,
				);
				$price = $m[4];
				$label = $m[5];
			} elseif ( preg_match( '/^after\s+(\d+(?:\.\d+)?)\s*%\s*[:=]\s*([\d.,]+)\s*(.*)$/i', $line, $m ) ) {
				$rule  = array(
					'kind' => 'pct',
					'n'    => (float) $m[1],
				);
				$price = $m[2];
				$label = $m[3];
			} elseif ( preg_match( '/^after\s+(\d+)(?:\s+sold)?\s*[:=]\s*([\d.,]+)\s*(.*)$/i', $line, $m ) ) {
				$rule  = array(
					'kind' => 'sold',
					'n'    => (int) $m[1],
				);
				$price = $m[2];
				$label = $m[3];
			} elseif ( preg_match( '/^(?:qty|buy|group)\s+(\d+)\s*\+?\s*[:=]\s*([\d.,]+)\s*(.*)$/i', $line, $m ) ) {
				$rule  = array(
					'kind' => 'qty',
					'n'    => max( 1, (int) $m[1] ),
				);
				$price = $m[2];
				$label = $m[3];
			} else {
				continue;
			}
			$parsed = parse_price( $price );
			if ( '' === $parsed ) {
				continue;
			}
			$rule['price'] = (float) $parsed;
			$rule['label'] = trim( (string) $label );
			$rule['line']  = $line;
			$rules[]       = $rule;
		}
		return $rules;
	}

	/**
	 * Lines that could not be read (shown as a warning in the admin).
	 *
	 * @param string $text Rules.
	 * @return string[]
	 */
	public static function invalid_lines( $text ) {
		$ok  = wp_list_pluck( self::parse( $text ), 'line' );
		$bad = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && 0 !== strpos( $line, '#' ) && ! in_array( $line, $ok, true ) ) {
				$bad[] = $line;
			}
		}
		return $bad;
	}

	/**
	 * Tickets of this type held by orders, and the seat count rules measure against.
	 *
	 * @param Event $event Event.
	 * @param array $type  Ticket type.
	 * @return array{sold:int,capacity:int}
	 */
	protected static function demand( Event $event, array $type ) {
		if ( $type['capacity'] ) {
			return array(
				'sold'     => $type['product_id'] ? Placeholders::held( $type['product_id'] ) : 0,
				'capacity' => $type['capacity'],
			);
		}
		return array(
			'sold'     => $type['product_id'] ? Placeholders::held( $type['product_id'] ) : 0,
			'capacity' => $event->get_capacity(),
			'event'    => $event->get_held(),
		);
	}

	/**
	 * Price for a ticket type at a moment.
	 *
	 * @param Event    $event Event.
	 * @param array    $type  Ticket type.
	 * @param int      $qty   Tickets of this type in the cart.
	 * @param int|null $now   Timestamp (default now).
	 * @return array{price:float,base:float,label:string,next:?array}
	 */
	public static function current( Event $event, array $type, $qty = 0, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$base   = round_amount( (float) parse_price( $type['price'] ) );
		$rules  = self::parse( $type['rules'] );
		$demand = self::demand( $event, $type );

		$at = static function ( $when ) use ( $rules, $base, $demand, $qty ) {
			$price = $base;
			$label = '';
			foreach ( $rules as $rule ) {
				switch ( $rule['kind'] ) {
					case 'until':
						$hit = $when <= $rule['at'];
						break;
					case 'from':
						$hit = $when >= $rule['at'];
						break;
					case 'pct':
						$sold = isset( $demand['event'] ) ? $demand['event'] : $demand['sold'];
						$hit  = $demand['capacity'] > 0 && $sold >= $demand['capacity'] * $rule['n'] / 100;
						break;
					case 'sold':
						$hit = $demand['sold'] >= $rule['n'];
						break;
					default:
						$hit = $qty >= $rule['n'];
				}
				if ( $hit ) {
					$price = $rule['price'];
					$label = $rule['label'];
				}
			}
			return array( round_amount( $price ), $label );
		};

		list( $price, $label ) = $at( $now );

		// Next scheduled change (date rules only), to tell buyers "price rises on …".
		$next   = null;
		$bounds = array();
		foreach ( $rules as $rule ) {
			if ( 'until' === $rule['kind'] && $rule['at'] >= $now ) {
				$bounds[] = $rule['at'] + 1;
			} elseif ( 'from' === $rule['kind'] && $rule['at'] > $now ) {
				$bounds[] = $rule['at'];
			}
		}
		sort( $bounds );
		foreach ( $bounds as $when ) {
			list( $p2, $l2 ) = $at( $when );
			if ( $p2 !== $price ) {
				$next = array(
					'price' => $p2,
					'label' => $l2,
					'when'  => $when,
				);
				break;
			}
		}

		/**
		 * Filter a ticket's current price.
		 *
		 * @param array $result price, base, label, next.
		 * @param Event $event  Event.
		 * @param array $type   Ticket type.
		 * @param int   $qty    Quantity in the cart.
		 */
		return (array) apply_filters(
			'sidcraft_commerce_event_ticket_price',
			array(
				'price' => $price,
				'base'  => $base,
				'label' => $label,
				'next'  => $next,
			),
			$event,
			$type,
			$qty
		);
	}

	/**
	 * current() for showing to the shopper: worked out in the store currency
	 * (ticket prices are typed in it), amounts in the shopper's currency.
	 *
	 * @param Event $event Event.
	 * @param array $type  Ticket type.
	 * @param int   $qty   Tickets of this type in the cart.
	 * @return array Same shape as current().
	 */
	public static function shopper( Event $event, array $type, $qty = 0 ) {
		$p = \SidcraftCommerce\Currencies::in_base(
			static function () use ( $event, $type, $qty ) {
				return self::current( $event, $type, $qty );
			}
		);
		$p['price'] = \SidcraftCommerce\money( $p['price'] );
		$p['base']  = \SidcraftCommerce\money( $p['base'] );
		if ( ! empty( $p['next'] ) ) {
			$p['next']['price'] = \SidcraftCommerce\money( $p['next']['price'] );
		}
		return $p;
	}

	/**
	 * Group-rate hints for buyers: [ [ n, price, label ], … ], prices in the
	 * shopper's currency.
	 *
	 * @param array $type Ticket type.
	 * @return array[]
	 */
	public static function shopper_group_rates( array $type ) {
		$out = self::group_rates( $type );
		foreach ( $out as $i => $row ) {
			$out[ $i ]['price'] = \SidcraftCommerce\money( (float) $row['price'] );
		}
		return $out;
	}

	/**
	 * Group-rate hints for buyers: [ [ n, price, label ], … ].
	 *
	 * @param array $type Ticket type.
	 * @return array[]
	 */
	public static function group_rates( array $type ) {
		$out = array();
		foreach ( self::parse( $type['rules'] ) as $rule ) {
			if ( 'qty' === $rule['kind'] ) {
				$out[] = array(
					'n'     => $rule['n'],
					'price' => $rule['price'],
					'label' => $rule['label'],
				);
			}
		}
		return $out;
	}
}
