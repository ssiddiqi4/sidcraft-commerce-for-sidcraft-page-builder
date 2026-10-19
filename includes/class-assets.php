<?php
/**
 * Conditional asset loading.
 *
 * Strategy:
 *   1. Register every handle on every request (cheap, nothing printed).
 *   2. On the `wp` action, before <head> is printed, scan the queried post's
 *      content and Sidcraft layout meta for commerce Unit names and enqueue
 *      only what those Units declare. CSS lands in <head>: no flash.
 *   3. Safety net: Unit::render() calls require_assets() too, which catches
 *      Units in widgets, popups or REST-loaded content. Late styles then print
 *      in the footer — correct, just not ideal.
 *
 * Nothing is loaded on pages without a commerce Unit.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Class Assets
 */
class Assets {

	/**
	 * Whether the JS config has been printed.
	 *
	 * @var bool
	 */
	private $config_added = false;

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'detect' ), 20 );
	}

	/**
	 * Register (not enqueue) all handles.
	 */
	public function register() {
		if ( wp_script_is( 'sidccofo-cart', 'registered' ) ) {
			return; // Already done (on demand, or by an earlier hook).
		}
		$ver = SIDCRAFT_COMMERCE_VERSION;
		$url = SIDCRAFT_COMMERCE_URL . 'assets/';

		wp_register_style( 'sidccofo-commerce', $url . 'css/sc-commerce.css', array(), $ver );
		wp_register_style( 'sidccofo-quick-view', $url . 'css/sc-quick-view.css', array( 'sidccofo-commerce' ), $ver );
		wp_register_style( 'sidccofo-checkout', $url . 'css/sc-checkout.css', array( 'sidccofo-commerce' ), $ver );
		wp_register_style( 'sidccofo-reviews', $url . 'css/sc-reviews.css', array( 'sidccofo-commerce' ), $ver );

		// No jQuery dependency. `defer` keeps scripts off the critical path.
		wp_register_script(
			'sidccofo-cart',
			$url . 'js/sc-cart.js',
			array(),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_register_script(
			'sidccofo-quick-view',
			$url . 'js/sc-quick-view.js',
			array( 'sidccofo-cart' ),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script(
			'sidccofo-reviews',
			$url . 'js/sc-reviews.js',
			array( 'sidccofo-cart' ),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_script(
			'sidccofo-checkout',
			$url . 'js/sc-checkout.js',
			array( 'sidccofo-cart' ),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		/**
		 * Register extra handles for third-party Units.
		 *
		 * @param Assets $assets This manager.
		 */
		do_action( 'sidcraft_commerce_register_assets', $this );
	}

	/**
	 * Pre-scan the current page and enqueue what its Units need.
	 */
	public function detect() {
		if ( ! is_singular() ) {
			// Archives: Units may come from a Sidcraft template; let the
			// filter below decide, otherwise rely on the render-time net.
			$found = array();
		} else {
			$found = $this->units_in_post( get_queried_object_id() );
		}

		/**
		 * Filter the Unit names detected on the current request. Sidcraft
		 * theme-builder templates (header, footer, archive) should add theirs.
		 *
		 * @param string[] $found Unit names, e.g. [ 'sidccofo-product-grid' ].
		 */
		$found = (array) apply_filters( 'sidcraft_commerce_detected_units', $found );

		$registry = Plugin::instance()->units;
		foreach ( array_unique( $found ) as $name ) {
			$unit = $registry->get( $name );
			if ( $unit ) {
				$this->require_assets( $unit->get_assets() );
			}
		}
	}

	/**
	 * Find commerce Unit names in a post's content and Sidcraft layout data.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public function units_in_post( $post_id ) {
		static $memo = array();
		if ( isset( $memo[ $post_id ] ) ) {
			return $memo[ $post_id ];
		}
		$memo[ $post_id ] = $this->scan_post( $post_id );
		return $memo[ $post_id ];
	}

	/**
	 * Uncached scan behind units_in_post().
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	protected function scan_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$haystack = (string) $post->post_content;

		/**
		 * Filter the post-meta keys where Sidcraft stores layout JSON.
		 *
		 * @param string[] $keys Meta keys.
		 */
		$default   = class_exists( '\\SidcraftPageBuilder\\Document\\DocumentManager' ) ? \SidcraftPageBuilder\Document\DocumentManager::META : '_sidsyn_document_data';
		$meta_keys = (array) apply_filters( 'sidcraft_commerce_layout_meta_keys', array( $default ) );
		foreach ( $meta_keys as $key ) {
			$raw = get_post_meta( $post_id, $key, true );
			if ( $raw ) {
				$haystack .= is_string( $raw ) ? $raw : wp_json_encode( $raw );
			}
		}

		$found = array();
		foreach ( array_keys( Plugin::instance()->units->all() ) as $name ) {
			$shortcode = str_replace( '-', '_', $name );
			if ( false !== strpos( $haystack, '"' . $name . '"' ) || has_shortcode( $post->post_content, $shortcode ) ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	/**
	 * Enqueue a Unit's declared assets.
	 *
	 * @param array $assets { styles: string[], scripts: string[] }.
	 */
	public function require_assets( array $assets ) {
		// Block themes render content (and so Units) before wp_enqueue_scripts
		// runs: register the handles first so enqueues and inline config stick.
		if ( ! wp_script_is( 'sidccofo-cart', 'registered' ) ) {
			$this->register();
		}
		foreach ( (array) ( $assets['styles'] ?? array() ) as $handle ) {
			wp_enqueue_style( $handle );
		}
		foreach ( (array) ( $assets['scripts'] ?? array() ) as $handle ) {
			wp_enqueue_script( $handle );
			$this->add_config();
		}
	}

	/**
	 * Print the JS config once, attached to sc-cart.
	 */
	private function add_config() {
		if ( $this->config_added ) {
			return;
		}
		$this->config_added = true;

		$config = array(
			// REST base, e.g. https://example.com/wp-json/sidcraft-commerce/v1/.
			'api'       => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			// Only for logged-in visitors (never page-cached); see sc-cart.js api().
			'restNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'cartUrl'   => page_url( 'cart' ),
			'lang'      => \SidcraftCommerce\Multilingual::current_language(),
			// Multisite: each store's cookies end in _<site id> (see cookie_name()).
			'cookieSuffix' => substr( \SidcraftCommerce\cookie_name( 'x' ), 1 ),
			'price'     => array(
				'symbol'   => \SidcraftCommerce\currency_symbol(),
				'position' => \SidcraftCommerce\currency_position(),
				'decimals' => \SidcraftCommerce\decimals(),
				'decimal'  => (string) \SidcraftCommerce\setting( 'decimal_sep', '.' ),
				'thousand' => (string) \SidcraftCommerce\setting( 'thousand_sep', ',' ),
			),
			'i18n'      => array(
				/* translators: %s: price including the chosen options. */
				'withOptions'       => __( 'With your choices: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'variantSoldOut'    => __( 'This combination is sold out. Choose other options.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'variantNotSold'    => __( 'This combination isn’t available. Choose other options.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'adding'            => __( 'Adding…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'added'             => __( 'Added', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'viewCart'          => __( 'View cart', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'error'             => __( 'Something went wrong. Please try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'close'             => __( 'Close', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'placing'           => __( 'Placing order…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'select'            => __( 'Select…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'noShip'            => __( 'Sorry, we don’t ship to this country.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'couponEmpty'       => __( 'Enter a coupon code.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'reviewStars'       => __( 'Choose 1 to 5 stars.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'reviewSending'     => __( 'Checking your order…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'paypalUnavailable' => __( 'PayPal could not load. Refresh the page or choose another payment method.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'paypalCapturing'   => __( 'Completing your PayPal payment…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'paypalCancelled'   => __( 'PayPal payment cancelled. You can try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'giftCardEmpty'     => __( 'Enter a gift card code.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);

		/**
		 * Filter the front-end config object (window.sidccofoCommerce).
		 *
		 * @param array $config Config.
		 */
		$config = apply_filters( 'sidcraft_commerce_js_config', $config );

		wp_add_inline_script( 'sidccofo-cart', 'window.sidccofoCommerce = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
