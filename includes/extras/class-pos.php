<?php
/**
 * Point of sale: a fast till in the admin for in-person sales.
 *
 * Store → Point of sale. Type or scan a SKU (a barcode scanner types it and
 * presses Enter) or search by name, adjust quantities, pick Cash, Card (on
 * your own terminal) or Other, and the order is created, paid and completed
 * in one step, taking stock and counting in reports like any other order.
 * A receipt prints in a pop-up. Tax follows your store settings for the
 * store's default country.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Currencies;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use SidcraftCommerce\Tax;
use function SidcraftCommerce\currency;
use function SidcraftCommerce\manage_cap;
use function SidcraftCommerce\round_amount;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Pos
 */
class Pos extends Tool {

	/** {@inheritDoc} */
	public function hooks() {
		$this->admin_page( 'sidccofo-pos', __( 'Point of sale', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'page' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Routes (store managers only, with the admin's REST nonce).
	 */
	public function routes() {
		$cap = static function () {
			return current_user_can( manage_cap() );
		};
		register_rest_route( 'sidcraft-commerce/v1', '/pos/products', array( 'methods' => 'GET', 'permission_callback' => $cap, 'callback' => array( $this, 'products' ) ) );
		register_rest_route( 'sidcraft-commerce/v1', '/pos/order', array( 'methods' => 'POST', 'permission_callback' => $cap, 'callback' => array( $this, 'order' ) ) );
	}

	/**
	 * Search by name or SKU (an exact SKU first).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function products( \WP_REST_Request $r ) {
		$q   = trim( sanitize_text_field( (string) $r['q'] ) );
		$ids = array();
		if ( '' !== $q ) {
			$ids = array_merge(
				get_posts( array( 'post_type' => Post_Types::PRODUCT, 'post_status' => 'publish', 'posts_per_page' => 5, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => '_sidccofo_sku', 'value' => $q ) ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				get_posts( array( 'post_type' => Post_Types::PRODUCT, 'post_status' => 'publish', 'posts_per_page' => 20, 'fields' => 'ids', 'no_found_rows' => true, 's' => $q ) )
			);
		}
		$out = array();
		foreach ( array_unique( $ids ) as $id ) {
			$p = Product::get( (int) $id );
			if ( $p && $p->can_add_to_cart() && ! $p->has_options() && ! $p->is_gift_card() && ! $p->is_subscription() ) {
				$out[] = array( 'id' => (int) $id, 'name' => wp_strip_all_tags( $p->get_name() ), 'sku' => (string) $p->get_sku(), 'price' => (float) $p->get_price(), 'stock' => $p->get_stock_quantity() );
			}
		}
		return rest_ensure_response( array( 'products' => $out ) );
	}

	/**
	 * Make a paid, completed order from the till.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function order( \WP_REST_Request $r ) {
		$method = in_array( (string) $r['method'], array( 'cash', 'card', 'other' ), true ) ? (string) $r['method'] : 'cash';
		$items  = array();
		$lines  = array();
		$sub    = 0.0;
		foreach ( (array) $r['items'] as $row ) {
			$p   = Product::get( absint( $row['id'] ?? 0 ) );
			$qty = max( 1, min( 999, (int) ( $row['qty'] ?? 1 ) ) );
			if ( ! $p || ! $p->can_add_to_cart() || $p->has_options() ) {
				return new \WP_Error( 'sidccofo_pos', __( 'A product can’t be sold from the till.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
			}
			$price   = round_amount( (float) $p->get_price( $qty ) );
			$total   = round_amount( $price * $qty );
			$items[] = array( 'product_id' => $p->get_id(), 'name' => wp_strip_all_tags( $p->get_name() ), 'sku' => (string) $p->get_sku(), 'price' => $price, 'quantity' => $qty, 'total' => $total );
			$lines[] = $total;
			$sub    += $total;
		}
		if ( ! $items ) {
			return new \WP_Error( 'sidccofo_pos', __( 'Add something first.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		$country = strtoupper( (string) setting( 'default_country', 'US' ) );
		$tax     = Tax::calculate( $lines, 0.0, array( 'country' => $country, 'state' => '' ) );
		$total   = round_amount( $sub + ( ! empty( $tax['inclusive'] ) ? 0.0 : (float) $tax['total'] ) );
		$email   = sanitize_email( (string) $r['email'] );
		$order   = Order::create(
			array(
				'items'          => $items,
				'subtotal'       => $sub,
				'shipping'       => 0,
				'total'          => $total,
				'tax'            => (float) $tax['total'],
				'tax_label'      => (string) $tax['label'],
				'tax_rate'       => (float) $tax['rate'],
				'tax_inclusive'  => ! empty( $tax['inclusive'] ),
				'currency'       => currency(),
				'billing'        => array( 'first_name' => __( 'In-store', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'last_name' => __( 'customer', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'email' => is_email( $email ) ? $email : '', 'country' => $country ),
				'payment_method' => 'pos',
				'notes'          => sanitize_text_field( (string) $r['note'] ),
			)
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		update_post_meta( $order->get_id(), '_sidccofo_pos', $method );
		update_post_meta( $order->get_id(), '_sidccofo_hide_proof', '1' );
		/* translators: %s: payment type. */
		$order->add_note( sprintf( __( 'Sold at the till (%s).', 'sidcraft-commerce-for-sidcraft-page-builder' ), $method ) );
		$order->payment_complete( 'pos-' . $method );
		$order->set_status( 'sidccofo-completed', __( 'Handed over at the till.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		return rest_ensure_response(
			array(
				'order'    => $order->get_id(),
				'total'    => $total,
				'tax'      => (float) $tax['total'],
				'items'    => $items,
				'currency' => currency(),
				'symbol'   => \SidcraftCommerce\currency_symbol(),
				'method'   => $method,
				'date'     => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
				'shop'     => get_bloginfo( 'name' ),
			)
		);
	}

	/**
	 * Script on the till page only.
	 *
	 * @param string $hook Hook.
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'sidccofo-pos' ) ) {
			return;
		}
		wp_enqueue_script( 'sidccofo-pos', SIDCRAFT_COMMERCE_URL . 'assets/js/sc-pos.js', array(), SIDCRAFT_COMMERCE_VERSION, true );
		wp_add_inline_script( 'sidccofo-pos', 'window.sidccofoPos=' . wp_json_encode( array( 'api' => esc_url_raw( rest_url( 'sidcraft-commerce/v1/pos/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'symbol' => \SidcraftCommerce\currency_symbol() ) ) . ';', 'before' );
	}

	/**
	 * The till.
	 */
	public function page() {
		?>
		<div class="wrap sc-pos">
			<h1><?php esc_html_e( 'Point of sale', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h1>
			<div class="sc-pos__grid" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1100px">
				<div>
					<p><input type="search" id="sc-pos-q" class="large-text" placeholder="<?php esc_attr_e( 'Scan a barcode or type a name or SKU, then Enter', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" autofocus autocomplete="off"></p>
					<ul id="sc-pos-results" style="margin:0;padding:0;list-style:none"></ul>
				</div>
				<div>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Item', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th><th><?php esc_html_e( 'Qty', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th><th><?php esc_html_e( 'Total', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th><th></th></tr></thead><tbody id="sc-pos-cart"></tbody></table>
					<p style="font-size:20px"><strong><?php esc_html_e( 'Total', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>: <span id="sc-pos-total">0.00</span></strong> <small><?php esc_html_e( '(before tax if prices exclude it)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></small></p>
					<p><input type="email" id="sc-pos-email" placeholder="<?php esc_attr_e( 'Customer email (optional, for the order email)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" class="regular-text"></p>
					<p>
						<button class="button button-primary button-hero" data-method="cash"><?php esc_html_e( 'Cash', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
						<button class="button button-hero" data-method="card"><?php esc_html_e( 'Card (terminal)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
						<button class="button button-hero" data-method="other"><?php esc_html_e( 'Other', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
					</p>
					<p id="sc-pos-msg" role="status"></p>
				</div>
			</div>
		</div>
		<?php
	}
}
