<?php
/**
 * Store tools: the optional features added in 2.1.0 (offline payments,
 * pickup and rate tables, EU VAT, invoices, CSV and reports, stock alerts,
 * cart drawer, search and filters, wishlist, bundles, bookings, memberships,
 * licence keys, checkout fields, deposits, donations, delivery slots,
 * tracking and feeds, REST API and webhooks, social proof, questions and
 * photo reviews…).
 *
 * Each tool is a small class under includes/extras/. Store → Settings →
 * Store tools switches each one on or off; one that is off loads nothing and
 * adds no menu, hook or script.
 *
 * Shared pieces live here: the on/off switches, a tiny cart-extras endpoint
 * the front-end scripts use to store a choice in the cart session (delivery
 * method, delivery date, deposit, donation…), and file-download helpers.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Fragments;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Extras
 */
class Extras {

	/**
	 * Tool id => [ class, on by default ] (no translated text: safe to call early).
	 *
	 * @return array
	 */
	public static function defs() {
		return array(
			'offline' => array( 'Offline_Payments', 1 ),
			'shipping' => array( 'Shipping_Methods', 1 ),
			'vat' => array( 'Vat', 1 ),
			'invoices' => array( 'Invoices', 1 ),
			'csv' => array( 'Csv', 1 ),
			'reports' => array( 'Reports', 1 ),
			'stock' => array( 'Stock_Alerts', 1 ),
			'drawer' => array( 'Cart_Drawer', 1 ),
			'search' => array( 'Search', 1 ),
			'wishlist' => array( 'Wishlist', 1 ),
			'bundles' => array( 'Bundles', 1 ),
			'bookings' => array( 'Bookings', 0 ),
			'memberships' => array( 'Memberships', 0 ),
			'licenses' => array( 'Licenses', 0 ),
			'fields' => array( 'Checkout_Fields', 1 ),
			'deposits' => array( 'Deposits', 0 ),
			'donations' => array( 'Donations', 1 ),
			'slots' => array( 'Delivery_Slots', 0 ),
			'tracking' => array( 'Tracking', 1 ),
			'api' => array( 'Api', 1 ),
			'social' => array( 'Social_Proof', 0 ),
			'questions' => array( 'Questions', 1 ),
			'customiser' => array( 'Customiser', 0 ),
			'dropship' => array( 'Dropship', 0 ),
			'vendors' => array( 'Vendors', 0 ),
			'pos' => array( 'Pos', 0 ),
			'subs_plus' => array( 'Subscriptions_Plus', 1 ),
		);
	}

