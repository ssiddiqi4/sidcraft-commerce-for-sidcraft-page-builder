<?php
/**
 * Software licence keys.
 *
 * Tick "Licence key" on a product (Store options → Licence). When an order
 * with that product is paid, a key like ABCD-1234-EFGH-5678 is made for each
 * unit, shown on the order-received page, in the order email and on the
 * customer's My licences page ([sidccofo_licenses]). A key has an activation
 * limit and an optional validity in days. Refunds and cancellations revoke it.
 *
 * Your software checks a key with the REST API:
 *   POST /wp-json/sidcraft-commerce/v1/licenses/activate    key, site
 *   POST /wp-json/sidcraft-commerce/v1/licenses/validate    key, site (optional)
 *   POST /wp-json/sidcraft-commerce/v1/licenses/deactivate  key, site
 * Answers: { valid, status, product, expires, activations_left }.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\rate_limit;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Licenses
 */
class Licenses extends Tool {

	const TYPE = 'sidccofo_license';

	/** {@inheritDoc} */
	public function hooks() {
		$this->settings(
			'licenses',
			__( 'Licence keys', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'lic_prefix' => array( __( 'Key prefix', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Optional, for example PRO. Keys then look like PRO-ABCD-1234-EFGH.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			)
		);
		add_action( 'init', array( $this, 'register_type' ) );
		add_action( 'sidcraft_commerce_order_status_changed', array( $this, 'on_status' ), 10, 3 );
		add_action( 'add_meta_boxes_' . Post_Types::PRODUCT, array( $this, 'product_box' ) );
		add_action( 'save_post_' . Post_Types::PRODUCT, array( $this, 'save_product' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'sidcraft_commerce_render_sidccofo-order-confirmation', array( $this, 'on_received_page' ), 20, 2 );
		add_filter( 'sidcraft_commerce_email', array( $this, 'in_email' ), 18, 3 );
		add_shortcode( 'sidccofo_licenses', array( $this, 'shortcode' ) );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
	}

	/**
	 * Keys list.
	 */
	public function register_type() {
		register_post_type(
			self::TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Licences', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name' => __( 'Licence', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . Post_Types::PRODUCT,
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title' ),
			)
		);
	}

	/**
	 * Is a product sold with licence keys?
	 *
	 * @param int $id Product.
	 * @return bool
	 */
	protected static function sells_key( $id ) {
		return '1' === (string) get_post_meta( $id, '_sidccofo_license', true );
	}

	/**
	 * Make a random key.
	 *
	 * @return string
	 */
	protected static function new_key() {
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		do {
			$parts = array();
			for ( $i = 0; $i < 4; $i++ ) {
				$p = '';
				for ( $j = 0; $j < 4; $j++ ) {
					$p .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
				}
				$parts[] = $p;
			}
			$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) setting( 'lic_prefix', '' ) ) );
			$key    = ( '' !== $prefix ? $prefix . '-' : '' ) . implode( '-', $parts );
		} while ( self::find( $key ) );
		return $key;
	}

