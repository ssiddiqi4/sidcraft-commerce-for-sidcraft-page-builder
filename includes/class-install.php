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
	const DB_VERSION = '3';

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
					self::clear_cron();
					delete_option( 'rewrite_rules' ); // Rebuilt on the site's next load.
				}
			);
			return;
		}
		self::clear_cron();
		flush_rewrite_rules();
	}

	/**
	 * Remove every scheduled job of the store (hourly tidy-up, campaign
	 * batches, order follow-ups). Campaigns still sending carry on after
	 * reactivation: the hourly job picks them up again.
	 */
	public static function clear_cron() {
		foreach ( array( 'sidcraft_commerce_cleanup', 'sidcraft_commerce_marketing_batch', 'sidcraft_commerce_marketing_followup' ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
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
		// The hourly job comes back if it was lost (cron reset, site moved, a
		// network site the activation loop didn't reach): renewals, reminders and
		// cleanups depend on it. wp_next_scheduled() reads the autoloaded cron option.
		if ( ! wp_next_scheduled( 'sidcraft_commerce_cleanup' ) ) {
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
			if ( version_compare( $version, '2.0.0', '<' ) ) {
				// 1.7.0: module switches (Settings → Modules), on as before.
				// 1.8.0: upsells / cross-sells switches and tax classes; /product-tag/ links.
				// 2.0.0: email marketing and live chat settings.
				// Defaults only (and a rewrite flush).
				add_action( 'init', array( __CLASS__, 'upgrade_settings' ), 22 );
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
	 * Custom tables. Cart sessions: one row per visitor with a cart, expiring
	 * automatically, which keeps wp_options free of per-visitor rows.
	 * Marketing (2.0.0): subscribers, the email log (one row per email sent,
	 * for opens, clicks and not sending twice) and checkouts left unfinished.
	 * Live chat (2.0.0): conversations and their messages.
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

		$prefix = $wpdb->prefix . 'sidccofo_';
		dbDelta(
			"CREATE TABLE {$prefix}subscribers (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(190) NOT NULL,
				first_name varchar(100) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				token char(32) NOT NULL,
				source varchar(40) NOT NULL DEFAULT '',
				tags varchar(255) NOT NULL DEFAULT '',
				created bigint(20) unsigned NOT NULL DEFAULT 0,
				confirmed bigint(20) unsigned NOT NULL DEFAULT 0,
				orders int(10) unsigned NOT NULL DEFAULT 0,
				spent decimal(19,4) NOT NULL DEFAULT 0,
				last_order bigint(20) unsigned NOT NULL DEFAULT 0,
				winback_sent bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email),
				UNIQUE KEY token (token),
				KEY status (status)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$prefix}email_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ref varchar(40) NOT NULL,
				subscriber_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(190) NOT NULL,
				sent bigint(20) unsigned NOT NULL DEFAULT 0,
				opened bigint(20) unsigned NOT NULL DEFAULT 0,
				clicked bigint(20) unsigned NOT NULL DEFAULT 0,
				failed tinyint(1) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY ref_email (ref,email),
				KEY subscriber_id (subscriber_id)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$prefix}abandoned (
				session_key char(32) NOT NULL,
				email varchar(190) NOT NULL,
				first_name varchar(100) NOT NULL DEFAULT '',
				token char(32) NOT NULL,
				cart longtext NOT NULL,
				total decimal(19,4) NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				updated bigint(20) unsigned NOT NULL DEFAULT 0,
				reminded bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (session_key),
				UNIQUE KEY token (token),
				KEY updated (updated)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$prefix}chats (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				visitor char(32) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(100) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'open',
				page varchar(255) NOT NULL DEFAULT '',
				created bigint(20) unsigned NOT NULL DEFAULT 0,
				updated bigint(20) unsigned NOT NULL DEFAULT 0,
				operator_read bigint(20) unsigned NOT NULL DEFAULT 0,
				visitor_read bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY visitor (visitor),
				KEY status_updated (status,updated)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$prefix}chat_messages (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				chat_id bigint(20) unsigned NOT NULL,
				sender varchar(10) NOT NULL,
				author_id bigint(20) unsigned NOT NULL DEFAULT 0,
				body text NOT NULL,
				created bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY chat_id (chat_id,id)
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
			'module_events'              => 1,
			'module_subscriptions'       => 1,
			'module_giftcards'           => 1,
			'module_coupons'             => 1,
			'tax_classes'                => '',
			'auto_upsells'               => 1,
			'cart_cross_sells'           => 1,
			// 2.0.0: email marketing (on) and live chat (off until a store turns it on).
			'module_marketing'           => 1,
			'module_livechat'            => 0,
			'marketing_double_optin'     => 1,
			'marketing_checkout_optin'   => 1,
			'marketing_optin_text'       => __( 'Email me news and offers. You can unsubscribe at any time.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'marketing_from_email'       => '',
			'marketing_address'          => '',
			'marketing_batch'            => '50',
			'marketing_track'            => 1,
			'marketing_welcome'          => 1,
			'marketing_welcome_subject'  => __( 'Welcome to {store_name}', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'marketing_welcome_body'     => __( "Hi {first_name},\n\nThanks for subscribing. We'll send you our news and offers now and then.\n\n{shop_link}", 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'marketing_abandoned'        => 1,
			'marketing_abandoned_hours'  => '4',
			'marketing_abandoned_subject' => __( 'You left something in your cart', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'marketing_abandoned_body'   => __( "Hi {first_name},\n\nYour cart is still waiting for you:\n\n{cart}\n\n{cart_link}", 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'marketing_followup'         => 1,
			'marketing_followup_days'    => '7',
			'marketing_followup_subject' => __( 'How was your order?', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'marketing_followup_body'    => __( "Hi {first_name},\n\nWe hope you're enjoying your order #{order_number}. A short review helps other shoppers:\n\n{products}", 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'marketing_winback'          => 0,
			'marketing_winback_days'     => '90',
			'marketing_winback_subject'  => __( 'We miss you', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'marketing_winback_body'     => __( "Hi {first_name},\n\nIt's been a while. Here's what's new in the shop:\n\n{shop_link}", 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'livechat_title'             => __( 'Chat with us', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'livechat_greeting'          => __( 'Hi! How can we help?', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'livechat_offline'           => __( 'We’re away right now. Leave a message and we’ll email you back.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'livechat_pages'             => 'all',
			'livechat_ask_email'         => 1,
			'livechat_position'          => 'right',
			'livechat_keep_days'         => '180',
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
