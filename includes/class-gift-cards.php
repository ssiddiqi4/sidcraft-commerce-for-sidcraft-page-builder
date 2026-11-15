<?php
/**
 * Gift cards: issuing, emailing, balance restores, admin screens and REST.
 *
 *   - An order line bought from a gift card product becomes one card per
 *     unit once the order is paid (Processing / Completed). The recipient
 *     is emailed right away, or on the chosen date by the hourly cron.
 *   - Cards used to pay are debited when the order is placed and credited
 *     back if it is cancelled or refunded.
 *   - If a paid order that issued cards is cancelled or refunded, its cards
 *     are disabled (the order note says so; re-enable them by publishing).
 *   - Store → Gift cards lists every card; "Issue Gift Card" creates one by
 *     hand (store credit, prizes, goodwill).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Gift_Card;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Class Gift_Cards
 */
class Gift_Cards {

	/** Failed code attempts allowed per visitor per hour. */
	const MAX_FAILURES = 10;

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		// Orders placed while gift cards were on still issue, deliver and give
		// back their cards after the module is switched off.
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'on_status' ), 5, 3 );
		add_action( 'sidcraft_commerce_cleanup', array( $this, 'send_due' ) );
		if ( ! Modules::enabled( 'giftcards' ) ) {
			return;
		}
		add_action( 'rest_api_init', array( $this, 'routes' ) );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . Post_Types::GIFTCARD, array( $this, 'meta_box' ) );
			add_action( 'save_post_' . Post_Types::GIFTCARD, array( $this, 'save' ), 10, 2 );
			add_filter( 'wp_insert_post_data', array( $this, 'default_code' ), 10, 2 );
			add_filter( 'manage_' . Post_Types::GIFTCARD . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . Post_Types::GIFTCARD . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
			add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
		}
	}

	/*
	------------------------------------------------------------------
	 * Orders
	 * ----------------------------------------------------------------
	 */

	/**
	 * Issue cards when paid; restore / disable on cancel or refund.
	 *
	 * @param Order  $order      Order.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 */
	public function on_status( Order $order, $old_status, $new_status ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		if ( in_array( $new_status, \SidcraftCommerce\Data\Post_Types::paid_statuses(), true ) ) {
			$this->issue_for_order( $order );
		} elseif ( in_array( $new_status, array( 'sidccofo-cancelled', 'sidccofo-refunded' ), true ) ) {
			$this->restore_used( $order );
			$this->disable_issued( $order );
		}
	}

	/**
	 * Create the cards an order bought (once).
	 *
	 * @param Order $order Order.
	 */
	public function issue_for_order( Order $order ) {
		if ( get_post_meta( $order->get_id(), '_sidccofo_gift_cards_issued', true ) ) {
			return;
		}
		$issued = array();
		foreach ( $order->get_items() as $item ) {
			if ( empty( $item['gift_card'] ) || ! is_array( $item['gift_card'] ) ) {
				continue;
			}
			$gift = $item['gift_card'];
			for ( $i = 0; $i < max( 1, (int) $item['quantity'] ); $i++ ) {
				$card = Gift_Card::create(
					array(
						'amount'          => $gift['amount'],
						'recipient_name'  => $gift['recipient_name'] ?? '',
						'recipient_email' => $gift['recipient_email'] ?? '',
						'sender'          => $gift['sender'] ?? '',
						'message'         => $gift['message'] ?? '',
						'send_date'       => $gift['send_date'] ?? '',
						'order'           => $order->get_id(),
						/* translators: %d: order number. */
						'note'            => sprintf( __( 'Bought with order #%d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ),
					)
				);
				if ( is_wp_error( $card ) ) {
					$order->add_note( 'Gift card: ' . $card->get_error_message() );
					continue;
				}
				$issued[] = $card->get_id();
				if ( $card->is_due() ) {
					self::send( $card );
				}
			}
		}
		if ( $issued ) {
			update_post_meta( $order->get_id(), '_sidccofo_gift_cards_issued', $issued );
			/* translators: %d: number of gift cards. */
			$order->add_note( sprintf( _n( '%d gift card issued.', '%d gift cards issued.', count( $issued ), 'sidcraft-commerce-for-sidcraft-page-builder' ), count( $issued ) ) );
		}
	}

	/**
	 * Give back what this order took from gift cards (once).
	 *
	 * @param Order $order Order.
	 */
	public function restore_used( Order $order ) {
		$used = $order->get_gift_cards_used();
		if ( ! $used || get_post_meta( $order->get_id(), '_sidccofo_gift_cards_restored', true ) ) {
			return;
		}
		foreach ( $used as $row ) {
			$card = Gift_Card::get( (int) ( $row['id'] ?? 0 ) );
			if ( $card ) {
				/* translators: %d: order number. */
				// 'base': what was taken from the card (store currency); older orders only have 'amount'.
				$card->credit( (float) ( $row['base'] ?? $row['amount'] ), $order->get_id(), sprintf( __( 'Returned: order #%d was cancelled or refunded.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
			}
		}
		update_post_meta( $order->get_id(), '_sidccofo_gift_cards_restored', 1 );
		$order->add_note( __( 'Gift card amounts returned to the cards.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
	}

	/**
	 * Disable cards this order bought (cancelled / refunded after paying).
	 *
	 * @param Order $order Order.
	 */
	public function disable_issued( Order $order ) {
		$issued = get_post_meta( $order->get_id(), '_sidccofo_gift_cards_issued', true );
		if ( ! is_array( $issued ) || ! $issued ) {
			return;
		}
		$disabled = 0;
		foreach ( $issued as $id ) {
			$card = Gift_Card::get( (int) $id );
			if ( $card && $card->is_active() ) {
				wp_update_post(
					array(
						'ID'          => $card->get_id(),
						'post_status' => 'draft',
					)
				);
				/* translators: %d: order number. */
				$card->log( 0, $order->get_id(), sprintf( __( 'Disabled: order #%d was cancelled or refunded.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ) );
				++$disabled;
			}
		}
		if ( $disabled ) {
			/* translators: %d: number of cards. */
			$order->add_note( sprintf( _n( '%d gift card from this order was disabled. Publish it again under Store → Gift cards to re-enable.', '%d gift cards from this order were disabled. Publish them again under Store → Gift cards to re-enable.', $disabled, 'sidcraft-commerce-for-sidcraft-page-builder' ), $disabled ) );
		}
	}

	/**
	 * Hourly: email cards whose send date has arrived.
	 */
	public function send_due() {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::GIFTCARD,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small, indexed by the cron cadence.
					array(
						'key'   => '_sidccofo_gc_sent',
						'value' => '0',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$card = Gift_Card::get( $id );
			if ( $card && $card->is_due() ) {
				self::send( $card );
			}
		}
	}

	/**
	 * Email a card to its recipient.
	 *
	 * @param Gift_Card $card Card.
	 * @return bool Sent.
	 */
	public static function send( Gift_Card $card ) {
		// In the language the card was bought in.
		$order = Order::get( (int) get_post_meta( $card->get_id(), '_sidccofo_gc_order', true ) );
		if ( $order ) {
			return \SidcraftCommerce\Multilingual::for_order(
				$order,
				static function () use ( $card ) {
					return self::send_now( $card );
				}
			);
		}
		return self::send_now( $card );
	}

	/**
	 * send() in the current language.
	 *
	 * @param Gift_Card $card Card.
	 * @return bool
	 */
	protected static function send_now( Gift_Card $card ) {
		$to = (string) $card->meta( 'recipient_email' );
		if ( ! is_email( $to ) ) {
			return false;
		}
		$sender = (string) $card->meta( 'sender' );
		$store  = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$heading = '' !== $sender
			/* translators: 1: sender name, 2: store name. */
			? sprintf( __( '%1$s sent you a %2$s gift card', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sender, $store )
			/* translators: %s: store name. */
			: sprintf( __( 'You’ve received a %s gift card', 'sidcraft-commerce-for-sidcraft-page-builder' ), $store );
		$body    = Plugin::instance()->templates->render(
			'emails/gift-card.php',
			array(
				'card'    => $card,
				'heading' => $heading,
			)
		);
		$from    = sanitize_text_field( (string) setting( 'from_name', $store ) );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from . ' <' . get_option( 'admin_email' ) . '>',
		);

		/**
		 * Filter the gift card email; return false to skip it.
		 *
		 * @param array|false $mail [ to, subject, body, headers ].
		 * @param Gift_Card   $card Card.
		 */
		$mail = apply_filters( 'sidcraft_commerce_gift_card_email', array( $to, $heading, $body, $headers ), $card );
		if ( ! $mail || ! wp_mail( $mail[0], $mail[1], $mail[2], $mail[3] ) ) {
			return false;
		}
		update_post_meta( $card->get_id(), '_sidccofo_gc_sent', time() );
		/* translators: %s: email address. */
		$card->log( 0, 0, sprintf( __( 'Emailed to %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $to ) );
		return true;
	}

	/*
	------------------------------------------------------------------
	 * REST: buying and redeeming
	 * ----------------------------------------------------------------
	 */

	/**
	 * Routes: cart/gift-card (add a gift card product), cart/redeem
	 * (apply a code), cart/redeem/remove.
	 */
	public function routes() {
		$public  = '__return_true'; // Cart actions are per-visitor and public, like the other cart routes.
		$context = array(
			'type'    => 'string',
			'default' => '',
			'enum'    => array( '', 'cart', 'checkout' ),
		);
		register_rest_route(
			Rest::NS,
			'/cart/gift-card',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public,
				'callback'            => array( $this, 'rest_add' ),
				'args'                => array(
					'product_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			Rest::NS,
			'/cart/redeem',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public,
				'callback'            => array( $this, 'rest_redeem' ),
				'args'                => array(
					'code'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context' => $context,
				),
			)
		);
		register_rest_route(
			Rest::NS,
			'/cart/redeem/remove',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public,
				'callback'            => array( $this, 'rest_unredeem' ),
				'args'                => array(
					'code'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'context' => $context,
				),
			)
		);
	}

	/**
	 * Cart payload (fragments etc.).
	 *
	 * @param string $context Context.
	 * @return array
	 */
	protected function payload( $context = '' ) {
		$cart = Cart::instance();
		return array(
			'fragments' => Fragments::collect( $context ),
			'cart_hash' => $cart->get_hash(),
			'count'     => $cart->get_count(),
			'total'     => $cart->get_total(),
		);
	}

	/**
	 * POST cart/gift-card.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_add( \WP_REST_Request $request ) {
		$result = Cart::instance()->add_gift_card( (int) $request['product_id'], (array) $request->get_params() );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array_merge( (array) $result->get_error_data(), array( 'status' => 400 ) ) );
			return $result;
		}
		$data            = $this->payload();
		$data['message'] = __( 'Gift card added to your cart.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		return rest_ensure_response( $data );
	}

	/**
	 * Rate-limit key for this visitor.
	 *
	 * @return string
	 */
	protected static function client_key() {
		return client_key( 'gc_fail' );
	}

	/**
	 * POST cart/redeem.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_redeem( \WP_REST_Request $request ) {
		$key = self::client_key();
		if ( (int) get_transient( $key ) >= self::MAX_FAILURES ) {
			$data            = $this->payload( (string) $request['context'] );
			$data['message'] = __( 'Too many attempts. Please try again in an hour.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			return new \WP_REST_Response( $data, 429 );
		}
		$result = Cart::instance()->apply_gift_card( (string) $request['code'] );
		$data   = $this->payload( (string) $request['context'] );
		if ( is_wp_error( $result ) ) {
			if ( 'sidccofo_giftcard_invalid' === $result->get_error_code() ) {
				set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
			}
			$data['message'] = $result->get_error_message();
			return new \WP_REST_Response( $data, 400 );
		}
		$data['message'] = sprintf(
			/* translators: 1: masked code, 2: balance. */
			__( 'Gift card %1$s applied (balance %2$s).', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			$result->get_masked_code(),
			format_price( \SidcraftCommerce\money( $result->get_balance() ) )
		);
		return rest_ensure_response( $data );
	}

	/**
	 * POST cart/redeem/remove.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_unredeem( \WP_REST_Request $request ) {
		Cart::instance()->remove_gift_card( (string) $request['code'] );
		$data            = $this->payload( (string) $request['context'] );
		$data['message'] = __( 'Gift card removed.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		return rest_ensure_response( $data );
	}

	/*
	------------------------------------------------------------------
	 * Admin: Store → Gift cards
	 * ----------------------------------------------------------------
	 */

	/**
	 * New cards get a generated code as their title.
	 *
	 * @param array $data    Post data.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public function default_code( $data, $postarr ) {
		if ( Post_Types::GIFTCARD === ( $data['post_type'] ?? '' ) && '' === trim( (string) $data['post_title'] ) && ! empty( $postarr['ID'] ) && 'auto-draft' !== $data['post_status'] ) {
			$data['post_title'] = Gift_Card::generate_code();
		}
		if ( Post_Types::GIFTCARD === ( $data['post_type'] ?? '' ) && '' !== trim( (string) $data['post_title'] ) ) {
			$data['post_title'] = Gift_Card::format( $data['post_title'] );
		}
		return $data;
	}

	/**
	 * Title placeholder.
	 *
	 * @param string   $text Placeholder.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function title_placeholder( $text, $post ) {
		return Post_Types::GIFTCARD === $post->post_type ? __( 'Gift card code (leave empty to generate one)', 'sidcraft-commerce-for-sidcraft-page-builder' ) : $text;
	}

	/**
	 * Register the box.
	 */
	public function meta_box() {
		add_meta_box( 'sidccofo-giftcard-data', __( 'Gift card', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_box' ), Post_Types::GIFTCARD, 'normal', 'high' );
	}

	/**
	 * The box: amount/balance, expiry, recipient, send, history.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_box( $post ) {
		wp_nonce_field( 'sidccofo_giftcard', '_sidccofo_giftcard_nonce' );
		$card = '' !== (string) get_post_meta( $post->ID, '_sidccofo_gc_initial', true ) ? Gift_Card::get( $post->ID ) : null;
		$m    = static function ( $key ) use ( $post ) {
			return (string) get_post_meta( $post->ID, '_sidccofo_gc_' . $key, true );
		};
		$sym  = trim( currency_symbol() );
		echo '<div class="sc-pd">';
		if ( $card ) {
			/* translators: %s: currency symbol. */
			printf( '<p><label for="sidccofo-gc-balance">%1$s</label><input type="text" inputmode="decimal" id="sidccofo-gc-balance" name="sidccofo_gc[balance]" value="%2$s"><span class="description">%3$s</span></p>', esc_html( sprintf( __( 'Balance (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sym ) ), esc_attr( (string) $card->get_balance() ), esc_html( sprintf( /* translators: %s: amount. */ __( 'Issued for %s. Changing the balance is logged.', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $card->get_initial() ) ) ) );
		} else {
			/* translators: %s: currency symbol. */
			printf( '<p><label for="sidccofo-gc-amount">%1$s</label><input type="text" inputmode="decimal" id="sidccofo-gc-amount" name="sidccofo_gc[amount]" value="" required></p>', esc_html( sprintf( __( 'Amount (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sym ) ) );
		}
		printf( '<p><label for="sidccofo-gc-expires">%1$s</label><input type="date" id="sidccofo-gc-expires" name="sidccofo_gc[expires]" value="%2$s"><span class="description">%3$s</span></p>', esc_html__( 'Expiry date', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_attr( $m( 'expires' ) ), esc_html__( 'Empty = never expires.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		printf( '<p><label for="sidccofo-gc-rname">%1$s</label><input type="text" id="sidccofo-gc-rname" name="sidccofo_gc[recipient_name]" value="%2$s"></p>', esc_html__( 'Recipient name', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_attr( $m( 'recipient_name' ) ) );
		printf( '<p><label for="sidccofo-gc-remail">%1$s</label><input type="email" id="sidccofo-gc-remail" name="sidccofo_gc[recipient_email]" value="%2$s"></p>', esc_html__( 'Recipient email', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_attr( $m( 'recipient_email' ) ) );
		printf( '<p><label for="sidccofo-gc-sender">%1$s</label><input type="text" id="sidccofo-gc-sender" name="sidccofo_gc[sender]" value="%2$s"></p>', esc_html__( 'From', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_attr( $m( 'sender' ) ) );
		printf( '<p><label for="sidccofo-gc-date">%1$s</label><input type="date" id="sidccofo-gc-date" name="sidccofo_gc[send_date]" value="%2$s"><span class="description">%3$s</span></p>', esc_html__( 'Send on', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_attr( $m( 'send_date' ) ), esc_html__( 'Empty = when saved.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		printf( '<p class="sc-pd__wide"><label for="sidccofo-gc-message">%1$s</label><textarea id="sidccofo-gc-message" name="sidccofo_gc[message]" rows="3" class="large-text">%2$s</textarea></p>', esc_html__( 'Message', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_textarea( $m( 'message' ) ) );
		$sent = (int) $m( 'sent' );
		printf(
			'<p class="sc-pd__wide"><label class="sc-pd__check"><input type="checkbox" name="sidccofo_gc[send_now]" value="1" %1$s> %2$s</label></p>',
			checked( ! $card, true, false ),
			esc_html(
				$sent
					/* translators: %s: date. */
					? sprintf( __( 'Email the card to the recipient again (last sent %s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), date_i18n( get_option( 'date_format' ), $sent ) )
					: __( 'Email the card to the recipient (on the send date, if set)', 'sidcraft-commerce-for-sidcraft-page-builder' )
			)
		);
		echo '</div>';

		if ( $card && $card->get_log() ) {
			echo '<h4>' . esc_html__( 'History', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h4><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Change', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Note', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( $card->get_log() ) as $row ) {
				$order = (int) $row['order'];
				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td>%3$s%4$s</td></tr>',
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) ),
					0.0 === (float) $row['amount'] ? '—' : esc_html( ( $row['amount'] > 0 ? '+' : '' ) . format_price( $row['amount'] ) ),
					esc_html( $row['note'] ),
					$order ? ' <a href="' . esc_url( (string) get_edit_post_link( $order ) ) . '">#' . esc_html( (string) $order ) . '</a>' : ''
				);
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Save the box.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! isset( $_POST['_sidccofo_giftcard_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_giftcard_nonce'] ) ), 'sidccofo_giftcard' ) || ! current_user_can( manage_cap() ) ) {
			return;
		}
		$raw = isset( $_POST['sidccofo_gc'] ) && is_array( $_POST['sidccofo_gc'] ) ? wp_unslash( $_POST['sidccofo_gc'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.

		$code = Gift_Card::normalize( $post->post_title );
		update_post_meta( $post_id, '_sidccofo_gc_code', $code );

		$new = '' === (string) get_post_meta( $post_id, '_sidccofo_gc_initial', true );
		if ( $new ) {
			$amount = round_amount( str_replace( ',', '.', (string) ( $raw['amount'] ?? '0' ) ) );
			if ( $amount <= 0 ) {
				return;
			}
			update_post_meta( $post_id, '_sidccofo_gc_initial', (string) $amount );
			update_post_meta( $post_id, '_sidccofo_gc_balance', (string) $amount );
			update_post_meta( $post_id, '_sidccofo_gc_sent', 0 );
			update_post_meta( $post_id, '_sidccofo_gc_order', 0 );
			$user = wp_get_current_user();
			/* translators: %s: user name. */
			Gift_Card::get( $post_id )->log( $amount, 0, sprintf( __( 'Issued by %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $user->display_name ) );
		}
		$card = Gift_Card::get( $post_id );
		if ( ! $new && isset( $raw['balance'] ) && is_numeric( str_replace( ',', '.', (string) $raw['balance'] ) ) ) {
			$user = wp_get_current_user();
			/* translators: %s: user name. */
			$card->set_balance( (float) str_replace( ',', '.', (string) $raw['balance'] ), sprintf( __( 'Balance changed by %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $user->display_name ) );
		}
		$date = static function ( $v ) {
			$v = sanitize_text_field( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		update_post_meta( $post_id, '_sidccofo_gc_expires', $date( $raw['expires'] ?? '' ) );
		update_post_meta( $post_id, '_sidccofo_gc_recipient_name', sanitize_text_field( (string) ( $raw['recipient_name'] ?? '' ) ) );
		update_post_meta( $post_id, '_sidccofo_gc_recipient_email', sanitize_email( (string) ( $raw['recipient_email'] ?? '' ) ) );
		update_post_meta( $post_id, '_sidccofo_gc_sender', sanitize_text_field( (string) ( $raw['sender'] ?? '' ) ) );
		update_post_meta( $post_id, '_sidccofo_gc_message', sanitize_textarea_field( (string) ( $raw['message'] ?? '' ) ) );
		update_post_meta( $post_id, '_sidccofo_gc_send_date', $date( $raw['send_date'] ?? '' ) );

		if ( ! empty( $raw['send_now'] ) && 'publish' === $post->post_status ) {
			update_post_meta( $post_id, '_sidccofo_gc_sent', 0 );
			if ( $card->is_due() ) {
				self::send( $card );
			}
		}
	}

	/**
	 * List columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function columns( $cols ) {
		return array(
			'cb'           => $cols['cb'] ?? '<input type="checkbox">',
			'title'        => __( 'Code', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_balance'   => __( 'Balance', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_recipient' => __( 'Recipient', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_state'     => __( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_expires'   => __( 'Expires', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_source'    => __( 'Source', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'date'         => $cols['date'] ?? __( 'Date', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * List cell.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Post ID.
	 */
	public function column( $col, $post_id ) {
		$card = Gift_Card::get( $post_id );
		if ( ! $card || '' === (string) $card->meta( 'initial' ) ) {
			return;
		}
		switch ( $col ) {
			case 'sc_balance':
				echo esc_html( format_price( $card->get_balance() ) . ' / ' . format_price( $card->get_initial() ) );
				break;
			case 'sc_recipient':
				echo esc_html( trim( $card->meta( 'recipient_name' ) . ' ' . ( $card->meta( 'recipient_email' ) ? '<' . $card->meta( 'recipient_email' ) . '>' : '' ) ) );
				break;
			case 'sc_state':
				if ( ! $card->is_active() ) {
					esc_html_e( 'Disabled', 'sidcraft-commerce-for-sidcraft-page-builder' );
				} elseif ( $card->is_expired() ) {
					echo '<span style="color:#b32d2e">' . esc_html__( 'Expired', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span>';
				} elseif ( $card->get_balance() <= 0 ) {
					esc_html_e( 'Used up', 'sidcraft-commerce-for-sidcraft-page-builder' );
				} elseif ( ! (int) $card->meta( 'sent' ) && is_email( (string) $card->meta( 'recipient_email' ) ) ) {
					/* translators: %s: date. */
					echo esc_html( $card->meta( 'send_date' ) ? sprintf( __( 'Active, sends %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), date_i18n( get_option( 'date_format' ), strtotime( $card->meta( 'send_date' ) ) ) ) : __( 'Active, not emailed', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
				} else {
					echo '<span style="color:#1a7f37">' . esc_html__( 'Active', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span>';
				}
				break;
			case 'sc_expires':
				echo esc_html( $card->get_expires() ? date_i18n( get_option( 'date_format' ), strtotime( $card->get_expires() ) ) : '—' );
				break;
			case 'sc_source':
				$order = (int) $card->meta( 'order' );
				if ( $order ) {
					printf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_edit_post_link( $order ) ), esc_html( sprintf( /* translators: %d: order number. */ __( 'Order #%d', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order ) ) );
				} else {
					esc_html_e( 'Issued by the store', 'sidcraft-commerce-for-sidcraft-page-builder' );
				}
				break;
		}
	}
}
