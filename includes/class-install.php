<?php
/**
 * Installer: sessions table, default settings, store pages, cron.
 *
 * @package SidcraftCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sidcraft_Commerce_Install
 */
final class Sidcraft_Commerce_Install {

	/** Bump when the table schema changes. */
	const DB_VERSION = '2';

	/**
	 * Activation hook. Network activation sets up every site of the network
	 * (sites added later are set up when they are created).
	 *
	 * @param bool $network_wide Activated for the whole network.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			self::each_site( array( __CLASS__, 'activate_site' ) );
			return;
		}
		self::activate_site();
	}

	/**
	 * Run a function on every site of the network (up to 1,000; larger
	 * networks set themselves up on first load, see maybe_upgrade()).
	 *
	 * @param callable $fn Function.
	 */
	public static function each_site( callable $fn ) {
		foreach ( get_sites(
			array(
				'fields'   => 'ids',
				'number'   => 1000,
				'archived' => 0,
				'deleted'  => 0,
			)
		) as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			try {
				$fn();
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * A site created on a network where the store is network-active.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( SIDCRAFT_COMMERCE_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::activate_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Set up the current site: table, settings, pages, cron.
	 */
	public static function activate_site() {
		self::create_tables();
		self::default_settings();
		self::create_pages();
		update_option( 'sidcraft_commerce_version', SIDCRAFT_COMMERCE_VERSION );

		if ( ! wp_next_scheduled( 'sidcraft_commerce_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'sidcraft_commerce_cleanup' );
		}

		// Product/category permalinks are registered on init; flush on next load.
		update_option( 'sidcraft_commerce_flush_rewrites', 1 );
	}

	/**
	 * Deactivation hook.
	 *
	 * @param bool $network_wide Deactivated for the whole network.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			self::each_site(
				static function () {
					wp_clear_scheduled_hook( 'sidcraft_commerce_cleanup' );
					delete_option( 'rewrite_rules' ); // Rebuilt on the site's next load.
				}
			);
			return;
		}
		wp_clear_scheduled_hook( 'sidcraft_commerce_cleanup' );
		flush_rewrite_rules();
	}

	/**
	 * Re-run schema changes after a plugin update.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'sidcraft_commerce_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
		}
		// 1.1.0: digital downloads — new setting defaults and the My downloads
		// page. On init, where translations may load.
		// Sites of a network the activation loop didn't reach still get their cleanup job.
		if ( is_multisite() && ! wp_next_scheduled( 'sidcraft_commerce_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'sidcraft_commerce_cleanup' );
		}
		$version = (string) get_option( 'sidcraft_commerce_version', '0' );
		if ( version_compare( $version, '1.1.0', '<' ) ) {
			add_action( 'init', array( __CLASS__, 'upgrade' ), 20 );
		} else {
			if ( version_compare( $version, '1.4.0', '<' ) ) {
				// 1.4.0: catalog, rewards and affiliate settings. Defaults only.
				add_action( 'init', array( __CLASS__, 'upgrade_settings' ), 20 );
			}
			if ( version_compare( $version, '1.6.0', '<' ) ) {
				// 1.6.0: subscriptions — setting defaults and the My subscriptions page.
				add_action( 'init', array( __CLASS__, 'upgrade_subscriptions' ), 21 );
			}
		}
	}

	/**
	 * Fill in settings added by this version, without touching pages.
	 */
	public static function upgrade_settings() {
		self::default_settings();
		update_option( 'sidcraft_commerce_flush_rewrites', 1, true ); // New /brand/ links.
		update_option( 'sidcraft_commerce_version', SIDCRAFT_COMMERCE_VERSION );
	}

	/**
	 * 1.6.0: settings defaults and only the new My subscriptions page.
	 */
	public static function upgrade_subscriptions() {
		self::default_settings();
		self::create_pages( array( 'subscriptions' ) );
		update_option( 'sidcraft_commerce_version', SIDCRAFT_COMMERCE_VERSION );
	}

	/**
	 * One-off upgrade steps for this version.
	 */
	public static function upgrade() {
		self::default_settings();
		self::create_pages();
		update_option( 'sidcraft_commerce_version', SIDCRAFT_COMMERCE_VERSION );
	}

	/**
	 * Cart sessions live in their own table: one row per visitor with a cart,
	 * expiring automatically. Keeps wp_options free of per-visitor rows.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'sidccofo_sessions';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				session_key char(32) NOT NULL,
				session_value longtext NOT NULL,
				session_expiry bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (session_key),
				KEY session_expiry (session_expiry)
			) {$charset};"
		);

		update_option( 'sidcraft_commerce_db_version', self::DB_VERSION );
	}

	/**
	 * Store settings defaults (only fills missing keys).
	 */
	public static function default_settings() {
		$defaults = array(
			'currency'                   => 'USD',
			'currency_symbol'            => '',
			'currency_position'          => 'left',
			'decimals'                   => 2,
			'thousand_sep'               => ',',
			'decimal_sep'                => '.',
			'collect_address'            => 1,
			'default_country'            => 'US',
			'shipping_flat'              => '0',
			'shipping_free_over'         => '0',
			'shipping_zones'             => array(),
			'tax_enabled'                => 0,
			'tax_prices_include'         => 'excl',
			'tax_shipping'               => 0,
			'tax_label'                  => __( 'Tax', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'tax_rates'                  => array(),
			'hide_out_of_stock'          => 0,
			'builder_products'           => 1,
			'auto_buy_box'               => 1,
			'auto_reviews'               => 1,
			'reviews_approve'            => 1,
			'tracking_notify'            => 1,
			'tracking_complete'          => 1,
			'tracking_carriers'          => array(),
			'giftcard_expiry_months'     => '0',
			'paypal_enabled'             => 1,
			'paypal_title'               => __( 'PayPal', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'paypal_sandbox'             => 1,
			'paypal_client_id'           => '',
			'paypal_secret'              => '',
			'paypal_webhook_id'          => '',
			'admin_email'                => get_option( 'admin_email' ),
			'from_name'                  => get_bloginfo( 'name' ),
			'download_method'            => 'force',
			'download_redirect_fallback' => 1,
			'download_inline'            => 0,
			'download_require_login'     => 0,
			'download_verify_email'      => 0,
			'download_grant_on'          => 'paid',
			'download_grant_new_files'   => 1,
			'download_revoke_on_refund'  => 1,
			'download_unique_names'      => 1,
			'download_allow_remote'      => 1,
			'download_approved_dirs'     => '',
			'download_email_links'       => 1,
			'download_log_ip'            => 0,
			'customer_groups'            => '',
			'currencies'                 => array(),
			'currency_by_country'        => 0,
			'weight_unit'                => 'kg',
			'dimension_unit'             => 'cm',
			'auto_specs'                 => 1,
			'rewards_enabled'            => 0,
			'rewards_earn_rate'          => '1',
			'rewards_point_value'        => '0.01',
			'rewards_min_redeem'         => '0',
			'rewards_max_percent'        => '100',
			'affiliates_enabled'         => 0,
			'affiliates_rate'            => '10',
			'affiliates_cookie_days'     => '30',
			'affiliates_auto_approve'    => 0,
			'affiliates_param'           => 'ref',
			'subscription_grace_days'    => '7',
		);
		$current  = (array) get_option( 'sidcraft_commerce_settings', array() );
		update_option( 'sidcraft_commerce_settings', array_merge( $defaults, $current ) );
	}

	/**
	 * Create Shop, Cart, Checkout and Order-received pages once. Each holds a
	 * shortcode that Sidcraft users can replace with the matching Unit.
	 *
	 * @param string[] $only Create only these keys (upgrades); empty = all.
	 */
	public static function create_pages( array $only = array() ) {
		$pages = array(
			'shop'          => array( __( 'Shop', 'sidcraft-commerce-for-sidcraft-page-builder' ), '[sidccofo_product_grid limit="12" columns="4"]' ),
			'cart'          => array( __( 'Cart', 'sidcraft-commerce-for-sidcraft-page-builder' ), '[sidccofo_cart]' ),
			'checkout'      => array( __( 'Checkout', 'sidcraft-commerce-for-sidcraft-page-builder' ), '[sidccofo_checkout]' ),
			'received'      => array( __( 'Order received', 'sidcraft-commerce-for-sidcraft-page-builder' ), '[sidccofo_order_confirmation]' ),
			'downloads'     => array( __( 'My downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ), '[sidccofo_downloads]' ),
			'subscriptions' => array( __( 'My subscriptions', 'sidcraft-commerce-for-sidcraft-page-builder' ), '[sidccofo_subscriptions]' ),
		);
		if ( $only ) {
			$pages = array_intersect_key( $pages, array_flip( $only ) );
		}

		$ids = (array) get_option( 'sidcraft_commerce_pages', array() );
		foreach ( $pages as $key => $page ) {
			if ( ! empty( $ids[ $key ] ) && get_post( $ids[ $key ] ) ) {
				continue;
			}
			$ids[ $key ] = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $page[0],
					'post_content' => $page[1],
				)
			);
		}
		update_option( 'sidcraft_commerce_pages', $ids );
	}
}
