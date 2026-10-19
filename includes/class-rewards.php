<?php
/**
 * Reward points: logged-in customers earn points on paid orders and spend
 * them at checkout.
 *
 * Settings (Store → Settings → Rewards & affiliates):
 *   rewards_enabled      Turn the program on.
 *   rewards_earn_rate    Points per 1 unit of currency spent on products (after coupons).
 *   rewards_point_value  What 1 point is worth when spent (e.g. 0.01).
 *   rewards_min_redeem   Fewest points that can be spent at once.
 *   rewards_max_percent  Most of an order (in %) points may pay.
 * A product can earn a fixed number of points per unit instead (Product data).
 *
 * Balances live in user meta (_sidccofo_points, with a history in
 * _sidccofo_points_log). Spending takes the points when the order is placed,
 * with a conditional update so the same points can't pay two orders; a
 * cancelled or refunded order gives them back. Earned points are added when
 * the order is paid and taken away again if it is cancelled or refunded.
 *
 * Order meta: _sidccofo_points_used, _sidccofo_points_value (the amount they
 * paid), _sidccofo_points_earn, _sidccofo_points_awarded, _sidccofo_points_returned.
 *
 * Points work like store credit: they lower what the customer pays (after
 * tax, before gift cards), so they never change the tax charged.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rewards
 */
class Rewards {

	const BALANCE = '_sidccofo_points';
	const LOG     = '_sidccofo_points_log';

