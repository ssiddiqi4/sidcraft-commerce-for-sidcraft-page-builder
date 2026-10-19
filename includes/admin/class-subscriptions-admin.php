<?php
/**
 * Subscriptions in the admin: the product fields, Store → Subscriptions
 * (list and detail screen), the link on orders and the settings section.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Admin;

use SidcraftCommerce\Checkout\Checkout;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Subscription;
use SidcraftCommerce\Subscriptions;
use function SidcraftCommerce\format_in;
use function SidcraftCommerce\manage_cap;

defined( 'ABSPATH' ) || exit;

/**
 * Class Subscriptions_Admin
 */
class Subscriptions_Admin {

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_filter( 'manage_' . Post_Types::SUBSCRIPTION . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . Post_Types::SUBSCRIPTION . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes_' . Post_Types::SUBSCRIPTION, array( $this, 'boxes' ) );
		add_action( 'save_post_' . Post_Types::SUBSCRIPTION, array( $this, 'save' ) );
		add_action( 'add_meta_boxes_' . Post_Types::ORDER, array( $this, 'order_box' ) );
		add_filter( 'sidcraft_commerce_settings_sections', array( $this, 'settings' ) );
	}

	/*
	------------------------------------------------------------------
	 * Product screen
	 * ----------------------------------------------------------------
	 */

