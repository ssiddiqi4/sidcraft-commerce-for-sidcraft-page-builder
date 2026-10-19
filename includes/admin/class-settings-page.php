<?php
/**
 * Store → Settings (Settings API, one option: sidcraft_commerce_settings).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Admin;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;
use function SidcraftCommerce\manage_cap;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings_Page
 */
class Settings_Page {

	const OPTION = 'sidcraft_commerce_settings';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( Product::class, 'bump_query_version' ) );
	}

	/**
	 * Submenu under Store.
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Types::PRODUCT,
			__( 'Store settings', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Settings', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			manage_cap(),
			'sidccofo-settings',
			array( $this, 'render' )
		);
	}

	/**
	 * Field definitions grouped by section.
	 *
	 * @return array
	 */
	protected function sections() {
		/**
		 * Add settings sections. Payment gateways don't need this: their
		 * get_settings_fields() are added automatically.
		 * Each: id => [ title, intro?, tab? (general|shipping|payments|emails), fields => key => [ label, type|options, help?, columns? ] ].
		 * Types: text, number, email, password, checkbox, textarea, table
		 * (rows; columns => key => [ label, text|amount|country|countries|state, placeholder ]),
		 * or an options array for a select.
		 * Fields are saved and sanitized into sidcraft_commerce_settings.
		 *
		 * @param array $sections Sections.
		 */
		$sections = $this->core_sections() + Downloads_Admin::settings_sections();

		// Each gateway brings its own section (enable, title, API keys).
		foreach ( \SidcraftCommerce\Checkout\Checkout::gateways() as $gateway ) {
			$section = $gateway->settings_section();
			if ( is_array( $section ) && ! empty( $section['fields'] ) ) {
				$sections[ 'gw_' . $gateway->get_id() ] = $section;
			}
		}
		// Gift cards after the payment methods, Emails last.
		if ( isset( $sections['giftcards'] ) ) {
			$giftcards = $sections['giftcards'];
			unset( $sections['giftcards'] );
			$sections['giftcards'] = $giftcards;
		}
		if ( isset( $sections['emails'] ) ) {
			$emails = $sections['emails'];
			unset( $sections['emails'] );
			$sections['emails'] = $emails;
		}

		return (array) apply_filters( 'sidcraft_commerce_settings_sections', $sections );
	}

	/**
	 * Built-in sections.
	 *
	 * @return array
	 */
	protected function core_sections() {
		$countries = array( '' => __( '— Select —', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) + \SidcraftCommerce\Locations::countries();
		$pages     = array( 0 => __( '— Select —', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		foreach ( get_pages() as $page ) {
			$pages[ $page->ID ] = $page->post_title;
		}

		return array(
			'general'  => array(
				'tab'    => 'general',
				'title'  => __( 'Store', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'fields' => array(
					'currency'          => array( __( 'Currency code', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'ISO code, e.g. USD, EUR, GBP, INR.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'currency_symbol'   => array( __( 'Currency symbol', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Leave empty to use the standard symbol.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'currency_position' => array(
						__( 'Symbol position', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						array(
							'left'  => __( 'Before ($99)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							'right' => __( 'After (99€)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						),
					),
					'decimals'          => array( __( 'Decimals', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'number' ),
					'thousand_sep'      => array( __( 'Thousand separator', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text' ),
					'decimal_sep'       => array( __( 'Decimal separator', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text' ),
					'collect_address'   => array( __( 'Collect shipping address', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Turn off for digital products and services.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'default_country'   => array( __( 'Store country', 'sidcraft-commerce-for-sidcraft-page-builder' ), $countries, __( 'Used for shipping and tax estimates until the customer picks a country at checkout.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'hide_out_of_stock' => array( __( 'Hide out-of-stock products in grids', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
					'builder_products'  => array( __( 'Design products with Sidcraft Page Builder', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Add New Product opens Sidcraft Page Builder, where a Product data panel sets the price and stock. Products also get "Edit with Sidcraft Page Builder". Turn off to use the WordPress editor.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'auto_buy_box'      => array( __( 'Auto-insert buy box on product pages', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Adds price, stock and Add to cart above the description. Turn off if your product layouts are built with Sidcraft Units.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'auto_reviews'      => array( __( 'Show reviews on product pages', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Adds star ratings and verified-customer reviews below the product description. Sidcraft layouts can place the Product Reviews Unit instead.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'reviews_approve'   => array( __( 'Publish verified reviews immediately', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Reviews are only accepted from customers who enter a paid order number and its email. Turn off to approve each review under Comments first.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				),
			),
			'catalog'  => array(
				'tab'    => 'general',
				'title'  => __( 'Catalog', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'fields' => array(
					'customer_groups' => array( __( 'Customer groups', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'textarea', __( 'One per line, e.g. Wholesale. Each becomes a role you can give customers under Users; price rules such as “group Wholesale: 70” then apply to them.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'weight_unit'     => array(
						__( 'Weight unit', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						array(
							'kg' => __( 'Kilograms (kg)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							'g'  => __( 'Grams (g)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							'lb' => __( 'Pounds (lb)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							'oz' => __( 'Ounces (oz)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						),
					),
					'dimension_unit'  => array(
						__( 'Dimension unit', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						array(
							'cm' => __( 'Centimetres (cm)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							'mm' => __( 'Millimetres (mm)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							'in' => __( 'Inches (in)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						),
					),
					'auto_specs'      => array( __( 'Show specifications on product pages', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Adds a table of brand, attributes, weight and dimensions below the description. Sidcraft layouts can place the Product Specifications Unit instead.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				),
			),
			'shipping' => array(
				'tab'    => 'shipping',
				'title'  => __( 'Shipping', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'intro'  => __( 'The customer’s country picks the zone. Use * for “everywhere else”. Without a * zone, countries outside every zone can’t check out and aren’t offered at checkout.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'fields' => array(
					'shipping_zones'     => array(
						__( 'Shipping zones', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'table',
						__( 'Countries: ISO codes separated by commas, e.g. US or GB, IE, or *. Per weight: optional, added for each unit of the order’s weight (products’ weights × quantities). Free over: leave empty to use the store-wide amount below.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						array(
							'name'      => array( __( 'Zone name', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'United States', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
							'countries' => array( __( 'Countries', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'countries', 'US' ),
							'rate'       => array( __( 'Flat rate', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'amount', '5.00' ),
							/* translators: %s: weight unit, e.g. kg. */
							'per_weight' => array( sprintf( __( 'Per %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), (string) \SidcraftCommerce\setting( 'weight_unit', 'kg' ) ), 'amount', '' ),
							'free_over'  => array( __( 'Free over', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'amount', '' ),
						),
					),
					'shipping_free_over' => array( __( 'Free shipping over', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Order subtotal that unlocks free shipping in every zone. 0 = off.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'shipping_flat'      => array( __( 'Flat rate when no zones are set', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Charged everywhere if the zone table is empty. 0 = free.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				),
			),
			'tracking' => array(
				'tab'    => 'shipping',
				'title'  => __( 'Shipment tracking', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'intro'  => __( 'Add tracking numbers on the order screen; the customer gets a “shipped” email with a tracking link. Links are built automatically for Australia Post, Canada Post, City-Link, DHL, DPD, Fastway, FedEx, OnTrac, Parcelforce, Polish carriers, Royal Mail, SAPO, TNT, UPS and USPS.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'fields' => array(
					'tracking_notify'   => array( __( 'Email the customer', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Default for new shipments: send the “Your order has shipped” email.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'tracking_complete' => array( __( 'Complete the order', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Default for new shipments: mark the order Completed.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'tracking_carriers' => array(
						__( 'Your carriers', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'table',
						__( 'Add carriers you use often, or fix a built-in link by using the same name. In the link, {tracking_number} is replaced by the tracking number and {postcode} by the customer’s postcode.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						array(
							'name' => array( __( 'Carrier name', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'Aramex' ),
							'url'  => array( __( 'Tracking link', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'url', 'https://www.aramex.com/track/results?ShipmentNumber={tracking_number}' ),
						),
					),
				),
			),
			'tax'      => array(
				'tab'    => 'shipping',
				'title'  => __( 'Tax', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'intro'  => __( 'The customer’s country and state pick the rate: a country + state row first, then the country, then *. No matching row means no tax.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'fields' => array(
					'tax_enabled'        => array( __( 'Charge tax', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
					'tax_prices_include' => array(
						__( 'Prices are entered', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						array(
							'excl' => __( 'Excluding tax (tax is added at checkout)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							'incl' => __( 'Including tax (shown as “Includes … tax”)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						),
					),
					'tax_shipping'       => array( __( 'Tax shipping', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Apply the same rate to shipping.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'tax_label'          => array( __( 'Tax label', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Shown when a rate has no label of its own, e.g. Tax, VAT, GST.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'tax_rates'          => array(
						__( 'Tax rates', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'table',
						__( 'Country: ISO code or *. State: optional code, e.g. CA, NY, ON. Rate in percent.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						array(
							'country' => array( __( 'Country', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'country', 'US' ),
							'state'   => array( __( 'State', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'state', 'CA' ),
							'rate'    => array( __( 'Rate %', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'amount', '7.25' ),
							'label'   => array( __( 'Label', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Sales tax', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
						),
					),
				),
			),
			'pages'    => array(
				'tab'    => 'general',
				'title'  => __( 'Pages', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'fields' => array(
					'page_shop'      => array( __( 'Shop page', 'sidcraft-commerce-for-sidcraft-page-builder' ), $pages ),
					'page_cart'      => array( __( 'Cart page', 'sidcraft-commerce-for-sidcraft-page-builder' ), $pages ),
					'page_checkout'  => array( __( 'Checkout page', 'sidcraft-commerce-for-sidcraft-page-builder' ), $pages ),
					'page_received'  => array( __( 'Order received page', 'sidcraft-commerce-for-sidcraft-page-builder' ), $pages ),
					'page_downloads' => array( __( 'My downloads page', 'sidcraft-commerce-for-sidcraft-page-builder' ), $pages, __( 'Customers’ files from all their orders ([sidccofo_downloads] or the My Downloads Unit). Linked from download emails.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				),
			),
			'giftcards' => array(
				'tab'    => 'payments',
				'title'  => __( 'Gift cards', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'intro'  => __( 'Sell gift cards by ticking “This product is a gift card” on a product. Issue cards by hand under Store → Gift cards. Customers redeem codes in the cart or at checkout.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'fields' => array(
					'giftcard_expiry_months' => array( __( 'New cards expire after (months)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( '0 = never. Check your local rules: many places require gift cards to stay valid for years or forever.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				),
			),
			'emails'   => array(
				'tab'    => 'emails',
				'title'  => __( 'Emails', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'fields' => array(
					'admin_email' => array( __( 'New-order notifications to', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'email' ),
					'from_name'   => array( __( '"From" name', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text' ),
				),
			),
		);
	}

	/**
	 * Register setting, sections and fields.
	 */
	public function register() {
		register_setting(
			'sidccofo_settings',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

		$tab = $this->current_tab();
		foreach ( $this->sections() as $id => $section ) {
			if ( null !== $tab && ( $section['tab'] ?? 'general' ) !== $tab ) {
				continue;
			}
			$intro = (string) ( $section['intro'] ?? '' );
			add_settings_section(
				'sidccofo_' . $id,
				$section['title'],
				static function () use ( $intro ) {
					if ( $intro ) {
						echo '<p>' . esc_html( $intro ) . '</p>';
					}
				},
				'sidccofo-settings'
			);
			foreach ( $section['fields'] as $key => $field ) {
				add_settings_field(
					$key,
					$field[0],
					array( $this, 'field' ),
					'sidccofo-settings',
					'sidccofo_' . $id,
					array(
						'key'       => $key,
						'type'      => $field[1],
						'help'      => $field[2] ?? '',
						'columns'   => $field[3] ?? array(),
						'label_for' => 'table' === $field[1] ? '' : 'sc-' . $key,
					)
				);
			}
		}
	}

	/**
	 * Tabs: slug => label.
	 *
	 * @return array
	 */
	protected function tabs() {
		$tabs = array(
			'general'   => __( 'General', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'shipping'  => __( 'Shipping & tax', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'payments'  => __( 'Payments', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'downloads' => __( 'Downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'emails'    => __( 'Emails', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);

		/**
		 * Filter the settings tabs: slug => label.
		 *
		 * @param array $tabs Tabs.
		 */
		return (array) apply_filters( 'sidcraft_commerce_settings_tabs', $tabs );
	}

	/**
	 * Tab being shown, or null while saving (all sections are registered so
	 * options.php can sanitize; sanitize() limits itself to the posted tab).
	 *
	 * @return string|null
	 */
	protected function current_tab() {
		global $pagenow;
		if ( 'options.php' === $pagenow ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'general';
	}

	/**
	 * Current value (page_* keys come from the pages option).
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	protected function value( $key ) {
		if ( 0 === strpos( $key, 'page_' ) ) {
			$pages = (array) get_option( 'sidcraft_commerce_pages', array() );
			return $pages[ substr( $key, 5 ) ] ?? 0;
		}
		$all = (array) get_option( self::OPTION, array() );
		return $all[ $key ] ?? '';
	}

	/**
	 * Render one field.
	 *
	 * @param array $args Args.
	 */
	public function field( $args ) {
		$key   = $args['key'];
		$name  = self::OPTION . '[' . $key . ']';
		$id    = 'sc-' . $key;
		$value = $this->value( $key );

		if ( is_array( $args['type'] ) ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $args['type'] as $opt => $label ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $opt ), selected( (string) $value, (string) $opt, false ), esc_html( $label ) );
			}
			echo '</select>';
		} elseif ( 'table' === $args['type'] ) {
			$this->table_field( $name, $id, (array) $value, $args['columns'] );
		} elseif ( 'checkbox' === $args['type'] ) {
			printf( '<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s>', esc_attr( $id ), esc_attr( $name ), checked( (bool) $value, true, false ) );
		} elseif ( 'textarea' === $args['type'] ) {
			printf( '<textarea id="%1$s" name="%2$s" rows="3" class="large-text">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
		} elseif ( 'password' === $args['type'] ) {
			// Never echo secrets back; empty input keeps the stored value.
			printf( '<input type="password" id="%1$s" name="%2$s" value="" class="regular-text" autocomplete="off" placeholder="%3$s">', esc_attr( $id ), esc_attr( $name ), $value ? esc_attr__( '•••••••• saved — leave empty to keep', 'sidcraft-commerce-for-sidcraft-page-builder' ) : '' );
		} else {
			printf( '<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="%5$s">', esc_attr( $args['type'] ), esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), 'number' === $args['type'] ? 'small-text' : 'regular-text' );
		}
		if ( $args['help'] ) {
			echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
		}
	}

	/**
	 * Editable rows (shipping zones, tax rates). An empty row is always
	 * offered; "Add row" clones it.
	 *
	 * @param string $name    Field name.
	 * @param string $id      Field id.
	 * @param array  $rows    Saved rows.
	 * @param array  $columns key => [ label, kind, placeholder ].
	 */
	protected function table_field( $name, $id, array $rows, array $columns ) {
		$rows[] = array(); // Blank row for adding.
		echo '<table class="widefat striped sc-rows" id="' . esc_attr( $id ) . '" style="max-width:820px"><thead><tr>';
		foreach ( $columns as $col ) {
			echo '<th scope="col">' . esc_html( $col[0] ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Remove', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></th></tr></thead><tbody>';
		$rows  = array_values( $rows );
		$blank = count( $rows ) - 1;
		foreach ( $rows as $i => $row ) {
			echo '<tr>';
			foreach ( $columns as $ckey => $col ) {
				printf(
					'<td><input type="text" name="%1$s" value="%2$s" placeholder="%3$s" aria-label="%4$s" class="%5$s"></td>',
					esc_attr( $name . '[' . $i . '][' . $ckey . ']' ),
					esc_attr( (string) ( $row[ $ckey ] ?? '' ) ),
					esc_attr( $i === $blank && '' !== (string) ( $col[2] ?? '' ) ? sprintf( /* translators: %s: example value. */ __( 'e.g. %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $col[2] ) : '' ), // Examples only on the empty row.
					esc_attr( $col[0] ),
					in_array( $col[1], array( 'amount', 'country', 'state', 'currency', 'rate', 'position', 'decimals' ), true ) ? 'small-text' : 'regular-text'
				);
			}
			printf( '<td><button type="button" class="button-link sc-rows__remove" aria-label="%s">✕</button></td>', esc_attr__( 'Remove row', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			echo '</tr>';
		}
		echo '</tbody></table>';
		printf( '<p><button type="button" class="button sc-rows__add" data-for="%1$s">%2$s</button></p>', esc_attr( $id ), esc_html__( 'Add row', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
	}

	/**
	 * Clean table rows: drop empty ones, normalise each column.
	 *
	 * @param mixed $raw     Submitted rows.
	 * @param array $columns Column definitions.
	 * @return array
	 */
	protected function sanitize_rows( $raw, array $columns ) {
		$out = array();
		foreach ( (array) $raw as $row ) {
			$row   = (array) $row;
			$clean = array();
			$empty = true;
			foreach ( $columns as $ckey => $col ) {
				$v = trim( sanitize_text_field( (string) ( $row[ $ckey ] ?? '' ) ) );
				switch ( $col[1] ) {
					case 'amount':
						$v = '' === $v ? '' : ( is_numeric( str_replace( ',', '.', $v ) ) ? (string) max( 0, (float) str_replace( ',', '.', $v ) ) : '' );
						break;
					case 'country':
						$v = '*' === $v ? '*' : \SidcraftCommerce\Locations::normalize_country( $v );
						break;
					case 'countries':
						$codes = array();
						foreach ( explode( ',', $v ) as $part ) {
							$part = trim( $part );
							$code = '*' === $part ? '*' : \SidcraftCommerce\Locations::normalize_country( $part );
							if ( '' !== $code ) {
								$codes[] = $code;
							}
						}
						$v = implode( ', ', array_unique( $codes ) );
						break;
					case 'state':
						$v = strtoupper( $v );
						break;
					case 'currency':
						$v = strtoupper( preg_replace( '/[^A-Za-z]/', '', $v ) );
						$v = 3 === strlen( $v ) ? $v : '';
						break;
					case 'rate':
						$v = str_replace( ',', '.', $v );
						$v = is_numeric( $v ) && (float) $v > 0 ? (string) round( (float) $v, 6 ) : '';
						break;
					case 'position':
						$v = strtolower( $v );
						$v = in_array( $v, array( 'before', 'after' ), true ) ? $v : '';
						break;
					case 'decimals':
						$v = '' === $v || ! is_numeric( $v ) ? '' : (string) max( 0, min( 4, (int) $v ) );
						break;
					case 'url':
						// Keep the {tracking_number} / {postcode} tokens through URL sanitizing.
						$v = str_replace( array( '{tracking_number}', '{postcode}', '%1$s', '%2$s' ), array( 'SIDCCOFONUM', 'SIDCCOFOPC', 'SIDCCOFONUM', 'SIDCCOFOPC' ), $v );
						$v = esc_url_raw( $v, array( 'http', 'https' ) );
						$v = str_replace( array( 'SIDCCOFONUM', 'SIDCCOFOPC' ), array( '{tracking_number}', '{postcode}' ), $v );
						break;
				}
				$clean[ $ckey ] = $v;
				if ( '' !== $v ) {
					$empty = false;
				}
			}
			if ( ! $empty ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Sanitize the whole settings array.
	 *
	 * @param array $input Raw.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = (array) $input;
		$old   = (array) get_option( self::OPTION, array() );
		$clean = $old;

		// Page assignments live in their own option.
		$pages = (array) get_option( 'sidcraft_commerce_pages', array() );
		foreach ( array( 'shop', 'cart', 'checkout', 'received', 'downloads' ) as $page ) {
			if ( isset( $input[ 'page_' . $page ] ) ) {
				$pages[ $page ] = absint( $input[ 'page_' . $page ] );
			}
		}
		update_option( 'sidcraft_commerce_pages', $pages );

		// Only the tab that was submitted: fields on other tabs keep their values.
		$posted_tab = isset( $input['_tab'] ) ? sanitize_key( (string) $input['_tab'] ) : '';
		foreach ( $this->sections() as $section ) {
			if ( '' !== $posted_tab && ( $section['tab'] ?? 'general' ) !== $posted_tab ) {
				continue;
			}
			foreach ( $section['fields'] as $key => $field ) {
				if ( 0 === strpos( $key, 'page_' ) ) {
					continue;
				}
				$raw = $input[ $key ] ?? null;
				switch ( is_array( $field[1] ) ? 'select' : $field[1] ) {
					case 'checkbox':
						$clean[ $key ] = empty( $raw ) ? 0 : 1;
						break;
					case 'number':
						$clean[ $key ] = max( 0, min( 4, absint( $raw ) ) );
						break;
					case 'select':
						$clean[ $key ] = array_key_exists( (string) $raw, $field[1] ) ? (string) $raw : ( $old[ $key ] ?? '' );
						break;
					case 'textarea':
						$clean[ $key ] = sanitize_textarea_field( (string) $raw );
						break;
					case 'table':
						$clean[ $key ] = $this->sanitize_rows( $raw, $field[3] ?? array() );
						break;
					case 'email':
						$clean[ $key ] = sanitize_email( (string) $raw );
						break;
					case 'password':
						if ( '' !== trim( (string) $raw ) ) {
							$clean[ $key ] = sanitize_text_field( (string) $raw );
						}
						break;
					default:
						$clean[ $key ] = sanitize_text_field( (string) $raw );
				}
			}
		}

		\SidcraftCommerce\Checkout\Checkout::reset_gateways();
		$clean['currency'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $clean['currency'] ), 0, 3 ) );
		foreach ( array( 'shipping_flat', 'shipping_free_over' ) as $amount ) {
			$clean[ $amount ] = is_numeric( $clean[ $amount ] ?? '' ) ? (string) max( 0, (float) $clean[ $amount ] ) : '0';
		}

		return $clean;
	}

	/**
	 * Page output.
	 */
	public function render() {
		if ( ! current_user_can( manage_cap() ) ) {
			return;
		}
		$tab = (string) $this->current_tab();
		echo '<div class="wrap"><h1>' . esc_html__( 'Store settings', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( $this->tabs() as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( add_query_arg( 'tab', $slug, admin_url( 'edit.php?post_type=' . Post_Types::PRODUCT . '&page=sidccofo-settings' ) ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav><form method="post" action="options.php">';
		settings_fields( 'sidccofo_settings' );
		printf( '<input type="hidden" name="%1$s[_tab]" value="%2$s">', esc_attr( self::OPTION ), esc_attr( $tab ) );
		if ( 'payments' === $tab ) {
			\SidcraftCommerce\Admin\Pro_Card::render( 'payments' );
		}
		do_settings_sections( 'sidccofo-settings' );
		submit_button();
		echo '</form></div>';
	}
}
