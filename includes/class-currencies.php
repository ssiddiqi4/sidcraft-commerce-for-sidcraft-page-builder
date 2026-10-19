<?php
/**
 * Selling in several currencies.
 *
 * The store currency (Store → Settings → Currency code) is the base: every
 * amount the owner types (prices, option extras, shipping rates, coupon
 * amounts, gift card balances, the reward point value) is in it. Other
 * currencies are rows of Store → Settings → Currencies: code, rate (units of
 * that currency per 1 of the store currency), symbol, symbol position,
 * decimals and countries.
 *
 * A shopper's currency is the one they chose (?sidccofo_currency=EUR, the
 * Currency Switcher Unit, stored in a cookie), else the one listed for their
 * country when "Pick from the customer's country" is on, else the store
 * currency. Their cart, checkout and order are worked out and charged in it:
 * base amounts convert where they are read (Product::get_price(), options,
 * shipping, coupons, gift cards, points), and each order keeps its currency
 * and the rate used (_sidccofo_currency, _sidccofo_exchange_rate), so the
 * store-currency value of any order amount is amount / rate.
 *
 * Admin screens, cron and WP-CLI always work in the store currency.
 * switch_to() / restore() show one order in its own currency (emails, the
 * order screen, the order-received page).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Class Currencies
 */
class Currencies {

	const COOKIE = 'sidccofo_currency';
	const PARAM  = 'sidccofo_currency';

	/**
	 * Currencies pushed by switch_to() (last wins).
	 *
	 * @var string[]
	 */
	protected static $stack = array();

	/**
	 * The shopper's currency, worked out once per request.
	 *
	 * @var string|null
	 */
	protected static $shopper = null;

