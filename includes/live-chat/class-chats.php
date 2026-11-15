<?php
/**
 * Live chat storage: conversations in {prefix}sidccofo_chats and their
 * messages in {prefix}sidccofo_chat_messages.
 *
 * A conversation belongs to a visitor key (a random cookie) and, for
 * logged-in customers, their user id. operator_read / visitor_read hold the
 * id of the last message each side has seen, so unread counts are a cheap
 * indexed query.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Live_Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Class Chats
 */
class Chats {

	/** Longest message. */
	const MAX_LENGTH = 2000;

	/**
	 * Conversations table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sidccofo_chats';
	}

	/**
	 * Messages table.
	 *
	 * @return string
	 */
	public static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'sidccofo_chat_messages';
	}

	/**
	 * Start a conversation.
	 *
	 * @param string $visitor Visitor key.
	 * @param array  $args    user_id, name, email, page.
	 * @return object|null
	 */
	public static function create( $visitor, array $args ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->insert(
			self::table(),
			array(
				'visitor' => $visitor,
				'user_id' => (int) ( $args['user_id'] ?? 0 ),
				'name'    => substr( sanitize_text_field( (string) ( $args['name'] ?? '' ) ), 0, 100 ),
				'email'   => substr( sanitize_email( (string) ( $args['email'] ?? '' ) ), 0, 190 ),
				'status'  => 'open',
				'page'    => substr( esc_url_raw( (string) ( $args['page'] ?? '' ) ), 0, 255 ),
				'created' => time(),
				'updated' => time(),
			)
		);
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * One conversation.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; read fresh (another person writes to it).
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), (int) $id ) );
	}

	/**
	 * A visitor's open conversation (or one closed in the last hour, so the
	 * last answer stays readable).
	 *
	 * @param string $visitor Visitor key.
	 * @return object|null
	 */
	public static function for_visitor( $visitor ) {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', (string) $visitor ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE visitor = %s AND (status = 'open' OR updated > %d) ORDER BY id DESC LIMIT 1", self::table(), $visitor, time() - HOUR_IN_SECONDS ) );
	}

	/**
	 * Add a message.
	 *
	 * @param object $chat      Conversation.
	 * @param string $sender    visitor|operator|system.
	 * @param string $body      Text.
	 * @param int    $author_id Operator user id.
	 * @return int Message id (0 when empty).
	 */
	public static function add_message( $chat, $sender, $body, $author_id = 0 ) {
		global $wpdb;
		$body = trim( sanitize_textarea_field( (string) $body ) );
		if ( '' === $body ) {
			return 0;
		}
		if ( function_exists( 'mb_substr' ) ) {
			$body = mb_substr( $body, 0, self::MAX_LENGTH );
		} else {
			$body = substr( $body, 0, self::MAX_LENGTH );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables.
		$wpdb->insert(
			self::messages_table(),
			array(
				'chat_id'   => (int) $chat->id,
				'sender'    => in_array( $sender, array( 'visitor', 'operator', 'system' ), true ) ? $sender : 'system',
				'author_id' => (int) $author_id,
				'body'      => $body,
				'created'   => time(),
			)
		);
		$id   = (int) $wpdb->insert_id;
		$data = array(
			'updated' => time(),
			'status'  => 'open',
		);
		// Your own message counts as read.
		if ( 'visitor' === $sender ) {
			$data['visitor_read'] = $id;
		} elseif ( 'operator' === $sender ) {
			$data['operator_read'] = $id;
		}
		$wpdb->update( self::table(), $data, array( 'id' => (int) $chat->id ) );
		// phpcs:enable
		return $id;
	}

	/**
	 * Messages after an id.
	 *
	 * @param int $chat_id Conversation.
	 * @param int $after   Last id the reader has.
	 * @param int $limit   Max.
	 * @return array[] id, sender, body, time, author.
	 */
	public static function messages( $chat_id, $after = 0, $limit = 100 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, sender, author_id, body, created FROM %i WHERE chat_id = %d AND id > %d ORDER BY id ASC LIMIT %d', self::messages_table(), (int) $chat_id, (int) $after, (int) $limit ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$author = '';
			if ( 'operator' === $row->sender && $row->author_id ) {
				$user   = get_userdata( (int) $row->author_id );
				$author = $user ? ( $user->first_name ? $user->first_name : $user->display_name ) : '';
			}
			$out[] = array(
				'id'     => (int) $row->id,
				'sender' => $row->sender,
				'body'   => $row->body,
				'time'   => (int) $row->created,
				'author' => $author,
			);
		}
		return $out;
	}

	/**
	 * Mark read up to a message.
	 *
	 * @param object $chat Conversation.
	 * @param string $side operator|visitor.
	 * @param int    $id   Message id.
	 */
	public static function mark_read( $chat, $side, $id ) {
		global $wpdb;
		$column = 'operator' === $side ? 'operator_read' : 'visitor_read';
		if ( (int) $id <= (int) $chat->$column ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->update( self::table(), array( $column => (int) $id ), array( 'id' => (int) $chat->id ) );
	}

	/**
	 * Open or close.
	 *
	 * @param int    $id     Conversation.
	 * @param string $status open|closed.
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->update(
			self::table(),
			array(
				'status'  => 'closed' === $status ? 'closed' : 'open',
				'updated' => time(),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Delete conversations and their messages.
	 *
	 * @param int[] $ids Conversations.
	 */
	public static function delete( array $ids ) {
		global $wpdb;
		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables.
			$wpdb->delete( self::messages_table(), array( 'chat_id' => $id ) );
			$wpdb->delete( self::table(), array( 'id' => $id ) );
			// phpcs:enable
		}
	}

	/**
	 * Conversations for the inbox, newest activity first, with unread counts.
	 *
	 * @param string $status open|closed|'' (all).
	 * @param int    $limit  Max.
	 * @return object[]
	 */
	public static function inbox( $status, $limit = 50 ) {
		global $wpdb;
		$where = in_array( $status, array( 'open', 'closed' ), true ) ? $wpdb->prepare( 'c.status = %s', $status ) : '1=1';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom tables; $where is prepared above.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.*, (SELECT COUNT(*) FROM %i m WHERE m.chat_id = c.id AND m.sender = 'visitor' AND m.id > c.operator_read) AS unread, (SELECT body FROM %i m2 WHERE m2.chat_id = c.id ORDER BY m2.id DESC LIMIT 1) AS last_body FROM %i c WHERE {$where} ORDER BY c.updated DESC LIMIT %d", self::messages_table(), self::messages_table(), self::table(), (int) $limit ) );
	}

	/**
	 * Visitor messages the store hasn't read, over open conversations.
	 *
	 * @return int
	 */
	public static function unread_total() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom tables.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i m INNER JOIN %i c ON c.id = m.chat_id WHERE c.status = 'open' AND m.sender = 'visitor' AND m.id > c.operator_read", self::messages_table(), self::table() ) );
	}

	/**
	 * Close conversations idle for a day; delete closed ones older than $days.
	 *
	 * @param int $days Days to keep (0 = forever).
	 */
	public static function tidy( $days ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'closed' WHERE status = 'open' AND updated < %d", self::table(), time() - DAY_IN_SECONDS ) );
		if ( $days > 0 ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'closed' AND updated < %d LIMIT 500", self::table(), time() - $days * DAY_IN_SECONDS ) );
			self::delete( array_map( 'intval', $ids ) );
		}
		// phpcs:enable
	}

	/**
	 * Conversations of an email (privacy tools).
	 *
	 * @param string $email Email.
	 * @return object[]
	 */
	public static function by_email( $email ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( '' === $email ) {
			return array();
		}
		$user = get_user_by( 'email', $email );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s OR (user_id > 0 AND user_id = %d) ORDER BY id ASC', self::table(), $email, $user ? (int) $user->ID : 0 ) );
	}
}
