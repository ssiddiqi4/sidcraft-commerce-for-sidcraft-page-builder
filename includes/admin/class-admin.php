<?php
/**
 * Admin screens for products and orders. Loaded only when is_admin().
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Admin;

use SidcraftCommerce\Data\Coupon;
use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\currency_symbol;
use function SidcraftCommerce\format_price;
use function SidcraftCommerce\manage_cap;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'add_meta_boxes_' . Post_Types::PRODUCT, array( $this, 'product_box' ) );
		add_action( 'save_post_' . Post_Types::PRODUCT, array( $this, 'save_product' ), 10, 2 );
		add_filter( 'manage_' . Post_Types::PRODUCT . '_posts_columns', array( $this, 'product_columns' ) );
		add_action( 'manage_' . Post_Types::PRODUCT . '_posts_custom_column', array( $this, 'product_column' ), 10, 2 );

		add_action( 'add_meta_boxes_' . Post_Types::ORDER, array( $this, 'order_boxes' ) );
		add_action( 'save_post_' . Post_Types::ORDER, array( $this, 'save_order' ) );
		add_action( 'admin_post_sidccofo_partial_refund', array( $this, 'record_refund' ) );
		add_filter( 'manage_' . Post_Types::ORDER . '_posts_columns', array( $this, 'order_columns' ) );
		add_action( 'manage_' . Post_Types::ORDER . '_posts_custom_column', array( $this, 'order_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'order_row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . Post_Types::ORDER, '__return_empty_array' );

		add_action( 'add_meta_boxes_' . Post_Types::COUPON, array( $this, 'coupon_box' ) );
		add_action( 'save_post_' . Post_Types::COUPON, array( $this, 'save_coupon' ), 10, 2 );
		add_filter( 'manage_' . Post_Types::COUPON . '_posts_columns', array( $this, 'coupon_columns' ) );
		add_action( 'manage_' . Post_Types::COUPON . '_posts_custom_column', array( $this, 'coupon_column' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'coupon_title_placeholder' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( SIDCRAFT_COMMERCE_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Admin CSS / JS on the store's own screens only (products, orders,
	 * coupons and Store → Settings).
	 */
	public function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, array( Post_Types::PRODUCT, Post_Types::ORDER, Post_Types::COUPON ), true ) ) {
			return;
		}
		$url = SIDCRAFT_COMMERCE_URL . 'assets/';
		wp_enqueue_style( 'sidccofo-admin', $url . 'css/sc-admin.css', array(), SIDCRAFT_COMMERCE_VERSION );
		wp_enqueue_script(
			'sidccofo-admin',
			$url . 'js/sc-admin.js',
			array(),
			SIDCRAFT_COMMERCE_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * One-off notices after a product or coupon save.
	 */
	public function notices() {
		$user = get_current_user_id();
		if ( get_transient( 'sidccofo_sale_notice_' . $user ) ) {
			delete_transient( 'sidccofo_sale_notice_' . $user );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'The sale price was removed because it was not lower than the regular price.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p></div>';
		}
		$ship = get_transient( 'sidccofo_ship_error_' . $user );
		if ( $ship ) {
			delete_transient( 'sidccofo_ship_error_' . $user );
			/* translators: %s: error message. */
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Tracking number not added: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $ship ) ) . '</p></div>';
		}
		$dupe = get_transient( 'sidccofo_coupon_dupe_' . $user );
		if ( $dupe ) {
			delete_transient( 'sidccofo_coupon_dupe_' . $user );
			/* translators: %s: coupon code. */
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'Another published coupon already uses the code “%s”. Codes must be unique; this coupon was saved as a draft.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $dupe ) ) . '</p></div>';
		}
	}

	/*
	------------------------------------------------------------------
	 * Coupons
	 * ----------------------------------------------------------------
	 */

	/**
	 * "Coupon code" placeholder for the title field.
	 *
	 * @param string   $text Placeholder.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function coupon_title_placeholder( $text, $post ) {
		return Post_Types::COUPON === $post->post_type ? __( 'Coupon code, e.g. SUMMER20', 'sidcraft-commerce-for-sidcraft-page-builder' ) : $text;
	}

	/**
	 * Register the coupon data meta box.
	 */
	public function coupon_box() {
		add_meta_box( 'sidccofo-coupon-data', __( 'Coupon data', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_coupon_box' ), Post_Types::COUPON, 'normal', 'high' );
	}

	/**
	 * Coupon fields.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_coupon_box( $post ) {
		wp_nonce_field( 'sidccofo_save_coupon', '_sidccofo_coupon_nonce' );
		$m    = static function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};
		$sym  = trim( currency_symbol() );
		$type = 'fixed' === $m( '_sidccofo_coupon_type' ) ? 'fixed' : 'percent';
		?>
		<p class="description"><?php esc_html_e( 'The title above is the code customers enter in the cart or at checkout (not case-sensitive). One coupon per order.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		<div class="sc-pd">
			<p><label for="sc-coupon-type"><?php esc_html_e( 'Discount type', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<select id="sc-coupon-type" name="sidccofo_coupon_type">
					<option value="percent" <?php selected( $type, 'percent' ); ?>><?php esc_html_e( 'Percentage of sale price (%)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></option>
					<?php /* translators: %s: currency symbol. */ ?>
					<option value="fixed" <?php selected( $type, 'fixed' ); ?>><?php echo esc_html( sprintf( __( 'Fixed amount (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sym ) ); ?></option>
				</select></p>
			<p><label for="sc-coupon-amount"><?php esc_html_e( 'Amount', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" inputmode="decimal" id="sc-coupon-amount" name="sidccofo_coupon_amount" value="<?php echo esc_attr( $m( '_sidccofo_coupon_amount' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. 15', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
				<span class="description"><?php esc_html_e( 'Percentage: 1–100, taken off the cart subtotal (already at sale prices). Fixed: an amount off the order. May be 0 for a free-shipping coupon.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_coupon_free_shipping" value="1" <?php checked( (bool) $m( '_sidccofo_coupon_free_shipping' ) ); ?>> <?php esc_html_e( 'Free shipping', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
			<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_coupon_logged_in" value="1" <?php checked( (bool) $m( '_sidccofo_coupon_logged_in' ) ); ?>> <?php esc_html_e( 'Only for logged-in customers', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
			<?php /* translators: %s: currency symbol. */ ?>
			<p><label for="sc-coupon-min"><?php echo esc_html( sprintf( __( 'Minimum spend (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sym ) ); ?></label>
				<input type="text" inputmode="decimal" id="sc-coupon-min" name="sidccofo_coupon_min_spend" value="<?php echo esc_attr( $m( '_sidccofo_coupon_min_spend' ) ); ?>" placeholder="<?php esc_attr_e( 'No minimum', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></p>
			<?php /* translators: %s: currency symbol. */ ?>
			<p><label for="sc-coupon-max"><?php echo esc_html( sprintf( __( 'Maximum spend (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sym ) ); ?></label>
				<input type="text" inputmode="decimal" id="sc-coupon-max" name="sidccofo_coupon_max_spend" value="<?php echo esc_attr( $m( '_sidccofo_coupon_max_spend' ) ); ?>" placeholder="<?php esc_attr_e( 'No maximum', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></p>
			<p class="sc-pd__wide"><label for="sc-coupon-emails"><?php esc_html_e( 'Allowed emails', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-coupon-emails" class="large-text" name="sidccofo_coupon_emails" value="<?php echo esc_attr( implode( ', ', (array) $m( '_sidccofo_coupon_emails' ) ) ); ?>" placeholder="<?php esc_attr_e( 'Everyone', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
				<span class="description"><?php esc_html_e( 'Separated by commas. Only customers with one of these emails (account or billing) can use it; *@example.com allows a whole domain.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<p><label for="sc-coupon-starts"><?php esc_html_e( 'Start date', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="date" id="sc-coupon-starts" name="sidccofo_coupon_starts" value="<?php echo esc_attr( $m( '_sidccofo_coupon_starts' ) ); ?>">
				<span class="description"><?php esc_html_e( 'Valid from the start of this day. Leave empty to start now.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<p><label for="sc-coupon-expires"><?php esc_html_e( 'Expiry date', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="date" id="sc-coupon-expires" name="sidccofo_coupon_expires" value="<?php echo esc_attr( $m( '_sidccofo_coupon_expires' ) ); ?>">
				<span class="description"><?php esc_html_e( 'Valid until the end of this day. Leave empty for no expiry.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<p><label for="sc-coupon-limit"><?php esc_html_e( 'Usage limit', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="number" min="0" step="1" id="sc-coupon-limit" name="sidccofo_coupon_usage_limit" value="<?php echo esc_attr( (string) (int) $m( '_sidccofo_coupon_usage_limit' ) ); ?>">
				<?php /* translators: %d: times used. */ ?>
				<span class="description"><?php echo esc_html( sprintf( __( '0 = unlimited. Used %d times so far.', 'sidcraft-commerce-for-sidcraft-page-builder' ), (int) $m( '_sidccofo_coupon_usage_count' ) ) ); ?></span></p>
			<p><label for="sc-coupon-per-customer"><?php esc_html_e( 'Uses per customer', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="number" min="0" step="1" id="sc-coupon-per-customer" name="sidccofo_coupon_per_customer" value="<?php echo esc_attr( (string) (int) $m( '_sidccofo_coupon_per_customer' ) ); ?>">
				<span class="description"><?php esc_html_e( '0 = unlimited. A customer is their account or their billing email, so guests are limited too.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
		</div>
		<?php
		$product_ids = array_filter( array_map( 'absint', (array) $m( '_sidccofo_coupon_products' ) ) );
		$cat_ids     = array_filter( array_map( 'absint', (array) $m( '_sidccofo_coupon_categories' ) ) );
		$ex_products = array_filter( array_map( 'absint', (array) $m( '_sidccofo_coupon_exclude_products' ) ) );
		$ex_cats     = array_filter( array_map( 'absint', (array) $m( '_sidccofo_coupon_exclude_categories' ) ) );
		$names       = array();
		foreach ( $product_ids as $pid ) {
			$names[] = get_the_title( $pid ) . ' (#' . $pid . ')';
		}
		$terms = get_terms(
			array(
				'taxonomy'   => Post_Types::CATEGORY,
				'hide_empty' => false,
			)
		);
		?>
		<h4 class="sc-pd__heading"><?php esc_html_e( 'Applies to', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd">
			<p class="sc-pd__wide"><label for="sc-coupon-products"><?php esc_html_e( 'Products', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-coupon-products" class="large-text" name="sidccofo_coupon_products" value="<?php echo esc_attr( implode( ', ', $product_ids ) ); ?>" placeholder="<?php esc_attr_e( 'Product IDs or SKUs, separated by commas', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
				<span class="description"><?php echo esc_html( $names ? implode( ', ', $names ) : __( 'Leave products and categories empty for the whole cart. With either set, the discount counts only those items.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></span></p>
			<?php if ( $terms && ! is_wp_error( $terms ) ) : ?>
				<fieldset class="sc-pd__wide sc-coupon-cats">
					<legend><strong><?php esc_html_e( 'Categories (subcategories included)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></strong></legend>
					<?php foreach ( $terms as $term ) : ?>
						<label class="sc-pd__check"><input type="checkbox" name="sidccofo_coupon_categories[]" value="<?php echo (int) $term->term_id; ?>" <?php checked( in_array( (int) $term->term_id, $cat_ids, true ) ); ?>> <?php echo esc_html( $term->name ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
		</div>
		<h4 class="sc-pd__heading"><?php esc_html_e( 'Never applies to', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd">
			<p class="sc-pd__wide"><label for="sc-coupon-ex-products"><?php esc_html_e( 'Excluded products', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-coupon-ex-products" class="large-text" name="sidccofo_coupon_exclude_products" value="<?php echo esc_attr( implode( ', ', $ex_products ) ); ?>" placeholder="<?php esc_attr_e( 'Product IDs or SKUs, separated by commas', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
				<span class="description"><?php echo esc_html( $ex_products ? implode( ', ', array_map( 'get_the_title', $ex_products ) ) : __( 'These never get the discount, even inside an included category.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></span></p>
			<?php if ( $terms && ! is_wp_error( $terms ) ) : ?>
				<fieldset class="sc-pd__wide sc-coupon-cats">
					<legend><strong><?php esc_html_e( 'Excluded categories (subcategories included)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></strong></legend>
					<?php foreach ( $terms as $term ) : ?>
						<label class="sc-pd__check"><input type="checkbox" name="sidccofo_coupon_exclude_categories[]" value="<?php echo (int) $term->term_id; ?>" <?php checked( in_array( (int) $term->term_id, $ex_cats, true ) ); ?>> <?php echo esc_html( $term->name ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save coupon data.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_coupon( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$code = Coupon::normalize( $post->post_title );
		update_post_meta( $post_id, '_sidccofo_coupon_code', $code );

		if ( ! isset( $_POST['_sidccofo_coupon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_coupon_nonce'] ) ), 'sidccofo_save_coupon' ) || ! current_user_can( manage_cap() ) ) {
			return;
		}

		$type   = isset( $_POST['sidccofo_coupon_type'] ) && 'fixed' === sanitize_key( wp_unslash( $_POST['sidccofo_coupon_type'] ) ) ? 'fixed' : 'percent';
		$amount = $this->price( wp_unslash( $_POST['sidccofo_coupon_amount'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by price().
		if ( 'percent' === $type && '' !== $amount ) {
			$amount = (string) min( 100, (float) $amount );
		}
		$min     = $this->price( wp_unslash( $_POST['sidccofo_coupon_min_spend'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by price().
		$expires = sanitize_text_field( wp_unslash( $_POST['sidccofo_coupon_expires'] ?? '' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ) {
			$expires = '';
		}

		update_post_meta( $post_id, '_sidccofo_coupon_type', $type );
		update_post_meta( $post_id, '_sidccofo_coupon_amount', $amount );
		update_post_meta( $post_id, '_sidccofo_coupon_min_spend', $min );
		update_post_meta( $post_id, '_sidccofo_coupon_expires', $expires );
		update_post_meta( $post_id, '_sidccofo_coupon_usage_limit', absint( $_POST['sidccofo_coupon_usage_limit'] ?? 0 ) );
		update_post_meta( $post_id, '_sidccofo_coupon_per_customer', absint( $_POST['sidccofo_coupon_per_customer'] ?? 0 ) );
		$starts = sanitize_text_field( wp_unslash( $_POST['sidccofo_coupon_starts'] ?? '' ) );
		update_post_meta( $post_id, '_sidccofo_coupon_starts', preg_match( '/^\d{4}-\d{2}-\d{2}$/', $starts ) ? $starts : '' );
		update_post_meta( $post_id, '_sidccofo_coupon_free_shipping', empty( $_POST['sidccofo_coupon_free_shipping'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_sidccofo_coupon_logged_in', empty( $_POST['sidccofo_coupon_logged_in'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_sidccofo_coupon_products', self::product_ids_from( sanitize_text_field( wp_unslash( $_POST['sidccofo_coupon_products'] ?? '' ) ) ) );
		$cats = isset( $_POST['sidccofo_coupon_categories'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['sidccofo_coupon_categories'] ) ) ) ) : array();
		update_post_meta( $post_id, '_sidccofo_coupon_categories', $cats );
		update_post_meta( $post_id, '_sidccofo_coupon_exclude_products', self::product_ids_from( sanitize_text_field( wp_unslash( $_POST['sidccofo_coupon_exclude_products'] ?? '' ) ) ) );
		$ex_cats = isset( $_POST['sidccofo_coupon_exclude_categories'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['sidccofo_coupon_exclude_categories'] ) ) ) ) : array();
		update_post_meta( $post_id, '_sidccofo_coupon_exclude_categories', $ex_cats );
		update_post_meta( $post_id, '_sidccofo_coupon_max_spend', $this->price( wp_unslash( $_POST['sidccofo_coupon_max_spend'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by price().
		$emails = array();
		foreach ( explode( ',', sanitize_text_field( wp_unslash( $_POST['sidccofo_coupon_emails'] ?? '' ) ) ) as $email ) {
			$email = strtolower( trim( $email ) );
			if ( is_email( $email ) || ( 0 === strpos( $email, '*@' ) && is_email( 'a' . substr( $email, 1 ) ) ) ) {
				$emails[] = $email;
			}
		}
		update_post_meta( $post_id, '_sidccofo_coupon_emails', array_values( array_unique( $emails ) ) );
		if ( '' === get_post_meta( $post_id, '_sidccofo_coupon_usage_count', true ) ) {
			update_post_meta( $post_id, '_sidccofo_coupon_usage_count', 0 );
		}

		// Codes must be unique among published coupons.
		if ( '' !== $code && 'publish' === $post->post_status ) {
			$other = Coupon::get_by_code( $code );
			if ( $other && $other->get_id() !== (int) $post_id ) {
				remove_action( 'save_post_' . Post_Types::COUPON, array( $this, 'save_coupon' ), 10 );
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				);
				add_action( 'save_post_' . Post_Types::COUPON, array( $this, 'save_coupon' ), 10, 2 );
				set_transient( 'sidccofo_coupon_dupe_' . get_current_user_id(), $code, 60 );
			}
		}
	}

	/**
	 * Product ids from typed ids and SKUs ("12, SHIRT-RED, 40").
	 *
	 * @param string $typed Typed list.
	 * @return int[]
	 */
	public static function product_ids_from( $typed ) {
		$ids = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $typed ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( ctype_digit( $part ) && Post_Types::PRODUCT === get_post_type( (int) $part ) ) {
				$ids[] = (int) $part;
				continue;
			}
			$found = get_posts(
				array(
					'post_type'      => Post_Types::PRODUCT,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_sidccofo_sku', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $part, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( $found ) {
				$ids[] = (int) $found[0];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Coupon list columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function coupon_columns( $cols ) {
		return array(
			'cb'           => $cols['cb'] ?? '<input type="checkbox">',
			'title'        => __( 'Code', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sidccofo_offer'     => __( 'Offer', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sidccofo_min_spend' => __( 'Minimum spend', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sidccofo_usage'     => __( 'Used / limit', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sidccofo_expires'   => __( 'Expires', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'date'         => $cols['date'] ?? __( 'Date', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Coupon list cell.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Post.
	 */
	public function coupon_column( $col, $post_id ) {
		$coupon = Coupon::get( $post_id );
		if ( ! $coupon ) {
			return;
		}
		switch ( $col ) {
			case 'sidccofo_offer':
				echo esc_html( $coupon->get_offer_label() );
				break;
			case 'sidccofo_min_spend':
				echo esc_html( $coupon->get_min_spend() > 0 ? format_price( $coupon->get_min_spend() ) : '—' );
				break;
			case 'sidccofo_usage':
				echo esc_html( $coupon->get_usage_count() . ' / ' . ( $coupon->get_usage_limit() ? $coupon->get_usage_limit() : '∞' ) );
				break;
			case 'sidccofo_expires':
				if ( '' === $coupon->get_expires() ) {
					echo '—';
				} else {
					$date = date_i18n( get_option( 'date_format' ), strtotime( $coupon->get_expires() ) );
					echo $coupon->is_expired() ? '<span style="color:#b32d2e">' . esc_html( $date ) . ' (' . esc_html__( 'expired', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ')</span>' : esc_html( $date );
				}
				break;
		}
	}

	/*
	------------------------------------------------------------------
	 * Products
	 * ----------------------------------------------------------------
	 */

	/**
	 * Register the product data meta box.
	 */
	public function product_box() {
		add_meta_box( 'sidccofo-product-data', __( 'Product data', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_product_box' ), Post_Types::PRODUCT, 'normal', 'high' );
	}

	/**
	 * Product data fields.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_product_box( $post ) {
		wp_nonce_field( 'sidccofo_save_product', '_sidccofo_product_nonce' );
		if ( \SidcraftCommerce\Multilingual::is_translation( $post->ID ) ) {
			$this->render_translation_box( $post );
			return;
		}
		$m   = static function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};
		$sym = currency_symbol();
		?>
		<div class="sc-pd">
			<p><label for="sc-regular"><?php echo esc_html( sprintf( /* translators: %s: symbol. */ __( 'Regular price (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), trim( $sym ) ) ); ?></label>
				<input type="text" inputmode="decimal" id="sc-regular" name="sidccofo_regular_price" value="<?php echo esc_attr( $m( '_sidccofo_regular_price' ) ); ?>" placeholder="0.00"></p>
			<p><label for="sc-sale"><?php echo esc_html( sprintf( /* translators: %s: symbol. */ __( 'Sale price (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), trim( $sym ) ) ); ?></label>
				<input type="text" inputmode="decimal" id="sc-sale" name="sidccofo_sale_price" value="<?php echo esc_attr( $m( '_sidccofo_sale_price' ) ); ?>" placeholder="<?php esc_attr_e( 'Optional', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
				<span class="description"><?php esc_html_e( 'Lower than the regular price. Shown above the struck-through regular price.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<p><label for="sc-sku"><?php esc_html_e( 'SKU', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-sku" name="sidccofo_sku" value="<?php echo esc_attr( $m( '_sidccofo_sku' ) ); ?>"></p>
			<p><label for="sc-badge"><?php esc_html_e( 'Image badge', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<select id="sc-badge" name="sidccofo_badge">
					<option value=""><?php esc_html_e( 'None (shows “Sale” when on sale)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></option>
					<?php foreach ( Product::badge_options() as $sidccofo_badge_key => $sidccofo_badge_label ) : ?>
						<option value="<?php echo esc_attr( $sidccofo_badge_key ); ?>" <?php selected( $m( '_sidccofo_badge' ), $sidccofo_badge_key ); ?>><?php echo esc_html( $sidccofo_badge_label ); ?></option>
					<?php endforeach; ?>
				</select></p>
			<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_featured" value="1" <?php checked( (bool) $m( '_sidccofo_featured' ) ); ?>> <?php esc_html_e( 'Featured product', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
			<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_manage_stock" value="1" <?php checked( (bool) $m( '_sidccofo_manage_stock' ) ); ?>> <?php esc_html_e( 'Track stock quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
			<p><label for="sc-stock"><?php esc_html_e( 'Stock quantity', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="number" min="0" step="1" id="sc-stock" name="sidccofo_stock" value="<?php echo esc_attr( (string) (int) $m( '_sidccofo_stock' ) ); ?>"></p>
			<p><label for="sc-status"><?php esc_html_e( 'Stock status (when not tracking)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<select id="sc-status" name="sidccofo_stock_status">
					<option value="instock" <?php selected( 'outofstock' !== $m( '_sidccofo_stock_status' ) ); ?>><?php esc_html_e( 'In stock', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></option>
					<option value="outofstock" <?php selected( 'outofstock', $m( '_sidccofo_stock_status' ) ); ?>><?php esc_html_e( 'Out of stock', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></option>
				</select></p>
			<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_hide_buy_box" value="1" <?php checked( (bool) $m( '_sidccofo_hide_buy_box' ) ); ?>> <?php esc_html_e( 'Hide automatic buy box (layout built with Sidcraft)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
			<?php if ( \SidcraftCommerce\Tax::classes() ) : ?>
				<p><label for="sc-tax-class"><?php esc_html_e( 'Tax class', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
					<select id="sc-tax-class" name="sidccofo_tax_class">
						<option value=""><?php esc_html_e( 'Standard rate', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></option>
						<?php foreach ( \SidcraftCommerce\Tax::classes() as $sidccofo_class => $sidccofo_class_name ) : ?>
							<option value="<?php echo esc_attr( $sidccofo_class ); ?>" <?php selected( sanitize_title( (string) $m( '_sidccofo_tax_class' ) ), $sidccofo_class ); ?>><?php echo esc_html( $sidccofo_class_name ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'Rates per class are set under Store → Settings → Shipping & tax.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<?php endif; ?>
		</div>
		<h4 class="sc-pd__heading"><?php esc_html_e( 'Linked products and external link', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd">
			<?php foreach ( array( '_sidccofo_upsells' => array( 'sc-upsells', 'sidccofo_upsells', __( 'Upsells', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Shown as “You may also like” below this product.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ), '_sidccofo_cross_sells' => array( 'sc-cross-sells', 'sidccofo_cross_sells', __( 'Cross-sells', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Suggested in the cart when this product is in it.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) ) as $sidccofo_key => $sidccofo_link ) : ?>
				<?php $sidccofo_ids = array_filter( array_map( 'absint', (array) $m( $sidccofo_key ) ) ); ?>
				<p class="sc-pd__wide"><label for="<?php echo esc_attr( $sidccofo_link[0] ); ?>"><?php echo esc_html( $sidccofo_link[2] ); ?></label>
					<input type="text" id="<?php echo esc_attr( $sidccofo_link[0] ); ?>" class="large-text" name="<?php echo esc_attr( $sidccofo_link[1] ); ?>" value="<?php echo esc_attr( implode( ', ', $sidccofo_ids ) ); ?>" placeholder="<?php esc_attr_e( 'Product IDs or SKUs, separated by commas', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
					<span class="description"><?php echo esc_html( $sidccofo_link[3] ); ?>
					<?php if ( $sidccofo_ids ) : ?>
						<?php echo esc_html( ' ' . implode( ', ', array_map( static function ( $id ) { return get_the_title( $id ); }, $sidccofo_ids ) ) ); ?>
					<?php endif; ?></span></p>
			<?php endforeach; ?>
			<p class="sc-pd__wide"><label for="sc-product-url"><?php esc_html_e( 'External / affiliate product URL', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="url" id="sc-product-url" class="large-text" name="sidccofo_product_url" value="<?php echo esc_attr( (string) $m( '_sidccofo_product_url' ) ); ?>" placeholder="https://">
				<span class="description"><?php esc_html_e( 'Leave empty for products sold here. With a link, the product is bought on that site: the Add to cart button becomes a button to it.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<p><label for="sc-button-text"><?php esc_html_e( 'Button text', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-button-text" name="sidccofo_button_text" value="<?php echo esc_attr( (string) $m( '_sidccofo_button_text' ) ); ?>" placeholder="<?php esc_attr_e( 'Buy product', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></p>
		</div>
		<?php
		$sidccofo_wu = (string) \SidcraftCommerce\setting( 'weight_unit', 'kg' );
		$sidccofo_du = (string) \SidcraftCommerce\setting( 'dimension_unit', 'cm' );
		?>
		<h4 class="sc-pd__heading"><?php esc_html_e( 'Shipping and specifications', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd">
			<?php /* translators: %s: unit, e.g. kg. */ ?>
			<p><label for="sc-weight"><?php echo esc_html( sprintf( __( 'Weight (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sidccofo_wu ) ); ?></label>
				<input type="text" inputmode="decimal" id="sc-weight" name="sidccofo_weight" value="<?php echo esc_attr( $m( '_sidccofo_weight' ) ); ?>" placeholder="0"></p>
			<?php foreach ( array( 'length' => __( 'Length', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'width' => __( 'Width', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'height' => __( 'Height', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) as $sidccofo_dim => $sidccofo_dim_label ) : ?>
				<p><label for="sc-<?php echo esc_attr( $sidccofo_dim ); ?>"><?php echo esc_html( $sidccofo_dim_label . ' (' . $sidccofo_du . ')' ); ?></label>
					<input type="text" inputmode="decimal" id="sc-<?php echo esc_attr( $sidccofo_dim ); ?>" name="sidccofo_<?php echo esc_attr( $sidccofo_dim ); ?>" value="<?php echo esc_attr( $m( '_sidccofo_' . $sidccofo_dim ) ); ?>" placeholder="0"></p>
			<?php endforeach; ?>
			<?php if ( \SidcraftCommerce\Rewards::enabled() ) : ?>
				<p><label for="sc-reward-points"><?php esc_html_e( 'Reward points per unit', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
					<input type="number" min="0" step="1" id="sc-reward-points" name="sidccofo_reward_points" value="<?php echo esc_attr( $m( '_sidccofo_reward_points' ) ); ?>" placeholder="<?php esc_attr_e( 'Store rate', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
					<span class="description"><?php esc_html_e( 'Empty = the store’s points per amount spent.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<?php endif; ?>
			<p class="sc-pd__wide"><label for="sc-attributes"><?php esc_html_e( 'Attributes (one per line)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<textarea id="sc-attributes" name="sidccofo_attributes" rows="4" class="large-text code" placeholder="<?php echo esc_attr( "[Display]\nScreen size: 15.6 in\nResolution: 1920 × 1080\n[Memory]\nRAM: 16 GB" ); ?>"><?php echo esc_textarea( (string) $m( '_sidccofo_attributes' ) ); ?></textarea>
				<span class="description"><?php esc_html_e( '“Label: value” per line. A line like [Display] starts a group. Shown with the brand, weight and dimensions in the specifications table. Weight also prices shipping when a zone has a per-weight rate.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
		</div>
		<h4 class="sc-pd__heading"><?php esc_html_e( 'Price rules', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd">
			<p class="sc-pd__wide"><label for="sc-price-rules"><?php esc_html_e( 'Quantity discounts, specials with dates and customer-group prices (optional, one per line)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<textarea id="sc-price-rules" name="sidccofo_price_rules" rows="4" class="large-text code" placeholder="<?php echo esc_attr( "10+: 18\nfrom 2026-11-27 until 2026-11-30: 15% off Black Friday\ngroup Wholesale: 70" ); ?>"><?php echo esc_textarea( (string) $m( '_sidccofo_price_rules' ) ); ?></textarea>
				<span class="description"><?php echo esc_html( \SidcraftCommerce\Data\Price_Rules::help_text() ); ?></span>
				<?php $sidccofo_bad_rules = \SidcraftCommerce\Data\Price_Rules::invalid_lines( (string) $m( '_sidccofo_price_rules' ) ); ?>
				<?php if ( $sidccofo_bad_rules ) : ?>
					<span class="description sc-pd__error">
						<?php
						/* translators: %s: lines. */
						echo esc_html( sprintf( __( 'Not understood (ignored): %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), implode( ' | ', $sidccofo_bad_rules ) ) );
						?>
					</span>
				<?php endif; ?>
			</p>
			<?php if ( \SidcraftCommerce\Currencies::is_multi() || '' !== (string) $m( '_sidccofo_currency_prices' ) ) : ?>
				<p class="sc-pd__wide"><label for="sc-currency-prices"><?php esc_html_e( 'Prices in other currencies (optional, one per line)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
					<textarea id="sc-currency-prices" name="sidccofo_currency_prices" rows="2" class="large-text code" placeholder="<?php echo esc_attr( "EUR 18.00\nGBP 15.50" ); ?>"><?php echo esc_textarea( (string) $m( '_sidccofo_currency_prices' ) ); ?></textarea>
					<span class="description"><?php esc_html_e( 'The regular price shoppers pay in that currency, instead of converting at the store rate. Sale prices, specials and quantity discounts take the same share off it.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span>
				</p>
			<?php endif; ?>
		</div>
		<h4 class="sc-pd__heading"><?php esc_html_e( 'Item options', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd">
			<p class="sc-pd__wide"><label for="sc-options"><?php esc_html_e( 'Choices customers make when ordering (one group per line)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<textarea id="sc-options" name="sidccofo_options" rows="5" class="large-text code" placeholder="<?php echo esc_attr( "Size*: Small | Medium +2 | Large +4\nExtras [checkbox]: Cheese +1 | Bacon +2.50\nSpecial instructions [text]: e.g. no onions" ); ?>"><?php echo esc_textarea( (string) $m( '_sidccofo_options' ) ); ?></textarea>
				<span class="description"><?php echo esc_html( \SidcraftCommerce\Data\Product_Options::help_text() ); ?></span></p>
			<?php $this->render_choice_stock( $post ); ?>
			<?php $this->render_variants( $post ); ?>
		</div>
		<?php if ( \SidcraftCommerce\Modules::enabled( 'giftcards' ) ) : ?>
			<h4 class="sc-pd__heading"><?php esc_html_e( 'Gift card', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
			<div class="sc-pd">
				<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_gift_card" value="1" <?php checked( (bool) $m( '_sidccofo_gift_card' ) ); ?>> <?php esc_html_e( 'This product is a gift card', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
				<p><label for="sc-gc-amounts"><?php echo esc_html( sprintf( /* translators: %s: symbol. */ __( 'Amounts (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), trim( $sym ) ) ); ?></label>
					<input type="text" id="sc-gc-amounts" name="sidccofo_gc_amounts" value="<?php echo esc_attr( $m( '_sidccofo_gc_amounts' ) ); ?>" placeholder="25, 50, 100">
					<span class="description"><?php esc_html_e( 'Separated by commas. The regular price above is not used for gift cards.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
				<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_gc_custom" value="1" <?php checked( (bool) $m( '_sidccofo_gc_custom' ) ); ?>> <?php esc_html_e( 'Let buyers enter their own amount', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
				<p><label for="sc-gc-min"><?php esc_html_e( 'Custom amount: minimum', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
					<input type="text" inputmode="decimal" id="sc-gc-min" name="sidccofo_gc_min" value="<?php echo esc_attr( $m( '_sidccofo_gc_min' ) ); ?>" placeholder="10"></p>
				<p><label for="sc-gc-max"><?php esc_html_e( 'Custom amount: maximum', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
					<input type="text" inputmode="decimal" id="sc-gc-max" name="sidccofo_gc_max" value="<?php echo esc_attr( $m( '_sidccofo_gc_max' ) ); ?>" placeholder="<?php esc_attr_e( 'No limit', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></p>
			</div>
		<?php elseif ( $m( '_sidccofo_gift_card' ) ) : ?>
			<p class="description sc-pd__error"><?php esc_html_e( 'This product is a gift card. It can’t be bought while Gift cards are turned off under Store → Settings → Modules.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		<?php endif; ?>
		<?php
		if ( \SidcraftCommerce\Modules::enabled( 'subscriptions' ) ) {
			Subscriptions_Admin::render_product_section( $post );
		} elseif ( $m( '_sidccofo_subscription' ) ) {
			echo '<p class="description sc-pd__error">' . esc_html__( 'This product is billed every period. It can’t be bought while Subscriptions are turned off under Store → Settings → Modules.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		}
		Downloads_Admin::render_product_section( $post );
	}

	/**
	 * Stock per choice: a number field for each choice of the saved options.
	 *
	 * @param \WP_Post $post Product.
	 */
	protected function render_choice_stock( $post ) {
		$groups = \SidcraftCommerce\Data\Product_Options::normalize( \SidcraftCommerce\Data\Product_Options::parse( (string) get_post_meta( $post->ID, '_sidccofo_options', true ) ) );
		$rows   = array();
		foreach ( $groups as $group ) {
			if ( 'text' !== $group['type'] ) {
				foreach ( $group['choices'] as $choice ) {
					$rows[] = array( $group, $choice );
				}
			}
		}
		if ( ! $rows ) {
			return;
		}
		?>
		<div class="sc-pd__wide sc-choice-stock">
			<p><strong><?php esc_html_e( 'Stock, SKU and image per choice', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></strong>
				<span class="description"><?php esc_html_e( 'All optional. Stock: leave empty for no limit; a choice at 0 shows as sold out, orders take from these counts and cancellations or refunds put the units back. SKU and image: used when the choice is picked (the image replaces the product image). New choices appear here after saving.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Option', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th><th><?php esc_html_e( 'Choice', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th><th><?php esc_html_e( 'In stock', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th><th><?php esc_html_e( 'SKU', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th><th><?php esc_html_e( 'Image', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as list( $group, $choice ) ) : ?>
						<?php
						$left  = \SidcraftCommerce\Data\Choice_Stock::get( $post->ID, $group['id'], $choice['id'] );
						$extra = Product::get( $post->ID ) ? Product::get( $post->ID )->get_choice_extra( $group['id'], $choice['id'] ) : array( 'sku' => '', 'image' => 0 );
						$base  = 'sidccofo_choice_extra[' . $group['id'] . '][' . $choice['id'] . ']';
						?>
						<tr>
							<td><?php echo esc_html( $group['label'] ); ?></td>
							<td><?php echo esc_html( $choice['label'] ); ?></td>
							<td><input type="number" min="0" step="1" class="small-text" name="sidccofo_choice_stock[<?php echo esc_attr( $group['id'] ); ?>][<?php echo esc_attr( $choice['id'] ); ?>]" value="<?php echo null === $left ? '' : esc_attr( (string) $left ); ?>" placeholder="∞" aria-label="<?php echo esc_attr( $group['label'] . ': ' . $choice['label'] ); ?>"></td>
							<td><input type="text" class="regular-text sc-choice-sku" name="<?php echo esc_attr( $base . '[sku]' ); ?>" value="<?php echo esc_attr( $extra['sku'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: choice. */ __( 'SKU of %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $choice['label'] ) ); ?>"></td>
							<td class="sc-choice-image" data-sc-image-field>
								<input type="hidden" name="<?php echo esc_attr( $base . '[image]' ); ?>" value="<?php echo esc_attr( (string) $extra['image'] ); ?>">
								<span class="sc-image-field__preview"><?php echo $extra['image'] ? wp_get_attachment_image( $extra['image'], array( 40, 40 ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core image HTML. ?></span>
								<button type="button" class="button button-small" data-sc-image-pick><?php esc_html_e( 'Choose', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
								<button type="button" class="button-link" data-sc-image-clear<?php echo $extra['image'] ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Combinations grid: each combination of two or more single-choice options
	 * with its own sold flag, SKU, prices, stock and image.
	 *
	 * @param \WP_Post $post Product.
	 */
	protected function render_variants( $post ) {
		$groups  = \SidcraftCommerce\Data\Product_Options::normalize( \SidcraftCommerce\Data\Product_Options::parse( (string) get_post_meta( $post->ID, '_sidccofo_options', true ) ) );
		$vgroups = \SidcraftCommerce\Data\Variants::groups( $groups );
		if ( count( $vgroups ) < 2 ) {
			return;
		}
		if ( \SidcraftCommerce\Data\Variants::count( $vgroups ) > \SidcraftCommerce\Data\Variants::MAX ) {
			/* translators: 1: number of combinations, 2: limit. */
			echo '<p class="sc-pd__wide description">' . esc_html( sprintf( __( 'These options make %1$d combinations, more than the %2$d that can each have their own SKU, price, stock and image. Use stock, SKU and image per choice instead.', 'sidcraft-commerce-for-sidcraft-page-builder' ), \SidcraftCommerce\Data\Variants::count( $vgroups ), \SidcraftCommerce\Data\Variants::MAX ) ) . '</p>';
			return;
		}
		$sym = trim( currency_symbol() );
		?>
		<div class="sc-pd__wide sc-variants">
			<p><strong><?php esc_html_e( 'Combinations', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></strong>
				<span class="description"><?php esc_html_e( 'Each combination of the options above can have its own SKU, price, stock and image. Empty fields use the product’s (price: the product price plus the choices’ amounts). Untick “Sold” for combinations you don’t offer. New combinations appear here after saving.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Combination', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<th><?php esc_html_e( 'Sold', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<?php /* translators: %s: currency symbol. */ ?>
					<th><?php echo esc_html( sprintf( __( 'Price (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sym ) ); ?></th>
					<?php /* translators: %s: currency symbol. */ ?>
					<th><?php echo esc_html( sprintf( __( 'Sale (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sym ) ); ?></th>
					<th><?php esc_html_e( 'In stock', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					<th><?php esc_html_e( 'Image', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( \SidcraftCommerce\Data\Variants::combinations( $groups ) as $combo ) : ?>
						<?php
						$row   = \SidcraftCommerce\Data\Variants::row( $post->ID, $combo['key'] );
						$left  = \SidcraftCommerce\Data\Variants::stock( $post->ID, $combo['key'] );
						$base  = 'sidccofo_variants[' . $combo['key'] . ']';
						$label = $combo['label'];
						?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><input type="hidden" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1" <?php checked( $row['enabled'] ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: combination. */ __( 'Sell %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $label ) ); ?>"></td>
							<td><input type="text" class="sc-choice-sku" name="<?php echo esc_attr( $base . '[sku]' ); ?>" value="<?php echo esc_attr( $row['sku'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: combination. */ __( 'SKU of %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $label ) ); ?>"></td>
							<td><input type="text" inputmode="decimal" class="small-text" name="<?php echo esc_attr( $base . '[regular]' ); ?>" value="<?php echo esc_attr( $row['regular'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: combination. */ __( 'Price of %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $label ) ); ?>"></td>
							<td><input type="text" inputmode="decimal" class="small-text" name="<?php echo esc_attr( $base . '[sale]' ); ?>" value="<?php echo esc_attr( $row['sale'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: combination. */ __( 'Sale price of %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $label ) ); ?>"></td>
							<td><input type="number" min="0" step="1" class="small-text" name="<?php echo esc_attr( $base . '[stock]' ); ?>" value="<?php echo null === $left ? '' : esc_attr( (string) $left ); ?>" placeholder="∞" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: combination. */ __( 'Stock of %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $label ) ); ?>"></td>
							<td class="sc-choice-image" data-sc-image-field>
								<input type="hidden" name="<?php echo esc_attr( $base . '[image]' ); ?>" value="<?php echo esc_attr( $row['image'] ? (string) $row['image'] : '' ); ?>">
								<span class="sc-image-field__preview"><?php echo $row['image'] ? wp_get_attachment_image( $row['image'], array( 40, 40 ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core image HTML. ?></span>
								<button type="button" class="button button-small" data-sc-image-pick><?php esc_html_e( 'Choose', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
								<button type="button" class="button-link" data-sc-image-clear<?php echo $row['image'] ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Save the stock per choice table. Counts for choices that no longer
	 * exist are removed.
	 *
	 * @param int $post_id Product.
	 */
	protected function save_choice_stock( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in save_product(); values cast below.
		$posted = isset( $_POST['sidccofo_choice_stock'] ) && is_array( $_POST['sidccofo_choice_stock'] ) ? wp_unslash( $_POST['sidccofo_choice_stock'] ) : array();
		$groups = \SidcraftCommerce\Data\Product_Options::normalize( \SidcraftCommerce\Data\Product_Options::parse( (string) get_post_meta( $post_id, '_sidccofo_options', true ) ) );
		$keep   = array();
		foreach ( $groups as $group ) {
			if ( 'text' === $group['type'] ) {
				continue;
			}
			foreach ( $group['choices'] as $choice ) {
				$key = \SidcraftCommerce\Data\Choice_Stock::meta_key( $group['id'], $choice['id'] );
				if ( isset( $posted[ $group['id'] ] ) && is_array( $posted[ $group['id'] ] ) && array_key_exists( $choice['id'], $posted[ $group['id'] ] ) ) {
					$raw = trim( (string) $posted[ $group['id'] ][ $choice['id'] ] );
					\SidcraftCommerce\Data\Choice_Stock::set( $post_id, $group['id'], $choice['id'], '' === $raw ? '' : max( 0, (int) $raw ) );
				}
				$keep[ $key ] = true;
			}
		}
		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $meta_key ) {
			// Combination stock (_sidccofo_cstock_v…) is kept by Variants::save().
			if ( 0 === strpos( (string) $meta_key, '_sidccofo_cstock_' ) && 0 !== strpos( (string) $meta_key, '_sidccofo_cstock_v' ) && ! isset( $keep[ $meta_key ] ) ) {
				delete_post_meta( $post_id, $meta_key );
			}
		}

		// A choice's own SKU and image, kept only for choices that still exist.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in save_product(); values sanitized below.
		$posted_extra = isset( $_POST['sidccofo_choice_extra'] ) && is_array( $_POST['sidccofo_choice_extra'] ) ? wp_unslash( $_POST['sidccofo_choice_extra'] ) : array();
		$extras       = array();
		foreach ( $groups as $group ) {
			if ( 'text' === $group['type'] ) {
				continue;
			}
			foreach ( $group['choices'] as $choice ) {
				$row   = (array) ( $posted_extra[ $group['id'] ][ $choice['id'] ] ?? array() );
				$sku   = sanitize_text_field( (string) ( $row['sku'] ?? '' ) );
				$image = absint( $row['image'] ?? 0 );
				$image = $image && wp_attachment_is_image( $image ) ? $image : 0;
				if ( '' !== $sku || $image ) {
					$extras[ $group['id'] . '|' . $choice['id'] ] = array(
						'sku'   => $sku,
						'image' => $image,
					);
				}
			}
		}
		update_post_meta( $post_id, '_sidccofo_choice_extra', $extras );
	}

	/**
	 * Parse a price field: "1,299.50" → "1299.50"; '' stays ''.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	protected function price( $raw ) {
		return \SidcraftCommerce\parse_price( $raw );
	}

	/**
	 * Product data for a translation: the store data lives on the original;
	 * here only the option and attribute labels are translated.
	 *
	 * @param \WP_Post $post Translation.
	 */
	protected function render_translation_box( $post ) {
		$orig = \SidcraftCommerce\Multilingual::original_id( $post->ID );
		$link = get_edit_post_link( $orig );
		$text = static function ( $id, $key ) {
			return (string) get_post_meta( $id, $key, true );
		};
		?>
		<div class="sc-pd">
			<p class="sc-pd__wide notice notice-info inline" style="padding:8px 12px">
				<?php
				/* translators: %s: link to the original product. */
				echo wp_kses_post( sprintf( __( 'Price, stock, price rules, downloads and the other store settings come from the original product, so every language sells the same stock: %s. Translate the option and attribute labels here.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '<a href="' . esc_url( (string) $link ) . '">' . esc_html( get_the_title( $orig ) ) . '</a>' ) );
				?>
			</p>
			<p class="sc-pd__wide"><label for="sc-options"><?php esc_html_e( 'Item options (translated labels)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<textarea id="sc-options" name="sidccofo_options" rows="4" class="large-text code" placeholder="<?php echo esc_attr( $text( $orig, '_sidccofo_options' ) ); ?>"><?php echo esc_textarea( $text( $post->ID, '_sidccofo_options' ) ); ?></textarea>
				<span class="description"><?php esc_html_e( 'The same groups and choices, in the same order, as the original; prices and stock are the original’s. Leave empty to show the original labels.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<p class="sc-pd__wide"><label for="sc-attributes"><?php esc_html_e( 'Attributes (translated)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<textarea id="sc-attributes" name="sidccofo_attributes" rows="4" class="large-text code" placeholder="<?php echo esc_attr( $text( $orig, '_sidccofo_attributes' ) ); ?>"><?php echo esc_textarea( $text( $post->ID, '_sidccofo_attributes' ) ); ?></textarea></p>
		</div>
		<?php
	}

	/**
	 * Save product data.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_product( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['_sidccofo_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_product_nonce'] ) ), 'sidccofo_save_product' ) ) {
			Product::sync_derived_meta( $post_id ); // REST/block-editor saves still sync.
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( \SidcraftCommerce\Multilingual::is_translation( $post_id ) ) {
			// Only the labels are this translation's own; the rest is the original's.
			update_post_meta( $post_id, '_sidccofo_options', \SidcraftCommerce\Data\Product_Options::clean_text( sanitize_textarea_field( wp_unslash( $_POST['sidccofo_options'] ?? '' ) ) ) );
			update_post_meta( $post_id, '_sidccofo_attributes', sanitize_textarea_field( wp_unslash( $_POST['sidccofo_attributes'] ?? '' ) ) );
			Product::sync_derived_meta( $post_id );
			return;
		}

		$regular = $this->price( wp_unslash( $_POST['sidccofo_regular_price'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by price().
		$sale    = $this->price( wp_unslash( $_POST['sidccofo_sale_price'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by price().

		// A sale price must be below the regular price; otherwise drop it and say so.
		if ( '' !== $sale && ( '' === $regular || (float) $sale >= (float) $regular ) ) {
			$sale = '';
			set_transient( 'sidccofo_sale_notice_' . get_current_user_id(), 1, 60 );
		}

		update_post_meta( $post_id, '_sidccofo_regular_price', $regular );
		update_post_meta( $post_id, '_sidccofo_sale_price', $sale );
		$badge = isset( $_POST['sidccofo_badge'] ) ? sanitize_key( wp_unslash( $_POST['sidccofo_badge'] ) ) : '';
		update_post_meta( $post_id, '_sidccofo_badge', array_key_exists( $badge, Product::badge_options() ) ? $badge : '' );
		update_post_meta( $post_id, '_sidccofo_sku', sanitize_text_field( wp_unslash( $_POST['sidccofo_sku'] ?? '' ) ) );
		update_post_meta( $post_id, '_sidccofo_featured', empty( $_POST['sidccofo_featured'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_sidccofo_manage_stock', empty( $_POST['sidccofo_manage_stock'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_sidccofo_stock', max( 0, absint( $_POST['sidccofo_stock'] ?? 0 ) ) );
		$stock_status = isset( $_POST['sidccofo_stock_status'] ) ? sanitize_key( wp_unslash( $_POST['sidccofo_stock_status'] ) ) : '';
		update_post_meta( $post_id, '_sidccofo_stock_status', 'outofstock' === $stock_status ? 'outofstock' : 'instock' );
		update_post_meta( $post_id, '_sidccofo_hide_buy_box', empty( $_POST['sidccofo_hide_buy_box'] ) ? 0 : 1 );
		update_post_meta( $post_id, '_sidccofo_options', \SidcraftCommerce\Data\Product_Options::clean_text( sanitize_textarea_field( wp_unslash( $_POST['sidccofo_options'] ?? '' ) ) ) );
		update_post_meta( $post_id, '_sidccofo_price_rules', \SidcraftCommerce\Data\Product_Options::clean_text( sanitize_textarea_field( wp_unslash( $_POST['sidccofo_price_rules'] ?? '' ) ) ) );
		if ( isset( $_POST['sidccofo_currency_prices'] ) ) {
			$lines = array();
			foreach ( preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( wp_unslash( $_POST['sidccofo_currency_prices'] ) ) ) as $line ) {
				if ( preg_match( '/^\s*([A-Za-z]{3})\s*[:=]?\s*([0-9]+(?:[.,][0-9]+)?)\s*$/', $line, $cm ) ) {
					$lines[ strtoupper( $cm[1] ) ] = strtoupper( $cm[1] ) . ' ' . str_replace( ',', '.', $cm[2] );
				}
			}
			update_post_meta( $post_id, '_sidccofo_currency_prices', implode( "\n", $lines ) );
		}
		$this->save_choice_stock( $post_id );
		// Combinations: only when the grid was on the screen (it appears after the options are saved).
		if ( isset( $_POST['sidccofo_variants'] ) && is_array( $_POST['sidccofo_variants'] ) ) {
			\SidcraftCommerce\Data\Variants::save( $post_id, wp_unslash( $_POST['sidccofo_variants'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Variants::save().
		}
		foreach ( array( 'weight', 'length', 'width', 'height' ) as $measure ) {
			$value = \SidcraftCommerce\parse_price( wp_unslash( $_POST[ 'sidccofo_' . $measure ] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed as a number.
			update_post_meta( $post_id, '_sidccofo_' . $measure, $value );
		}
		if ( isset( $_POST['sidccofo_reward_points'] ) ) {
			$points = trim( sanitize_text_field( wp_unslash( $_POST['sidccofo_reward_points'] ) ) );
			update_post_meta( $post_id, '_sidccofo_reward_points', '' === $points ? '' : (string) absint( $points ) );
		}
		$attributes = array_slice( array_filter( array_map( 'trim', preg_split( '/\R/', sanitize_textarea_field( wp_unslash( $_POST['sidccofo_attributes'] ?? '' ) ) ) ), 'strlen' ), 0, 100 );
		update_post_meta( $post_id, '_sidccofo_attributes', implode( "\n", $attributes ) );

		// Linked products, external link and tax class.
		update_post_meta( $post_id, '_sidccofo_upsells', array_values( array_diff( self::product_ids_from( sanitize_text_field( wp_unslash( $_POST['sidccofo_upsells'] ?? '' ) ) ), array( $post_id ) ) ) );
		update_post_meta( $post_id, '_sidccofo_cross_sells', array_values( array_diff( self::product_ids_from( sanitize_text_field( wp_unslash( $_POST['sidccofo_cross_sells'] ?? '' ) ) ), array( $post_id ) ) ) );
		update_post_meta( $post_id, '_sidccofo_product_url', esc_url_raw( wp_unslash( $_POST['sidccofo_product_url'] ?? '' ), array( 'http', 'https' ) ) );
		update_post_meta( $post_id, '_sidccofo_button_text', sanitize_text_field( wp_unslash( $_POST['sidccofo_button_text'] ?? '' ) ) );
		if ( isset( $_POST['sidccofo_tax_class'] ) ) {
			$class = sanitize_title( wp_unslash( $_POST['sidccofo_tax_class'] ) );
			update_post_meta( $post_id, '_sidccofo_tax_class', isset( \SidcraftCommerce\Tax::classes()[ $class ] ) ? $class : '' );
		}

		// Gift card product (its fields aren't shown, so are kept, while Gift cards are off).
		if ( \SidcraftCommerce\Modules::enabled( 'giftcards' ) ) {
			update_post_meta( $post_id, '_sidccofo_gift_card', empty( $_POST['sidccofo_gift_card'] ) ? 0 : 1 );
			$amounts = array();
			foreach ( preg_split( '/[\s;,]+/', sanitize_text_field( wp_unslash( $_POST['sidccofo_gc_amounts'] ?? '' ) ) ) as $part ) {
				$value = $this->price( $part );
				if ( '' !== $value && (float) $value > 0 ) {
					$amounts[] = $value;
				}
			}
			update_post_meta( $post_id, '_sidccofo_gc_amounts', implode( ', ', array_unique( $amounts ) ) );
			update_post_meta( $post_id, '_sidccofo_gc_custom', empty( $_POST['sidccofo_gc_custom'] ) ? 0 : 1 );
			update_post_meta( $post_id, '_sidccofo_gc_min', $this->price( wp_unslash( $_POST['sidccofo_gc_min'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by price().
			update_post_meta( $post_id, '_sidccofo_gc_max', $this->price( wp_unslash( $_POST['sidccofo_gc_max'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by price().
		}

		// Subscription (likewise) and virtual / downloadable products.
		if ( \SidcraftCommerce\Modules::enabled( 'subscriptions' ) ) {
			Subscriptions_Admin::save_product_section( $post_id );
		}
		Downloads_Admin::save_product_section( $post_id );

		Product::sync_derived_meta( $post_id );
	}

	/**
	 * Product list columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function product_columns( $cols ) {
		$new = array();
		foreach ( $cols as $key => $label ) {
			if ( 'title' === $key ) {
				$new['sidccofo_thumb'] = '<span class="sc-sr-only screen-reader-text">' . esc_html__( 'Image', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span>';
			}
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['sidccofo_price'] = __( 'Price', 'sidcraft-commerce-for-sidcraft-page-builder' );
				$new['sidccofo_stock'] = __( 'Stock', 'sidcraft-commerce-for-sidcraft-page-builder' );
				$new['sidccofo_sku']   = __( 'SKU', 'sidcraft-commerce-for-sidcraft-page-builder' );
			}
		}
		return $new;
	}

	/**
	 * Product list cell.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Post.
	 */
	public function product_column( $col, $post_id ) {
		$product = Product::get( $post_id );
		if ( ! $product ) {
			return;
		}
		switch ( $col ) {
			case 'sidccofo_thumb':
				echo get_the_post_thumbnail( $post_id, array( 40, 40 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'sidccofo_price':
				echo $product->get_price_html() ? $product->get_price_html() : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Product.
				if ( $product->get_badge() ) {
					echo '<br><small style="font-weight:600;color:#2271b1">' . esc_html( Product::badge_options()[ $product->get_badge() ] ) . '</small>';
				}
				break;
			case 'sidccofo_stock':
				$qty = $product->get_stock_quantity();
				echo $product->is_in_stock() ? esc_html__( 'In stock', 'sidcraft-commerce-for-sidcraft-page-builder' ) : '<span style="color:#b32d2e">' . esc_html__( 'Out of stock', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span>';
				echo null !== $qty ? ' (' . esc_html( $qty ) . ')' : '';
				break;
			case 'sidccofo_sku':
				echo esc_html( $product->get_sku() ? $product->get_sku() : '—' );
				break;
		}
	}

	/*
	------------------------------------------------------------------
	 * Orders
	 * ----------------------------------------------------------------
	 */

	/**
	 * Order meta boxes.
	 */
	public function order_boxes() {
		remove_meta_box( 'submitdiv', Post_Types::ORDER, 'side' );
		remove_meta_box( 'slugdiv', Post_Types::ORDER, 'normal' );
		add_meta_box( 'sidccofo-order-items', __( 'Items', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_order_items' ), Post_Types::ORDER, 'normal', 'high' );
		add_meta_box( 'sidccofo-order-customer', __( 'Customer', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_order_customer' ), Post_Types::ORDER, 'normal' );
		add_meta_box( 'sidccofo-order-actions', __( 'Order status', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_order_actions' ), Post_Types::ORDER, 'side', 'high' );
		add_meta_box( 'sidccofo-order-refund', __( 'Refund', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_order_refund' ), Post_Types::ORDER, 'side' );
		add_meta_box( 'sidccofo-order-notes', __( 'History', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_order_notes' ), Post_Types::ORDER, 'side' );
	}

	/**
	 * Items table.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_order_items( $post ) {
		$order = Order::get( $post->ID );
		\SidcraftCommerce\Currencies::switch_to( $order->get_currency() ); // Item options and totals in the order's currency.
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Product', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'SKU', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Price', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Qty', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th style="text-align:right">' . esc_html__( 'Total', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th></tr></thead><tbody>';
		foreach ( $order->get_items() as $item ) {
			$link = get_edit_post_link( $item['product_id'] );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$d</td><td style="text-align:right">%5$s</td></tr>',
				wp_kses_post( ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $item['name'] ) . '</a>' : esc_html( $item['name'] ) ) . \SidcraftCommerce\item_options_html( $item['options'] ?? array() ) . \SidcraftCommerce\Subscriptions::line_html( $item ) ),
				esc_html( $item['sku'] ? $item['sku'] : '—' ),
				esc_html( \SidcraftCommerce\format_in( $item['price'], $order->get_currency() ) ),
				(int) $item['quantity'],
				esc_html( \SidcraftCommerce\format_in( $item['total'], $order->get_currency() ) )
			);
		}
		echo '</tbody><tfoot>';
		foreach ( \SidcraftCommerce\totals_rows( $order ) as $row ) {
			if ( 'total' === $row['key'] ) {
				printf( '<tr><th colspan="4"><strong>%s</strong></th><td style="text-align:right"><strong>%s</strong></td></tr>', esc_html( $row['label'] ), esc_html( $row['value'] . ' ' . $order->get_currency() ) );
			} elseif ( '' === $row['value'] ) {
				printf( '<tr><td colspan="5" style="text-align:right;color:#646970">%s</td></tr>', esc_html( $row['label'] ) );
			} else {
				printf( '<tr><th colspan="4">%s</th><td style="text-align:right">%s</td></tr>', esc_html( $row['label'] ), esc_html( $row['value'] ) );
			}
		}
		echo '</tfoot></table>';
		\SidcraftCommerce\Currencies::restore();
	}

	/**
	 * Customer and payment details.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_order_customer( $post ) {
		$order   = Order::get( $post->ID );
		$billing = $order->get_billing();
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( \SidcraftCommerce\Checkout\Checkout::fields() as $key => $field ) {
			if ( ! empty( $billing[ $key ] ) ) {
				$value = $billing[ $key ];
				if ( 'country' === $key ) {
					$value = \SidcraftCommerce\Locations::country_name( $value );
				} elseif ( 'state' === $key ) {
					$value = \SidcraftCommerce\Locations::state_name( $billing['country'] ?? '', $value );
				} elseif ( 'select' === $field[1] && isset( $field[5][ $value ] ) ) {
					$value = $field[5][ $value ];
				}
				printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html( $field[0] ), 'email' === $key ? '<a href="mailto:' . esc_attr( $value ) . '">' . esc_html( $value ) . '</a>' : esc_html( $value ) );
			}
		}
		$notes = get_post_meta( $post->ID, '_sidccofo_notes', true );
		if ( $notes ) {
			printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'Customer note', 'sidcraft-commerce-for-sidcraft-page-builder' ), nl2br( esc_html( $notes ) ) );
		}
		if ( $order->get_currency() !== \SidcraftCommerce\base_currency() ) {
			printf(
				'<tr><th>%1$s</th><td>%2$s</td></tr>',
				esc_html__( 'Currency', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				/* translators: 1: order currency, 2: rate, 3: store currency, 4: total in the store currency. */
				esc_html( sprintf( __( '%1$s at %2$s per %3$s (total %4$s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_currency(), (string) $order->get_exchange_rate(), \SidcraftCommerce\base_currency(), \SidcraftCommerce\format_base( $order->to_base( $order->get_total() ) ) ) )
			);
		}
		$gateway = \SidcraftCommerce\Checkout\Checkout::gateway( $order->get_payment_method() );
		printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'Payment method', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_html( $order->get_payment_method_title() ) );
		if ( $order->get_transaction_id() ) {
			printf( '<tr><th>%1$s</th><td><code>%2$s</code></td></tr>', esc_html__( 'Transaction', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_html( $order->get_transaction_id() ) );
		}
		$customer_id = (int) get_post_meta( $post->ID, '_sidccofo_customer_id', true );
		if ( $customer_id > 0 ) {
			$user = get_userdata( $customer_id );
			printf( '<tr><th>%1$s</th><td>%2$s</td></tr>', esc_html__( 'Account', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_html( $user ? $user->user_email : (string) $customer_id ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Record a partial refund and the units to put back in stock.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_order_refund( $post ) {
		$order = Order::get( $post->ID );
		if ( ! $order ) {
			return;
		}
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: amount already refunded, 2: order total. */
				__( 'Refunded %1$s of %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				\SidcraftCommerce\format_in( $order->get_refunded_amount(), $order->get_currency() ),
				\SidcraftCommerce\format_in( $order->get_total(), $order->get_currency() )
			)
		) . '</p>';
		if ( in_array( $order->get_status(), array( 'sidccofo-cancelled', 'sidccofo-refunded' ), true ) ) {
			return;
		}
		$held = $order->quantities_held();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sidccofo_partial_refund' );
		echo '<input type="hidden" name="action" value="sidccofo_partial_refund">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $post->ID ) . '">';
		/* translators: %s: currency code. */
		echo '<p><label>' . esc_html( sprintf( __( 'Amount (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_currency() ) ) . ' <input name="amount" type="number" min="0" step="0.001" style="width:100%"></label></p>';
		foreach ( $order->get_items() as $index => $item ) {
			$product_id = (int) $item['product_id'];
			$max        = min( (int) $item['quantity'], (int) ( $held[ $product_id ] ?? 0 ) );
			$held[ $product_id ] = max( 0, (int) ( $held[ $product_id ] ?? 0 ) - $max );
			if ( $max < 1 ) {
				continue;
			}
			echo '<p><label>' . esc_html(
				sprintf(
					/* translators: 1: product name, 2: units still held. */
					__( 'Return %1$s (up to %2$d)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					$item['name'],
					$max
				)
			) . ' <input name="refund_qty[' . esc_attr( (string) $index ) . ']" type="number" min="0" max="' . esc_attr( (string) $max ) . '" step="1" value="0" style="width:5em"></label></p>';
		}
		echo '<p class="description">' . esc_html__( 'An amount that covers the rest of the order marks it Refunded and returns any units still held. A smaller amount returns only the quantities you enter. The payment itself is refunded in the provider’s dashboard; this records it here.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		submit_button( __( 'Record refund', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Apply a refund from the order screen.
	 */
	public function record_refund() {
		if ( ! current_user_can( manage_cap() ) || ! check_admin_referer( 'sidccofo_partial_refund' ) ) {
			wp_die( esc_html__( 'You cannot refund this order.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$order_id = absint( $_POST['order_id'] ?? 0 );
		$order    = Order::get( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$amount = isset( $_POST['amount'] ) ? (float) \SidcraftCommerce\parse_price( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : 0;
		$posted = isset( $_POST['refund_qty'] ) && is_array( $_POST['refund_qty'] ) ? array_map( 'absint', wp_unslash( $_POST['refund_qty'] ) ) : array();
		$qtys   = array();
		foreach ( $order->get_items() as $index => $item ) {
			$qty = absint( $posted[ $index ] ?? 0 );
			if ( $qty < 1 ) {
				continue;
			}
			$product_id           = (int) $item['product_id'];
			$qtys[ $product_id ] = ( $qtys[ $product_id ] ?? 0 ) + $qty;
		}
		$user = wp_get_current_user();
		$order->record_refund(
			$amount,
			$qtys,
			'manual-' . wp_generate_password( 12, false ),
			sprintf(
				/* translators: %s: user display name. */
				__( 'Refund recorded by %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				$user->display_name
			),
			false
		);
		wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) );
		exit;
	}

	/**
	 * Status select + save.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_order_actions( $post ) {
		$order = Order::get( $post->ID );
		wp_nonce_field( 'sidccofo_save_order', '_sidccofo_order_nonce' );
		echo '<p><select name="sidccofo_order_status" style="width:100%">';
		foreach ( Post_Types::order_statuses() as $status => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $status ), selected( $order->get_status(), $status, false ), esc_html( $label ) );
		}
		echo '</select></p><p class="description">' . esc_html__( 'Stock is reserved when the order is placed. Cancelled or Refunded puts back the units still held. Disputed keeps them reserved until the dispute is lost.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		submit_button( __( 'Update order', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'primary', 'save', false );
	}

	/**
	 * Order history.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_order_notes( $post ) {
		$order = Order::get( $post->ID );
		echo '<ul style="margin:0">';
		foreach ( array_reverse( $order->get_notes() ) as $note ) {
			printf(
				'<li style="border-bottom:1px solid #eee;padding:6px 0"><span>%1$s</span><br><small style="color:#757575">%2$s</small></li>',
				esc_html( $note['note'] ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $note['time'] ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Save a status change from the order screen.
	 *
	 * @param int $post_id Post.
	 */
	public function save_order( $post_id ) {
		if ( Order::$updating || ! isset( $_POST['_sidccofo_order_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_order_nonce'] ) ), 'sidccofo_save_order' ) || ! current_user_can( manage_cap() ) ) {
			return;
		}
		// A shipment added on this save already completed the order; the status
		// dropdown still shows the old status and must not undo that.
		if ( \SidcraftCommerce\Shipments::completed_now( $post_id ) ) {
			return;
		}
		$status = sanitize_key( wp_unslash( $_POST['sidccofo_order_status'] ?? '' ) );
		$order  = Order::get( $post_id );
		if ( $order && $status ) {
			$user = wp_get_current_user();
			/* translators: %s: user display name. */
			$order->set_status( $status, sprintf( __( 'By %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $user->display_name ) );
		}
	}

	/**
	 * Order list columns.
	 *
	 * @return array
	 */
	public function order_columns() {
		return array(
			'cb'          => '<input type="checkbox">',
			'title'       => __( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sidccofo_customer' => __( 'Customer', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sidccofo_status'   => __( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sidccofo_total'    => __( 'Total', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sidccofo_payment'  => __( 'Payment', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'date'        => __( 'Date', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Order list cell.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Post.
	 */
	public function order_column( $col, $post_id ) {
		$order = Order::get( $post_id );
		if ( ! $order ) {
			return;
		}
		$billing = $order->get_billing();
		$colors  = wp_list_pluck( Post_Types::order_status_definitions(), 'color' );
		switch ( $col ) {
			case 'sidccofo_customer':
				echo esc_html( trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) ) ) . '<br><small>' . esc_html( $billing['email'] ?? '' ) . '</small>';
				break;
			case 'sidccofo_status':
				printf( '<strong style="color:%1$s">%2$s</strong>', esc_attr( $colors[ $order->get_status() ] ?? '#000' ), esc_html( $order->get_status_label() ) );
				break;
			case 'sidccofo_total':
				echo esc_html( \SidcraftCommerce\format_in( $order->get_total(), $order->get_currency() ) );
				break;
			case 'sidccofo_payment':
				echo esc_html( $order->get_payment_method_title() );
				break;
		}
	}

	/**
	 * Orders: only "View", no quick edit or trash link (keep records).
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public function order_row_actions( $actions, $post ) {
		if ( Post_Types::ORDER === $post->post_type ) {
			return array( 'edit' => '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html__( 'View', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a>' );
		}
		return $actions;
	}

	/**
	 * Plugins screen link.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Post_Types::PRODUCT . '&page=sidccofo-settings' ) ) . '">' . esc_html__( 'Settings', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a>' );
		return $links;
	}
}
