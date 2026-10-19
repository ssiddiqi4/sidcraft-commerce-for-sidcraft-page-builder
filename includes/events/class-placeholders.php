<?php
/**
 * Hidden product placeholders: one private store product per ticket type.
 *
 * Tickets go through the normal cart, checkout and payment gateways, so the
 * store's currency, tax rules, coupons, gift cards, stock locking, refunds
 * and order emails all apply to them unchanged. The placeholders are kept out
 * of the shop, search and the Products list, and every link to one points to
 * its event.
 *
 * Product meta:
 *   _sidccofo_ev_event (event ID), _sidccofo_ev_type (ticket type id),
 *   _sidccofo_ev_held (seats held by orders), _sidccofo_ev_removed (type deleted).
 * Order meta:
 *   _sidccofo_ev_holds (product ID => seats this order holds).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\parse_price;

defined( 'ABSPATH' ) || exit;

/**
 * Class Placeholders
 */
class Placeholders {

	const EVENT   = '_sidccofo_ev_event';
	const TYPE    = '_sidccofo_ev_type';
	const HELD    = '_sidccofo_ev_held';
	const REMOVED = '_sidccofo_ev_removed';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_filter( 'sidcraft_commerce_is_purchasable', array( $this, 'purchasable' ), 10, 2 );
		add_filter( 'sidcraft_commerce_product_price', array( $this, 'price' ), 10, 2 );
		add_filter( 'sidcraft_commerce_validate_cart_item', array( $this, 'validate' ), 20, 3 );
		add_filter( 'sidcraft_commerce_product_grid_query_args', array( $this, 'grid_args' ) );
		add_filter( 'private_title_format', array( $this, 'title_format' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'permalink' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'hide_from_queries' ) );
		add_action( 'load-post.php', array( $this, 'redirect_edit' ) );

