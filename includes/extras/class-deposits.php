<?php
/**
 * Deposits and partial payments.
 *
 * The shopper chooses at checkout to pay a share now; the rest is shown as a
 * credit line ("Due later") so the payment gateway charges only the deposit.
 * The order records the balance, the order screen shows it and has a button
 * to mark it paid, and the order email tells the customer what is still due.
 *
 * Settings: deposit mode (all products / only products that allow it),
 * the percent, and the label. A product can allow or set its own percent in
 * Store options on the product.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\format_price;
use function SidcraftCommerce\round_amount;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Deposits
 */
class Deposits extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$this->settings(
			'deposits',
			__( 'Deposits', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'dep_mode'    => array(
					__( 'Offer deposits on', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					array(
						'all'     => __( 'Every order', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'product' => __( 'Only orders whose products allow it', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					),
				),
				'dep_percent' => array( __( 'Deposit', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Percent of the order total paid now (1–99).', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			)
		);
		add_filter( 'sidcraft_commerce_extra_keys', array( $this, 'keys' ) );
		add_filter( 'sidcraft_commerce_cart_fees', array( $this, 'fees' ), 50, 2 );
		add_action( 'sidcraft_commerce_checkout_after_fields', array( $this, 'choice' ), 30 );
		add_action( 'sidcraft_commerce_checkout_input', array( $this, 'input' ), 10, 2 );
		add_action( 'sidcraft_commerce_order_created', array( $this, 'stamp' ) );
		add_action( 'add_meta_boxes_' . Post_Types::PRODUCT, array( $this, 'product_box' ) );
		add_action( 'save_post_' . Post_Types::PRODUCT, array( $this, 'save_product' ) );
		add_action( 'add_meta_boxes_' . Post_Types::ORDER, array( $this, 'order_box' ) );
		add_action( 'admin_post_sidccofo_balance_paid', array( $this, 'balance_paid' ) );
		add_filter( 'sidcraft_commerce_email', array( $this, 'email_note' ), 15, 3 );
	}

	/**
	 * Percent of a cart that is paid now (0 = deposits not available).
	 *
	 * @param Cart $cart Cart.
	 * @return float
	 */
	public static function percent( Cart $cart ) {
		$pct = min( 99.0, max( 0.0, (float) setting( 'dep_percent', 0 ) ) );
		if ( 'product' === setting( 'dep_mode', 'all' ) ) {
			$pct = 0.0;
			foreach ( $cart->get_items() as $line ) {
				$own = (float) get_post_meta( (int) $line['product_id'], '_sidccofo_deposit_percent', true );
				if ( $own > 0 ) {
					$pct = max( $pct, min( 99.0, $own ) );
				}
			}
			if ( $pct <= 0 ) {
				return 0.0;
			}
		}
		return $pct;
	}

	/**
	 * Whether the shopper chose a deposit.
	 *
	 * @param Cart $cart Cart.
	 * @return bool
	 */
	public static function chosen( Cart $cart ) {
		return 'part' === $cart->extra( 'deposit', '' ) && self::percent( $cart ) > 0 && ! $cart->is_empty();
	}

	/**
	 * Whitelist.
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public function keys( $keys ) {
		$keys['deposit'] = static function ( $value, $cart ) {
			return 'part' === $value && Deposits::percent( $cart ) > 0 ? 'part' : '';
		};
		return $keys;
	}

	/**
	 * Use the submitted choice.
	 *
	 * @param array $input Input.
	 * @param Cart  $cart  Cart.
	 */
	public function input( $input, $cart ) {
		if ( isset( $input['deposit'] ) ) {
			$cart->set_extra( 'deposit', 'part' === $input['deposit'] && self::percent( $cart ) > 0 ? 'part' : '' );
		}
	}

	/**
	 * The credit for the part paid later.
	 *
	 * @param array $fees Fees.
	 * @param Cart  $cart Cart.
	 * @return array
	 */
	public function fees( $fees, $cart ) {
		if ( ! self::chosen( $cart ) ) {
			return $fees;
		}
		$total = max( 0.0, $cart->get_subtotal() - $cart->get_discount() ) + $cart->get_shipping() + ( $cart->prices_include_tax() ? 0.0 : $cart->get_tax() );
		foreach ( $fees as $f ) {
			$total += $f['amount'];
		}
		$later = round_amount( $total * ( 100 - self::percent( $cart ) ) / 100 );
		if ( $later > 0 ) {
			$fees[] = array( 'id' => 'deposit_later', 'label' => __( 'Balance due later', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'amount' => -1 * $later );
		}
		return $fees;
	}

	/**
	 * Radio choice at checkout.
	 *
	 * @param Cart $cart Cart.
	 */
	public function choice( $cart ) {
		$pct = self::percent( $cart );
		if ( $pct <= 0 || $cart->is_empty() ) {
			return;
		}
		Extras::enqueue();
		$part = self::chosen( $cart );
		$pct  = rtrim( rtrim( number_format( $pct, 2 ), '0' ), '.' );
		echo '<fieldset class="sc-choice"><legend>' . esc_html__( 'Payment plan', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</legend><label class="sc-choice__opt"><input type="radio" name="deposit" value="full" data-sc-extra="deposit" ' . checked( ! $part, true, false ) . '><span>' . esc_html__( 'Pay in full now', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></label><label class="sc-choice__opt"><input type="radio" name="deposit" value="part" data-sc-extra="deposit" ' . checked( $part, true, false ) . '><span>' . esc_html( sprintf( /* translators: %s: percent. */ __( 'Pay a %s%% deposit now, the rest later', 'sidcraft-commerce-for-sidcraft-page-builder' ), $pct ) ) . '</span></label></fieldset>';
	}

	/**
	 * Record the balance on the order.
	 *
	 * @param Order $order Order.
	 */
	public function stamp( $order ) {
		foreach ( $order->get_fees() as $f ) {
			if ( 'deposit_later' === $f['id'] ) {
				update_post_meta( $order->get_id(), '_sidccofo_balance_due', (string) abs( (float) $f['amount'] ) );
				/* translators: %s: amount. */
				$order->add_note( sprintf( __( 'Deposit order: %s is still to be paid.', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( abs( (float) $f['amount'] ) ) ) );
			}
		}
	}

	/**
	 * Product box.
	 */
	public function product_box() {
		add_meta_box(
			'sidccofo-deposit',
			__( 'Deposit', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			static function ( $post ) {
				wp_nonce_field( 'sidccofo_deposit', '_sidccofo_dep' );
				echo '<p><label>' . esc_html__( 'Deposit % (0 = not offered)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '<br><input type="number" min="0" max="99" step="1" class="widefat" name="sidccofo_deposit_percent" value="' . esc_attr( (string) get_post_meta( $post->ID, '_sidccofo_deposit_percent', true ) ) . '"></label></p>';
			},
			Post_Types::PRODUCT,
			'side'
		);
	}

	/**
	 * Save.
	 *
	 * @param int $post_id Product.
	 */
	public function save_product( $post_id ) {
		if ( ! isset( $_POST['_sidccofo_dep'] ) || ! current_user_can( 'edit_post', $post_id ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_dep'] ) ), 'sidccofo_deposit' ) ) {
			return;
		}
		$v = isset( $_POST['sidccofo_deposit_percent'] ) ? absint( $_POST['sidccofo_deposit_percent'] ) : 0;
		update_post_meta( $post_id, '_sidccofo_deposit_percent', $v > 0 ? (string) min( 99, $v ) : '' );
	}

	/**
	 * Order box.
	 */
	public function order_box() {
		add_meta_box( 'sidccofo-balance', __( 'Balance due', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_order_box' ), Post_Types::ORDER, 'side' );
	}

	/**
	 * Show the balance.
	 *
	 * @param \WP_Post $post Order post.
	 */
	public function render_order_box( $post ) {
		$due = (float) get_post_meta( $post->ID, '_sidccofo_balance_due', true );
		if ( $due <= 0 ) {
			echo '<p>' . esc_html__( 'Nothing is due on this order.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
			return;
		}
		$order = Order::get( $post->ID );
		echo '<p><strong>' . esc_html( \SidcraftCommerce\format_in( $due, $order ? $order->get_currency() : \SidcraftCommerce\currency() ) ) . '</strong> ' . esc_html__( 'is still to be collected.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		$url = wp_nonce_url( add_query_arg( array( 'action' => 'sidccofo_balance_paid', 'order' => $post->ID ), admin_url( 'admin-post.php' ) ), 'sidccofo_balance_' . $post->ID );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Mark balance as paid', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></p>';
	}

	/**
	 * Mark the balance paid.
	 */
	public function balance_paid() {
		$id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		if ( ! $id || ! current_user_can( \SidcraftCommerce\manage_cap() ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'sidccofo_balance_' . $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		$order = Order::get( $id );
		if ( $order ) {
			update_post_meta( $id, '_sidccofo_balance_due', '0' );
			$order->add_note( __( 'The balance was marked as paid.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		wp_safe_redirect( (string) get_edit_post_link( $id, 'raw' ) );
		exit;
	}

	/**
	 * Tell the customer what is still due.
	 *
	 * @param array  $mail      Mail.
	 * @param Order  $order     Order.
	 * @param string $recipient Recipient.
	 * @return array
	 */
	public function email_note( $mail, $order, $recipient ) {
		if ( ! $mail || ! $order instanceof Order || ! isset( $mail[2] ) ) {
			return $mail;
		}
		$due = (float) get_post_meta( $order->get_id(), '_sidccofo_balance_due', true );
		if ( $due <= 0 ) {
			return $mail;
		}
		$box = '<p style="margin:16px 0;padding:12px 16px;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7"><strong>' . esc_html( sprintf( /* translators: %s: amount. */ __( 'Balance still due: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), \SidcraftCommerce\format_in( $due, $order->get_currency() ) ) ) . '</strong>' . ( 'customer' === $recipient ? '<br>' . esc_html__( 'We will contact you about the rest of the payment.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : '' ) . '</p>';
		$mail[2] = false !== stripos( (string) $mail[2], '</body>' ) ? preg_replace( '#</body>#i', $box . '</body>', (string) $mail[2], 1 ) : $mail[2] . $box;
		return $mail;
	}
}
