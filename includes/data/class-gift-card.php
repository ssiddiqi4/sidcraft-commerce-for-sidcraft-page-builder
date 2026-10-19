<?php
/**
 * Gift card model on top of the sidccofo_giftcard post type.
 *
 * The post title is the code (XXXX-XXXX-XXXX-XXXX). Meta:
 *   _sidccofo_gc_code            Normalised code (upper case, no dashes) for lookups.
 *   _sidccofo_gc_initial         Value when issued.
 *   _sidccofo_gc_balance         Remaining value.
 *   _sidccofo_gc_expires         Y-m-d, last valid day ('' = never).
 *   _sidccofo_gc_recipient_name / _email, _sidccofo_gc_sender, _sidccofo_gc_message
 *   _sidccofo_gc_send_date       Y-m-d to email the recipient ('' = right away).
 *   _sidccofo_gc_sent            Timestamp the card was emailed (0 = not yet).
 *   _sidccofo_gc_order           Order that bought it (0 = issued by the store).
 *   _sidccofo_gc_log             [ [ time, amount (+/-), order, note ], … ].
 *
 * A card is usable while published, not expired and with a balance.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Data;

// Balances are in the store currency, so they round to its precision.
use function SidcraftCommerce\round_base as round_amount;

defined( 'ABSPATH' ) || exit;

/**
 * Class Gift_Card
 */
class Gift_Card {

	/** Characters used in codes (no 0/O, 1/I/L). */
	const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	/**
	 * Post.
	 *
	 * @var \WP_Post
	 */
	protected $post;

