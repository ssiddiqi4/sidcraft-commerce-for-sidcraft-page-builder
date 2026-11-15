<?php
/**
 * Checkouts left unfinished, in {prefix}sidccofo_abandoned: one row per cart
 * session whose shopper typed an email at checkout and agreed to emails. The
 * row holds a copy of the cart (product, quantity, choices) so the reminder can
 * list it and its link can rebuild it on any device. Placing an order, or
 * emptying the cart, removes the row.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Marketing;

use SidcraftCommerce\Cart\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Class Abandoned
 */
class Abandoned {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sidccofo_abandoned';
	}

	/**
	 * Remember (or refresh) the current cart.
	 *
	 * @param Cart   $cart       Cart.
	 * @param string $email      Email.
	 * @param string $first_name First name.
	 */
	public static function save( Cart $cart, $email, $first_name ) {
		global $wpdb;
		$key = $cart->session()->get_key();
		if ( '' === $key ) {
			return;
		}
		$lines = array();
		foreach ( $cart->get_items() as $line ) {
			if ( ! empty( $line['gift_card'] ) ) {
				continue; // Gift cards carry a recipient and message: not rebuilt from a link.
			}
			$lines[] = array(
				'product_id' => (int) $line['product_id'],
				'quantity'   => (int) $line['quantity'],
				'selection'  => $line['selection'] ?? array(),
				'name'       => $line['product']->get_name(),
				'total'      => (float) $line['total'],
			);
		}
		if ( ! $lines ) {
			self::delete( $key );
			return;
		}
		$existing = self::get( $key );
		$data     = array(
			'session_key' => $key,
			'email'       => Subscribers::normalize( $email ),
			'first_name'  => sanitize_text_field( (string) $first_name ),
			'token'       => $existing ? $existing->token : wp_generate_password( 32, false ),
			'cart'        => wp_json_encode( $lines ),
			'total'       => (float) $cart->get_subtotal(),
			'currency'    => \SidcraftCommerce\currency(),
			'updated'     => time(),
			// A changed cart may be reminded about again; an unchanged one once.
			'reminded'    => $existing && (string) $existing->cart === wp_json_encode( $lines ) ? (int) $existing->reminded : 0,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->replace( self::table(), $data );
	}

	/**
	 * Row of a session.
	 *
	 * @param string $key Session key.
	 * @return object|null
	 */
	public static function get( $key ) {
		global $wpdb;
		if ( '' === (string) $key ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE session_key = %s', self::table(), $key ) );
	}

	/**
	 * Row by the token in a reminder link.
	 *
	 * @param string $token Token.
	 * @return object|null
	 */
	public static function by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', (string) $token ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token = %s', self::table(), $token ) );
	}

	/**
	 * Forget a session's cart.
	 *
	 * @param string $key Session key.
	 */
	public static function delete( $key ) {
		global $wpdb;
		if ( '' === (string) $key ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->delete( self::table(), array( 'session_key' => $key ) );
	}

	/**
	 * Forget every cart of an email (privacy eraser).
	 *
	 * @param string $email Email.
	 * @return int Rows removed.
	 */
	public static function delete_by_email( $email ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return (int) $wpdb->delete( self::table(), array( 'email' => Subscribers::normalize( $email ) ) );
	}

	/**
	 * Carts untouched since $before and not reminded yet.
	 *
	 * @param int $before Unix time.
	 * @param int $limit  Max rows.
	 * @return object[]
	 */
	public static function due( $before, $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE reminded = 0 AND updated < %d ORDER BY updated ASC LIMIT %d', self::table(), (int) $before, (int) $limit ) );
	}

	/**
	 * Mark a cart as reminded.
	 *
	 * @param object $row Row.
	 */
	public static function mark_reminded( $row ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->update( self::table(), array( 'reminded' => time() ), array( 'session_key' => $row->session_key ) );
	}

	/**
	 * Reminder link used: count it (the row stays until the order is placed).
	 *
	 * @param object $row Row.
	 */
	public static function mark_clicked( $row ) {
		$count = (int) get_option( 'sidcraft_commerce_carts_recovered_clicks', 0 );
		update_option( 'sidcraft_commerce_carts_recovered_clicks', $count + 1, false );
		unset( $row );
	}

	/**
	 * Drop carts older than $age seconds.
	 *
	 * @param int $age Seconds.
	 */
	public static function prune( $age ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated < %d', self::table(), time() - (int) $age ) );
	}

	/**
	 * Count of carts being remembered.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * The cart as an email table.
	 *
	 * @param object $row Row.
	 * @return string
	 */
	public static function summary_html( $row ) {
		$lines = (array) json_decode( (string) $row->cart, true );
		$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
		foreach ( $lines as $line ) {
			$html .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eee;">' . esc_html( (string) ( $line['name'] ?? '' ) ) . ' × ' . (int) ( $line['quantity'] ?? 1 ) . '</td>'
				. '<td align="right" style="padding:8px 0;border-bottom:1px solid #eee;">' . esc_html( \SidcraftCommerce\format_in( (float) ( $line['total'] ?? 0 ), (string) $row->currency ) ) . '</td></tr>';
		}
		return $html . '</table>';
	}
}
