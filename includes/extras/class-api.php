<?php
/**
 * REST API with API keys, and outgoing webhooks.
 *
 * Store → API & webhooks creates keys (shown once, stored hashed) and webhook
 * endpoints. Send a key as "Authorization: Bearer sck_…" (or the header
 * "X-Sidcraft-Key"). Read keys can GET; read/write keys can also create and
 * change products and set order statuses.
 *
 *   GET  /wp-json/sidcraft-commerce/v1/api/products?page=1&per_page=20&search=
 *   GET  /wp-json/sidcraft-commerce/v1/api/products/{id}
 *   POST /wp-json/sidcraft-commerce/v1/api/products          (write)  name, price, sku, stock, status, description
 *   POST /wp-json/sidcraft-commerce/v1/api/products/{id}     (write)  same fields
 *   GET  /wp-json/sidcraft-commerce/v1/api/orders?status=&page=
 *   GET  /wp-json/sidcraft-commerce/v1/api/orders/{id}
 *   POST /wp-json/sidcraft-commerce/v1/api/orders/{id}       (write)  status
 *   GET  /wp-json/sidcraft-commerce/v1/api/customers
 *
 * Webhooks: order.created, order.paid, order.status_changed, order.refunded,
 * product.saved. Each delivery is JSON, signed with HMAC-SHA256 of the body in
 * the header X-Sidcraft-Signature (hex), using the webhook's secret.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\manage_cap;
use function SidcraftCommerce\rate_limit;

defined( 'ABSPATH' ) || exit;

/**
 * Class Api
 */
class Api extends Tool {

	const KEYS  = 'sidcraft_commerce_api_keys';
	const HOOKS = 'sidcraft_commerce_webhooks';
	const NS    = 'sidcraft-commerce/v1';

	/**
	 * Webhook deliveries waiting for the end of the request.
	 *
	 * @var array[]
	 */
	protected static $queue = array();

