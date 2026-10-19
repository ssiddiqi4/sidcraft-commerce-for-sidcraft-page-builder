<?php
/**
 * Main orchestrator. Loads files, wires services, registers Units.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Unit registry.
	 *
	 * @var Unit_Registry
	 */
	public $units;

	/**
	 * Asset manager.
	 *
	 * @var Assets
	 */
	public $assets;

	/**
	 * Emails.
	 *
	 * @var Emails
	 */
	public $emails;

	/**
	 * Template loader.
	 *
	 * @var Template_Loader
	 */
	public $templates;

	/**
	 * Reward points.
	 *
	 * @var Rewards
	 */
	public $rewards;

	/**
	 * Get the instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Load files and attach hooks. Called once from the bootstrap.
	 */
	public function boot() {
		$this->includes();

		$this->templates = new Template_Loader();
		$this->assets    = new Assets();
		$this->units     = new Unit_Registry();

		( new Modules() )->hooks();
		( new Data\Post_Types() )->hooks();
		( new Currencies() )->hooks();
		( new Multilingual() )->hooks();
		( new Network() )->hooks();
		$this->assets->hooks();
		( new Rest() )->hooks();
		( new Frontend() )->hooks();
		$this->emails = new Emails();
		$this->emails->hooks();
		( new Shipments() )->hooks();
		( new Gift_Cards() )->hooks();
		( new Reviews() )->hooks();
		( new Catalog() )->hooks();
		( new Merchandising() )->hooks();
		$this->rewards = new Rewards();
		$this->rewards->hooks();
		( new Affiliates() )->hooks();
		( new Accounts() )->hooks();
		// Off only once no subscription is running (see Settings_Page::sanitize()).
		if ( Modules::enabled( 'subscriptions' ) ) {
			( new Subscriptions() )->hooks();
		}
		( new Downloads\Downloads() )->hooks();
		( new Downloads\Files() )->hooks();
		( new Downloads\Handler() )->hooks();
		( new Downloads\Privacy() )->hooks();
		( new Integrations\Sidcraft_Bridge( $this->units ) )->hooks();
		( new Integrations\Builder_Products() )->hooks();
		( new Events\Events() )->hooks();

		if ( is_admin() ) {
			( new Admin\Admin() )->hooks();
			( new Admin\Settings_Page() )->hooks();
			( new Admin\Pro_Card() )->hooks();
			( new Admin\Export() )->hooks();
			( new Admin\Downloads_Admin() )->hooks();
			if ( Modules::enabled( 'subscriptions' ) ) {
				( new Admin\Subscriptions_Admin() )->hooks();
			}
		}

		add_action( 'init', array( $this, 'register_core_units' ), 5 );
		add_action( 'init', array( Data\Price_Rules::class, 'sync_roles' ), 20 );
		add_action( 'sidcraft_commerce_cleanup', array( Data\Price_Rules::class, 'refresh_dated' ) );

		/**
		 * Fires after Sidcraft Commerce has booted. Register extra Units here:
		 *     add_action( 'sidcraft_commerce_loaded', function ( $plugin ) {
		 *         $plugin->units->register( new My_Unit() );
		 *     } );
		 *
		 * @param Plugin $plugin This instance.
		 */
		do_action( 'sidcraft_commerce_loaded', $this );
	}

	/**
	 * Require class files (explicit list: no Composer, Plugin Check friendly).
	 */
	private function includes() {
		$files = array(
			'includes/helpers.php',
			'includes/class-currencies.php',
			'includes/class-multilingual.php',
			'includes/class-network.php',
			'includes/class-locations.php',
			'includes/class-tax.php',
			'includes/class-shipping.php',
			'includes/class-catalog.php',
			'includes/class-merchandising.php',
			'includes/data/class-post-types.php',
			'includes/data/class-product-options.php',
			'includes/data/class-price-rules.php',
			'includes/data/class-choice-stock.php',
			'includes/data/class-product.php',
			'includes/data/class-coupon.php',
			'includes/data/class-gift-card.php',
			'includes/data/class-order.php',
			'includes/data/class-subscription.php',
			'includes/cart/class-session.php',
			'includes/cart/class-cart.php',
			'includes/payments/abstract-gateway.php',
			'includes/payments/class-gateway-paypal-standard.php',
			'includes/checkout/class-checkout.php',
			'includes/class-template-loader.php',
			'includes/class-assets.php',
			'includes/class-rest.php',
			'includes/class-fragments.php',
			'includes/class-frontend.php',
			'includes/class-emails.php',
			'includes/class-shipments.php',
			'includes/class-gift-cards.php',
			'includes/class-reviews.php',
			'includes/class-rewards.php',
			'includes/class-affiliates.php',
			'includes/class-accounts.php',
			'includes/class-subscriptions.php',
			'includes/downloads/class-downloads.php',
			'includes/downloads/class-files.php',
			'includes/downloads/class-handler.php',
			'includes/downloads/class-privacy.php',
			'includes/class-unit-registry.php',
			'includes/abstracts/abstract-unit.php',
			'includes/units/class-unit-product-grid.php',
			'includes/units/class-unit-add-to-cart.php',
			'includes/units/class-unit-product-price.php',
			'includes/units/class-unit-stock-status.php',
			'includes/units/class-unit-product-rating.php',
			'includes/units/class-unit-product-reviews.php',
			'includes/units/class-unit-mini-cart.php',
			'includes/units/class-unit-cart.php',
			'includes/units/class-unit-checkout.php',
			'includes/units/class-unit-order-confirmation.php',
			'includes/units/class-unit-downloads.php',
			'includes/units/class-unit-product-specs.php',
			'includes/units/class-unit-product-categories.php',
			'includes/units/class-unit-currency-switcher.php',
			'includes/units/class-unit-affiliate-dashboard.php',
			'includes/units/class-unit-subscriptions.php',
			'includes/integrations/class-sidcraft-bridge.php',
			'includes/events/class-qr.php',
			'includes/events/class-barcode.php',
			'includes/events/class-pdf.php',
			'includes/events/class-event.php',
			'includes/events/class-pricing.php',
			'includes/events/class-placeholders.php',
			'includes/events/class-ticket.php',
			'includes/events/class-attendees.php',
			'includes/events/class-calendar.php',
			'includes/events/class-ticket-pdf.php',
			'includes/events/class-mailer.php',
			'includes/events/class-ticketing.php',
			'includes/events/class-integrations.php',
			'includes/events/class-frontend.php',
			'includes/events/class-blocks.php',
			'includes/events/class-admin.php',
			'includes/events/units/class-event-list.php',
			'includes/events/units/class-event-calendar.php',
			'includes/events/units/class-event-details.php',
			'includes/events/units/class-event-tickets.php',
			'includes/events/units/class-event-map.php',
			'includes/events/units/class-my-tickets.php',
			'includes/events/class-events.php',
			'includes/admin/class-admin.php',
			'includes/admin/class-settings-page.php',
			'includes/admin/class-pro-card.php',
			'includes/admin/class-export.php',
			'includes/admin/class-downloads-admin.php',
			'includes/admin/class-subscriptions-admin.php',
		);
		foreach ( $files as $file ) {
			require_once SIDCRAFT_COMMERCE_PATH . $file;
		}
	}

	/**
	 * Register the Units that ship with the add-on.
	 */
	public function register_core_units() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		$core = array(
			new Units\Product_Grid(),
			new Units\Add_To_Cart(),
			new Units\Product_Price(),
			new Units\Stock_Status(),
			new Units\Product_Rating(),
			new Units\Product_Reviews(),
			new Units\Mini_Cart(),
			new Units\Cart(),
			new Units\Checkout(),
			new Units\Order_Confirmation(),
			new Units\Downloads(),
			new Units\Product_Specs(),
			new Units\Product_Categories(),
			new Units\Currency_Switcher(),
			new Units\Affiliate_Dashboard(),
			new Units\Subscriptions(),
		);

		/**
		 * Filter the core Units before registration.
		 *
		 * @param Abstracts\Unit[] $core Unit instances.
		 */
		$core = apply_filters( 'sidcraft_commerce_core_units', $core );

		foreach ( $core as $unit ) {
			$this->units->register( $unit );
		}

		/**
		 * Fires after core Units are registered.
		 *
		 * @param Unit_Registry $registry Registry.
		 */
		do_action( 'sidcraft_commerce_register_units', $this->units );
	}
}