	/**
	 * Find a licence post by key.
	 *
	 * @param string $key Key.
	 * @return \WP_Post|null
	 */
	public static function find( $key ) {
		$found = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'title'          => strtoupper( trim( $key ) ),
				'no_found_rows'  => true,
			)
		);
		return $found ? $found[0] : null;
	}

	/**
	 * Create keys when an order is paid; revoke when it isn't any more.
	 *
	 * @param Order  $order Order.
	 * @param string $old   Old status.
	 * @param string $new   New status.
	 */
	public function on_status( $order, $old, $new ) {
		unset( $old );
		if ( in_array( $new, Post_Types::paid_statuses(), true ) ) {
			$this->issue( $order );
		} elseif ( in_array( $new, array( 'sidccofo-cancelled', 'sidccofo-refunded' ), true ) ) {
			foreach ( $this->for_order( $order->get_id() ) as $post ) {
				update_post_meta( $post->ID, '_sidccofo_lic_status', 'revoked' );
			}
		}
	}

	/**
	 * Make the keys of an order (once).
	 *
	 * @param Order $order Order.
	 */
	protected function issue( Order $order ) {
		if ( get_post_meta( $order->get_id(), '_sidccofo_licenses_issued', true ) ) {
			foreach ( $this->for_order( $order->get_id() ) as $post ) {
				if ( 'revoked' === get_post_meta( $post->ID, '_sidccofo_lic_status', true ) ) {
					update_post_meta( $post->ID, '_sidccofo_lic_status', 'active' ); // Order reopened.
				}
			}
			return;
		}
		$made = false;
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item['product_id'];
			if ( ! self::sells_key( $pid ) ) {
				continue;
			}
			$days  = (int) get_post_meta( $pid, '_sidccofo_license_days', true );
			$limit = max( 1, (int) get_post_meta( $pid, '_sidccofo_license_sites', true ) );
			for ( $i = 0; $i < max( 1, (int) $item['quantity'] ); $i++ ) {
				$key = self::new_key();
				$id  = wp_insert_post(
					array(
						'post_type'   => self::TYPE,
						'post_status' => 'publish',
						'post_title'  => $key,
					)
				);
				if ( is_wp_error( $id ) || ! $id ) {
					continue;
				}
				update_post_meta( $id, '_sidccofo_lic_order', $order->get_id() );
				update_post_meta( $id, '_sidccofo_lic_product', $pid );
				update_post_meta( $id, '_sidccofo_lic_email', $order->get_email() );
				update_post_meta( $id, '_sidccofo_lic_user', (int) get_post_meta( $order->get_id(), '_sidccofo_customer_id', true ) );
				update_post_meta( $id, '_sidccofo_lic_limit', $limit );
				update_post_meta( $id, '_sidccofo_lic_status', 'active' );
				update_post_meta( $id, '_sidccofo_lic_expires', $days > 0 ? time() + $days * DAY_IN_SECONDS : 0 );
				update_post_meta( $id, '_sidccofo_lic_sites', array() );
				$made = true;
			}
		}
		update_post_meta( $order->get_id(), '_sidccofo_licenses_issued', 1 );
		if ( $made ) {
			$order->add_note( __( 'Licence keys were issued for this order.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
	}

	/**
	 * Licences of an order.
	 *
	 * @param int $order_id Order.
	 * @return \WP_Post[]
	 */
	protected function for_order( $order_id ) {
		return get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'meta_key'       => '_sidccofo_lic_order', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $order_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	/**
	 * State of a licence.
	 *
	 * @param int $id Licence post id.
	 * @return string active|expired|revoked
	 */
	protected static function state( $id ) {
		$status = (string) get_post_meta( $id, '_sidccofo_lic_status', true );
		if ( 'active' !== $status ) {
			return 'revoked';
		}
		$exp = (int) get_post_meta( $id, '_sidccofo_lic_expires', true );
		return $exp > 0 && $exp < time() ? 'expired' : 'active';
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
		foreach ( array( 'validate', 'activate', 'deactivate' ) as $action ) {
			register_rest_route(
				'sidcraft-commerce/v1',
				'/licenses/' . $action,
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => function ( \WP_REST_Request $request ) use ( $action ) {
						return $this->handle( $action, $request );
					},
				)
			);
		}
	}

	/**
	 * Answer an API call.
	 *
	 * @param string           $action  validate|activate|deactivate.
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function handle( $action, \WP_REST_Request $request ) {
		if ( ! rate_limit( 'license', 60, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'sidccofo_rate', __( 'Too many requests.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
		}
		$post = self::find( sanitize_text_field( (string) $request['key'] ) );
		$site = strtolower( untrailingslashit( esc_url_raw( (string) $request['site'] ) ) );
		if ( ! $post ) {
			return rest_ensure_response( array( 'valid' => false, 'status' => 'unknown' ) );
		}
		$state = self::state( $post->ID );
		$sites = array_values( array_filter( (array) get_post_meta( $post->ID, '_sidccofo_lic_sites', true ) ) );
		$limit = (int) get_post_meta( $post->ID, '_sidccofo_lic_limit', true );
		if ( 'activate' === $action && 'active' === $state && '' !== $site ) {
			if ( ! in_array( $site, $sites, true ) ) {
				if ( count( $sites ) >= $limit ) {
					return rest_ensure_response( array( 'valid' => false, 'status' => 'limit_reached', 'activations_left' => 0 ) );
				}
				$sites[] = $site;
				update_post_meta( $post->ID, '_sidccofo_lic_sites', $sites );
			}
		} elseif ( 'deactivate' === $action && '' !== $site ) {
			$sites = array_values( array_diff( $sites, array( $site ) ) );
			update_post_meta( $post->ID, '_sidccofo_lic_sites', $sites );
		}
		$valid = 'active' === $state && ( '' === $site || 'deactivate' === $action || in_array( $site, $sites, true ) || 'validate' === $action && count( $sites ) < $limit && '' === $site );
		if ( 'validate' === $action && '' !== $site ) {
			$valid = 'active' === $state && in_array( $site, $sites, true );
		}
		$exp = (int) get_post_meta( $post->ID, '_sidccofo_lic_expires', true );
		return rest_ensure_response(
			array(
				'valid'            => $valid,
				'status'           => 'deactivate' === $action ? 'deactivated' : $state,
				'product'          => wp_strip_all_tags( get_the_title( (int) get_post_meta( $post->ID, '_sidccofo_lic_product', true ) ) ),
				'expires'          => $exp > 0 ? gmdate( 'c', $exp ) : null,
				'activations_left' => max( 0, $limit - count( $sites ) ),
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * Showing keys
	 * ----------------------------------------------------------------
	 */

	/**
	 * Keys as a small HTML block.
	 *
	 * @param \WP_Post[] $posts Licences.
	 * @return string
	 */
	protected function block( array $posts ) {
		if ( ! $posts ) {
			return '';
		}
		$h = '<div class="sc-licenses"><h3>' . esc_html__( 'Your licence keys', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h3><ul>';
		foreach ( $posts as $p ) {
			$exp = (int) get_post_meta( $p->ID, '_sidccofo_lic_expires', true );
			$h  .= '<li><code>' . esc_html( $p->post_title ) . '</code> · ' . esc_html( wp_strip_all_tags( get_the_title( (int) get_post_meta( $p->ID, '_sidccofo_lic_product', true ) ) ) ) . ' · ' . esc_html( ucfirst( self::state( $p->ID ) ) ) . ( $exp > 0 ? ' · ' . esc_html( sprintf( /* translators: %s: date. */ __( 'valid until %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_date( get_option( 'date_format' ), $exp ) ) ) : '' ) . '</li>';
		}
		return $h . '</ul></div>';
	}

	/**
	 * Keys on the order-received page.
	 *
	 * @param string $html     Unit HTML.
	 * @param array  $settings Settings.
	 * @return string
	 */
	public function on_received_page( $html, $settings ) {
		unset( $settings );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Access by the order key.
		$id  = isset( $_GET['sidccofo_order'] ) ? absint( $_GET['sidccofo_order'] ) : 0;
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		$order = $id ? Order::get( $id ) : null;
		if ( ! $order || ! $order->key_is_valid( $key ) ) {
			return $html;
		}
		$box = $this->block( $this->for_order( $id ) );
		return '' === $box ? $html : preg_replace( '#</div>\s*$#', $box . '</div>', $html, 1 );
	}

	/**
	 * Keys in the customer's order email.
	 *
	 * @param array  $mail      Mail.
	 * @param Order  $order     Order.
	 * @param string $recipient Recipient.
	 * @return array
	 */
	public function in_email( $mail, $order, $recipient ) {
		if ( ! $mail || 'customer' !== $recipient || ! $order instanceof Order || ! isset( $mail[2] ) || ! in_array( $order->get_status(), Post_Types::paid_statuses(), true ) ) {
			return $mail;
		}
		$this->issue( $order );
		$box = $this->block( $this->for_order( $order->get_id() ) );
		if ( '' === $box ) {
			return $mail;
		}
		$mail[2] = false !== stripos( (string) $mail[2], '</body>' ) ? preg_replace( '#</body>#i', $box . '</body>', (string) $mail[2], 1 ) : $mail[2] . $box;
		return $mail;
	}

	/**
	 * [sidccofo_licenses]: the logged-in customer's keys.
	 *
	 * @return string
	 */
	public function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Log in to see your licence keys.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		}
		if ( function_exists( 'wp_cache_set_last_changed' ) ) {
			\SidcraftCommerce\Extras\Extras::enqueue();
		}
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array( 'key' => '_sidccofo_lic_user', 'value' => get_current_user_id() ),
					array( 'key' => '_sidccofo_lic_email', 'value' => wp_get_current_user()->user_email ),
				),
			)
		);
		$box = $this->block( $posts );
		return '' !== $box ? $box : '<p>' . esc_html__( 'You have no licence keys yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
	}

	/*
	------------------------------------------------------------------
	 * Admin
	 * ----------------------------------------------------------------
	 */

	/**
	 * Product box.
	 */
	public function product_box() {
		add_meta_box( 'sidccofo-license', __( 'Licence key', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_product_box' ), Post_Types::PRODUCT, 'side' );
	}

	/**
	 * Box contents.
	 *
	 * @param \WP_Post $post Product.
	 */
	public function render_product_box( $post ) {
		wp_nonce_field( 'sidccofo_license', '_sidccofo_lic' );
		echo '<p><label><input type="checkbox" name="sidccofo_license" value="1" ' . checked( self::sells_key( $post->ID ), true, false ) . '> ' . esc_html__( 'Issue a licence key per unit sold', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Sites per key', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '<br><input type="number" min="1" class="widefat" name="sidccofo_license_sites" value="' . esc_attr( (string) max( 1, (int) get_post_meta( $post->ID, '_sidccofo_license_sites', true ) ) ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Valid for (days, 0 = forever)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '<br><input type="number" min="0" class="widefat" name="sidccofo_license_days" value="' . esc_attr( (string) (int) get_post_meta( $post->ID, '_sidccofo_license_days', true ) ) . '"></label></p>';
	}

	/**
	 * Save.
	 *
	 * @param int $post_id Product.
	 */
	public function save_product( $post_id ) {
		if ( ! isset( $_POST['_sidccofo_lic'] ) || ! current_user_can( 'edit_post', $post_id ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_lic'] ) ), 'sidccofo_license' ) ) {
			return;
		}
		update_post_meta( $post_id, '_sidccofo_license', empty( $_POST['sidccofo_license'] ) ? '' : '1' );
		update_post_meta( $post_id, '_sidccofo_license_sites', isset( $_POST['sidccofo_license_sites'] ) ? max( 1, absint( $_POST['sidccofo_license_sites'] ) ) : 1 );
		update_post_meta( $post_id, '_sidccofo_license_days', isset( $_POST['sidccofo_license_days'] ) ? absint( $_POST['sidccofo_license_days'] ) : 0 );
	}

	/**
	 * List columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function columns( $cols ) {
		return array(
			'cb'      => $cols['cb'] ?? '',
			'title'   => __( 'Key', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'product' => __( 'Product', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'status'  => __( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sites'   => __( 'Sites', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'email'   => __( 'Customer', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Column content.
	 *
	 * @param string $col Column.
	 * @param int    $id  Post.
	 */
	public function column( $col, $id ) {
		switch ( $col ) {
			case 'product':
				echo esc_html( wp_strip_all_tags( get_the_title( (int) get_post_meta( $id, '_sidccofo_lic_product', true ) ) ) );
				break;
			case 'status':
				echo esc_html( ucfirst( self::state( $id ) ) );
				break;
			case 'sites':
				echo esc_html( count( (array) get_post_meta( $id, '_sidccofo_lic_sites', true ) ) . ' / ' . (int) get_post_meta( $id, '_sidccofo_lic_limit', true ) );
				break;
			case 'email':
				echo esc_html( (string) get_post_meta( $id, '_sidccofo_lic_email', true ) );
				break;
		}
	}
}