	/** {@inheritDoc} */
	public function hooks() {
		$this->admin_page( 'sidccofo-api', __( 'API & webhooks', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'page' ) );
		add_action( 'admin_post_sidccofo_api', array( $this, 'handle_admin' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'sidcraft_commerce_order_created', function ( $o ) {
			self::fire( 'order.created', $this->order_data( $o ) );
		} );
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'on_status' ), 10, 3 );
		add_action( 'save_post_' . Post_Types::PRODUCT, function ( $id ) {
			if ( 'publish' === get_post_status( $id ) && ! wp_is_post_revision( $id ) && ! wp_is_post_autosave( $id ) ) {
				self::fire( 'product.saved', $this->product_data( (int) $id ) );
			}
		}, 99 );
		add_action( 'shutdown', array( __CLASS__, 'deliver' ), 99 );
	}

	/*
	------------------------------------------------------------------
	 * Webhooks
	 * ----------------------------------------------------------------
	 */

	/**
	 * Topics.
	 *
	 * @return string[]
	 */
	public static function topics() {
		return array( 'order.created', 'order.paid', 'order.status_changed', 'order.refunded', 'product.saved' );
	}

	/**
	 * Order status changed.
	 *
	 * @param Order  $order Order.
	 * @param string $old   Old.
	 * @param string $new   New.
	 */
	public function on_status( $order, $old, $new ) {
		$data = $this->order_data( $order ) + array( 'previous_status' => $old );
		self::fire( 'order.status_changed', $data );
		if ( in_array( $new, Post_Types::paid_statuses(), true ) && ! in_array( $old, Post_Types::paid_statuses(), true ) ) {
			self::fire( 'order.paid', $data );
		}
		if ( 'sidccofo-refunded' === $new ) {
			self::fire( 'order.refunded', $data );
		}
	}

	/**
	 * Queue a delivery to every webhook that listens to a topic.
	 *
	 * @param string $topic Topic.
	 * @param array  $data  Payload.
	 */
	public static function fire( $topic, array $data ) {
		foreach ( (array) get_option( self::HOOKS, array() ) as $hook ) {
			if ( ! empty( $hook['on'] ) && in_array( $topic, (array) $hook['topics'], true ) ) {
				self::$queue[] = array( $hook, $topic, $data );
			}
		}
	}

	/**
	 * Send queued deliveries after the response has gone out.
	 */
	public static function deliver() {
		if ( ! self::$queue ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$log = (array) get_option( 'sidcraft_commerce_webhook_log', array() );
		foreach ( self::$queue as $job ) {
			list( $hook, $topic, $data ) = $job;
			$body     = wp_json_encode( array( 'topic' => $topic, 'time' => gmdate( 'c' ), 'data' => $data ) );
			$response = wp_safe_remote_post(
				$hook['url'],
				array(
					'timeout'     => 8,
					'redirection' => 0,
					'headers'     => array(
						'Content-Type'           => 'application/json',
						'X-Sidcraft-Topic'       => $topic,
						'X-Sidcraft-Signature'   => hash_hmac( 'sha256', (string) $body, (string) $hook['secret'] ),
						'User-Agent'             => 'SidcraftCommerce/' . SIDCRAFT_COMMERCE_VERSION,
					),
					'body'        => $body,
				)
			);
			$log[ $hook['id'] ] = array(
				'time'   => time(),
				'topic'  => $topic,
				'status' => is_wp_error( $response ) ? $response->get_error_message() : (int) wp_remote_retrieve_response_code( $response ),
			);
		}
		self::$queue = array();
		update_option( 'sidcraft_commerce_webhook_log', $log, false );
	}

	/*
	------------------------------------------------------------------
	 * Data shapes
	 * ----------------------------------------------------------------
	 */

	/**
	 * An order for the API.
	 *
	 * @param Order $o Order.
	 * @return array
	 */
	public function order_data( $o ) {
		$items = array();
		foreach ( $o->get_items() as $i ) {
			$items[] = array( 'product_id' => (int) $i['product_id'], 'name' => wp_strip_all_tags( (string) $i['name'] ), 'sku' => (string) $i['sku'], 'quantity' => (int) $i['quantity'], 'price' => (float) $i['price'], 'total' => (float) $i['total'] );
		}
		return array(
			'id'       => $o->get_id(),
			'status'   => str_replace( 'sidccofo-', '', $o->get_status() ),
			'currency' => $o->get_currency(),
			'subtotal' => (float) $o->get_subtotal(),
			'discount' => (float) $o->get_discount(),
			'shipping' => (float) $o->get_shipping(),
			'tax'      => (float) $o->get_tax(),
			'total'    => (float) $o->get_total(),
			'payment'  => $o->get_payment_method(),
			'email'    => $o->get_email(),
			'billing'  => $o->get_billing(),
			'items'    => $items,
			'created'  => get_post_time( 'c', true, $o->get_id() ),
		);
	}

	/**
	 * A product for the API.
	 *
	 * @param int $id Product.
	 * @return array
	 */
	public function product_data( $id ) {
		$p = Product::get( $id );
		if ( ! $p ) {
			return array();
		}
		return array(
			'id'            => $id,
			'name'          => wp_strip_all_tags( $p->get_name() ),
			'slug'          => get_post_field( 'post_name', $id ),
			'status'        => get_post_status( $id ),
			'sku'           => (string) $p->get_sku(),
			'regular_price' => $p->get_regular_price(),
			'sale_price'    => $p->get_sale_price(),
			'price'         => $p->get_price(),
			'stock'         => $p->get_stock_quantity(),
			'in_stock'      => $p->is_in_stock(),
			'url'           => $p->get_permalink(),
			'image'         => (string) get_the_post_thumbnail_url( $id, 'full' ),
			'description'   => wp_strip_all_tags( $p->get_short_description() ),
		);
	}

	/*
	------------------------------------------------------------------
	 * REST
	 * ----------------------------------------------------------------
	 */

	/**
	 * Which key is calling? Returns the key row or null.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|null
	 */
	protected function caller( \WP_REST_Request $request ) {
		$token = '';
		$auth  = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/^Bearer\s+(sck_[A-Za-z0-9]{32,})$/', $auth, $m ) ) {
			$token = $m[1];
		} elseif ( preg_match( '/^sck_[A-Za-z0-9]{32,}$/', (string) $request->get_header( 'x_sidcraft_key' ) ) ) {
			$token = (string) $request->get_header( 'x_sidcraft_key' );
		}
		if ( '' === $token ) {
			return null;
		}
		$hash = hash( 'sha256', $token );
		foreach ( (array) get_option( self::KEYS, array() ) as $key ) {
			if ( hash_equals( (string) $key['hash'], $hash ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Permission check factory.
	 *
	 * @param bool $write Needs a read/write key.
	 * @return callable
	 */
	protected function guard( $write ) {
		return function ( \WP_REST_Request $request ) use ( $write ) {
			$key = $this->caller( $request );
			if ( ! $key ) {
				return new \WP_Error( 'sidccofo_api_auth', __( 'A valid API key is required.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 401 ) );
			}
			if ( $write && 'write' !== $key['perm'] ) {
				return new \WP_Error( 'sidccofo_api_perm', __( 'This key is read-only.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 403 ) );
			}
			if ( ! rate_limit( 'api_' . substr( (string) $key['hash'], 0, 12 ), 300, MINUTE_IN_SECONDS ) ) {
				return new \WP_Error( 'sidccofo_rate', __( 'Too many requests.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
			}
			return true;
		};
	}

	/**
	 * Routes.
	 */
	public function routes() {
		$read  = $this->guard( false );
		$write = $this->guard( true );
		register_rest_route( self::NS, '/api/products', array(
			array( 'methods' => 'GET', 'permission_callback' => $read, 'callback' => array( $this, 'list_products' ) ),
			array( 'methods' => 'POST', 'permission_callback' => $write, 'callback' => array( $this, 'save_product' ) ),
		) );
		register_rest_route( self::NS, '/api/products/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'permission_callback' => $read, 'callback' => array( $this, 'get_product' ) ),
			array( 'methods' => 'POST', 'permission_callback' => $write, 'callback' => array( $this, 'save_product' ) ),
		) );
		register_rest_route( self::NS, '/api/orders', array( 'methods' => 'GET', 'permission_callback' => $read, 'callback' => array( $this, 'list_orders' ) ) );
		register_rest_route( self::NS, '/api/orders/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'permission_callback' => $read, 'callback' => array( $this, 'get_order' ) ),
			array( 'methods' => 'POST', 'permission_callback' => $write, 'callback' => array( $this, 'set_order_status' ) ),
		) );
		register_rest_route( self::NS, '/api/customers', array( 'methods' => 'GET', 'permission_callback' => $read, 'callback' => array( $this, 'list_customers' ) ) );
	}

	/**
	 * Products list.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function list_products( \WP_REST_Request $r ) {
		$q = new \WP_Query(
			array(
				'post_type'      => Post_Types::PRODUCT,
				'post_status'    => 'publish',
				'posts_per_page' => min( 100, max( 1, (int) $r['per_page'] ?: 20 ) ),
				'paged'          => max( 1, (int) $r['page'] ),
				's'              => sanitize_text_field( (string) $r['search'] ),
				'fields'         => 'ids',
			)
		);
		$res = rest_ensure_response( array_map( array( $this, 'product_data' ), $q->posts ) );
		$res->header( 'X-WP-Total', (string) $q->found_posts );
		$res->header( 'X-WP-TotalPages', (string) $q->max_num_pages );
		return $res;
	}

	/**
	 * One product.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_product( \WP_REST_Request $r ) {
		$d = $this->product_data( (int) $r['id'] );
		return $d ? rest_ensure_response( $d ) : new \WP_Error( 'sidccofo_api_404', __( 'Product not found.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
	}

	/**
	 * Create or update a product.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_product( \WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$post = array( 'post_type' => Post_Types::PRODUCT );
		if ( $id ) {
			if ( Post_Types::PRODUCT !== get_post_type( $id ) ) {
				return new \WP_Error( 'sidccofo_api_404', __( 'Product not found.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
			}
			$post['ID'] = $id;
		} elseif ( '' === trim( (string) $r['name'] ) ) {
			return new \WP_Error( 'sidccofo_api_name', __( 'A name is required.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		if ( null !== $r['name'] ) {
			$post['post_title'] = sanitize_text_field( (string) $r['name'] );
		}
		if ( null !== $r['description'] ) {
			$post['post_excerpt'] = wp_kses_post( (string) $r['description'] );
		}
		$status = (string) $r['status'];
		if ( in_array( $status, array( 'publish', 'draft', 'private' ), true ) ) {
			$post['post_status'] = $status;
		} elseif ( ! $id ) {
			$post['post_status'] = 'draft';
		}
		$new = $id ? wp_update_post( $post, true ) : wp_insert_post( $post, true );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$new = (int) $new;
		foreach ( array( 'price' => '_sidccofo_regular_price', 'sale_price' => '_sidccofo_sale_price' ) as $f => $meta ) {
			if ( null !== $r[ $f ] ) {
				update_post_meta( $new, $meta, is_numeric( $r[ $f ] ) ? (string) max( 0, (float) $r[ $f ] ) : '' );
			}
		}
		if ( null !== $r['sku'] ) {
			update_post_meta( $new, '_sidccofo_sku', sanitize_text_field( (string) $r['sku'] ) );
		}
		if ( null !== $r['stock'] ) {
			if ( '' === (string) $r['stock'] ) {
				update_post_meta( $new, '_sidccofo_manage_stock', 'no' );
			} elseif ( is_numeric( $r['stock'] ) ) {
				update_post_meta( $new, '_sidccofo_manage_stock', 'yes' );
				update_post_meta( $new, '_sidccofo_stock', (int) $r['stock'] );
			}
		}
		Product::forget( $new );
		Product::sync_derived_meta( $new );
		return rest_ensure_response( $this->product_data( $new ) );
	}

	/**
	 * Orders list.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function list_orders( \WP_REST_Request $r ) {
		$status = 'sidccofo-' . sanitize_key( (string) $r['status'] );
		$q      = new \WP_Query(
			array(
				'post_type'      => Post_Types::ORDER,
				'post_status'    => array_key_exists( $status, Post_Types::order_statuses() ) ? $status : array_keys( Post_Types::order_statuses() ),
				'posts_per_page' => min( 100, max( 1, (int) $r['per_page'] ?: 20 ) ),
				'paged'          => max( 1, (int) $r['page'] ),
				'fields'         => 'ids',
			)
		);
		$out = array();
		foreach ( $q->posts as $id ) {
			$o = Order::get( $id );
			if ( $o ) {
				$out[] = $this->order_data( $o );
			}
		}
		$res = rest_ensure_response( $out );
		$res->header( 'X-WP-Total', (string) $q->found_posts );
		return $res;
	}

	/**
	 * One order.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_order( \WP_REST_Request $r ) {
		$o = Order::get( (int) $r['id'] );
		return $o ? rest_ensure_response( $this->order_data( $o ) ) : new \WP_Error( 'sidccofo_api_404', __( 'Order not found.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
	}

	/**
	 * Change an order's status.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_order_status( \WP_REST_Request $r ) {
		$o      = Order::get( (int) $r['id'] );
		$status = 'sidccofo-' . sanitize_key( (string) $r['status'] );
		if ( ! $o ) {
			return new \WP_Error( 'sidccofo_api_404', __( 'Order not found.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
		}
		if ( ! array_key_exists( $status, Post_Types::order_statuses() ) ) {
			return new \WP_Error( 'sidccofo_api_status', __( 'Unknown status.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		$o->set_status( $status, __( 'Changed through the API.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		return rest_ensure_response( $this->order_data( Order::get( $o->get_id() ) ) );
	}

	/**
	 * Customers (from paid orders).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function list_customers( \WP_REST_Request $r ) {
		unset( $r );
		$by  = array();
		$ids = get_posts( array( 'post_type' => Post_Types::ORDER, 'post_status' => Post_Types::paid_statuses(), 'posts_per_page' => 2000, 'fields' => 'ids', 'no_found_rows' => true ) );
		foreach ( $ids as $id ) {
			$o = Order::get( $id );
			if ( ! $o || '' === $o->get_email() ) {
				continue;
			}
			$e = strtolower( $o->get_email() );
			$b = $o->get_billing();
			$by[ $e ] = $by[ $e ] ?? array( 'email' => $e, 'first_name' => $b['first_name'] ?? '', 'last_name' => $b['last_name'] ?? '', 'orders' => 0, 'total_spent' => 0.0 );
			++$by[ $e ]['orders'];
			$by[ $e ]['total_spent'] += $o->to_base( (float) $o->get_total() );
		}
		return rest_ensure_response( array_values( $by ) );
	}

	/*
	------------------------------------------------------------------
	 * Admin page
	 * ----------------------------------------------------------------
	 */

	/**
	 * The page.
	 */
	public function page() {
		echo '<div class="wrap"><h1>' . esc_html__( 'API & webhooks', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h1>';
		$new = get_transient( 'sidccofo_new_key_' . get_current_user_id() );
		if ( $new ) {
			delete_transient( 'sidccofo_new_key_' . get_current_user_id() );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Copy your new key now. It can’t be shown again:', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' <code style="user-select:all">' . esc_html( (string) $new ) . '</code></p></div>';
		}
		$post = static function ( $action, $fields ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="sidccofo_api"><input type="hidden" name="do" value="' . esc_attr( $action ) . '">';
			wp_nonce_field( 'sidccofo_api' );
			echo $fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
			echo '</form>';
		};
		echo '<h2>' . esc_html__( 'API keys', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h2><table class="widefat striped" style="max-width:800px"><thead><tr><th>' . esc_html__( 'Name', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Key', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Access', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( (array) get_option( self::KEYS, array() ) as $k ) {
			echo '<tr><td>' . esc_html( $k['name'] ) . '</td><td><code>' . esc_html( $k['prefix'] ) . '…</code></td><td>' . esc_html( $k['perm'] ) . '</td><td>';
			$post( 'revoke', '<input type="hidden" name="id" value="' . esc_attr( $k['id'] ) . '"><button class="button">' . esc_html__( 'Revoke', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button>' );
			echo '</td></tr>';
		}
		echo '</tbody></table><p>';
		$post( 'newkey', '<input name="name" placeholder="' . esc_attr__( 'Name (for example Zapier)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '" required> <select name="perm"><option value="read">' . esc_html__( 'Read only', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</option><option value="write">' . esc_html__( 'Read and write', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</option></select> <button class="button button-primary">' . esc_html__( 'Create key', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button>' );
		echo '</p><h2>' . esc_html__( 'Webhooks', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Name / URL', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Topics', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Last delivery', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th></th></tr></thead><tbody>';
		$log = (array) get_option( 'sidcraft_commerce_webhook_log', array() );
		foreach ( (array) get_option( self::HOOKS, array() ) as $h ) {
			$l = $log[ $h['id'] ] ?? null;
			echo '<tr><td><strong>' . esc_html( $h['name'] ) . '</strong><br><small>' . esc_html( $h['url'] ) . '</small></td><td>' . esc_html( implode( ', ', (array) $h['topics'] ) ) . '</td><td>' . ( $l ? esc_html( wp_date( 'Y-m-d H:i', (int) $l['time'] ) . ' · ' . $l['topic'] . ' · ' . $l['status'] ) : '—' ) . '</td><td>';
			$post( 'delhook', '<input type="hidden" name="id" value="' . esc_attr( $h['id'] ) . '"><button class="button">' . esc_html__( 'Delete', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button>' );
			echo '</td></tr>';
		}
		echo '</tbody></table><h3>' . esc_html__( 'Add a webhook', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h3>';
		$boxes = '';
		foreach ( self::topics() as $t ) {
			$boxes .= '<label style="margin-right:10px"><input type="checkbox" name="topics[]" value="' . esc_attr( $t ) . '"> ' . esc_html( $t ) . '</label>';
		}
		$post( 'addhook', '<p><input name="name" placeholder="' . esc_attr__( 'Name', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '" required> <input type="url" name="url" placeholder="https://hooks.example.com/…" size="45" required></p><p>' . $boxes . '</p><p><button class="button button-primary">' . esc_html__( 'Add webhook', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></p>' );
		echo '<p class="description">' . esc_html__( 'Every delivery is signed: header X-Sidcraft-Signature = HMAC-SHA256 (hex) of the request body with the webhook’s secret. Only public https/http addresses are called.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p></div>';
	}

	/**
	 * Admin actions.
	 */
	public function handle_admin() {
		if ( ! current_user_can( manage_cap() ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'sidccofo_api' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$keys = (array) get_option( self::KEYS, array() );
		$hooks = (array) get_option( self::HOOKS, array() );
		$id   = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		if ( 'newkey' === $do ) {
			$token  = 'sck_' . wp_generate_password( 40, false );
			$keys[] = array(
				'id'     => wp_generate_password( 10, false ),
				'name'   => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : 'API',
				'perm'   => isset( $_POST['perm'] ) && 'write' === $_POST['perm'] ? 'write' : 'read',
				'hash'   => hash( 'sha256', $token ),
				'prefix' => substr( $token, 0, 10 ),
			);
			update_option( self::KEYS, $keys, false );
			set_transient( 'sidccofo_new_key_' . get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS );
		} elseif ( 'revoke' === $do ) {
			update_option( self::KEYS, array_values( array_filter( $keys, static function ( $k ) use ( $id ) { return $k['id'] !== $id; } ) ), false );
		} elseif ( 'addhook' === $do ) {
			$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'http', 'https' ) ) : '';
			$topics = isset( $_POST['topics'] ) ? array_values( array_intersect( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['topics'] ) ), self::topics() ) ) : array();
			if ( '' !== $url && wp_http_validate_url( $url ) && $topics ) {
				$hooks[] = array(
					'id'     => wp_generate_password( 10, false ),
					'name'   => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : 'Webhook',
					'url'    => $url,
					'topics' => $topics,
					'secret' => wp_generate_password( 32, false ),
					'on'     => 1,
				);
				update_option( self::HOOKS, $hooks, false );
			}
		} elseif ( 'delhook' === $do ) {
			update_option( self::HOOKS, array_values( array_filter( $hooks, static function ( $h ) use ( $id ) { return $h['id'] !== $id; } ) ), false );
		}
		wp_safe_redirect( $this->page_url( 'sidccofo-api' ) );
		exit;
	}
}
