<?php
/**
 * Guest orders and WordPress accounts.
 *
 * Checkout can create an account from the order email. A later registration
 * or login with that same account email attaches guest orders from 1.0.9 on
 * (they store _sidccofo_billing_email and customer id 0).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Class Accounts
 */
class Accounts {

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'user_register', array( $this, 'claim' ) );
		add_action( 'wp_login', array( $this, 'claim_on_login' ), 10, 2 );
		add_action( 'sidcraft_commerce_cleanup', array( Order::class, 'release_abandoned' ) );
	}

	/**
	 * Attach guest orders when someone logs in.
	 *
	 * @param string  $user_login Login.
	 * @param \WP_User $user      User.
	 */
	public function claim_on_login( $user_login, $user ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( $user instanceof \WP_User ) {
			$this->claim( $user->ID );
		}
	}

	/**
	 * Give this user the guest orders placed with their account email.
	 *
	 * @param int $user_id User ID.
	 */
	public function claim( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$email = strtolower( $user->user_email );
		$ids   = get_posts(
			array(
				'post_type'      => Post_Types::ORDER,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded claim of this user's guest orders.
					array(
						'key'   => '_sidccofo_billing_email',
						'value' => $email,
					),
					array(
						'key'   => '_sidccofo_customer_id',
						'value' => '0',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$order = Order::get( (int) $id );
			if ( ! $order || strtolower( $order->get_email() ) !== $email ) {
				continue;
			}
			if ( (int) get_post_meta( (int) $id, '_sidccofo_customer_id', true ) > 0 ) {
				continue;
			}
			$order->assign_customer( (int) $user_id );
			$order->add_note(
				sprintf(
					/* translators: %s: account email. */
					__( 'Attached to the account for %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					$email
				)
			);
		}
	}
}
