<?php
/**
 * Store → Reports: sales over time, best-sellers, coupons, refunds, revenue
 * by country and the customers who spent most. Everything is worked out from
 * paid orders in the chosen dates and shown in the store currency.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Locations;
use function SidcraftCommerce\base_currency;
use function SidcraftCommerce\format_base;
use function SidcraftCommerce\round_amount;

defined( 'ABSPATH' ) || exit;

/**
 * Class Reports
 */
class Reports extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$this->admin_page( 'sidccofo-reports', __( 'Reports', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'page' ) );
		add_action( 'admin_post_sidccofo_report_csv', array( $this, 'csv' ) );
	}

	/**
	 * Work the figures out.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	public static function compute( $from, $to ) {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ORDER,
				'post_status'    => array_merge( Post_Types::paid_statuses(), array( 'sidccofo-refunded' ) ),
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
		$r   = array(
			'orders'    => 0,
			'gross'     => 0.0,
			'tax'       => 0.0,
			'shipping'  => 0.0,
			'discount'  => 0.0,
			'refunds'   => 0.0,
			'items'     => 0,
			'days'      => array(),
			'products'  => array(),
			'coupons'   => array(),
			'countries' => array(),
			'customers' => array(),
		);
		// Every day of the range, so the chart has no gaps.
		for ( $t = strtotime( $from ); $t <= strtotime( $to ) && count( $r['days'] ) < 400; $t += DAY_IN_SECONDS ) {
			$r['days'][ gmdate( 'Y-m-d', $t ) ] = 0.0;
		}
		foreach ( $ids as $id ) {
			$o = Order::get( $id );
			if ( ! $o ) {
				continue;
			}
			$total    = $o->to_base( (float) $o->get_total() );
			$refunded = $o->to_base( (float) $o->get_refunded_amount() );
			++$r['orders'];
			$r['gross']    += $total;
			$r['tax']      += $o->to_base( (float) $o->get_tax() );
			$r['shipping'] += $o->to_base( (float) $o->get_shipping() );
			$r['discount'] += $o->to_base( (float) $o->get_discount() );
			$r['refunds']  += $refunded;
			$day            = substr( (string) get_post_field( 'post_date', $id ), 0, 10 );
			if ( isset( $r['days'][ $day ] ) ) {
				$r['days'][ $day ] += $total - $refunded;
			}
			foreach ( $o->get_items() as $item ) {
				$key = (int) $item['product_id'];
				if ( ! isset( $r['products'][ $key ] ) ) {
					$r['products'][ $key ] = array(
						'name'    => (string) $item['name'],
						'qty'     => 0,
						'revenue' => 0.0,
					);
				}
				$r['products'][ $key ]['qty']     += (int) $item['quantity'];
				$r['products'][ $key ]['revenue'] += $o->to_base( (float) $item['total'] );
				$r['items']                       += (int) $item['quantity'];
			}
			$code = $o->get_coupon_code();
			if ( '' !== $code ) {
				$c = strtoupper( $code );
				if ( ! isset( $r['coupons'][ $c ] ) ) {
					$r['coupons'][ $c ] = array( 'uses' => 0, 'discount' => 0.0 );
				}
				++$r['coupons'][ $c ]['uses'];
				$r['coupons'][ $c ]['discount'] += $o->to_base( (float) $o->get_discount() );
			}
			$b       = $o->get_billing();
			$country = (string) ( $b['country'] ?? '' );
			if ( ! isset( $r['countries'][ $country ] ) ) {
				$r['countries'][ $country ] = array( 'orders' => 0, 'revenue' => 0.0 );
			}
			++$r['countries'][ $country ]['orders'];
			$r['countries'][ $country ]['revenue'] += $total;
			$email = strtolower( $o->get_email() );
			if ( '' !== $email ) {
				if ( ! isset( $r['customers'][ $email ] ) ) {
					$r['customers'][ $email ] = array( 'name' => trim( ( $b['first_name'] ?? '' ) . ' ' . ( $b['last_name'] ?? '' ) ), 'orders' => 0, 'spent' => 0.0 );
				}
				++$r['customers'][ $email ]['orders'];
				$r['customers'][ $email ]['spent'] += $total;
			}
		}
		uasort( $r['products'], static function ( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );
		uasort( $r['coupons'], static function ( $a, $b ) { return $b['uses'] <=> $a['uses']; } );
		uasort( $r['countries'], static function ( $a, $b ) { return $b['revenue'] <=> $a['revenue']; } );
		uasort( $r['customers'], static function ( $a, $b ) { return $b['spent'] <=> $a['spent']; } );
		$r['net']  = $r['gross'] - $r['refunds'];
		$r['aov']  = $r['orders'] ? $r['gross'] / $r['orders'] : 0.0;
		$r['repeat'] = count( array_filter( $r['customers'], static function ( $c ) { return $c['orders'] > 1; } ) );
		return $r;
	}

	/**
	 * Dates from the request.
	 *
	 * @return array
	 */
	protected function range() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : gmdate( 'Y-m-d' );
		return array( $from, $to );
	}

	/**
	 * Inline SVG bar chart of revenue per day.
	 *
	 * @param array $days Date => amount.
	 * @return string
	 */
	protected static function chart( array $days ) {
		$max = max( 1.0, max( $days ? $days : array( 0 ) ) );
		$n   = max( 1, count( $days ) );
		$w   = 860;
		$h   = 180;
		$bw  = $w / $n;
		$svg = '<svg viewBox="0 0 ' . $w . ' ' . ( $h + 24 ) . '" role="img" aria-label="' . esc_attr__( 'Revenue per day', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '" style="width:100%;max-width:900px;background:#fff;border:1px solid #dcdcde;border-radius:6px">';
		$i   = 0;
		foreach ( $days as $day => $amount ) {
			$bh   = $amount > 0 ? max( 2, ( $amount / $max ) * ( $h - 10 ) ) : 0;
			$svg .= '<rect x="' . round( $i * $bw + 1, 2 ) . '" y="' . round( $h - $bh, 2 ) . '" width="' . max( 1, round( $bw - 2, 2 ) ) . '" height="' . round( $bh, 2 ) . '" fill="#2271b1"><title>' . esc_html( $day . ': ' . format_base( round_amount( $amount ) ) ) . '</title></rect>';
			++$i;
		}
		$keys = array_keys( $days );
		if ( $keys ) {
			$svg .= '<text x="4" y="' . ( $h + 16 ) . '" font-size="11" fill="#646970">' . esc_html( reset( $keys ) ) . '</text><text x="' . ( $w - 4 ) . '" y="' . ( $h + 16 ) . '" font-size="11" text-anchor="end" fill="#646970">' . esc_html( end( $keys ) ) . '</text>';
		}
		return $svg . '</svg>';
	}

	/**
	 * Admin page.
	 */
	public function page() {
		$d                 = 'sidcraft-commerce-for-sidcraft-page-builder';
		list( $from, $to ) = $this->range();
		$r                 = self::compute( $from, $to );
		echo '<div class="wrap"><h1>' . esc_html__( 'Reports', $d ) . '</h1>';
		echo '<form method="get"><input type="hidden" name="post_type" value="' . esc_attr( Post_Types::PRODUCT ) . '"><input type="hidden" name="page" value="sidccofo-reports">';
		echo '<label>' . esc_html__( 'From', $d ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> <label>' . esc_html__( 'To', $d ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> ';
		echo '<button class="button button-primary">' . esc_html__( 'Show', $d ) . '</button> ';
		$csv = wp_nonce_url( add_query_arg( array( 'action' => 'sidccofo_report_csv', 'from' => $from, 'to' => $to ), admin_url( 'admin-post.php' ) ), 'sidccofo_report_csv' );
		echo '<a class="button" href="' . esc_url( $csv ) . '">' . esc_html__( 'Download CSV', $d ) . '</a></form>';
		echo '<p class="description">' . sprintf( /* translators: %s: currency code. */ esc_html__( 'Paid orders, in %s.', $d ), esc_html( base_currency() ) ) . '</p>';

		echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0">';
		$cards = array(
			__( 'Orders', $d )         => (string) $r['orders'],
			__( 'Gross sales', $d )    => format_base( round_amount( $r['gross'] ) ),
			__( 'Refunds', $d )        => format_base( round_amount( $r['refunds'] ) ),
			__( 'Net sales', $d )      => format_base( round_amount( $r['net'] ) ),
			__( 'Average order', $d )  => format_base( round_amount( $r['aov'] ) ),
			__( 'Items sold', $d )     => (string) $r['items'],
			__( 'Tax collected', $d )  => format_base( round_amount( $r['tax'] ) ),
			__( 'Discounts given', $d ) => format_base( round_amount( $r['discount'] ) ),
			__( 'Repeat customers', $d ) => (string) $r['repeat'],
		);
		foreach ( $cards as $label => $value ) {
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 16px;min-width:140px"><div style="color:#646970;font-size:12px">' . esc_html( $label ) . '</div><div style="font-size:20px;font-weight:600">' . esc_html( $value ) . '</div></div>';
		}
		echo '</div>';
		echo self::chart( $r['days'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.

		$table = static function ( $title, array $head, array $rows ) {
			echo '<h2>' . esc_html( $title ) . '</h2><table class="widefat striped" style="max-width:900px"><thead><tr>';
			foreach ( $head as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			if ( ! $rows ) {
				echo '<tr><td colspan="' . count( $head ) . '">' . esc_html__( 'Nothing in this period.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</td></tr>';
			}
			foreach ( $rows as $row ) {
				echo '<tr>';
				foreach ( $row as $cell ) {
					echo '<td>' . esc_html( (string) $cell ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		};
		$rows = array();
		foreach ( array_slice( $r['products'], 0, 10, true ) as $p ) {
			$rows[] = array( $p['name'], $p['qty'], format_base( round_amount( $p['revenue'] ) ) );
		}
		$table( __( 'Best-selling products', $d ), array( __( 'Product', $d ), __( 'Units', $d ), __( 'Revenue', $d ) ), $rows );
		$rows = array();
		foreach ( array_slice( $r['countries'], 0, 10, true ) as $c => $v ) {
			$rows[] = array( '' !== $c ? Locations::country_name( $c ) : '—', $v['orders'], format_base( round_amount( $v['revenue'] ) ) );
		}
		$table( __( 'Revenue by country', $d ), array( __( 'Country', $d ), __( 'Orders', $d ), __( 'Revenue', $d ) ), $rows );
		$rows = array();
		foreach ( array_slice( $r['coupons'], 0, 10, true ) as $c => $v ) {
			$rows[] = array( $c, $v['uses'], format_base( round_amount( $v['discount'] ) ) );
		}
		$table( __( 'Coupons used', $d ), array( __( 'Code', $d ), __( 'Orders', $d ), __( 'Discount given', $d ) ), $rows );
		$rows = array();
		foreach ( array_slice( $r['customers'], 0, 10, true ) as $e => $v ) {
			$rows[] = array( '' !== $v['name'] ? $v['name'] . ' (' . $e . ')' : $e, $v['orders'], format_base( round_amount( $v['spent'] ) ) );
		}
		$table( __( 'Top customers in this period', $d ), array( __( 'Customer', $d ), __( 'Orders', $d ), __( 'Spent', $d ) ), $rows );
		echo '</div>';
	}

	/**
	 * CSV of the daily figures and products.
	 */
	public function csv() {
		if ( ! current_user_can( \SidcraftCommerce\manage_cap() ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'sidccofo_report_csv' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		list( $from, $to ) = $this->range();
		$r                 = self::compute( $from, $to );
		$rows              = array( array( 'Date', 'Net revenue' ) );
		foreach ( $r['days'] as $day => $amount ) {
			$rows[] = array( $day, round_amount( $amount ) );
		}
		$rows[] = array();
		$rows[] = array( 'Product', 'Units', 'Revenue' );
		foreach ( $r['products'] as $p ) {
			$rows[] = array( $p['name'], $p['qty'], round_amount( $p['revenue'] ) );
		}
		Extras::send_file( 'sales-report-' . $from . '-to-' . $to . '.csv', 'text/csv; charset=utf-8', Extras::csv( $rows ) );
	}
}
