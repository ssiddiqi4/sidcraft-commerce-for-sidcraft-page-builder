<?php
/**
 * Email marketing: the mailing list, signup (Newsletter Unit and checkout
 * opt-in, with optional double opt-in), campaigns sent in batches by WP-Cron
 * to a segment, and automations (welcome, abandoned cart, order follow-up,
 * win-back). Every email carries an unsubscribe link and one-click
 * List-Unsubscribe headers; opens and clicks are counted when tracking is on.
 *
 * Only people who said yes get marketing email: subscribed rows, never
 * customers who didn't opt in. Links in emails use the subscriber's random
 * token; tracking URLs are signed.
 *
 *   REST (sidcraft-commerce/v1/marketing/…)
 *     POST subscribe                 email, first_name, consent, website (honeypot)
 *     GET|POST unsubscribe?t=        one-click unsubscribe (mail clients)
 *     GET  open/{log}/{sig}          1×1 GIF
 *     GET  click/{log}/{sig}?u=      redirect
 *     POST capture                   checkout email, for the abandoned-cart email
 *   Front links: ?sidccofo_list=confirm|unsubscribe&t=…, ?sidccofo_recover=…
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Marketing;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Checkout\Checkout;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Rest;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Marketing
 */
class Marketing {

	const CAMPAIGN = 'sidccofo_campaign';

	const BATCH_HOOK = 'sidcraft_commerce_marketing_batch';

