<?php
/**
 * Live chat: a chat button on store pages and an inbox under Store → Live
 * chat (see Admin\Live_Chat_Admin).
 *
 * Light by design: the page gets one small deferred script and stylesheet;
 * the script builds the window when it is first opened, and only polls while
 * a conversation is open (every few seconds while the window is open and the
 * tab visible, slowly otherwise). Visitors are known by a random cookie;
 * logged-in customers by their account too.
 *
 * The store shows as online while someone has the inbox open (its polling
 * keeps a short-lived flag fresh). Otherwise visitors leave a message with
 * their email, and the store gets an email.
 *
 *   REST (sidcraft-commerce/v1/chat/…)
 *     GET  state                  online, the visitor's conversation and its messages
 *     GET  poll?after=            new messages
 *     POST send                   body, name, email, page
 *     POST end                    visitor closes the conversation
 *     GET  admin/inbox?status=    conversations (manage_cap)
 *     GET  admin/(id)?after=      a conversation's messages; marks them read
 *     POST admin/(id)/reply       body
 *     POST admin/(id)/status      status=open|closed
 *     DELETE admin/(id)
 * Visitor POSTs need the X-Sidccofo-Chat header, which a cross-site form
 * can't send.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Live_Chat;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Rest;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Live_Chat
 */
class Live_Chat {

	const COOKIE = 'sidccofo_chat';

