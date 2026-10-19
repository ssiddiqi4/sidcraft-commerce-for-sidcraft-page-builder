<?php
/**
 * Shipment tracking: tracking numbers on orders, automatic tracking links
 * for major carriers, custom carriers, and the "Your order has shipped"
 * email.
 *
 * Stored on the order as _sidccofo_shipments: a list of
 *   [ id, carrier (slug or ''), carrier_name, number, url (custom), date (Y-m-d), added (timestamp) ].
 *
 * Built-in links use {tracking_number} and {postcode}. Store → Settings →
 * Shipping & tax → "Your carriers" adds carriers or replaces a built-in
 * link (same name), so a carrier changing its website never needs a plugin
 * update.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Class Shipments
 */
class Shipments {

	const META = '_sidccofo_shipments';

	/**
	 * Order IDs that got a shipment email this request (so the generic
	 * "order complete" email isn't sent as well).
	 *
	 * @var int[]
	 */
	protected static $notified = array();

	/**
	 * Order IDs completed by a shipment in this request (the status dropdown
	 * on the same screen must not undo it).
	 *
	 * @var int[]
	 */
	protected static $completed = array();

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . Post_Types::ORDER, array( $this, 'meta_box' ) );
			add_action( 'save_post_' . Post_Types::ORDER, array( $this, 'save' ), 5 ); // Before the status save (10).
		}
		add_action( 'sidcraft_commerce_order_confirmation_after', array( $this, 'show_on_confirmation' ) );
	}

	/*
	------------------------------------------------------------------
	 * Carriers
	 * ----------------------------------------------------------------
	 */

	/**
	 * Built-in carriers grouped by region: group => [ slug => [ name, url ] ].
	 *
	 * @return array
	 */
	public static function builtin() {
		$carriers = array(
			__( 'Australia', 'sidcraft-commerce-for-sidcraft-page-builder' )      => array(
				'australia-post' => array( 'Australia Post', 'https://auspost.com.au/mypost/track/details/{tracking_number}' ),
			),
			__( 'Canada', 'sidcraft-commerce-for-sidcraft-page-builder' )         => array(
				'canada-post' => array( 'Canada Post', 'https://www.canadapost-postescanada.ca/track-reperage/en#/details/{tracking_number}' ),
			),
			__( 'Malaysia', 'sidcraft-commerce-for-sidcraft-page-builder' )       => array(
				'city-link' => array( 'City-Link Express', 'https://www.citylinkexpress.com/tracking-result/?track0={tracking_number}' ),
			),
			__( 'Poland', 'sidcraft-commerce-for-sidcraft-page-builder' )         => array(
				'poczta-polska' => array( 'Poczta Polska', 'https://emonitoring.poczta-polska.pl/?numer={tracking_number}' ),
				'inpost'        => array( 'InPost', 'https://inpost.pl/sledzenie-przesylek?number={tracking_number}' ),
				'dpd-pl'        => array( 'DPD Polska', 'https://tracktrace.dpd.com.pl/parcelDetails?typ=1&p1={tracking_number}' ),
				'dhl-pl'        => array( 'DHL Parcel Polska', 'https://www.dhl.com/pl-pl/home/sledzenie-przesylek.html?tracking-id={tracking_number}&submit=1' ),
				'gls-pl'        => array( 'GLS Poland', 'https://gls-group.com/PL/pl/sledzenie-paczek?match={tracking_number}' ),
			),
			__( 'South Africa', 'sidcraft-commerce-for-sidcraft-page-builder' )   => array(
				'fastway-za' => array( 'Fastway South Africa', 'https://www.fastway.co.za/our-services/track-your-parcel?l={tracking_number}' ),
				'sapo'       => array( 'SAPO (South African Post Office)', 'https://tracking.postoffice.co.za/TrackNTrace/TrackNTrace.aspx?id={tracking_number}' ),
			),
			__( 'United Kingdom', 'sidcraft-commerce-for-sidcraft-page-builder' ) => array(
				'royal-mail'  => array( 'Royal Mail', 'https://www.royalmail.com/track-your-item#/tracking-results/{tracking_number}' ),
				'parcelforce' => array( 'Parcelforce', 'https://www.parcelforce.com/track-trace?trackNumber={tracking_number}' ),
				'dpd-uk'      => array( 'DPD (UK)', 'https://www.dpd.co.uk/service/tracking?consignment={tracking_number}' ),
			),
			__( 'United States', 'sidcraft-commerce-for-sidcraft-page-builder' )  => array(
				'usps'   => array( 'USPS', 'https://tools.usps.com/go/TrackConfirmAction?tLabels={tracking_number}' ),
				'ontrac' => array( 'OnTrac', 'https://www.ontrac.com/tracking/?number={tracking_number}' ),
			),
			__( 'Worldwide', 'sidcraft-commerce-for-sidcraft-page-builder' )      => array(
				'dhl'      => array( 'DHL', 'https://www.dhl.com/global-en/home/tracking.html?tracking-id={tracking_number}&submit=1' ),
				'dpd'      => array( 'DPD', 'https://tracking.dpd.de/status/en_US/parcel/{tracking_number}' ),
				'fedex'    => array( 'FedEx', 'https://www.fedex.com/fedextrack/?trknbr={tracking_number}' ),
				'tnt-con'  => array( 'TNT Express (consignment)', 'https://www.tnt.com/express/en_gb/site/shipping-tools/tracking.html?searchType=con&cons={tracking_number}' ),
				'tnt-ref'  => array( 'TNT Express (reference)', 'https://www.tnt.com/express/en_gb/site/shipping-tools/tracking.html?searchType=ref&cons={tracking_number}' ),
				'ups'      => array( 'UPS', 'https://www.ups.com/track?tracknum={tracking_number}' ),
			),
		);

		/**
		 * Filter the built-in carriers: group => [ slug => [ name, url ] ].
		 * In the URL, {tracking_number} and {postcode} are replaced.
		 *
		 * @param array $carriers Carriers.
		 */
		return (array) apply_filters( 'sidcraft_commerce_tracking_providers', $carriers );
	}

	/**
	 * All carriers, grouped, with the store's own carriers (Settings) added
	 * under "Your carriers" and replacing built-in links of the same name.
	 *
	 * @return array group => [ slug => [ name, url ] ].
	 */
	public static function carriers() {
		$groups = self::builtin();
		$own    = array();
		foreach ( (array) setting( 'tracking_carriers', array() ) as $row ) {
			$name = trim( (string) ( $row['name'] ?? '' ) );
			$url  = trim( (string) ( $row['url'] ?? '' ) );
			if ( '' === $name || '' === $url ) {
				continue;
			}
			$replaced = false;
			foreach ( $groups as $group => $list ) {
				foreach ( $list as $slug => $carrier ) {
					if ( 0 === strcasecmp( $carrier[0], $name ) ) {
						$groups[ $group ][ $slug ][1] = $url;
						$replaced                      = true;
					}
				}
			}
			if ( ! $replaced ) {
				$own[ 'own-' . sanitize_title( $name ) ] = array( $name, $url );
			}
		}
		if ( $own ) {
			$groups = array( __( 'Your carriers', 'sidcraft-commerce-for-sidcraft-page-builder' ) => $own ) + $groups;
		}
		return $groups;
	}

	/**
	 * One carrier by slug.
	 *
	 * @param string $slug Slug.
	 * @return array|null [ name, url ].
	 */
	public static function carrier( $slug ) {
		foreach ( self::carriers() as $list ) {
			if ( isset( $list[ $slug ] ) ) {
				return $list[ $slug ];
			}
		}
		return null;
	}

	/**
	 * Fill a link template.
	 *
	 * @param string $template URL with {tracking_number} / {postcode} (or %1$s / %2$s).
	 * @param string $number   Tracking number.
	 * @param string $postcode Postcode.
	 * @return string
	 */
	public static function fill( $template, $number, $postcode = '' ) {
		$number   = rawurlencode( trim( (string) $number ) );
		$postcode = rawurlencode( strtoupper( str_replace( ' ', '', (string) $postcode ) ) );
		return str_replace( array( '{tracking_number}', '{postcode}', '%1$s', '%2$s' ), array( $number, $postcode, $number, $postcode ), (string) $template );
	}

	/**
	 * Tracking link for a stored shipment.
	 *
	 * @param array $shipment Shipment.
	 * @param Order $order    Order (postcode).
	 * @return string '' when there is no link.
	 */
	public static function tracking_url( array $shipment, Order $order ) {
		$billing  = $order->get_billing();
		$postcode = (string) ( $billing['postcode'] ?? '' );
		$template = '';
		if ( ! empty( $shipment['url'] ) ) {
			$template = (string) $shipment['url'];
		} elseif ( ! empty( $shipment['carrier'] ) ) {
			$carrier  = self::carrier( (string) $shipment['carrier'] );
			$template = $carrier ? $carrier[1] : '';
		}
		$url = '' !== $template ? self::fill( $template, $shipment['number'] ?? '', $postcode ) : '';

		/**
		 * Filter a shipment's tracking link.
		 *
		 * @param string $url      Link.
		 * @param array  $shipment Shipment.
		 * @param Order  $order    Order.
		 */
		return esc_url_raw( (string) apply_filters( 'sidcraft_commerce_tracking_url', $url, $shipment, $order ) );
	}

	/*
	------------------------------------------------------------------
	 * Storage
	 * ----------------------------------------------------------------
	 */

	/**
	 * Shipments on an order, each with its resolved tracking link.
	 *
	 * @param Order $order Order.
	 * @return array[]
	 */
	public static function get( Order $order ) {
		$list = get_post_meta( $order->get_id(), self::META, true );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $shipment ) {
			if ( ! is_array( $shipment ) || empty( $shipment['number'] ) ) {
				continue;
			}
			$shipment['tracking_url'] = self::tracking_url( $shipment, $order );
			$out[]                    = $shipment;
		}
		return $out;
	}

	/**
	 * Add a shipment.
	 *
	 * @param Order $order    Order.
	 * @param array $data     carrier (slug) | carrier_name + url (custom), number, date (Y-m-d).
	 * @param bool  $notify   Email the customer.
	 * @param bool  $complete Mark the order Completed.
	 * @return array|\WP_Error The stored shipment.
	 */
	public static function add( Order $order, array $data, $notify = true, $complete = true ) {
		$number = trim( sanitize_text_field( (string) ( $data['number'] ?? '' ) ) );
		if ( '' === $number ) {
			return new \WP_Error( 'sidccofo_tracking_number', __( 'Enter a tracking number.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$slug    = sanitize_key( (string) ( $data['carrier'] ?? '' ) );
		$carrier = $slug ? self::carrier( $slug ) : null;
		if ( $carrier ) {
			$name = $carrier[0];
			$url  = '';
		} else {
			$slug = '';
			$name = trim( sanitize_text_field( (string) ( $data['carrier_name'] ?? '' ) ) );
			$url  = trim( (string) ( $data['url'] ?? '' ) );
			$url  = str_replace( array( '{tracking_number}', '{postcode}' ), array( 'SIDCCOFONUM', 'SIDCCOFOPC' ), $url );
			$url  = str_replace( array( 'SIDCCOFONUM', 'SIDCCOFOPC' ), array( '{tracking_number}', '{postcode}' ), esc_url_raw( $url, array( 'http', 'https' ) ) );
			if ( '' === $name ) {
				return new \WP_Error( 'sidccofo_tracking_carrier', __( 'Choose a carrier, or enter the carrier’s name for a custom link.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			}
		}
		$date = (string) ( $data['date'] ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = wp_date( 'Y-m-d' );
		}

		$shipment = array(
			'id'           => strtolower( wp_generate_password( 10, false ) ),
			'carrier'      => $slug,
			'carrier_name' => $name,
			'number'       => $number,
			'url'          => $url,
			'date'         => $date,
			'added'        => time(),
		);
		$list     = get_post_meta( $order->get_id(), self::META, true );
		$list     = is_array( $list ) ? $list : array();
		$list[]   = $shipment;
		update_post_meta( $order->get_id(), self::META, $list );

		/* translators: 1: carrier, 2: tracking number. */
		$order->add_note( sprintf( __( 'Shipped with %1$s, tracking number %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $name, $number ) );

		$shipment['tracking_url'] = self::tracking_url( $shipment, $order );
		if ( $notify ) {
			self::$notified[] = $order->get_id();
		}
		if ( $complete && ! in_array( $order->get_status(), array( 'sidccofo-completed', 'sidccofo-cancelled', 'sidccofo-refunded' ), true ) ) {
			$order->set_status( 'sidccofo-completed', __( 'Shipped.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			self::$completed[] = $order->get_id();
		}
		if ( $notify ) {
			Plugin::instance()->emails->send_shipment( $order, $shipment );
		}

		/**
		 * Fires after a shipment is added to an order.
		 *
		 * @param array $shipment Shipment (with tracking_url).
		 * @param Order $order    Order.
		 * @param bool  $notify   Customer emailed.
		 */
		do_action( 'sidcraft_commerce_shipment_added', $shipment, $order, $notify );
		return $shipment;
	}

	/**
	 * Remove a shipment by ID.
	 *
	 * @param Order  $order Order.
	 * @param string $id    Shipment ID.
	 */
	public static function remove( Order $order, $id ) {
		$list = get_post_meta( $order->get_id(), self::META, true );
		$keep = array();
		foreach ( is_array( $list ) ? $list : array() as $shipment ) {
			if ( ( $shipment['id'] ?? '' ) === $id ) {
				/* translators: %s: tracking number. */
				$order->add_note( sprintf( __( 'Tracking number %s removed.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $shipment['number'] ?? '' ) );
				continue;
			}
			$keep[] = $shipment;
		}
		update_post_meta( $order->get_id(), self::META, $keep );
	}

	/**
	 * Was this order marked Completed by a shipment in this request?
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public static function completed_now( $order_id ) {
		return in_array( (int) $order_id, self::$completed, true );
	}

	/**
	 * Did this order just get a shipment email?
	 *
	 * @param Order $order Order.
	 * @return bool
	 */
	public static function was_notified( Order $order ) {
		return in_array( $order->get_id(), self::$notified, true );
	}

	/*
	------------------------------------------------------------------
	 * Admin: order screen
	 * ----------------------------------------------------------------
	 */

	/**
	 * Register the box.
	 */
	public function meta_box() {
		add_meta_box( 'sidccofo-order-shipments', __( 'Shipment tracking', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_box' ), Post_Types::ORDER, 'normal', 'default' );
	}

	/**
	 * Box: existing shipments + add form.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_box( $post ) {
		$order = Order::get( $post->ID );
		if ( ! $order ) {
			return;
		}
		wp_nonce_field( 'sidccofo_shipments', '_sidccofo_shipments_nonce' );
		$shipments = self::get( $order );

		if ( $shipments ) {
			echo '<table class="widefat striped sc-ship-table"><thead><tr><th>' . esc_html__( 'Carrier', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Tracking number', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Shipped', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Remove', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th></tr></thead><tbody>';
			foreach ( $shipments as $s ) {
				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td><label><input type="checkbox" name="sidccofo_ship_remove[]" value="%4$s"> <span class="screen-reader-text">%5$s</span></label></td></tr>',
					esc_html( $s['carrier_name'] ),
					$s['tracking_url'] ? '<a href="' . esc_url( $s['tracking_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $s['number'] ) . '</a>' : esc_html( $s['number'] ),
					esc_html( date_i18n( get_option( 'date_format' ), strtotime( $s['date'] ) ) ),
					esc_attr( $s['id'] ),
					esc_html__( 'Remove this tracking number', 'sidcraft-commerce-for-sidcraft-page-builder' )
				);
			}
			echo '</tbody></table>';
		}

		echo '<div class="sc-ship-form">';
		echo '<p><label for="sidccofo-ship-carrier">' . esc_html__( 'Carrier', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><select id="sidccofo-ship-carrier" name="sidccofo_ship[carrier]" data-sc-ship-carrier>';
		echo '<option value="">' . esc_html__( '— Choose —', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</option>';
		foreach ( self::carriers() as $group => $list ) {
			echo '<optgroup label="' . esc_attr( $group ) . '">';
			foreach ( $list as $slug => $carrier ) {
				echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $carrier[0] ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '<option value="custom">' . esc_html__( 'Other carrier (custom link)…', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</option></select></p>';
		echo '<p><label for="sidccofo-ship-number">' . esc_html__( 'Tracking number', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><input type="text" id="sidccofo-ship-number" name="sidccofo_ship[number]" autocomplete="off"></p>';
		echo '<p><label for="sidccofo-ship-date">' . esc_html__( 'Date shipped', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><input type="date" id="sidccofo-ship-date" name="sidccofo_ship[date]" value="' . esc_attr( wp_date( 'Y-m-d' ) ) . '"></p>';
		echo '<p class="sc-ship-custom" data-sc-ship-custom hidden><label for="sidccofo-ship-name">' . esc_html__( 'Carrier name', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><input type="text" id="sidccofo-ship-name" name="sidccofo_ship[carrier_name]"></p>';
		echo '<p class="sc-ship-custom sc-ship-wide" data-sc-ship-custom hidden><label for="sidccofo-ship-url">' . esc_html__( 'Tracking link', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><input type="url" id="sidccofo-ship-url" name="sidccofo_ship[url]" placeholder="https://carrier.example/track?no={tracking_number}"><span class="description">' . esc_html__( 'The full link to the tracking page. Use {tracking_number} where the number goes, or paste the link for this one parcel.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></p>';
		printf(
			'<p class="sc-ship-wide"><label class="sc-pd__check"><input type="checkbox" name="sidccofo_ship[notify]" value="1" %1$s> %2$s</label> <label class="sc-pd__check"><input type="checkbox" name="sidccofo_ship[complete]" value="1" %3$s> %4$s</label></p>',
			checked( (bool) setting( 'tracking_notify', 1 ), true, false ),
			esc_html__( 'Email the customer that the order has shipped', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			checked( (bool) setting( 'tracking_complete', 1 ), true, false ),
			esc_html__( 'Mark the order Completed', 'sidcraft-commerce-for-sidcraft-page-builder' )
		);
		echo '<p class="sc-ship-wide description">' . esc_html__( 'Fill in the tracking number and click Update order.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Save from the order screen (runs before the status change is saved).
	 *
	 * @param int $post_id Post ID.
	 */
	public function save( $post_id ) {
		if ( Order::$updating || ! isset( $_POST['_sidccofo_shipments_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_shipments_nonce'] ) ), 'sidccofo_shipments' ) || ! current_user_can( manage_cap() ) ) {
			return;
		}
		$order = Order::get( $post_id );
		if ( ! $order ) {
			return;
		}

		$remove = isset( $_POST['sidccofo_ship_remove'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['sidccofo_ship_remove'] ) ) : array();
		foreach ( $remove as $id ) {
			self::remove( $order, preg_replace( '/[^A-Za-z0-9]/', '', $id ) );
		}

		$raw = array();
		if ( isset( $_POST['sidccofo_ship'] ) && is_array( $_POST['sidccofo_ship'] ) ) {
			$posted = wp_unslash( $_POST['sidccofo_ship'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field below.
			// The URL keeps its {tracking_number} placeholders here; add() runs esc_url_raw() around them.
			foreach ( array( 'carrier', 'carrier_name', 'url', 'number', 'date', 'complete', 'notify' ) as $key ) {
				$raw[ $key ] = isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ? sanitize_text_field( (string) $posted[ $key ] ) : '';
			}
		}
		if ( '' === trim( $raw['number'] ?? '' ) ) {
			return;
		}
		$complete = ! empty( $raw['complete'] );
		$result   = self::add(
			$order,
			array(
				'carrier'      => 'custom' === ( $raw['carrier'] ?? '' ) ? '' : (string) ( $raw['carrier'] ?? '' ),
				'carrier_name' => (string) ( $raw['carrier_name'] ?? '' ),
				'url'          => (string) ( $raw['url'] ?? '' ),
				'number'       => (string) $raw['number'],
				'date'         => (string) ( $raw['date'] ?? '' ),
			),
			! empty( $raw['notify'] ),
			$complete
		);
		if ( is_wp_error( $result ) ) {
			set_transient( 'sidccofo_ship_error_' . get_current_user_id(), $result->get_error_message(), 60 );
		}
	}

	/*
	------------------------------------------------------------------
	 * Front end
	 * ----------------------------------------------------------------
	 */

	/**
	 * Tracking on the order-received page.
	 *
	 * @param Order $order Order.
	 */
	public function show_on_confirmation( $order ) {
		if ( ! $order instanceof Order ) {
			return;
		}
		$shipments = self::get( $order );
		if ( $shipments ) {
			echo Plugin::instance()->templates->render( 'units/shipments.php', array( 'shipments' => $shipments ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in template.
		}
	}
}
