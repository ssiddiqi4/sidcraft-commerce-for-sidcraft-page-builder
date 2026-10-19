<?php
/**
 * Stock counts for single option choices ("Size: Large — 3 left").
 *
 * A choice is tracked when the product screen gives it a number (Product data
 * → Stock per choice); empty means unlimited. Each tracked choice is its own
 * post meta row, _sidccofo_cstock_{hash of group and choice ids}, so a sale
 * can take units with one conditional UPDATE, like product stock: two
 * checkouts can't both take the last unit.
 *
 * Orders take choice stock when they reserve product stock and record what
 * they took (_sidccofo_choice_taken: rows of product_id, keys, held), so a
 * cancel, refund or partial refund puts back exactly that.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Class Choice_Stock
 */
class Choice_Stock {

	/**
	 * Meta key for a choice.
	 *
	 * @param string $group  Group id.
	 * @param string $choice Choice id.
	 * @return string
	 */
	public static function meta_key( $group, $choice ) {
		return '_sidccofo_cstock_' . substr( md5( $group . '|' . $choice ), 0, 12 );
	}

	/**
	 * Units left, or null when the choice isn't tracked.
	 *
	 * @param int    $product_id Product.
	 * @param string $group      Group id.
	 * @param string $choice     Choice id.
	 * @return int|null
	 */
	public static function get( $product_id, $group, $choice ) {
		$raw = get_post_meta( (int) $product_id, self::meta_key( $group, $choice ), true );
		return '' === $raw || null === $raw ? null : max( 0, (int) $raw );
	}

	/**
	 * Set a choice's stock ('' or null stops tracking it).
	 *
	 * @param int        $product_id Product.
	 * @param string     $group      Group id.
	 * @param string     $choice     Choice id.
	 * @param int|string $qty        Units.
	 */
	public static function set( $product_id, $group, $choice, $qty ) {
		$key = self::meta_key( $group, $choice );
		if ( null === $qty || '' === trim( (string) $qty ) ) {
			delete_post_meta( (int) $product_id, $key );
		} else {
			update_post_meta( (int) $product_id, $key, max( 0, (int) $qty ) );
		}
	}