	/**
	 * Parsed extra currencies, keyed by a fingerprint of the setting.
	 *
	 * @var array
	 */
	protected static $extra = array();

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'handle_switch' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_no_cache' ), 7 );
		add_filter( 'sidcraft_commerce_settings_sections', array( $this, 'settings' ) );
	}

	/*
	------------------------------------------------------------------
	 * Which currencies
	 * ----------------------------------------------------------------
	 */

	/**
	 * The store currency.
	 *
	 * @return string
	 */
	public static function base() {
		return strtoupper( (string) setting( 'currency', 'USD' ) );
	}

	/**
	 * Extra currencies: code => [ code, rate, symbol, position, decimals, countries[] ].
	 *
	 * @return array[]
	 */
	public static function extra() {
		$raw = setting( 'currencies', array() );
		$key = self::base() . md5( wp_json_encode( $raw ) );
		if ( isset( self::$extra[ $key ] ) ) {
			return self::$extra[ $key ];
		}
		$out  = array();
		$base = self::base();
		foreach ( (array) $raw as $row ) {
			$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $row['code'] ?? '' ) ) );
			$rate = (float) ( $row['rate'] ?? 0 );
			if ( 3 !== strlen( $code ) || $code === $base || $rate <= 0 ) {
				continue;
			}
			$countries = array();
			foreach ( explode( ',', (string) ( $row['countries'] ?? '' ) ) as $c ) {
				$c = strtoupper( trim( $c ) );
				if ( '' !== $c ) {
					$countries[] = $c;
				}
			}
			$out[ $code ] = array(
				'code'      => $code,
				'rate'      => $rate,
				'symbol'    => (string) ( $row['symbol'] ?? '' ),
				'position'  => 'after' === ( $row['position'] ?? '' ) ? 'right' : ( 'before' === ( $row['position'] ?? '' ) ? 'left' : '' ),
				'decimals'  => '' === (string) ( $row['decimals'] ?? '' ) ? null : max( 0, min( 4, (int) $row['decimals'] ) ),
				'countries' => $countries,
			);
		}
		/**
		 * Filter the extra currencies (e.g. to load exchange rates from a service).
		 *
		 * @param array[] $out code => row.
		 */
		self::$extra = array( $key => (array) apply_filters( 'sidcraft_commerce_currencies', $out ) );
		return self::$extra[ $key ];
	}

	/**
	 * Every currency a shopper can pick, the store currency first.
	 *
	 * @return string[]
	 */
	public static function codes() {
		return array_merge( array( self::base() ), array_keys( self::extra() ) );
	}

	/**
	 * Does the store sell in more than one currency?
	 *
	 * @return bool
	 */
	public static function is_multi() {
		return count( self::codes() ) > 1;
	}

	/**
	 * The currency amounts are in right now.
	 *
	 * @return string
	 */
	public static function active() {
		if ( self::$stack ) {
			return end( self::$stack );
		}
		if ( null === self::$shopper ) {
			self::$shopper = self::resolve();
		}
		return self::$shopper;
	}

	/**
	 * The shopper's currency for this request.
	 *
	 * @return string
	 */
	protected static function resolve() {
		$base = self::base();
		if ( ! did_action( 'init' ) && ! doing_action( 'init' ) ) {
			return $base; // Settings and cookies are not trustworthy this early.
		}
		$extra = self::extra();
		if ( ! $extra || self::store_context() ) {
			return $base;
		}
		$code = isset( $_COOKIE[ cookie_name( self::COOKIE ) ] ) ? strtoupper( sanitize_key( wp_unslash( $_COOKIE[ cookie_name( self::COOKIE ) ] ) ) ) : '';
		if ( '' === $code && setting( 'currency_by_country', 0 ) ) {
			$country = self::visitor_country();
			foreach ( $extra as $row ) {
				if ( '' !== $country && in_array( $country, $row['countries'], true ) ) {
					$code = $row['code'];
					break;
				}
			}
		}
		$code = isset( $extra[ $code ] ) ? $code : $base;
		/**
		 * Filter the shopper's currency (must be one of codes()).
		 *
		 * @param string $code Currency code.
		 */
		$code = strtoupper( (string) apply_filters( 'sidcraft_commerce_shopper_currency', $code ) );
		return in_array( $code, self::codes(), true ) ? $code : $base;
	}

	/**
	 * Requests that always use the store currency: admin screens (not
	 * admin-ajax), cron and WP-CLI.
	 *
	 * @return bool
	 */
	protected static function store_context() {
		return ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Visitor's country: the cart's chosen country, else a CDN country header.
	 *
	 * @return string ISO code or ''.
	 */
	public static function visitor_country() {
		$country = '';
		if ( class_exists( Cart\Cart::class ) ) {
			$session  = Cart\Cart::instance()->session();
			$location = $session->has_session() ? (array) $session->get( 'location', array() ) : array();
			$country  = (string) ( $location['country'] ?? '' );
		}
		if ( '' === $country ) {
			foreach ( array( 'HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY' ) as $header ) {
				if ( ! empty( $_SERVER[ $header ] ) ) {
					$country = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
					break;
				}
			}
		}
		/**
		 * Filter the visitor's country (two-letter ISO code), e.g. from a geolocation service.
		 *
		 * @param string $country Country.
		 */
		$country = strtoupper( (string) apply_filters( 'sidcraft_commerce_visitor_country', $country ) );
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	/**
	 * Work in another currency until restore() (e.g. to show an order).
	 *
	 * @param string $code Currency code ('' = the store currency).
	 */
	public static function switch_to( $code ) {
		$code          = strtoupper( (string) $code );
		self::$stack[] = '' !== $code ? $code : self::base();
	}

	/**
	 * Undo the last switch_to().
	 */
	public static function restore() {
		array_pop( self::$stack );
	}

	/**
	 * Run a function in a currency.
	 *
	 * @param string   $code Currency code.
	 * @param callable $fn   Function.
	 * @return mixed What it returns.
	 */
	public static function using( $code, callable $fn ) {
		self::switch_to( $code );
		try {
			return $fn();
		} finally {
			self::restore();
		}
	}

	/**
	 * Run a function in the store currency (amounts typed by the owner).
	 *
	 * @param callable $fn Function.
	 * @return mixed
	 */
	public static function in_base( callable $fn ) {
		return self::using( self::base(), $fn );
	}

	/**
	 * Forget the shopper's currency (tests, and after a switch).
	 */
	public static function reset() {
		self::$shopper = null;
		self::$stack   = array();
		self::$extra   = array();
	}

	/*
	------------------------------------------------------------------
	 * Converting
	 * ----------------------------------------------------------------
	 */

	/**
	 * Units of a currency per 1 of the store currency.
	 *
	 * @param string $code Currency ('' = active).
	 * @return float
	 */
	public static function rate( $code = '' ) {
		$code = '' !== $code ? strtoupper( $code ) : self::active();
		if ( $code === self::base() ) {
			return 1.0;
		}
		$extra = self::extra();
		return isset( $extra[ $code ] ) ? (float) $extra[ $code ]['rate'] : 1.0;
	}

	/**
	 * A store-currency amount in a currency, rounded to its decimals.
	 *
	 * @param float  $amount Store-currency amount.
	 * @param string $code   Currency ('' = active).
	 * @return float
	 */
	public static function to_active( $amount, $code = '' ) {
		$code = '' !== $code ? strtoupper( $code ) : self::active();
		if ( $code === self::base() ) {
			return (float) $amount;
		}
		return round( (float) $amount * self::rate( $code ), decimals_for( $code ) );
	}

	/**
	 * An amount in a currency back in the store currency (not rounded, so
	 * converting it again gives the same amount).
	 *
	 * @param float  $amount Amount.
	 * @param string $code   Its currency ('' = active).
	 * @return float
	 */
	public static function to_base( $amount, $code = '' ) {
		$code = '' !== $code ? strtoupper( $code ) : self::active();
		if ( $code === self::base() ) {
			return (float) $amount;
		}
		return round( (float) $amount / self::rate( $code ), 6 );
	}

	/**
	 * Display details of a currency: symbol, position (left|right), decimals.
	 *
	 * @param string $code Currency.
	 * @return array
	 */
	public static function format_of( $code ) {
		$code  = strtoupper( (string) $code );
		$extra = self::extra();
		$row   = $extra[ $code ] ?? null;
		return array(
			'symbol'   => $row && '' !== $row['symbol'] ? $row['symbol'] : '',
			'position' => $row && '' !== $row['position'] ? $row['position'] : '',
			'decimals' => $row ? $row['decimals'] : null,
		);
	}

	/*
	------------------------------------------------------------------
	 * Switching
	 * ----------------------------------------------------------------
	 */

	/**
	 * ?sidccofo_currency=EUR: remember the choice and reload without the parameter.
	 */
	public function handle_switch() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A display preference, like a language switcher.
		if ( ! isset( $_GET[ self::PARAM ] ) || is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = strtoupper( sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) );
		if ( ! in_array( $code, self::codes(), true ) ) {
			return;
		}
		self::remember( $code );
		if ( ! headers_sent() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			wp_safe_redirect( remove_query_arg( self::PARAM ) );
			exit;
		}
	}

	/**
	 * Store the shopper's choice.
	 *
	 * @param string $code Currency.
	 */
	public static function remember( $code ) {
		$code = strtoupper( (string) $code );
		if ( ! headers_sent() ) {
			setcookie(
				cookie_name( self::COOKIE ),
				$code,
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ cookie_name( self::COOKIE ) ] = $code;
		self::$shopper           = null;
		/**
		 * Fires when a shopper picks a currency.
		 *
		 * @param string $code Currency.
		 */
		do_action( 'sidcraft_commerce_currency_switched', $code );
	}

	/**
	 * Pages shown in another currency (or picked by country) must not be
	 * served from a page cache to shoppers using a different one.
	 */
	public function maybe_no_cache() {
		if ( ! self::is_multi() ) {
			return;
		}
		$chosen = ! empty( $_COOKIE[ cookie_name( self::COOKIE ) ] ) && self::base() !== self::active();
		if ( $chosen || setting( 'currency_by_country', 0 ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache flag.
			}
			nocache_headers();
		}
	}

	/**
	 * Link that switches to a currency.
	 *
	 * @param string $code Currency.
	 * @return string
	 */
	public static function switch_url( $code ) {
		return add_query_arg( self::PARAM, strtoupper( $code ) );
	}

	/*
	------------------------------------------------------------------
	 * Settings
	 * ----------------------------------------------------------------
	 */

	/**
	 * Store → Settings → Currencies section.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public function settings( $sections ) {
		$sections['currencies'] = array(
			'tab'    => 'general',
			'title'  => __( 'Currencies', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			/* translators: %s: store currency code. */
			'intro'  => sprintf( __( 'Sell in more currencies besides %s. Prices, shipping rates, coupon amounts and gift card balances stay in %s; shoppers see and pay them converted at your rate, and each order is charged and kept in the shopper’s currency. Add the Currency Switcher Unit (or [sidccofo_currency_switcher]) so shoppers can choose.', 'sidcraft-commerce-for-sidcraft-page-builder' ), self::base(), self::base() ),
			'fields' => array(
				'currencies'          => array(
					__( 'More currencies', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'table',
					/* translators: %s: store currency code. */
					sprintf( __( 'Rate: how much 1 %s is in that currency, e.g. 0.92. Symbol, position (before or after) and decimals: leave empty for the standard ones. Countries: ISO codes whose shoppers get this currency first when picking by country.', 'sidcraft-commerce-for-sidcraft-page-builder' ), self::base() ),
					array(
						'code'      => array( __( 'Code', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'currency', 'EUR' ),
						'rate'      => array( __( 'Rate', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'rate', '0.92' ),
						'symbol'    => array( __( 'Symbol', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', '€' ),
						'position'  => array( __( 'Position', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'position', 'after' ),
						'decimals'  => array( __( 'Decimals', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'decimals', '2' ),
						'countries' => array( __( 'Countries', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'countries', 'DE, FR, IE' ),
					),
				),
				'currency_by_country' => array( __( 'Pick from the customer’s country', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Shoppers who haven’t chosen get the currency listed for their country: the one they pick at checkout, or the country your CDN reports (Cloudflare and similar). Pages are then not page-cached.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			),
		);
		return $sections;
	}
}