	/**
	 * Tool id => [ label, help ] (translated; call after init).
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'offline' => array( __( 'Bank transfer, cheque and cash on delivery', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Payment methods that take no card: the order waits on hold (or processing for cash on delivery) until you mark it paid.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'shipping' => array( __( 'Local pickup and rate tables', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Local pickup, rates by order total or by weight, and an extra handling fee, on top of the shipping zones.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'vat' => array( __( 'EU VAT numbers and tax-exempt customers', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Checks VAT numbers (format, and the EU VIES service when your server can reach it), removes tax for valid business buyers in other EU countries, exempts customer groups, and reports tax collected by country.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'invoices' => array( __( 'PDF invoices and packing slips', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Numbered PDF invoices and packing slips, attached to order emails and downloadable by you and your customers.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'csv' => array( __( 'CSV import and export', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Import and export products (and their stock) as CSV; export orders and customers.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'reports' => array( __( 'Sales reports', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Sales over time, best-sellers, coupons, refunds, revenue by country and customer value, under Store → Reports.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'stock' => array( __( 'Stock alerts, backorders and pre-orders', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Low-stock emails to you, “Notify me when back in stock”, backorders, pre-orders and minimum, maximum and step quantities.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'drawer' => array( __( 'Cart drawer and sticky add-to-cart', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'A slide-out cart that opens after adding a product, and a bar that keeps the Add to cart button in view on product pages.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'search' => array( __( 'Live search and product filters', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'A search box that shows products as you type, and filters by price, category, tag, rating and stock for product grids.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'wishlist' => array( __( 'Wishlist, compare and recently viewed', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Heart buttons and a wishlist page, side-by-side product comparison, and a “recently viewed” list.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'bundles' => array( __( 'Bundles, frequently bought together and order bumps', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Fixed bundles at a bundle price, “bought together” offers on product pages, and a one-tick extra at checkout.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'bookings' => array( __( 'Bookings and appointments', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Services and rentals sold by the time slot or by the night, with capacity, opening hours and calendar files.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'memberships' => array( __( 'Memberships and content restriction', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Plans that unlock pages and posts for a period or for as long as a subscription runs, with drip release.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'licenses' => array( __( 'Software licence keys', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Issue a licence key per purchase, with activation limits and a small API your software can call to check it.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'fields' => array( __( 'Checkout field editor', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Add, rename, reorder and hide checkout fields, with conditions, from Settings.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'deposits' => array( __( 'Deposits and partial payments', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Take part of the price now and the balance later, by product or for everything.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'donations' => array( __( 'Pay what you want, donations and quote requests', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Products where the buyer picks the price, a donation box at checkout, and “Request a quote” instead of a price.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'slots' => array( __( 'Delivery date and time slots', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Let customers pick a delivery or pickup date and time, with cut-off times, blocked days and a capacity per slot.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'tracking' => array( __( 'Analytics events, product feeds and schema', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'GA4, Meta and TikTok purchase and cart events, Google Merchant and Facebook product feeds, and product structured data.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'api' => array( __( 'REST API and webhooks', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Read-only and write REST routes for products, orders and customers with API keys, plus outgoing webhooks for Zapier, Make and n8n.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'social' => array( __( 'Social-proof popups', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( '“Maria from Texas just bought…” popups built from real recent orders, with names shortened for privacy.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'questions' => array( __( 'Product questions and photo reviews', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Questions and answers on product pages, and photos with reviews.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'customiser' => array( __( 'Product personalisation', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Text, image upload and size charts on a product, with a live preview of the text, for engraving, printing and gifts.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'dropship' => array( __( 'Suppliers and dropshipping', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Assign products to suppliers, email each supplier their lines of an order, and record their tracking numbers.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'vendors' => array( __( 'Multi-vendor marketplace', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Vendors list their own products, see their own orders, and are paid a commission you set.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'pos' => array( __( 'Point of sale', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'A fast till screen in the admin for in-person sales, with barcode or SKU scanning and cash or card-on-terminal payments.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'subs_plus' => array( __( 'Subscription extras', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				__( 'Customers can pause and switch plans, failed renewals are retried with reminder emails, and renewals can be prorated.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
		);
	}

	/**
	 * Is a tool on?
	 *
	 * @param string $id Tool id.
	 * @return bool
	 */
	public static function on( $id ) {
		$tools = self::defs();
		if ( ! isset( $tools[ $id ] ) ) {
			return false;
		}
		return (bool) apply_filters( 'sidcraft_commerce_tool_enabled', (bool) setting( 'tool_' . $id, $tools[ $id ][1] ), $id );
	}