	/**
	 * Tracked choices among a selection: "group|choice" => [ group, choice, label, left ].
	 *
	 * @param int   $product_id Product.
	 * @param array $groups     Product's groups (normalized).
	 * @param array $selection  Clean selection: group id => choice id | choice ids | text.
	 * @return array
	 */
	public static function tracked_in( $product_id, array $groups, array $selection ) {
		$out = array();
		foreach ( $groups as $group ) {
			if ( 'text' === $group['type'] || ! isset( $selection[ $group['id'] ] ) ) {
				continue;
			}
			$labels = wp_list_pluck( $group['choices'], 'label', 'id' );
			foreach ( (array) $selection[ $group['id'] ] as $cid ) {
				$left = self::get( $product_id, $group['id'], (string) $cid );
				if ( null !== $left ) {
					$out[ $group['id'] . '|' . $cid ] = array(
						'group'  => $group['id'],
						'choice' => (string) $cid,
						'label'  => $group['label'] . ': ' . ( $labels[ $cid ] ?? $cid ),
						'left'   => $left,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Is every choice of a required single-choice group sold out (so the
	 * product can't be bought at all)?
	 *
	 * @param int   $product_id Product.
	 * @param array $groups     Groups (normalized).
	 * @return bool
	 */
	public static function blocks_purchase( $product_id, array $groups ) {
		foreach ( $groups as $group ) {
			if ( ! $group['required'] || 'text' === $group['type'] || 'checkbox' === $group['type'] || ! $group['choices'] ) {
				continue;
			}
			$open = false;
			foreach ( $group['choices'] as $choice ) {
				$left = self::get( $product_id, $group['id'], $choice['id'] );
				if ( null === $left || $left > 0 ) {
					$open = true;
					break;
				}
			}
			if ( ! $open ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Take or return units with one conditional update.
	 *
	 * @param int    $product_id Product.
	 * @param string $meta_key   Choice meta key.
	 * @param int    $delta      Negative to take, positive to return.
	 * @return bool False when taking more than is left (nothing changes then).
	 */
	public static function adjust( $product_id, $meta_key, $delta ) {
		global $wpdb;
		$delta = (int) $delta;
		if ( 0 === $delta ) {
			return true;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Conditional stock update, as for product stock.
		if ( $delta < 0 ) {
			$done = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) - %d WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS SIGNED) >= %d",
					abs( $delta ),
					(int) $product_id,
					$meta_key,
					abs( $delta )
				)
			);
		} else {
			$done = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) + %d WHERE post_id = %d AND meta_key = %s",
					$delta,
					(int) $product_id,
					$meta_key
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( (int) $product_id, 'post_meta' );
		return $delta > 0 || (bool) $done;
	}

	/**
	 * Take the tracked choices of an order's items. All or nothing.
	 *
	 * @param int   $order_id Order.
	 * @param array $items    Order items (with 'selection' where options were chosen).
	 * @return bool False when a choice ran out (nothing is taken then).
	 */
	public static function take_for_order( $order_id, array $items ) {
		$taken = array();
		foreach ( $items as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			$qty        = (int) ( $item['quantity'] ?? 0 );
			if ( ! $product_id || $qty < 1 || empty( $item['selection'] ) || ! is_array( $item['selection'] ) ) {
				continue;
			}
			$product = Product::get( $product_id );
			$keys    = array();
			foreach ( self::tracked_in( $product_id, $product ? $product->get_options() : array(), $item['selection'] ) as $row ) {
				$key = self::meta_key( $row['group'], $row['choice'] );
				if ( ! self::adjust( $product_id, $key, -1 * $qty ) ) {
					self::give_back( $taken );
					foreach ( $keys as $done ) {
						self::adjust( $product_id, $done, $qty );
					}
					return false;
				}
				$keys[] = $key;
			}
			if ( $keys ) {
				$taken[] = array(
					'product_id' => $product_id,
					'keys'       => $keys,
					'held'       => $qty,
				);
			}
		}
		update_post_meta( (int) $order_id, '_sidccofo_choice_taken', $taken );
		return true;
	}

	/**
	 * Put back everything an order still holds.
	 *
	 * @param int $order_id Order.
	 */
	public static function release_for_order( $order_id ) {
		$taken = get_post_meta( (int) $order_id, '_sidccofo_choice_taken', true );
		if ( is_array( $taken ) && $taken ) {
			self::give_back( $taken );
		}
		delete_post_meta( (int) $order_id, '_sidccofo_choice_taken' );
	}

	/**
	 * Put back some units of one product (partial refund), oldest lines first.
	 *
	 * @param int $order_id   Order.
	 * @param int $product_id Product.
	 * @param int $units      Units returned.
	 */
	public static function release_units( $order_id, $product_id, $units ) {
		$taken = get_post_meta( (int) $order_id, '_sidccofo_choice_taken', true );
		if ( ! is_array( $taken ) || ! $taken ) {
			return;
		}
		$units = (int) $units;
		foreach ( $taken as $i => $row ) {
			if ( $units < 1 ) {
				break;
			}
			if ( (int) $row['product_id'] !== (int) $product_id || (int) $row['held'] < 1 ) {
				continue;
			}
			$n = min( $units, (int) $row['held'] );
			foreach ( (array) $row['keys'] as $key ) {
				self::adjust( $product_id, $key, $n );
			}
			$taken[ $i ]['held'] = (int) $row['held'] - $n;
			$units              -= $n;
		}
		update_post_meta( (int) $order_id, '_sidccofo_choice_taken', $taken );
	}

	/**
	 * Return held units.
	 *
	 * @param array $taken Rows: product_id, keys, held.
	 */
	protected static function give_back( array $taken ) {
		foreach ( $taken as $row ) {
			if ( (int) ( $row['held'] ?? 0 ) < 1 ) {
				continue;
			}
			foreach ( (array) ( $row['keys'] ?? array() ) as $key ) {
				self::adjust( (int) $row['product_id'], (string) $key, (int) $row['held'] );
			}
		}
	}
}
