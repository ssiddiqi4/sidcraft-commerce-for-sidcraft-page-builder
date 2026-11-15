<?php
/**
 * Store → Live chat: the inbox where the store answers visitors. While it is
 * open the store shows as online (see Live_Chat::operator_seen()).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Admin;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Live_Chat\Chats;
use SidcraftCommerce\Rest;
use function SidcraftCommerce\manage_cap;

defined( 'ABSPATH' ) || exit;

/**
 * Class Live_Chat_Admin
 */
class Live_Chat_Admin {

	const PAGE = 'sidccofo-live-chat';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected $hook = '';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Submenu under Store, with the unread count.
	 */
	public function menu() {
		if ( ! current_user_can( manage_cap() ) ) {
			return;
		}
		$unread = get_transient( 'sidccofo_chat_unread' );
		if ( false === $unread ) {
			$unread = Chats::unread_total();
			set_transient( 'sidccofo_chat_unread', $unread, 30 );
		}
		$label = __( 'Live chat', 'sidcraft-commerce-for-sidcraft-page-builder' );
		if ( (int) $unread > 0 ) {
			$label .= ' <span class="awaiting-mod"><span class="pending-count">' . esc_html( number_format_i18n( (int) $unread ) ) . '</span></span>';
		}
		$this->hook = (string) add_submenu_page(
			'edit.php?post_type=' . Post_Types::PRODUCT,
			__( 'Live chat', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			$label,
			manage_cap(),
			self::PAGE,
			array( $this, 'page' )
		);
	}

	/**
	 * Inbox script on the inbox page only.
	 *
	 * @param string $hook Page hook.
	 */
	public function assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		$url = SIDCRAFT_COMMERCE_URL . 'assets/';
		wp_enqueue_style( 'sidccofo-chat-admin', $url . 'css/sc-chat-admin.css', array(), SIDCRAFT_COMMERCE_VERSION );
		wp_enqueue_script( 'sidccofo-chat-admin', $url . 'js/sc-chat-admin.js', array(), SIDCRAFT_COMMERCE_VERSION, true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which conversation to show first.
		$open = isset( $_GET['chat'] ) ? absint( $_GET['chat'] ) : 0;
		wp_add_inline_script(
			'sidccofo-chat-admin',
			'window.sidccofoChatAdmin = ' . wp_json_encode(
				array(
					'api'   => esc_url_raw( rest_url( Rest::NS . '/chat/admin/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'open'  => $open,
					'i18n'  => array(
						'open'      => __( 'Open', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'closed'    => __( 'Ended', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'all'       => __( 'All', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'none'      => __( 'No chats here yet. Visitors see you as online while this page is open.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'pick'      => __( 'Choose a chat on the left.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'reply'     => __( 'Type your reply…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'send'      => __( 'Send', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'emailToo'  => __( 'Also email this reply', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'end'       => __( 'End chat', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'reopen'    => __( 'Reopen', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'delete'    => __( 'Delete', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'confirm'   => __( 'Delete this chat for good?', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'visitor'   => __( 'Visitor', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'you'       => __( 'You', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'orders'    => __( 'Orders', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'account'   => __( 'Account', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'page'      => __( 'Started on', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'seen'      => __( 'Seen', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'error'     => __( 'Something went wrong. Please try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'online'    => __( 'You are online for visitors while this page is open.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'title'     => __( 'Live chat', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Inbox page (built by the script).
	 */
	public function page() {
		if ( ! current_user_can( manage_cap() ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Live chat', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h1>';
		echo '<div id="sc-chat-inbox" class="sc-inbox"><p>' . esc_html__( 'Loading…', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p></div>';
		echo '<noscript><p>' . esc_html__( 'The inbox needs JavaScript.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p></noscript></div>';
	}
}