	/**
	 * Normalise a code for lookups: upper case, letters and digits only.
	 *
	 * @param string $code Code as typed.
	 * @return string
	 */
	public static function normalize( $code ) {
		return substr( preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) $code ) ), 0, 32 );
	}

	/**
	 * Display form: groups of four.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function format( $code ) {
		return implode( '-', str_split( self::normalize( $code ), 4 ) );
	}

	/**
	 * A new unique code.
	 *
	 * @return string XXXX-XXXX-XXXX-XXXX.
	 */
	public static function generate_code() {
		$max = strlen( self::ALPHABET ) - 1;
		do {
			$raw = '';
			for ( $i = 0; $i < 16; $i++ ) {
				$raw .= self::ALPHABET[ random_int( 0, $max ) ];
			}
		} while ( self::get_by_code( $raw, true ) );
		return self::format( $raw );
	}

	/**
	 * Get by post ID.
	 *
	 * @param int $id Post ID.
	 * @return Gift_Card|null
	 */
	public static function get( $id ) {
		$post = get_post( absint( $id ) );
		return ( $post && Post_Types::GIFTCARD === $post->post_type ) ? new static( $post ) : null;
	}

	/**
	 * Find a card by code.
	 *
	 * @param string $code     Code as typed.
	 * @param bool   $any      Include unpublished cards.
	 * @return Gift_Card|null
	 */
	public static function get_by_code( $code, $any = false ) {
		$code = self::normalize( $code );
		if ( strlen( $code ) < 8 ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::GIFTCARD,
				'post_status'    => $any ? 'any' : 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact-match lookup.
					array(
						'key'   => '_sidccofo_gc_code',
						'value' => $code,
					),
				),
			)
		);
		return $ids ? self::get( $ids[0] ) : null;
	}

	/**
	 * Issue a new card.
	 *
	 * @param array $args amount, recipient_name, recipient_email, sender, message, send_date, expires, order, code, note.
	 * @return Gift_Card|\WP_Error
	 */
	public static function create( array $args ) {
		$amount = round_amount( max( 0, (float) ( $args['amount'] ?? 0 ) ) );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'sidccofo_giftcard_amount', __( 'A gift card needs an amount.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$code = ! empty( $args['code'] ) ? self::format( $args['code'] ) : self::generate_code();
		$id   = wp_insert_post(
			array(
				'post_type'   => Post_Types::GIFTCARD,
				'post_status' => 'publish',
				'post_title'  => $code,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$expires = (string) ( $args['expires'] ?? '' );
		if ( '' === $expires ) {
			$months  = absint( \SidcraftCommerce\setting( 'giftcard_expiry_months', 0 ) );
			$expires = $months ? wp_date( 'Y-m-d', strtotime( '+' . $months . ' months' ) ) : '';
		}
		$meta = array(
			'_sidccofo_gc_code'            => self::normalize( $code ),
			'_sidccofo_gc_initial'         => (string) $amount,
			'_sidccofo_gc_balance'         => (string) $amount,
			'_sidccofo_gc_expires'         => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ? $expires : '',
			'_sidccofo_gc_recipient_name'  => sanitize_text_field( (string) ( $args['recipient_name'] ?? '' ) ),
			'_sidccofo_gc_recipient_email' => sanitize_email( (string) ( $args['recipient_email'] ?? '' ) ),
			'_sidccofo_gc_sender'          => sanitize_text_field( (string) ( $args['sender'] ?? '' ) ),
			'_sidccofo_gc_message'         => sanitize_textarea_field( (string) ( $args['message'] ?? '' ) ),
			'_sidccofo_gc_send_date'       => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $args['send_date'] ?? '' ) ) ? $args['send_date'] : '',
			'_sidccofo_gc_sent'            => 0,
			'_sidccofo_gc_order'           => absint( $args['order'] ?? 0 ),
			'_sidccofo_gc_log'             => array(),
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		$card = self::get( $id );
		$card->log( $amount, absint( $args['order'] ?? 0 ), (string) ( $args['note'] ?? __( 'Issued.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
		return $card;
	}

	/**
	 * Constructor.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function __construct( \WP_Post $post ) {
		$this->post = $post;
	}

	/**
	 * Meta helper.
	 *
	 * @param string $key Key without the _sidccofo_gc_ prefix.
	 * @return mixed
	 */
	public function meta( $key ) {
		return get_post_meta( $this->post->ID, '_sidccofo_gc_' . $key, true );
	}

	/**
	 * Id.
	 *
	 * @return int
	 */
	public function get_id() {
		return (int) $this->post->ID;
	}

	/**
	 * Code (XXXX-XXXX-…).
	 *
	 * @return string
	 */
	public function get_code() {
		return self::format( (string) $this->meta( 'code' ) );
	}

	/**
	 * Code with only the last four shown, for receipts: ••••-1234.
	 *
	 * @return string
	 */
	public function get_masked_code() {
		return '••••-' . substr( self::normalize( $this->get_code() ), -4 );
	}

	/**
	 * Initial value.
	 *
	 * @return float
	 */
	public function get_initial() {
		return (float) $this->meta( 'initial' );
	}

	/**
	 * Balance.
	 *
	 * @return float
	 */
	public function get_balance() {
		return max( 0.0, (float) $this->meta( 'balance' ) );
	}

	/**
	 * Expiry date (Y-m-d) or ''.
	 *
	 * @return string
	 */
	public function get_expires() {
		return (string) $this->meta( 'expires' );
	}

	/**
	 * Expired (after the end of the expiry day, site time)?
	 *
	 * @return bool
	 */
	public function is_expired() {
		$date = $this->get_expires();
		return '' !== $date && wp_date( 'Y-m-d' ) > $date;
	}

	/**
	 * Published (not disabled by the store)?
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'publish' === get_post_status( $this->post );
	}

	/**
	 * Can pay now? true or a WP_Error explaining why not.
	 *
	 * @return true|\WP_Error
	 */
	public function validate() {
		if ( ! $this->is_active() ) {
			$error = new \WP_Error( 'sidccofo_giftcard_invalid', __( 'This gift card is not valid.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} elseif ( $this->is_expired() ) {
			$error = new \WP_Error( 'sidccofo_giftcard_expired', __( 'This gift card has expired.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} elseif ( $this->get_balance() <= 0 ) {
			$error = new \WP_Error( 'sidccofo_giftcard_empty', __( 'This gift card has no balance left.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} else {
			$error = true;
		}
		/**
		 * Filter gift card validation.
		 *
		 * @param true|\WP_Error $error Result.
		 * @param Gift_Card      $card  Card.
		 */
		return apply_filters( 'sidcraft_commerce_validate_gift_card', $error, $this );
	}

	/**
	 * Take an amount off the balance (never below zero).
	 *
	 * @param float  $amount Amount.
	 * @param int    $order  Order ID.
	 * @param string $note   Note.
	 * @return float Amount actually taken.
	 */
	public function debit( $amount, $order = 0, $note = '' ) {
		$take = round_amount( min( $this->get_balance(), max( 0, (float) $amount ) ) );
		if ( $take > 0 ) {
			update_post_meta( $this->get_id(), '_sidccofo_gc_balance', (string) round_amount( $this->get_balance() - $take ) );
			$this->log( -$take, $order, $note );
		}
		return $take;
	}

	/**
	 * Put an amount back (cancelled order, refund, admin adjustment).
	 *
	 * @param float  $amount Amount.
	 * @param int    $order  Order ID.
	 * @param string $note   Note.
	 */
	public function credit( $amount, $order = 0, $note = '' ) {
		$amount = round_amount( max( 0, (float) $amount ) );
		if ( $amount > 0 ) {
			update_post_meta( $this->get_id(), '_sidccofo_gc_balance', (string) round_amount( $this->get_balance() + $amount ) );
			$this->log( $amount, $order, $note );
		}
	}

	/**
	 * Set the balance (admin), logging the difference.
	 *
	 * @param float  $balance New balance.
	 * @param string $note    Note.
	 */
	public function set_balance( $balance, $note = '' ) {
		$balance = round_amount( max( 0, (float) $balance ) );
		$diff    = round_amount( $balance - $this->get_balance() );
		if ( 0.0 !== (float) $diff ) {
			update_post_meta( $this->get_id(), '_sidccofo_gc_balance', (string) $balance );
			$this->log( $diff, 0, $note );
		}
	}

	/**
	 * Append to the history.
	 *
	 * @param float  $amount Amount (+ credit, − debit).
	 * @param int    $order  Order ID.
	 * @param string $note   Note.
	 */
	public function log( $amount, $order, $note ) {
		$log   = $this->meta( 'log' );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'   => time(),
			'amount' => round_amount( $amount ),
			'order'  => absint( $order ),
			'note'   => wp_strip_all_tags( (string) $note ),
		);
		update_post_meta( $this->get_id(), '_sidccofo_gc_log', $log );
	}

	/**
	 * History.
	 *
	 * @return array[]
	 */
	public function get_log() {
		$log = $this->meta( 'log' );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Should the recipient email go out now?
	 *
	 * @return bool
	 */
	public function is_due() {
		$date = (string) $this->meta( 'send_date' );
		return ! (int) $this->meta( 'sent' ) && is_email( (string) $this->meta( 'recipient_email' ) ) && ( '' === $date || $date <= wp_date( 'Y-m-d' ) );
	}
}
