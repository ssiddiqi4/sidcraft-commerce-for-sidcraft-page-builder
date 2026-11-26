<?php
/**
 * CSV import and export: products (with stock), orders and customers.
 *
 * Store → Import / Export. Product CSV columns:
 *   sku, name, description, short_description, regular_price, sale_price,
 *   stock (empty = stock not tracked), status, categories (comma-separated),
 *   tags (comma-separated), weight, image_url (import only: downloaded once)
 * plus id and slug on export. On import a row is matched to an existing
 * product by id, else by SKU, else it creates a new product.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\manage_cap;

defined( 'ABSPATH' ) || exit;

/**
 * Class Csv
 */
class Csv extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$this->admin_page( 'sidccofo-csv', __( 'Import / Export', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'page' ) );
		add_action( 'admin_post_sidccofo_csv_export', array( $this, 'export' ) );
		add_action( 'admin_post_sidccofo_csv_import', array( $this, 'import' ) );
	}

	/**
	 * Product columns.
	 *
	 * @return string[]
	 */
	public static function columns() {
		return array( 'id', 'sku', 'name', 'slug', 'description', 'short_description', 'regular_price', 'sale_price', 'stock', 'status', 'categories', 'tags', 'weight', 'image_url' );
	}

	/**
	 * Admin page.
	 */
	public function page() {
		if ( ! current_user_can( manage_cap() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Result notice only.
		$done = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : -1;
		$new  = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
		$bad  = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		// phpcs:enable
		echo '<div class="wrap"><h1>' . esc_html__( 'Import / Export', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h1>';
		if ( $done >= 0 ) {
			/* translators: 1: rows updated, 2: products created, 3: rows skipped. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Import finished: %1$d products updated, %2$d created, %3$d rows skipped.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $done, $new, $bad ) ) . '</p></div>';
		}
		echo '<h2>' . esc_html__( 'Export', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h2><p>';
		foreach ( array(
			'products'  => __( 'Products (CSV)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'orders'    => __( 'Orders (CSV)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'customers' => __( 'Customers (CSV)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		) as $what => $label ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'sidccofo_csv_export', 'what' => $what ), admin_url( 'admin-post.php' ) ), 'sidccofo_csv_export' );
			echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
		}
		echo '</p><h2>' . esc_html__( 'Import products', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h2>';
		echo '<p>' . esc_html__( 'Upload a CSV with these columns in the first row (any order, any may be left out):', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' <code>' . esc_html( implode( ', ', self::columns() ) ) . '</code></p>';
		echo '<p class="description">' . esc_html__( 'Rows are matched to products by id, then SKU; the rest create new products. Leave stock empty to stop tracking stock. image_url downloads the picture once, for new products or ones with no image.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sidccofo_csv_import">';
		wp_nonce_field( 'sidccofo_csv_import' );
		echo '<input type="file" name="csv" accept=".csv,text/csv" required> <button class="button button-primary">' . esc_html__( 'Import', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></form></div>';
	}

	/**
	 * Download.
	 */
	public function export() {
		if ( ! current_user_can( manage_cap() ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'sidccofo_csv_export' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		$what = isset( $_GET['what'] ) ? sanitize_key( wp_unslash( $_GET['what'] ) ) : 'products';
		if ( 'orders' === $what ) {
			$rows = $this->orders_rows();
		} elseif ( 'customers' === $what ) {
			$rows = $this->customers_rows();
		} else {
			$what = 'products';
			$rows = $this->products_rows();
		}
		Extras::send_file( 'sidcraft-' . $what . '-' . gmdate( 'Y-m-d' ) . '.csv', 'text/csv; charset=utf-8', Extras::csv( $rows ) );
	}

	/**
	 * Product rows.
	 *
	 * @return array[]
	 */
	protected function products_rows() {
		$rows = array( self::columns() );
		$ids  = get_posts(
			array(
				'post_type'      => Post_Types::PRODUCT,
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$post   = get_post( $id );
			$cats   = wp_get_object_terms( $id, Post_Types::CATEGORY, array( 'fields' => 'names' ) );
			$tags   = wp_get_object_terms( $id, Post_Types::TAG, array( 'fields' => 'names' ) );
			$manage = 'yes' === get_post_meta( $id, '_sidccofo_manage_stock', true );
			$rows[] = array(
				$id,
				get_post_meta( $id, '_sidccofo_sku', true ),
				$post->post_title,
				$post->post_name,
				$post->post_content,
				$post->post_excerpt,
				get_post_meta( $id, '_sidccofo_regular_price', true ),
				get_post_meta( $id, '_sidccofo_sale_price', true ),
				$manage ? (int) get_post_meta( $id, '_sidccofo_stock', true ) : '',
				$post->post_status,
				is_wp_error( $cats ) ? '' : implode( ', ', $cats ),
				is_wp_error( $tags ) ? '' : implode( ', ', $tags ),
				get_post_meta( $id, '_sidccofo_weight', true ),
				(string) get_the_post_thumbnail_url( $id, 'full' ),
			);
		}
		return $rows;
	}

	/**
	 * Order rows.
	 *
	 * @return array[]
	 */
	protected function orders_rows() {
		$rows = array( array( 'order', 'date', 'status', 'currency', 'subtotal', 'discount', 'shipping', 'tax', 'total', 'coupon', 'payment', 'email', 'first_name', 'last_name', 'country', 'items' ) );
		$ids  = get_posts(
			array(
				'post_type'      => Post_Types::ORDER,
				'post_status'    => array_keys( Post_Types::order_statuses() ),
				'posts_per_page' => 10000,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$o = Order::get( $id );
			if ( ! $o ) {
				continue;
			}
			$b     = $o->get_billing();
			$items = array();
			foreach ( $o->get_items() as $item ) {
				$items[] = $item['quantity'] . ' × ' . $item['name'] . ( ! empty( $item['sku'] ) ? ' [' . $item['sku'] . ']' : '' );
			}
			$rows[] = array( $id, $o->get_date(), $o->get_status(), $o->get_currency(), $o->get_subtotal(), $o->get_discount(), $o->get_shipping(), $o->get_tax(), $o->get_total(), $o->get_coupon_code(), $o->get_payment_method_title(), $o->get_email(), $b['first_name'] ?? '', $b['last_name'] ?? '', $b['country'] ?? '', implode( '; ', $items ) );
		}
		return $rows;
	}

	/**
	 * Customer rows (by billing email, paid orders).
	 *
	 * @return array[]
	 */
	protected function customers_rows() {
		$by  = array();
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ORDER,
				'post_status'    => Post_Types::paid_statuses(),
				'posts_per_page' => 10000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$o = Order::get( $id );
			if ( ! $o || '' === $o->get_email() ) {
				continue;
			}
			$e = strtolower( $o->get_email() );
			$b = $o->get_billing();
			if ( ! isset( $by[ $e ] ) ) {
				$by[ $e ] = array( $e, $b['first_name'] ?? '', $b['last_name'] ?? '', $b['phone'] ?? '', $b['country'] ?? '', 0, 0.0, '' );
			}
			++$by[ $e ][5];
			$by[ $e ][6] += $o->to_base( (float) $o->get_total() );
			$by[ $e ][7]  = max( (string) $by[ $e ][7], $o->get_date() );
		}
		return array_merge( array( array( 'email', 'first_name', 'last_name', 'phone', 'country', 'orders', 'total_spent', 'last_order' ) ), array_values( $by ) );
	}

	/**
	 * Import a product CSV.
	 */
	public function import() {
		if ( ! current_user_can( manage_cap() ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sidccofo_csv_import' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		$tmp = isset( $_FILES['csv']['tmp_name'] ) && is_string( $_FILES['csv']['tmp_name'] ) ? $_FILES['csv']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A server temp path, checked with is_uploaded_file().
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_safe_redirect( $this->back() );
			exit;
		}
		$result = self::import_file( $tmp );
		wp_safe_redirect( add_query_arg( array( 'imported' => $result['updated'], 'created' => $result['created'], 'skipped' => $result['skipped'] ), $this->back() ) );
		exit;
	}

	/**
	 * Page URL.
	 *
	 * @return string
	 */
	protected function back() {
		return $this->page_url( 'sidccofo-csv' );
	}

	/**
	 * Import from a file path.
	 *
	 * @param string $path CSV path.
	 * @return array updated, created, skipped.
	 */
	public static function import_file( $path ) {
		$out = array(
			'updated' => 0,
			'created' => 0,
			'skipped' => 0,
		);
		$h   = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $h ) {
			return $out;
		}
		$head = fgetcsv( $h );
		if ( ! is_array( $head ) ) {
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return $out;
		}
		$head = array_map(
			static function ( $c ) {
				return strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $c ) ) );
			},
			$head
		);
		$n = 0;
		while ( false !== ( $row = fgetcsv( $h ) ) && $n < 5000 ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			++$n;
			if ( ! array_filter( $row, 'strlen' ) ) {
				continue;
			}
			$data = array();
			foreach ( $head as $i => $col ) {
				$data[ $col ] = isset( $row[ $i ] ) ? (string) $row[ $i ] : '';
			}
			$r = self::import_row( $data );
			if ( 'updated' === $r ) {
				++$out['updated'];
			} elseif ( 'created' === $r ) {
				++$out['created'];
			} else {
				++$out['skipped'];
			}
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		Product::bump_query_version();
		return $out;
	}

	/**
	 * Import one row.
	 *
	 * @param array $d Column => value.
	 * @return string updated|created|skipped
	 */
	protected static function import_row( array $d ) {
		$id  = isset( $d['id'] ) && ctype_digit( trim( $d['id'] ) ) ? (int) $d['id'] : 0;
		$sku = isset( $d['sku'] ) ? sanitize_text_field( trim( $d['sku'] ) ) : '';
		if ( $id && Post_Types::PRODUCT !== get_post_type( $id ) ) {
			$id = 0;
		}
		if ( ! $id && '' !== $sku ) {
			$found = get_posts(
				array(
					'post_type'      => Post_Types::PRODUCT,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_sidccofo_sku', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $sku, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			$id    = $found ? (int) $found[0] : 0;
		}
		$name = isset( $d['name'] ) ? sanitize_text_field( $d['name'] ) : '';
		if ( ! $id && '' === $name ) {
			return 'skipped';
		}
		$status = isset( $d['status'] ) && in_array( $d['status'], array( 'publish', 'draft', 'private', 'pending' ), true ) ? $d['status'] : '';
		$post   = array( 'post_type' => Post_Types::PRODUCT );
		if ( $id ) {
			$post['ID'] = $id;
		} else {
			$post['post_status'] = '' !== $status ? $status : 'publish';
		}
		if ( '' !== $name ) {
			$post['post_title'] = $name;
		}
		if ( isset( $d['description'] ) && ( $id ? array_key_exists( 'description', 'sidcraft-commerce-for-sidcraft-page-builder' ) : true ) ) {
			$post['post_content'] = wp_kses_post( $d['description'] );
		}
		if ( isset( $d['short_description'] ) ) {
			$post['post_excerpt'] = wp_kses_post( $d['short_description'] );
		}
		if ( $id && '' !== $status ) {
			$post['post_status'] = $status;
		}
		if ( ! empty( $d['slug'] ) && ! $id ) {
			$post['post_name'] = sanitize_title( $d['slug'] );
		}
		$result = $id ? wp_update_post( $post, true ) : wp_insert_post( $post, true );
		if ( is_wp_error( $result ) || ! $result ) {
			return 'skipped';
		}
		$pid = (int) $result;
		if ( '' !== $sku ) {
			update_post_meta( $pid, '_sidccofo_sku', $sku );
		}
		foreach ( array(
			'regular_price' => '_sidccofo_regular_price',
			'sale_price'    => '_sidccofo_sale_price',
			'weight'        => '_sidccofo_weight',
		) as $col => $meta ) {
			if ( isset( $d[ $col ] ) ) {
				$v = trim( str_replace( ',', '.', $d[ $col ] ) );
				update_post_meta( $pid, $meta, is_numeric( $v ) ? (string) max( 0, (float) $v ) : '' );
			}
		}
		if ( isset( $d['stock'] ) ) {
			if ( '' === trim( $d['stock'] ) ) {
				update_post_meta( $pid, '_sidccofo_manage_stock', 'no' );
			} elseif ( is_numeric( $d['stock'] ) ) {
				update_post_meta( $pid, '_sidccofo_manage_stock', 'yes' );
				update_post_meta( $pid, '_sidccofo_stock', (int) $d['stock'] );
				update_post_meta( $pid, '_sidccofo_stock_status', (int) $d['stock'] > 0 ? 'instock' : 'outofstock' );
			}
		}
		foreach ( array(
			'categories' => Post_Types::CATEGORY,
			'tags'       => Post_Types::TAG,
		) as $col => $tax ) {
			if ( isset( $d[ $col ] ) && '' !== trim( $d[ $col ] ) ) {
				$names = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $d[ $col ] ) ) ) );
				wp_set_object_terms( $pid, $names, $tax );
			}
		}
		if ( ! empty( $d['image_url'] ) && ! has_post_thumbnail( $pid ) ) {
			self::sideload( $pid, trim( $d['image_url'] ) );
		}
		Product::sync_derived_meta( $pid );
		return isset( $post['ID'] ) ? 'updated' : 'created';
	}

	/**
	 * Download a picture for a product (public http(s) addresses only).
	 *
	 * @param int    $post_id Product.
	 * @param string $url     Image URL.
	 */
	protected static function sideload( $post_id, $url ) {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$att = media_sideload_image( $url, $post_id, null, 'id' );
		if ( ! is_wp_error( $att ) ) {
			set_post_thumbnail( $post_id, (int) $att );
		}
	}
}
