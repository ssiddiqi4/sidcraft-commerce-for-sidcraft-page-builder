<?php
/**
 * Sends one marketing email: merge tags, the store's email layout, the
 * unsubscribe footer and headers (one-click List-Unsubscribe), and open and
 * click tracking through the email log.
 *
 * Merge tags: {first_name}, {email}, {store_name}, {shop_url}, {shop_link},
 * {unsubscribe_url}, plus whatever the caller passes (e.g. {cart}).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Marketing;

use SidcraftCommerce\Plugin;
use SidcraftCommerce\Rest;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Mailer
 */
class Mailer {

	/**
	 * Log table.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'sidccofo_email_log';
	}

	/**
	 * Send to a subscriber, once per ref (a campaign "c12", or an automation
	 * "welcome", "cart:<token>", "followup:<order>", "winback:<time>").
	 *
	 * @param object $row     Subscriber row.
	 * @param string $ref     What this email is.
	 * @param string $subject Subject (merge tags allowed).
	 * @param string $body    Body: HTML, or plain text with blank lines between paragraphs.
	 * @param array  $tags    Extra merge tags: name => HTML (already escaped).
	 * @return bool Sent.
	 */
	public static function send( $row, $ref, $subject, $body, array $tags = array() ) {
		global $wpdb;
		if ( ! $row || 'subscribed' !== $row->status ) {
			return false;
		}
		// Claim the log row first: a second batch or a cron overlap can't send it again.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; INSERT IGNORE is the lock.
		$claimed = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (ref, subscriber_id, email, sent) VALUES (%s, %d, %s, %d)', self::log_table(), substr( (string) $ref, 0, 40 ), (int) $row->id, $row->email, time() ) );
		if ( ! $claimed ) {
			return false;
		}
		$log_id = (int) $wpdb->insert_id;
		$ok     = self::deliver( $row, $subject, $body, $tags, $log_id );
		if ( ! $ok ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$wpdb->update( self::log_table(), array( 'failed' => 1 ), array( 'id' => $log_id ) );
		}
		return $ok;
	}

