<?php
/**
 * Digital downloads.
 *
 * A product can be "Downloadable" with one or more files, a download limit
 * and an expiry, and "Virtual" (nothing to ship). When an order is paid, the
 * buyer gets a private link per file. Links appear on the order-received
 * page, in the order emails and in the My Downloads Unit; staff manage them
 * on the order screen.
 *
 * Access records live on the order (post meta _sidccofo_download_access),
 * keyed "{product id}-{file id}", so they go wherever the order goes.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Downloads;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Downloads
 */
class Downloads {

	/** Product meta. */
	const DOWNLOADABLE = '_sidccofo_downloadable';
	const VIRTUAL      = '_sidccofo_virtual';
	const FILES        = '_sidccofo_download_files';
	const LIMIT        = '_sidccofo_download_limit';
	const EXPIRY       = '_sidccofo_download_expiry';

	/** Order meta. */
	const PERMS = '_sidccofo_download_access';
	const LOG   = '_sidccofo_download_log';
	const INDEX = '_sidccofo_dl_product';

	/** Log entries kept per order. */
	const LOG_MAX = 200;

	/** Query var of download links. */
	const QUERY_VAR = 'sidccofo_download';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'on_status' ), 5, 3 );
	}

	/*
	------------------------------------------------------------------
	 * Settings (Store → Settings → Downloads)
	 * ----------------------------------------------------------------
	 */

	/**
	 * Defaults of the download settings (stored as download_* keys).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'method'            => 'force',
			'redirect_fallback' => 1,
			'inline'            => 0,
			'require_login'     => 0,
			'verify_email'      => 0,
			'grant_on'          => 'paid',
			'grant_new_files'   => 1,
			'revoke_on_refund'  => 1,
			'unique_names'      => 1,
			'allow_remote'      => 1,
			'approved_dirs'     => '',
			'email_links'       => 1,
			'log_ip'            => 0,
		);
	}

	/**
	 * One download setting.
	 *
	 * @param string $key Key without the download_ prefix.
	 * @return mixed
	 */
	public static function get( $key ) {
		$defaults = self::defaults();
		return setting( 'download_' . $key, $defaults[ $key ] ?? null );
	}

	/*
	------------------------------------------------------------------
	 * Products
	 * ----------------------------------------------------------------
	 */

	/**
	 * Is a product downloadable (ticked, with at least one file)?
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_downloadable( $product_id ) {
		return (bool) get_post_meta( (int) $product_id, self::DOWNLOADABLE, true ) && (bool) self::files( $product_id );
	}

	/**
	 * Is a product virtual (nothing to ship)?
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_virtual( $product_id ) {
		/**
		 * Filter whether a product is virtual.
		 *
		 * @param bool $virtual    Virtual.
		 * @param int  $product_id Product ID.
		 */
		return (bool) apply_filters( 'sidcraft_commerce_product_is_virtual', (bool) get_post_meta( (int) $product_id, self::VIRTUAL, true ), (int) $product_id );
	}

	/**
	 * File rows as stored on a product (whether or not it is downloadable).
	 *
	 * @param int $product_id Product ID.
	 * @return array[] file id => [ id, name, file ].
	 */
	public static function stored_files( $product_id ) {
		$rows = get_post_meta( (int) $product_id, self::FILES, true );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['file'] ) ) {
				continue;
			}
			$out[ (string) $row['id'] ] = array(
				'id'   => (string) $row['id'],
				'name' => (string) ( $row['name'] ?? '' ),
				'file' => (string) $row['file'],
			);
		}
		return $out;
	}

	/**
	 * The files a product gives access to.
	 *
	 * @param int $product_id Product ID.
	 * @return array[] file id => [ id, name, file ].
	 */
	public static function files( $product_id ) {
		$product_id = (int) $product_id;
		$files      = get_post_meta( $product_id, self::DOWNLOADABLE, true ) ? self::stored_files( $product_id ) : array();

		/**
		 * Filter a product's downloadable files.
		 *
		 * @param array[] $files      file id => [ id, name, file ].
		 * @param int     $product_id Product ID.
		 */
		return (array) apply_filters( 'sidcraft_commerce_downloadable_files', $files, $product_id );
	}

	/**
	 * Download limit per unit bought (null = unlimited).
	 *
	 * @param int $product_id Product ID.
	 * @return int|null
	 */
	public static function limit( $product_id ) {
		$value = get_post_meta( (int) $product_id, self::LIMIT, true );
		return '' === (string) $value || (int) $value < 1 ? null : (int) $value;
	}

	/**
	 * Days the links work for (0 = forever).
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	public static function expiry_days( $product_id ) {
		return max( 0, (int) get_post_meta( (int) $product_id, self::EXPIRY, true ) );
	}

	/**
	 * A display name for a file.
	 *
	 * @param array $file File row.
	 * @return string
	 */
	public static function file_name( array $file ) {
		if ( '' !== trim( (string) ( $file['name'] ?? '' ) ) ) {
			return (string) $file['name'];
		}
		$path = wp_parse_url( (string) ( $file['file'] ?? '' ), PHP_URL_PATH );
		return $path ? wp_basename( (string) $path ) : __( 'Download', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/*
	------------------------------------------------------------------
	 * Access on orders
	 * ----------------------------------------------------------------
	 */

	/**
	 * Access records on an order.
	 *
	 * @param Order|int $order Order or ID.
	 * @return array[] key => record.
	 */
	public static function permissions( $order ) {
		$id    = $order instanceof Order ? $order->get_id() : (int) $order;
		$perms = get_post_meta( $id, self::PERMS, true );
		return is_array( $perms ) ? array_filter( $perms, 'is_array' ) : array();
	}

	/**
	 * Save access records.
	 *
	 * @param Order $order Order.
	 * @param array $perms Records.
	 */
	public static function save_permissions( Order $order, array $perms ) {
		update_post_meta( $order->get_id(), self::PERMS, $perms );
		$indexed = array_map( 'intval', (array) get_post_meta( $order->get_id(), self::INDEX, false ) );
		foreach ( array_unique( wp_list_pluck( $perms, 'product_id' ) ) as $product_id ) {
			if ( ! in_array( (int) $product_id, $indexed, true ) ) {
				add_post_meta( $order->get_id(), self::INDEX, (int) $product_id );
			}
		}

		/**
		 * Fires after an order's download access changes.
		 *
		 * @param Order $order Order.
		 * @param array $perms Records.
		 */
		do_action( 'sidcraft_commerce_download_permissions_saved', $order, $perms );
	}

	/**
	 * A new access record.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $file       File row.
	 * @param int   $quantity   Units bought.
	 * @return array
	 */
	public static function new_permission( $product_id, array $file, $quantity = 1 ) {
		$limit = self::limit( $product_id );
		$days  = self::expiry_days( $product_id );
		return array(
			'product_id' => (int) $product_id,
			'file_id'    => (string) $file['id'],
			'name'       => self::file_name( $file ),
			'product'    => (string) get_the_title( (int) $product_id ),
			'token'      => wp_generate_password( 32, false ),
			'remaining'  => null === $limit ? null : $limit * max( 1, (int) $quantity ),
			'expires'    => $days ? time() + $days * DAY_IN_SECONDS : 0,
			'count'      => 0,
			'granted'    => time(),
			'last'       => 0,
			'suspended'  => 0,
			'revoked'    => 0,
		);
	}

	/**
	 * Give an order access to every file of its downloadable products. Safe
	 * to call again: existing records (including revoked ones) are kept.
	 *
	 * @param Order $order Order.
	 * @return int Number of new links.
	 */
	public static function grant( Order $order ) {
		$quantities = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['gift_card'] ) || empty( $item['product_id'] ) ) {
				continue;
			}
			$pid                = (int) $item['product_id'];
			$quantities[ $pid ] = ( $quantities[ $pid ] ?? 0 ) + max( 1, (int) ( $item['quantity'] ?? 1 ) );
		}

		$perms = self::permissions( $order );
		$added = 0;
		foreach ( $quantities as $product_id => $qty ) {
			foreach ( self::files( $product_id ) as $file ) {
				$key = $product_id . '-' . $file['id'];
				if ( isset( $perms[ $key ] ) ) {
					continue;
				}
				$perms[ $key ] = self::new_permission( $product_id, $file, $qty );
				++$added;
			}
		}
		if ( $added ) {
			self::save_permissions( $order, $perms );
			$order->add_note(
				sprintf(
					/* translators: %d: number of files. */
					_n( 'Download access granted to %d file.', 'Download access granted to %d files.', $added, 'sidcraft-commerce-for-sidcraft-page-builder' ),
					$added
				)
			);
		}
		return $added;
	}

	/**
	 * Pause or resume every link on an order (cancelled or refunded orders).
	 *
	 * @param Order $order     Order.
	 * @param bool  $suspended Pause.
	 */
	public static function set_suspended( Order $order, $suspended ) {
		$perms   = self::permissions( $order );
		$changed = false;
		foreach ( $perms as $key => $perm ) {
			if ( (bool) $perm['suspended'] !== (bool) $suspended ) {
				$perms[ $key ]['suspended'] = $suspended ? 1 : 0;
				$changed                    = true;
			}
		}
		if ( $changed ) {
			self::save_permissions( $order, $perms );
			$order->add_note( $suspended ? __( 'Download links paused.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Download links resumed.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
	}

	/**
	 * Grant on payment (or completion), pause on cancel or refund.
	 *
	 * Runs before the order emails (priority 10) so they carry the links.
	 *
	 * @param Order  $order      Order.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 */
	public function on_status( $order, $old_status, $new_status ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		if ( ! $order instanceof Order ) {
			return;
		}
		if ( in_array( $new_status, Post_Types::paid_statuses(), true ) ) {
			if ( 'completed' === self::get( 'grant_on' ) && 'sidccofo-completed' !== $new_status && ! self::permissions( $order ) ) {
				return;
			}
			self::grant( $order );
			self::set_suspended( $order, false );
		} elseif ( in_array( $new_status, array( 'sidccofo-cancelled', 'sidccofo-refunded', 'sidccofo-failed' ), true ) && self::get( 'revoke_on_refund' ) ) {
			self::set_suspended( $order, true );
		}
	}

	/**
	 * Why a link can't be used right now ('' when it can).
	 *
	 * @param array $perm  Record.
	 * @param Order $order Order.
	 * @return string
	 */
	public static function blocked_reason( array $perm, Order $order ) {
		if ( ! empty( $perm['revoked'] ) ) {
			return __( 'Access to this file was removed.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( ! empty( $perm['suspended'] ) || ! $order->is_paid() ) {
			return __( 'This download is not available for this order.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( null !== $perm['remaining'] && (int) $perm['remaining'] < 1 ) {
			return __( 'You have used all the downloads for this file.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( ! empty( $perm['expires'] ) && (int) $perm['expires'] < time() ) {
			return __( 'This download link has expired.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		return '';
	}

	/**
	 * The private link for one record.
	 *
	 * @param Order|int $order Order or ID.
	 * @param string    $key   Record key.
	 * @param array     $perm  Record.
	 * @return string
	 */
	public static function url( $order, $key, array $perm ) {
		$id = $order instanceof Order ? $order->get_id() : (int) $order;
		return add_query_arg(
			array(
				self::QUERY_VAR => $id . '.' . $key,
				'key'           => $perm['token'],
			),
			home_url( '/' )
		);
	}

	/**
	 * Rows to show the customer for an order.
	 *
	 * @param Order $order Order.
	 * @param bool  $all   Include links that can't be used any more.
	 * @return array[] Each: key, order_id, name, product, url, remaining, expires, usable, reason.
	 */
	public static function rows( Order $order, $all = false ) {
		$rows = array();
		foreach ( self::permissions( $order ) as $key => $perm ) {
			if ( ! empty( $perm['revoked'] ) ) {
				continue;
			}
			$reason = self::blocked_reason( $perm, $order );
			if ( '' !== $reason && ! $all ) {
				continue;
			}
			$rows[] = array(
				'key'       => (string) $key,
				'order_id'  => $order->get_id(),
				'name'      => (string) $perm['name'],
				'product'   => (string) $perm['product'],
				'url'       => '' === $reason ? self::url( $order, $key, $perm ) : '',
				'remaining' => null === $perm['remaining'] ? __( 'Unlimited', 'sidcraft-commerce-for-sidcraft-page-builder' ) : number_format_i18n( max( 0, (int) $perm['remaining'] ) ),
				'limited'   => null !== $perm['remaining'],
				'expires'   => empty( $perm['expires'] ) ? __( 'Never', 'sidcraft-commerce-for-sidcraft-page-builder' ) : date_i18n( get_option( 'date_format' ), (int) $perm['expires'] ),
				'expiring'  => ! empty( $perm['expires'] ),
				'usable'    => '' === $reason,
				'reason'    => $reason,
			);
		}

		/**
		 * Filter the download rows shown to a customer.
		 *
		 * @param array[] $rows  Rows.
		 * @param Order   $order Order.
		 */
		return (array) apply_filters( 'sidcraft_commerce_download_rows', $rows, $order );
	}

	/**
	 * Does the order hold downloadable products (or download links)?
	 *
	 * @param Order $order Order.
	 * @return bool
	 */
	public static function order_has_downloads( Order $order ) {
		if ( self::permissions( $order ) ) {
			return true;
		}
		foreach ( $order->get_items() as $item ) {
			if ( is_array( $item ) && empty( $item['gift_card'] ) && self::is_downloadable( (int) ( $item['product_id'] ?? 0 ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Orders of a user that carry downloads (newest first).
	 *
	 * @param int $user_id User ID.
	 * @return Order[]
	 */
	public static function customer_orders( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return array();
		}
		$ids    = get_posts(
			array(
				'post_type'      => Post_Types::ORDER,
				'post_status'    => Post_Types::paid_statuses(),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One customer's orders, bounded.
					array(
						'key'   => '_sidccofo_customer_id',
						'value' => $user_id,
					),
					array(
						'key'     => self::PERMS,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		$orders = array();
		foreach ( $ids as $id ) {
			$order = Order::get( (int) $id );
			if ( $order ) {
				$orders[] = $order;
			}
		}
		return $orders;
	}

	/**
	 * Paid orders that already have downloads of a product.
	 *
	 * @param int $product_id Product ID.
	 * @param int $limit      Most orders to return.
	 * @return int[]
	 */
	public static function orders_with_product( $product_id, $limit = 500 ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Post_Types::ORDER,
					'post_status'    => Post_Types::paid_statuses(),
					'posts_per_page' => (int) $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => self::INDEX, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Our own index, bounded.
					'meta_value'     => (int) $product_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Our own index, bounded.
				)
			)
		);
	}

	/**
	 * After files are added to a product, give past buyers the new files too.
	 *
	 * @param int      $product_id Product ID.
	 * @param string[] $new_ids    IDs of the files just added.
	 * @return int Orders updated.
	 */
	public static function grant_new_files( $product_id, array $new_ids ) {
		if ( ! $new_ids || ! self::get( 'grant_new_files' ) ) {
			return 0;
		}
		$product_id = (int) $product_id;
		$files      = self::files( $product_id );
		$limit      = self::limit( $product_id );
		$updated    = 0;
		foreach ( self::orders_with_product( $product_id ) as $order_id ) {
			$order = Order::get( $order_id );
			if ( ! $order ) {
				continue;
			}
			$perms = self::permissions( $order );
			$qty   = 1;
			foreach ( $perms as $perm ) {
				if ( (int) $perm['product_id'] === $product_id && null !== $perm['remaining'] && $limit ) {
					$qty = max( 1, (int) ceil( ( (int) $perm['remaining'] + (int) $perm['count'] ) / $limit ) );
					break;
				}
			}
			$added = 0;
			foreach ( $new_ids as $file_id ) {
				$key = $product_id . '-' . $file_id;
				if ( isset( $files[ $file_id ] ) && ! isset( $perms[ $key ] ) ) {
					$perms[ $key ] = self::new_permission( $product_id, $files[ $file_id ], $qty );
					++$added;
				}
			}
			if ( $added ) {
				self::save_permissions( $order, $perms );
				/* translators: %d: number of files. */
				$order->add_note( sprintf( _n( '%d new file was added to a product in this order; the customer can download it.', '%d new files were added to a product in this order; the customer can download them.', $added, 'sidcraft-commerce-for-sidcraft-page-builder' ), $added ) );
				++$updated;
			}
		}
		return $updated;
	}

	/**
	 * Add an entry to an order's download log.
	 *
	 * @param Order  $order Order.
	 * @param string $key   Record key.
	 */
	public static function log( Order $order, $key ) {
		$log   = get_post_meta( $order->get_id(), self::LOG, true );
		$log   = is_array( $log ) ? $log : array();
		$entry = array(
			'time' => time(),
			'key'  => (string) $key,
			'user' => get_current_user_id(),
			'ip'   => '',
		);
		if ( self::get( 'log_ip' ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip          = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			$entry['ip'] = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		}
		$log[] = $entry;
		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, -1 * self::LOG_MAX );
		}
		update_post_meta( $order->get_id(), self::LOG, $log );
	}

	/**
	 * An order's download log (newest first).
	 *
	 * @param Order|int $order Order or ID.
	 * @return array[]
	 */
	public static function get_log( $order ) {
		$id  = $order instanceof Order ? $order->get_id() : (int) $order;
		$log = get_post_meta( $id, self::LOG, true );
		return is_array( $log ) ? array_reverse( array_filter( $log, 'is_array' ) ) : array();
	}

	/*
	------------------------------------------------------------------
	 * Emails
	 * ----------------------------------------------------------------
	 */

	/**
	 * Email the customer their download links (from the order screen).
	 *
	 * @param Order $order Order.
	 * @return bool
	 */
	public static function send_links( Order $order ) {
		return \SidcraftCommerce\Multilingual::for_order(
			$order,
			static function () use ( $order ) {
				return self::send_links_now( $order );
			}
		);
	}

	/**
	 * send_links() in the order's language.
	 *
	 * @param Order $order Order.
	 * @return bool
	 */
	protected static function send_links_now( Order $order ) {
		$to = $order->get_email();
		if ( ! is_email( $to ) || ! self::rows( $order ) ) {
			return false;
		}
		/* translators: %d: order number. */
		$subject = sprintf( __( 'Your downloads (#%d)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() );
		$body    = \SidcraftCommerce\Plugin::instance()->templates->render(
			'emails/order.php',
			array(
				'order'     => $order,
				'heading'   => __( 'Your downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'recipient' => 'customer',
				'shipment'  => null,
				'shipments' => array(),
				/* translators: %d: order number. */
				'message'   => sprintf( __( 'Here are the downloads for order #%d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_id() ),
			)
		);
		$from    = sanitize_text_field( (string) setting( 'from_name', get_bloginfo( 'name' ) ) );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from . ' <' . get_option( 'admin_email' ) . '>',
		);

		/** This filter is documented in includes/class-emails.php */
		$mail = apply_filters( 'sidcraft_commerce_email', array( $to, $subject, $body, $headers ), $order, 'customer' );
		return $mail ? wp_mail( $mail[0], $mail[1], $mail[2], $mail[3] ) : false;
	}
}
