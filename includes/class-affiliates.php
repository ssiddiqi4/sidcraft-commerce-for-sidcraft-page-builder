<?php
/**
 * Affiliates: people who send customers through their own link earn a
 * commission on the orders those customers place.
 *
 * Settings (Store → Settings → Rewards & affiliates):
 *   affiliates_enabled       Turn the program on.
 *   affiliates_rate          Commission in % of products bought (after coupons;
 *                            shipping, tax and gift cards don't count).
 *   affiliates_cookie_days   How long a visit through a link counts.
 *   affiliates_auto_approve  Approve sign-ups straight away.
 *   affiliates_param         Link parameter: https://shop.example/?ref=CODE.
 *
 * Joining: the Affiliate Dashboard Unit ([sidccofo_affiliate_dashboard]) lets a
 * logged-in customer apply, then shows their link, commissions and payouts.
 * Store → Affiliates approves applications, sets personal rates and records
 * payouts.
 *
 * User meta: _sidccofo_aff_status (pending|approved|rejected), _sidccofo_aff_code,
 * _sidccofo_aff_rate ('' = store rate), _sidccofo_aff_payment, _sidccofo_aff_website.
 * Order meta: _sidccofo_affiliate_id, _sidccofo_commission, _sidccofo_commission_rate,
 * _sidccofo_commission_status: pending (not paid yet) → unpaid (owed) → paid,
 * or void when the order is cancelled or refunded first.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Class Affiliates
 */
class Affiliates {

