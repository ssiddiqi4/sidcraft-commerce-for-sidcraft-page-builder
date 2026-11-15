<?php
/**
 * Per-combination variants: on a product with two or more single-choice
 * options (Colour and Size), each combination ("Red / L") can have its own
 * SKU, price, sale price, stock and image, or not be sold at all.
 *
 * Stored on the product:
 *   _sidccofo_variants  "key" => [ enabled, sku, regular, sale, image ], where key
 *                       is "group:choice|group:choice" in option order. A
 *                       combination without a row is sold at the usual price.
 *   _sidccofo_cstock_v… Units left of a combination (one meta row each, so a
 *                       sale takes them with one conditional UPDATE, like
 *                       choice stock; missing = not tracked).
 *
 * A combination's own price replaces the product price plus its options'
 * amounts; other options (checkboxes, text) still add theirs. Prices are in
 * the store currency, shown in the shopper's.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Data;

use function SidcraftCommerce\round_amount;

defined( 'ABSPATH' ) || exit;

/**
 * Class Variants
 */
class Variants {

	const META = '_sidccofo_variants';

	/** Most combinations a product can list (bigger grids aren't edited by hand). */
	const MAX = 200;

	/**
	 * The options that make combinations: single-choice groups with choices.
	 *
	 * @param array $groups Product's option groups (normalized).
	 * @return array[]
	 */
	public static function groups( array $groups ) {
		return array_values(
			array_filter(
				$groups,
				static function ( $group ) {
					return in_array( $group['type'], array( 'select', 'radio' ), true ) && $group['choices'];
				}
			)
		);
	}

	/**
	 * Do combinations apply (two or more such options, not too many combinations)?
	 *
	 * @param array $groups Product's option groups (normalized).
	 * @return bool
	 */
	public static function applies( array $groups ) {
		$groups = self::groups( $groups );
		return count( $groups ) >= 2 && self::count( $groups ) <= self::MAX;
	}

	/**
	 * Number of combinations.
	 *
	 * @param array $variant_groups From groups().
	 * @return int
	 */
	public static function count( array $variant_groups ) {
		$n = 1;
		foreach ( $variant_groups as $group ) {
			$n *= max( 1, count( $group['choices'] ) );
		}
		return $n;
	}

	/**
	 * Every combination: [ key, choices (group => choice), label ].
	 *
	 * @param array $groups Product's option groups (normalized).
	 * @return array[]
	 */
	public static function combinations( array $groups ) {
		$groups = self::groups( $groups );
		if ( count( $groups ) < 2 || self::count( $groups ) > self::MAX ) {
			return array();
		}
		$out = array( array() );
		foreach ( $groups as $group ) {
			$next = array();
			foreach ( $out as $partial ) {
				foreach ( $group['choices'] as $choice ) {
					$next[] = $partial + array( $group['id'] => array( $choice['id'], $choice['label'] ) );
				}
			}
			$out = $next;
		}
		return array_map(
			static function ( $combo ) {
				$choices = array();
				$labels  = array();
				foreach ( $combo as $gid => $pick ) {
					$choices[ $gid ] = $pick[0];
					$labels[]        = $pick[1];
				}
				return array(
					'key'     => self::key( $choices ),
					'choices' => $choices,
					'label'   => implode( ' / ', $labels ),
				);
			},
			$out
		);
	}

	/**
	 * Key of a combination.
	 *
	 * @param array $choices Group id => choice id, in option order.
	 * @return string
	 */
	public static function key( array $choices ) {
		$parts = array();
		foreach ( $choices as $gid => $cid ) {
			$parts[] = $gid . ':' . $cid;
		}
		return implode( '|', $parts );
	}

	/**
	 * The combination a selection picks ('' when combinations don't apply or
	 * an option of the combination isn't chosen).
	 *
	 * @param array $groups    Product's option groups (normalized).
	 * @param array $selection Group id => choice id(s).
	 * @return string
	 */
	public static function key_for( array $groups, array $selection ) {
		if ( ! self::applies( $groups ) ) {
			return '';
		}
		$choices = array();
		foreach ( self::groups( $groups ) as $group ) {
			$cid = $selection[ $group['id'] ] ?? '';
			if ( ! is_scalar( $cid ) || '' === (string) $cid ) {
				return '';
			}
			$choices[ $group['id'] ] = (string) $cid;
		}
		return self::key( $choices );
	}

