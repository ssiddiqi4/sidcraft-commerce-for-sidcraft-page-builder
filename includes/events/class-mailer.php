<?php
/**
 * Ticket emails: confirmation with PDF tickets, QR codes and a calendar
 * file, per-attendee copies, and reminders before the event.
 *
 * QR images are embedded in the message (CID) by default, so they show even
 * when remote images are blocked; "linked" mode uses signed image URLs for
 * mail plugins that don't send through PHPMailer.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Plugin;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailer
 */
class Mailer {

	/**
	 * Group tickets by event ID.
	 *
	 * @param Ticket[] $tickets Tickets.
	 * @return array event ID => [ event, tickets ].
	 */
	public static function group( array $tickets ) {
		$groups = array();
		foreach ( $tickets as $ticket ) {
			$event = $ticket->get_event();
			if ( ! $event ) {
				continue;
			}
			$id = $event->get_id();
			if ( ! isset( $groups[ $id ] ) ) {
				$groups[ $id ] = array(
					'event'   => $event,
					'tickets' => array(),
				);
			}
			$groups[ $id ]['tickets'][] = $ticket;
		}
		return $groups;
	}

	/**
	 * Image for a ticket in emails: PNG bytes of the QR code or barcode.
	 *
	 * @param Ticket $ticket Ticket.
	 * @return string
	 */
	public static function code_png( Ticket $ticket ) {
		return 'barcode' === setting( 'ev_code_type', 'qr' ) ? Barcode::png( $ticket->get_code(), 70, 2 ) : QR::png( $ticket->get_qr_payload(), 5 );
	}

