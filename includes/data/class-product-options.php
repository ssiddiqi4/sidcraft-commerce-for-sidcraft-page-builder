<?php
/**
 * Item options: choices a customer makes when adding a product to the cart
 * (size, extras, "no onions"), each optionally adding to the price.
 *
 * Store owners type them on the product, one group per line:
 *
 *     Size*: Small | Medium +2 | Large +4
 *     Crust: Regular | Thin -1.50
 *     Extras [checkbox]: Cheese +1 | Bacon +2.50
 *     Spice level [radio]: Mild | Medium | Hot
 *     Special instructions [text]: e.g. no onions
 *
 * "*" = required. Types: select (default with choices), radio, checkbox,
 * text (default without choices; the text after ":" is the placeholder).
 * "+2" adds to the unit price and "-1.50" takes off it (never below zero).
 * A choice can have its own stock count (Product data → Stock per choice).
 *
 * Add-ons can supply groups in code with the sidcraft_commerce_product_options
 * filter, using the same array shape that parse() returns.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Data;

use function SidcraftCommerce\round_amount;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Options
 */
class Product_Options {

	const TYPES = array( 'select', 'radio', 'checkbox', 'text' );

	/**
	 * Parse the typed definition into option groups.
	 *
	 * @param string $text One group per line.
	 * @return array[] Each: id, label, type, required, choices[ id, label, price ], placeholder, max.
	 */
	public static function parse( $text ) {
		$groups = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$colon = strpos( $line, ':' );
			$head  = false === $colon ? $line : substr( $line, 0, $colon );
			$tail  = false === $colon ? '' : trim( substr( $line, $colon + 1 ) );

			$type = '';
			if ( preg_match( '/\[(\w+)\]/', $head, $m ) ) {
				$type = strtolower( $m[1] );
				$head = str_replace( $m[0], '', $head );
			}
			$required = false !== strpos( $head, '*' );
			$label    = trim( sanitize_text_field( str_replace( '*', '', $head ) ) );
			if ( '' === $label ) {
				continue;
			}

			$choices = array();
			if ( 'text' !== $type && '' !== $tail ) {
				foreach ( explode( '|', $tail ) as $raw ) {
					$raw   = trim( $raw );
					$price = 0.0;
					// "+2" (spaces allowed) adds; " -2" (a space before the minus, none
					// after) takes off, so names like "Ages 3-5" stay names.
					if ( preg_match( '/^(.*?)\s*\+\s*([0-9][0-9.,]*)$/', $raw, $pm ) ) {
						$raw   = trim( $pm[1] );
						$price = (float) \SidcraftCommerce\parse_price( $pm[2] );
					} elseif ( preg_match( '/^(.*?\S)\s+[\-\x{2212}]([0-9][0-9.,]*)$/u', $raw, $pm ) ) {
						$raw   = trim( $pm[1] );
						$price = -1 * (float) \SidcraftCommerce\parse_price( $pm[2] );
					}
					$raw = sanitize_text_field( $raw );
					if ( '' !== $raw ) {
						$choices[] = array(
							'label' => $raw,
							'price' => $price,
						);
					}
				}
			}
			if ( ! in_array( $type, self::TYPES, true ) ) {
				$type = $choices ? 'select' : 'text';
			}
			if ( 'text' !== $type && ! $choices ) {
				$type = 'text';
			}

			$groups[] = array(
				'label'       => $label,
				'type'        => $type,
				'required'    => $required,
				'choices'     => $choices,
				'placeholder' => 'text' === $type ? sanitize_text_field( $tail ) : '',
			);
		}
		return self::normalize( $groups );
	}

	/**
	 * One-line explanation of the typed format, for admin screens.
	 *
	 * @return string
	 */
	public static function help_text() {
		return __( 'Format: Name: choice | choice +price. Add * after the name to make it required. Add [radio], [checkbox] or [text] after the name for buttons, multiple choices or a text box. "+2" adds 2 to the price and "-2" takes 2 off.', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/**
	 * Tidy the typed definition for storing (trim lines, drop empty ones).
	 *
	 * @param string $text Typed definition.
	 * @return string
	 */
	public static function clean_text( $text ) {
		$lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $text ) ), 'strlen' );
		return implode( "\n", array_slice( $lines, 0, 30 ) );
	}

	/**
	 * Fill in ids and defaults; drop invalid groups. Accepts groups from
	 * parse() or from add-ons.
	 *
	 * @param array $groups Groups.
	 * @return array[]
	 */
	public static function normalize( $groups ) {
		$out  = array();
		$used = array();
		foreach ( (array) $groups as $group ) {
			if ( ! is_array( $group ) || empty( $group['label'] ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) $group['label'] );
			$type  = in_array( $group['type'] ?? '', self::TYPES, true ) ? $group['type'] : 'select';
			$id    = sanitize_key( (string) ( $group['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = sanitize_key( sanitize_title( $label ) );
			}
			if ( '' === $id ) {
				$id = 'option';
			}
			$base = $id;
			$n    = 2;
			while ( isset( $used[ $id ] ) ) {
				$id = $base . '-' . $n++;
			}
			$used[ $id ] = true;

			$choices = array();
			$cused   = array();
			foreach ( (array) ( $group['choices'] ?? array() ) as $choice ) {
				if ( ! is_array( $choice ) || ! isset( $choice['label'] ) || '' === (string) $choice['label'] ) {
					continue;
				}
				$cid = sanitize_key( (string) ( $choice['id'] ?? '' ) );
				if ( '' === $cid ) {
					$cid = sanitize_key( sanitize_title( (string) $choice['label'] ) );
				}
				if ( '' === $cid ) {
					$cid = 'c';
				}
				$cbase = $cid;
				$m     = 2;
				while ( isset( $cused[ $cid ] ) ) {
					$cid = $cbase . '-' . $m++;
				}
				$cused[ $cid ] = true;
				$choices[]     = array(
					'id'    => $cid,
					'label' => sanitize_text_field( (string) $choice['label'] ),
					'price' => round_amount( (float) ( $choice['price'] ?? 0 ) ),
				);
			}
			if ( 'text' !== $type && ! $choices ) {
				continue;
			}
			$out[] = array(
				'id'          => $id,
				'label'       => $label,
				'type'        => $type,
				'required'    => ! empty( $group['required'] ),
				'choices'     => 'text' === $type ? array() : $choices,
				'placeholder' => sanitize_text_field( (string) ( $group['placeholder'] ?? '' ) ),
				'max'         => max( 0, (int) ( $group['max'] ?? 0 ) ),
				'maxlength'   => max( 1, min( 500, (int) ( $group['maxlength'] ?? 200 ) ) ),
			);
		}
		return $out;
	}

	/**
	 * Check a customer's choices against the product's groups.
	 *
	 * @param array $groups    Groups (normalized).
	 * @param mixed $selection group id => choice id | choice ids | text.
	 * @return array|\WP_Error [ selection (clean, for storing), rows (for display), extra (price per unit) ],
	 *                         or an error whose data.fields maps group id => message.
	 */
	public static function resolve( array $groups, $selection ) {
		$selection = is_array( $selection ) ? $selection : array();
		$clean     = array();
		$rows      = array();
		$extra     = 0.0;
		$errors    = array();

		foreach ( $groups as $group ) {
			$id    = $group['id'];
			$value = $selection[ $id ] ?? null;

			if ( 'text' === $group['type'] ) {
				$text = is_scalar( $value ) ? mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, $group['maxlength'] ) : '';
				if ( '' === $text ) {
					if ( $group['required'] ) {
						/* translators: %s: option name, e.g. "Special instructions". */
						$errors[ $id ] = sprintf( __( 'Fill in “%s”.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $group['label'] );
					}
					continue;
				}
				$clean[ $id ] = $text;
				$rows[]       = array(
					'label' => $group['label'],
					'value' => $text,
					'price' => 0.0,
				);
				continue;
			}

			$by_id = array();
			foreach ( $group['choices'] as $choice ) {
				$by_id[ $choice['id'] ] = $choice;
			}
			$picked = array();
			foreach ( 'checkbox' === $group['type'] ? (array) $value : array( $value ) as $cid ) {
				$cid = is_scalar( $cid ) ? sanitize_key( (string) $cid ) : '';
				if ( '' !== $cid && isset( $by_id[ $cid ] ) && ! isset( $picked[ $cid ] ) ) {
					$picked[ $cid ] = $by_id[ $cid ];
				}
			}
			if ( ! $picked ) {
				if ( $group['required'] ) {
					/* translators: %s: option name, e.g. "Size". */
					$errors[ $id ] = sprintf( __( 'Choose “%s”.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $group['label'] );
				}
				continue;
			}
			if ( 'checkbox' === $group['type'] && $group['max'] && count( $picked ) > $group['max'] ) {
				$errors[ $id ] = sprintf(
					/* translators: 1: number, 2: option name. */
					_n( 'Choose up to %1$d for “%2$s”.', 'Choose up to %1$d for “%2$s”.', $group['max'], 'sidcraft-commerce-for-sidcraft-page-builder' ),
					$group['max'],
					$group['label']
				);
				continue;
			}
			$price        = round_amount( array_sum( wp_list_pluck( $picked, 'price' ) ) );
			$extra       += $price;
			$clean[ $id ] = 'checkbox' === $group['type'] ? array_keys( $picked ) : key( $picked );
			$rows[]       = array(
				'label' => $group['label'],
				'value' => implode( ', ', wp_list_pluck( $picked, 'label' ) ),
				'price' => $price,
			);
		}

		if ( $errors ) {
			return new \WP_Error(
				'sidccofo_options_invalid',
				reset( $errors ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}
		return array(
			'selection' => $clean,
			'rows'      => $rows,
			'extra'     => round_amount( $extra ),
		);
	}

	/**
	 * Sanitize raw request input (nested arrays of strings) before resolve().
	 *
	 * @param mixed $raw Raw input.
	 * @return array
	 */
	public static function from_request( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = array_values( array_map( 'sanitize_text_field', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) ) );
			} elseif ( is_scalar( $value ) ) {
				$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $out;
	}
}