	/**
	 * Stored row of a combination, with defaults.
	 *
	 * @param int    $product_id Product.
	 * @param string $key        Combination.
	 * @return array [ enabled, sku, regular, sale, image ].
	 */
	public static function row( $product_id, $key ) {
		$all = get_post_meta( (int) $product_id, self::META, true );
		$row = is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
		return array(
			'enabled' => ! isset( $row['enabled'] ) || ! empty( $row['enabled'] ),
			'sku'     => (string) ( $row['sku'] ?? '' ),
			'regular' => (string) ( $row['regular'] ?? '' ),
			'sale'    => (string) ( $row['sale'] ?? '' ),
			'image'   => (int) ( $row['image'] ?? 0 ),
		);
	}

	/**
	 * Meta key of a combination's stock.
	 *
	 * @param string $key Combination.
	 * @return string
	 */
	public static function stock_key( $key ) {
		return '_sidccofo_cstock_v' . substr( md5( (string) $key ), 0, 12 );
	}

	/**
	 * Units left of a combination, or null when not tracked.
	 *
	 * @param int    $product_id Product.
	 * @param string $key        Combination.
	 * @return int|null
	 */
	public static function stock( $product_id, $key ) {
		$raw = get_post_meta( (int) $product_id, self::stock_key( $key ), true );
		return '' === $raw || null === $raw ? null : max( 0, (int) $raw );
	}

	/**
	 * Set a combination's stock ('' or null stops tracking it).
	 *
	 * @param int        $product_id Product.
	 * @param string     $key        Combination.
	 * @param int|string $qty        Units.
	 */
	public static function set_stock( $product_id, $key, $qty ) {
		if ( null === $qty || '' === trim( (string) $qty ) ) {
			delete_post_meta( (int) $product_id, self::stock_key( $key ) );
		} else {
			update_post_meta( (int) $product_id, self::stock_key( $key ), max( 0, (int) $qty ) );
		}
	}

	/**
	 * Can this combination be bought (sold, and in stock when tracked)?
	 *
	 * @param int    $product_id Product.
	 * @param string $key        Combination.
	 * @return bool
	 */
	public static function available( $product_id, $key ) {
		$left = self::stock( $product_id, $key );
		return self::row( $product_id, $key )['enabled'] && ( null === $left || $left > 0 );
	}

	/**
	 * The combination's own price per unit in the shopper's currency (the sale
	 * price when lower), or null when it has none.
	 *
	 * @param int    $product_id Product.
	 * @param string $key        Combination.
	 * @return float|null
	 */
	public static function own_price( $product_id, $key ) {
		$row = self::row( $product_id, $key );
		if ( '' === $row['regular'] || ! is_numeric( $row['regular'] ) ) {
			return null;
		}
		$price = (float) $row['regular'];
		if ( '' !== $row['sale'] && is_numeric( $row['sale'] ) && (float) $row['sale'] < $price ) {
			$price = (float) $row['sale'];
		}
		return round_amount( \SidcraftCommerce\Currencies::to_active( max( 0.0, $price ) ) );
	}

	/**
	 * What the combination's options add to the price (to swap for its own
	 * price), in the currency of $groups (get_options(): the shopper's).
	 *
	 * @param array $groups  Product's option groups (normalized).
	 * @param string $key    Combination.
	 * @return float
	 */
	public static function options_amount( array $groups, $key ) {
		$picked = array();
		foreach ( explode( '|', (string) $key ) as $part ) {
			list( $gid, $cid ) = array_pad( explode( ':', $part, 2 ), 2, '' );
			$picked[ $gid ]    = $cid;
		}
		$sum = 0.0;
		foreach ( self::groups( $groups ) as $group ) {
			foreach ( $group['choices'] as $choice ) {
				if ( ( $picked[ $group['id'] ] ?? null ) === $choice['id'] ) {
					$sum += (float) $choice['price'];
				}
			}
		}
		return round_amount( $sum );
	}

