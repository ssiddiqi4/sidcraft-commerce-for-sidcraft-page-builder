<?php
/**
 * PDF tickets: one A4 page per ticket with the event, the attendee, a QR
 * code (and optionally a barcode) for check-in.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ticket_PDF
 */
class Ticket_PDF {

	/**
	 * Build a PDF for one or more tickets.
	 *
	 * @param Ticket[] $tickets Tickets.
	 * @return string PDF bytes.
	 */
	public static function render( array $tickets ) {
		$first = $tickets ? reset( $tickets ) : null;
		/* translators: %s: event name. */
		$title = $first && $first->get_event() ? sprintf( __( 'Tickets – %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $first->get_event()->get_name() ) : __( 'Tickets', 'sidcraft-commerce-for-sidcraft-page-builder' );
		$pdf   = new PDF( $title );
		foreach ( $tickets as $ticket ) {
			self::page( $pdf, $ticket );
		}
		if ( ! $tickets ) {
			$pdf->add_page();
		}
		return $pdf->output();
	}

	/**
	 * Accent colour for tickets.
	 *
	 * @return string
	 */
	protected static function accent() {
		$c = sanitize_hex_color( (string) setting( 'ev_pdf_color', '#7c5cfc' ) );
		return $c ? $c : '#7c5cfc';
	}

	/**
	 * One ticket page.
	 *
	 * @param PDF    $pdf    Writer.
	 * @param Ticket $ticket Ticket.
	 */
	protected static function page( PDF $pdf, Ticket $ticket ) {
		$event  = $ticket->get_event();
		$accent = self::accent();
		$muted  = '#6b6a7a';
		$ink    = '#1a1a1f';
		$pdf->add_page();

		$x  = 36;
		$w  = 523;
		$y  = 36;
		$code_kind = (string) setting( 'ev_code_type', 'qr' );

		// Card background: painted behind the content once its height is known.
		$mark = $pdf->mark();

		// Optional featured image (JPEG).
		$img_h = 0;
		if ( $event && setting( 'ev_pdf_image', 1 ) ) {
			$thumb = (int) get_post_thumbnail_id( $event->get_id() );
			$file  = $thumb ? get_attached_file( $thumb ) : '';
			if ( $file ) {
				$meta  = wp_get_attachment_metadata( $thumb );
				$large = is_array( $meta ) && ! empty( $meta['sizes']['large']['file'] ) ? path_join( dirname( $file ), $meta['sizes']['large']['file'] ) : $file;
				if ( $pdf->jpeg( $x, $y, $w, 140, $large ) || $pdf->jpeg( $x, $y, $w, 140, $file ) ) {
					$img_h = 140;
				}
			}
		}

		// Title band.
		$band_y = $y + $img_h;
		$name   = $event ? $event->get_name() : __( 'Event', 'sidcraft-commerce-for-sidcraft-page-builder' );
		$lines  = $pdf->wrap( $name, 20, $w - 40, 'bold', 2 );
		$band_h = 34 + count( $lines ) * 25;
		$pdf->rect( $x, $band_y, $w, $band_h, $accent );
		$ty = $band_y + 34;
		foreach ( $lines as $line ) {
			$pdf->text( $x + 20, $ty, $line, 20, 'bold', '#ffffff' );
			$ty += 25;
		}
		$pdf->text( $x + $w - 20, $band_y + 18, strtoupper( get_bloginfo( 'name' ) ), 8, 'bold', '#ffffff', 'right' );

		// Details (left column).
		$cy   = $band_y + $band_h + 28;
		$colw = 300;
		$row  = static function ( $label, $value, $link = '' ) use ( $pdf, $x, &$cy, $colw, $muted, $ink ) {
			if ( '' === trim( (string) $value ) ) {
				return;
			}
			$pdf->text( $x + 20, $cy, strtoupper( $label ), 7.5, 'bold', $muted );
			$cy += 14;
			$top = $cy - 11;
			$cy  = $pdf->paragraph( $x + 20, $cy, $value, 11.5, $colw, 'regular', '' !== $link ? '#2563eb' : $ink, 1.3, 3 );
			if ( '' !== $link ) {
				$pdf->link( $x + 20, $top, $colw, $cy - $top, $link );
			}
			$cy += 8;
		};

		if ( $event ) {
			$row( __( 'Date', 'sidcraft-commerce-for-sidcraft-page-builder' ), $event->get_date_label() . ( $event->is_online() && ! $event->is_all_day() ? ' (' . $event->get_timezone_label() . ')' : '' ) );
			if ( $event->has_location() ) {
				$row( __( 'Venue', 'sidcraft-commerce-for-sidcraft-page-builder' ), $event->get_location_label(), $event->get_directions_url() );
			}
			if ( $event->is_online() && '' !== $event->get_join_url() ) {
				$zoom = $event->get_zoom();
				$join = $event->get_join_url() . ( '' !== $zoom['id'] ? '  ·  ID ' . $zoom['id'] . ( '' !== $zoom['passcode'] ? '  ·  ' . __( 'Passcode', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' ' . $zoom['passcode'] : '' ) : '' );
				$row( __( 'Join online', 'sidcraft-commerce-for-sidcraft-page-builder' ), $join, $event->get_join_url() );
			}
		}
		$type = $ticket->get_type_name();
		$att  = (string) $ticket->meta( 'attendee_type' );
		$row( __( 'Ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ), $type . ( '' !== $att && $att !== $type ? ' · ' . $att : '' ) );
		$row( __( 'Attendee', 'sidcraft-commerce-for-sidcraft-page-builder' ), $ticket->get_attendee_name() );
		if ( $ticket->meta( 'order' ) ) {
			/* translators: %d: order number. */
			$row( __( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ), sprintf( __( '#%d', 'sidcraft-commerce-for-sidcraft-page-builder' ), (int) $ticket->meta( 'order' ) ) );
		}

		// Code (right column).
		$qx   = $x + $w - 20 - 170;
		$qy   = $band_y + $band_h + 18;
		$code = $ticket->get_code();
		if ( 'barcode' !== $code_kind ) {
			$pdf->qr( $qx, $qy, 170, $ticket->get_qr_payload() );
			$qy += 178;
		}
		$pdf->text( $qx + 85, $qy + 6, $code, 14, 'mono', $ink, 'center' );
		$qy += 16;
		if ( 'qr' !== $code_kind ) {
			$pdf->barcode( $qx + 5, $qy, 160, 46, $code );
			$qy += 54;
		}
		$pdf->text( $qx + 85, $qy + 6, __( 'Show this code at the entrance', 'sidcraft-commerce-for-sidcraft-page-builder' ), 7.5, 'regular', $muted, 'center' );
		$card_h = max( $cy, $qy + 6 ) + 20 - $y;
		$pdf->rect_behind( $mark, $x, $y, $w, $card_h, '#f4f3f8' );

		if ( 'cancelled' === $ticket->get_status() ) {
			$pdf->rect( $x, $band_y + $band_h, $w, 26, '#dc2626' );
			$pdf->text( $x + $w / 2, $band_y + $band_h + 18, strtoupper( __( 'Cancelled – not valid for entry', 'sidcraft-commerce-for-sidcraft-page-builder' ) ), 11, 'bold', '#ffffff', 'center' );
		}

		// Footer.
		$fy = $y + $card_h + 26;
		$pdf->line( 20, $fy - 12, 575, $fy - 12, '#b8b6c4', 0.8, true );
		$footer = trim( (string) setting( 'ev_pdf_footer', '' ) );
		if ( $event && ( '' !== $event->get_organizer() || '' !== $event->get_organizer_email() ) ) {
			/* translators: %s: organiser name and email. */
			$footer = trim( sprintf( __( 'Organiser: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), trim( $event->get_organizer() . ' ' . $event->get_organizer_email() ) ) . "\n" . $footer );
		}
		if ( '' !== $footer ) {
			$pdf->paragraph( $x, $fy + 8, $footer, 8.5, $w, 'regular', $muted, 1.4, 8 );
		}
		$pdf->link( $qx, $band_y + $band_h + 18, 170, 170, $ticket->get_url() );
	}
}
