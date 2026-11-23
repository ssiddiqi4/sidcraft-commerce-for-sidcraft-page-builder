<?php
/**
 * Suppliers and dropshipping.
 *
 * List your suppliers (name and email) under Settings → Store tools, pick the
 * supplier on each product (box "Supplier"), and when an order is paid every
 * supplier gets an email with just their products, the delivery address and a
 * private link where they enter the carrier and tracking number. That adds
 * the shipment to the order (the customer gets the "shipped" email) and the
 * order completes once every supplier has shipped.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Locations;
use SidcraftCommerce\Shipments;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Dropship
 */
class Dropship extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		$this->settings(
			'dropship',
			__( 'Suppliers', $d ),
			array(
				'ds_suppliers' => array(
					__( 'Suppliers', $d ),
					'table',
					__( 'Slug: letters and numbers. The email receives the orders for their products.', $d ),
					array(
						'slug'  => array( __( 'Slug', $d ), 'text', 'acme' ),
						'name'  => array( __( 'Name', $d ), 'text', 'Acme Wholesale' ),
						'email' => array( __( 'Email', $d ), 'text', 'orders@acme.example' ),
					),
				),
			)
		);
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'on_status' ), 10, 3 );
		add_action( 'add_meta_boxes_' . Post_Types::PRODUCT, array( $this, 'box' ) );
		add_action( 'save_post_' . Post_Types::PRODUCT, array( $this, 'save' ) );
		add_action( 'template_redirect', array( $this, 'page' ), 1 );
	}

	/**
	 * Suppliers.
	 *
	 * @return array slug => [ name, email ].
	 */
	public static function suppliers() {
		$out = array();
		foreach ( (array) setting( 'ds_suppliers', array() ) as $row ) {
			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
			if ( '' !== $slug && is_email( (string) ( $row['email'] ?? '' ) ) ) {
				$out[ $slug ] = array( 'name' => (string) ( $row['name'] ?: $slug ), 'email' => (string) $row['email'] );
			}
		}
		return $out;
	}

	/**
	 * Private link token for one supplier and order.
	 *
	 * @param int    $order_id Order.
	 * @param string $slug     Supplier.
	 * @return string
	 */
	protected static function token( $order_id, $slug ) {
		return hash_hmac( 'sha256', $order_id . '|' . $slug, wp_salt( 'auth' ) );
	}

	/**
	 * Send the orders to suppliers when payment arrives.
	 *
	 * @param Order  $order Order.
	 * @param string $old   Old.
	 * @param string $new   New.
	 */
	public function on_status( $order, $old, $new ) {
		unset( $old );
		if ( ! in_array( $new, Post_Types::paid_statuses(), true ) || get_post_meta( $order->get_id(), '_sidccofo_ds_sent', true ) ) {
			return;
		}
		$sup = self::suppliers();
		$by  = array();
		foreach ( $order->get_items() as $item ) {
			$slug = (string) get_post_meta( (int) $item['product_id'], '_sidccofo_supplier', true );
			if ( isset( $sup[ $slug ] ) ) {
				$by[ $slug ][] = $item;
			}
		}
		if ( ! $by ) {
			return;
		}
		update_post_meta( $order->get_id(), '_sidccofo_ds_sent', 1 );
		update_post_meta( $order->get_id(), '_sidccofo_ds_pending', array_keys( $by ) );
		$b    = $order->get_billing();
		$addr = trim( ( $b['first_name'] ?? '' ) . ' ' . ( $b['last_name'] ?? '' ) ) . "\n" . ( $b['address_1'] ?? '' ) . ( ! empty( $b['address_2'] ) ? "\n" . $b['address_2'] : '' ) . "\n" . trim( ( $b['postcode'] ?? '' ) . ' ' . ( $b['city'] ?? '' ) ) . "\n" . ( ! empty( $b['country'] ) ? Locations::country_name( $b['country'] ) : '' ) . "\n" . ( $b['phone'] ?? '' );
		foreach ( $by as $slug => $items ) {
			$lines = array();
			foreach ( $items as $i ) {
				$lines[] = $i['quantity'] . ' × ' . wp_strip_all_tags( $i['name'] ) . ( ! empty( $i['sku'] ) ? ' [' . $i['sku'] . ']' : '' ) . ( ! empty( $i['options'] ) ? ' (' . \SidcraftCommerce\item_options_text( $i['options'] ) . ')' : '' );
			}
			$link = add_query_arg( array( 'sidccofo_ship' => $order->get_id(), 'sup' => $slug, 't' => self::token( $order->get_id(), $slug ) ), home_url( '/' ) );
			wp_mail(
				$sup[ $slug ]['email'],
				/* translators: %d: order number. */
				sprintf( __( 'New order #%d to ship', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ),
				/* translators: 1: items, 2: address, 3: link. */
				sprintf( __( "Please ship these items:\n\n%1\$s\n\nShip to:\n%2\$s\n\nWhen it is on its way, enter the carrier and tracking number here:\n%3\$s", 'sidcraft-commerce-for-sidcraft-page-builder' ), implode( "\n", $lines ), $addr, $link )
			);
			/* translators: %s: supplier. */
			$order->add_note( sprintf( __( 'Sent to supplier %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sup[ $slug ]['name'] ) );
		}
	}

	/**
	 * The supplier's tracking page.
	 */
	public function page() {
		// phpcs:disable WordPress.Security.NonceVerification -- The private token is the credential.
		if ( empty( $_REQUEST['sidccofo_ship'] ) ) {
			return;
		}
		$id    = absint( $_REQUEST['sidccofo_ship'] );
		$slug  = isset( $_REQUEST['sup'] ) ? sanitize_key( wp_unslash( $_REQUEST['sup'] ) ) : '';
		$token = isset( $_REQUEST['t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['t'] ) ) : '';
		// phpcs:enable
		$order = Order::get( $id );
		$sup   = self::suppliers();
		if ( ! $order || ! isset( $sup[ $slug ] ) || ! hash_equals( self::token( $id, $slug ), $token ) ) {
			wp_die( esc_html__( 'This link is not valid.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		$d    = 'sidcraft-commerce-for-sidcraft-page-builder';
		$msg  = '';
		$left = (array) get_post_meta( $id, '_sidccofo_ds_pending', true );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The private token is the credential.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) && in_array( $slug, $left, true ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$left_after = array_values( array_diff( $left, array( $slug ) ) );
			$result     = Shipments::add(
				$order,
				array(
					'carrier'      => isset( $_POST['carrier'] ) ? sanitize_key( wp_unslash( $_POST['carrier'] ) ) : '',
					'carrier_name' => isset( $_POST['carrier_name'] ) ? sanitize_text_field( wp_unslash( $_POST['carrier_name'] ) ) : '',
					'number'       => isset( $_POST['number'] ) ? sanitize_text_field( wp_unslash( $_POST['number'] ) ) : '',
				),
				true,
				! $left_after
			);
			// phpcs:enable
			if ( is_wp_error( $result ) ) {
				$msg = $result->get_error_message();
			} else {
				update_post_meta( $id, '_sidccofo_ds_pending', $left_after );
				$msg  = __( 'Thank you. The tracking number was saved and the customer was told.', $d );
				$left = $left_after;
			}
		}
		$opts = '<option value="">' . esc_html__( 'Other (type the name)', $d ) . '</option>';
		foreach ( Shipments::carriers() as $group => $list ) {
			$opts .= '<optgroup label="' . esc_attr( (string) $group ) . '">';
			foreach ( $list as $slug2 => $c ) {
				$opts .= '<option value="' . esc_attr( $slug2 ) . '">' . esc_html( $c[0] ) . '</option>';
			}
			$opts .= '</optgroup>';
		}
		$form = in_array( $slug, $left, true )
			? '<form method="post"><p><label>' . esc_html__( 'Carrier', $d ) . '<br><select name="carrier">' . $opts . '</select></label></p><p><label>' . esc_html__( 'Carrier name (if other)', $d ) . '<br><input name="carrier_name"></label></p><p><label>' . esc_html__( 'Tracking number', $d ) . '<br><input name="number" required></label></p><p><button>' . esc_html__( 'Save tracking', $d ) . '</button></p></form>'
			: '<p>' . esc_html__( 'Nothing left to ship for this order.', $d ) . '</p>';
		wp_die( ( '' !== $msg ? '<p><strong>' . esc_html( $msg ) . '</strong></p>' : '' ) . '<h1>' . esc_html( sprintf( /* translators: %d: order. */ __( 'Order #%d', $d ), $id ) ) . '</h1>' . $form, esc_html__( 'Shipping', $d ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Product box.
	 */
	public function box() {
		add_meta_box(
			'sidccofo-supplier',
			__( 'Supplier', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			static function ( $post ) {
				wp_nonce_field( 'sidccofo_supplier', '_sidccofo_sup' );
				$cur = (string) get_post_meta( $post->ID, '_sidccofo_supplier', true );
				echo '<select name="sidccofo_supplier" class="widefat"><option value="">' . esc_html__( 'None (ship it yourself)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</option>';
				foreach ( Dropship::suppliers() as $slug => $s ) {
					echo '<option value="' . esc_attr( $slug ) . '"' . selected( $cur, $slug, false ) . '>' . esc_html( $s['name'] ) . '</option>';
				}
				echo '</select>';
			},
			Post_Types::PRODUCT,
			'side'
		);
	}

	/**
	 * Save.
	 *
	 * @param int $post_id Product.
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST['_sidccofo_sup'] ) || ! current_user_can( 'edit_post', $post_id ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_sup'] ) ), 'sidccofo_supplier' ) ) {
			return;
		}
		$slug = isset( $_POST['sidccofo_supplier'] ) ? sanitize_key( wp_unslash( $_POST['sidccofo_supplier'] ) ) : '';
		update_post_meta( $post_id, '_sidccofo_supplier', isset( self::suppliers()[ $slug ] ) ? $slug : '' );
	}
}