	/**
	 * Is every combination unavailable (so the product can't be bought)?
	 *
	 * @param Product $product Product.
	 * @return bool
	 */
	public static function none_available( Product $product ) {
		$combos = self::combinations( $product->get_options() );
		if ( ! $combos ) {
			return false;
		}
		foreach ( $combos as $combo ) {
			if ( self::available( $product->get_id(), $combo['key'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Lowest and highest price of the combinations on sale (shopper's currency),
	 * or null when they all cost the same.
	 *
	 * @param Product $product Product.
	 * @return float[]|null [ min, max ].
	 */
	public static function price_range( Product $product ) {
		$groups = $product->get_options();
		$combos = self::combinations( $groups );
		if ( ! $combos ) {
			return null;
		}
		$base   = $product->get_price();
		$prices = array();
		foreach ( $combos as $combo ) {
			if ( ! self::row( $product->get_id(), $combo['key'] )['enabled'] ) {
				continue;
			}
			$own      = self::own_price( $product->get_id(), $combo['key'] );
			$prices[] = null !== $own ? $own : round_amount( max( 0.0, $base + self::options_amount( $groups, $combo['key'] ) ) ); // Choice amounts are already in the shopper's currency.
		}
		if ( ! $prices || abs( max( $prices ) - min( $prices ) ) < 0.005 ) {
			return null;
		}
		return array( min( $prices ), max( $prices ) );
	}

	/**
	 * Combinations for the product page script.
	 *
	 * @param Product $product Product.
	 * @return array[] [ choices, price|null, sku, image, srcset, available, sold ].
	 */
	public static function for_script( Product $product ) {
		$out = array();
		foreach ( self::combinations( $product->get_options() ) as $combo ) {
			$row   = self::row( $product->get_id(), $combo['key'] );
			$image = $row['image'] ? wp_get_attachment_image_src( $row['image'], 'large' ) : false;
			$out[] = array(
				'choices'   => $combo['choices'],
				'price'     => self::own_price( $product->get_id(), $combo['key'] ),
				'sku'       => $row['sku'],
				'image'     => $image ? $image[0] : '',
				'srcset'    => $image ? (string) wp_get_attachment_image_srcset( $row['image'], 'large' ) : '',
				'available' => self::available( $product->get_id(), $combo['key'] ),
				'sold'      => $row['enabled'],
			);
		}
		return $out;
	}

	/**
	 * Save the rows and stock of every combination (from the product screen
	 * or an import). Rows for combinations that no longer exist are dropped.
	 *
	 * @param int   $product_id Product.
	 * @param array $rows       key => [ enabled, sku, regular, sale, stock, image ].
	 */
	public static function save( $product_id, array $rows ) {
		$product = Product::get( $product_id );
		$keep    = array();
		$store   = array();
		foreach ( self::combinations( $product ? $product->get_options() : array() ) as $combo ) {
			$row                    = (array) ( $rows[ $combo['key'] ] ?? array() );
			$keep[ self::stock_key( $combo['key'] ) ] = true;
			$clean                  = array(
				'enabled' => ! isset( $row['enabled'] ) || ! empty( $row['enabled'] ) ? 1 : 0,
				'sku'     => sanitize_text_field( (string) ( $row['sku'] ?? '' ) ),
				'regular' => \SidcraftCommerce\parse_price( (string) ( $row['regular'] ?? '' ) ),
				'sale'    => \SidcraftCommerce\parse_price( (string) ( $row['sale'] ?? '' ) ),
				'image'   => absint( $row['image'] ?? 0 ),
			);
			$clean['image'] = $clean['image'] && wp_attachment_is_image( $clean['image'] ) ? $clean['image'] : 0;
			if ( ! $clean['enabled'] || '' !== $clean['sku'] || '' !== $clean['regular'] || '' !== $clean['sale'] || $clean['image'] ) {
				$store[ $combo['key'] ] = $clean;
			}
			if ( array_key_exists( 'stock', $row ) ) {
				self::set_stock( $product_id, $combo['key'], '' === trim( (string) $row['stock'] ) ? '' : max( 0, (int) $row['stock'] ) );
			}
		}
		update_post_meta( (int) $product_id, self::META, $store );
		foreach ( array_keys( (array) get_post_meta( (int) $product_id ) ) as $meta_key ) {
			if ( 0 === strpos( (string) $meta_key, '_sidccofo_cstock_v' ) && ! isset( $keep[ $meta_key ] ) ) {
				delete_post_meta( (int) $product_id, $meta_key );
			}
		}
		Product::forget( $product_id );
	}
}
