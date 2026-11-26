<?php
/**
 * Social-proof popups: "Maria L. from Texas bought Blue Jacket · 2 hours ago",
 * built from real recent paid orders.
 *
 * Privacy: only the first name and the first letter of the last name are
 * shown, plus the country (or the city when you allow it). Orders can be
 * left out by ticking "Hide from popups" on the order. Nothing about a
 * visitor is collected by this feature.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Locations;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Social_Proof
 */
class Social_Proof extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$this->settings(
			'social',
			__( 'Social-proof popups', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'sp_days'     => array( __( 'Show orders from the last (days)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Default 7.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'sp_city'     => array( __( 'Show the city instead of the country', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
				'sp_every'    => array( __( 'Seconds between popups', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Default 12.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'sp_pages'    => array(
					__( 'Show on', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					array(
						'store' => __( 'Store pages (product, shop, cart)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'all'   => __( 'Every page', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					),
				),
			)
		);
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'wp_footer', array( $this, 'markup' ), 6 );
		add_action( 'sidcraft_commerce_order_status_changed', function () {
			delete_transient( 'sidccofo_proof' );
		} );
		add_action( 'add_meta_boxes_' . Post_Types::ORDER, function () {
			add_meta_box( 'sidccofo-proof', __( 'Popups', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'order_box' ), Post_Types::ORDER, 'side' );
		} );
		add_action( 'save_post_' . Post_Types::ORDER, array( $this, 'save_order_box' ) );
	}

	/**
	 * Order box.
	 *
	 * @param \WP_Post $post Order post.
	 */
	public function order_box( $post ) {
		wp_nonce_field( 'sidccofo_proof', '_sidccofo_proof' );
		echo '<label><input type="checkbox" name="sidccofo_hide_proof" value="1" ' . checked( (bool) get_post_meta( $post->ID, '_sidccofo_hide_proof', true ), true, false ) . '> ' . esc_html__( 'Hide from social-proof popups', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label>';
	}

	/**
	 * Save the box.
	 *
	 * @param int $post_id Order.
	 */
	public function save_order_box( $post_id ) {
		if ( ! isset( $_POST['_sidccofo_proof'] ) || ! current_user_can( 'edit_post', $post_id ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_proof'] ) ), 'sidccofo_proof' ) ) {
			return;
		}
		update_post_meta( $post_id, '_sidccofo_hide_proof', empty( $_POST['sidccofo_hide_proof'] ) ? '' : '1' );
		delete_transient( 'sidccofo_proof' );
	}

	/**
	 * Route.
	 */
	public function routes() {
		register_rest_route( 'sidcraft-commerce/v1', '/social/recent', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( $this, 'recent' ) ) );
	}

	/**
	 * Recent purchases, shortened for privacy.
	 *
	 * @return \WP_REST_Response
	 */
	public function recent() {
		$list = get_transient( 'sidccofo_proof' );
		if ( false === $list ) {
			$list = array();
			$days = max( 1, (int) setting( 'sp_days', 7 ) );
			$ids  = get_posts(
				array(
					'post_type'      => Post_Types::ORDER,
					'post_status'    => Post_Types::paid_statuses(),
					'posts_per_page' => 12,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'date_query'     => array( array( 'after' => $days . ' days ago' ) ),
					'meta_query'     => array( array( 'key' => '_sidccofo_hide_proof', 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			foreach ( $ids as $id ) {
				$o = Order::get( $id );
				if ( ! $o ) {
					continue;
				}
				$b     = $o->get_billing();
				$items = $o->get_items();
				if ( ! $items || '' === trim( (string) ( $b['first_name'] ?? '' ) ) ) {
					continue;
				}
				$first = $items[0];
				$place = setting( 'sp_city', 0 ) && ! empty( $b['city'] ) ? (string) $b['city'] : ( ! empty( $b['country'] ) ? Locations::country_name( $b['country'] ) : '' );
				$list[] = array(
					'name'    => ucfirst( mb_substr( trim( (string) $b['first_name'] ), 0, 24 ) ) . ( ! empty( $b['last_name'] ) ? ' ' . mb_strtoupper( mb_substr( trim( (string) $b['last_name'] ), 0, 1 ) ) . '.' : '' ),
					'place'   => $place,
					'product' => wp_strip_all_tags( (string) $first['name'] ),
					'url'     => (string) get_permalink( (int) $first['product_id'] ),
					'image'   => (string) get_the_post_thumbnail_url( (int) $first['product_id'], 'thumbnail' ),
					'ago'     => sprintf( /* translators: %s: time, e.g. "2 hours". */ __( '%s ago', 'sidcraft-commerce-for-sidcraft-page-builder' ), human_time_diff( get_post_time( 'U', true, $id ) ) ),
				);
			}
			set_transient( 'sidccofo_proof', $list, 5 * MINUTE_IN_SECONDS );
		}
		return rest_ensure_response( array( 'items' => $list ) );
	}

	/**
	 * The popup container, on the chosen pages.
	 */
	public function markup() {
		if ( is_admin() || is_page( \SidcraftCommerce\page_id( 'checkout' ) ) ) {
			return;
		}
		$store = wp_script_is( 'sidccofo-cart', 'enqueued' );
		if ( 'all' !== setting( 'sp_pages', 'store' ) && ! $store ) {
			return;
		}
		Extras::enqueue();
		echo '<div class="sc-proof" data-sc-proof data-every="' . esc_attr( (string) max( 5, (int) setting( 'sp_every', 12 ) ) ) . '" aria-live="polite" hidden></div>';
	}
}