	/**
	 * Load and hook every tool that is on.
	 */
	public function boot() {
		// Tools build translated settings text, so they load on init (WordPress 6.7+ warns about translations earlier).
		add_action( 'init', array( $this, 'load_tools' ), 1 );
		add_filter( 'sidcraft_commerce_settings_tabs', array( $this, 'tab' ) );
		add_filter( 'sidcraft_commerce_settings_sections', array( $this, 'section' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 6 );
	}

	/**
	 * Register the shared front-end script and style (printed only when a tool asks).
	 */
	public static function register_assets() {
		if ( wp_script_is( 'sidccofo-extras', 'registered' ) ) {
			return;
		}
		$url = SIDCRAFT_COMMERCE_URL . 'assets/';
		wp_register_style( 'sidccofo-extras', $url . 'css/sc-extras.css', array( 'sidccofo-commerce' ), SIDCRAFT_COMMERCE_VERSION );
		wp_register_script(
			'sidccofo-extras',
			$url . 'js/sc-extras.js',
			array( 'sidccofo-cart' ),
			SIDCRAFT_COMMERCE_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Ask for the shared script and style (and the cart script they build on).
	 */
	public static function enqueue() {
		self::register_assets();
		\SidcraftCommerce\Plugin::instance()->assets->require_assets(
			array(
				'styles'  => array( 'sidccofo-commerce', 'sidccofo-extras' ),
				'scripts' => array( 'sidccofo-cart', 'sidccofo-extras' ),
			)
		);
	}

	/**
	 * Load and hook every tool that is on.
	 */
	public function load_tools() {
		require_once __DIR__ . '/class-tool.php';
		require_once __DIR__ . '/class-simple-unit.php';
		foreach ( self::defs() as $id => $tool ) {
			if ( ! self::on( $id ) ) {
				continue;
			}
			$file = __DIR__ . '/class-' . strtolower( str_replace( '_', '-', $tool[0] ) ) . '.php';
			if ( ! is_readable( $file ) ) {
				continue;
			}
			require_once $file;
			$class = __NAMESPACE__ . '\\' . $tool[0];
			if ( class_exists( $class ) ) {
				( new $class() )->hooks();
			}
		}
	}

	/**
	 * Settings tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function tab( $tabs ) {
		$tabs['tools'] = __( 'Store tools', 'sidcraft-commerce-for-sidcraft-page-builder' );
		return $tabs;
	}

	/**
	 * The on/off switches.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public function section( $sections ) {
		$fields = array();
		foreach ( self::labels() as $id => $tool ) {
			$fields[ 'tool_' . $id ] = array( $tool[0], 'checkbox', $tool[1] );
		}
		$sections['tools'] = array(
			'title'  => __( 'Store tools', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'intro'  => __( 'Switch on what your store needs. A tool that is off loads nothing and adds no menu, hook or script. Each tool you switch on gets its own settings section on this tab after you save.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'tab'    => 'tools',
			'fields' => $fields,
		);
		return $sections;
	}

	/**
	 * POST /extras/cart: store one whitelisted choice in the cart session
	 * (a delivery method, a date, a deposit, a donation…) and return the
	 * fresh totals. Tools whitelist their keys with
	 * add_filter( 'sidcraft_commerce_extra_keys', … ): key => sanitize callback.
	 */
	public function routes() {
		register_rest_route(
			'sidcraft-commerce/v1',
			'/extras/cart',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'set_cart_extra' ),
			)
		);
	}

	/**
	 * Handle POST /extras/cart.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_cart_extra( \WP_REST_Request $request ) {
		$keys = (array) apply_filters( 'sidcraft_commerce_extra_keys', array() );
		$key  = sanitize_key( (string) $request['key'] );
		if ( ! isset( $keys[ $key ] ) || ! is_callable( $keys[ $key ] ) ) {
			return new \WP_Error( 'sidccofo_bad_extra', __( 'Unknown option.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		$cart  = Cart::instance();
		$value = call_user_func( $keys[ $key ], $request['value'], $cart );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		$cart->set_extra( $key, $value );
		$context = in_array( (string) $request['context'], array( 'cart', 'checkout' ), true ) ? (string) $request['context'] : '';
		return rest_ensure_response(
			array(
				'fragments' => Fragments::collect( $context ),
				'cart_hash' => $cart->get_hash(),
				'count'     => $cart->get_count(),
				'total'     => $cart->get_total(),
			)
		);
	}

	/**
	 * Send a file download and stop.
	 *
	 * @param string $filename Name.
	 * @param string $mime     Type.
	 * @param string $body     Contents.
	 */
	public static function send_file( $filename, $mime, $body ) {
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A file, not HTML.
		exit;
	}

	/**
	 * Make a CSV string (Excel-safe: cells starting = + - @ are prefixed).
	 *
	 * @param array[] $rows Rows (first row = header).
	 * @return string
	 */
	public static function csv( array $rows ) {
		$h = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $h, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		foreach ( $rows as $row ) {
			$row = array_map(
				static function ( $v ) {
					$v = (string) $v;
					return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) && ! is_numeric( $v ) ) ? "'" . $v : $v;
				},
				$row
			);
			fputcsv( $h, $row ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}
		rewind( $h );
		$out = stream_get_contents( $h );
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return (string) $out;
	}
}
