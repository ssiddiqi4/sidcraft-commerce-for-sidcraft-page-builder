<?php
/**
 * The mailing list: one row per email address in {prefix}sidccofo_subscribers.
 *
 * Statuses: pending (waiting for the confirmation click), subscribed,
 * unsubscribed. Only subscribed addresses get marketing email. Each row has a
 * random token for its confirm and unsubscribe links, and keeps a few order
 * figures (orders, spent in the store currency, last order) so segments are a
 * simple query.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Class Subscribers
 */
class Subscribers {

	const STATUSES = array( 'pending', 'subscribed', 'unsubscribed' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sidccofo_subscribers';
	}

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function status_labels() {
		return array(
			'pending'      => __( 'Waiting for confirmation', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'subscribed'   => __( 'Subscribed', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'unsubscribed' => __( 'Unsubscribed', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * One subscriber by email.
	 *
	 * @param string $email Email.
	 * @return object|null
	 */
	public static function by_email( $email ) {
		global $wpdb;
		$email = self::normalize( $email );
		if ( '' === $email ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', self::table(), $email ) );
	}

	/**
	 * One subscriber by id.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), (int) $id ) );
	}

	/**
	 * One subscriber by link token.
	 *
	 * @param string $token Token.
	 * @return object|null
	 */
	public static function by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', (string) $token ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token = %s', self::table(), $token ) );
	}

	/**
	 * Lower-case, valid email or ''.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function normalize( $email ) {
		$email = strtolower( trim( sanitize_email( (string) $email ) ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Add someone, or update them. An unsubscribed address is only subscribed
	 * again when they asked for it themselves ($opt_in true); an import or a
	 * customer record never re-subscribes them.
	 *
	 * @param string $email  Email.
	 * @param array  $args   first_name, user_id, status (pending|subscribed), source, tags (string[]), opt_in (bool).
	 * @return object|null The row, or null for an invalid email.
	 */
	public static function add( $email, array $args = array() ) {
		global $wpdb;
		$email = self::normalize( $email );
		if ( '' === $email ) {
			return null;
		}
		$status   = in_array( $args['status'] ?? '', self::STATUSES, true ) ? $args['status'] : 'pending';
		$existing = self::by_email( $email );
		$tags     = self::clean_tags( $args['tags'] ?? array() );
		$name     = sanitize_text_field( (string) ( $args['first_name'] ?? '' ) );

		if ( $existing ) {
			$data = array();
			if ( '' !== $name && '' === $existing->first_name ) {
				$data['first_name'] = $name;
			}
			if ( ! empty( $args['user_id'] ) && ! (int) $existing->user_id ) {
				$data['user_id'] = (int) $args['user_id'];
			}
			if ( $tags ) {
				$data['tags'] = implode( ',', array_unique( array_merge( self::tags_of( $existing ), $tags ) ) );
			}
			$again = 'unsubscribed' === $existing->status && ! empty( $args['opt_in'] );
			if ( 'subscribed' === $status && ( 'pending' === $existing->status || $again ) ) {
				$data['status']    = 'subscribed';
				$data['confirmed'] = time();
			} elseif ( 'pending' === $status && $again ) {
				$data['status'] = 'pending';
			}
			if ( $data ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
				$wpdb->update( self::table(), $data, array( 'id' => (int) $existing->id ) );
			}
			$row = self::get( (int) $existing->id );
			if ( isset( $data['status'] ) && 'subscribed' === $data['status'] ) {
				/** This action is documented below. */
				do_action( 'sidcraft_commerce_subscriber_confirmed', $row );
			}
			return $row;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->insert(
			self::table(),
			array(
				'email'      => $email,
				'first_name' => $name,
				'user_id'    => (int) ( $args['user_id'] ?? 0 ),
				'status'     => $status,
				'token'      => wp_generate_password( 32, false ),
				'source'     => sanitize_key( (string) ( $args['source'] ?? '' ) ),
				'tags'       => implode( ',', $tags ),
				'created'    => time(),
				'confirmed'  => 'subscribed' === $status ? time() : 0,
			)
		);
		$row = self::get( (int) $wpdb->insert_id );
		if ( $row && 'subscribed' === $row->status ) {
			/**
			 * Someone joined the list (confirmed, or subscribed at checkout).
			 *
			 * @param object $row Subscriber row.
			 */
			do_action( 'sidcraft_commerce_subscriber_confirmed', $row );
		}
		return $row;
	}

	/**
	 * Confirm a pending subscriber (their link click).
	 *
	 * @param object $row Subscriber.
	 * @return bool Whether they are subscribed now.
	 */
	public static function confirm( $row ) {
		global $wpdb;
		if ( ! $row || 'unsubscribed' === $row->status ) {
			return false;
		}
		if ( 'pending' === $row->status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			$wpdb->update(
				self::table(),
				array(
					'status'    => 'subscribed',
					'confirmed' => time(),
				),
				array( 'id' => (int) $row->id )
			);
			/** This action is documented in add(). */
			do_action( 'sidcraft_commerce_subscriber_confirmed', self::get( (int) $row->id ) );
		}
		return true;
	}

	/**
	 * Set a status.
	 *
	 * @param int    $id     Subscriber.
	 * @param string $status Status.
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		$data = array( 'status' => $status );
		if ( 'subscribed' === $status ) {
			$data['confirmed'] = time();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete subscribers (and their email log).
	 *
	 * @param int[] $ids Ids.
	 */
	public static function delete( array $ids ) {
		global $wpdb;
		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables.
			$wpdb->delete( self::table(), array( 'id' => $id ) );
			$wpdb->delete( $wpdb->prefix . 'sidccofo_email_log', array( 'subscriber_id' => $id ) );
			// phpcs:enable
		}
	}

	/**
	 * Count an order on its customer's row (segments read these figures).
	 *
	 * @param string $email Email.
	 * @param float  $total Order total in the store currency.
	 * @param int    $time  Order time.
	 */
	public static function record_order( $email, $total, $time ) {
		global $wpdb;
		$email = self::normalize( $email );
		if ( '' === $email ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; one atomic update.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET orders = orders + 1, spent = spent + %f, last_order = GREATEST(last_order, %d) WHERE email = %s', self::table(), (float) $total, (int) $time, $email ) );
	}

	/**
	 * Tags as a clean list.
	 *
	 * @param string|string[] $tags Comma list or array.
	 * @return string[]
	 */
	public static function clean_tags( $tags ) {
		$tags = is_array( $tags ) ? $tags : explode( ',', (string) $tags );
		$out  = array();
		foreach ( $tags as $tag ) {
			$tag = sanitize_title( (string) $tag );
			if ( '' !== $tag ) {
				$out[] = substr( $tag, 0, 40 );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A row's tags.
	 *
	 * @param object $row Subscriber.
	 * @return string[]
	 */
	public static function tags_of( $row ) {
		return array_values( array_filter( explode( ',', (string) $row->tags ) ) );
	}

	/**
	 * Segments: key => label. A campaign goes to subscribed people in one.
	 *
	 * @return array
	 */
	public static function segments() {
		return array(
			'all'        => __( 'Everyone subscribed', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'customers'  => __( 'Customers (one order or more)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'prospects'  => __( 'Not customers yet', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'spent_over' => __( 'Spent more than… (amount)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'inactive'   => __( 'No order in the last … days', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'recent'     => __( 'Ordered in the last … days', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'tag'        => __( 'Tagged… (tag)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Values for the segment conditions in segment SQL (see in_segment()):
	 * orders from–to, spent over, last order from–before, tag (or '').
	 *
	 * Every segment uses the same fixed SQL with these bounds, so no SQL is
	 * ever built from variables.
	 *
	 * @param string $segment Segment.
	 * @param string $value   Value.
	 * @return array
	 */
	public static function segment_args( $segment, $value ) {
		global $wpdb;
		$orders = array( 0, 4294967295 );
		$spent  = -1.0;
		$last   = array( 0, PHP_INT_MAX );
		$tag    = '';
		$days   = time() - max( 1, (int) $value ) * DAY_IN_SECONDS;
		switch ( $segment ) {
			case 'customers':
				$orders[0] = 1;
				break;
			case 'prospects':
				$orders[1] = 0;
				break;
			case 'spent_over':
				$spent = (float) $value;
				break;
			case 'inactive':
				$orders[0] = 1;
				$last[1]   = $days;
				break;
			case 'recent':
				$last[0] = $days;
				break;
			case 'tag':
				$tag = sanitize_title( (string) $value );
				break;
		}
		// Tags are stored as "a,b,c": match a whole tag (portable, unlike FIND_IN_SET).
		$like = $wpdb->esc_like( $tag );
		return array( $orders[0], $orders[1], $spent, $last[0], $last[1], $tag, $tag, $like . ',%', '%,' . $like, '%,' . $like . ',%' );
	}

	/**
	 * Subscribers in a segment after an id (for batches).
	 *
	 * @param string $segment Segment.
	 * @param string $value   Value.
	 * @param int    $after   Last id done.
	 * @param int    $limit   Batch size.
	 * @return object[]
	 */
	public static function in_segment( $segment, $value, $after, $limit ) {
		global $wpdb;
		$a = self::segment_args( $segment, $value );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'subscribed' AND orders >= %d AND orders <= %d AND spent > %f AND last_order >= %d AND last_order < %d AND ( %s = '' OR tags = %s OR tags LIKE %s OR tags LIKE %s OR tags LIKE %s ) AND id > %d ORDER BY id ASC LIMIT %d",
				self::table(),
				$a[0],
				$a[1],
				$a[2],
				$a[3],
				$a[4],
				$a[5],
				$a[6],
				$a[7],
				$a[8],
				$a[9],
				(int) $after,
				(int) $limit
			)
		);
	}

	/**
	 * How many subscribers a segment has.
	 *
	 * @param string $segment Segment.
	 * @param string $value   Value.
	 * @return int
	 */
	public static function count_segment( $segment, $value ) {
		global $wpdb;
		$a = self::segment_args( $segment, $value );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE status = 'subscribed' AND orders >= %d AND orders <= %d AND spent > %f AND last_order >= %d AND last_order < %d AND ( %s = '' OR tags = %s OR tags LIKE %s OR tags LIKE %s OR tags LIKE %s )",
				self::table(),
				$a[0],
				$a[1],
				$a[2],
				$a[3],
				$a[4],
				$a[5],
				$a[6],
				$a[7],
				$a[8],
				$a[9]
			)
		);
	}

	/**
	 * Counts by status.
	 *
	 * @return array status => count.
	 */
	public static function counts() {
		global $wpdb;
		$out = array_fill_keys( self::STATUSES, 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', self::table() ) ) as $row ) {
			$out[ $row->status ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * A page of subscribers for the admin list.
	 *
	 * @param array $args status, search, page, per_page.
	 * @return array [ rows, total ].
	 */
	public static function query( array $args ) {
		global $wpdb;
		$status = in_array( $args['status'] ?? '', self::STATUSES, true ) ? (string) $args['status'] : '';
		$search = (string) ( $args['search'] ?? '' );
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$per    = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$offset = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per );
		$table  = self::table();
		// Empty status or search: that condition is skipped ('' = '').
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = '' OR status = %s ) AND ( %s = '' OR email LIKE %s OR first_name LIKE %s OR tags LIKE %s ) ORDER BY id DESC LIMIT %d OFFSET %d", $table, $status, $status, $search, $like, $like, $like, $per, $offset ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = '' OR status = %s ) AND ( %s = '' OR email LIKE %s OR first_name LIKE %s OR tags LIKE %s )", $table, $status, $status, $search, $like, $like, $like ) );
		// phpcs:enable
		return array( $rows, $total );
	}

	/**
	 * Confirm and unsubscribe links.
	 *
	 * @param object $row    Subscriber.
	 * @param string $action confirm|unsubscribe.
	 * @return string
	 */
	public static function link( $row, $action ) {
		return add_query_arg(
			array(
				'sidccofo_list' => $action,
				't'             => $row->token,
			),
			home_url( '/' )
		);
	}
}