	/**
	 * Send tickets.
	 *
	 * @param Order|null $order    Order (null for tickets issued by hand).
	 * @param Ticket[]   $tickets  Tickets to include.
	 * @param string     $to       Recipient.
	 * @param array      $args     heading, intro, kind (tickets|attendee|reminder), attach (bool).
	 * @return bool
	 */
	public static function send( $order, array $tickets, $to, array $args = array() ) {
		if ( ! is_email( $to ) || ! $tickets ) {
			return false;
		}
		$args   = wp_parse_args(
			$args,
			array(
				'heading' => __( 'Your tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'intro'   => '',
				'kind'    => 'tickets',
				'attach'  => true,
			)
		);
		$embed  = 'link' !== setting( 'ev_email_images', 'embed' );
		$groups = self::group( $tickets );
		$images = array();
		foreach ( $tickets as $ticket ) {
			$images[ $ticket->get_id() ] = $embed ? 'cid:sidccofo-code-' . $ticket->get_id() : $ticket->get_qr_url();
		}

		$body = Plugin::instance()->templates->render(
			'emails/tickets.php',
			array(
				'order'   => $order,
				'groups'  => $groups,
				'heading' => $args['heading'],
				'intro'   => $args['intro'],
				'kind'    => $args['kind'],
				'images'  => $images,
			)
		);

		$store   = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$from    = sanitize_text_field( (string) setting( 'from_name', $store ) );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from . ' <' . get_option( 'admin_email' ) . '>',
		);
		$first   = reset( $groups );
		$subject = $first ? $args['heading'] . ' – ' . wp_strip_all_tags( $first['event']->get_name() ) : $args['heading'];

		// Attachments: one PDF with every ticket, plus a calendar file per event.
		$files = array();
		$tmp   = '';
		if ( $args['attach'] ) {
			$tmp = trailingslashit( get_temp_dir() ) . 'sidccofo-' . wp_generate_password( 12, false ) . '/';
			wp_mkdir_p( $tmp );
			if ( setting( 'ev_attach_pdf', 1 ) ) {
				$name = $order ? 'tickets-' . $order->get_id() . '.pdf' : 'ticket-' . sanitize_file_name( strtolower( reset( $tickets )->get_code() ) ) . '.pdf';
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary attachment, deleted after sending.
				if ( file_put_contents( $tmp . $name, Ticket_PDF::render( $tickets ) ) ) {
					$files[] = $tmp . $name;
				}
			}
			if ( setting( 'ev_attach_ics', 1 ) ) {
				foreach ( $groups as $group ) {
					$name = sanitize_file_name( sanitize_title( $group['event']->get_name() ) ) . '.ics';
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					if ( file_put_contents( $tmp . $name, Calendar::event_ics( $group['event'], true ) ) ) {
						$files[] = $tmp . $name;
					}
				}
			}
		}

		$embedder = null;
		if ( $embed ) {
			$embedder = static function ( $phpmailer ) use ( $tickets ) {
				foreach ( $tickets as $ticket ) {
					$png = self::code_png( $ticket );
					if ( '' !== $png && method_exists( $phpmailer, 'addStringEmbeddedImage' ) ) {
						$phpmailer->addStringEmbeddedImage( $png, 'sidccofo-code-' . $ticket->get_id(), 'ticket-' . strtolower( $ticket->get_code() ) . '.png', 'base64', 'image/png' );
					}
				}
			};
			add_action( 'phpmailer_init', $embedder );
		}

		/**
		 * Filter a ticket email; return false to skip it.
		 *
		 * @param array|false $mail    [ to, subject, body, headers, attachments ].
		 * @param Order|null  $order   Order.
		 * @param Ticket[]    $tickets Tickets.
		 * @param string      $kind    tickets | attendee | reminder.
		 */
		$mail = apply_filters( 'sidcraft_commerce_ticket_email', array( $to, $subject, $body, $headers, $files ), $order, $tickets, $args['kind'] );
		$sent = $mail ? wp_mail( $mail[0], $mail[1], $mail[2], $mail[3], $mail[4] ) : false;

		if ( $embedder ) {
			remove_action( 'phpmailer_init', $embedder );
		}
		foreach ( $files as $file ) {
			wp_delete_file( $file );
		}
		if ( '' !== $tmp && is_dir( $tmp ) ) {
			@rmdir( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Our empty temp folder.
		}
		return (bool) $sent;
	}

	/**
	 * Tickets email for an order (and per-attendee copies when enabled).
	 *
	 * @param Order    $order   Order.
	 * @param Ticket[] $tickets Tickets (default: all valid tickets of the order).
	 * @return bool
	 */
	public static function send_order( Order $order, array $tickets = array() ) {
		return \SidcraftCommerce\Multilingual::for_order(
			$order,
			static function () use ( $order, $tickets ) {
				return self::send_order_now( $order, $tickets );
			}
		);
	}

	/**
	 * send_order() in the order's language.
	 *
	 * @param Order    $order   Order.
	 * @param Ticket[] $tickets Tickets.
	 * @return bool
	 */
	protected static function send_order_now( Order $order, array $tickets ) {
		$tickets = $tickets ? $tickets : array_values(
			array_filter(
				Ticket::for_order( $order->get_id() ),
				static function ( Ticket $t ) {
					return 'cancelled' !== $t->get_status();
				}
			)
		);
		if ( ! $tickets ) {
			return false;
		}
		$billing = $order->get_billing();
		$sent    = self::send(
			$order,
			$tickets,
			$order->get_email(),
			array(
				'heading' => _n( 'Your ticket', 'Your tickets', count( $tickets ), 'sidcraft-commerce-for-sidcraft-page-builder' ),
				/* translators: %s: first name. */
				'intro'   => sprintf( __( 'Hi %s, thanks for your order! Your tickets are below and attached as a PDF. Show the QR code on your phone or a printout at the entrance.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $billing['first_name'] ?? '' ),
			)
		);
		if ( $sent ) {
			/* translators: %s: email address. */
			$order->add_note( sprintf( __( 'Tickets emailed to %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_email() ) );
		}

		if ( setting( 'ev_email_attendees', 0 ) ) {
			foreach ( $tickets as $ticket ) {
				$email = (string) $ticket->meta( 'email' );
				if ( is_email( $email ) && strtolower( $email ) !== strtolower( $order->get_email() ) ) {
					$buyer = trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) );
					self::send(
						$order,
						array( $ticket ),
						$email,
						array(
							'heading' => __( 'Your ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							/* translators: 1: attendee name, 2: buyer name. */
							'intro'   => sprintf( __( 'Hi %1$s, %2$s got you a ticket. Show the QR code at the entrance.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $ticket->get_attendee_name(), '' !== $buyer ? $buyer : __( 'a friend', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
							'kind'    => 'attendee',
						)
					);
				}
			}
		}
		return $sent;
	}

	/**
	 * Reminder before an event, to each order's buyer (and attendees when enabled).
	 *
	 * @param Event $event Event.
	 * @return int Emails sent.
	 */
	public static function send_reminders( Event $event ) {
		$by_email = array();
		foreach ( Ticket::for_event( $event->get_id() ) as $ticket ) {
			if ( 'cancelled' === $ticket->get_status() ) {
				continue;
			}
			$order = $ticket->get_order();
			$buyer = $order ? $order->get_email() : $ticket->get_attendee_email();
			if ( is_email( $buyer ) ) {
				$by_email[ strtolower( $buyer ) ]['tickets'][] = $ticket;
				$by_email[ strtolower( $buyer ) ]['order']     = $order;
			}
			$own = (string) $ticket->meta( 'email' );
			if ( setting( 'ev_email_attendees', 0 ) && is_email( $own ) && strtolower( $own ) !== strtolower( (string) $buyer ) ) {
				$by_email[ strtolower( $own ) ]['tickets'][] = $ticket;
				$by_email[ strtolower( $own ) ]['order']     = $order;
			}
		}
		$sent = 0;
		foreach ( $by_email as $email => $row ) {
			$ok = self::send(
				$row['order'],
				$row['tickets'],
				$email,
				array(
					/* translators: %s: event name. */
					'heading' => __( 'See you soon', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					/* translators: 1: event name, 2: date. */
					'intro'   => sprintf( __( 'A reminder that %1$s is coming up on %2$s. Your tickets are below.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $event->get_name(), $event->get_date_label() ),
					'kind'    => 'reminder',
					'attach'  => (bool) setting( 'ev_reminder_attach', 0 ),
				)
			);
			if ( $ok ) {
				++$sent;
			}
		}
		return $sent;
	}
}