	const COOKIE = 'sidccofo_ref';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_filter( 'sidcraft_commerce_settings_sections', array( $this, 'settings' ) );
		add_filter( 'sidcraft_commerce_settings_tabs', array( $this, 'tab' ) );
		// Commissions already noted keep following their orders when the program is switched off.
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'status_changed' ), 20, 3 );
		add_action( 'sidcraft_commerce_order_partial_refund', array( $this, 'partial_refund' ), 10, 3 );
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'init', array( $this, 'track' ), 30 );
		add_action( 'sidcraft_commerce_order_created', array( $this, 'order_created' ) );
		add_action( 'admin_post_sidccofo_aff_apply', array( $this, 'apply' ) );
		add_action( 'admin_post_sidccofo_aff_details', array( $this, 'save_details' ) );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 40 );
			add_action( 'admin_post_sidccofo_aff_admin', array( $this, 'admin_action' ) );
			add_action( 'add_meta_boxes_' . Post_Types::ORDER, array( $this, 'order_box' ), 30 );
		}
	}

	/**
	 * Is the program on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) setting( 'affiliates_enabled', 0 );
	}

	/**
	 * Link parameter name.
	 *
	 * @return string
	 */
	public static function param() {
		$param = sanitize_key( (string) setting( 'affiliates_param', 'ref' ) );
		return '' !== $param ? $param : 'ref';
	}

	/*
	------------------------------------------------------------------
	 * Affiliates
	 * ----------------------------------------------------------------
	 */

	/**
	 * Status of a user ('' = not an affiliate).
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function status( $user_id ) {
		$status = (string) get_user_meta( (int) $user_id, '_sidccofo_aff_status', true );
		return in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ? $status : '';
	}

	/**
	 * A user's referral code (made on first use).
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function code( $user_id ) {
		$code = (string) get_user_meta( (int) $user_id, '_sidccofo_aff_code', true );
		if ( '' === $code ) {
			$user = get_userdata( (int) $user_id );
			$base = $user ? sanitize_key( $user->user_login ) : '';
			$code = '' !== $base && strlen( $base ) <= 20 && ! self::user_by_code( $base ) ? $base : strtolower( wp_generate_password( 8, false ) );
			update_user_meta( (int) $user_id, '_sidccofo_aff_code', $code );
		}
		return $code;
	}

	/**
	 * The approved affiliate with a code.
	 *
	 * @param string $code Code.
	 * @return int User id or 0.
	 */
	public static function user_by_code( $code ) {
		$code = sanitize_key( (string) $code );
		if ( '' === $code ) {
			return 0;
		}
		$users = get_users(
			array(
				'meta_key'   => '_sidccofo_aff_code', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return $users ? (int) $users[0] : 0;
	}

	/**
	 * Commission rate for an affiliate (%).
	 *
	 * @param int $user_id User.
	 * @return float
	 */
	public static function rate( $user_id ) {
		$own = get_user_meta( (int) $user_id, '_sidccofo_aff_rate', true );
		$raw = '' !== (string) $own ? $own : setting( 'affiliates_rate', '10' );
		return min( 100.0, max( 0.0, (float) str_replace( ',', '.', (string) $raw ) ) );
	}

	/**
	 * Referral link for an affiliate.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function link( $user_id ) {
		return add_query_arg( self::param(), self::code( $user_id ), home_url( '/' ) );
	}

	/*
	------------------------------------------------------------------
	 * Tracking and commissions
	 * ----------------------------------------------------------------
	 */

	/**
	 * A visit through ?ref=CODE: remember the affiliate for the cookie days.
	 */
	public function track() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only referral parameter.
		if ( is_admin() || ! isset( $_GET[ self::param() ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = sanitize_key( wp_unslash( $_GET[ self::param() ] ) );
		$user = self::user_by_code( $code );
		if ( ! $user || 'approved' !== self::status( $user ) || get_current_user_id() === $user || headers_sent() ) {
			return;
		}
		$days = max( 1, min( 365, (int) setting( 'affiliates_cookie_days', '30' ) ) );
		setcookie(
			cookie_name( self::COOKIE ),
			$code,
			array(
				'expires'  => time() + $days * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ cookie_name( self::COOKIE ) ] = $code;
	}

	/**
	 * Commission an order's items earn at a rate.
	 *
	 * @param array $items    Order items.
	 * @param float $discount Coupon discount.
	 * @param float $rate     Rate (%).
	 * @return float
	 */
	public static function commission_for( array $items, $discount, $rate ) {
		$goods = 0.0;
		foreach ( $items as $item ) {
			if ( empty( $item['gift_card'] ) ) {
				$goods += (float) $item['total'];
			}
		}
		$base = max( 0.0, $goods - (float) $discount );
		return round_amount( $base * (float) $rate / 100 );
	}

	/**
	 * New order from a referred visitor: note the affiliate and commission.
	 *
	 * @param Order $order Order.
	 */
	public function order_created( $order ) {
		$code = isset( $_COOKIE[ cookie_name( self::COOKIE ) ] ) ? sanitize_key( wp_unslash( $_COOKIE[ cookie_name( self::COOKIE ) ] ) ) : '';
		$aff  = self::user_by_code( $code );
		if ( ! $aff || 'approved' !== self::status( $aff ) ) {
			return;
		}
		// No commission on your own orders.
		$buyer = (int) get_post_meta( $order->get_id(), '_sidccofo_customer_id', true );
		$user  = get_userdata( $aff );
		if ( $buyer === $aff || get_current_user_id() === $aff || ( $user && strtolower( $user->user_email ) === strtolower( $order->get_email() ) ) ) {
			return;
		}
		$rate   = self::rate( $aff );
		// Commissions are owed in the store currency.
		$amount = \SidcraftCommerce\round_base( self::commission_for( $order->get_items(), $order->get_discount(), $rate ) / $order->get_exchange_rate() );
		/**
		 * Filter an order's affiliate commission.
		 *
		 * @param float $amount Commission.
		 * @param Order $order  Order.
		 * @param int   $aff    Affiliate user id.
		 */
		$amount = \SidcraftCommerce\round_base( max( 0.0, (float) apply_filters( 'sidcraft_commerce_affiliate_commission', $amount, $order, $aff ) ) );
		update_post_meta( $order->get_id(), '_sidccofo_affiliate_id', $aff );
		update_post_meta( $order->get_id(), '_sidccofo_commission', (string) $amount );
		update_post_meta( $order->get_id(), '_sidccofo_commission_rate', (string) $rate );
		update_post_meta( $order->get_id(), '_sidccofo_commission_status', 'pending' );
		/* translators: 1: affiliate name, 2: commission. */
		$order->add_note( sprintf( __( 'Referred by affiliate %1$s (commission %2$s).', 'sidcraft-commerce-for-sidcraft-page-builder' ), $user ? $user->display_name : '#' . $aff, format_base( $amount ) ) );
	}

	/**
	 * Partial refund: the commission shrinks with the order (until paid out).
	 *
	 * @param Order $order    Order.
	 * @param float $refunded Amount refunded in total so far.
	 * @param float $total    Order total.
	 */
	public function partial_refund( $order, $refunded, $total ) {
		$id     = $order->get_id();
		$status = (string) get_post_meta( $id, '_sidccofo_commission_status', true );
		if ( ! in_array( $status, array( 'pending', 'unpaid' ), true ) || (float) $total <= 0 ) {
			return;
		}
		$full = get_post_meta( $id, '_sidccofo_commission_full', true );
		if ( '' === $full ) {
			$full = (string) get_post_meta( $id, '_sidccofo_commission', true );
			update_post_meta( $id, '_sidccofo_commission_full', $full );
		}
		$share  = max( 0.0, 1 - min( 1, (float) $refunded / (float) $total ) );
		$amount = \SidcraftCommerce\round_base( (float) $full * $share );
		update_post_meta( $id, '_sidccofo_commission', (string) $amount );
		/* translators: %s: commission. */
		$order->add_note( sprintf( __( 'Affiliate commission reduced to %s after a partial refund.', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_base( $amount ) ) );
	}

	/**
	 * Commission follows the order: owed once paid, void if cancelled or
	 * refunded before it was paid out.
	 *
	 * @param Order  $order Order.
	 * @param string $old   Old status.
	 * @param string $new   New status.
	 */
	public function status_changed( $order, $old, $new ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		$id     = $order->get_id();
		$status = (string) get_post_meta( $id, '_sidccofo_commission_status', true );
		if ( '' === $status ) {
			return;
		}
		if ( in_array( $new, array( 'sidccofo-cancelled', 'sidccofo-refunded' ), true ) ) {
			if ( 'paid' === $status ) {
				$order->add_note( __( 'The affiliate commission for this order was already paid out.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			} elseif ( 'void' !== $status ) {
				update_post_meta( $id, '_sidccofo_commission_status', 'void' );
			}
			return;
		}
		if ( in_array( $new, Post_Types::paid_statuses(), true ) && in_array( $status, array( 'pending', 'void' ), true ) ) {
			update_post_meta( $id, '_sidccofo_commission_status', 'unpaid' );
		}
	}

	/**
	 * An affiliate's orders with commission.
	 *
	 * @param int    $user_id User.
	 * @param string $status  Commission status ('' = any).
	 * @param int    $limit   Most orders (-1 = all).
	 * @return int[] Order ids, newest first.
	 */
	public static function order_ids( $user_id, $status = '', $limit = -1 ) {
		$query = array(
			array(
				'key'   => '_sidccofo_affiliate_id',
				'value' => (int) $user_id,
			),
		);
		if ( '' !== $status ) {
			$query[] = array(
				'key'   => '_sidccofo_commission_status',
				'value' => $status,
			);
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Post_Types::ORDER,
					'post_status'    => array_keys( Post_Types::order_statuses() ),
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => $query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			)
		);
	}

	/**
	 * Totals for an affiliate: pending, unpaid, paid amounts and order count.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function totals( $user_id ) {
		$out = array(
			'pending' => 0.0,
			'unpaid'  => 0.0,
			'paid'    => 0.0,
			'orders'  => 0,
		);
		foreach ( self::order_ids( $user_id ) as $id ) {
			$status = (string) get_post_meta( $id, '_sidccofo_commission_status', true );
			if ( isset( $out[ $status ] ) ) {
				$out[ $status ] += (float) get_post_meta( $id, '_sidccofo_commission', true );
				++$out['orders'];
			}
		}
		foreach ( array( 'pending', 'unpaid', 'paid' ) as $key ) {
			$out[ $key ] = \SidcraftCommerce\round_base( $out[ $key ] );
		}
		return $out;
	}

	/**
	 * Mark an affiliate's owed commissions paid.
	 *
	 * @param int    $user_id User.
	 * @param string $note    Payout note (method, reference).
	 * @return float Amount marked paid.
	 */
	public static function pay_out( $user_id, $note = '' ) {
		$sum = 0.0;
		foreach ( self::order_ids( $user_id, 'unpaid' ) as $id ) {
			$sum += (float) get_post_meta( $id, '_sidccofo_commission', true );
			update_post_meta( $id, '_sidccofo_commission_status', 'paid' );
			update_post_meta( $id, '_sidccofo_commission_paid', time() );
		}
		$sum = \SidcraftCommerce\round_base( $sum );
		if ( $sum > 0 ) {
			$log   = get_user_meta( (int) $user_id, '_sidccofo_aff_payouts', true );
			$log   = is_array( $log ) ? $log : array();
			$log[] = array(
				'time'   => time(),
				'amount' => $sum,
				'note'   => sanitize_text_field( (string) $note ),
			);
			update_user_meta( (int) $user_id, '_sidccofo_aff_payouts', array_slice( $log, -100 ) );
		}
		return $sum;
	}

	/*
	------------------------------------------------------------------
	 * Front end: apply and edit details (from the dashboard Unit)
	 * ----------------------------------------------------------------
	 */

	/**
	 * Back to the page the form was on.
	 *
	 * @param string $flag Query flag to show a message.
	 */
	protected function back( $flag ) {
		$to = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'sidccofo_aff', $flag, $to ? $to : home_url( '/' ) ) );
		exit;
	}

	/**
	 * Apply to join.
	 */
	public function apply() {
		$user = get_current_user_id();
		if ( ! $user || ! isset( $_POST['_sidccofo_aff_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_aff_nonce'] ) ), 'sidccofo_aff_apply' ) ) {
			wp_die( esc_html__( 'Your session expired. Go back, reload the page and try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		if ( '' === self::status( $user ) ) {
			update_user_meta( $user, '_sidccofo_aff_website', esc_url_raw( wp_unslash( $_POST['website'] ?? '' ) ) );
			update_user_meta( $user, '_sidccofo_aff_payment', sanitize_textarea_field( wp_unslash( $_POST['payment'] ?? '' ) ) );
			$approved = (bool) setting( 'affiliates_auto_approve', 0 );
			update_user_meta( $user, '_sidccofo_aff_status', $approved ? 'approved' : 'pending' );
			self::code( $user );
			if ( ! $approved ) {
				$to = (string) setting( 'admin_email', get_option( 'admin_email' ) );
				$u  = wp_get_current_user();
				/* translators: %s: site name. */
				wp_mail( $to, sprintf( __( '[%s] New affiliate application', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), sprintf( "%s <%s>\n\n%s", $u->display_name, $u->user_email, admin_url( 'edit.php?post_type=' . Post_Types::PRODUCT . '&page=sidccofo-affiliates' ) ) );
			}
		}
		$this->back( 'applied' );
	}

	/**
	 * Update payout details.
	 */
	public function save_details() {
		$user = get_current_user_id();
		if ( ! $user || ! isset( $_POST['_sidccofo_aff_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_aff_nonce'] ) ), 'sidccofo_aff_details' ) ) {
			wp_die( esc_html__( 'Your session expired. Go back, reload the page and try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		if ( '' !== self::status( $user ) ) {
			update_user_meta( $user, '_sidccofo_aff_payment', sanitize_textarea_field( wp_unslash( $_POST['payment'] ?? '' ) ) );
		}
		$this->back( 'saved' );
	}

	/*
	------------------------------------------------------------------
	 * Admin: Store → Affiliates and the order box
	 * ----------------------------------------------------------------
	 */

	/**
	 * Menu.
	 */
	public function menu() {
		add_submenu_page( 'edit.php?post_type=' . Post_Types::PRODUCT, __( 'Affiliates', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Affiliates', 'sidcraft-commerce-for-sidcraft-page-builder' ), manage_cap(), 'sidccofo-affiliates', array( $this, 'admin_page' ) );
	}

	/**
	 * The list.
	 */
	public function admin_page() {
		if ( ! current_user_can( manage_cap() ) ) {
			return;
		}
		$users = get_users(
			array(
				'meta_key'     => '_sidccofo_aff_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'number'       => 500,
			)
		);
		$labels = array(
			'pending'  => __( 'Waiting for approval', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'approved' => __( 'Approved', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'rejected' => __( 'Rejected', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
		$post = admin_url( 'admin-post.php' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Affiliates', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( isset( $_GET['done'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p></div>';
		}
		/* translators: 1: rate, 2: shortcode. */
		echo '<p>' . esc_html( sprintf( __( 'Store rate: %1$s%% of products bought. Customers join from a page with the Affiliate Dashboard Unit or %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), self::rate( 0 ), '[sidccofo_affiliate_dashboard]' ) ) . '</p>';
		if ( ! $users ) {
			echo '<p>' . esc_html__( 'No affiliates yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Affiliate', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Code', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Rate %', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Orders', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Pending', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Owed', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Paid', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Payout details', 'sidcraft-commerce-for-sidcraft-page-builder' ), '' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $users as $user ) {
			$status = self::status( $user->ID );
			$t      = self::totals( $user->ID );
			echo '<tr><td><a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a><br><span class="description">' . esc_html( $user->user_email ) . '</span></td>';
			echo '<td>' . esc_html( $labels[ $status ] ?? $status ) . '</td>';
			echo '<td><code>' . esc_html( self::code( $user->ID ) ) . '</code></td>';
			echo '<td><form method="post" action="' . esc_url( $post ) . '" style="display:flex;gap:4px">';
			wp_nonce_field( 'sidccofo_aff_admin_' . $user->ID, '_sidccofo_nonce' );
			echo '<input type="hidden" name="action" value="sidccofo_aff_admin"><input type="hidden" name="user" value="' . (int) $user->ID . '"><input type="hidden" name="do" value="rate">';
			echo '<input type="text" name="rate" class="small-text" value="' . esc_attr( (string) get_user_meta( $user->ID, '_sidccofo_aff_rate', true ) ) . '" placeholder="' . esc_attr( (string) self::rate( 0 ) ) . '"><button class="button button-small">' . esc_html__( 'Set', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></form></td>';
			echo '<td>' . esc_html( number_format_i18n( $t['orders'] ) ) . '</td><td>' . esc_html( format_base( $t['pending'] ) ) . '</td><td><strong>' . esc_html( format_base( $t['unpaid'] ) ) . '</strong></td><td>' . esc_html( format_base( $t['paid'] ) ) . '</td>';
			echo '<td>' . nl2br( esc_html( (string) get_user_meta( $user->ID, '_sidccofo_aff_payment', true ) ) ) . '</td><td>';
			foreach ( array(
				'approve' => array( __( 'Approve', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'approved' !== $status ),
				'reject'  => array( __( 'Reject', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'rejected' !== $status ),
				'payout'  => array( __( 'Mark owed as paid', 'sidcraft-commerce-for-sidcraft-page-builder' ), $t['unpaid'] > 0 ),
			) as $do => $button ) {
				if ( ! $button[1] ) {
					continue;
				}
				echo '<form method="post" action="' . esc_url( $post ) . '" style="display:inline-block;margin:2px">';
				wp_nonce_field( 'sidccofo_aff_admin_' . $user->ID, '_sidccofo_nonce' );
				echo '<input type="hidden" name="action" value="sidccofo_aff_admin"><input type="hidden" name="user" value="' . (int) $user->ID . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
				if ( 'payout' === $do ) {
					echo '<input type="text" name="note" placeholder="' . esc_attr__( 'Reference (optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '" class="regular-text" style="width:160px"> ';
				}
				echo '<button class="button button-small' . ( 'payout' === $do ? ' button-primary' : '' ) . '">' . esc_html( $button[0] ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Approve, reject, set a rate or record a payout.
	 */
	public function admin_action() {
		$user = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		if ( ! current_user_can( manage_cap() ) || ! $user || ! isset( $_POST['_sidccofo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_nonce'] ) ), 'sidccofo_aff_admin_' . $user ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'approve' === $do ) {
			update_user_meta( $user, '_sidccofo_aff_status', 'approved' );
			self::code( $user );
		} elseif ( 'reject' === $do ) {
			update_user_meta( $user, '_sidccofo_aff_status', 'rejected' );
		} elseif ( 'rate' === $do ) {
			$rate = trim( sanitize_text_field( wp_unslash( $_POST['rate'] ?? '' ) ) );
			update_user_meta( $user, '_sidccofo_aff_rate', is_numeric( str_replace( ',', '.', $rate ) ) ? (string) min( 100, max( 0, (float) str_replace( ',', '.', $rate ) ) ) : '' );
		} elseif ( 'payout' === $do ) {
			self::pay_out( $user, sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ) );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Post_Types::PRODUCT . '&page=sidccofo-affiliates&done=1' ) );
		exit;
	}

	/**
	 * Affiliate box on referred orders.
	 *
	 * @param \WP_Post $post Order.
	 */
	public function order_box( $post ) {
		if ( ! get_post_meta( $post->ID, '_sidccofo_affiliate_id', true ) ) {
			return;
		}
		add_meta_box(
			'sidccofo-order-affiliate',
			__( 'Affiliate', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			static function ( $post ) {
				$aff    = (int) get_post_meta( $post->ID, '_sidccofo_affiliate_id', true );
				$user   = get_userdata( $aff );
				$labels = array(
					'pending' => __( 'Waits for payment', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'unpaid'  => __( 'Owed', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'paid'    => __( 'Paid out', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'void'    => __( 'Void (order cancelled or refunded)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				);
				$status = (string) get_post_meta( $post->ID, '_sidccofo_commission_status', true );
				echo '<p>' . ( $user ? '<a href="' . esc_url( get_edit_user_link( $aff ) ) . '">' . esc_html( $user->display_name ) . '</a>' : esc_html( '#' . $aff ) ) . '</p>';
				/* translators: 1: amount, 2: rate. */
				echo '<p>' . esc_html( sprintf( __( 'Commission %1$s (%2$s%%)', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_base( (float) get_post_meta( $post->ID, '_sidccofo_commission', true ) ), (string) get_post_meta( $post->ID, '_sidccofo_commission_rate', true ) ) ) . '<br>' . esc_html( $labels[ $status ] ?? $status ) . '</p>';
			},
			Post_Types::ORDER,
			'side'
		);
	}

	/**
	 * Settings tab (shared with reward points).
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function tab( $tabs ) {
		$tabs['marketing'] = __( 'Rewards & affiliates', 'sidcraft-commerce-for-sidcraft-page-builder' );
		return $tabs;
	}

	/**
	 * Settings section.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public function settings( $sections ) {
		$sections['affiliates'] = array(
			'tab'    => 'marketing',
			'title'  => __( 'Affiliates', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'intro'  => __( 'Affiliates share a link; orders from visitors who arrive through it earn them a commission once paid. Put the Affiliate Dashboard Unit (or [sidccofo_affiliate_dashboard]) on a page where customers can join and follow their earnings. Approve them and record payouts under Store → Affiliates.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'fields' => array(
				'affiliates_enabled'      => array( __( 'Affiliates', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Turn the program on.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'affiliates_rate'         => array( __( 'Commission (%)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Of products bought, after coupons. Shipping, tax and gift cards don’t count. Each affiliate can have their own rate.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'affiliates_cookie_days'  => array( __( 'Referral lasts (days)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'How long after the visit an order still counts.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'affiliates_auto_approve' => array( __( 'Approve new affiliates automatically', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
				'affiliates_param'        => array( __( 'Link parameter', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Links look like https://your-store.example/?ref=CODE. Any page of the site works.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			),
		);
		return $sections;
	}
}
