<?php
/**
 * Zoom and WhatsApp.
 *
 * Zoom: paste a meeting link on the event, or let the store create (and keep
 * the time of) a meeting with a Zoom Server-to-Server OAuth app. Ticket
 * holders get the join link, meeting ID and passcode in their email, PDF,
 * ticket page and calendar file; the public event page never shows them.
 *
 * WhatsApp: click-to-chat with the organiser on the event page, a group
 * invite link for ticket holders, "share my ticket" on the ticket page, and
 * optionally a WhatsApp Cloud API template message when tickets are issued.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use SidcraftCommerce\Data\Order;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Integrations
 */
class Integrations {

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'sidcraft_commerce_event_saved', array( $this, 'maybe_zoom' ), 10, 2 );
		add_action( 'sidcraft_commerce_tickets_issued', array( $this, 'whatsapp_tickets' ), 10, 2 );
	}

	/*
	------------------------------------------------------------------
	 * Zoom
	 * ----------------------------------------------------------------
	 */

	/**
	 * Zoom API credentials present?
	 *
	 * @return bool
	 */
	public static function zoom_ready() {
		return '' !== trim( (string) setting( 'zoom_account_id', '' ) ) && '' !== trim( (string) setting( 'zoom_client_id', '' ) ) && '' !== trim( (string) setting( 'zoom_client_secret', '' ) );
	}

	/**
	 * Server-to-Server OAuth access token (cached ~55 minutes).
	 *
	 * @return string|\WP_Error
	 */
	protected static function zoom_token() {
		$cached = get_transient( 'sidccofo_zoom_token' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$response = wp_remote_post(
			add_query_arg(
				array(
					'grant_type' => 'account_credentials',
					'account_id' => rawurlencode( trim( (string) setting( 'zoom_account_id', '' ) ) ),
				),
				'https://zoom.us/oauth/token'
			),
			array(
				'timeout' => 15,
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
					'Authorization' => 'Basic ' . base64_encode( trim( (string) setting( 'zoom_client_id', '' ) ) . ':' . trim( (string) setting( 'zoom_client_secret', '' ) ) ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new \WP_Error( 'sidccofo_zoom_auth', isset( $body['reason'] ) ? (string) $body['reason'] : __( 'Zoom did not accept the credentials.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		set_transient( 'sidccofo_zoom_token', (string) $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 300 ) );
		return (string) $body['access_token'];
	}

	/**
	 * Create or update the event's Zoom meeting when "Create a Zoom meeting" is on.
	 *
	 * @param Event $event Event.
	 * @param bool  $changed_time Start or end changed.
	 */
	public function maybe_zoom( $event, $changed_time ) {
		if ( ! $event instanceof Event || ! $event->is_online() || ! $event->meta( 'zoom_auto' ) || ! self::zoom_ready() || ! $event->get_start() ) {
			return;
		}
		$id = preg_replace( '/\D/', '', (string) $event->meta( 'zoom_id' ) );
		if ( '' !== $id && ! $changed_time ) {
			return;
		}
		$token = self::zoom_token();
		if ( is_wp_error( $token ) ) {
			update_post_meta( $event->get_id(), '_sidccofo_ev_zoom_error', $token->get_error_message() );
			return;
		}
		$payload = array(
			'topic'      => wp_strip_all_tags( $event->get_name() ),
			'type'       => 2,
			'start_time' => wp_date( 'Y-m-d\TH:i:s', $event->get_start() ),
			'timezone'   => wp_timezone_string(),
			'duration'   => max( 15, (int) round( ( $event->get_end() - $event->get_start() ) / 60 ) ),
			'agenda'     => mb_substr( $event->get_summary( 60 ), 0, 1900 ),
			'settings'   => array(
				'join_before_host' => false,
				'waiting_room'     => true,
				'approval_type'    => 2,
			),
		);
		$url      = '' !== $id ? 'https://api.zoom.us/v2/meetings/' . $id : 'https://api.zoom.us/v2/users/me/meetings';
		$response = wp_remote_request(
			$url,
			array(
				'method'  => '' !== $id ? 'PATCH' : 'POST',
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			update_post_meta( $event->get_id(), '_sidccofo_ev_zoom_error', $response->get_error_message() );
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 300 ) {
			update_post_meta( $event->get_id(), '_sidccofo_ev_zoom_error', (string) ( $body['message'] ?? 'HTTP ' . $code ) );
			return;
		}
		delete_post_meta( $event->get_id(), '_sidccofo_ev_zoom_error' );
		if ( '' === $id && is_array( $body ) ) {
			update_post_meta( $event->get_id(), '_sidccofo_ev_zoom_id', (string) ( $body['id'] ?? '' ) );
			update_post_meta( $event->get_id(), '_sidccofo_ev_zoom_url', esc_url_raw( (string) ( $body['join_url'] ?? '' ) ) );
			update_post_meta( $event->get_id(), '_sidccofo_ev_zoom_passcode', (string) ( $body['password'] ?? '' ) );
		}
		Event::forget( $event->get_id() );
	}

	/*
	------------------------------------------------------------------
	 * WhatsApp
	 * ----------------------------------------------------------------
	 */

	/**
	 * Share-my-ticket link (WhatsApp click to chat with prefilled text).
	 *
	 * @param Ticket $ticket Ticket.
	 * @return string
	 */
	public static function share_url( Ticket $ticket ) {
		$event = $ticket->get_event();
		/* translators: 1: event name, 2: date, 3: ticket link. */
		$text = sprintf( __( 'My ticket for %1$s (%2$s): %3$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $event ? $event->get_name() : '', $event ? $event->get_date_label() : '', $ticket->get_url() );
		return 'https://wa.me/?text=' . rawurlencode( $text );
	}

	/**
	 * Cloud API configured?
	 *
	 * @return bool
	 */
	public static function whatsapp_ready() {
		return (bool) setting( 'wa_enabled', 0 ) && '' !== trim( (string) setting( 'wa_phone_number_id', '' ) ) && '' !== trim( (string) setting( 'wa_token', '' ) ) && '' !== trim( (string) setting( 'wa_template', '' ) );
	}

	/**
	 * Send a template message to a phone number.
	 *
	 * Template body parameters, in order: {{1}} name, {{2}} event, {{3}} date,
	 * {{4}} ticket link (or the order's ticket PDF link).
	 *
	 * @param string   $phone  Number with country code.
	 * @param string[] $params Body parameters.
	 * @return true|\WP_Error
	 */
	public static function whatsapp_send( $phone, array $params ) {
		$to = preg_replace( '/\D+/', '', (string) $phone );
		if ( strlen( $to ) < 8 ) {
			return new \WP_Error( 'sidccofo_wa_phone', __( 'No usable phone number.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$parameters = array();
		foreach ( $params as $p ) {
			$parameters[] = array(
				'type' => 'text',
				'text' => mb_substr( wp_strip_all_tags( (string) $p ), 0, 900 ),
			);
		}
		$response = wp_remote_post(
			'https://graph.facebook.com/v19.0/' . rawurlencode( trim( (string) setting( 'wa_phone_number_id', '' ) ) ) . '/messages',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . trim( (string) setting( 'wa_token', '' ) ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'messaging_product' => 'whatsapp',
						'to'                => $to,
						'type'              => 'template',
						'template'          => array(
							'name'       => trim( (string) setting( 'wa_template', '' ) ),
							'language'   => array( 'code' => trim( (string) setting( 'wa_language', 'en_US' ) ) ),
							'components' => array(
								array(
									'type'       => 'body',
									'parameters' => $parameters,
								),
							),
						),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			return new \WP_Error( 'sidccofo_wa_failed', (string) ( $body['error']['message'] ?? 'HTTP ' . $code ) );
		}
		return true;
	}

	/**
	 * After tickets are issued: message the buyer on WhatsApp.
	 *
	 * @param Order    $order   Order.
	 * @param Ticket[] $tickets Tickets.
	 */
	public function whatsapp_tickets( $order, $tickets ) {
		if ( ! $order instanceof Order || ! $tickets || ! self::whatsapp_ready() ) {
			return;
		}
		$billing = $order->get_billing();
		$phone   = (string) ( $billing['phone'] ?? '' );
		if ( '' === $phone ) {
			return;
		}
		$first = reset( $tickets );
		$event = $first->get_event();
		$link  = 1 === count( $tickets ) ? $first->get_url() : Ticketing::order_pdf_url( $order );
		$sent  = self::whatsapp_send( $phone, array( (string) ( $billing['first_name'] ?? '' ), $event ? $event->get_name() : '', $event ? $event->get_date_label() : '', $link ) );
		$order->add_note( true === $sent ? __( 'Tickets sent on WhatsApp.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : 'WhatsApp: ' . $sent->get_error_message() );
	}
}
