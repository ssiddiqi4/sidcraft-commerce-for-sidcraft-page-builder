<?php
/**
 * Guest orders and WordPress accounts.
 *
 * Checkout can create an account from the order email. Guest orders placed
 * with the account email (they store _sidccofo_billing_email and customer id
 * 0) are attached once the person has shown the address is theirs: a login
 * with a password, or a password reset, both of which go through that inbox.
 * Creating an account alone attaches nothing, so typing someone else's email
 * at checkout can't reach their orders, downloads, subscriptions or tickets.
 * Accounts made at checkout are marked unverified until then.
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

	/** User meta on accounts made at checkout, until the email is proven. */
	const UNVERIFIED = '_sidccofo_email_unverified';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'wp_login', array( $this, 'claim_on_login' ), 10, 2 );
		add_action( 'after_password_reset', array( $this, 'claim_on_reset' ) );
	}

	/**
	 * Attach guest orders when someone logs in.
	 *
	 * @param string  $user_login Login.
	 * @param \WP_User $user      User.
	 */
	public function claim_on_login( $user_login, $user ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( $user instanceof \WP_User ) {
			delete_user_meta( $user->ID, self::UNVERIFIED );
			$this->claim( $user->ID );
		}
	}

	/**
	 * A password set from the emailed link proves the address too.
	 *
	 * @param \WP_User $user User.
	 */
	public function claim_on_reset( $user ) {
		if ( $user instanceof \WP_User ) {
			delete_user_meta( $user->ID, self::UNVERIFIED );
			$this->claim( $user->ID );
		}
	}

	/**
	 * Has this user shown their account email is theirs? (Accounts made at
	 * checkout haven't, until they log in with a password or reset it.)
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function email_verified( $user_id ) {
		return $user_id > 0 && ! get_user_meta( (int) $user_id, self::UNVERIFIED, true );
	}

	/**
	 * Give this user the guest orders placed with their account email.
	 *
	 * @param int $user_id User ID.
	 */
	public function claim( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! is_email( $user->user_email ) || ! self::email_verified( (int) $user_id ) ) {
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
