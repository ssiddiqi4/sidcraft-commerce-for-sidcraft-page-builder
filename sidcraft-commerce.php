<?php
/**
 * Plugin Name:       Sidcraft Commerce for Sidcraft Page Builder
 * Description:       An online store for Sidcraft Page Builder: products, digital downloads, event tickets, cart, checkout, coupons, reviews and PayPal payments. No WooCommerce needed.
 * Version:           2.0.2
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Requires Plugins:  sidcraft-page-builder
 * Author:            Suhaib Siddiqi
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sidcraft-commerce-for-sidcraft-page-builder
 * Domain Path:       /languages
 *
 * @package SidcraftCommerce
 */

defined( 'ABSPATH' ) || exit;

/*
 * Constants. Everything else lives in /includes and loads only after the
 * Sidcraft check passes, so a missing builder never causes a fatal error.
 */
define( 'SIDCRAFT_COMMERCE_VERSION', '2.0.2' );
define( 'SIDCRAFT_COMMERCE_FILE', __FILE__ );
define( 'SIDCRAFT_COMMERCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'SIDCRAFT_COMMERCE_URL', plugin_dir_url( __FILE__ ) );

require_once SIDCRAFT_COMMERCE_PATH . 'includes/class-dependencies.php';
require_once SIDCRAFT_COMMERCE_PATH . 'includes/class-install.php';
require_once SIDCRAFT_COMMERCE_PATH . 'includes/class-modules.php';
require_once SIDCRAFT_COMMERCE_PATH . 'includes/integrations/class-builder-products.php';

// Before Sidcraft Page Builder loads: it reads its post types while its own file loads.
\SidcraftCommerce\Integrations\Builder_Products::early();

// Events can be designed in Sidcraft Page Builder too (same early timing).
add_filter(
	'sidcraft_page_builder_documents_post_types',
	static function ( $types ) {
		if ( is_array( $types ) && ! in_array( 'sidccofo_event', $types, true ) && \SidcraftCommerce\Modules::enabled( 'events' ) ) {
			$types[] = 'sidccofo_event';
		}
		return $types;
	}
);

/*
 * Activation: create the cart-session table, default settings and the Shop,
 * Cart, Checkout and Order-received pages. Safe to run more than once.
 */
register_activation_hook( __FILE__, array( 'Sidcraft_Commerce_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Sidcraft_Commerce_Install', 'deactivate' ) );
// Multisite: set up stores created after network activation.
add_action( 'wp_initialize_site', array( 'Sidcraft_Commerce_Install', 'new_site' ), 200 );

/**
 * Boot once every plugin has loaded. Priority 20 lets Sidcraft finish its own
 * bootstrap first.
 */
add_action(
	'plugins_loaded',
	static function () {
		$missing = Sidcraft_Commerce_Dependencies::missing();
		if ( ! empty( $missing ) ) {
			// Admin notice, then stop: no hooks, assets, post types or Units.
			Sidcraft_Commerce_Dependencies::register_notice( $missing );
			return;
		}

		// Run DB upgrades after a plugin update (activation hook doesn't fire then).
		Sidcraft_Commerce_Install::maybe_upgrade();

		require_once SIDCRAFT_COMMERCE_PATH . 'includes/class-plugin.php';
		\SidcraftCommerce\Plugin::instance()->boot();
	},
	20
);
