<?php
/**
 * Several stores on a WordPress Multisite network.
 *
 * Each site is its own store: its own products, prices, currency, language,
 * orders, customers' carts (cookies are named per site, see cookie_name())
 * and settings. On the network this adds:
 *
 *   Network Admin → Stores    every store's orders and sales for the last 30
 *                              days (in each store's currency), the latest
 *                              orders across stores, and a copy tool;
 *   copy                      settings, products (with categories, brands,
 *                              images and downloadable files) and coupons from
 *                              one store to others, in batches. Copied items
 *                              remember where they came from
 *                              (_sidccofo_network_source = "site:id"), so
 *                              copying again updates them instead of
 *                              duplicating;
 *   WP-CLI                    wp sidcraft-commerce stores
 *                             wp sidcraft-commerce copy --from=2 --to=3,4 [--what=settings,products,coupons] [--update]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Data\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Class Network
 */
class Network {

	const JOB    = 'sidcraft_commerce_network_copy';
	const SOURCE = '_sidccofo_network_source';
	const BATCH  = 20;

	/**
	 * Settings that stay with each store when settings are copied.
	 */
	const OWN_SETTINGS = array( 'admin_email', 'from_name' );

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'network_admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_sidccofo_network_copy', array( $this, 'handle_copy' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'sidcraft-commerce stores', array( $this, 'cli_stores' ) );
			\WP_CLI::add_command( 'sidcraft-commerce copy', array( $this, 'cli_copy' ) );
		}
	}

	/*
	------------------------------------------------------------------
	 * Stores
	 * ----------------------------------------------------------------
	 */

	/**
	 * Sites running the store.
	 *
	 * @return int[]
	 */
	public static function store_sites() {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$basename = plugin_basename( SIDCRAFT_COMMERCE_FILE );
		$network  = is_plugin_active_for_network( $basename );
		$out      = array();
		foreach ( get_sites(
			array(
				'fields'   => 'ids',
				'number'   => 500,
				'archived' => 0,
				'deleted'  => 0,
			)
		) as $id ) {
			if ( $network || in_array( $basename, (array) get_blog_option( (int) $id, 'active_plugins', array() ), true ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Run a function on a store.
	 *
	 * @param int      $blog_id Site.
	 * @param callable $fn      Function.
	 * @return mixed
	 */
	public static function on( $blog_id, callable $fn ) {
		$switched = (int) $blog_id !== get_current_blog_id();
		if ( $switched ) {
			switch_to_blog( (int) $blog_id );
		}
		try {
			return $fn();
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * A store's numbers for the last 30 days.
	 *
	 * @param int $blog_id Site.
	 * @return array name, url, admin, currency, products, orders, sales, pending.
	 */
	public static function summary( $blog_id ) {
		return self::on(
			$blog_id,
			static function () {
				global $wpdb;
				$settings = (array) get_option( 'sidcraft_commerce_settings', array() );
				$statuses = Post_Types::stock_statuses();
				$since    = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
				$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Report over this store's orders; placeholders built above.
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) AS orders, COALESCE( SUM( CAST( t.meta_value AS DECIMAL(20,4) ) / COALESCE( NULLIF( CAST( r.meta_value AS DECIMAL(20,8) ), 0 ), 1 ) ), 0 ) AS sales
						FROM {$wpdb->posts} p
						LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_sidccofo_total'
						LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = '_sidccofo_exchange_rate'
						WHERE p.post_type = %s AND p.post_status IN ($in) AND p.post_date_gmt >= %s",
						array_merge( array( Post_Types::ORDER ), $statuses, array( $since ) )
					)
				);
				$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'sidccofo-pending'", Post_Types::ORDER ) );
				// phpcs:enable
				$counts = wp_count_posts( Post_Types::PRODUCT );
				return array(
					'id'       => get_current_blog_id(),
					'name'     => (string) get_bloginfo( 'name' ),
					'url'      => home_url( '/' ),
					'admin'    => admin_url( 'edit.php?post_type=' . Post_Types::ORDER ),
					'currency' => strtoupper( (string) ( $settings['currency'] ?? 'USD' ) ),
					'products' => (int) ( $counts->publish ?? 0 ),
					'orders'   => (int) ( $row->orders ?? 0 ),
					'sales'    => round( (float) ( $row->sales ?? 0 ), 2 ),
					'pending'  => $pending,
				);
			}
		);
	}

	/**
	 * Latest orders across stores, newest first.
	 *
	 * @param int $limit Orders.
	 * @return array[] site, name, id, date (GMT timestamp), status, total, edit.
	 */
	public static function recent_orders( $limit = 20 ) {
		$rows = array();
		foreach ( self::store_sites() as $blog_id ) {
			$rows = array_merge(
				$rows,
				self::on(
					$blog_id,
					static function () use ( $limit ) {
						$out = array();
						foreach ( get_posts(
							array(
								'post_type'      => Post_Types::ORDER,
								'post_status'    => array_keys( Post_Types::order_status_definitions() ),
								'posts_per_page' => $limit,
								'orderby'        => 'date',
								'order'          => 'DESC',
								'no_found_rows'  => true,
							)
						) as $post ) {
							$order = Data\Order::get( $post->ID );
							if ( ! $order ) {
								continue;
							}
							$out[] = array(
								'site'   => get_current_blog_id(),
								'name'   => (string) get_bloginfo( 'name' ),
								'id'     => $order->get_id(),
								'date'   => (int) strtotime( $post->post_date_gmt . ' UTC' ),
								'status' => $order->get_status_label(),
								'total'  => format_in( $order->get_total(), $order->get_currency() ),
								'edit'   => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
							);
						}
						return $out;
					}
				)
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['date'] <=> $a['date'];
			}
		);
		return array_slice( $rows, 0, $limit );
	}

	/*
	------------------------------------------------------------------
	 * Copying
	 * ----------------------------------------------------------------
	 */

	/**
	 * Copy the store settings (each store keeps its own payment methods and email sender).
	 *
	 * @param int $from Source site.
	 * @param int $to   Target site.
	 */
	public static function copy_settings( $from, $to ) {
		$source = (array) get_blog_option( (int) $from, 'sidcraft_commerce_settings', array() );
		self::on(
			$to,
			static function () use ( $source ) {
				$target = (array) get_option( 'sidcraft_commerce_settings', array() );
				// Payment methods stay per store: their accounts, emails and on/off switches.
				$gateways = array( 'paypal' );
				foreach ( \SidcraftCommerce\Checkout\Checkout::gateways() as $gateway ) {
					$gateways[] = preg_quote( $gateway->get_id(), '/' );
				}
				$own = '/^(' . implode( '|', $gateways ) . ')_|(secret|password|client_id|api_key|webhook|token|merchant)/';
				foreach ( $source as $key => $value ) {
					if ( in_array( $key, self::OWN_SETTINGS, true ) || preg_match( $own, (string) $key ) ) {
						continue;
					}
					$target[ $key ] = $value;
				}
				update_option( 'sidcraft_commerce_settings', $target );
				update_option( 'sidcraft_commerce_flush_rewrites', 1 );
			}
		);
	}

	/**
	 * Copy a batch of products.
	 *
	 * @param int  $from   Source site.
	 * @param int  $to     Target site.
	 * @param int  $offset Products already done.
	 * @param bool $update Update products copied before (or with the same SKU).
	 * @param int  $limit  Batch size.
	 * @return array [ done (bool), next offset, created, updated, skipped ].
	 */
	public static function copy_products( $from, $to, $offset, $update, $limit = self::BATCH ) {
		return self::copy_posts( Post_Types::PRODUCT, $from, $to, $offset, $update, $limit );
	}

	/**
	 * Copy a batch of coupons (their use counts start at 0).
	 *
	 * @param int  $from   Source site.
	 * @param int  $to     Target site.
	 * @param int  $offset Coupons already done.
	 * @param bool $update Update coupons copied before (or with the same code).
	 * @param int  $limit  Batch size.
	 * @return array
	 */
	public static function copy_coupons( $from, $to, $offset, $update, $limit = self::BATCH ) {
		return self::copy_posts( Post_Types::COUPON, $from, $to, $offset, $update, $limit );
	}

	/**
	 * Copy a batch of products or coupons.
	 *
	 * @param string $type   Post type.
	 * @param int    $from   Source site.
	 * @param int    $to     Target site.
	 * @param int    $offset Done so far.
	 * @param bool   $update Update existing copies.
	 * @param int    $limit  Batch size.
	 * @return array
	 */
	protected static function copy_posts( $type, $from, $to, $offset, $update, $limit ) {
		$items = self::on(
			$from,
			static function () use ( $type, $offset, $limit ) {
				$out = array();
				foreach ( get_posts(
					array(
						'post_type'        => $type,
						'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
						'posts_per_page'   => $limit,
						'offset'           => $offset,
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'suppress_filters' => true, // Every language, untouched by translation plugins.
						'no_found_rows'    => true,
					)
				) as $post ) {
					$out[] = self::export( $post );
				}
				return $out;
			}
		);
		$result = array(
			'done'    => count( $items ) < $limit,
			'next'    => (int) $offset + count( $items ),
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
		);
		foreach ( $items as $item ) {
			$outcome = self::on(
				$to,
				static function () use ( $item, $from, $update ) {
					return self::import( $item, (int) $from, (bool) $update );
				}
			);
			++$result[ $outcome ];
		}
		return $result;
	}

	/**
	 * Everything needed to recreate a post on another store (read on the source).
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	protected static function export( \WP_Post $post ) {
		$meta = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( in_array( $key, array( '_edit_lock', '_edit_last', '_thumbnail_id', self::SOURCE, '_sidccofo_total_sales', '_sidccofo_rating_avg', '_sidccofo_rating_count', '_sidccofo_coupon_usage_count', '_sidccofo_download_log', '_sidccofo_upsells', '_sidccofo_cross_sells' ), true ) ) {
				// Store-specific: counts, and ids of products that differ per store.
				continue;
			}
			$meta[ $key ] = maybe_unserialize( $values[0] );
		}
		$terms = array();
		foreach ( array( Post_Types::CATEGORY, Post_Types::BRAND, Post_Types::TAG ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			foreach ( (array) wp_get_object_terms( $post->ID, $tax ) as $term ) {
				if ( $term instanceof \WP_Term ) {
					$chain = array();
					for ( $t = $term; $t && ! is_wp_error( $t ); $t = $t->parent ? get_term( $t->parent, $tax ) : null ) {
						array_unshift(
							$chain,
							array(
								'name' => $t->name,
								'slug' => $t->slug,
							)
						);
					}
					$terms[ $tax ][] = $chain;
				}
			}
		}
		$thumb = (int) get_post_thumbnail_id( $post );
		$files = array();
		foreach ( (array) ( $meta['_sidccofo_download_files'] ?? array() ) as $row ) {
			$path    = is_array( $row ) ? Downloads\Files::local_path( (string) ( $row['file'] ?? '' ) ) : '';
			$uploads = wp_upload_dir( null, false );
			$base    = wp_normalize_path( untrailingslashit( $uploads['basedir'] ) );
			$files[] = $path && 0 === strpos( $path, $base . '/' ) && is_readable( $path ) ? array(
				'path'     => $path,
				'relative' => substr( $path, strlen( $base ) + 1 ),
			) : null;
		}
		// A coupon's product and category limits hold this store's ids: send what finds them on another.
		$limits = array();
		if ( Post_Types::COUPON === $post->post_type ) {
			foreach ( array( 'products' => '_sidccofo_coupon_products', 'exclude_products' => '_sidccofo_coupon_exclude_products' ) as $limit => $key ) {
				foreach ( array_filter( array_map( 'absint', (array) ( $meta[ $key ] ?? array() ) ) ) as $pid ) {
					$limits[ $limit ][] = array(
						'source' => get_current_blog_id() . ':' . $pid,
						'sku'    => (string) get_post_meta( $pid, '_sidccofo_sku', true ),
					);
				}
			}
			foreach ( array( 'categories' => '_sidccofo_coupon_categories', 'exclude_categories' => '_sidccofo_coupon_exclude_categories' ) as $limit => $key ) {
				foreach ( array_filter( array_map( 'absint', (array) ( $meta[ $key ] ?? array() ) ) ) as $tid ) {
					$term = get_term( $tid, Post_Types::CATEGORY );
					if ( $term instanceof \WP_Term ) {
						$limits[ $limit ][] = $term->slug;
					}
				}
			}
		}
		return array(
			'source' => get_current_blog_id() . ':' . $post->ID,
			'limits' => $limits,
			'post'   => array(
				'post_type'    => $post->post_type,
				'post_status'  => $post->post_status,
				'post_title'   => $post->post_title,
				'post_name'    => $post->post_name,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
				'menu_order'   => $post->menu_order,
			),
			'meta'   => $meta,
			'terms'  => $terms,
			'image'  => $thumb ? array(
				'id'    => $thumb,
				'path'  => (string) get_attached_file( $thumb ),
				'title' => get_the_title( $thumb ),
				'alt'   => (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ),
			) : null,
			'files'  => $files,
		);
	}

	/**
	 * Create or update the copy on the current (target) store.
	 *
	 * @param array $item   From export().
	 * @param int   $from   Source site.
	 * @param bool  $update Update an existing copy.
	 * @return string created|updated|skipped
	 */
	protected static function import( array $item, $from, $update ) {
		$type     = $item['post']['post_type'];
		$existing = self::find_copy( $item );
		if ( $existing && ! $update ) {
			return 'skipped';
		}
		$postarr = wp_slash( $item['post'] );
		if ( $existing ) {
			$postarr['ID'] = $existing;
			$id            = wp_update_post( $postarr, true );
		} else {
			$id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			return 'skipped';
		}
		foreach ( $item['meta'] as $key => $value ) {
			if ( '_sidccofo_download_files' === $key ) {
				continue;
			}
			if ( '_sidccofo_choice_extra' === $key && is_array( $value ) ) {
				// Choice SKUs travel; their images live in the source store's media library.
				foreach ( $value as $choice => $extra ) {
					$value[ $choice ]['image'] = 0;
				}
			}
			update_post_meta( $id, $key, wp_slash( $value ) );
		}
		if ( Post_Types::COUPON === $type && ! $existing ) {
			update_post_meta( $id, '_sidccofo_coupon_usage_count', 0 );
		}
		if ( Post_Types::COUPON === $type ) {
			self::map_coupon_limits( $id, $item );
		}
		update_post_meta( $id, self::SOURCE, $item['source'] );

		foreach ( $item['terms'] as $tax => $chains ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$ids = array();
			foreach ( $chains as $chain ) {
				$parent = 0;
				foreach ( $chain as $t ) {
					$found = get_term_by( 'slug', $t['slug'], $tax );
					if ( ! $found ) {
						$made  = wp_insert_term( $t['name'], $tax, array( 'slug' => $t['slug'], 'parent' => $parent ) );
						$found = is_wp_error( $made ) ? null : get_term( (int) $made['term_id'], $tax );
					}
					$parent = $found ? (int) $found->term_id : $parent;
				}
				if ( $parent ) {
					$ids[] = $parent;
				}
			}
			wp_set_object_terms( $id, $ids, $tax );
		}

		if ( $item['image'] && is_readable( $item['image']['path'] ) ) {
			$att = self::copy_attachment( $item['image'], $from, $id );
			if ( $att ) {
				set_post_thumbnail( $id, $att );
			}
		}

		if ( isset( $item['meta']['_sidccofo_download_files'] ) && is_array( $item['meta']['_sidccofo_download_files'] ) ) {
			$rows    = array();
			$uploads = wp_upload_dir( null, false );
			foreach ( array_values( $item['meta']['_sidccofo_download_files'] ) as $i => $row ) {
				$file = $item['files'][ $i ] ?? null;
				if ( is_array( $row ) && $file ) {
					$dest = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . $file['relative'] );
					if ( wp_mkdir_p( dirname( $dest ) ) && ( file_exists( $dest ) || copy( $file['path'], $dest ) ) ) {
						$row['file'] = Downloads\Files::path_to_url( $dest );
					}
				}
				$rows[] = $row;
			}
			update_post_meta( $id, '_sidccofo_download_files', wp_slash( $rows ) );
			Downloads\Files::protect();
		}

		if ( Post_Types::PRODUCT === $type ) {
			Data\Product::forget( $id );
			Data\Product::sync_derived_meta( $id );
		}
		return $existing ? 'updated' : 'created';
	}

	/**
	 * Point a copied coupon's product and category limits at this store's
	 * products (copied before, or with the same SKU) and categories (same slug).
	 * A limit with nothing to point at would let the coupon apply to everything,
	 * so the coupon is kept as a draft instead.
	 *
	 * @param int   $id   Coupon on the current (target) store.
	 * @param array $item From export().
	 */
	protected static function map_coupon_limits( $id, array $item ) {
		$limits   = (array) ( $item['limits'] ?? array() );
		$products = array();
		foreach ( (array) ( $limits['products'] ?? array() ) as $ref ) {
			$found = self::find_copy(
				array(
					'post'   => array( 'post_type' => Post_Types::PRODUCT ),
					'source' => (string) $ref['source'],
					'meta'   => array( '_sidccofo_sku' => (string) $ref['sku'] ),
				)
			);
			if ( $found ) {
				$products[] = $found;
			}
		}
		$categories = array();
		foreach ( (array) ( $limits['categories'] ?? array() ) as $slug ) {
			$term = get_term_by( 'slug', (string) $slug, Post_Types::CATEGORY );
			if ( $term ) {
				$categories[] = (int) $term->term_id;
			}
		}
		update_post_meta( $id, '_sidccofo_coupon_products', $products );
		update_post_meta( $id, '_sidccofo_coupon_categories', $categories );

		// Exclusions: only what this store has (a missing one excludes nothing).
		$ex_products = array();
		foreach ( (array) ( $limits['exclude_products'] ?? array() ) as $ref ) {
			$found = self::find_copy(
				array(
					'post'   => array( 'post_type' => Post_Types::PRODUCT ),
					'source' => (string) $ref['source'],
					'meta'   => array( '_sidccofo_sku' => (string) $ref['sku'] ),
				)
			);
			if ( $found ) {
				$ex_products[] = $found;
			}
		}
		$ex_categories = array();
		foreach ( (array) ( $limits['exclude_categories'] ?? array() ) as $slug ) {
			$term = get_term_by( 'slug', (string) $slug, Post_Types::CATEGORY );
			if ( $term ) {
				$ex_categories[] = (int) $term->term_id;
			}
		}
		update_post_meta( $id, '_sidccofo_coupon_exclude_products', $ex_products );
		update_post_meta( $id, '_sidccofo_coupon_exclude_categories', $ex_categories );
		if ( ( ! empty( $limits['products'] ) && ! $products ) || ( ! empty( $limits['categories'] ) && ! $categories ) ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'draft',
				)
			);
		}
	}

	/**
	 * The current store's copy of an item: one copied before, else a product
	 * with the same SKU or a coupon with the same code.
	 *
	 * @param array $item From export().
	 * @return int
	 */
	protected static function find_copy( array $item ) {
		$type = $item['post']['post_type'];
		$ids  = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_key'         => self::SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $item['source'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( $ids ) {
			return (int) $ids[0];
		}
		$key   = Post_Types::COUPON === $type ? '_sidccofo_coupon_code' : '_sidccofo_sku';
		$value = (string) ( $item['meta'][ $key ] ?? '' );
		if ( '' === $value ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_key'         => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Copy an image into the current store's media library (once).
	 *
	 * @param array $image  id, path, title, alt (on the source).
	 * @param int   $from   Source site.
	 * @param int   $parent Product it belongs to.
	 * @return int Attachment id or 0.
	 */
	protected static function copy_attachment( array $image, $from, $parent ) {
		$source = $from . ':' . $image['id'];
		$found  = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_key'         => self::SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $source, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( $found ) {
			return (int) $found[0];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file of the source store.
		$bits = wp_upload_bits( wp_basename( $image['path'] ), null, (string) file_get_contents( $image['path'] ) );
		if ( ! empty( $bits['error'] ) ) {
			return 0;
		}
		$type = wp_check_filetype( $bits['file'] );
		$att  = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $type['type'],
				'post_title'     => (string) $image['title'],
				'post_status'    => 'inherit',
			),
			$bits['file'],
			(int) $parent,
			true
		);
		if ( is_wp_error( $att ) ) {
			return 0;
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $bits['file'] ) );
		if ( '' !== $image['alt'] ) {
			update_post_meta( $att, '_wp_attachment_image_alt', $image['alt'] );
		}
		update_post_meta( $att, self::SOURCE, $source );
		return (int) $att;
	}

	/*
	------------------------------------------------------------------
	 * Network admin screen
	 * ----------------------------------------------------------------
	 */

	/**
	 * Network Admin → Stores.
	 */
	public function menu() {
		add_menu_page( __( 'Stores', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Stores', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'manage_network_options', 'sidccofo-network', array( $this, 'render' ), 'dashicons-store', 25 );
	}

	/**
	 * The screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		$sites = self::store_sites();
		echo '<div class="wrap"><h1>' . esc_html__( 'Stores', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = isset( $_GET['copied'] ) ? sanitize_text_field( wp_unslash( $_GET['copied'] ) ) : '';
		if ( '' !== $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Last 30 days', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>' . esc_html__( 'Store', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Currency', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Products', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Orders', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Sales', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Awaiting payment', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th></tr></thead><tbody>';
		$totals = array();
		foreach ( $sites as $blog_id ) {
			$s = self::summary( $blog_id );
			$totals[ $s['currency'] ] = ( $totals[ $s['currency'] ] ?? 0 ) + $s['sales'];
			printf(
				'<tr><td><strong><a href="%1$s">%2$s</a></strong><br><a href="%3$s">%4$s</a></td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$s</td><td>%9$s</td></tr>',
				esc_url( $s['admin'] ),
				esc_html( $s['name'] ),
				esc_url( $s['url'] ),
				esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $s['url'] ) ) ),
				esc_html( $s['currency'] ),
				esc_html( number_format_i18n( $s['products'] ) ),
				esc_html( number_format_i18n( $s['orders'] ) ),
				esc_html( format_in( $s['sales'], $s['currency'] ) ),
				esc_html( number_format_i18n( $s['pending'] ) )
			);
		}
		if ( ! $sites ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No site runs the store yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</td></tr>';
		}
		echo '</tbody>';
		if ( count( $sites ) > 1 ) {
			$parts = array();
			foreach ( $totals as $code => $sum ) {
				$parts[] = format_in( $sum, $code );
			}
			echo '<tfoot><tr><th colspan="4">' . esc_html__( 'All stores', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th colspan="2">' . esc_html( implode( ' + ', $parts ) ) . '</th></tr></tfoot>';
		}
		echo '</table><p class="description">' . esc_html__( 'Sales: orders that are paid or holding stock, in each store’s own currency (orders paid in other currencies are counted at their rate).', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';

		echo '<h2>' . esc_html__( 'Latest orders', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h2><table class="widefat striped" style="max-width:980px"><thead><tr><th>' . esc_html__( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Store', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Date', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Total', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th></tr></thead><tbody>';
		$orders = self::recent_orders( 15 );
		foreach ( $orders as $o ) {
			printf(
				'<tr><td><a href="%1$s">#%2$d</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_url( $o['edit'] ),
				(int) $o['id'],
				esc_html( $o['name'] ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $o['date'] ) ),
				esc_html( $o['status'] ),
				esc_html( $o['total'] )
			);
		}
		if ( ! $orders ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No orders yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( count( $sites ) > 1 ) {
			$this->render_copy_form( $sites );
		}
		echo '</div>';
	}

	/**
	 * The copy form.
	 *
	 * @param int[] $sites Stores.
	 */
	protected function render_copy_form( array $sites ) {
		$names = array();
		foreach ( $sites as $id ) {
			$names[ $id ] = get_blog_option( $id, 'blogname' ) . ' (' . preg_replace( '#^https?://#', '', untrailingslashit( get_home_url( $id ) ) ) . ')';
		}
		echo '<h2>' . esc_html__( 'Copy between stores', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:980px">';
		wp_nonce_field( 'sidccofo_network_copy' );
		echo '<input type="hidden" name="action" value="sidccofo_network_copy"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="sc-copy-from">' . esc_html__( 'From', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label></th><td><select id="sc-copy-from" name="from">';
		foreach ( $names as $id => $name ) {
			echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $name ) . '</option>';
		}
		echo '</select></td></tr><tr><th scope="row">' . esc_html__( 'To', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><td><fieldset>';
		foreach ( $names as $id => $name ) {
			echo '<label style="display:block"><input type="checkbox" name="to[]" value="' . esc_attr( (string) $id ) . '"> ' . esc_html( $name ) . '</label>';
		}
		echo '</fieldset></td></tr><tr><th scope="row">' . esc_html__( 'Copy', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><td><fieldset>';
		$what = array(
			'settings' => __( 'Settings (currencies, shipping, tax, rewards and the rest; not payment methods or the email sender)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'products' => __( 'Products, with their categories, brands, images and downloadable files', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'coupons'  => __( 'Coupons (use counts start at 0)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
		foreach ( $what as $key => $label ) {
			echo '<label style="display:block"><input type="checkbox" name="what[]" value="' . esc_attr( $key ) . '"' . ( 'products' === $key ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset></td></tr><tr><th scope="row">' . esc_html__( 'Already there', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><td><label><input type="checkbox" name="update" value="1"> ' . esc_html__( 'Update products and coupons copied before, or with the same SKU or code (otherwise they are skipped)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Copy', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		echo '</form>';
	}

	/**
	 * Start or continue a copy (one batch per request).
	 */
	public function handle_copy() {
		if ( ! current_user_can( 'manage_network_options' ) || ! check_admin_referer( 'sidccofo_network_copy' ) ) {
			wp_die( esc_html__( 'You cannot copy between stores.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$stores = self::store_sites();
		if ( isset( $_POST['from'] ) ) {
			$from = absint( $_POST['from'] );
			$to   = array_values( array_diff( array_intersect( array_map( 'absint', (array) wp_unslash( $_POST['to'] ?? array() ) ), $stores ), array( $from ) ) );
			$what = array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['what'] ?? array() ) ), array( 'settings', 'products', 'coupons' ) ) );
			if ( ! in_array( $from, $stores, true ) || ! $to || ! $what ) {
				wp_safe_redirect( add_query_arg( 'copied', rawurlencode( __( 'Choose a store to copy from, at least one other store to copy to, and what to copy.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ), network_admin_url( 'admin.php?page=sidccofo-network' ) ) );
				exit;
			}
			$job = array(
				'from'   => $from,
				'to'     => $to,
				'what'   => $what,
				'update' => ! empty( $_POST['update'] ),
				'target' => 0,
				'step'   => 0,
				'offset' => 0,
				'counts' => array(),
			);
		} else {
			$job = get_site_option( self::JOB );
			if ( ! is_array( $job ) ) {
				wp_safe_redirect( network_admin_url( 'admin.php?page=sidccofo-network' ) );
				exit;
			}
		}

		$job = self::run_step( $job );
		if ( ! empty( $job['finished'] ) ) {
			delete_site_option( self::JOB );
			wp_safe_redirect( add_query_arg( 'copied', rawurlencode( self::describe( $job ) ), network_admin_url( 'admin.php?page=sidccofo-network' ) ) );
			exit;
		}
		update_site_option( self::JOB, $job );
		// Next batch: a self-submitting form keeps the nonce in POST.
		$action = esc_url( admin_url( 'admin-post.php' ) );
		$nonce  = wp_create_nonce( 'sidccofo_network_copy' );
		$done   = array_sum( array_map( 'array_sum', $job['counts'] ) );
		/* translators: %d: items copied so far. */
		$text = esc_html( sprintf( __( 'Copying… %d items so far.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $done ) );
		echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__( 'Copying', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</title><p style="font:16px system-ui;margin:40px">' . $text . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		echo '<form id="f" method="post" action="' . $action . '"><input type="hidden" name="action" value="sidccofo_network_copy"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '"><noscript><button>' . esc_html__( 'Continue', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></noscript></form><script>document.getElementById("f").submit();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		exit;
	}

	/**
	 * Do one step of a copy job: settings for a target, or a batch.
	 *
	 * @param array $job Job.
	 * @return array Updated job (finished = true at the end).
	 */
	public static function run_step( array $job ) {
		$targets = array_values( $job['to'] );
		if ( ! isset( $targets[ $job['target'] ] ) ) {
			$job['finished'] = true;
			return $job;
		}
		$to   = (int) $targets[ $job['target'] ];
		$what = $job['what'][ $job['step'] ] ?? null;
		if ( null === $what ) {
			$job['target']++;
			$job['step']   = 0;
			$job['offset'] = 0;
			return self::run_step( $job );
		}
		$count = &$job['counts'][ $what ];
		$count = is_array( $count ) ? $count : array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
		);
		if ( 'settings' === $what ) {
			self::copy_settings( $job['from'], $to );
			++$count['updated'];
			$done = true;
		} else {
			$r = 'products' === $what ? self::copy_products( $job['from'], $to, $job['offset'], $job['update'] ) : self::copy_coupons( $job['from'], $to, $job['offset'], $job['update'] );
			foreach ( array( 'created', 'updated', 'skipped' ) as $k ) {
				$count[ $k ] += $r[ $k ];
			}
			$job['offset'] = $r['next'];
			$done          = $r['done'];
		}
		unset( $count );
		if ( $done ) {
			$job['step']++;
			$job['offset'] = 0;
		}
		return $job;
	}

	/**
	 * "Copied to 2 stores: products 10 new, 3 updated…"
	 *
	 * @param array $job Finished job.
	 * @return string
	 */
	protected static function describe( array $job ) {
		$parts = array();
		foreach ( $job['counts'] as $what => $c ) {
			if ( 'settings' === $what ) {
				$parts[] = __( 'settings', 'sidcraft-commerce-for-sidcraft-page-builder' );
				continue;
			}
			/* translators: 1: products or coupons, 2: new, 3: updated, 4: skipped. */
			$parts[] = sprintf( __( '%1$s: %2$d new, %3$d updated, %4$d skipped', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'products' === $what ? __( 'products', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'coupons', 'sidcraft-commerce-for-sidcraft-page-builder' ), $c['created'], $c['updated'], $c['skipped'] );
		}
		/* translators: 1: number of stores, 2: what was copied. */
		return sprintf( _n( 'Copied to %1$d store: %2$s.', 'Copied to %1$d stores: %2$s.', count( $job['to'] ), 'sidcraft-commerce-for-sidcraft-page-builder' ), count( $job['to'] ), implode( '; ', $parts ) );
	}

	/*
	------------------------------------------------------------------
	 * WP-CLI
	 * ----------------------------------------------------------------
	 */

	/**
	 * List the network's stores with their last 30 days.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sidcraft-commerce stores
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function cli_stores( $args, $assoc ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP-CLI signature.
		$rows = array();
		foreach ( self::store_sites() as $id ) {
			$s      = self::summary( $id );
			$rows[] = array(
				'site'     => $s['id'],
				'name'     => $s['name'],
				'currency' => $s['currency'],
				'products' => $s['products'],
				'orders'   => $s['orders'],
				'sales'    => $s['sales'],
				'pending'  => $s['pending'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'site', 'name', 'currency', 'products', 'orders', 'sales', 'pending' ) );
	}

	/**
	 * Copy settings, products and coupons from one store to others.
	 *
	 * ## OPTIONS
	 *
	 * --from=<site>
	 * : Site id to copy from.
	 *
	 * --to=<sites>
	 * : Site ids to copy to, separated by commas.
	 *
	 * [--what=<what>]
	 * : settings, products and/or coupons, separated by commas.
	 * ---
	 * default: products
	 * ---
	 *
	 * [--update]
	 * : Update items copied before (or with the same SKU or code).
	 *
	 * ## EXAMPLES
	 *
	 *     wp sidcraft-commerce copy --from=1 --to=2,3 --what=settings,products
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function cli_copy( $args, $assoc ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$stores = self::store_sites();
		$from   = absint( $assoc['from'] ?? 0 );
		$to     = array_values( array_diff( array_intersect( array_map( 'absint', explode( ',', (string) ( $assoc['to'] ?? '' ) ) ), $stores ), array( $from ) ) );
		$what   = array_values( array_intersect( array_map( 'trim', explode( ',', (string) ( $assoc['what'] ?? 'products' ) ) ), array( 'settings', 'products', 'coupons' ) ) );
		if ( ! in_array( $from, $stores, true ) || ! $to || ! $what ) {
			\WP_CLI::error( 'Give --from (a store), --to (other stores) and --what (settings, products, coupons).' );
		}
		$job = array(
			'from'   => $from,
			'to'     => $to,
			'what'   => $what,
			'update' => ! empty( $assoc['update'] ),
			'target' => 0,
			'step'   => 0,
			'offset' => 0,
			'counts' => array(),
		);
		do {
			$job = self::run_step( $job );
		} while ( empty( $job['finished'] ) );
		\WP_CLI::success( self::describe( $job ) );
	}
}