	/**
	 * Cart whose checkout is being placed (set just before the order exists).
	 *
	 * @var Cart|null
	 */
	protected $placing = null;

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_filter( 'sidcraft_commerce_settings_sections', array( $this, 'settings' ) );
		add_filter( 'sidcraft_commerce_settings_tabs', array( $this, 'tab' ) );
		// Orders placed while the program was on still give back, take back
		// and award their points after it is switched off.
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'status_changed' ), 20, 3 );
		add_action( 'sidcraft_commerce_order_partial_refund', array( $this, 'partial_refund' ), 10, 3 );
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'sidcraft_commerce_cart_total', array( $this, 'cart_total' ), 50, 2 );
		add_filter( 'sidcraft_commerce_totals_rows', array( $this, 'totals_rows' ), 10, 2 );
		add_filter( 'sidcraft_commerce_checkout_errors', array( $this, 'checkout_errors' ), 20 );
		add_action( 'sidcraft_commerce_order_created', array( $this, 'order_created' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'show_user_profile', array( $this, 'profile' ) );
		add_action( 'edit_user_profile', array( $this, 'profile' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
	}

	/**
	 * Is the program on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) setting( 'rewards_enabled', 0 );
	}

	/**
	 * Points per unit of currency.
	 *
	 * @return float
	 */
	public static function earn_rate() {
		return max( 0.0, (float) str_replace( ',', '.', (string) setting( 'rewards_earn_rate', '1' ) ) );
	}

	/**
	 * Value of one point.
	 *
	 * @return float
	 */
	public static function point_value() {
		return max( 0.0, (float) str_replace( ',', '.', (string) setting( 'rewards_point_value', '0.01' ) ) );
	}

	/*
	------------------------------------------------------------------
	 * Balances
	 * ----------------------------------------------------------------
	 */

	/**
	 * A user's points.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function balance( $user_id ) {
		return max( 0, self::raw_balance( $user_id ) );
	}

	/**
	 * Stored points, below zero when points earned on a refunded order had
	 * already been spent (later earnings pay that back first).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function raw_balance( $user_id ) {
		return $user_id ? (int) get_user_meta( (int) $user_id, self::BALANCE, true ) : 0;
	}

	/**
	 * Add points (or take them with a negative number, never below zero
	 * unless $owe: taking back earned points that were already spent).
	 *
	 * @param int    $user_id User.
	 * @param int    $points  Points.
	 * @param string $note    History note.
	 * @param int    $order   Order id (0 = none).
	 * @param bool   $owe     Let the balance go below zero.
	 * @return int Points actually added or taken (signed).
	 */
	public static function adjust( $user_id, $points, $note, $order = 0, $owe = false ) {
		$user_id = (int) $user_id;
		$points  = (int) $points;
		if ( ! $user_id || 0 === $points ) {
			return 0;
		}
		$before = self::raw_balance( $user_id );
		$after  = $before + $points;
		if ( ! $owe && $points < 0 ) {
			$after = max( min( $before, 0 ), $after );
		}
		if ( $after === $before ) {
			return 0;
		}
		update_user_meta( $user_id, self::BALANCE, $after );
		self::log( $user_id, $after - $before, $note, $order );
		return $after - $before;
	}

	/**
	 * Take points only if the user still has them (one conditional update).
	 *
	 * @param int    $user_id User.
	 * @param int    $points  Points to take.
	 * @param string $note    History note.
	 * @param int    $order   Order id.
	 * @return bool
	 */
	public static function spend( $user_id, $points, $note, $order = 0 ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$points  = (int) $points;
		if ( ! $user_id || $points < 1 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional update: two checkouts can't spend the same points.
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS SIGNED) - %d WHERE user_id = %d AND meta_key = %s AND CAST(meta_value AS SIGNED) >= %d", $points, $user_id, self::BALANCE, $points ) );
		wp_cache_delete( $user_id, 'user_meta' );
		if ( ! $done ) {
			return false;
		}
		self::log( $user_id, -1 * $points, $note, $order );
		return true;
	}

	/**
	 * Add a history row (newest last, 200 kept).
	 *
	 * @param int    $user_id User.
	 * @param int    $points  Signed points.
	 * @param string $note    Note.
	 * @param int    $order   Order id.
	 */
	protected static function log( $user_id, $points, $note, $order ) {
		$log   = get_user_meta( $user_id, self::LOG, true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'   => time(),
			'points' => (int) $points,
			'note'   => wp_strip_all_tags( (string) $note ),
			'order'  => (int) $order,
		);
		update_user_meta( $user_id, self::LOG, array_slice( $log, -200 ) );
	}

	/**
	 * A user's history, newest first.
	 *
	 * @param int $user_id User.
	 * @return array[]
	 */
	public static function history( $user_id ) {
		$log = get_user_meta( (int) $user_id, self::LOG, true );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	/*
	------------------------------------------------------------------
	 * Earning
	 * ----------------------------------------------------------------
	 */

	/**
	 * Points an order's items earn: each product's fixed points per unit,
	 * or the store's rate on what was paid for it after the coupon.
	 *
	 * @param array $items         Order items.
	 * @param float $discount      Coupon discount on the order.
	 * @param float $exchange_rate The order's rate (amounts are in its currency).
	 * @return int
	 */
	public static function points_for_items( array $items, $discount, $exchange_rate = 1.0 ) {
		// The earn rate is per unit of the store currency.
		$exchange_rate = (float) $exchange_rate > 0 ? (float) $exchange_rate : 1.0;
		$goods = 0.0;
		foreach ( $items as $item ) {
			if ( empty( $item['gift_card'] ) ) {
				$goods += (float) $item['total'];
			}
		}
		$factor = $goods > 0 ? max( 0.0, 1 - (float) $discount / $goods ) : 0.0;
		$rate   = self::earn_rate();
		$points = 0.0;
		foreach ( $items as $item ) {
			if ( ! empty( $item['gift_card'] ) ) {
				continue; // Buying store credit earns nothing.
			}
			$fixed = get_post_meta( (int) $item['product_id'], '_sidccofo_reward_points', true );
			if ( '' !== $fixed && null !== $fixed && is_numeric( $fixed ) ) {
				$points += max( 0, (int) $fixed ) * (int) $item['quantity'];
			} else {
				$points += (float) $item['total'] / $exchange_rate * $factor * $rate;
			}
		}
		/**
		 * Filter the points an order earns.
		 *
		 * @param int   $points Points.
		 * @param array $items  Items.
		 */
		return max( 0, (int) apply_filters( 'sidcraft_commerce_reward_points_earned', (int) floor( $points + 0.00001 ), $items ) );
	}

	/*
	------------------------------------------------------------------
	 * Spending in the cart
	 * ----------------------------------------------------------------
	 */

	/**
	 * Points the shopper asked to use.
	 *
	 * @param Cart $cart Cart.
	 * @return int
	 */
	public static function requested( Cart $cart ) {
		return max( 0, (int) $cart->session()->get( 'points', 0 ) );
	}

	/**
	 * Points that will be used and what they pay, for a total before points.
	 *
	 * @param Cart  $cart  Cart.
	 * @param float $total Total before points.
	 * @return array [ points, amount ].
	 */
	public static function redemption( Cart $cart, $total ) {
		$none = array(
			'points' => 0,
			'amount' => 0.0,
		);
		$user  = get_current_user_id();
		$want  = min( self::requested( $cart ), self::balance( $user ) );
		$value = self::point_value() * \SidcraftCommerce\Currencies::rate(); // In the cart's currency.
		if ( ! $user || $want < 1 || $value <= 0 || $total <= 0 || $want < (int) setting( 'rewards_min_redeem', 0 ) ) {
			return $none;
		}
		$max    = round_amount( $total * min( 100, max( 0, (float) setting( 'rewards_max_percent', '100' ) ) ) / 100 );
		$amount = round_amount( min( $want * $value, $max ) );
		// Don't charge more points than the amount needs.
		$points = (int) min( $want, ceil( $amount / $value - 0.00001 ) );
		if ( $points < 1 || $amount <= 0 ) {
			return $none;
		}
		return array(
			'points' => $points,
			'amount' => $amount,
		);
	}

	/**
	 * Last redemption worked out per cart and total (the filter runs often).
	 *
	 * @var array
	 */
	protected $memo = array();

	/**
	 * Lower the cart total by the points used.
	 *
	 * @param float $total Total before points.
	 * @param Cart  $cart  Cart.
	 * @return float
	 */
	public function cart_total( $total, $cart ) {
		if ( ! $cart instanceof Cart ) {
			return $total;
		}
		$r = self::redemption( $cart, (float) $total );
		$this->memo[ spl_object_id( $cart ) ] = $r;
		return $r['amount'] > 0 ? round_amount( (float) $total - $r['amount'] ) : $total;
	}

	/**
	 * Redemption for a cart (works the total out first).
	 *
	 * @param Cart $cart Cart.
	 * @return array [ points, amount ].
	 */
	public function for_cart( Cart $cart ) {
		$cart->get_total_before_gift_cards();
		return $this->memo[ spl_object_id( $cart ) ] ?? array(
			'points' => 0,
			'amount' => 0.0,
		);
	}

	/**
	 * "Reward points" line above the total.
	 *
	 * @param array $rows   Rows.
	 * @param mixed $source Cart or Order.
	 * @return array
	 */
	public function totals_rows( $rows, $source ) {
		if ( $source instanceof Cart ) {
			$r = $this->for_cart( $source );
		} elseif ( $source instanceof Order ) {
			$r = array(
				'points' => (int) get_post_meta( $source->get_id(), '_sidccofo_points_used', true ),
				'amount' => (float) get_post_meta( $source->get_id(), '_sidccofo_points_value', true ),
			);
		} else {
			return $rows;
		}
		if ( $r['amount'] <= 0 ) {
			return $rows;
		}
		$row = array(
			'key'   => 'points',
			/* translators: %s: number of points. */
			'label' => sprintf( __( 'Reward points (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( $r['points'] ) ),
			'value' => format_price( -1 * $r['amount'] ),
			'class' => 'sc-totals__discount',
		);
		// Before gift cards and the total.
		$at = count( $rows );
		foreach ( $rows as $i => $existing ) {
			if ( in_array( $existing['key'] ?? '', array( 'gift_card', 'gift_card_notice', 'total' ), true ) ) {
				$at = $i;
				break;
			}
		}
		array_splice( $rows, $at, 0, array( $row ) );
		return $rows;
	}

	/*
	------------------------------------------------------------------
	 * Orders
	 * ----------------------------------------------------------------
	 */

	/**
	 * Last check before the order is created: the points must still be there.
	 *
	 * @param array $errors Errors.
	 * @return array
	 */
	public function checkout_errors( $errors ) {
		$cart          = Cart::instance();
		$this->placing = null;
		if ( self::requested( $cart ) > 0 && self::requested( $cart ) > self::balance( get_current_user_id() ) ) {
			$errors['points'] = __( 'You don’t have that many reward points any more. Change the amount and try again.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( ! $errors ) {
			$this->placing = $cart;
		}
		return $errors;
	}

	/**
	 * New order: take the points it uses and note what it will earn.
	 *
	 * @param Order $order Order.
	 */
	public function order_created( $order ) {
		$user = (int) get_post_meta( $order->get_id(), '_sidccofo_customer_id', true );
		update_post_meta( $order->get_id(), '_sidccofo_points_earn', self::points_for_items( $order->get_items(), $order->get_discount(), $order->get_exchange_rate() ) );
		if ( ! $this->placing || ! $user ) {
			return;
		}
		$cart          = $this->placing;
		$this->placing = null;
		// What the order's total was worked out with (not worked out again:
		// the balance could have changed since).
		$r = $this->memo[ spl_object_id( $cart ) ] ?? array(
			'points' => 0,
			'amount' => 0.0,
		);
		$cart->session()->set( 'points', 0 );
		if ( $r['points'] < 1 ) {
			return;
		}
		/* translators: %d: order number. */
		if ( self::spend( $user, $r['points'], sprintf( __( 'Used on order #%d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ), $order->get_id() ) ) {
			update_post_meta( $order->get_id(), '_sidccofo_points_used', $r['points'] );
			update_post_meta( $order->get_id(), '_sidccofo_points_value', (string) $r['amount'] );
			/* translators: 1: points, 2: amount. */
			$order->add_note( sprintf( __( '%1$s reward points paid %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( $r['points'] ), format_price( $r['amount'] ) ) );
		} else {
			// Spent elsewhere a moment ago and the total already counts them:
			// cancel this order (its stock goes back) and let checkout say so.
			update_post_meta( $order->get_id(), '_sidccofo_checkout_problem', __( 'Your reward points balance changed. Check your order total and place the order again.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			$order->set_status( 'sidccofo-cancelled', __( 'The reward points for this order could not be taken (the balance changed), so it was cancelled before payment.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
	}

	/**
	 * Partial refund: take back the share of earned points the refunded
	 * amount stands for.
	 *
	 * @param Order $order    Order.
	 * @param float $refunded Amount refunded in total so far.
	 * @param float $total    Order total.
	 */
	public function partial_refund( $order, $refunded, $total ) {
		$id   = $order->get_id();
		$user = (int) get_post_meta( $id, '_sidccofo_customer_id', true );
		$earn = (int) get_post_meta( $id, '_sidccofo_points_earn', true );
		if ( ! $user || $earn < 1 || (float) $total <= 0 || ! get_post_meta( $id, '_sidccofo_points_awarded', true ) ) {
			return;
		}
		$due    = min( $earn, (int) floor( $earn * min( 1, (float) $refunded / (float) $total ) ) );
		$clawed = (int) get_post_meta( $id, '_sidccofo_points_clawed', true );
		if ( $due > $clawed ) {
			/* translators: %d: order number. */
			self::adjust( $user, $clawed - $due, sprintf( __( 'Part of order #%d refunded.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $id ), $id, true );
			update_post_meta( $id, '_sidccofo_points_clawed', $due );
		}
	}

	/**
	 * Award points when paid; take them back (and return spent points) on
	 * cancel or refund; take spent points again if such an order is reopened.
	 *
	 * @param Order  $order Order.
	 * @param string $old   Old status.
	 * @param string $new   New status.
	 */
	public function status_changed( $order, $old, $new ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		$id   = $order->get_id();
		$user = (int) get_post_meta( $id, '_sidccofo_customer_id', true );
		if ( ! $user ) {
			return;
		}
		$used = (int) get_post_meta( $id, '_sidccofo_points_used', true );
		$earn = (int) get_post_meta( $id, '_sidccofo_points_earn', true );

		if ( in_array( $new, array( 'sidccofo-cancelled', 'sidccofo-refunded' ), true ) ) {
			if ( get_post_meta( $id, '_sidccofo_points_awarded', true ) ) {
				$left = $earn - (int) get_post_meta( $id, '_sidccofo_points_clawed', true );
				/* translators: %d: order number. */
				self::adjust( $user, -1 * max( 0, $left ), sprintf( __( 'Order #%d cancelled or refunded.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $id ), $id, true );
				update_post_meta( $id, '_sidccofo_points_awarded', 0 );
				delete_post_meta( $id, '_sidccofo_points_clawed' );
			}
			if ( $used > 0 && ! get_post_meta( $id, '_sidccofo_points_returned', true ) ) {
				/* translators: %d: order number. */
				self::adjust( $user, $used, sprintf( __( 'Returned from order #%d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $id ), $id );
				update_post_meta( $id, '_sidccofo_points_returned', 1 );
			}
			return;
		}

		if ( $used > 0 && get_post_meta( $id, '_sidccofo_points_returned', true ) && in_array( $new, Post_Types::stock_statuses(), true ) ) {
			/* translators: %d: order number. */
			if ( self::spend( $user, $used, sprintf( __( 'Used again on reopened order #%d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $id ), $id ) ) {
				update_post_meta( $id, '_sidccofo_points_returned', 0 );
			} else {
				$order->add_note( __( 'The reward points this order used were given back when it was cancelled and the customer no longer has them.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			}
		}

		if ( $earn > 0 && in_array( $new, Post_Types::paid_statuses(), true ) && ! get_post_meta( $id, '_sidccofo_points_awarded', true ) ) {
			/* translators: %d: order number. */
			self::adjust( $user, $earn, sprintf( __( 'Earned on order #%d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $id ), $id );
			update_post_meta( $id, '_sidccofo_points_awarded', 1 );
			/* translators: %s: points. */
			$order->add_note( sprintf( __( 'Customer earned %s reward points.', 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( $earn ) ) );
		}
	}

	/*
	------------------------------------------------------------------
	 * REST: use / stop using points at checkout
	 * ----------------------------------------------------------------
	 */

	/**
	 * Routes.
	 */
	public function routes() {
		register_rest_route(
			Rest::NS,
			'/cart/points',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'rest_apply' ),
				'args'                => array(
					'points'  => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'context' => array(
						'type'    => 'string',
						'default' => 'checkout',
						'enum'    => array( '', 'cart', 'checkout' ),
					),
				),
			)
		);
	}

	/**
	 * POST cart/points: { points } (0 stops using them).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_apply( \WP_REST_Request $request ) {
		$cart    = Cart::instance();
		$balance = self::balance( get_current_user_id() );
		$points  = min( (int) $request['points'], $balance );
		$min     = (int) setting( 'rewards_min_redeem', 0 );
		$status  = 200;
		if ( $points > 0 && $points < $min ) {
			/* translators: %s: minimum points. */
			$message = sprintf( __( 'Use at least %s points at a time.', 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( $min ) );
			$status  = 400;
		} else {
			$cart->session()->set( 'points', $points );
			$cart->session()->save();
			$r       = $this->for_cart( $cart );
			$message = $r['points'] > 0
				/* translators: 1: points, 2: amount. */
				? sprintf( __( 'Using %1$s points (%2$s).', 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( $r['points'] ), format_price( $r['amount'] ) )
				: __( 'Not using reward points.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		return new \WP_REST_Response(
			array(
				'fragments' => Fragments::collect( (string) $request['context'] ),
				'cart_hash' => $cart->get_hash(),
				'count'     => $cart->get_count(),
				'total'     => $cart->get_total(),
				'message'   => $message,
			),
			$status
		);
	}

	/*
	------------------------------------------------------------------
	 * Admin: user profile and settings
	 * ----------------------------------------------------------------
	 */

	/**
	 * Balance, adjustment and history on the user screen.
	 *
	 * @param \WP_User $user User.
	 */
	public function profile( $user ) {
		if ( ! current_user_can( manage_cap() ) ) {
			return;
		}
		wp_nonce_field( 'sidccofo_points_' . $user->ID, '_sidccofo_points_nonce' );
		?>
		<h2><?php esc_html_e( 'Reward points', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Balance', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
				<td><strong><?php echo esc_html( number_format_i18n( self::balance( $user->ID ) ) ); ?></strong>
					<?php /* translators: %s: amount. */ ?>
					<span class="description"><?php echo esc_html( sprintf( __( 'worth %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( self::balance( $user->ID ) * self::point_value() ) ) ); ?></span>
					<?php if ( self::raw_balance( $user->ID ) < 0 ) : ?>
						<?php /* translators: %s: points. */ ?>
						<br><span class="description"><?php echo esc_html( sprintf( __( 'Owes %s points from refunded orders; new points pay this back first.', 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( -1 * self::raw_balance( $user->ID ) ) ) ); ?></span>
					<?php endif; ?></td>
			</tr>
			<tr>
				<th><label for="sc-points-adjust"><?php esc_html_e( 'Add or remove points', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></th>
				<td><input type="number" step="1" id="sc-points-adjust" name="sidccofo_points_adjust" class="small-text" value="">
					<input type="text" name="sidccofo_points_note" class="regular-text" placeholder="<?php esc_attr_e( 'Reason (shown in the history)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
					<p class="description"><?php esc_html_e( 'A negative number removes points.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p></td>
			</tr>
			<?php $log = array_slice( self::history( $user->ID ), 0, 20 ); ?>
			<?php if ( $log ) : ?>
				<tr>
					<th><?php esc_html_e( 'Recent history', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<td><ul style="margin:0">
						<?php foreach ( $log as $row ) : ?>
							<li><?php echo esc_html( wp_date( get_option( 'date_format' ), $row['time'] ) . ' · ' . ( $row['points'] > 0 ? '+' : '' ) . number_format_i18n( $row['points'] ) . ' · ' . $row['note'] ); ?></li>
						<?php endforeach; ?>
					</ul></td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Save a manual adjustment.
	 *
	 * @param int $user_id User.
	 */
	public function save_profile( $user_id ) {
		if ( ! current_user_can( manage_cap() ) || ! isset( $_POST['_sidccofo_points_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_points_nonce'] ) ), 'sidccofo_points_' . $user_id ) ) {
			return;
		}
		$delta = isset( $_POST['sidccofo_points_adjust'] ) ? (int) $_POST['sidccofo_points_adjust'] : 0;
		if ( 0 !== $delta ) {
			$note = isset( $_POST['sidccofo_points_note'] ) ? sanitize_text_field( wp_unslash( $_POST['sidccofo_points_note'] ) ) : '';
			self::adjust( $user_id, $delta, '' !== $note ? $note : __( 'Adjusted by the store.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
	}

	/**
	 * Settings tab.
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
		$sections['rewards'] = array(
			'tab'    => 'marketing',
			'title'  => __( 'Reward points', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'intro'  => __( 'Logged-in customers earn points on paid orders and can spend them at checkout. Spent points come back if an order is cancelled or refunded, and points earned on it are taken away again. Adjust a customer’s points on their user screen.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'fields' => array(
				'rewards_enabled'     => array( __( 'Reward points', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Turn the program on.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'rewards_earn_rate'   => array( __( 'Points per 1 spent', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'On products, after coupons; shipping and tax earn nothing. Products can earn a fixed number per unit instead (Product data).', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'rewards_point_value' => array( __( 'Value of 1 point', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'In store currency when spent, e.g. 0.01 (100 points = 1).', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'rewards_min_redeem'  => array( __( 'Fewest points to spend', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( '0 = any amount.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'rewards_max_percent' => array( __( 'Most of an order paid with points (%)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( '100 = the whole order.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			),
		);
		return $sections;
	}
}
