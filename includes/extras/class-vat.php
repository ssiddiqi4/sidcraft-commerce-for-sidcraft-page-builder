<?php
/**
 * EU VAT numbers, reverse charge, tax-exempt customers and a tax report.
 *
 * - A "VAT number" field at checkout (EU buyers, or everyone). The number is
 *   checked for its format and, when the server can reach it, with the EU
 *   VIES service (the answer is cached for a day).
 * - A valid number from an EU country other than the store's own removes tax
 *   from the order ("reverse charge"), and the order says so.
 * - Customers with a role listed in Settings, or the "Tax exempt" box on their
 *   profile, pay no tax.
 * - Store → Tax report lists net sales, tax and orders by country and rate for
 *   any dates, and exports them as CSV (for VAT returns and EU OSS).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Locations;
use function SidcraftCommerce\format_price;
use function SidcraftCommerce\round_amount;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Vat
 */
class Vat extends Tool {

	/**
	 * EU member states (ISO code => VAT prefix).
	 *
	 * @return array
	 */
	public static function eu() {
		$codes = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE' );
		$out   = array();
		foreach ( $codes as $c ) {
			$out[ $c ] = 'GR' === $c ? 'EL' : $c;
		}
		return $out;
	}

	/** {@inheritDoc} */
	public function hooks() {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		$this->settings(
			'vat',
			__( 'EU VAT and tax exemptions', $d ),
			array(
				'vat_field'       => array(
					__( 'VAT number field at checkout', $d ),
					array(
						'eu'     => __( 'Show for EU countries', $d ),
						'always' => __( 'Show for everyone', $d ),
						'off'    => __( 'Don’t show', $d ),
					),
				),
				'vat_reverse'     => array( __( 'Reverse charge', $d ), 'checkbox', __( 'A valid EU VAT number from another EU country than the store’s removes tax (the store country is “Default country” under General).', $d ) ),
				'vat_vies'        => array( __( 'Check numbers with VIES', $d ), 'checkbox', __( 'Asks the EU’s VIES service whether the number exists. If your server can’t reach it, numbers with a correct format are accepted.', $d ) ),
				'vat_exempt_roles' => array( __( 'Tax-exempt roles', $d ), 'text', __( 'User roles that never pay tax, separated by commas (for example wholesale). You can also tick “Tax exempt” on a user’s profile.', $d ) ),
			)
		);

		add_filter( 'sidcraft_commerce_checkout_fields', array( $this, 'field' ) );
		add_filter( 'sidcraft_commerce_extra_keys', array( $this, 'keys' ) );
		add_action( 'sidcraft_commerce_checkout_input', array( $this, 'input' ), 10, 2 );
		add_filter( 'sidcraft_commerce_checkout_errors', array( $this, 'errors' ), 10, 3 );
		add_filter( 'sidcraft_commerce_tax', array( $this, 'tax' ), 10, 4 );
		add_filter( 'sidcraft_commerce_cart_fees', array( $this, 'fees' ), 10, 2 );
		add_action( 'sidcraft_commerce_order_created', array( $this, 'stamp' ) );
		add_action( 'show_user_profile', array( $this, 'profile' ) );
		add_action( 'edit_user_profile', array( $this, 'profile' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
		$this->admin_page( 'sidccofo-tax-report', __( 'Tax report', $d ), array( $this, 'report_page' ) );
		add_action( 'admin_post_sidccofo_tax_csv', array( $this, 'report_csv' ) );
	}

	/*
	------------------------------------------------------------------
	 * Number checks
	 * ----------------------------------------------------------------
	 */

	/**
	 * Clean a VAT number: letters and digits only, upper case.
	 *
	 * @param string $raw Typed number.
	 * @return string
	 */
	public static function normalize( $raw ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9+*]/', '', (string) $raw ) );
	}

	/**
	 * Does a number have the right shape for its country?
	 *
	 * @param string $number Normalized number with country prefix (DE123456789).
	 * @return string Country code ('' when the format is wrong).
	 */
	public static function format_country( $number ) {
		$patterns = array(
			'AT' => 'U\d{8}', 'BE' => '[01]\d{9}', 'BG' => '\d{9,10}', 'HR' => '\d{11}', 'CY' => '\d{8}[A-Z]',
			'CZ' => '\d{8,10}', 'DK' => '\d{8}', 'EE' => '\d{9}', 'FI' => '\d{8}', 'FR' => '[A-Z0-9]{2}\d{9}',
			'DE' => '\d{9}', 'EL' => '\d{9}', 'HU' => '\d{8}', 'IE' => '(\d{7}[A-Z]{1,2}|\d[A-Z+*]\d{5}[A-Z])',
			'IT' => '\d{11}', 'LV' => '\d{11}', 'LT' => '(\d{9}|\d{12})', 'LU' => '\d{8}', 'MT' => '\d{8}',
			'NL' => '\d{9}B\d{2}', 'PL' => '\d{10}', 'PT' => '\d{9}', 'RO' => '\d{2,10}', 'SK' => '\d{10}',
			'SI' => '\d{8}', 'ES' => '[A-Z0-9]\d{7}[A-Z0-9]', 'SE' => '\d{12}',
		);
		$prefix = substr( $number, 0, 2 );
		if ( ! isset( $patterns[ $prefix ] ) || ! preg_match( '/^' . $patterns[ $prefix ] . '$/', substr( $number, 2 ) ) ) {
			return '';
		}
		$map = array_flip( self::eu() );
		return $map[ $prefix ] ?? '';
	}

	/**
	 * Check a number.
	 *
	 * @param string $raw Typed number.
	 * @return array|\WP_Error [ number, country ] or an error.
	 */
	public static function check( $raw ) {
		$number = self::normalize( $raw );
		$bad    = new \WP_Error( 'sidccofo_vat', __( 'That VAT number doesn’t look right. Start with the two-letter country code, for example DE123456789.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		if ( '' === $number ) {
			return $bad;
		}
		$country = self::format_country( $number );
		if ( '' === $country ) {
			return $bad;
		}
		if ( setting( 'vat_vies', 1 ) ) {
			$answer = self::vies( $number );
			if ( false === $answer ) {
				return new \WP_Error( 'sidccofo_vat', __( 'The EU VIES service says this VAT number isn’t registered.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			}
		}
		return array(
			'number'  => $number,
			'country' => $country,
		);
	}

	/**
	 * Ask VIES. True = valid, false = invalid, null = could not ask.
	 *
	 * @param string $number Number with prefix.
	 * @return bool|null
	 */
	protected static function vies( $number ) {
		$key    = 'sidccofo_vies_' . md5( $number );
		$cached = get_transient( $key );
		if ( 'y' === $cached ) {
			return true;
		}
		if ( 'n' === $cached ) {
			return false;
		}
		$url      = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/' . rawurlencode( substr( $number, 0, 2 ) ) . '/vat/' . rawurlencode( substr( $number, 2 ) );
		$response = wp_remote_get( $url, array( 'timeout' => 6 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! array_key_exists( 'isValid', $body ) ) {
			return null;
		}
		set_transient( $key, $body['isValid'] ? 'y' : 'n', DAY_IN_SECONDS );
		return (bool) $body['isValid'];
	}

	/*
	------------------------------------------------------------------
	 * Checkout
	 * ----------------------------------------------------------------
	 */

	/**
	 * Add the VAT number field.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public function field( $fields ) {
		$mode = (string) setting( 'vat_field', 'eu' );
		if ( 'off' === $mode ) {
			return $fields;
		}
		$country = isset( $fields['country'] ) ? Cart::instance()->get_location()['country'] : '';
		if ( 'eu' === $mode && '' !== $country && ! isset( self::eu()[ $country ] ) && ! is_admin() ) {
			return $fields;
		}
		$fields['vat_number'] = array( __( 'VAT number (EU businesses)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', false, 'off', 'full' );
		return $fields;
	}

	/**
	 * Cart-extras key: a number typed in the field is checked on the spot.
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public function keys( $keys ) {
		$keys['vat_number'] = static function ( $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			$ok = Vat::check( $value );
			return is_wp_error( $ok ) ? $ok : $ok['number'];
		};
		return $keys;
	}

	/**
	 * Keep the number submitted with the checkout.
	 *
	 * @param array $input Input.
	 * @param Cart  $cart  Cart.
	 */
	public function input( $input, $cart ) {
		if ( ! isset( $input['vat_number'] ) ) {
			return;
		}
		$value = trim( (string) $input['vat_number'] );
		$ok    = '' === $value ? '' : self::check( $value );
		$cart->set_extra( 'vat_number', is_array( $ok ) ? $ok['number'] : '' );
		$cart->session()->set( 'vat_bad', is_wp_error( $ok ) ? $ok->get_error_message() : '' );
	}

	/**
	 * Refuse a number that failed its check.
	 *
	 * @param array $errors  Errors.
	 * @param array $billing Fields.
	 * @param array $input   Input.
	 * @return array
	 */
	public function errors( $errors, $billing, $input ) {
		unset( $billing );
		$msg = (string) Cart::instance()->session()->get( 'vat_bad', '' );
		if ( '' !== $msg && ! empty( $input['vat_number'] ) ) {
			$errors['vat_number'] = $msg;
		}
		return $errors;
	}

	/**
	 * Is this cart's buyer not charged tax?
	 *
	 * @param Cart $cart Cart.
	 * @return string '' = taxed, 'reverse' = reverse charge, 'exempt' = exempt customer.
	 */
	public static function treatment( Cart $cart ) {
		$user = wp_get_current_user();
		if ( $user->ID ) {
			if ( get_user_meta( $user->ID, 'sidccofo_tax_exempt', true ) ) {
				return 'exempt';
			}
			$roles = array_filter( array_map( 'trim', explode( ',', strtolower( (string) setting( 'vat_exempt_roles', '' ) ) ) ) );
			if ( $roles && array_intersect( $roles, (array) $user->roles ) ) {
				return 'exempt';
			}
		}
		$number = (string) $cart->extra( 'vat_number', '' );
		if ( '' !== $number && setting( 'vat_reverse', 1 ) ) {
			$buyer = self::format_country( $number );
			$store = strtoupper( (string) setting( 'default_country', 'US' ) );
			$loc   = $cart->get_location();
			if ( '' !== $buyer && $buyer !== $store && isset( self::eu()[ $store ] ) && $loc['country'] === $buyer ) {
				return 'reverse';
			}
		}
		return '';
	}

	/**
	 * Remove tax for exempt buyers (remember what was removed).
	 *
	 * @param array $out      Tax result.
	 * @param array $lines    Lines.
	 * @param float $shipping Shipping.
	 * @param array $location Location.
	 * @return array
	 */
	public function tax( $out, $lines, $shipping, $location ) {
		unset( $lines, $shipping, $location );
		if ( empty( $out['total'] ) || ! self::treatment( Cart::instance() ) ) {
			return $out;
		}
		$out['removed']   = $out['total'];
		$out['items']     = 0.0;
		$out['shipping']  = 0.0;
		$out['total']     = 0.0;
		return $out;
	}

	/**
	 * With prices that include tax, the removed tax comes off the total as a credit.
	 *
	 * @param array $fees Fees.
	 * @param Cart  $cart Cart.
	 * @return array
	 */
	public function fees( $fees, $cart ) {
		$details = $cart->get_tax_details();
		if ( ! empty( $details['removed'] ) && ! empty( $details['inclusive'] ) ) {
			$fees[] = array(
				'id'     => 'vat_removed',
				'label'  => 'reverse' === self::treatment( $cart ) ? __( 'VAT removed (reverse charge)', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Tax exempt', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'amount' => -1 * (float) $details['removed'],
			);
		}
		return $fees;
	}

	/**
	 * Note the treatment on the order.
	 *
	 * @param Order $order Order.
	 */
	public function stamp( $order ) {
		$cart = Cart::instance();
		$mode = self::treatment( $cart );
		if ( 'reverse' === $mode ) {
			update_post_meta( $order->get_id(), '_sidccofo_reverse_charge', 1 );
			update_post_meta( $order->get_id(), '_sidccofo_vat_number', (string) $cart->extra( 'vat_number', '' ) );
			$order->add_note( __( 'VAT reverse charge: the buyer’s EU VAT number was accepted, so no VAT was charged.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} elseif ( 'exempt' === $mode ) {
			update_post_meta( $order->get_id(), '_sidccofo_tax_exempt', 1 );
			$order->add_note( __( 'Tax-exempt customer: no tax was charged.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} elseif ( '' !== (string) $cart->extra( 'vat_number', '' ) ) {
			update_post_meta( $order->get_id(), '_sidccofo_vat_number', (string) $cart->extra( 'vat_number', '' ) );
		}
	}

	/*
	------------------------------------------------------------------
	 * User profile
	 * ----------------------------------------------------------------
	 */

	/**
	 * "Tax exempt" box on the profile (store managers only).
	 *
	 * @param \WP_User $user User.
	 */
	public function profile( $user ) {
		if ( ! current_user_can( \SidcraftCommerce\manage_cap() ) ) {
			return;
		}
		wp_nonce_field( 'sidccofo_tax_exempt', '_sidccofo_te' );
		?>
		<h2><?php esc_html_e( 'Store', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h2>
		<table class="form-table" role="presentation"><tr>
			<th scope="row"><?php esc_html_e( 'Tax exempt', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
			<td><label><input type="checkbox" name="sidccofo_tax_exempt" value="1" <?php checked( (bool) get_user_meta( $user->ID, 'sidccofo_tax_exempt', true ) ); ?>> <?php esc_html_e( 'This customer pays no tax in the store.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></td>
		</tr></table>
		<?php
	}

	/**
	 * Save the box.
	 *
	 * @param int $user_id User.
	 */
	public function save_profile( $user_id ) {
		if ( ! current_user_can( \SidcraftCommerce\manage_cap() ) || ! isset( $_POST['_sidccofo_te'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_te'] ) ), 'sidccofo_tax_exempt' ) ) {
			return;
		}
		if ( empty( $_POST['sidccofo_tax_exempt'] ) ) {
			delete_user_meta( $user_id, 'sidccofo_tax_exempt' );
		} else {
			update_user_meta( $user_id, 'sidccofo_tax_exempt', 1 );
		}
	}

	/*
	------------------------------------------------------------------
	 * Report
	 * ----------------------------------------------------------------
	 */

	/**
	 * Tax by country and rate for paid orders between two dates.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array[] country, rate, orders, net, tax, gross (store currency).
	 */
	public static function report( $from, $to ) {
		$ids  = get_posts(
			array(
				'post_type'      => Post_Types::ORDER,
				'post_status'    => Post_Types::paid_statuses(),
				'posts_per_page' => 5000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						'after'     => $from . ' 00:00:00',
						'before'    => $to . ' 23:59:59',
						'inclusive' => true,
					),
				),
			)
		);
		$rows = array();
		foreach ( $ids as $id ) {
			$order = Order::get( $id );
			if ( ! $order ) {
				continue;
			}
			$billing = $order->get_billing();
			$country = (string) ( $billing['country'] ?? '' );
			$rate    = (float) $order->get_tax_rate();
			$key     = $country . '|' . $rate . '|' . ( get_post_meta( $id, '_sidccofo_reverse_charge', true ) ? 'rc' : '' );
			$total   = $order->to_base( (float) $order->get_total() );
			$tax     = $order->to_base( (float) $order->get_tax() );
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'country' => $country,
					'rate'    => $rate,
					'note'    => get_post_meta( $id, '_sidccofo_reverse_charge', true ) ? __( 'Reverse charge', 'sidcraft-commerce-for-sidcraft-page-builder' ) : '',
					'orders'  => 0,
					'net'     => 0.0,
					'tax'     => 0.0,
					'gross'   => 0.0,
				);
			}
			++$rows[ $key ]['orders'];
			$rows[ $key ]['tax']   += $tax;
			$rows[ $key ]['gross'] += $total;
			$rows[ $key ]['net']   += $total - $tax;
		}
		ksort( $rows );
		return array_values( $rows );
	}

	/**
	 * Date range from the request.
	 *
	 * @return array [ from, to ].
	 */
	protected function range() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only report filters.
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : gmdate( 'Y-m-01' );
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : gmdate( 'Y-m-d' );
		return array( $from, $to );
	}

	/**
	 * Admin page.
	 */
	public function report_page() {
		list( $from, $to ) = $this->range();
		$rows              = self::report( $from, $to );
		echo '<div class="wrap"><h1>' . esc_html__( 'Tax report', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h1>';
		echo '<form method="get"><input type="hidden" name="post_type" value="' . esc_attr( Post_Types::PRODUCT ) . '"><input type="hidden" name="page" value="sidccofo-tax-report">';
		echo '<label>' . esc_html__( 'From', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> ';
		echo '<label>' . esc_html__( 'To', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> ';
		echo '<button class="button button-primary">' . esc_html__( 'Show', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button> ';
		$csv = wp_nonce_url( add_query_arg( array( 'action' => 'sidccofo_tax_csv', 'from' => $from, 'to' => $to ), admin_url( 'admin-post.php' ) ), 'sidccofo_tax_csv' );
		echo '<a class="button" href="' . esc_url( $csv ) . '">' . esc_html__( 'Download CSV', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></form>';
		echo '<p class="description">' . esc_html__( 'Paid orders only, in the store currency. Net is the total without tax.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array( __( 'Country', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Rate', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Note', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Orders', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Net', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Tax', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Gross', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No paid orders in this period.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( '' !== $r['country'] ? Locations::country_name( $r['country'] ) : '—' ) . '</td><td>' . esc_html( rtrim( rtrim( number_format( $r['rate'], 3 ), '0' ), '.' ) ) . '%</td><td>' . esc_html( $r['note'] ) . '</td><td>' . esc_html( (string) $r['orders'] ) . '</td><td>' . esc_html( format_price( round_amount( $r['net'] ) ) ) . '</td><td>' . esc_html( format_price( round_amount( $r['tax'] ) ) ) . '</td><td>' . esc_html( format_price( round_amount( $r['gross'] ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * CSV download.
	 */
	public function report_csv() {
		if ( ! current_user_can( \SidcraftCommerce\manage_cap() ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'sidccofo_tax_csv' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		list( $from, $to ) = $this->range();
		$rows              = array( array( 'Country', 'Rate %', 'Note', 'Orders', 'Net', 'Tax', 'Gross' ) );
		foreach ( self::report( $from, $to ) as $r ) {
			$rows[] = array( $r['country'], $r['rate'], $r['note'], $r['orders'], round_amount( $r['net'] ), round_amount( $r['tax'] ), round_amount( $r['gross'] ) );
		}
		Extras::send_file( 'tax-report-' . $from . '-to-' . $to . '.csv', 'text/csv; charset=utf-8', Extras::csv( $rows ) );
	}
}
