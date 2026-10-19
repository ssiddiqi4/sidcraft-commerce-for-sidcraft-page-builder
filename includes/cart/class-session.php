<?php
/**
 * Visitor session for the cart, stored in {prefix}sidccofo_sessions.
 *
 * Cookie strategy (keeps pages cacheable):
 *   - Nothing is set until a visitor first adds to cart.
 *   - sidccofo_session       httpOnly, random 32-char key → DB row.
 *   - sidccofo_items_in_cart readable by JS, "1" while the cart has items.
 *   - sidccofo_cart_hash     readable by JS, changes whenever the cart changes.
 * Page caches should bypass the cache when sidccofo_session is present, or simply
 * cache everything except Cart/Checkout (we send DONOTCACHEPAGE there).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Class Session
 */
class Session {

	const COOKIE = 'sidccofo_session';

	/**
	 * Session key or '' when the visitor has none yet.
	 *
	 * @var string
	 */
	protected $key = '';

	/**
	 * Session data.
	 *
	 * @var array
	 */
	protected $data = array();

	/**
	 * Unsaved changes.
	 *
	 * @var bool
	 */
	protected $dirty = false;

	/**
	 * Load from the cookie, if any.
	 */
	public function __construct() {
		$raw = isset( $_COOKIE[ \SidcraftCommerce\cookie_name( self::COOKIE ) ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ \SidcraftCommerce\cookie_name( self::COOKIE ) ] ) ) : '';
		if ( preg_match( '/^[A-Za-z0-9]{32}$/', $raw ) ) {
			$this->key  = $raw;
			$this->data = $this->read( $raw );
		}
		add_action( 'shutdown', array( $this, 'save' ), 20 );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	protected function table() {
		global $wpdb;
		return $wpdb->prefix . 'sidccofo_sessions';
	}

	/**
	 * Lifetime in seconds (default 7 days).
	 *
	 * @return int
	 */
	protected function lifetime() {
		return (int) apply_filters( 'sidcraft_commerce_session_lifetime', 7 * DAY_IN_SECONDS );
	}

	/**
	 * Read a row.
	 *
	 * @param string $key Session key.
	 * @return array
	 */
	protected function read( $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; per-visitor data must not be object-cached across users.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT session_value, session_expiry FROM %i WHERE session_key = %s', $this->table(), $key ) );
		if ( ! $row || (int) $row->session_expiry < time() ) {
			return array();
		}
		$data = json_decode( $row->session_value, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Get a value.
	 *
	 * @param string $name    Key.
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public function get( $name, $fallback = null ) {
		return $this->data[ $name ] ?? $fallback;
	}

	/**
	 * Set a value; starts a session (and sets the cookie) if needed.
	 *
	 * @param string $name  Key.
	 * @param mixed  $value Value.
	 */
	public function set( $name, $value ) {
		if ( '' === $this->key ) {
			$this->key = wp_generate_password( 32, false );
			$this->set_cookie( \SidcraftCommerce\cookie_name( self::COOKIE ), $this->key, true );
		}
		$this->data[ $name ] = $value;
		$this->dirty         = true;
	}

	/**
	 * Whether this visitor has a session.
	 *
	 * @return bool
	 */
	public function has_session() {
		return '' !== $this->key;
	}

	/**
	 * Persist changes. Runs on shutdown and can be called early.
	 */
	public function save() {
		global $wpdb;
		if ( ! $this->dirty || '' === $this->key ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, name from $wpdb->prefix.
		$wpdb->replace(
			$this->table(),
			array(
				'session_key'    => $this->key,
				'session_value'  => wp_json_encode( $this->data ),
				'session_expiry' => time() + $this->lifetime(),
			),
			array( '%s', '%s', '%d' )
		);
		$this->dirty = false;
	}

	/**
	 * Set a cookie with safe defaults.
	 *
	 * @param string $name      Name.
	 * @param string $value     Value ('' deletes).
	 * @param bool   $http_only Hide from JS.
	 */
	public function set_cookie( $name, $value, $http_only = false ) {
		if ( headers_sent() ) {
			return;
		}
		$expires = '' === $value ? time() - YEAR_IN_SECONDS : time() + $this->lifetime();
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => $http_only,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ $name ] = $value;
	}

	/**
	 * Delete expired rows (hourly cron).
	 */
	public static function cleanup() {
		global $wpdb;
		$table = $wpdb->prefix . 'sidccofo_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE session_expiry < %d', $table, time() ) );
	}
}
