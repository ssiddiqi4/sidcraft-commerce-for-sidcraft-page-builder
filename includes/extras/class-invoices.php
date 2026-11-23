<?php
/**
 * PDF invoices and packing slips.
 *
 * An invoice gets its number the first time it is made for a paid order
 * (INV-1001, INV-1002… no gaps, never reused). The PDF is attached to the
 * "order received" email when the order is paid, linked from the
 * order-received page and available on the order screen. Packing slips list
 * what to put in the parcel (no prices) with the delivery address and notes.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Events\PDF;
use SidcraftCommerce\Locations;
use function SidcraftCommerce\format_in;
use function SidcraftCommerce\setting;
use function SidcraftCommerce\totals_rows;

defined( 'ABSPATH' ) || exit;

/**
 * Class Invoices
 */
class Invoices extends Tool {

	const SEQ = 'sidcraft_commerce_invoice_seq';

	/** {@inheritDoc} */
	public function hooks() {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		$this->settings(
			'invoices',
			__( 'PDF invoices and packing slips', $d ),
			array(
				'inv_company' => array( __( 'Your company details', $d ), 'textarea', __( 'Name, address, tax or company number: printed at the top of invoices. One item per line.', $d ) ),
				'inv_prefix'  => array( __( 'Invoice number prefix', $d ), 'text', __( 'For example INV-', $d ) ),
				'inv_start'   => array( __( 'First invoice number', $d ), 'text', __( 'Used once, for the first invoice. Numbers then go up by one.', $d ) ),
				'inv_footer'  => array( __( 'Footer text', $d ), 'textarea', __( 'Payment terms, bank details, thank-you note.', $d ) ),
				'inv_attach'  => array( __( 'Attach to the paid-order email', $d ), 'checkbox', __( 'The customer gets the invoice as a PDF with their order email once the order is paid.', $d ) ),
				'inv_link'    => array( __( 'Show a download link to customers', $d ), 'checkbox', __( 'On the order-received page, for paid orders.', $d ) ),
			)
		);
		add_action( 'admin_post_sidccofo_invoice', array( $this, 'download' ) );
		add_action( 'admin_post_nopriv_sidccofo_invoice', array( $this, 'download' ) );
		add_action( 'add_meta_boxes_' . Post_Types::ORDER, array( $this, 'box' ) );
		add_filter( 'sidcraft_commerce_email', array( $this, 'attach' ), 20, 3 );
		add_filter( 'sidcraft_commerce_render_sidccofo-order-confirmation', array( $this, 'link' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'cleanup' ) );
	}

	/*
	------------------------------------------------------------------
	 * Numbering
	 * ----------------------------------------------------------------
	 */

