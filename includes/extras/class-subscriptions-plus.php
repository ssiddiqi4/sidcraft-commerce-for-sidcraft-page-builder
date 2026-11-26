<?php
/**
 * Subscription extras: pause, switch plan with a proration credit, and
 * reminders before a renewal.
 *
 * - Pause: the customer skips renewals for 1 month (or 1, 2 or 3 renewals'
 *   worth of days you allow): the next payment date moves forward.
 * - Switch: the customer picks another plan from the list you set; the unused
 *   part of the paid period becomes a credit taken off the next renewal.
 * - Reminder: an email N days before each automatic renewal.
 *
 * Failed renewals already retry and chase the customer with the grace period
 * under Subscriptions; this adds the pieces around it.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Product;
use SidcraftCommerce\Data\Subscription;
use SidcraftCommerce\Subscriptions;
use function SidcraftCommerce\format_in;
use function SidcraftCommerce\round_amount;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Subscriptions_Plus
 */
class Subscriptions_Plus extends Tool {

	const ACTION = 'sidccofo_subscription_plus';

	/** {@inheritDoc} */
	public function hooks() {
		$this->settings(
			'subs_plus',
			__( 'Subscription extras', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'sp_pause_days' => array( __( 'Customers can pause for (days)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'The next payment moves by this many days. 0 = no pausing.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'sp_switch_to'  => array( __( 'Plans customers can switch to (product ids)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Subscription products, separated by commas. Empty = no switching.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'sp_remind'     => array( __( 'Remind before renewal (days)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Email the customer this many days before an automatic renewal. 0 = off.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			)
		);
		add_action( 'sidcraft_commerce_subscription_actions', array( $this, 'buttons' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'sidcraft_commerce_cleanup', array( $this, 'remind' ) );
	}

	/**
	 * Buttons under a subscription in My Subscriptions.
	 *
	 * @param Subscription $sub Subscription.
	 */
	public function buttons( $sub ) {
		if ( 'active' !== $sub->get_status() || ! $sub->get_next_payment() ) {
			return;
		}
		$days = (int) setting( 'sp_pause_days', 0 );
		$form = static function ( $do, $label, $extra = '' ) use ( $sub ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sc-subplus"><input type="hidden" name="action" value="' . esc_attr( Subscriptions_Plus::ACTION ) . '"><input type="hidden" name="subscription" value="' . esc_attr( (string) $sub->get_id() ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped here.
			wp_nonce_field( Subscriptions_Plus::ACTION . '_' . $sub->get_id() );
			echo $extra . '<button type="submit" class="sc-btn">' . esc_html( $label ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $extra is escaped by the caller.
		};
		if ( $days > 0 ) {
			/* translators: %d: days. */
			$form( 'pause', sprintf( __( 'Pause %d days', 'sidcraft-commerce-for-sidcraft-page-builder' ), $days ) );
		}
		$ids = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) setting( 'sp_switch_to', '' ) ) ) );
		$cur = (int) $sub->get_item()['product_id'];
		$opt = '';
		foreach ( $ids as $id ) {
			$p = Product::get( $id );
			if ( $p && $p->is_subscription() && $id !== $cur ) {
				$opt .= '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . '</option>';
			}
		}
		if ( '' !== $opt ) {
			$form( 'switch', __( 'Switch plan', 'sidcraft-commerce-for-sidcraft-page-builder' ), '<select name="plan" aria-label="' . esc_attr__( 'New plan', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '">' . $opt . '</select> ' );
		}
	}

	/**
	 * Handle a pause or switch.
	 */
	public function handle() {
		$id  = absint( $_POST['subscription'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
		$do  = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
		$sub = Subscription::get( $id );
		if ( ! $sub || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::ACTION . '_' . $id ) || ! get_current_user_id() || $sub->get_customer_id() !== get_current_user_id() ) {
			wp_die( esc_html__( 'This link has expired. Go back, refresh the page and try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		if ( 'pause' === $do ) {
			self::pause( $sub, (int) setting( 'sp_pause_days', 0 ) );
		} elseif ( 'switch' === $do ) {
			self::switch_plan( $sub, absint( $_POST['plan'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
		exit;
	}

	/**
	 * Move the next payment (and the end date, if any) forward.
	 *
	 * @param Subscription $sub  Subscription.
	 * @param int          $days Days.
	 * @return bool
	 */
	public static function pause( Subscription $sub, $days ) {
		if ( $days < 1 || 'active' !== $sub->get_status() || ! $sub->get_next_payment() ) {
			return false;
		}
		$sub->set( '_sidccofo_sub_next', $sub->get_next_payment() + $days * DAY_IN_SECONDS );
		if ( $sub->get_end() ) {
			$sub->set( '_sidccofo_sub_end', $sub->get_end() + $days * DAY_IN_SECONDS );
		}
		/* translators: 1: days, 2: date. */
		$sub->add_note( sprintf( __( 'Paused by the customer for %1$d days. Next payment: %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $days, Subscriptions::date( $sub->get_next_payment() ) ) );
		return true;
	}

	/**
	 * Change the plan; the unused part of the paid period becomes a credit.
	 *
	 * @param Subscription $sub        Subscription.
	 * @param int          $product_id New plan.
	 * @return bool
	 */
	public static function switch_plan( Subscription $sub, $product_id ) {
		$allowed = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) setting( 'sp_switch_to', '' ) ) ) );
		$new     = Product::get( $product_id );
		if ( ! $new || ! in_array( $product_id, $allowed, true ) || ! $new->is_subscription() || 'active' !== $sub->get_status() || ! $sub->get_next_payment() ) {
			return false;
		}
		$terms = Subscriptions::product_terms( $new );
		$old   = $sub->get_terms();
		$item  = $sub->get_item();

		// Credit: the share of the period still unused, at the old price.
		$length       = max( 1, Subscriptions::add_period( $sub->get_next_payment(), $old['period'], (int) $old['interval'] ) - $sub->get_next_payment() );
		$left         = max( 0, $sub->get_next_payment() - time() );
		$credit       = round_amount( $sub->get_recurring_subtotal() * min( 1.0, $left / $length ) );

		$item['product_id'] = $new->get_id();
		$item['name']       = wp_strip_all_tags( $new->get_name() );
		$item['sku']        = (string) $new->get_sku();
		$item['price']      = (float) \SidcraftCommerce\Currencies::using(
			$sub->get_currency(),
			static function () use ( $new ) {
				return $new->get_price();
			}
		);
		$sub->set( '_sidccofo_sub_item', $item );
		$sub->set( '_sidccofo_sub_period', $terms['period'] );
		$sub->set( '_sidccofo_sub_interval', $terms['interval'] );
		if ( $credit > 0 ) {
			update_post_meta( $sub->get_id(), '_sidccofo_sub_credit', $credit );
		}
		/* translators: 1: plan, 2: credit. */
		$sub->add_note( sprintf( __( 'Switched to %1$s. Credit for the unused part of the period: %2$s, taken off the next renewal.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $item['name'], format_in( $credit, $sub->get_currency() ) ) );
		return true;
	}

	/**
	 * Email customers about a renewal coming up.
	 */
	public function remind() {
		$days = (int) setting( 'sp_remind', 0 );
		if ( $days < 1 ) {
			return;
		}
		$now = time();
		foreach ( Subscription::find( array( 'active' ), '_sidccofo_sub_next', $now + $days * DAY_IN_SECONDS, 100 ) as $id ) {
			$sub = Subscription::get( $id );
			if ( ! $sub || $sub->get_next_payment() <= $now || (int) get_post_meta( $id, '_sidccofo_sub_reminded', true ) === $sub->get_next_payment() || ! is_email( $sub->get_email() ) ) {
				continue;
			}
			update_post_meta( $id, '_sidccofo_sub_reminded', $sub->get_next_payment() );
			wp_mail(
				$sub->get_email(),
				/* translators: %s: subscription name. */
				sprintf( __( 'Your %s renews soon', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sub->get_name() ),
				/* translators: 1: name, 2: amount, 3: date. */
				sprintf( __( "Your subscription “%1\$s” renews on %3\$s for %2\$s.\n\nYou can cancel, pause or switch plan from your account before then.", 'sidcraft-commerce-for-sidcraft-page-builder' ), $sub->get_name(), format_in( $sub->get_recurring_subtotal(), $sub->get_currency() ), Subscriptions::date( $sub->get_next_payment() ) )
			);
		}
	}
}