	const FOLLOWUP_HOOK = 'sidcraft_commerce_marketing_followup';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'template_redirect', array( $this, 'links' ), 1 );
		add_action( 'sidcraft_commerce_checkout_before_submit', array( $this, 'checkout_optin' ) );
		add_action( 'sidcraft_commerce_order_created', array( $this, 'order_created' ) );
		add_action( 'sidcraft_commerce_payment_complete', array( $this, 'payment_complete' ) );
		add_action( 'sidcraft_commerce_cart_updated', array( $this, 'cart_updated' ) );
		add_action( 'sidcraft_commerce_subscriber_confirmed', array( $this, 'welcome' ) );
		add_action( 'sidcraft_commerce_cleanup', array( $this, 'hourly' ) );
		add_action( self::BATCH_HOOK, array( $this, 'send_batch' ) );
		add_action( self::FOLLOWUP_HOOK, array( $this, 'followup' ) );
		add_filter( 'sidcraft_commerce_js_config', array( $this, 'js_config' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Campaigns: drafts written in the WordPress editor, under Store.
	 */
	public function register_post_type() {
		register_post_type(
			self::CAMPAIGN,
			array(
				'labels'          => array(
					'name'               => __( 'Email campaigns', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name'      => __( 'Email campaign', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'menu_name'          => __( 'Email campaigns', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'add_new'            => __( 'New campaign', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'add_new_item'       => __( 'New email campaign', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'edit_item'          => __( 'Edit email campaign', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'not_found'          => __( 'No campaigns yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'not_found_in_trash' => __( 'No campaigns in the trash.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'search_items'       => __( 'Search campaigns', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . Post_Types::PRODUCT,
				'show_in_rest'    => false, // The classic editor: email HTML, not blocks.
				'supports'        => array( 'title', 'editor', 'revisions' ),
				'capability_type' => 'post',
				'capabilities'    => array(
					'edit_post'          => \SidcraftCommerce\manage_cap(),
					'read_post'          => \SidcraftCommerce\manage_cap(),
					'delete_post'        => \SidcraftCommerce\manage_cap(),
					'edit_posts'         => \SidcraftCommerce\manage_cap(),
					'edit_others_posts'  => \SidcraftCommerce\manage_cap(),
					'delete_posts'       => \SidcraftCommerce\manage_cap(),
					'publish_posts'      => \SidcraftCommerce\manage_cap(),
					'read_private_posts' => \SidcraftCommerce\manage_cap(),
					'create_posts'       => \SidcraftCommerce\manage_cap(),
				),
				'map_meta_cap'    => false,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * REST
	 * ----------------------------------------------------------------
	 */

	/**
	 * Routes.
	 */
	public function routes() {
		$public = '__return_true';
		register_rest_route(
			Rest::NS,
			'/marketing/subscribe',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public,
				'callback'            => array( $this, 'rest_subscribe' ),
			)
		);
		register_rest_route(
			Rest::NS,
			'/marketing/unsubscribe',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'permission_callback' => $public,
				'callback'            => array( $this, 'rest_unsubscribe' ),
			)
		);
		register_rest_route(
			Rest::NS,
			'/marketing/(?P<kind>open|click)/(?P<log>\d+)/(?P<sig>[a-f0-9]{16})',
			array(
				'methods'             => 'GET',
				'permission_callback' => $public,
				'callback'            => array( $this, 'rest_track' ),
			)
		);
		register_rest_route(
			Rest::NS,
			'/marketing/capture',
			array(
				'methods'             => 'POST',
				'permission_callback' => $public,
				'callback'            => array( $this, 'rest_capture' ),
			)
		);
	}

	/**
	 * Newsletter signup.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_subscribe( \WP_REST_Request $request ) {
		// Bots fill every field, people don't see this one.
		if ( '' !== (string) $request->get_param( 'website' ) ) {
			return rest_ensure_response( array( 'message' => self::thanks_message() ) );
		}
		if ( ! \SidcraftCommerce\rate_limit( 'sub', 5, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'sidccofo_rate', __( 'Too many tries. Please try again later.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
		}
		$email = Subscribers::normalize( (string) $request->get_param( 'email' ) );
		if ( '' === $email ) {
			return new \WP_Error( 'sidccofo_email', __( 'Enter a valid email address.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		if ( ! $request->get_param( 'consent' ) ) {
			return new \WP_Error( 'sidccofo_consent', __( 'Please tick the box to agree to receive our emails.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		$this->subscribe(
			$email,
			array(
				'first_name' => (string) $request->get_param( 'first_name' ),
				'source'     => 'form',
				'tags'       => (string) $request->get_param( 'tags' ),
				'user_id'    => get_current_user_id(),
			)
		);
		// The same answer whatever the address's state, so the form can't tell who is on the list.
		return rest_ensure_response( array( 'message' => self::thanks_message() ) );
	}

	/**
	 * What the signup form says afterwards.
	 *
	 * @return string
	 */
	protected static function thanks_message() {
		return setting( 'marketing_double_optin', 1 )
			? __( 'Almost done: check your inbox and click the link to confirm.', 'sidcraft-commerce-for-sidcraft-page-builder' )
			: __( 'Thanks for subscribing!', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/**
	 * Add someone who asked to join, with or without double opt-in.
	 *
	 * @param string $email Email.
	 * @param array  $args  first_name, source, tags, user_id; confirmed (skip double opt-in).
	 * @return object|null
	 */
	public function subscribe( $email, array $args ) {
		$double = setting( 'marketing_double_optin', 1 ) && empty( $args['confirmed'] );
		$row    = Subscribers::add(
			$email,
			array_merge(
				$args,
				array(
					'status' => $double ? 'pending' : 'subscribed',
					'opt_in' => true,
				)
			)
		);
		if ( $row && 'pending' === $row->status ) {
			$this->send_confirmation( $row );
		}
		return $row;
	}

	/**
	 * Double opt-in email (at most one an hour per address).
	 *
	 * @param object $row Subscriber.
	 */
	protected function send_confirmation( $row ) {
		$lock = 'sidccofo_optin_' . md5( $row->email );
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, HOUR_IN_SECONDS );
		Mailer::deliver(
			$row,
			/* translators: %s: store name. */
			sprintf( __( 'Confirm your subscription to %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			__( "Hi {first_name},\n\nPlease confirm that you want to get our news and offers:\n\n{confirm_link}\n\nIf you didn't ask for this, ignore this email and you won't hear from us.", 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array( 'confirm_link' => Mailer::button( Subscribers::link( $row, 'confirm' ), __( 'Yes, subscribe me', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) ),
			0,
			false
		);
	}

	/**
	 * One-click unsubscribe (RFC 8058) from a mail client.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_unsubscribe( \WP_REST_Request $request ) {
		$row = Subscribers::by_token( (string) $request->get_param( 't' ) );
		if ( $row ) {
			Subscribers::set_status( (int) $row->id, 'unsubscribed' );
		}
		return rest_ensure_response( array( 'unsubscribed' => (bool) $row ) );
	}

	/**
	 * Open pixel and click redirect.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|void
	 */
	public function rest_track( \WP_REST_Request $request ) {
		$kind  = (string) $request['kind'];
		$log   = (int) $request['log'];
		$url   = 'click' === $kind ? (string) $request->get_param( 'u' ) : '';
		$valid = hash_equals( Mailer::sign( $kind . $log . '|' . $url ), (string) $request['sig'] );
		if ( $valid ) {
			Mailer::record( $kind, $log );
		}
		if ( 'click' === $kind ) {
			// Only the destination the signed email carried; anything else goes home (no open redirect).
			$to = $valid ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
			nocache_headers();
			wp_redirect( '' !== $to ? $to : home_url( '/' ), 302, 'Sidcraft Commerce' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Signed destination from our own email.
			exit;
		}
		nocache_headers();
		header( 'Content-Type: image/gif' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.Security.EscapeOutput.OutputNotEscaped -- 1×1 transparent GIF.
		exit;
	}

	/**
	 * Checkout email typed in: remember the cart for a reminder, but only for
	 * people who agreed to emails (ticked the box, or already subscribed).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_capture( \WP_REST_Request $request ) {
		$email = Subscribers::normalize( (string) $request->get_param( 'email' ) );
		$cart  = Cart::instance();
		if ( '' === $email || ! setting( 'marketing_abandoned', 1 ) || $cart->is_empty() || ! $cart->session()->has_session() ) {
			return rest_ensure_response( array( 'ok' => false ) );
		}
		$row = Subscribers::by_email( $email );
		if ( ! ( $row && 'subscribed' === $row->status ) && ! $request->get_param( 'optin' ) ) {
			self::forget_cart( $cart->session()->get_key() );
			return rest_ensure_response( array( 'ok' => false ) );
		}
		Abandoned::save( $cart, $email, (string) $request->get_param( 'first_name' ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Tell the checkout script to send the email for cart reminders.
	 *
	 * @param array $config Front-end config.
	 * @return array
	 */
	public function js_config( $config ) {
		$config['marketing'] = array( 'capture' => (bool) setting( 'marketing_abandoned', 1 ) );
		return $config;
	}

	/*
	------------------------------------------------------------------
	 * Front links
	 * ----------------------------------------------------------------
	 */

	/**
	 * Confirm / unsubscribe / restore-cart links from emails.
	 */
	public function links() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Links in emails carry their own random token.
		if ( isset( $_GET['sidccofo_recover'] ) ) {
			$this->recover_cart( sanitize_text_field( wp_unslash( $_GET['sidccofo_recover'] ) ) );
			return;
		}
		if ( ! isset( $_GET['sidccofo_list'], $_GET['t'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_GET['sidccofo_list'] ) );
		$row    = Subscribers::by_token( sanitize_text_field( wp_unslash( $_GET['t'] ) ) );
		// phpcs:enable
		nocache_headers();
		$title = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( ! $row ) {
			wp_die( esc_html__( 'This link is no longer valid.', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_html( $title ), array( 'response' => 404 ) );
		}
		if ( 'confirm' === $action ) {
			$ok = Subscribers::confirm( $row );
			wp_die( esc_html( $ok ? __( 'Thanks! Your subscription is confirmed.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'This address was unsubscribed. Sign up again on our website to get our emails.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ), esc_html( $title ), array( 'response' => 200, 'link_url' => esc_url( home_url( '/' ) ), 'link_text' => esc_html__( 'Back to the shop', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
		}
		if ( 'unsubscribe' === $action ) {
			Subscribers::set_status( (int) $row->id, 'unsubscribed' );
			wp_die( esc_html__( 'You’re unsubscribed and won’t get our marketing emails any more. Order emails still arrive as usual.', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_html( $title ), array( 'response' => 200, 'link_url' => esc_url( home_url( '/' ) ), 'link_text' => esc_html__( 'Back to the shop', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) );
		}
	}

	/**
	 * Put a remembered cart back and go to the checkout.
	 *
	 * @param string $token Token.
	 */
	protected function recover_cart( $token ) {
		$saved = Abandoned::by_token( $token );
		$cart  = Cart::instance();
		if ( $saved ) {
			if ( $cart->is_empty() ) {
				foreach ( (array) json_decode( (string) $saved->cart, true ) as $line ) {
					$product_id = (int) ( $line['product_id'] ?? 0 );
					$qty        = max( 1, (int) ( $line['quantity'] ?? 1 ) );
					if ( ! empty( $line['selection'] ) && is_array( $line['selection'] ) ) {
						$cart->add_with_options( $product_id, $qty, $line['selection'] );
					} else {
						$cart->add( $product_id, $qty );
					}
				}
			}
			Abandoned::mark_clicked( $saved );
		}
		nocache_headers();
		wp_safe_redirect( \SidcraftCommerce\page_url( $cart->is_empty() ? 'shop' : 'checkout' ) );
		exit;
	}

	/*
	------------------------------------------------------------------
	 * Checkout and orders
	 * ----------------------------------------------------------------
	 */

	/**
	 * Opt-in box above "Place order" (never ticked in advance).
	 *
	 * @param Cart $cart Cart.
	 */
	public function checkout_optin( $cart ) {
		unset( $cart );
		if ( ! setting( 'marketing_checkout_optin', 1 ) ) {
			return;
		}
		$text = (string) setting( 'marketing_optin_text', '' );
		if ( '' === trim( $text ) ) {
			$text = __( 'Email me news and offers. You can unsubscribe at any time.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		printf(
			'<p class="sc-field sc-field--full sc-optin"><label for="sc-marketing_optin"><input type="checkbox" id="sc-marketing_optin" name="marketing_optin" value="1" data-sc-optin> %s</label></p>',
			esc_html( $text )
		);
	}

	/**
	 * Subscribe at checkout when ticked; the checkout is no longer abandoned.
	 *
	 * @param Order $order Order.
	 */
	public function order_created( $order ) {
		$cart = Cart::instance();
		if ( $cart->session()->has_session() ) {
			self::forget_cart( $cart->session()->get_key() );
		}
		$input = Checkout::input();
		if ( ! empty( $input['marketing_optin'] ) && setting( 'marketing_checkout_optin', 1 ) ) {
			$billing = $order->get_billing();
			// A checkout email is the customer's own: no confirmation round trip.
			$this->subscribe(
				$order->get_email(),
				array(
					'first_name' => (string) ( $billing['first_name'] ?? '' ),
					'source'     => 'checkout',
					'user_id'    => get_current_user_id(),
					'confirmed'  => true,
				)
			);
		}
	}

	/**
	 * Paid: count the order on the subscriber, plan the follow-up email.
	 *
	 * @param Order $order Order.
	 */
	public function payment_complete( $order ) {
		if ( get_post_meta( $order->get_id(), '_sidccofo_marketing_counted', true ) ) {
			return;
		}
		update_post_meta( $order->get_id(), '_sidccofo_marketing_counted', 1 );
		Subscribers::record_order( $order->get_email(), (float) $order->to_base( $order->get_total() ), time() );
		if ( setting( 'marketing_followup', 1 ) ) {
			$days = max( 1, (int) setting( 'marketing_followup_days', 7 ) );
			wp_schedule_single_event( time() + $days * DAY_IN_SECONDS, self::FOLLOWUP_HOOK, array( $order->get_id() ) );
		}
	}

	/**
	 * Keep a remembered cart up to date while the visitor shops on.
	 *
	 * @param Cart $cart Cart.
	 */
	public function cart_updated( $cart ) {
		if ( ! $cart->session()->has_session() ) {
			return;
		}
		$saved = Abandoned::get( $cart->session()->get_key() );
		if ( ! $saved ) {
			return;
		}
		if ( $cart->is_empty() ) {
			self::forget_cart( $saved->session_key );
			return;
		}
		Abandoned::save( $cart, $saved->email, $saved->first_name );
	}

	/**
	 * Forget a remembered cart.
	 *
	 * @param string $session_key Session.
	 */
	public static function forget_cart( $session_key ) {
		Abandoned::delete( $session_key );
	}

	/*
	------------------------------------------------------------------
	 * Automations
	 * ----------------------------------------------------------------
	 */

	/**
	 * Welcome email.
	 *
	 * @param object $row Subscriber.
	 */
	public function welcome( $row ) {
		if ( ! $row || ! setting( 'marketing_welcome', 1 ) ) {
			return;
		}
		Mailer::send( $row, 'welcome', (string) setting( 'marketing_welcome_subject', '' ), (string) setting( 'marketing_welcome_body', '' ) );
	}

	/**
	 * Order follow-up (review request).
	 *
	 * @param int $order_id Order.
	 */
	public function followup( $order_id ) {
		$order = Order::get( (int) $order_id );
		if ( ! $order || ! $order->is_paid() || ! setting( 'marketing_followup', 1 ) || in_array( $order->get_status(), array( 'sidccofo-refunded', 'sidccofo-cancelled' ), true ) ) {
			return;
		}
		$row = Subscribers::by_email( $order->get_email() );
		if ( ! $row ) {
			return;
		}
		$list = '';
		$seen = array();
		foreach ( $order->get_items() as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			if ( isset( $seen[ $product_id ] ) || 'publish' !== get_post_status( $product_id ) ) {
				continue;
			}
			$seen[ $product_id ] = true;
			$list               .= '<li><a href="' . esc_url( get_permalink( $product_id ) . '#reviews' ) . '">' . esc_html( get_the_title( $product_id ) ) . '</a></li>';
		}
		Mailer::send(
			$row,
			'followup:' . $order->get_id(),
			(string) setting( 'marketing_followup_subject', '' ),
			(string) setting( 'marketing_followup_body', '' ),
			array(
				'order_number' => (string) $order->get_id(),
				'products'     => $list ? '<ul>' . $list . '</ul>' : '',
			)
		);
	}

	/**
	 * Hourly: cart reminders, the day's win-back, stuck campaigns, old carts.
	 */
	public function hourly() {
		if ( setting( 'marketing_abandoned', 1 ) ) {
			$this->remind_carts();
		}
		if ( setting( 'marketing_winback', 0 ) && (int) get_option( 'sidcraft_commerce_winback_day' ) !== (int) gmdate( 'Ymd' ) ) {
			update_option( 'sidcraft_commerce_winback_day', (int) gmdate( 'Ymd' ), false );
			$this->winback();
		}
		$this->resume_campaigns();
		Abandoned::prune( 30 * DAY_IN_SECONDS );
	}

	/**
	 * Remind people of carts left for the configured hours (once each).
	 */
	protected function remind_carts() {
		$hours = max( 1, (int) setting( 'marketing_abandoned_hours', 4 ) );
		foreach ( Abandoned::due( time() - $hours * HOUR_IN_SECONDS, 100 ) as $saved ) {
			Abandoned::mark_reminded( $saved );
			$row = Subscribers::by_email( $saved->email );
			if ( ! $row || 'subscribed' !== $row->status ) {
				continue;
			}
			Mailer::send(
				$row,
				'cart:' . substr( $saved->token, 0, 20 ) . ':' . $saved->updated,
				(string) setting( 'marketing_abandoned_subject', '' ),
				(string) setting( 'marketing_abandoned_body', '' ),
				array(
					'cart'      => Abandoned::summary_html( $saved ),
					'cart_link' => Mailer::button( add_query_arg( 'sidccofo_recover', $saved->token, home_url( '/' ) ), __( 'Finish your order', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				)
			);
		}
	}

	/**
	 * Customers with no order for the configured days: one email per quiet spell.
	 */
	protected function winback() {
		global $wpdb;
		$days   = max( 7, (int) setting( 'marketing_winback_days', 90 ) );
		$before = time() - $days * DAY_IN_SECONDS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'subscribed' AND orders > 0 AND last_order < %d AND winback_sent < last_order LIMIT 200", Subscribers::table(), $before ) );
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$wpdb->update( Subscribers::table(), array( 'winback_sent' => time() ), array( 'id' => (int) $row->id ) );
			Mailer::send( $row, 'winback:' . $row->last_order, (string) setting( 'marketing_winback_subject', '' ), (string) setting( 'marketing_winback_body', '' ) );
		}
	}

	/*
	------------------------------------------------------------------
	 * Campaigns
	 * ----------------------------------------------------------------
	 */

	/**
	 * Queue a campaign: now, or at a time.
	 *
	 * @param int $campaign_id Campaign.
	 * @param int $when        Unix time (0 = now).
	 */
	public static function queue( $campaign_id, $when = 0 ) {
		$when = max( time(), (int) $when );
		update_post_meta( $campaign_id, '_sidccofo_status', 'scheduled' );
		update_post_meta( $campaign_id, '_sidccofo_send_at', $when );
		delete_post_meta( $campaign_id, '_sidccofo_last_id' );
		wp_clear_scheduled_hook( self::BATCH_HOOK, array( (int) $campaign_id ) );
		wp_schedule_single_event( $when, self::BATCH_HOOK, array( (int) $campaign_id ) );
	}

	/**
	 * Stop a scheduled or sending campaign.
	 *
	 * @param int $campaign_id Campaign.
	 */
	public static function cancel( $campaign_id ) {
		wp_clear_scheduled_hook( self::BATCH_HOOK, array( (int) $campaign_id ) );
		update_post_meta( $campaign_id, '_sidccofo_status', 'draft' );
	}

	/**
	 * Campaign status.
	 *
	 * @param int $campaign_id Campaign.
	 * @return string draft|scheduled|sending|sent
	 */
	public static function status( $campaign_id ) {
		$status = (string) get_post_meta( $campaign_id, '_sidccofo_status', true );
		return in_array( $status, array( 'scheduled', 'sending', 'sent' ), true ) ? $status : 'draft';
	}

	/**
	 * A campaign's body HTML (blocks rendered, shortcodes removed).
	 *
	 * @param \WP_Post $post Campaign.
	 * @return string
	 */
	public static function body( \WP_Post $post ) {
		$html = has_blocks( $post->post_content ) ? do_blocks( $post->post_content ) : wpautop( $post->post_content );
		return strip_shortcodes( $html );
	}

	/**
	 * Send the next batch of a campaign, then plan the one after.
	 *
	 * @param int $campaign_id Campaign.
	 */
	public function send_batch( $campaign_id ) {
		$post = get_post( (int) $campaign_id );
		if ( ! $post || self::CAMPAIGN !== $post->post_type || 'trash' === $post->post_status ) {
			return;
		}
		$status = self::status( $post->ID );
		if ( ! in_array( $status, array( 'scheduled', 'sending' ), true ) ) {
			return;
		}
		$segment = (string) get_post_meta( $post->ID, '_sidccofo_segment', true );
		$value   = (string) get_post_meta( $post->ID, '_sidccofo_segment_value', true );
		if ( 'scheduled' === $status ) {
			update_post_meta( $post->ID, '_sidccofo_status', 'sending' );
			update_post_meta( $post->ID, '_sidccofo_recipients', Subscribers::count_segment( $segment, $value ) );
		}
		$subject = (string) get_post_meta( $post->ID, '_sidccofo_subject', true );
		$subject = '' !== $subject ? $subject : $post->post_title;
		$body    = self::body( $post );
		$batch   = max( 1, min( 500, (int) setting( 'marketing_batch', 50 ) ) );
		$after   = (int) get_post_meta( $post->ID, '_sidccofo_last_id', true );
		$rows    = Subscribers::in_segment( $segment, $value, $after, $batch );
		foreach ( $rows as $row ) {
			Mailer::send( $row, 'c' . $post->ID, $subject, $body );
			update_post_meta( $post->ID, '_sidccofo_last_id', (int) $row->id );
		}
		if ( count( $rows ) < $batch ) {
			update_post_meta( $post->ID, '_sidccofo_status', 'sent' );
			update_post_meta( $post->ID, '_sidccofo_sent_at', time() );
			return;
		}
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BATCH_HOOK, array( (int) $post->ID ) );
	}

	/**
	 * A campaign whose next batch was lost (cron cleared, server moved) carries on.
	 */
	protected function resume_campaigns() {
		$ids = get_posts(
			array(
				'post_type'      => self::CAMPAIGN,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 20,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Few campaigns; hourly.
				'meta_query'     => array(
					array(
						'key'     => '_sidccofo_status',
						'value'   => array( 'scheduled', 'sending' ),
						'compare' => 'IN',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$when = (int) get_post_meta( $id, '_sidccofo_send_at', true );
			if ( $when <= time() - 10 * MINUTE_IN_SECONDS && ! wp_next_scheduled( self::BATCH_HOOK, array( (int) $id ) ) ) {
				wp_schedule_single_event( time(), self::BATCH_HOOK, array( (int) $id ) );
			}
		}
	}

	/*
	------------------------------------------------------------------
	 * Privacy
	 * ----------------------------------------------------------------
	 */

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['sidcraft-commerce-marketing'] = array(
			'exporter_friendly_name' => __( 'Store mailing list', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['sidcraft-commerce-marketing'] = array(
			'eraser_friendly_name' => __( 'Store mailing list', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export a person's list entry.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public function export( $email ) {
		$row  = Subscribers::by_email( $email );
		$data = array();
		if ( $row ) {
			$labels = Subscribers::status_labels();
			$data[] = array(
				'group_id'    => 'sidccofo-marketing',
				'group_label' => __( 'Mailing list', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'item_id'     => 'subscriber-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'value' => $row->email,
					),
					array(
						'name'  => __( 'First name', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'value' => $row->first_name,
					),
					array(
						'name'  => __( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'value' => $labels[ $row->status ] ?? $row->status,
					),
					array(
						'name'  => __( 'Signed up', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'value' => $row->created ? wp_date( 'Y-m-d H:i', (int) $row->created ) : '',
					),
					array(
						'name'  => __( 'Confirmed', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'value' => $row->confirmed ? wp_date( 'Y-m-d H:i', (int) $row->confirmed ) : '',
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase a person's list entry, sent-email log and remembered carts.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public function erase( $email ) {
		$row = Subscribers::by_email( $email );
		if ( $row ) {
			Subscribers::delete( array( (int) $row->id ) );
		}
		$carts = Abandoned::delete_by_email( $email );
		return array(
			'items_removed'  => (bool) $row || $carts > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