	/**
	 * Take the next invoice number (safe when two orders are paid at once).
	 *
	 * @return int
	 */
	protected static function next_number() {
		global $wpdb;
		if ( false === get_option( self::SEQ, false ) ) {
			add_option( self::SEQ, max( 1, (int) setting( 'inv_start', 1001 ) ) - 1, '', false );
		}
		for ( $i = 0; $i < 20; $i++ ) {
			wp_cache_delete( self::SEQ, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::SEQ ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$next    = $current + 1;
			// Only one request can move the counter from $current to $next.
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $next, self::SEQ, (string) $current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 1 === (int) $changed ) {
				wp_cache_delete( self::SEQ, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				return $next;
			}
		}
		return (int) time();
	}

	/**
	 * The invoice number of an order (made on first use, paid orders only).
	 *
	 * @param Order $order Order.
	 * @return string '' when the order isn't paid yet.
	 */
	public static function number( Order $order ) {
		$saved = (string) get_post_meta( $order->get_id(), '_sidccofo_invoice_no', true );
		if ( '' !== $saved ) {
			return $saved;
		}
		if ( ! in_array( $order->get_status(), Post_Types::paid_statuses(), true ) && 'sidccofo-refunded' !== $order->get_status() ) {
			return '';
		}
		$no = sanitize_text_field( (string) setting( 'inv_prefix', 'INV-' ) ) . self::next_number();
		if ( ! add_post_meta( $order->get_id(), '_sidccofo_invoice_no', $no, true ) ) {
			return (string) get_post_meta( $order->get_id(), '_sidccofo_invoice_no', true );
		}
		update_post_meta( $order->get_id(), '_sidccofo_invoice_date', time() );
		return $no;
	}

	/*
	------------------------------------------------------------------
	 * PDF
	 * ----------------------------------------------------------------
	 */

	/**
	 * Build a PDF.
	 *
	 * @param Order  $order Order.
	 * @param string $type  invoice | packing.
	 * @return string PDF bytes.
	 */
	public static function pdf( Order $order, $type = 'invoice' ) {
		$invoice = 'packing' !== $type;
		$number  = $invoice ? self::number( $order ) : '';
		$pdf     = new PDF( $invoice ? 'Invoice ' . $number : 'Packing slip #' . $order->get_id() );
		$pdf->add_page();
		$ink   = '#1a1a1f';
		$muted = '#6b6a7a';
		$x     = 42;
		$right = 553;
		$y     = 50;

		// Company (left) and title (right).
		$company = trim( (string) setting( 'inv_company', '' ) );
		if ( '' === $company ) {
			$company = get_bloginfo( 'name' );
		}
		$cy = $y;
		foreach ( preg_split( '/\r\n|\r|\n/', $company ) as $i => $line ) {
			$pdf->text( $x, $cy + 10, $line, 0 === $i ? 15 : 10, 0 === $i ? 'bold' : 'regular', 0 === $i ? $ink : $muted );
			$cy += 0 === $i ? 20 : 14;
		}
		$pdf->text( $right, $y + 14, strtoupper( $invoice ? __( 'Invoice', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Packing slip', 'sidcraft-commerce-for-sidcraft-page-builder' ) ), 20, 'bold', $ink, 'right' );
		$ry = $y + 34;
		$meta = array();
		if ( $invoice ) {
			$meta[ __( 'Invoice no.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ] = '' !== $number ? $number : '—';
			$stamp = (int) get_post_meta( $order->get_id(), '_sidccofo_invoice_date', true );
			$meta[ __( 'Invoice date', 'sidcraft-commerce-for-sidcraft-page-builder' ) ] = wp_date( get_option( 'date_format' ), $stamp ? $stamp : time() );
		}
		$meta[ __( 'Order no.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ] = '#' . $order->get_id();
		$meta[ __( 'Order date', 'sidcraft-commerce-for-sidcraft-page-builder' ) ] = $order->get_date();
		if ( $invoice && '' !== $order->get_payment_method_title() ) {
			$meta[ __( 'Payment', 'sidcraft-commerce-for-sidcraft-page-builder' ) ] = $order->get_payment_method_title();
		}
		foreach ( $meta as $label => $value ) {
			$pdf->text( $right - 110, $ry, $label, 9, 'regular', $muted, 'right' );
			$pdf->text( $right, $ry, (string) $value, 9, 'bold', $ink, 'right' );
			$ry += 13;
		}
		$y = max( $cy, $ry ) + 24;

		// Customer.
		$b    = $order->get_billing();
		$name = trim( ( $b['first_name'] ?? '' ) . ' ' . ( $b['last_name'] ?? '' ) );
		$addr = array_filter(
			array(
				$name,
				$b['address_1'] ?? '',
				$b['address_2'] ?? '',
				trim( ( $b['postcode'] ?? '' ) . ' ' . ( $b['city'] ?? '' ) ),
				! empty( $b['country'] ) ? Locations::country_name( $b['country'] ) : '',
				$b['email'] ?? '',
			)
		);
		if ( ! empty( $b['vat_number'] ) || get_post_meta( $order->get_id(), '_sidccofo_vat_number', true ) ) {
			$addr[] = 'VAT: ' . ( $b['vat_number'] ?? get_post_meta( $order->get_id(), '_sidccofo_vat_number', true ) );
		}
		$pdf->text( $x, $y, strtoupper( $invoice ? __( 'Bill to', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Ship to', 'sidcraft-commerce-for-sidcraft-page-builder' ) ), 8, 'bold', $muted );
		$y += 14;
		foreach ( $addr as $line ) {
			$pdf->text( $x, $y, (string) $line, 10.5, 'regular', $ink );
			$y += 14;
		}
		if ( 'pickup' === get_post_meta( $order->get_id(), '_sidccofo_delivery', true ) ) {
			$pdf->text( $x, $y, __( 'Local pickup', 'sidcraft-commerce-for-sidcraft-page-builder' ), 10.5, 'bold', $ink );
			$y += 14;
		}
		$y += 14;

		// Items.
		$cur = $order->get_currency();
		$pdf->rect( $x, $y - 12, $right - $x, 20, '#f1f1f4' );
		$pdf->text( $x + 8, $y + 2, __( 'Item', 'sidcraft-commerce-for-sidcraft-page-builder' ), 9, 'bold', $ink );
		$pdf->text( $right - 8, $y + 2, $invoice ? __( 'Total', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Qty', 'sidcraft-commerce-for-sidcraft-page-builder' ), 9, 'bold', $ink, 'right' );
		if ( $invoice ) {
			$pdf->text( $right - 120, $y + 2, __( 'Qty', 'sidcraft-commerce-for-sidcraft-page-builder' ), 9, 'bold', $ink, 'right' );
			$pdf->text( $right - 170, $y + 2, __( 'Price', 'sidcraft-commerce-for-sidcraft-page-builder' ), 9, 'bold', $ink, 'right' );
		}
		$y += 24;
		foreach ( $order->get_items() as $item ) {
			$lines = $pdf->wrap( (string) $item['name'], 10, $invoice ? 285 : 430, 'regular' );
			$top   = $y;
			foreach ( $lines as $line ) {
				$pdf->text( $x + 8, $y, $line, 10, 'regular', $ink );
				$y += 13;
			}
			if ( ! empty( $item['sku'] ) ) {
				$pdf->text( $x + 8, $y, 'SKU ' . $item['sku'], 8, 'regular', $muted );
				$y += 11;
			}
			foreach ( (array) ( $item['options'] ?? array() ) as $opt ) {
				$pdf->text( $x + 8, $y, trim( ( $opt['label'] ?? '' ) . ': ' . ( $opt['value'] ?? '' ) ), 8, 'regular', $muted );
				$y += 11;
			}
			if ( $invoice ) {
				$pdf->text( $right - 170, $top, format_in( $item['price'], $cur ), 10, 'regular', $ink, 'right' );
				$pdf->text( $right - 120, $top, (string) $item['quantity'], 10, 'regular', $ink, 'right' );
				$pdf->text( $right - 8, $top, format_in( $item['total'], $cur ), 10, 'regular', $ink, 'right' );
			} else {
				$pdf->text( $right - 8, $top, (string) $item['quantity'], 10, 'bold', $ink, 'right' );
			}
			$y += 6;
			$pdf->line( $x, $y - 4, $right, $y - 4, '#e4e4e8' );
			if ( $y > 760 ) {
				$pdf->add_page();
				$y = 60;
			}
		}

		// Totals (invoice) or notes (packing slip).
		if ( $invoice ) {
			$y += 8;
			\SidcraftCommerce\Currencies::switch_to( $cur );
			foreach ( totals_rows( $order ) as $row ) {
				if ( '' === $row['value'] ) {
					continue;
				}
				$bold = in_array( $row['key'], array( 'total' ), true );
				$pdf->text( $right - 130, $y, wp_strip_all_tags( $row['label'] ), $bold ? 11 : 10, $bold ? 'bold' : 'regular', $ink, 'right' );
				$pdf->text( $right - 8, $y, html_entity_decode( wp_strip_all_tags( $row['value'] ), ENT_QUOTES, 'UTF-8' ), $bold ? 11 : 10, $bold ? 'bold' : 'regular', $ink, 'right' );
				$y += $bold ? 18 : 15;
			}
			if ( get_post_meta( $order->get_id(), '_sidccofo_reverse_charge', true ) ) {
				$y += 4;
				$pdf->text( $x, $y, __( 'VAT reverse charge: the customer accounts for VAT.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 9, 'bold', $ink );
				$y += 14;
			}
		} elseif ( '' !== trim( (string) get_post_meta( $order->get_id(), '_sidccofo_notes', true ) ) ) {
			$y += 8;
			$pdf->text( $x, $y, strtoupper( __( 'Customer note', 'sidcraft-commerce-for-sidcraft-page-builder' ) ), 8, 'bold', $muted );
			$pdf->paragraph( $x, $y + 14, (string) get_post_meta( $order->get_id(), '_sidccofo_notes', true ), 10, 480 );
		}

		$footer = trim( (string) setting( 'inv_footer', '' ) );
		if ( $invoice && '' !== $footer ) {
			$pdf->paragraph( $x, 790, $footer, 8.5, 500, 'regular', $muted, 1.3, 3 );
		}
		return $pdf->output();
	}

	/*
	------------------------------------------------------------------
	 * Access
	 * ----------------------------------------------------------------
	 */

	/**
	 * Link to a document.
	 *
	 * @param Order  $order Order.
	 * @param string $type  invoice | packing.
	 * @return string
	 */
	public static function url( Order $order, $type = 'invoice' ) {
		return add_query_arg(
			array(
				'action' => 'sidccofo_invoice',
				'order'  => $order->get_id(),
				'type'   => 'packing' === $type ? 'packing' : 'invoice',
				'key'    => $order->get_key(),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Serve the PDF to the store manager, or to anyone with the order's key
	 * (the link in the customer's email and on their confirmation page).
	 * Packing slips are for managers only.
	 */
	public function download() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Access is by the order key or capability, like the order-received page.
		$id    = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		$type  = isset( $_GET['type'] ) && 'packing' === $_GET['type'] ? 'packing' : 'invoice';
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		$order = Order::get( $id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 404 ) );
		}
		$manager = current_user_can( \SidcraftCommerce\manage_cap() );
		$owner   = '' !== $key && $order->key_is_valid( $key ) && 'invoice' === $type;
		if ( ! $manager && ! $owner ) {
			wp_die( esc_html__( 'Not allowed.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		if ( 'invoice' === $type && '' === self::number( $order ) ) {
			wp_die( esc_html__( 'An invoice is made once the order is paid.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 409 ) );
		}
		$name = 'invoice' === $type ? self::number( $order ) : 'packing-slip-' . $order->get_id();
		Extras::send_file( sanitize_file_name( $name ) . '.pdf', 'application/pdf', self::pdf( $order, $type ) );
	}

	/**
	 * Order screen box.
	 */
	public function box() {
		add_meta_box( 'sidccofo-order-invoice', __( 'Invoice and packing slip', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_box' ), Post_Types::ORDER, 'side' );
	}

	/**
	 * Box content.
	 *
	 * @param \WP_Post $post Order post.
	 */
	public function render_box( $post ) {
		$order = Order::get( $post->ID );
		if ( ! $order ) {
			return;
		}
		$no = (string) get_post_meta( $order->get_id(), '_sidccofo_invoice_no', true );
		if ( '' !== $no ) {
			echo '<p><strong>' . esc_html( $no ) . '</strong></p>';
		}
		echo '<p><a class="button" href="' . esc_url( self::url( $order, 'invoice' ) ) . '">' . esc_html__( 'Download invoice (PDF)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></p>';
		echo '<p><a class="button" href="' . esc_url( self::url( $order, 'packing' ) ) . '">' . esc_html__( 'Download packing slip (PDF)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></p>';
	}

	/**
	 * Attach the invoice to the customer's email once the order is paid.
	 *
	 * @param array|false $mail      Mail.
	 * @param Order       $order     Order.
	 * @param string      $recipient customer|admin.
	 * @return array|false
	 */
	public function attach( $mail, $order, $recipient ) {
		if ( ! $mail || 'customer' !== $recipient || ! setting( 'inv_attach', 1 ) || ! $order instanceof Order || ! in_array( $order->get_status(), Post_Types::paid_statuses(), true ) ) {
			return $mail;
		}
		$file = self::temp_file( $order );
		if ( $file ) {
			$mail[4] = array_merge( (array) ( $mail[4] ?? array() ), array( $file ) );
		}
		return $mail;
	}

	/**
	 * Temp files to delete at the end of the request.
	 *
	 * @var string[]
	 */
	protected static $temp = array();

	/**
	 * Write the invoice to a temp file (for an email attachment).
	 *
	 * @param Order $order Order.
	 * @return string Path or ''.
	 */
	protected static function temp_file( Order $order ) {
		$no = self::number( $order );
		if ( '' === $no ) {
			return '';
		}
		$dir  = get_temp_dir();
		$path = trailingslashit( $dir ) . sanitize_file_name( $no ) . '-' . wp_generate_password( 6, false ) . '.pdf';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A short-lived file in the system temp folder.
		if ( false === file_put_contents( $path, self::pdf( $order, 'invoice' ) ) ) {
			return '';
		}
		self::$temp[] = $path;
		return $path;
	}

	/**
	 * Delete temp files.
	 */
	public static function cleanup() {
		foreach ( self::$temp as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		self::$temp = array();
	}

	/**
	 * Download link on the order-received page.
	 *
	 * @param string $html     Unit HTML.
	 * @param array  $settings Settings.
	 * @param mixed  $unit     Unit.
	 * @return string
	 */
	public function link( $html, $settings, $unit ) {
		unset( $settings, $unit );
		if ( ! setting( 'inv_link', 1 ) ) {
			return $html;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Same read-only access as the page itself.
		$id  = isset( $_GET['sidccofo_order'] ) ? absint( $_GET['sidccofo_order'] ) : 0;
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		$order = $id ? Order::get( $id ) : null;
		if ( ! $order || ! $order->key_is_valid( $key ) || ! in_array( $order->get_status(), Post_Types::paid_statuses(), true ) ) {
			return $html;
		}
		$box = '<p class="sc-invoice-link"><a class="sc-btn" href="' . esc_url( self::url( $order ) ) . '">' . esc_html__( 'Download invoice (PDF)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></p>';
		return preg_replace( '#</div>\s*$#', $box . '</div>', $html, 1 );
	}
}
