<?php
/**
 * Multi-vendor marketplace.
 *
 * Give a user the "Vendor" role (Users → Add New) and they can add and edit
 * their own products under Store → Products (nothing else on the site; their
 * products wait for your approval unless you let them publish). When an
 * order with their products is paid, their share (the price minus your
 * commission) goes to their balance; refunds take it back. Store → Vendor
 * dashboard shows a vendor their balance, earnings and the lines they must
 * ship; Store → Vendor payouts is where you record what you paid them.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Locations;
use function SidcraftCommerce\format_base;
use function SidcraftCommerce\manage_cap;
use function SidcraftCommerce\round_amount;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Vendors
 */
class Vendors extends Tool {

	const ROLE   = 'sidccofo_vendor';
	const LEDGER = 'sidccofo_vendor_ledger';

	/** {@inheritDoc} */
	public function hooks() {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		$this->settings(
			'vendors',
			__( 'Marketplace vendors', $d ),
			array(
				'ven_commission' => array( __( 'Your commission %', $d ), 'text', __( 'The share of each vendor sale you keep (0–100).', $d ) ),
				'ven_publish'    => array( __( 'Vendors may publish products without approval', $d ), 'checkbox' ),
			)
		);
		add_action( 'init', array( $this, 'role' ) );
		add_filter( 'user_has_cap', array( $this, 'caps' ), 10, 4 );
		add_filter( 'map_meta_cap', array( $this, 'only_products' ), 10, 4 );
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'on_status' ), 10, 3 );
		$this->admin_page( 'sidccofo-vendor', __( 'Vendor dashboard', $d ), array( $this, 'dashboard' ), 'edit_posts' );
		$this->admin_page( 'sidccofo-payouts', __( 'Vendor payouts', $d ), array( $this, 'payouts' ) );
		add_action( 'admin_post_sidccofo_payout', array( $this, 'record_payout' ) );
	}

	/**
	 * Create the role once.
	 */
	public function role() {
		if ( get_role( self::ROLE ) ) {
			return;
		}
		add_role(
			self::ROLE,
			__( 'Vendor', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'read'                 => true,
				'upload_files'         => true,
				'edit_posts'           => true,
				'delete_posts'         => true,
				'edit_published_posts' => true,
				'delete_published_posts' => true,
			)
		);
	}

	/**
	 * Is this user a vendor?
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	protected static function is_vendor( $user_id ) {
		$u = get_userdata( $user_id );
		return $u && in_array( self::ROLE, (array) $u->roles, true );
	}

	/**
	 * Publishing right, and the dashboard right for managers.
	 *
	 * @param array    $all  Caps.
	 * @param string[] $caps Required caps.
	 * @param array    $args Args.
	 * @param \WP_User $user User.
	 * @return array
	 */
	public function caps( $all, $caps, $args, $user ) {
		unset( $args );
		if ( in_array( self::ROLE, (array) $user->roles, true ) && setting( 'ven_publish', 0 ) ) {
			$all['publish_posts'] = true;
		}
		return $all;
	}

	/**
	 * Vendors may only touch products.
	 *
	 * @param string[] $caps    Caps.
	 * @param string   $cap     Cap.
	 * @param int      $user_id User.
	 * @param array    $args    Args.
	 * @return string[]
	 */
	public function only_products( $caps, $cap, $user_id, $args ) {
		if ( in_array( $cap, array( 'edit_post', 'delete_post', 'read_post', 'publish_post' ), true ) && ! empty( $args[0] ) && self::is_vendor( $user_id ) && Post_Types::PRODUCT !== get_post_type( (int) $args[0] ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/*
	------------------------------------------------------------------
	 * Earnings
	 * ----------------------------------------------------------------
	 */

	/**
	 * Add an entry to a vendor's ledger.
	 *
	 * @param int    $vendor Vendor.
	 * @param string $type   earn | reverse | payout.
	 * @param float  $amount Amount (store currency); earnings positive.
	 * @param int    $order  Order (0 for payouts).
	 * @param string $note   Note.
	 */
	protected static function entry( $vendor, $type, $amount, $order = 0, $note = '' ) {
		$l   = (array) get_user_meta( $vendor, self::LEDGER, true );
		$l[] = array( 'time' => time(), 'type' => $type, 'amount' => round_amount( $amount ), 'order' => $order, 'note' => $note );
		update_user_meta( $vendor, self::LEDGER, array_slice( $l, -500 ) );
	}

	/**
	 * Balance owed to a vendor.
	 *
	 * @param int $vendor Vendor.
	 * @return float
	 */
	public static function balance( $vendor ) {
		$sum = 0.0;
		foreach ( array_filter( (array) get_user_meta( $vendor, self::LEDGER, true ), 'is_array' ) as $e ) {
			$sum += 'payout' === $e['type'] ? -1 * (float) $e['amount'] : (float) $e['amount'];
		}
		return round_amount( $sum );
	}

	/**
	 * Vendor lines of an order: vendor id => [ items[], earn ].
	 *
	 * @param Order $order Order.
	 * @return array
	 */
	protected function lines( Order $order ) {
		$pct = min( 100.0, max( 0.0, (float) setting( 'ven_commission', 10 ) ) );
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$author = (int) get_post_field( 'post_author', (int) $item['product_id'] );
			if ( ! $author || ! self::is_vendor( $author ) ) {
				continue;
			}
			$base = $order->to_base( (float) $item['total'] );
			$out[ $author ]['items'][] = $item;
			$out[ $author ]['earn']    = ( $out[ $author ]['earn'] ?? 0.0 ) + $base * ( 100 - $pct ) / 100;
		}
		return $out;
	}

	/**
	 * Pay or take back when the order's payment state changes.
	 *
	 * @param Order  $order Order.
	 * @param string $old   Old.
	 * @param string $new   New.
	 */
	public function on_status( $order, $old, $new ) {
		$paid = Post_Types::paid_statuses();
		if ( in_array( $new, $paid, true ) && ! get_post_meta( $order->get_id(), '_sidccofo_vendor_paid', true ) ) {
			$lines = $this->lines( $order );
			foreach ( $lines as $vendor => $l ) {
				/* translators: %d: order number. */
				self::entry( $vendor, 'earn', $l['earn'], $order->get_id(), sprintf( __( 'Order #%d', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
			}
			if ( $lines ) {
				update_post_meta( $order->get_id(), '_sidccofo_vendor_paid', 1 );
			}
		} elseif ( ! in_array( $new, $paid, true ) && in_array( $old, $paid, true ) && get_post_meta( $order->get_id(), '_sidccofo_vendor_paid', true ) ) {
			foreach ( $this->lines( $order ) as $vendor => $l ) {
				/* translators: %d: order number. */
				self::entry( $vendor, 'reverse', -1 * $l['earn'], $order->get_id(), sprintf( __( 'Order #%d reversed', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
			}
			delete_post_meta( $order->get_id(), '_sidccofo_vendor_paid' );
		}
	}

	/*
	------------------------------------------------------------------
	 * Screens
	 * ----------------------------------------------------------------
	 */

	/**
	 * Dashboard of the vendor who is looking (managers see a vendor picker).
	 */
	public function dashboard() {
		$d      = 'sidcraft-commerce-for-sidcraft-page-builder';
		$me     = get_current_user_id();
		$vendor = $me;
		if ( current_user_can( manage_cap() ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only picker.
			$vendor = isset( $_GET['vendor'] ) ? absint( $_GET['vendor'] ) : 0;
			if ( ! self::is_vendor( $vendor ) ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Vendor dashboard', $d ) . '</h1><p>' . esc_html__( 'Pick a vendor on Vendor payouts to see their dashboard.', $d ) . '</p></div>';
				return;
			}
		} elseif ( ! self::is_vendor( $me ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Vendor dashboard', $d ) . '</h1><p><strong>' . esc_html__( 'Balance owed to you:', $d ) . '</strong> ' . esc_html( format_base( self::balance( $vendor ) ) ) . '</p><h2>' . esc_html__( 'Earnings', $d ) . '</h2><table class="widefat striped" style="max-width:700px"><thead><tr><th>' . esc_html__( 'Date', $d ) . '</th><th>' . esc_html__( 'Entry', $d ) . '</th><th>' . esc_html__( 'Amount', $d ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( array_slice( array_filter( (array) get_user_meta( $vendor, self::LEDGER, true ), 'is_array' ), -30 ) ) as $e ) {
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d', (int) $e['time'] ) ) . '</td><td>' . esc_html( ucfirst( $e['type'] ) . ( $e['note'] ? ' · ' . $e['note'] : '' ) ) . '</td><td>' . esc_html( format_base( 'payout' === $e['type'] ? -1 * (float) $e['amount'] : (float) $e['amount'] ) ) . '</td></tr>';
		}
		echo '</tbody></table><h2>' . esc_html__( 'To ship', $d ) . '</h2>';
		$ids = get_posts( array( 'post_type' => Post_Types::ORDER, 'post_status' => array( 'sidccofo-processing' ), 'posts_per_page' => 30, 'fields' => 'ids', 'no_found_rows' => true ) );
		$any = false;
		foreach ( $ids as $id ) {
			$o = Order::get( $id );
			$l = $o ? $this->lines( $o ) : array();
			if ( empty( $l[ $vendor ] ) ) {
				continue;
			}
			$any = true;
			$b   = $o->get_billing();
			echo '<div style="background:#fff;border:1px solid #dcdcde;padding:10px 14px;margin:8px 0;max-width:700px"><strong>#' . (int) $id . '</strong><br>';
			foreach ( $l[ $vendor ]['items'] as $i ) {
				echo esc_html( $i['quantity'] . ' × ' . wp_strip_all_tags( $i['name'] ) ) . '<br>';
			}
			echo '<small>' . esc_html( trim( ( $b['first_name'] ?? '' ) . ' ' . ( $b['last_name'] ?? '' ) . ', ' . ( $b['address_1'] ?? '' ) . ', ' . ( $b['postcode'] ?? '' ) . ' ' . ( $b['city'] ?? '' ) . ', ' . ( ! empty( $b['country'] ) ? Locations::country_name( $b['country'] ) : '' ) ) ) . '</small></div>';
		}
		if ( ! $any ) {
			echo '<p>' . esc_html__( 'Nothing to ship.', $d ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Payouts page (managers).
	 */
	public function payouts() {
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		echo '<div class="wrap"><h1>' . esc_html__( 'Vendor payouts', $d ) . '</h1><table class="widefat striped" style="max-width:800px"><thead><tr><th>' . esc_html__( 'Vendor', $d ) . '</th><th>' . esc_html__( 'Balance owed', $d ) . '</th><th>' . esc_html__( 'Record a payout', $d ) . '</th></tr></thead><tbody>';
		$vendors = get_users( array( 'role' => self::ROLE, 'number' => 200 ) );
		if ( ! $vendors ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No vendors yet. Give a user the Vendor role.', $d ) . '</td></tr>';
		}
		foreach ( $vendors as $v ) {
			echo '<tr><td><a href="' . esc_url( $this->page_url( 'sidccofo-vendor', array( 'vendor' => $v->ID ) ) ) . '">' . esc_html( $v->display_name ) . '</a></td><td>' . esc_html( format_base( self::balance( $v->ID ) ) ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sidccofo_payout"><input type="hidden" name="vendor" value="' . esc_attr( (string) $v->ID ) . '">';
			wp_nonce_field( 'sidccofo_payout_' . $v->ID );
			echo '<input type="number" step="0.01" min="0.01" name="amount" style="width:100px" required> <button class="button">' . esc_html__( 'Paid', $d ) . '</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Record a payout.
	 */
	public function record_payout() {
		$v = isset( $_POST['vendor'] ) ? absint( $_POST['vendor'] ) : 0;
		if ( ! $v || ! current_user_can( manage_cap() ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sidccofo_payout_' . $v ) ) {
			wp_die( esc_html__( 'Not allowed.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		$amount = isset( $_POST['amount'] ) ? round( (float) $_POST['amount'], 2 ) : 0.0;
		if ( self::is_vendor( $v ) && $amount > 0 ) {
			self::entry( $v, 'payout', $amount, 0, __( 'Payout', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		wp_safe_redirect( $this->page_url( 'sidccofo-payouts' ) );
		exit;
	}
}