	/** Transient that says someone has the inbox open. */
	const ONLINE = 'sidccofo_chat_online';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 30 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'sidcraft_commerce_cleanup', array( $this, 'tidy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Whether the chat button belongs on this page.
	 *
	 * @return bool
	 */
	public static function should_show() {
		if ( is_admin() || is_feed() || is_embed() || is_404() ) {
			$show = false;
		} elseif ( 'store' === setting( 'livechat_pages', 'all' ) ) {
			$store = array_filter( array( \SidcraftCommerce\page_id( 'shop' ), \SidcraftCommerce\page_id( 'cart' ), \SidcraftCommerce\page_id( 'checkout' ) ) );
			$show  = is_singular( Post_Types::PRODUCT ) || is_tax( array( Post_Types::CATEGORY, Post_Types::BRAND, Post_Types::TAG ) ) || is_post_type_archive( Post_Types::PRODUCT ) || ( $store && is_page( $store ) );
		} else {
			$show = true;
		}

		/**
		 * Filter whether the chat button shows on this page.
		 *
		 * @param bool $show Show it.
		 */
		return (bool) apply_filters( 'sidcraft_commerce_livechat_show', $show );
	}

	/**
	 * Script and style, with their config.
	 */
	public function enqueue() {
		if ( ! self::should_show() ) {
			return;
		}
		$url = SIDCRAFT_COMMERCE_URL . 'assets/';
		wp_enqueue_style( 'sidccofo-chat', $url . 'css/sc-chat.css', array(), SIDCRAFT_COMMERCE_VERSION );
		wp_enqueue_script(
			'sidccofo-chat',
			$url . 'js/sc-chat.js',
			array(),
			SIDCRAFT_COMMERCE_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		$user   = wp_get_current_user();
		$config = array(
			'api'       => esc_url_raw( rest_url( Rest::NS . '/chat/' ) ),
			'restNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'position'  => 'left' === setting( 'livechat_position', 'right' ) ? 'left' : 'right',
			'askEmail'  => ! is_user_logged_in() && setting( 'livechat_ask_email', 1 ),
			'known'     => is_user_logged_in(),
			'name'      => is_user_logged_in() ? ( $user->first_name ? $user->first_name : $user->display_name ) : '',
			'cookie'    => \SidcraftCommerce\cookie_name( 'sidccofo_chat_active' ),
			'i18n'      => array(
				'title'       => (string) setting( 'livechat_title', __( 'Chat with us', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'greeting'    => (string) setting( 'livechat_greeting', '' ),
				'offline'     => (string) setting( 'livechat_offline', '' ),
				'open'        => __( 'Open chat', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'close'       => __( 'Close chat', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'end'         => __( 'End chat', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'online'      => __( 'We’re online', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'away'        => __( 'Away — we’ll reply by email', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'name'        => __( 'Your name', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'email'       => __( 'Your email', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'message'     => __( 'Type your message…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'send'        => __( 'Send', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'needEmail'   => __( 'Enter your email so we can reply.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'error'       => __( 'Your message could not be sent. Please try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'ended'       => __( 'This chat has ended. Send a message to start a new one.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'you'         => __( 'You', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'store'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'unread'      => __( 'New message', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);
		wp_add_inline_script( 'sidccofo-chat', 'window.sidccofoChat = ' . wp_json_encode( $config ) . ';', 'before' );
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
		$visitor = array( $this, 'visitor_can_post' );
		$public  = '__return_true';
		$staff   = static function () {
			return current_user_can( \SidcraftCommerce\manage_cap() );
		};
		$id      = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
		$routes  = array(
			'/chat/state'                     => array( 'GET', $public, 'rest_state', array() ),
			'/chat/poll'                      => array( 'GET', $public, 'rest_poll', array() ),
			'/chat/send'                      => array( 'POST', $visitor, 'rest_send', array() ),
			'/chat/end'                       => array( 'POST', $visitor, 'rest_end', array() ),
			'/chat/admin/inbox'               => array( 'GET', $staff, 'rest_inbox', array() ),
			'/chat/admin/(?P<id>\d+)'         => array( 'GET', $staff, 'rest_thread', $id ),
			'/chat/admin/(?P<id>\d+)/reply'   => array( 'POST', $staff, 'rest_reply', $id ),
			'/chat/admin/(?P<id>\d+)/status'  => array( 'POST', $staff, 'rest_set_status', $id ),
			'/chat/admin/(?P<id>\d+)/delete'  => array( 'POST', $staff, 'rest_delete', $id ),
		);
		foreach ( $routes as $path => $route ) {
			register_rest_route(
				Rest::NS,
				$path,
				array(
					'methods'             => $route[0],
					'permission_callback' => $route[1],
					'callback'            => array( $this, $route[2] ),
					'args'                => $route[3],
				)
			);
		}
	}

	/**
	 * Visitor writes need the custom header (blocks cross-site form posts).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function visitor_can_post( \WP_REST_Request $request ) {
		return '1' === (string) $request->get_header( 'x_sidccofo_chat' );
	}

	/**
	 * The visitor's key, from the cookie ('' when none).
	 *
	 * @return string
	 */
	protected static function visitor_key() {
		$name = \SidcraftCommerce\cookie_name( self::COOKIE );
		$raw  = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
		return preg_match( '/^[A-Za-z0-9]{32}$/', $raw ) ? $raw : '';
	}

	/**
	 * Give the visitor a key (first message).
	 *
	 * @return string
	 */
	protected static function start_visitor() {
		$key  = wp_generate_password( 32, false );
		$opts = array(
			'expires'  => time() + 30 * DAY_IN_SECONDS,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		if ( ! headers_sent() ) {
			setcookie( \SidcraftCommerce\cookie_name( self::COOKIE ), $key, $opts );
		}
		$_COOKIE[ \SidcraftCommerce\cookie_name( self::COOKIE ) ] = $key;
		return $key;
	}

	/**
	 * The visitor's current conversation, if it is theirs.
	 *
	 * @return object|null
	 */
	protected static function current_chat() {
		$key = self::visitor_key();
		return '' === $key ? null : Chats::for_visitor( $key );
	}

	/**
	 * Is someone at the inbox?
	 *
	 * @return bool
	 */
	public static function online() {
		$seen = (int) get_transient( self::ONLINE );
		return $seen > time() - 90;
	}

	/**
	 * The inbox is open: keep the online flag fresh (one write per half minute).
	 */
	public static function operator_seen() {
		if ( (int) get_transient( self::ONLINE ) < time() - 30 ) {
			set_transient( self::ONLINE, time(), 2 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Messages for the visitor (operator names, no ids of staff).
	 *
	 * @param object $chat  Conversation.
	 * @param int    $after Last id.
	 * @return array
	 */
	protected static function payload( $chat, $after ) {
		$messages = $chat ? Chats::messages( (int) $chat->id, $after ) : array();
		if ( $chat && $messages ) {
			Chats::mark_read( $chat, 'visitor', (int) end( $messages )['id'] );
		}
		return array(
			'online'   => self::online(),
			'chat'     => $chat ? array(
				'id'     => (int) $chat->id,
				'status' => $chat->status,
				'email'  => '' !== $chat->email,
			) : null,
			'messages' => $messages,
		);
	}

	/**
	 * State when the window opens.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_state() {
		$response = rest_ensure_response( self::payload( self::current_chat(), 0 ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * New messages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_poll( \WP_REST_Request $request ) {
		$response = rest_ensure_response( self::payload( self::current_chat(), absint( $request->get_param( 'after' ) ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * A visitor message (starts a conversation when there is none).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_send( \WP_REST_Request $request ) {
		$body = trim( (string) $request->get_param( 'body' ) );
		if ( '' === $body ) {
			return new \WP_Error( 'sidccofo_chat_empty', __( 'Type a message first.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		$chat   = self::current_chat();
		$user   = wp_get_current_user();
		$online = self::online();
		$key    = self::visitor_key();
		if ( ! \SidcraftCommerce\Marketing\Marketing::rate_ok( 'chatmsg_' . $key, 20, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'sidccofo_rate', __( 'You’re sending messages too fast. Please wait a moment.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
		}

		if ( ! $chat || 'closed' === $chat->status ) {
			$email = is_user_logged_in() ? $user->user_email : sanitize_email( (string) $request->get_param( 'email' ) );
			$name  = is_user_logged_in() ? ( $user->first_name ? $user->first_name : $user->display_name ) : sanitize_text_field( (string) $request->get_param( 'name' ) );
			// Away, the store answers by email: it needs one. Online, it's up to the settings.
			if ( '' !== $email && ! is_email( $email ) ) {
				return new \WP_Error( 'sidccofo_chat_email', __( 'Enter a valid email address.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
			}
			if ( '' === $email && ( ! $online || setting( 'livechat_ask_email', 1 ) ) ) {
				return new \WP_Error( 'sidccofo_chat_email', __( 'Enter your email so we can reply.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
			}
			if ( ! \SidcraftCommerce\Marketing\Marketing::rate_ok( 'chatnew', 5, HOUR_IN_SECONDS ) ) {
				return new \WP_Error( 'sidccofo_rate', __( 'Too many chats from your connection. Please try again later.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
			}
			$chat = Chats::create(
				'' !== $key ? $key : self::start_visitor(),
				array(
					'user_id' => get_current_user_id(),
					'name'    => $name,
					'email'   => $email,
					'page'    => (string) $request->get_param( 'page' ),
				)
			);
			if ( ! $chat ) {
				return new \WP_Error( 'sidccofo_chat', __( 'Your message could not be sent. Please try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 500 ) );
			}
		}
		Chats::add_message( $chat, 'visitor', $body );
		if ( ! $online ) {
			$this->notify_store( $chat, $body );
		}
		return rest_ensure_response( self::payload( Chats::get( (int) $chat->id ), absint( $request->get_param( 'after' ) ) ) );
	}

	/**
	 * The visitor ends the conversation.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_end() {
		$chat = self::current_chat();
		if ( $chat && 'open' === $chat->status ) {
			Chats::add_message( $chat, 'system', __( 'The visitor ended the chat.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			Chats::set_status( (int) $chat->id, 'closed' );
		}
		return rest_ensure_response( array( 'ended' => true ) );
	}

	/**
	 * Email the store about a message nobody was online for (at most one
	 * email per conversation every 15 minutes).
	 *
	 * @param object $chat Conversation.
	 * @param string $body Message.
	 */
	protected function notify_store( $chat, $body ) {
		$lock = 'sidccofo_chat_mail_' . (int) $chat->id;
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 15 * MINUTE_IN_SECONDS );
		$to = sanitize_email( (string) setting( 'admin_email', get_option( 'admin_email' ) ) );
		if ( ! is_email( $to ) ) {
			return;
		}
		$who = trim( $chat->name . ( '' !== $chat->email ? ' <' . $chat->email . '>' : '' ) );
		/* translators: %s: visitor. */
		$subject = sprintf( __( 'New chat message from %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), '' !== $who ? $who : __( 'a visitor', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		$link    = admin_url( 'edit.php?post_type=' . Post_Types::PRODUCT . '&page=sidccofo-live-chat&chat=' . (int) $chat->id );
		$text    = $body . "\n\n" . __( 'Reply in the store’s Live chat inbox:', 'sidcraft-commerce-for-sidcraft-page-builder' ) . "\n" . $link;
		$headers = array();
		if ( is_email( $chat->email ) ) {
			$headers[] = 'Reply-To: ' . str_replace( array( "\r", "\n" ), '', $chat->email );
		}
		wp_mail( $to, $subject, $text, $headers );
	}

	/**
	 * Inbox.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_inbox( \WP_REST_Request $request ) {
		self::operator_seen();
		$out = array();
		foreach ( Chats::inbox( sanitize_key( (string) $request->get_param( 'status' ) ) ) as $row ) {
			$out[] = array(
				'id'      => (int) $row->id,
				'name'    => '' !== $row->name ? $row->name : __( 'Visitor', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'email'   => $row->email,
				'status'  => $row->status,
				'unread'  => (int) $row->unread,
				'last'    => wp_html_excerpt( (string) $row->last_body, 80, '…' ),
				'updated' => (int) $row->updated,
			);
		}
		return rest_ensure_response(
			array(
				'chats'  => $out,
				'unread' => Chats::unread_total(),
			)
		);
	}

	/**
	 * A conversation for the operator (marks it read).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_thread( \WP_REST_Request $request ) {
		self::operator_seen();
		$chat = Chats::get( (int) $request['id'] );
		if ( ! $chat ) {
			return new \WP_Error( 'sidccofo_chat_missing', __( 'This chat no longer exists.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
		}
		$messages = Chats::messages( (int) $chat->id, absint( $request->get_param( 'after' ) ) );
		if ( $messages ) {
			Chats::mark_read( $chat, 'operator', (int) end( $messages )['id'] );
		}
		$user   = $chat->user_id ? get_userdata( (int) $chat->user_id ) : null;
		$orders = 0;
		if ( '' !== $chat->email ) {
			$orders = count(
				get_posts(
					array(
						'post_type'      => Post_Types::ORDER,
						'post_status'    => 'any',
						'fields'         => 'ids',
						'posts_per_page' => 100,
						'no_found_rows'  => true,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin only, one conversation.
						'meta_key'       => '_sidccofo_billing_email',
						'meta_value'     => strtolower( $chat->email ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				)
			);
		}
		return rest_ensure_response(
			array(
				'chat'     => array(
					'id'      => (int) $chat->id,
					'name'    => $chat->name,
					'email'   => $chat->email,
					'status'  => $chat->status,
					'page'    => $chat->page,
					'created' => (int) $chat->created,
					'user'    => $user ? array(
						'name' => $user->display_name,
						'link' => get_edit_user_link( $user->ID ),
					) : null,
					'orders'  => $orders,
					'ordersLink' => '' !== $chat->email ? admin_url( 'edit.php?post_type=' . Post_Types::ORDER . '&s=' . rawurlencode( $chat->email ) ) : '',
				),
				'messages' => $messages,
				'visitorRead' => (int) $chat->visitor_read,
			)
		);
	}

	/**
	 * Operator reply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_reply( \WP_REST_Request $request ) {
		self::operator_seen();
		$chat = Chats::get( (int) $request['id'] );
		if ( ! $chat ) {
			return new \WP_Error( 'sidccofo_chat_missing', __( 'This chat no longer exists.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
		}
		$id = Chats::add_message( $chat, 'operator', (string) $request->get_param( 'body' ), get_current_user_id() );
		if ( ! $id ) {
			return new \WP_Error( 'sidccofo_chat_empty', __( 'Type a message first.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		// The visitor may have left: the operator can send the answer by email too.
		if ( is_email( $chat->email ) && $request->get_param( 'email' ) ) {
			$this->email_visitor( $chat, (string) $request->get_param( 'body' ) );
		}
		return $this->rest_thread( $request );
	}

	/**
	 * Send the operator's answer by email.
	 *
	 * @param object $chat Conversation.
	 * @param string $body Message.
	 */
	protected function email_visitor( $chat, $body ) {
		$store = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: store name. */
		$subject = sprintf( __( 'Reply from %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $store );
		$from    = sanitize_text_field( (string) setting( 'from_name', $store ) ) . ' <' . get_option( 'admin_email' ) . '>';
		wp_mail( $chat->email, $subject, sanitize_textarea_field( $body ) . "\n\n— " . $store . "\n" . home_url( '/' ), array( 'From: ' . str_replace( array( "\r", "\n" ), '', $from ) ) );
	}

	/**
	 * Open / close.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_set_status( \WP_REST_Request $request ) {
		$chat = Chats::get( (int) $request['id'] );
		if ( ! $chat ) {
			return new \WP_Error( 'sidccofo_chat_missing', __( 'This chat no longer exists.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
		}
		$status = 'closed' === $request->get_param( 'status' ) ? 'closed' : 'open';
		if ( 'closed' === $status && 'open' === $chat->status ) {
			Chats::add_message( $chat, 'system', __( 'Chat ended.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		Chats::set_status( (int) $chat->id, $status );
		return $this->rest_thread( $request );
	}

	/**
	 * Delete a conversation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_delete( \WP_REST_Request $request ) {
		Chats::delete( array( (int) $request['id'] ) );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Hourly tidy-up.
	 */
	public function tidy() {
		Chats::tidy( absint( setting( 'livechat_keep_days', 180 ) ) );
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
		$exporters['sidcraft-commerce-chat'] = array(
			'exporter_friendly_name' => __( 'Store live chats', 'sidcraft-commerce-for-sidcraft-page-builder' ),
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
		$erasers['sidcraft-commerce-chat'] = array(
			'eraser_friendly_name' => __( 'Store live chats', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export someone's chats.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public function export( $email ) {
		$data = array();
		foreach ( Chats::by_email( $email ) as $chat ) {
			$lines = array();
			foreach ( Chats::messages( (int) $chat->id, 0, 1000 ) as $m ) {
				$lines[] = wp_date( 'Y-m-d H:i', $m['time'] ) . ' ' . $m['sender'] . ': ' . $m['body'];
			}
			$data[] = array(
				'group_id'    => 'sidccofo-chats',
				'group_label' => __( 'Live chats', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'item_id'     => 'chat-' . $chat->id,
				'data'        => array(
					array(
						'name'  => __( 'Started', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'value' => wp_date( 'Y-m-d H:i', (int) $chat->created ),
					),
					array(
						'name'  => __( 'Messages', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'value' => implode( "\n", $lines ),
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
	 * Erase someone's chats.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public function erase( $email ) {
		$ids = array_map( 'intval', wp_list_pluck( Chats::by_email( $email ), 'id' ) );
		Chats::delete( $ids );
		return array(
			'items_removed'  => (bool) $ids,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