		add_action( 'sidcraft_commerce_order_created', array( $this, 'reconcile' ), 5 );
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'reconcile' ), 4 );
		add_action( 'updated_post_meta', array( $this, 'refund_quantities_changed' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'refund_quantities_changed' ), 10, 3 );
	}

	/*
	------------------------------------------------------------------
	 * Lookups
	 * ----------------------------------------------------------------
	 */

	/**
	 * Event ID a product sells tickets for (0 = not a placeholder).
	 *
	 * @param int $product_id Product.
	 * @return int
	 */
	public static function event_id( $product_id ) {
		return (int) get_post_meta( (int) $product_id, self::EVENT, true );
	}

	/**
	 * Is this product a ticket placeholder?
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function is_placeholder( $product_id ) {
		return self::event_id( $product_id ) > 0;
	}

	/**
	 * Event and ticket type behind a placeholder.
	 *
	 * @param int $product_id Product.
	 * @return array{0:?Event,1:?array}
	 */
	public static function resolve( $product_id ) {
		$event = Event::get( self::event_id( $product_id ) );
		if ( ! $event || get_post_meta( (int) $product_id, self::REMOVED, true ) ) {
			return array( $event, null );
		}
		$type = $event->get_ticket_type( (string) get_post_meta( (int) $product_id, self::TYPE, true ) );
		return array( $event, $type && (int) $type['product_id'] === (int) $product_id ? $type : null );
	}

	/**
	 * Seats held by orders for a placeholder.
	 *
	 * @param int $product_id Product.
	 * @return int
	 */
	public static function held( $product_id ) {
		return max( 0, (int) get_post_meta( (int) $product_id, self::HELD, true ) );
	}

	/**
	 * Placeholders of an event (all, including removed types).
	 *
	 * @param int $event_id Event.
	 * @return int[]
	 */
	public static function products_of( $event_id ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Post_Types::PRODUCT,
					'post_status'    => array( 'private', 'publish', 'draft' ),
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => self::EVENT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin/event save only.
					'meta_value'     => (int) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * Sync from the event
	 * ----------------------------------------------------------------
	 */

	/**
	 * Create or update the placeholder of every ticket type and write their
	 * product IDs back to the event. Types that were deleted keep their
	 * product (orders link to it) but can no longer be bought.
	 *
	 * @param Event $event Event.
	 */
	public static function sync( Event $event ) {
		$raw   = $event->meta( 'tickets' );
		$raw   = is_array( $raw ) ? $raw : array();
		$keep  = array();
		$thumb = (int) get_post_thumbnail_id( $event->get_id() );
		$date  = $event->get_start() ? wp_date( get_option( 'date_format' ), $event->get_start() ) : '';

		foreach ( $raw as $i => $row ) {
			$type = Event::normalize_ticket_type( (array) $row );
			if ( '' === $type['id'] ) {
				continue;
			}
			$title = $event->get_name() . ' – ' . ( '' !== $type['name'] ? $type['name'] : __( 'Ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) . ( '' !== $date ? ' (' . $date . ')' : '' );
			$pid   = $type['product_id'];
			if ( ! $pid || ! Product::get( $pid ) || self::event_id( $pid ) !== $event->get_id() ) {
				$pid = wp_insert_post(
					array(
						'post_type'      => Post_Types::PRODUCT,
						'post_status'    => 'private',
						'post_title'     => $title,
						'post_content'   => '',
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
						'meta_input'     => array(
							self::EVENT => $event->get_id(),
							self::TYPE  => $type['id'],
							self::HELD  => 0,
						),
					),
					true
				);
				if ( is_wp_error( $pid ) ) {
					continue;
				}
			} else {
				wp_update_post(
					array(
						'ID'          => $pid,
						'post_title'  => $title,
						'post_status' => 'private',
					)
				);
			}

			$price = parse_price( $type['price'] );
			update_post_meta( $pid, '_sidccofo_regular_price', $price );
			update_post_meta( $pid, '_sidccofo_sale_price', '' );
			update_post_meta( $pid, '_sidccofo_sku', 'EV' . $event->get_id() . '-' . strtoupper( $type['id'] ) );
			update_post_meta( $pid, '_sidccofo_virtual', 1 );
			update_post_meta( $pid, '_sidccofo_hide_buy_box', 1 );
			update_post_meta( $pid, '_sidccofo_options', '' );
			update_post_meta( $pid, '_sidccofo_gift_card', 0 );
			update_post_meta( $pid, self::EVENT, $event->get_id() );
			update_post_meta( $pid, self::TYPE, $type['id'] );
			delete_post_meta( $pid, self::REMOVED );
			if ( $type['capacity'] ) {
				update_post_meta( $pid, '_sidccofo_manage_stock', 1 );
				update_post_meta( $pid, '_sidccofo_stock', max( 0, $type['capacity'] - self::held( $pid ) ) );
			} else {
				update_post_meta( $pid, '_sidccofo_manage_stock', 0 );
				update_post_meta( $pid, '_sidccofo_stock_status', 'instock' );
			}
			if ( $thumb ) {
				update_post_meta( $pid, '_thumbnail_id', $thumb );
			} else {
				delete_post_meta( $pid, '_thumbnail_id' );
			}
			Product::sync_derived_meta( $pid );
			Product::forget( $pid );

			$raw[ $i ]['product_id'] = (int) $pid;
			$keep[]                  = (int) $pid;
		}
		update_post_meta( $event->get_id(), '_sidccofo_ev_tickets', array_values( $raw ) );
		Event::forget( $event->get_id() );

		foreach ( self::products_of( $event->get_id() ) as $pid ) {
			if ( ! in_array( $pid, $keep, true ) ) {
				update_post_meta( $pid, self::REMOVED, 1 );
				Product::forget( $pid );
			}
		}
	}

	/*
	------------------------------------------------------------------
	 * Product behaviour
	 * ----------------------------------------------------------------
	 */

	/**
	 * Placeholders are private posts; they can be bought while their ticket type is on sale.
	 *
	 * @param bool    $ok      Purchasable.
	 * @param Product $product Product.
	 * @return bool
	 */
	public function purchasable( $ok, $product ) {
		if ( ! $product instanceof Product || ! self::is_placeholder( $product->get_id() ) ) {
			return $ok;
		}
		list( $event, $type ) = self::resolve( $product->get_id() );
		return $event && $type && '' === $event->type_unavailable_reason( $type );
	}

	/**
	 * Current ticket price (early-bird, demand and group rules).
	 *
	 * @param float   $price   Price.
	 * @param Product $product Product.
	 * @return float
	 */
	public function price( $price, $product ) {
		if ( ! $product instanceof Product || ! self::is_placeholder( $product->get_id() ) ) {
			return $price;
		}
		list( $event, $type ) = self::resolve( $product->get_id() );
		if ( ! $event || ! $type ) {
			return $price;
		}
		$qty = 0;
		if ( ( ! is_admin() || wp_doing_ajax() ) && did_action( 'init' ) && class_exists( Cart::class ) ) {
			$qty = Cart::instance()->product_quantity( $product->get_id() );
		}
		return Pricing::current( $event, $type, $qty )['price'];
	}

	/**
	 * Ticket limits: per-order minimum and maximum, event-wide seats, and a
	 * clear reason when a ticket type isn't on sale.
	 *
	 * @param true|\WP_Error $result   Result so far.
	 * @param Product|null   $product  Product.
	 * @param int            $quantity Units wanted (all lines of this product).
	 * @return true|\WP_Error
	 */
	public function validate( $result, $product, $quantity ) {
		if ( ! $product instanceof Product || ! self::is_placeholder( $product->get_id() ) ) {
			return $result;
		}
		list( $event, $type ) = self::resolve( $product->get_id() );
		if ( ! $event || ! $type ) {
			return new \WP_Error( 'sidccofo_unavailable', __( 'These tickets are no longer available.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$label = $event->get_name() . ' – ' . $type['name'];
		if ( is_wp_error( $result ) ) {
			if ( 'sidccofo_unavailable' === $result->get_error_code() ) {
				$reason = $event->type_unavailable_reason( $type );
				/* translators: 1: ticket name, 2: reason. */
				return new \WP_Error( 'sidccofo_unavailable', '' !== $reason ? sprintf( __( '%1$s: %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $label, $reason ) : $result->get_error_message() );
			}
			if ( 'sidccofo_not_enough_stock' === $result->get_error_code() ) {
				/* translators: 1: tickets left, 2: ticket name. */
				return new \WP_Error( 'sidccofo_not_enough_stock', sprintf( _n( 'Only %1$d ticket left for %2$s.', 'Only %1$d tickets left for %2$s.', (int) $product->get_stock_quantity(), 'sidcraft-commerce-for-sidcraft-page-builder' ), (int) $product->get_stock_quantity(), $label ) );
			}
			return $result;
		}
		$quantity = (int) $quantity;
		if ( $type['max'] && $quantity > $type['max'] ) {
			/* translators: 1: number, 2: ticket name. */
			return new \WP_Error( 'sidccofo_event_max', sprintf( _n( 'You can buy up to %1$d ticket for %2$s per order.', 'You can buy up to %1$d tickets for %2$s per order.', $type['max'], 'sidcraft-commerce-for-sidcraft-page-builder' ), $type['max'], $label ) );
		}
		if ( $type['min'] > 1 && $quantity > 0 && $quantity < $type['min'] ) {
			/* translators: 1: number, 2: ticket name. */
			return new \WP_Error( 'sidccofo_event_min', sprintf( __( 'Choose at least %1$d tickets for %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $type['min'], $label ) );
		}
		$left = $event->get_remaining();
		if ( null !== $left ) {
			$in_cart = $quantity;
			if ( did_action( 'init' ) ) {
				$cart = Cart::instance();
				foreach ( $event->get_ticket_types() as $other ) {
					if ( $other['product_id'] && $other['product_id'] !== $product->get_id() ) {
						$in_cart += $cart->product_quantity( $other['product_id'] );
					}
				}
			}
			if ( $in_cart > $left ) {
				/* translators: 1: seats left, 2: event name. */
				return new \WP_Error( 'sidccofo_event_full', sprintf( _n( 'Only %1$d seat is left for %2$s.', 'Only %1$d seats are left for %2$s.', $left, 'sidcraft-commerce-for-sidcraft-page-builder' ), $left, $event->get_name() ) );
			}
		}
		return $result;
	}

	/**
	 * Keep placeholders out of product grids.
	 *
	 * @param array $args WP_Query args.
	 * @return array
	 */
	public function grid_args( $args ) {
		$args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$args['meta_query'][] = array(
			'key'     => self::EVENT,
			'compare' => 'NOT EXISTS',
		);
		return $args;
	}

	/**
	 * Keep placeholders out of the Products list, front-end searches and
	 * product archives.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function hide_from_queries( $query ) {
		if ( ! $query instanceof \WP_Query ) {
			return;
		}
		$types = (array) $query->get( 'post_type' );
		if ( is_admin() ) {
			global $pagenow;
			if ( ! $query->is_main_query() || 'edit.php' !== $pagenow || ! in_array( Post_Types::PRODUCT, $types, true ) ) {
				return;
			}
		} elseif ( ! $query->is_main_query() || ( ! $query->is_search() && ! in_array( Post_Types::PRODUCT, $types, true ) ) ) {
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'     => self::EVENT,
			'compare' => 'NOT EXISTS',
		);
		$query->set( 'meta_query', $meta );
	}

	/**
	 * No "Private:" prefix on ticket names.
	 *
	 * @param string   $format Format.
	 * @param \WP_Post $post   Post.
	 * @return string
	 */
	public function title_format( $format, $post = null ) {
		return $post && Post_Types::PRODUCT === $post->post_type && self::is_placeholder( $post->ID ) ? '%s' : $format;
	}

	/**
	 * Links to a placeholder go to its event.
	 *
	 * @param string   $link Link.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function permalink( $link, $post ) {
		if ( $post && Post_Types::PRODUCT === $post->post_type ) {
			$event_id = self::event_id( $post->ID );
			if ( $event_id && Events::EVENT === get_post_type( $event_id ) ) {
				return (string) get_permalink( $event_id );
			}
		}
		return $link;
	}

	/**
	 * Editing a placeholder opens its event instead.
	 */
	public function redirect_edit() {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		if ( $id && Post_Types::PRODUCT === get_post_type( $id ) && self::is_placeholder( $id ) ) {
			$event_id = self::event_id( $id );
			if ( get_post( $event_id ) ) {
				wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $event_id ) );
				exit;
			}
		}
	}

	/*
	------------------------------------------------------------------
	 * Seats held by orders
	 * ----------------------------------------------------------------
	 */

	/**
	 * Bring the held counters in line with what an order holds now. Called
	 * on creation, on every status change and after partial refunds.
	 *
	 * @param Order $order Order.
	 */
	public function reconcile( $order ) {
		if ( ! $order instanceof Order ) {
			return;
		}
		$now = array();
		if ( get_post_meta( $order->get_id(), '_sidccofo_stock_reduced', true ) ) {
			foreach ( $order->quantities_held() as $pid => $qty ) {
				if ( $qty > 0 && self::is_placeholder( $pid ) ) {
					$now[ (int) $pid ] = (int) $qty;
				}
			}
		}
		$before = get_post_meta( $order->get_id(), '_sidccofo_ev_holds', true );
		$before = is_array( $before ) ? array_map( 'intval', $before ) : array();
		if ( $now === $before ) {
			return;
		}
		foreach ( array_unique( array_merge( array_keys( $now ), array_keys( $before ) ) ) as $pid ) {
			$delta = ( $now[ $pid ] ?? 0 ) - ( $before[ $pid ] ?? 0 );
			if ( $delta ) {
				self::adjust_held( (int) $pid, $delta );
			}
		}
		update_post_meta( $order->get_id(), '_sidccofo_ev_holds', $now );

		/**
		 * Fires when the seats an order holds change (placed, cancelled,
		 * refunded, partly refunded, reopened).
		 *
		 * @param Order $order  Order.
		 * @param array $now    product ID => seats held now.
		 * @param array $before product ID => seats held before.
		 */
		do_action( 'sidcraft_commerce_event_holds_changed', $order, $now, $before );
	}

	/**
	 * A partial refund named ticket quantities.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Post.
	 * @param string $meta_key Key.
	 */
	public function refund_quantities_changed( $meta_id, $post_id, $meta_key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		if ( '_sidccofo_refunded_qty' !== $meta_key || Post_Types::ORDER !== get_post_type( $post_id ) ) {
			return;
		}
		$order = Order::get( $post_id );
		if ( $order && get_post_meta( $post_id, '_sidccofo_ev_holds', true ) ) {
			$this->reconcile( $order );
		}
	}

	/**
	 * Change a placeholder's held counter.
	 *
	 * @param int $product_id Product.
	 * @param int $delta      Seats.
	 */
	public static function adjust_held( $product_id, $delta ) {
		global $wpdb;
		$product_id = (int) $product_id;
		if ( '' === get_post_meta( $product_id, self::HELD, true ) ) {
			add_post_meta( $product_id, self::HELD, 0, true );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic counter; update_post_meta cannot add in place.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d) WHERE post_id = %d AND meta_key = %s", (int) $delta, $product_id, self::HELD ) );
		wp_cache_delete( $product_id, 'post_meta' );
		Product::forget( $product_id );
	}

	/**
	 * Recount held seats for an event from its orders (Events → Tools).
	 *
	 * @param Event $event Event.
	 * @return array product ID => seats.
	 */
	public static function recount( Event $event ) {
		global $wpdb;
		$pids   = self::products_of( $event->get_id() );
		$counts = array_fill_keys( $pids, 0 );
		if ( ! $pids ) {
			return $counts;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off recount over our own meta.
		$order_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", '_sidccofo_stock_reduced', '1' ) );
		foreach ( $order_ids as $oid ) {
			$order = Order::get( (int) $oid );
			if ( ! $order ) {
				continue;
			}
			$holds = array();
			foreach ( $order->quantities_held() as $pid => $qty ) {
				if ( isset( $counts[ $pid ] ) && $qty > 0 ) {
					$counts[ $pid ] += (int) $qty;
				}
				if ( $qty > 0 && self::is_placeholder( $pid ) ) {
					$holds[ (int) $pid ] = (int) $qty;
				}
			}
			update_post_meta( $order->get_id(), '_sidccofo_ev_holds', $holds );
		}
		// Orders that no longer hold stock hold no seats either.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off recount over our own meta.
		$tracked = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_sidccofo_ev_holds' ) );
		foreach ( array_diff( $tracked, $order_ids ) as $oid ) {
			update_post_meta( (int) $oid, '_sidccofo_ev_holds', array() );
		}
		foreach ( $counts as $pid => $count ) {
			update_post_meta( $pid, self::HELD, $count );
			Product::forget( $pid );
		}
		self::sync( Event::get( $event->get_id() ) );
		return $counts;
	}
}
