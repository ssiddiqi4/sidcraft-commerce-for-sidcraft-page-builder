<?php
/**
 * Products, product categories, orders and order statuses.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Data;

use function SidcraftCommerce\manage_cap;

defined( 'ABSPATH' ) || exit;

/**
 * Class Post_Types
 */
class Post_Types {

	const PRODUCT  = 'sidccofo_product';
	const CATEGORY = 'sidccofo_product_cat';
	const BRAND    = 'sidccofo_brand';
	const ORDER    = 'sidccofo_order';
	const COUPON   = 'sidccofo_coupon';
	const GIFTCARD = 'sidccofo_giftcard';

	/** Subscriptions (repeat billing). */
	const SUBSCRIPTION = 'sidccofo_sub';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register' ), 0 );
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
	}

	/**
	 * Statuses added with register_order_status(): slug => args.
	 *
	 * @var array
	 */
	protected static $added_statuses = array();

	/**
	 * Built definitions for this request (after init).
	 *
	 * @var array|null
	 */
	protected static $status_cache = null;

	/**
	 * Add an order status, e.g. from an add-on:
	 *
	 *     add_action( 'sidcraft_commerce_loaded', function () {
	 *         \SidcraftCommerce\Data\Post_Types::register_order_status( 'rst-preparing', array(
	 *             'label'          => __( 'Preparing', 'my-addon' ),
	 *             'paid'           => true,          // Counts as paid (stock, sales, gift cards, reviews).
	 *             'after'          => 'sidccofo-processing',
	 *             'color'          => '#b45309',     // Orders list.
	 *             'customer_email' => array(         // Optional email when an order moves here.
	 *                 'heading' => __( 'We’re preparing your order', 'my-addon' ),
	 *                 'message' => __( 'The kitchen has started on your order.', 'my-addon' ),
	 *             ),
	 *         ) );
	 *     } );
	 *
	 * Call it before init (sidcraft_commerce_loaded or plugins_loaded). Slugs
	 * are at most 20 characters (a WordPress limit); prefix them with your add-on's prefix.
	 *
	 * @param string $slug Status slug.
	 * @param array  $args label, paid, reserves_stock, after, color, customer_email, label_count.
	 * @return bool False when the slug is invalid.
	 */
	public static function register_order_status( $slug, array $args ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug || strlen( $slug ) > 20 || empty( $args['label'] ) ) {
			_doing_it_wrong( esc_html( __METHOD__ ), esc_html( 'Order status slugs must be 1-20 characters (a-z, 0-9, - and _) and need a label.' ), '0.9.0' );
			return false;
		}
		self::$added_statuses[ $slug ] = $args;
		self::$status_cache            = null;
		return true;
	}

	/**
	 * All order statuses with their behaviour, in display order.
	 *
	 * @return array slug => [ label, paid, reserves_stock, color, customer_email, label_count ].
	 */
	public static function order_status_definitions() {
		if ( null !== self::$status_cache ) {
			return self::$status_cache;
		}
		$core = array(
			'sidccofo-pending'    => array(
				'label' => _x( 'Pending payment', 'order status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'color' => '#8a6d00',
			),
			'sidccofo-on-hold'    => array(
				'label'          => _x( 'On hold', 'order status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'reserves_stock' => true,
				'color'          => '#94660c',
			),
			'sidccofo-processing' => array(
				'label' => _x( 'Processing', 'order status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'paid'  => true,
				'color' => '#1a7f37',
			),
			'sidccofo-completed'  => array(
				'label' => _x( 'Completed', 'order status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'paid'  => true,
				'color' => '#2271b1',
			),
			'sidccofo-disputed'   => array(
				'label'          => _x( 'Disputed', 'order status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'paid'           => true,
				'reserves_stock' => true,
				'color'          => '#9a6700',
				'customer_email' => array(
					'heading' => __( 'A payment dispute was opened', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'message' => __( 'The payment provider opened a dispute on this order. The items stay reserved until the dispute is decided.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
			),
			'sidccofo-cancelled'  => array(
				'label' => _x( 'Cancelled', 'order status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'color' => '#757575',
			),
			'sidccofo-refunded'   => array(
				'label' => _x( 'Refunded', 'order status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'color' => '#757575',
			),
		);

		// Added statuses go after the status they name, else before Cancelled.
		$defs = $core;
		foreach ( self::$added_statuses as $slug => $args ) {
			$after = isset( $args['after'] ) ? sanitize_key( (string) $args['after'] ) : '';
			unset( $defs[ $slug ] );
			$keys = array_keys( $defs );
			$pos  = '' !== $after && in_array( $after, $keys, true ) ? array_search( $after, $keys, true ) + 1 : array_search( 'sidccofo-cancelled', $keys, true );
			$pos  = false === $pos ? count( $defs ) : $pos;
			$defs = array_slice( $defs, 0, $pos, true ) + array( $slug => $args ) + array_slice( $defs, $pos, null, true );
		}

		/**
		 * Filter the order statuses and how each behaves.
		 *
		 * @param array $defs slug => [ label, paid, reserves_stock, color, customer_email, label_count ].
		 */
		$defs = (array) apply_filters( 'sidcraft_commerce_order_status_definitions', $defs );

		/**
		 * Older, label-only filter: slug => label. Still honoured; statuses
		 * added here behave like "Processing" only if marked paid in the
		 * definitions filter above.
		 *
		 * @param array $labels slug => label.
		 */
		$labels = (array) apply_filters( 'sidcraft_commerce_order_statuses', wp_list_pluck( array_filter( $defs, 'is_array' ), 'label' ) );

		$out = array();
		foreach ( $labels as $slug => $label ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || strlen( $slug ) > 20 ) {
				continue;
			}
			$args        = isset( $defs[ $slug ] ) && is_array( $defs[ $slug ] ) ? $defs[ $slug ] : array();
			$paid        = ! empty( $args['paid'] );
			$email       = $args['customer_email'] ?? false;
			$out[ $slug ] = array(
				'label'          => (string) $label,
				'paid'           => $paid,
				'reserves_stock' => array_key_exists( 'reserves_stock', $args ) ? (bool) $args['reserves_stock'] : $paid,
				'color'          => sanitize_hex_color( (string) ( $args['color'] ?? '' ) ) ? sanitize_hex_color( (string) $args['color'] ) : '#50575e',
				'customer_email' => is_array( $email ) && ! empty( $email['heading'] ) ? array(
					'heading' => (string) $email['heading'],
					'message' => (string) ( $email['message'] ?? '' ),
				) : false,
				'label_count'    => isset( $args['label_count'] ) && is_array( $args['label_count'] ) ? $args['label_count'] : null,
			);
		}

		if ( did_action( 'init' ) ) {
			self::$status_cache = $out; // Labels are translated from init on.
		}
		return $out;
	}

	/**
	 * Order statuses: slug => label.
	 *
	 * @return array
	 */
	public static function order_statuses() {
		return wp_list_pluck( self::order_status_definitions(), 'label' );
	}

	/**
	 * Statuses that count as paid: stock taken, sales recorded, gift cards
	 * issued, the customer may review.
	 *
	 * @return string[]
	 */
	public static function paid_statuses() {
		return array_keys( wp_list_filter( self::order_status_definitions(), array( 'paid' => true ) ) );
	}

	/**
	 * Statuses that hold stock for the order (paid ones plus On hold).
	 *
	 * @return string[]
	 */
	public static function stock_statuses() {
		return array_keys( wp_list_filter( self::order_status_definitions(), array( 'reserves_stock' => true ) ) );
	}

	/**
	 * Register everything.
	 */
	public function register() {
		register_post_type(
			self::PRODUCT,
			apply_filters(
				'sidcraft_commerce_product_post_type_args',
				array(
					'labels'        => array(
						'name'          => __( 'Products', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'singular_name' => __( 'Product', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'menu_name'     => __( 'Store', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'all_items'     => __( 'Products', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'add_new'       => __( 'Add New Product', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'add_new_item'  => __( 'Add product', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'edit_item'     => __( 'Edit product', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'search_items'  => __( 'Search products', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'not_found'     => __( 'No products yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					),
					'public'        => true,
					'has_archive'   => false, // The Shop page (product grid) is the archive.
					'menu_icon'     => 'dashicons-cart',
					'menu_position' => 56,
					'show_in_rest'  => true,
					'rewrite'       => array(
						'slug'       => 'product',
						'with_front' => false,
					),
					'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'custom-fields' ),
				)
			)
		);

		register_taxonomy(
			self::CATEGORY,
			self::PRODUCT,
			array(
				'labels'            => array(
					'name'          => __( 'Product categories', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name' => __( 'Product category', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'menu_name'     => __( 'Categories', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'       => 'product-category',
					'with_front' => false,
				),
			)
		);

		register_taxonomy(
			self::BRAND,
			self::PRODUCT,
			array(
				'labels'            => array(
					'name'          => __( 'Brands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name' => __( 'Brand', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'menu_name'     => __( 'Brands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'all_items'     => __( 'All brands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'edit_item'     => __( 'Edit brand', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'add_new_item'  => __( 'Add brand', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'search_items'  => __( 'Search brands', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'not_found'     => __( 'No brands yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'hierarchical'      => true, // A checkbox list on the product screen.
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'       => 'brand',
					'with_front' => false,
				),
			)
		);

		$cap = manage_cap();
		register_post_type(
			self::ORDER,
			array(
				'labels'       => array(
					'name'          => __( 'Orders', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name' => __( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'edit_item'     => __( 'Order details', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'search_items'  => __( 'Search orders', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'not_found'     => __( 'No orders yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . self::PRODUCT,
				'supports'     => false,
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'          => $cap,
					'read_post'          => $cap,
					'delete_post'        => $cap,
					'edit_posts'         => $cap,
					'edit_others_posts'  => $cap,
					'delete_posts'       => $cap,
					'publish_posts'      => $cap,
					'read_private_posts' => $cap,
					'create_posts'       => 'do_not_allow', // Orders come from checkout only.
				),
			)
		);

		register_post_type(
			self::SUBSCRIPTION,
			array(
				'labels'       => array(
					'name'          => __( 'Subscriptions', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name' => __( 'Subscription', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'edit_item'     => __( 'Subscription details', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'search_items'  => __( 'Search subscriptions', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'not_found'     => __( 'No subscriptions yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . self::PRODUCT,
				'supports'     => false,
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'          => $cap,
					'read_post'          => $cap,
					'delete_post'        => $cap,
					'edit_posts'         => $cap,
					'edit_others_posts'  => $cap,
					'delete_posts'       => $cap,
					'publish_posts'      => $cap,
					'read_private_posts' => $cap,
					'create_posts'       => 'do_not_allow', // Subscriptions come from checkout only.
				),
			)
		);

		register_post_type(
			self::COUPON,
			array(
				'labels'       => array(
					'name'               => __( 'Coupons', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name'      => __( 'Coupon', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'add_new'            => __( 'Add New Coupon', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'add_new_item'       => __( 'Add coupon', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'edit_item'          => __( 'Edit coupon', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'search_items'       => __( 'Search coupons', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'not_found'          => __( 'No coupons yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'not_found_in_trash' => __( 'No coupons in the trash.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . self::PRODUCT,
				'supports'     => array( 'title' ),
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'edit_published_posts'   => $cap,
					'delete_posts'           => $cap,
					'delete_published_posts' => $cap,
					'delete_others_posts'    => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'create_posts'           => $cap,
				),
			)
		);

		$counts = array(
			/* translators: %s: number of orders. */
			'sidccofo-pending'    => _n_noop( 'Pending payment <span class="count">(%s)</span>', 'Pending payment <span class="count">(%s)</span>', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			/* translators: %s: number of orders. */
			'sidccofo-on-hold'    => _n_noop( 'On hold <span class="count">(%s)</span>', 'On hold <span class="count">(%s)</span>', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			/* translators: %s: number of orders. */
			'sidccofo-processing' => _n_noop( 'Processing <span class="count">(%s)</span>', 'Processing <span class="count">(%s)</span>', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			/* translators: %s: number of orders. */
			'sidccofo-completed'  => _n_noop( 'Completed <span class="count">(%s)</span>', 'Completed <span class="count">(%s)</span>', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			/* translators: %s: number of orders. */
			'sidccofo-cancelled'  => _n_noop( 'Cancelled <span class="count">(%s)</span>', 'Cancelled <span class="count">(%s)</span>', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			/* translators: %s: number of orders. */
			'sidccofo-refunded'   => _n_noop( 'Refunded <span class="count">(%s)</span>', 'Refunded <span class="count">(%s)</span>', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			/* translators: %s: number of orders. */
			'sidccofo-disputed'   => _n_noop( 'Disputed <span class="count">(%s)</span>', 'Disputed <span class="count">(%s)</span>', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);

		register_post_type(
			self::GIFTCARD,
			array(
				'labels'       => array(
					'name'               => __( 'Gift cards', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name'      => __( 'Gift card', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'add_new'            => __( 'Issue Gift Card', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'add_new_item'       => __( 'Issue gift card', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'edit_item'          => __( 'Edit gift card', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'search_items'       => __( 'Search gift cards', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'not_found'          => __( 'No gift cards yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'not_found_in_trash' => __( 'No gift cards in the trash.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . self::PRODUCT,
				'supports'     => array( 'title' ),
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'edit_published_posts'   => $cap,
					'delete_posts'           => $cap,
					'delete_published_posts' => $cap,
					'delete_others_posts'    => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'create_posts'           => $cap,
				),
			)
		);

		foreach ( self::order_status_definitions() as $status => $def ) {
			$label = $def['label'];
			if ( empty( $counts[ $status ] ) && $def['label_count'] ) {
				$counts[ $status ] = $def['label_count'];
			}
			register_post_status(
				$status,
				array(
					'label'                     => $label,
					'public'                    => false,
					'internal'                  => false,
					'protected'                 => true,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					// Statuses added through the sidcraft_commerce_order_statuses filter get a plain, untranslated count label.
					'label_count'               => $counts[ $status ] ?? array(
						0          => $label . ' (%s)',
						1          => $label . ' (%s)',
						'singular' => $label . ' (%s)',
						'plural'   => $label . ' (%s)',
						'context'  => null,
						'domain'   => null,
					),
				)
			);
		}

		// Product fields, exposed to the block editor / REST for headless use.
		$meta = array(
			'_sidccofo_regular_price' => 'string',
			'_sidccofo_sale_price'    => 'string',
			'_sidccofo_sku'           => 'string',
			'_sidccofo_manage_stock'  => 'boolean',
			'_sidccofo_stock'         => 'integer',
			'_sidccofo_stock_status'  => 'string',
			'_sidccofo_featured'      => 'boolean',
			'_sidccofo_badge'         => 'string',
		);
		foreach ( $meta as $key => $type ) {
			register_post_meta(
				self::PRODUCT,
				$key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * Flush rewrite rules once after activation — and self-heal when another
	 * plugin flushed them while this add-on wasn't loaded (e.g. reactivating
	 * or updating Sidcraft Page Builder), which would make product URLs 404.
	 */
	public function maybe_flush() {
		$rules   = get_option( 'rewrite_rules' );
		$missing = is_array( $rules ) && $rules && ! self::has_product_rules( $rules );

		// The flag stays stored (autoloaded, 0 when done): a missing option costs a
		// database query on every request, a stored one comes with the other options.
		$flag = get_option( 'sidcraft_commerce_flush_rewrites' );
		if ( $flag || $missing ) {
			update_option( 'sidcraft_commerce_flush_rewrites', 0, true );
			flush_rewrite_rules( false );
		} elseif ( false === $flag ) {
			add_option( 'sidcraft_commerce_flush_rewrites', 0, '', true );
		}
	}

	/**
	 * Do the stored rewrite rules include our product permalinks?
	 *
	 * @param array $rules Rules.
	 * @return bool
	 */
	protected static function has_product_rules( array $rules ) {
		$base = (string) ( get_post_type_object( self::PRODUCT )->rewrite['slug'] ?? 'product' );
		foreach ( array_keys( $rules ) as $regex ) {
			if ( 0 === strpos( (string) $regex, $base . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
