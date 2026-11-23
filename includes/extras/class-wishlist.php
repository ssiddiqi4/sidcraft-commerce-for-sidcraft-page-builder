<?php
/**
 * Wishlist, compare and recently viewed.
 *
 * Wishlist: a heart on product pages; kept in the account of a logged-in
 * shopper, in a cookie for a guest (and merged into the account at login).
 * Compare: up to four products side by side. Recently viewed: the last
 * products a visitor looked at (kept in the visitor's browser).
 *
 * Pages stay cacheable: every list is filled in by script from small REST
 * calls, so the page HTML is the same for everybody.
 *
 * Units / shortcodes: [sidccofo_wishlist], [sidccofo_compare],
 * [sidccofo_recently_viewed], [sidccofo_wishlist_button], [sidccofo_compare_button].
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use SidcraftCommerce\Plugin;
use function SidcraftCommerce\cookie_name;
use function SidcraftCommerce\format_price;
use function SidcraftCommerce\resolve_product;

defined( 'ABSPATH' ) || exit;

/**
 * Class Wishlist
 */
class Wishlist extends Tool {

	const WL  = 'sidccofo_wl';
	const CMP = 'sidccofo_cmp';

	/** {@inheritDoc} */
	public function hooks() {
		$d     = 'sidcraft-commerce-for-sidcraft-page-builder';
		$prod  = array( 'type' => 'product', 'label' => __( 'Product', $d ), 'section' => 'content', 'default' => '' );
		$limit = array( 'type' => 'number', 'label' => __( 'How many', $d ), 'section' => 'content', 'default' => 8, 'min' => 1, 'max' => 24 );
		Simple_Unit::add( 'sidccofo-wishlist', __( 'Wishlist', $d ), array(), array( $this, 'render_wishlist' ), 'heart' );
		Simple_Unit::add( 'sidccofo-compare', __( 'Compare Products', $d ), array(), array( $this, 'render_compare' ), 'columns' );
		Simple_Unit::add( 'sidccofo-recently-viewed', __( 'Recently Viewed', $d ), array( 'limit' => $limit ), array( $this, 'render_recent' ), 'clock' );
		Simple_Unit::add( 'sidccofo-wishlist-button', __( 'Wishlist Button', $d ), array( 'product_id' => $prod ), array( $this, 'render_heart' ), 'heart' );
		Simple_Unit::add( 'sidccofo-compare-button', __( 'Compare Button', $d ), array( 'product_id' => $prod ), array( $this, 'render_compare_button' ), 'columns' );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'wp_login', array( $this, 'merge_on_login' ), 10, 2 );
		add_action( 'sidcraft_commerce_after_add_to_cart_button', array( $this, 'buttons_in_form' ), 20, 1 );
		add_action( 'wp_footer', array( $this, 'track_view' ) );
	}

	/*
	------------------------------------------------------------------
	 * Storage
	 * ----------------------------------------------------------------
	 */

	/**
	 * Ids in a cookie.
	 *
	 * @param string $name Cookie base name.
	 * @return int[]
	 */
	protected function from_cookie( $name ) {
		$raw = isset( $_COOKIE[ cookie_name( $name ) ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ cookie_name( $name ) ] ) ) : '';
		return array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );
	}

	/**
	 * Save ids in a cookie.
	 *
	 * @param string $name Cookie base name.
	 * @param int[]  $ids  Ids.
	 */
	protected function to_cookie( $name, array $ids ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie( cookie_name( $name ), implode( ',', array_map( 'absint', $ids ) ), time() + 60 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
		$_COOKIE[ cookie_name( $name ) ] = implode( ',', $ids );
	}

	/**
	 * Wishlist ids of this shopper.
	 *
	 * @return int[]
	 */
	protected function wishlist() {
		if ( is_user_logged_in() ) {
			return array_values( array_unique( array_filter( array_map( 'absint', (array) get_user_meta( get_current_user_id(), 'sidccofo_wishlist', true ) ) ) ) );
		}
		return $this->from_cookie( self::WL );
	}

	/**
	 * Save the wishlist.
	 *
	 * @param int[] $ids Ids.
	 */
	protected function save_wishlist( array $ids ) {
		$ids = array_slice( array_values( array_unique( $ids ) ), 0, 200 );
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), 'sidccofo_wishlist', $ids );
		} else {
			$this->to_cookie( self::WL, array_slice( $ids, 0, 60 ) );
		}
	}

	/**
	 * A guest's wishlist joins the account at login.
	 *
	 * @param string   $login Login.
	 * @param \WP_User $user  User.
	 */
	public function merge_on_login( $login, $user ) {
		unset( $login );
		$guest = $this->from_cookie( self::WL );
		if ( ! $guest ) {
			return;
		}
		$have = array_map( 'absint', (array) get_user_meta( $user->ID, 'sidccofo_wishlist', true ) );
		update_user_meta( $user->ID, 'sidccofo_wishlist', array_values( array_unique( array_merge( $have, $guest ) ) ) );
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
		$ns = 'sidcraft-commerce/v1';
		$pc = array(
			'permission_callback' => '__return_true',
		);
		register_rest_route( $ns, '/wishlist', array_merge( $pc, array( 'methods' => 'GET', 'callback' => array( $this, 'get_lists' ) ) ) );
		register_rest_route( $ns, '/wishlist/toggle', array_merge( $pc, array( 'methods' => 'POST', 'callback' => array( $this, 'toggle_wishlist' ) ) ) );
		register_rest_route( $ns, '/compare/toggle', array_merge( $pc, array( 'methods' => 'POST', 'callback' => array( $this, 'toggle_compare' ) ) ) );
		register_rest_route( $ns, '/compare/table', array_merge( $pc, array( 'methods' => 'GET', 'callback' => array( $this, 'compare_table' ) ) ) );
		register_rest_route( $ns, '/cards', array_merge( $pc, array( 'methods' => 'GET', 'callback' => array( $this, 'cards' ) ) ) );
	}

	/**
	 * Current lists.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_lists() {
		return rest_ensure_response(
			array(
				'wishlist' => $this->wishlist(),
				'compare'  => $this->from_cookie( self::CMP ),
			)
		);
	}

	/**
	 * Add or remove a product.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_wishlist( \WP_REST_Request $request ) {
		$id = absint( $request['product_id'] );
		if ( ! Product::get( $id ) ) {
			return new \WP_Error( 'sidccofo_bad_product', __( 'Unknown product.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
		}
		$ids = $this->wishlist();
		$on  = ! in_array( $id, $ids, true );
		$ids = $on ? array_merge( $ids, array( $id ) ) : array_values( array_diff( $ids, array( $id ) ) );
		$this->save_wishlist( $ids );
		return rest_ensure_response( array( 'on' => $on, 'wishlist' => $ids ) );
	}

	/**
	 * Add or remove a product from the compare list (four at most).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_compare( \WP_REST_Request $request ) {
		$id = absint( $request['product_id'] );
		if ( ! Product::get( $id ) ) {
			return new \WP_Error( 'sidccofo_bad_product', __( 'Unknown product.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
		}
		$ids = $this->from_cookie( self::CMP );
		$on  = ! in_array( $id, $ids, true );
		if ( $on && count( $ids ) >= 4 ) {
			return new \WP_Error( 'sidccofo_compare_full', __( 'You can compare up to four products. Remove one first.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		$ids = $on ? array_merge( $ids, array( $id ) ) : array_values( array_diff( $ids, array( $id ) ) );
		$this->to_cookie( self::CMP, $ids );
		return rest_ensure_response( array( 'on' => $on, 'compare' => $ids ) );
	}

	/**
	 * Product cards for a list of ids.
	 *
	 * @param \WP_REST_Request $request Request (?ids=1,2,3 or ?list=wishlist).
	 * @return \WP_REST_Response
	 */
	public function cards( \WP_REST_Request $request ) {
		$list = (string) $request['list'];
		if ( 'wishlist' === $list ) {
			$ids = $this->wishlist();
		} else {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $request['ids'] ) ) ) ) );
		}
		$ids  = array_slice( $ids, 0, 24 );
		$html = '';
		foreach ( $ids as $id ) {
			$p = Product::get( $id );
			if ( ! $p || ! $p->is_published() ) {
				continue;
			}
			$html .= '<li class="sc-grid__item">' . Plugin::instance()->templates->render(
				'loop/product-card.php',
				array(
					'product'  => $p,
					'settings' => array(
						'show_add_to_cart' => true,
						'show_category'    => true,
						'show_quick_view'  => false,
						'show_rating'      => true,
					),
				)
			) . '</li>';
		}
		return rest_ensure_response( array( 'html' => $html, 'count' => substr_count( $html, 'sc-grid__item' ) ) );
	}

	/**
	 * The comparison table.
	 *
	 * @return \WP_REST_Response
	 */
	public function compare_table() {
		$products = array();
		foreach ( $this->from_cookie( self::CMP ) as $id ) {
			$p = Product::get( $id );
			if ( $p && $p->is_published() ) {
				$products[] = $p;
			}
		}
		$d = 'sidcraft-commerce-for-sidcraft-page-builder';
		if ( ! $products ) {
			return rest_ensure_response( array( 'html' => '<p>' . esc_html__( 'Add products to compare from their pages.', $d ) . '</p>' ) );
		}
		$rows = array(
			__( 'Price', $d )        => array(),
			__( 'Rating', $d )       => array(),
			__( 'Availability', $d ) => array(),
			__( 'SKU', $d )          => array(),
		);
		$specs = array();
		foreach ( $products as $i => $p ) {
			$rows[ __( 'Price', $d ) ][ $i ]        = wp_kses_post( $p->get_price_html() );
			$rows[ __( 'Rating', $d ) ][ $i ]       = $p->get_review_count() ? esc_html( number_format_i18n( $p->get_average_rating(), 1 ) . ' (' . $p->get_review_count() . ')' ) : '—';
			$rows[ __( 'Availability', $d ) ][ $i ] = esc_html( $p->is_in_stock() ? __( 'In stock', $d ) : __( 'Out of stock', $d ) );
			$rows[ __( 'SKU', $d ) ][ $i ]          = esc_html( $p->get_sku() ? $p->get_sku() : '—' );
			foreach ( $p->get_specs() as $spec ) {
				$specs[ $spec['label'] ][ $i ] = esc_html( (string) $spec['value'] );
			}
		}
		$rows = array_merge( $rows, $specs );
		$html = '<div class="sc-compare__scroll"><table class="sc-compare__table"><thead><tr><th></th>';
		foreach ( $products as $p ) {
			$html .= '<th scope="col"><a href="' . esc_url( $p->get_permalink() ) . '">' . $p->get_image( 'thumbnail' ) . '<span>' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . '</span></a><button type="button" class="sc-link" data-sc-compare-remove="' . esc_attr( $p->get_id() ) . '">' . esc_html__( 'Remove', $d ) . '</button></th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $label => $cells ) {
			$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th>';
			foreach ( array_keys( $products ) as $i ) {
				$html .= '<td>' . ( $cells[ $i ] ?? '—' ) . '</td>';
			}
			$html .= '</tr>';
		}
		return rest_ensure_response( array( 'html' => $html . '</tbody></table></div>' ) );
	}

	/*
	------------------------------------------------------------------
	 * Rendering
	 * ----------------------------------------------------------------
	 */

	/**
	 * Wishlist list container.
	 *
	 * @return string
	 */
	public function render_wishlist() {
		Extras::enqueue();
		return '<div class="sc-list" data-sc-list="wishlist"><ul class="sc-grid sc-grid--cols-4" data-sc-list-items></ul><p class="sc-list__empty" hidden>' . esc_html__( 'Your wishlist is empty. Tap the heart on a product to save it here.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p></div>';
	}

	/**
	 * Compare table container.
	 *
	 * @return string
	 */
	public function render_compare() {
		Extras::enqueue();
		return '<div class="sc-compare" data-sc-compare-table aria-live="polite"></div>';
	}

	/**
	 * Recently viewed container.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public function render_recent( $s ) {
		Extras::enqueue();
		return '<div class="sc-list" data-sc-list="recent" data-limit="' . (int) $s['limit'] . '"><h3 class="sc-list__title">' . esc_html__( 'Recently viewed', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h3><ul class="sc-grid sc-grid--cols-4" data-sc-list-items></ul></div>';
	}

	/**
	 * Heart button.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public function render_heart( $s ) {
		$p = resolve_product( $s );
		if ( ! $p ) {
			return '';
		}
		Extras::enqueue();
		return '<button type="button" class="sc-heart" data-sc-wish="' . esc_attr( $p->get_id() ) . '" aria-pressed="false"><span aria-hidden="true">♡</span> <span class="sc-heart__label">' . esc_html__( 'Add to wishlist', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></button>';
	}

	/**
	 * Compare button.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	public function render_compare_button( $s ) {
		$p = resolve_product( $s );
		if ( ! $p ) {
			return '';
		}
		Extras::enqueue();
		return '<button type="button" class="sc-heart sc-compare-btn" data-sc-cmp="' . esc_attr( $p->get_id() ) . '" aria-pressed="false"><span aria-hidden="true">⇄</span> <span class="sc-heart__label">' . esc_html__( 'Compare', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></button>';
	}

	/**
	 * Heart and compare buttons next to Add to cart.
	 *
	 * @param Product $product Product.
	 */
	public function buttons_in_form( $product ) {
		if ( ! $product instanceof Product ) {
			return;
		}
		Extras::enqueue();
		echo '<span class="sc-atc__extras"><button type="button" class="sc-heart" data-sc-wish="' . esc_attr( $product->get_id() ) . '" aria-pressed="false"><span aria-hidden="true">♡</span> <span class="sc-heart__label">' . esc_html__( 'Wishlist', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></button> <button type="button" class="sc-heart" data-sc-cmp="' . esc_attr( $product->get_id() ) . '" aria-pressed="false"><span aria-hidden="true">⇄</span> <span class="sc-heart__label">' . esc_html__( 'Compare', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></button></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Tell the script which product is being viewed (cache-safe).
	 */
	public function track_view() {
		if ( is_singular( Post_Types::PRODUCT ) ) {
			echo '<span hidden data-sc-track-product="' . esc_attr( get_the_ID() ) . '"></span>';
			Extras::enqueue();
		}
	}
}