	/**
	 * Render and send (no log row: tests and confirmation emails pass 0).
	 *
	 * @param object $row     Subscriber row (or a stand-in with email, first_name, token).
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @param array  $tags    Extra merge tags.
	 * @param int    $log_id  Log row for tracking (0 = none).
	 * @param bool   $footer  Add the unsubscribe footer and headers.
	 * @return bool
	 */
	public static function deliver( $row, $subject, $body, array $tags = array(), $log_id = 0, $footer = true ) {
		$unsubscribe = ! empty( $row->token ) ? Subscribers::link( $row, 'unsubscribe' ) : home_url( '/' );
		$shop        = \SidcraftCommerce\page_url( 'shop' );
		$tags        = array_merge(
			array(
				'first_name'      => esc_html( '' !== (string) $row->first_name ? $row->first_name : __( 'there', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'email'           => esc_html( $row->email ),
				'store_name'      => esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				'shop_url'        => esc_url( $shop ),
				'shop_link'       => self::button( $shop, __( 'Visit the shop', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'unsubscribe_url' => esc_url( $unsubscribe ),
			),
			$tags
		);

		$subject = wp_strip_all_tags( html_entity_decode( self::merge( $subject, $tags ), ENT_QUOTES, 'UTF-8' ) );
		$html    = self::merge( self::format_body( $body ), $tags );
		if ( $log_id && setting( 'marketing_track', 1 ) ) {
			$html = self::track_links( $html, $log_id );
		}
		$html = Plugin::instance()->templates->render(
			'emails/marketing.php',
			array(
				'subject'     => $subject,
				'content'     => $html,
				'footer'      => $footer,
				'unsubscribe' => $unsubscribe,
				'address'     => (string) setting( 'marketing_address', '' ),
				'pixel'       => $log_id && setting( 'marketing_track', 1 ) ? self::tracking_url( 'open', $log_id ) : '',
			)
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from() );
		if ( $footer && ! empty( $row->token ) ) {
			$headers[] = 'List-Unsubscribe: <' . esc_url_raw( rest_url( Rest::NS . '/marketing/unsubscribe?t=' . $row->token ) ) . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		/**
		 * Filter a marketing email before sending; return false to skip it.
		 *
		 * @param array|false $mail [ to, subject, body, headers ].
		 * @param object      $row  Subscriber.
		 */
		$mail = apply_filters( 'sidcraft_commerce_marketing_email', array( $row->email, $subject, $html, $headers ), $row );
		return $mail ? (bool) wp_mail( $mail[0], $mail[1], $mail[2], $mail[3] ) : false;
	}

	/**
	 * "From" header.
	 *
	 * @return string
	 */
	public static function from() {
		$name  = sanitize_text_field( (string) setting( 'from_name', get_bloginfo( 'name' ) ) );
		$email = sanitize_email( (string) setting( 'marketing_from_email', '' ) );
		if ( ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}
		return str_replace( array( "\r", "\n", '<', '>' ), '', $name ) . ' <' . $email . '>';
	}

	/**
	 * Replace {tags}. Unknown tags are left as they are.
	 *
	 * @param string $text Text.
	 * @param array  $tags name => replacement.
	 * @return string
	 */
	public static function merge( $text, array $tags ) {
		return preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( $m ) use ( $tags ) {
				return array_key_exists( $m[1], $tags ) ? (string) $tags[ $m[1] ] : $m[0];
			},
			(string) $text
		);
	}

	/**
	 * Plain text (settings textareas) becomes paragraphs; HTML (campaigns) is
	 * kept, without scripts.
	 *
	 * @param string $body Body.
	 * @return string
	 */
	public static function format_body( $body ) {
		$body = (string) $body;
		if ( $body === wp_strip_all_tags( $body ) ) {
			$body = wpautop( esc_html( $body ) );
			// Merge tags hold HTML (buttons, product lists): keep them out of <p>.
			return preg_replace( '#<p>\s*(\{[a-z_]+\})\s*</p>#', '$1', $body );
		}
		return wp_kses_post( $body );
	}

	/**
	 * An email button.
	 *
	 * @param string $url   Link.
	 * @param string $label Text.
	 * @return string
	 */
	public static function button( $url, $label ) {
		return '<p style="margin:24px 0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#7c5cfc;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:8px;">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Point links through the click tracker (not the unsubscribe link).
	 *
	 * @param string $html   HTML.
	 * @param int    $log_id Log row.
	 * @return string
	 */
	protected static function track_links( $html, $log_id ) {
		return preg_replace_callback(
			'#href="(https?://[^"]+)"#i',
			static function ( $m ) use ( $log_id ) {
				$url = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
				if ( false !== strpos( $url, 'sidccofo_list=' ) ) {
					return $m[0];
				}
				return 'href="' . esc_url( add_query_arg( 'u', rawurlencode( $url ), self::tracking_url( 'click', $log_id, $url ) ) ) . '"';
			},
			$html
		);
	}

	/**
	 * Signed tracking URL. A click's signature covers its destination too, so
	 * the tracker only ever sends people where the email pointed.
	 *
	 * @param string $kind   open|click.
	 * @param int    $log_id Log row.
	 * @param string $url    Destination (clicks).
	 * @return string
	 */
	public static function tracking_url( $kind, $log_id, $url = '' ) {
		return rest_url( Rest::NS . '/marketing/' . $kind . '/' . (int) $log_id . '/' . self::sign( $kind . (int) $log_id . '|' . $url ) );
	}

	/**
	 * Short HMAC so log ids can't be guessed or the click tracker used as an open redirect.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	public static function sign( $data ) {
		return substr( hash_hmac( 'sha256', (string) $data, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Record an open or a click (first time only).
	 *
	 * @param string $kind   open|click.
	 * @param int    $log_id Log row.
	 */
	public static function record( $kind, $log_id ) {
		global $wpdb;
		$column = 'click' === $kind ? 'clicked' : 'opened';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; column from a fixed list.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET {$column} = %d WHERE id = %d AND {$column} = 0", self::log_table(), time(), (int) $log_id ) );
		if ( 'clicked' === $column ) {
			// A click means it was opened, even with images off.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET opened = %d WHERE id = %d AND opened = 0', self::log_table(), time(), (int) $log_id ) );
		}
		// phpcs:enable
	}

	/**
	 * Sent / opened / clicked counts for a ref (or a ref prefix ending in "%").
	 *
	 * @param string $ref Ref.
	 * @return array sent, opened, clicked, failed.
	 */
	public static function stats( $ref ) {
		global $wpdb;
		$op = '%' === substr( $ref, -1 ) ? 'LIKE' : '=';
		$v  = '%' === substr( $ref, -1 ) ? $wpdb->esc_like( substr( $ref, 0, -1 ) ) . '%' : $ref;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; operator from a fixed pair.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS sent, SUM(opened > 0) AS opened, SUM(clicked > 0) AS clicked, SUM(failed) AS failed FROM %i WHERE ref {$op} %s", self::log_table(), $v ) );
		return array(
			'sent'    => (int) ( $row->sent ?? 0 ),
			'opened'  => (int) ( $row->opened ?? 0 ),
			'clicked' => (int) ( $row->clicked ?? 0 ),
			'failed'  => (int) ( $row->failed ?? 0 ),
		);
	}
}