	/**
	 * Subscription fields on the product screen.
	 *
	 * @param \WP_Post $post Product.
	 */
	public static function render_product_section( $post ) {
		$m     = static function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};
		$terms = Subscriptions::normalize_terms(
			array(
				'period'   => $m( '_sidccofo_sub_period' ),
				'interval' => $m( '_sidccofo_sub_interval' ),
				'length'   => $m( '_sidccofo_sub_length' ),
				'trial'    => $m( '_sidccofo_sub_trial' ),
			)
		);
		?>
		<h4 class="sc-pd__heading"><?php esc_html_e( 'Subscription', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd">
			<p class="sc-pd__wide"><label class="sc-pd__check"><input type="checkbox" name="sidccofo_subscription" value="1" <?php checked( (bool) $m( '_sidccofo_subscription' ) ); ?>> <?php esc_html_e( 'Bill this product every period (the price above is charged each time)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
			<p><label for="sc-sub-interval"><?php esc_html_e( 'Bill every', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<span style="display:flex;gap:6px">
					<input type="number" min="1" max="365" step="1" id="sc-sub-interval" name="sidccofo_sub_interval" value="<?php echo esc_attr( (string) $terms['interval'] ); ?>" style="width:5em">
					<select name="sidccofo_sub_period" aria-label="<?php esc_attr_e( 'Period', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
						<?php foreach ( Subscriptions::periods() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $terms['period'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</span></p>
			<p><label for="sc-sub-length"><?php esc_html_e( 'Number of payments', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="number" min="0" max="999" step="1" id="sc-sub-length" name="sidccofo_sub_length" value="<?php echo esc_attr( $terms['length'] ? (string) $terms['length'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Until cancelled', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
				<span class="description"><?php esc_html_e( 'Including the first. Empty: until the customer cancels.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<p><label for="sc-sub-trial"><?php esc_html_e( 'Free trial (days)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="number" min="0" max="365" step="1" id="sc-sub-trial" name="sidccofo_sub_trial" value="<?php echo esc_attr( $terms['trial'] ? (string) $terms['trial'] : '' ); ?>" placeholder="0"></p>
			<p><label for="sc-sub-fee"><?php esc_html_e( 'Sign-up fee', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" inputmode="decimal" id="sc-sub-fee" name="sidccofo_sub_fee" value="<?php echo esc_attr( (string) $m( '_sidccofo_sub_fee' ) ); ?>" placeholder="0">
				<span class="description"><?php esc_html_e( 'Charged once, with the first payment.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
		</div>
		<?php
	}

	/**
	 * Save the subscription fields (nonce checked by the caller).
	 *
	 * @param int $post_id Product.
	 */
	public static function save_product_section( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in Admin::save_product().
		update_post_meta( $post_id, '_sidccofo_subscription', empty( $_POST['sidccofo_subscription'] ) ? 0 : 1 );
		$terms = Subscriptions::normalize_terms(
			array(
				'period'   => sanitize_key( wp_unslash( $_POST['sidccofo_sub_period'] ?? 'month' ) ),
				'interval' => absint( $_POST['sidccofo_sub_interval'] ?? 1 ),
				'length'   => absint( $_POST['sidccofo_sub_length'] ?? 0 ),
				'trial'    => absint( $_POST['sidccofo_sub_trial'] ?? 0 ),
			)
		);
		update_post_meta( $post_id, '_sidccofo_sub_period', $terms['period'] );
		update_post_meta( $post_id, '_sidccofo_sub_interval', $terms['interval'] );
		update_post_meta( $post_id, '_sidccofo_sub_length', $terms['length'] );
		update_post_meta( $post_id, '_sidccofo_sub_trial', $terms['trial'] );
		update_post_meta( $post_id, '_sidccofo_sub_fee', \SidcraftCommerce\parse_price( wp_unslash( $_POST['sidccofo_sub_fee'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by parse_price().
		// phpcs:enable
	}

	/*
	------------------------------------------------------------------
	 * List
	 * ----------------------------------------------------------------
	 */

	/**
	 * Columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function columns( $columns ) {
		return array(
			'cb'           => $columns['cb'] ?? '',
			'title'        => __( 'Subscription', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_customer'  => __( 'Customer', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_product'   => __( 'Product', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_status'    => __( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_recurring' => __( 'Price', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_next'      => __( 'Next payment', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'date'         => __( 'Started', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Subscription.
	 */
	public function column( $column, $post_id ) {
		$sub = Subscription::get( $post_id );
		if ( ! $sub ) {
			return;
		}
		switch ( $column ) {
			case 'sc_customer':
				$billing = $sub->get_billing();
				echo esc_html( trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) ) ) . '<br><small>' . esc_html( $sub->get_email() ) . '</small>';
				break;
			case 'sc_product':
				echo esc_html( $sub->get_name() );
				break;
			case 'sc_status':
				echo esc_html( $sub->get_status_label() );
				break;
			case 'sc_recurring':
				echo esc_html( Subscriptions::recurring_text( $sub ) );
				break;
			case 'sc_next':
				echo esc_html( in_array( $sub->get_status(), array( 'active', 'on-hold' ), true ) ? Subscriptions::date( $sub->get_next_payment() ) : '—' );
				break;
		}
	}

	/**
	 * No Quick Edit or Trash link: subscriptions are cancelled, not deleted.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( Post_Types::SUBSCRIPTION === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'], $actions['trash'] );
		}
		return $actions;
	}

	/*
	------------------------------------------------------------------
	 * Detail screen
	 * ----------------------------------------------------------------
	 */

	/**
	 * Meta boxes.
	 */
	public function boxes() {
		remove_meta_box( 'submitdiv', Post_Types::SUBSCRIPTION, 'side' );
		remove_meta_box( 'slugdiv', Post_Types::SUBSCRIPTION, 'normal' );
		add_meta_box( 'sidccofo-sub-details', __( 'Subscription', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_details' ), Post_Types::SUBSCRIPTION, 'normal', 'high' );
		add_meta_box( 'sidccofo-sub-orders', __( 'Orders', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_orders' ), Post_Types::SUBSCRIPTION, 'normal' );
		add_meta_box( 'sidccofo-sub-actions', __( 'Manage', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_actions' ), Post_Types::SUBSCRIPTION, 'side', 'high' );
		add_meta_box( 'sidccofo-sub-notes', __( 'History', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_notes' ), Post_Types::SUBSCRIPTION, 'side' );
	}

	/**
	 * Details.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_details( $post ) {
		$sub     = Subscription::get( $post->ID );
		$billing = $sub->get_billing();
		$terms   = $sub->get_terms();
		$left    = $sub->get_payments_left();
		$gateway = Checkout::gateway( $sub->get_gateway_id() );
		$user    = get_userdata( $sub->get_customer_id() );
		$rows    = array(
			__( 'Product', 'sidcraft-commerce-for-sidcraft-page-builder' )      => esc_html( $sub->get_name() ) . \SidcraftCommerce\item_options_html( $sub->get_item()['options'] ),
			__( 'Price', 'sidcraft-commerce-for-sidcraft-page-builder' )        => esc_html( Subscriptions::recurring_text( $sub ) ) . '<br><small>' . esc_html__( 'Before shipping and tax.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</small>',
			__( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' )       => esc_html( $sub->get_status_label() ),
			__( 'Customer', 'sidcraft-commerce-for-sidcraft-page-builder' )     => esc_html( trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) ) ) . ' · ' . esc_html( $sub->get_email() ) . ( $user ? ' · <a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->user_login ) . '</a>' : '' ),
			__( 'Payments made', 'sidcraft-commerce-for-sidcraft-page-builder' ) => esc_html( null === $left ? (string) $sub->get_paid_count() : sprintf( /* translators: 1: paid, 2: total. */ __( '%1$d of %2$d', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sub->get_paid_count(), $terms['length'] ) ),
			__( 'Next payment', 'sidcraft-commerce-for-sidcraft-page-builder' ) => esc_html( Subscriptions::date( $sub->get_next_payment() ) ),
			__( 'Ends', 'sidcraft-commerce-for-sidcraft-page-builder' )         => esc_html( Subscriptions::date( $sub->get_end() ) ),
			__( 'Trial ends', 'sidcraft-commerce-for-sidcraft-page-builder' )   => esc_html( Subscriptions::date( $sub->get_trial_end() ) ),
			__( 'Renewals paid by', 'sidcraft-commerce-for-sidcraft-page-builder' ) => esc_html( $gateway && $sub->get_token() ? sprintf( /* translators: %s: payment method. */ __( '%s, charged automatically', 'sidcraft-commerce-for-sidcraft-page-builder' ), $gateway->get_title() ) : __( 'Link emailed to the customer', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:30%">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * First order and renewals.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_orders( $post ) {
		$sub = Subscription::get( $post->ID );
		$ids = array_merge( array( $sub->get_parent_id() ), array_reverse( $sub->get_renewal_ids() ) );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Type', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Date', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th style="text-align:right">' . esc_html__( 'Total', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$order = Order::get( $id );
			if ( ! $order ) {
				continue;
			}
			printf(
				'<tr><td><a href="%1$s">#%2$d</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td style="text-align:right">%6$s</td></tr>',
				esc_url( (string) get_edit_post_link( $id ) ),
				(int) $id,
				esc_html( $id === $sub->get_parent_id() ? __( 'First order', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Renewal', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				esc_html( $order->get_date() ),
				esc_html( $order->get_status_label() ),
				esc_html( format_in( $order->get_total(), $order->get_currency() ) )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Status, next payment, price and "renew now".
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_actions( $post ) {
		$sub    = Subscription::get( $post->ID );
		$status = $sub->get_status();
		wp_nonce_field( 'sidccofo_save_subscription', '_sidccofo_sub_nonce' );
		$choices = array(
			'active'         => __( 'Active', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'on-hold'        => __( 'Payment due (on hold)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'pending-cancel' => __( 'Cancel at period end', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'cancelled'      => __( 'Cancel now', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
		if ( ! isset( $choices[ $status ] ) ) {
			$choices = array( $status => $sub->get_status_label() ) + $choices;
		}
		echo '<p><label for="sc-sub-status"><strong>' . esc_html__( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</strong></label><select id="sc-sub-status" name="sidccofo_sub_status" style="width:100%">';
		foreach ( $choices as $key => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( $status, $key, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		// Every payment made: the date is when it ends, not another payment.
		$done = 0 === $sub->get_payments_left();
		$next = $done ? $sub->get_end() : $sub->get_next_payment();
		echo '<p><label for="sc-sub-next"><strong>' . esc_html( $done ? __( 'Ends', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Next payment', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) . '</strong></label><input type="date" id="sc-sub-next" name="sidccofo_sub_next" value="' . esc_attr( $next ? wp_date( 'Y-m-d', $next ) : '' ) . '" style="width:100%"></p>';
		$item = $sub->get_item();
		/* translators: %s: currency code. */
		echo '<p><label for="sc-sub-price"><strong>' . esc_html( sprintf( __( 'Price per unit each period (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sub->get_currency() ) ) . '</strong></label><input type="text" inputmode="decimal" id="sc-sub-price" name="sidccofo_sub_price" value="' . esc_attr( (string) $item['price'] ) . '" style="width:100%"></p>';
		if ( in_array( $status, array( 'active', 'on-hold' ), true ) && ! $sub->get_pending_renewal() ) {
			echo '<p><label><input type="checkbox" name="sidccofo_sub_renew_now" value="1"> ' . esc_html__( 'Create the renewal order now', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label></p>';
		}
		if ( $sub->get_token() ) {
			echo '<p><label><input type="checkbox" name="sidccofo_sub_forget_card" value="1"> ' . esc_html__( 'Stop charging the saved payment method (email a link instead)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label></p>';
		}
		submit_button( __( 'Update subscription', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'primary', 'save', false );
	}

	/**
	 * History.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_notes( $post ) {
		$sub = Subscription::get( $post->ID );
		echo '<ul style="margin:0">';
		foreach ( array_reverse( $sub->get_notes() ) as $note ) {
			printf(
				'<li style="border-bottom:1px solid #eee;padding:6px 0"><span>%1$s</span><br><small style="color:#757575">%2$s</small></li>',
				esc_html( $note['note'] ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $note['time'] ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Save the Manage box.
	 *
	 * @param int $post_id Subscription.
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST['_sidccofo_sub_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_sub_nonce'] ) ), 'sidccofo_save_subscription' ) || ! current_user_can( manage_cap() ) ) {
			return;
		}
		$sub = Subscription::get( $post_id );
		if ( ! $sub ) {
			return;
		}
		$admin = wp_get_current_user();
		/* translators: %s: user name. */
		$by = sprintf( __( 'the store (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $admin->display_name );

		$item  = $sub->get_item();
		$price = \SidcraftCommerce\parse_price( wp_unslash( $_POST['sidccofo_sub_price'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by parse_price().
		if ( '' !== $price && abs( (float) $price - (float) $item['price'] ) > 0.000001 ) {
			$old           = (float) $item['price'];
			$item['price'] = (float) $price;
			$sub->set( '_sidccofo_sub_item', $item );
			/* translators: 1: old price, 2: new price, 3: user. */
			$sub->add_note( sprintf( __( 'Price per unit changed from %1$s to %2$s by %3$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_in( $old, $sub->get_currency() ), format_in( (float) $price, $sub->get_currency() ), $admin->display_name ) );
		}

		$date = sanitize_text_field( wp_unslash( $_POST['sidccofo_sub_next'] ?? '' ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && in_array( $sub->get_status(), array( 'active', 'on-hold', 'pending-cancel' ), true ) ) {
			$done    = 0 === $sub->get_payments_left();
			$current = $done ? $sub->get_end() : $sub->get_next_payment();
			if ( ! $current || wp_date( 'Y-m-d', $current ) !== $date ) {
				$time = ( new \DateTimeImmutable( $date . ' ' . ( $current ? wp_date( 'H:i:s', $current ) : '00:00:00' ), wp_timezone() ) )->getTimestamp();
				if ( $done ) {
					$sub->set( '_sidccofo_sub_end', $time );
					/* translators: 1: date, 2: user. */
					$sub->add_note( sprintf( __( 'End moved to %1$s by %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), Subscriptions::date( $time ), $admin->display_name ) );
				} else {
					$sub->set( '_sidccofo_sub_next', $time );
					$sub->set( '_sidccofo_sub_end', 0 );
					$sub->set( '_sidccofo_sub_anchor_day', (int) wp_date( 'j', $time ) );
					/* translators: 1: date, 2: user. */
					$sub->add_note( sprintf( __( 'Next payment moved to %1$s by %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), Subscriptions::date( $time ), $admin->display_name ) );
				}
			}
		}

		if ( ! empty( $_POST['sidccofo_sub_forget_card'] ) && $sub->get_token() ) {
			$sub->set_token( '', array() );
			/* translators: %s: user. */
			$sub->add_note( sprintf( __( 'Saved payment method removed by %s. Renewals will be paid from an emailed link.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $admin->display_name ) );
		}

		$status = sanitize_key( wp_unslash( $_POST['sidccofo_sub_status'] ?? '' ) );
		if ( $status !== $sub->get_status() ) {
			if ( 'cancelled' === $status ) {
				$renewal = $sub->get_pending_renewal();
				if ( $renewal ) {
					$sub->set( '_sidccofo_sub_renewal', 0 );
					/* translators: %s: who. */
					$renewal->set_status( 'sidccofo-cancelled', sprintf( __( 'Cancelled by %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $by ) );
				}
				/* translators: %s: who. */
				$sub->set_status( 'cancelled', sprintf( __( 'Cancelled by %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $by ) );
			} elseif ( in_array( $status, array( 'active', 'on-hold', 'pending-cancel' ), true ) && 'pending' !== $sub->get_status() ) {
				if ( 'active' === $status && ! $sub->get_next_payment() && ! $sub->get_end() ) {
					// Reactivated without a date: bill one period from now.
					$terms = $sub->get_terms();
					$sub->set( '_sidccofo_sub_next', Subscriptions::add_period( time(), $terms['period'], $terms['interval'] ) );
				}
				/* translators: %s: user. */
				$sub->set_status( $status, sprintf( __( 'Changed by %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $admin->display_name ) );
			}
		}

		if ( ! empty( $_POST['sidccofo_sub_renew_now'] ) && in_array( $sub->get_status(), array( 'active', 'on-hold' ), true ) && ! $sub->get_pending_renewal() ) {
			Subscriptions::renew( $sub );
		}
	}

	/*
	------------------------------------------------------------------
	 * Orders and settings
	 * ----------------------------------------------------------------
	 */

	/**
	 * "Subscription" box on orders that start or renew one.
	 *
	 * @param \WP_Post $post Order.
	 */
	public function order_box( $post ) {
		$order = Order::get( $post->ID );
		if ( $order && Subscriptions::for_order( $order ) ) {
			add_meta_box( 'sidccofo-order-subscriptions', __( 'Subscription', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_order_box' ), Post_Types::ORDER, 'side' );
		}
	}

	/**
	 * Links to the subscriptions.
	 *
	 * @param \WP_Post $post Order.
	 */
	public function render_order_box( $post ) {
		$order = Order::get( $post->ID );
		echo '<p>' . esc_html( Subscriptions::renewal_subscription( $order ) ? __( 'This order renews:', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'This order started:', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) . '</p><ul style="margin:0">';
		foreach ( Subscriptions::for_order( $order ) as $sub ) {
			printf( '<li><a href="%1$s">#%2$d</a> %3$s · %4$s</li>', esc_url( (string) get_edit_post_link( $sub->get_id() ) ), (int) $sub->get_id(), esc_html( $sub->get_name() ), esc_html( $sub->get_status_label() ) );
		}
		echo '</ul>';
	}

	/**
	 * Settings section.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public function settings( $sections ) {
		$sections['subscriptions'] = array(
			'tab'    => 'payments',
			'title'  => __( 'Subscriptions', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'intro'  => __( 'Turn a product into a subscription on its edit screen. When a renewal is due, Stripe (Sidcraft Pro) charges the saved card; otherwise the customer is emailed a link to pay with any payment method. Put the My Subscriptions Unit (or [sidccofo_subscriptions]) on a page so customers can see and cancel theirs.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'fields' => array(
				'subscription_grace_days' => array( __( 'Days to pay a renewal', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'A renewal still unpaid after this many days is cancelled, and so is its subscription.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			),
		);
		return $sections;
	}
}
