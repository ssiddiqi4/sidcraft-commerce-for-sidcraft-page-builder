<?php
/**
 * Memberships and content restriction.
 *
 * Plans (Settings → Store tools → Memberships): a name, the products that
 * grant it, and how many days each paid order adds (0 = forever). Every paid
 * order, including each subscription renewal, extends access; a refund or
 * cancellation takes the days it gave back. Pages, posts and products can be
 * limited to one or more plans in the "Members only" box on their edit
 * screen, optionally released N days after a member joined (drip content).
 * Visitors without access see a message with a log-in link instead of the
 * content (the page itself stays visible for search engines unless you hide
 * it in your theme).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use function SidcraftCommerce\page_url;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Memberships
 */
class Memberships extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$this->settings(
			'memberships',
			__( 'Memberships', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'mem_plans' => array(
					__( 'Plans', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'table',
					__( 'Slug: letters and numbers (for example gold). Products: ids of the products that grant the plan. Days: access each paid order adds (0 = forever).', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					array(
						'slug'     => array( __( 'Slug', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'gold' ),
						'name'     => array( __( 'Name', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'Gold member' ),
						'products' => array( __( 'Products (ids)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', '12, 15' ),
						'days'     => array( __( 'Days per order', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'amount', '30' ),
					),
				),
				'mem_message' => array( __( 'Message for visitors without access', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Shown instead of members-only content.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			)
		);
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'on_status' ), 10, 3 );
		add_filter( 'the_content', array( $this, 'gate' ), 9 );
		add_action( 'add_meta_boxes', array( $this, 'box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'user_register', array( $this, 'claim_pending' ) );
		add_action( 'wp_login', array( $this, 'claim_on_login' ), 10, 2 );
		add_shortcode( 'sidccofo_member_only', array( $this, 'shortcode' ) );
		add_shortcode( 'sidccofo_membership', array( $this, 'status_shortcode' ) );
	}

	/**
	 * Plans.
	 *
	 * @return array slug => [ name, products[], days ].
	 */
	public static function plans() {
		$out = array();
		foreach ( (array) setting( 'mem_plans', array() ) as $row ) {
			$slug = sanitize_key( (string) ( $row['slug'] ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}
			$out[ $slug ] = array(
				'name'     => '' !== trim( (string) ( $row['name'] ?? '' ) ) ? (string) $row['name'] : $slug,
				'products' => array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) ( $row['products'] ?? '' ) ) ) ) ),
				'days'     => max( 0, (int) ( $row['days'] ?? 0 ) ),
			);
		}
		return $out;
	}

	/**
	 * A user's memberships.
	 *
	 * @param int $user_id User.
	 * @return array slug => [ start, expires (0 = never) ].
	 */
	public static function of( $user_id ) {
		$m = get_user_meta( $user_id, 'sidccofo_memberships', true );
		return is_array( $m ) ? $m : array();
	}

	/**
	 * Does a user hold a plan right now?
	 *
	 * @param int    $user_id User.
	 * @param string $slug    Plan.
	 * @return array|null The record, or null.
	 */
	public static function holds( $user_id, $slug ) {
		$m = self::of( $user_id );
		if ( isset( $m[ $slug ] ) && ( 0 === (int) $m[ $slug ]['expires'] || (int) $m[ $slug ]['expires'] > time() ) ) {
			return $m[ $slug ];
		}
		return null;
	}

	/**
	 * Add or remove access.
	 *
	 * @param int    $user_id User.
	 * @param string $slug    Plan.
	 * @param int    $days    Days (0 = forever).
	 * @param int    $sign    1 give, -1 take back.
	 */
	protected function adjust( $user_id, $slug, $days, $sign ) {
		$m   = self::of( $user_id );
		$now = time();
		if ( $sign > 0 ) {
			$cur = $m[ $slug ] ?? array( 'start' => $now, 'expires' => 0 );
			if ( empty( $m[ $slug ] ) || ( 0 !== (int) $cur['expires'] && (int) $cur['expires'] < $now ) ) {
				$cur = array( 'start' => $now, 'expires' => $days > 0 ? $now : 0 ); // Fresh start.
			}
			if ( 0 === $days || ( isset( $m[ $slug ] ) && 0 === (int) $m[ $slug ]['expires'] && $days > 0 && 0 === (int) $cur['expires'] && isset( $m[ $slug ]['forever'] ) ) ) {
				$cur['expires'] = 0;
				$cur['forever'] = 1;
			} elseif ( empty( $cur['forever'] ) ) {
				$cur['expires'] = max( (int) $cur['expires'], $now ) + $days * DAY_IN_SECONDS;
			}
			$m[ $slug ] = $cur;
		} elseif ( isset( $m[ $slug ] ) ) {
			if ( 0 === $days || ! empty( $m[ $slug ]['forever'] ) ) {
				unset( $m[ $slug ] );
			} else {
				$m[ $slug ]['expires'] = (int) $m[ $slug ]['expires'] - $days * DAY_IN_SECONDS;
				if ( $m[ $slug ]['expires'] <= $now ) {
					unset( $m[ $slug ] );
				}
			}
		}
		update_user_meta( $user_id, 'sidccofo_memberships', $m );
	}

	/**
	 * Grant or take back when an order's payment state changes.
	 *
	 * @param Order  $order Order.
	 * @param string $old   Old status.
	 * @param string $new   New status.
	 */
	public function on_status( $order, $old, $new ) {
		$paid = Post_Types::paid_statuses();
		$was  = in_array( $old, $paid, true );
		$is   = in_array( $new, $paid, true );
		if ( $was === $is ) {
			return;
		}
		$grants = array();
		foreach ( self::plans() as $slug => $plan ) {
			foreach ( $order->get_items() as $item ) {
				if ( in_array( (int) $item['product_id'], $plan['products'], true ) ) {
					$grants[ $slug ] = $plan['days'];
				}
			}
		}
		if ( ! $grants ) {
			return;
		}
		$user = (int) get_post_meta( $order->get_id(), '_sidccofo_customer_id', true );
		if ( ! $user && is_email( $order->get_email() ) ) {
			$u    = get_user_by( 'email', $order->get_email() );
			$user = $u ? (int) $u->ID : 0;
		}
		foreach ( $grants as $slug => $days ) {
			if ( $user ) {
				$this->adjust( $user, $slug, $days, $is ? 1 : -1 );
			} elseif ( $is ) {
				$p = (array) get_option( 'sidccofo_pending_memberships', array() );
				$p[ strtolower( $order->get_email() ) ][] = array( 'slug' => $slug, 'days' => $days );
				update_option( 'sidccofo_pending_memberships', $p, false );
			}
		}
	}

	/**
	 * A new account gets what its email already bought.
	 *
	 * @param int $user_id User.
	 */
	public function claim_pending( $user_id ) {
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return;
		}
		$p = (array) get_option( 'sidccofo_pending_memberships', array() );
		$e = strtolower( $u->user_email );
		if ( empty( $p[ $e ] ) ) {
			return;
		}
		foreach ( $p[ $e ] as $g ) {
			$this->adjust( $user_id, $g['slug'], (int) $g['days'], 1 );
		}
		unset( $p[ $e ] );
		update_option( 'sidccofo_pending_memberships', $p, false );
	}

	/**
	 * Same at log-in (an account that existed first).
	 *
	 * @param string   $login Login.
	 * @param \WP_User $user  User.
	 */
	public function claim_on_login( $login, $user ) {
		unset( $login );
		$this->claim_pending( $user->ID );
	}

	/**
	 * May this user see a post?
	 *
	 * @param int $post_id Post.
	 * @param int $user_id User (0 = guest).
	 * @return bool
	 */
	public static function can_view( $post_id, $user_id ) {
		$need = array_filter( (array) get_post_meta( $post_id, '_sidccofo_members_only', true ) );
		if ( ! $need ) {
			return true;
		}
		if ( $user_id && user_can( $user_id, 'edit_post', $post_id ) ) {
			return true;
		}
		$drip = (int) get_post_meta( $post_id, '_sidccofo_drip_days', true );
		foreach ( $need as $slug ) {
			$rec = $user_id ? self::holds( $user_id, $slug ) : null;
			if ( $rec && ( $drip <= 0 || time() >= (int) $rec['start'] + $drip * DAY_IN_SECONDS ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hide restricted content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function gate( $content ) {
		$id = get_the_ID();
		if ( ! $id || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( self::can_view( $id, get_current_user_id() ) ) {
			return $content;
		}
		return $this->blocked_message( $id );
	}

	/**
	 * What a visitor without access sees.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	protected function blocked_message( $post_id ) {
		$msg  = '' !== trim( (string) setting( 'mem_message', '' ) ) ? (string) setting( 'mem_message', '' ) : __( 'This content is for members.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		$drip = (int) get_post_meta( $post_id, '_sidccofo_drip_days', true );
		$out  = '<div class="sc-members-only"><p><strong>' . esc_html( $msg ) . '</strong></p>';
		if ( $drip > 0 && is_user_logged_in() ) {
			$out .= '<p>' . esc_html( sprintf( /* translators: %d: days. */ __( 'It opens %d days after you join.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $drip ) ) . '</p>';
		}
		if ( ! is_user_logged_in() ) {
			$out .= '<p><a class="sc-btn" href="' . esc_url( wp_login_url( (string) get_permalink( $post_id ) ) ) . '">' . esc_html__( 'Log in', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></p>';
		}
		$plans = self::plans();
		foreach ( array_filter( (array) get_post_meta( $post_id, '_sidccofo_members_only', true ) ) as $slug ) {
			if ( isset( $plans[ $slug ] ) && $plans[ $slug ]['products'] ) {
				$out .= '<p><a class="sc-btn sc-btn--primary" href="' . esc_url( (string) get_permalink( $plans[ $slug ]['products'][0] ) ) . '">' . esc_html( sprintf( /* translators: %s: plan. */ __( 'Join: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $plans[ $slug ]['name'] ) ) . '</a></p>';
			}
		}
		return $out . '</div>';
	}

	/**
	 * [sidccofo_member_only plan="gold"]…[/sidccofo_member_only]
	 *
	 * @param array  $atts    Attributes.
	 * @param string $content Content.
	 * @return string
	 */
	public function shortcode( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'plan' => '' ), $atts, 'sidccofo_member_only' );
		$uid  = get_current_user_id();
		$ok   = '' === $atts['plan'] ? ! empty( self::of( $uid ) ) : (bool) self::holds( $uid, sanitize_key( $atts['plan'] ) );
		return $ok ? do_shortcode( $content ) : '<div class="sc-members-only"><p>' . esc_html( '' !== trim( (string) setting( 'mem_message', '' ) ) ? (string) setting( 'mem_message', '' ) : __( 'This content is for members.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) . '</p></div>';
	}

	/**
	 * [sidccofo_membership]: the member's plans and dates.
	 *
	 * @return string
	 */
	public function status_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Log in to see your membership.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		}
		$plans = self::plans();
		$rows  = '';
		foreach ( self::of( get_current_user_id() ) as $slug => $rec ) {
			if ( ! self::holds( get_current_user_id(), $slug ) ) {
				continue;
			}
			$rows .= '<li>' . esc_html( $plans[ $slug ]['name'] ?? $slug ) . ' · ' . ( 0 === (int) $rec['expires'] ? esc_html__( 'no end date', 'sidcraft-commerce-for-sidcraft-page-builder' ) : esc_html( sprintf( /* translators: %s: date. */ __( 'until %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_date( get_option( 'date_format' ), (int) $rec['expires'] ) ) ) ) . '</li>';
		}
		return '' === $rows ? '<p>' . esc_html__( 'You have no active membership.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>' : '<ul class="sc-membership">' . $rows . '</ul>';
	}

	/**
	 * Edit-screen box on pages, posts and products.
	 */
	public function box() {
		foreach ( array( 'page', 'post', Post_Types::PRODUCT ) as $type ) {
			add_meta_box( 'sidccofo-members', __( 'Members only', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_box' ), $type, 'side' );
		}
	}

	/**
	 * Box contents.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_box( $post ) {
		$plans = self::plans();
		wp_nonce_field( 'sidccofo_members', '_sidccofo_mem' );
		if ( ! $plans ) {
			echo '<p>' . esc_html__( 'Add a plan under Store → Settings → Store tools → Memberships first.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
			return;
		}
		$have = array_filter( (array) get_post_meta( $post->ID, '_sidccofo_members_only', true ) );
		foreach ( $plans as $slug => $plan ) {
			echo '<p><label><input type="checkbox" name="sidccofo_members_only[]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, $have, true ), true, false ) . '> ' . esc_html( $plan['name'] ) . '</label></p>';
		}
		echo '<p><label>' . esc_html__( 'Release after (days)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '<br><input type="number" min="0" class="widefat" name="sidccofo_drip_days" value="' . esc_attr( (string) (int) get_post_meta( $post->ID, '_sidccofo_drip_days', true ) ) . '"></label></p>';
	}

	/**
	 * Save the box.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 */
	public function save( $post_id, $post ) {
		unset( $post );
		if ( ! isset( $_POST['_sidccofo_mem'] ) || ! current_user_can( 'edit_post', $post_id ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_mem'] ) ), 'sidccofo_members' ) ) {
			return;
		}
		$valid = array_keys( self::plans() );
		$picked = isset( $_POST['sidccofo_members_only'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['sidccofo_members_only'] ) ), $valid ) ) : array();
		update_post_meta( $post_id, '_sidccofo_members_only', $picked );
		update_post_meta( $post_id, '_sidccofo_drip_days', isset( $_POST['sidccofo_drip_days'] ) ? absint( $_POST['sidccofo_drip_days'] ) : 0 );
	}
}
