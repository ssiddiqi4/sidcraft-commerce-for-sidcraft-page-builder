<?php
/**
 * Events admin: the event screen (details, tickets, attendee fields), the
 * Attendees list with CSV export, the Check-in scanner, the order screen's
 * tickets box and the Events settings tab.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use function SidcraftCommerce\currency_symbol;
use function SidcraftCommerce\manage_cap;
use function SidcraftCommerce\parse_price;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'classic_editor' ), 10, 2 );
		add_action( 'edit_form_after_title', array( $this, 'boxes_after_title' ) );
		add_action( 'add_meta_boxes_' . Events::EVENT, array( $this, 'event_boxes' ) );
		add_action( 'save_post_' . Events::EVENT, array( $this, 'save_event' ), 10, 2 );
		add_filter( 'manage_' . Events::EVENT . '_posts_columns', array( $this, 'event_columns' ) );
		add_action( 'manage_' . Events::EVENT . '_posts_custom_column', array( $this, 'event_column' ), 10, 2 );
		add_filter( 'manage_edit-' . Events::EVENT . '_sortable_columns', array( $this, 'event_sortable' ) );
		add_action( 'pre_get_posts', array( $this, 'event_list_order' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );

		add_filter( 'manage_' . Events::TICKET . '_posts_columns', array( $this, 'ticket_columns' ) );
		add_action( 'manage_' . Events::TICKET . '_posts_custom_column', array( $this, 'ticket_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'ticket_filters' ) );
		add_action( 'pre_get_posts', array( $this, 'ticket_query' ) );
		add_action( 'add_meta_boxes_' . Events::TICKET, array( $this, 'ticket_boxes' ) );
		add_action( 'save_post_' . Events::TICKET, array( $this, 'save_ticket' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . Events::TICKET, array( $this, 'ticket_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . Events::TICKET, array( $this, 'ticket_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'notices' ) );

		add_action( 'add_meta_boxes_' . Post_Types::ORDER, array( $this, 'order_box' ), 20 );
		add_action( 'admin_post_sidccofo_ev_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_sidccofo_ev_export', array( $this, 'export' ) );

		add_filter( 'sidcraft_commerce_settings_sections', array( $this, 'settings' ) );
		add_filter( 'sidcraft_commerce_settings_tabs', array( $this, 'settings_tab' ) );
	}

	/*
	------------------------------------------------------------------
	 * Menu and assets
	 * ----------------------------------------------------------------
	 */

	/**
	 * Check-in and Settings under Events.
	 */
	public function menu() {
		$parent = 'edit.php?post_type=' . Events::EVENT;
		add_submenu_page( $parent, __( 'Check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ), current_user_can( manage_cap() ) ? manage_cap() : 'sidccofo_check_in', 'sidccofo-checkin', array( $this, 'checkin_page' ) );
		add_submenu_page( $parent, __( 'Event settings', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Settings', 'sidcraft-commerce-for-sidcraft-page-builder' ), manage_cap(), 'edit.php?post_type=' . Post_Types::PRODUCT . '&page=sidccofo-settings&tab=events' );
	}

	/**
	 * Admin CSS/JS on event, ticket, order and check-in screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		$screen = get_current_screen();
		$ours   = $screen && ( in_array( $screen->post_type, array( Events::EVENT, Events::TICKET, Post_Types::ORDER ), true ) || false !== strpos( (string) $hook, 'sidccofo-checkin' ) );
		if ( ! $ours ) {
			return;
		}
		$url = SIDCRAFT_COMMERCE_URL . 'assets/';
		wp_enqueue_style( 'sidccofo-admin', $url . 'css/sc-admin.css', array(), SIDCRAFT_COMMERCE_VERSION );
		wp_enqueue_style( 'sidccofo-events-admin', $url . 'css/sc-events-admin.css', array( 'sidccofo-admin' ), SIDCRAFT_COMMERCE_VERSION );
		wp_enqueue_script( 'sidccofo-events-admin', $url . 'js/sc-events-admin.js', array(), SIDCRAFT_COMMERCE_VERSION, true );
		wp_localize_script(
			'sidccofo-events-admin',
			'sidccofoEventsAdmin',
			array(
				'api'   => esc_url_raw( rest_url( \SidcraftCommerce\Rest::NS . '/events/checkin' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'map'   => array(
					'provider' => (string) \SidcraftCommerce\setting( 'ev_map_provider', 'google' ),
					'key'      => trim( (string) \SidcraftCommerce\setting( 'ev_google_maps_key', '' ) ),
				),
				'i18n'  => array(
					'remove'      => __( 'Remove this ticket type? Orders already placed keep their tickets.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'scanning'    => __( 'Point the camera at a ticket QR code…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'noCamera'    => __( 'This browser can’t scan with the camera. Type the code, use a USB scanner, or scan the QR code with your phone’s camera app (it opens the ticket page with a Check in button).', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'checking'    => __( 'Checking…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'undo'        => __( 'Undo', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'error'       => __( 'Could not reach the site. Check the connection and try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'startCamera' => __( 'Scan with camera', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'stopCamera'  => __( 'Stop camera', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * Event screen
	 * ----------------------------------------------------------------
	 */

	/**
	 * Events use the WordPress classic editor screen. In the block editor the
	 * event form ends up in a collapsed panel under an empty canvas, so a new
	 * event looked like a blank page.
	 *
	 * @param bool   $use       Use the block editor.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function classic_editor( $use, $post_type ) {
		if ( Events::EVENT !== $post_type ) {
			return $use;
		}

		/**
		 * Edit events in the block editor instead (the event form then sits
		 * in the meta box panel below the canvas).
		 *
		 * @param bool $use Default false.
		 */
		return (bool) apply_filters( 'sidcraft_commerce_event_block_editor', false );
	}

	/**
	 * Event details straight under the title, above the description.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function boxes_after_title( $post ) {
		if ( Events::EVENT !== $post->post_type ) {
			return;
		}
		do_meta_boxes( get_current_screen(), 'sidccofo_after_title', $post );
	}

	/**
	 * Meta boxes.
	 */
	public function event_boxes() {
		add_meta_box( 'sidccofo-ev-details', __( 'Event details', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'details_box' ), Events::EVENT, 'sidccofo_after_title', 'high' );
		add_meta_box( 'sidccofo-ev-tickets', __( 'Tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'tickets_box' ), Events::EVENT, 'normal', 'high' );
		add_meta_box( 'sidccofo-ev-attendees', __( 'Attendee information', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'attendees_box' ), Events::EVENT, 'normal', 'default' );
		add_meta_box( 'sidccofo-ev-location', __( 'Location preview', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'location_box' ), Events::EVENT, 'side', 'high' );
		add_meta_box( 'sidccofo-ev-sales', __( 'Sales & check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'sales_box' ), Events::EVENT, 'side', 'high' );
	}

	/**
	 * Stored local date-time to a datetime-local value.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	protected static function dt_value( $v ) {
		$v = (string) $v;
		return strlen( $v ) >= 16 ? substr( $v, 0, 10 ) . 'T' . substr( $v, 11, 5 ) : ( 10 === strlen( $v ) ? $v . 'T00:00' : '' );
	}

	/**
	 * Date, place, online, organiser, registration.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function details_box( $post ) {
		wp_nonce_field( 'sidccofo_save_event', '_sidccofo_event_nonce' );
		$m = static function ( $key ) use ( $post ) {
			return (string) get_post_meta( $post->ID, '_sidccofo_ev_' . $key, true );
		};
		$check = static function ( $key, $default = false ) use ( $post ) {
			$v = get_post_meta( $post->ID, '_sidccofo_ev_' . $key, true );
			return '' === $v ? $default : (bool) $v;
		};
		$zoom_error = $m( 'zoom_error' );
		?>
		<h4 class="sc-pd__heading"><?php esc_html_e( 'Date and time', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd">
			<p><label for="sc-ev-start"><?php esc_html_e( 'Starts', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="datetime-local" id="sc-ev-start" name="sidccofo_ev[start]" value="<?php echo esc_attr( self::dt_value( $m( 'start' ) ) ); ?>" required></p>
			<p><label for="sc-ev-end"><?php esc_html_e( 'Ends', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="datetime-local" id="sc-ev-end" name="sidccofo_ev[end]" value="<?php echo esc_attr( self::dt_value( $m( 'end' ) ) ); ?>"></p>
			<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_ev[all_day]" value="1" <?php checked( $check( 'all_day' ) ); ?>> <?php esc_html_e( 'All-day event', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
			<p class="sc-pd__wide description">
				<?php
				/* translators: %s: time zone. */
				echo esc_html( sprintf( __( 'Times are in the site time zone (%s), set under Settings → General.', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_timezone_string() ) );
				?>
			</p>
		</div>

		<h4 class="sc-pd__heading"><?php esc_html_e( 'Venue', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd">
			<p><label for="sc-ev-venue"><?php esc_html_e( 'Venue name', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-ev-venue" name="sidccofo_ev[venue]" value="<?php echo esc_attr( $m( 'venue' ) ); ?>"></p>
			<p class="sc-pd__span2"><label for="sc-ev-address"><?php esc_html_e( 'Address', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-ev-address" name="sidccofo_ev[address]" value="<?php echo esc_attr( $m( 'address' ) ); ?>" placeholder="<?php esc_attr_e( 'Street, city, country', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></p>
			<p><label for="sc-ev-lat"><?php esc_html_e( 'Latitude (optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-ev-lat" name="sidccofo_ev[lat]" value="<?php echo esc_attr( $m( 'lat' ) ); ?>" placeholder="42.5048"></p>
			<p><label for="sc-ev-lng"><?php esc_html_e( 'Longitude (optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-ev-lng" name="sidccofo_ev[lng]" value="<?php echo esc_attr( $m( 'lng' ) ); ?>" placeholder="-71.1956"></p>
			<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_ev[show_map]" value="1" <?php checked( $check( 'show_map', true ) ); ?>> <?php esc_html_e( 'Show a map on the event page', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
			<p class="sc-pd__wide description"><?php esc_html_e( 'The map uses the address; coordinates pin it exactly (and are needed for OpenStreetMap).', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		</div>

		<h4 class="sc-pd__heading"><?php esc_html_e( 'Online', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd" data-sc-ev-online>
			<p class="sc-pd__wide"><label class="sc-pd__check sc-pd__check--top"><input type="checkbox" name="sidccofo_ev[online]" value="1" <?php checked( $check( 'online' ) ); ?> data-sc-ev-online-toggle> <?php esc_html_e( 'Online or hybrid event (join details go to ticket holders only)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
			<p class="sc-pd__span2" data-sc-ev-online-field><label for="sc-ev-zoom-url"><?php esc_html_e( 'Zoom meeting link', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="url" id="sc-ev-zoom-url" name="sidccofo_ev[zoom_url]" value="<?php echo esc_attr( $m( 'zoom_url' ) ); ?>" placeholder="https://zoom.us/j/…"></p>
			<p data-sc-ev-online-field><label for="sc-ev-zoom-id"><?php esc_html_e( 'Meeting ID', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-ev-zoom-id" name="sidccofo_ev[zoom_id]" value="<?php echo esc_attr( $m( 'zoom_id' ) ); ?>"></p>
			<p data-sc-ev-online-field><label for="sc-ev-zoom-pass"><?php esc_html_e( 'Passcode', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-ev-zoom-pass" name="sidccofo_ev[zoom_passcode]" value="<?php echo esc_attr( $m( 'zoom_passcode' ) ); ?>"></p>
			<p class="sc-pd__wide" data-sc-ev-online-field>
				<label class="sc-pd__check sc-pd__check--top"><input type="checkbox" name="sidccofo_ev[zoom_auto]" value="1" <?php checked( $check( 'zoom_auto' ) ); ?> <?php disabled( ! Integrations::zoom_ready() && ! $check( 'zoom_auto' ) ); ?>> <?php esc_html_e( 'Create the Zoom meeting for me (and keep its time in sync)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<?php if ( ! Integrations::zoom_ready() ) : ?>
					<span class="description"><?php esc_html_e( 'Add your Zoom Server-to-Server OAuth app under Events → Settings to turn this on.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $zoom_error ) : ?>
					<span class="description sc-ev-error">
						<?php
						/* translators: %s: error message. */
						echo esc_html( sprintf( __( 'Zoom: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $zoom_error ) );
						?>
					</span>
				<?php endif; ?>
			</p>
			<p class="sc-pd__span2" data-sc-ev-online-field><label for="sc-ev-online-url"><?php esc_html_e( 'Other join link (Google Meet, Teams, YouTube…)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="url" id="sc-ev-online-url" name="sidccofo_ev[online_url]" value="<?php echo esc_attr( $m( 'online_url' ) ); ?>"></p>
		</div>

		<h4 class="sc-pd__heading"><?php esc_html_e( 'Organiser and WhatsApp', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd">
			<p><label for="sc-ev-org"><?php esc_html_e( 'Organiser', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-ev-org" name="sidccofo_ev[organizer]" value="<?php echo esc_attr( $m( 'organizer' ) ); ?>"></p>
			<p><label for="sc-ev-org-email"><?php esc_html_e( 'Organiser email', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="email" id="sc-ev-org-email" name="sidccofo_ev[organizer_email]" value="<?php echo esc_attr( $m( 'organizer_email' ) ); ?>"></p>
			<p><label for="sc-ev-wa"><?php esc_html_e( 'WhatsApp number (chat button)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="tel" id="sc-ev-wa" name="sidccofo_ev[whatsapp_number]" value="<?php echo esc_attr( $m( 'whatsapp_number' ) ); ?>" placeholder="+1 617 555 0100"></p>
			<p><label for="sc-ev-wa-group"><?php esc_html_e( 'WhatsApp group invite link (ticket holders)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="url" id="sc-ev-wa-group" name="sidccofo_ev[whatsapp_group]" value="<?php echo esc_attr( $m( 'whatsapp_group' ) ); ?>" placeholder="https://chat.whatsapp.com/…"></p>
		</div>

		<h4 class="sc-pd__heading"><?php esc_html_e( 'Registration', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h4>
		<div class="sc-pd">
			<p><label for="sc-ev-cap"><?php esc_html_e( 'Event capacity (all ticket types)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="number" min="0" step="1" id="sc-ev-cap" name="sidccofo_ev[capacity]" value="<?php echo esc_attr( (string) (int) $m( 'capacity' ) ); ?>">
				<span class="description"><?php esc_html_e( '0 = no event-wide limit. Ticket types can have their own limits too.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_ev[close_at_start]" value="1" <?php checked( $check( 'close_at_start', true ) ); ?>> <?php esc_html_e( 'Stop selling when the event starts', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<span class="description"><?php esc_html_e( 'Ticket types with their own "Sales end" sell until that date instead.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
			<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_ev[show_remaining]" value="1" <?php checked( $check( 'show_remaining', (bool) \SidcraftCommerce\setting( 'ev_show_remaining', 1 ) ) ); ?>> <?php esc_html_e( 'Show how many tickets are left', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
			<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_ev[hide_auto]" value="1" <?php checked( $check( 'hide_auto' ) ); ?>> <?php esc_html_e( 'Hide the automatic details and tickets (layout built with Sidcraft Page Builder)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
		</div>
		<?php
	}

	/**
	 * One ticket type card.
	 *
	 * @param string $i    Row index.
	 * @param array  $type Ticket type.
	 * @param Event|null $event Event (for held counts).
	 */
	protected function ticket_row( $i, array $type, $event = null ) {
		$n    = 'sidccofo_ev_tickets[' . $i . ']';
		$sym  = trim( currency_symbol() );
		$held = $type['product_id'] ? Placeholders::held( $type['product_id'] ) : 0;
		$bad  = Pricing::invalid_lines( $type['rules'] );
		?>
		<div class="sc-ev-row" data-sc-ev-row>
			<input type="hidden" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo esc_attr( $type['id'] ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $n ); ?>[product_id]" value="<?php echo (int) $type['product_id']; ?>">
			<div class="sc-ev-row__head">
				<strong data-sc-ev-row-title><?php echo esc_html( '' !== $type['name'] ? $type['name'] : __( 'New ticket type', 'sidcraft-commerce-for-sidcraft-page-builder' ) ); ?></strong>
				<?php if ( $type['product_id'] ) : ?>
					<span class="sc-ev-row__sold">
						<?php
						/* translators: 1: tickets held by orders, 2: capacity or ∞. */
						echo esc_html( sprintf( __( '%1$d sold / %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $held, $type['capacity'] ? (string) $type['capacity'] : '∞' ) );
						?>
					</span>
				<?php endif; ?>
				<button type="button" class="button-link sc-ev-row__remove" data-sc-ev-remove aria-label="<?php esc_attr_e( 'Remove ticket type', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">✕</button>
			</div>
			<div class="sc-pd">
				<p><label><?php esc_html_e( 'Ticket name', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
					<input type="text" name="<?php echo esc_attr( $n ); ?>[name]" value="<?php echo esc_attr( $type['name'] ); ?>" placeholder="<?php esc_attr_e( 'General admission', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" data-sc-ev-name></label></p>
				<p><label><?php esc_html_e( 'Attendee type', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
					<input type="text" name="<?php echo esc_attr( $n ); ?>[attendee_type]" value="<?php echo esc_attr( $type['attendee_type'] ); ?>" placeholder="<?php esc_attr_e( 'Adult, Child, Student, VIP…', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" list="sc-ev-attendee-types"></label></p>
				<p><label><?php echo esc_html( sprintf( /* translators: %s: currency symbol. */ __( 'Price (%s)', 'sidcraft-commerce-for-sidcraft-page-builder' ), $sym ) ); ?>
					<input type="text" inputmode="decimal" name="<?php echo esc_attr( $n ); ?>[price]" value="<?php echo esc_attr( $type['price'] ); ?>" placeholder="<?php esc_attr_e( '0 = free', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Tickets available', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
					<input type="number" min="0" step="1" name="<?php echo esc_attr( $n ); ?>[capacity]" value="<?php echo esc_attr( (string) $type['capacity'] ); ?>" placeholder="<?php esc_attr_e( '0 = no limit', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Min per order', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
					<input type="number" min="0" step="1" name="<?php echo esc_attr( $n ); ?>[min]" value="<?php echo esc_attr( (string) $type['min'] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Max per order', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
					<input type="number" min="0" step="1" name="<?php echo esc_attr( $n ); ?>[max]" value="<?php echo esc_attr( (string) $type['max'] ); ?>" placeholder="<?php esc_attr_e( '0 = no limit', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Sales start', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
					<input type="datetime-local" name="<?php echo esc_attr( $n ); ?>[sale_start]" value="<?php echo esc_attr( self::dt_value( $type['sale_start'] ) ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Sales end', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
					<input type="datetime-local" name="<?php echo esc_attr( $n ); ?>[sale_end]" value="<?php echo esc_attr( self::dt_value( $type['sale_end'] ) ); ?>"></label></p>
				<p class="sc-pd__wide"><label><?php esc_html_e( 'Description', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
					<input type="text" name="<?php echo esc_attr( $n ); ?>[description]" value="<?php echo esc_attr( $type['description'] ); ?>" placeholder="<?php esc_attr_e( 'What this ticket includes', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></label></p>
				<p class="sc-pd__wide"><label><?php esc_html_e( 'Dynamic pricing (optional, one rule per line)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>
					<textarea name="<?php echo esc_attr( $n ); ?>[rules]" rows="3" class="large-text code" placeholder="<?php echo esc_attr( "until 2026-11-01: 20 Early bird\nafter 75%: 35\nqty 5: 18 Group rate" ); ?>"><?php echo esc_textarea( $type['rules'] ); ?></textarea></label>
					<?php if ( $bad ) : ?>
						<span class="description sc-ev-error">
							<?php
							/* translators: %s: lines. */
							echo esc_html( sprintf( __( 'Not understood: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), implode( ' | ', $bad ) ) );
							?>
						</span>
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Ticket types.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function tickets_box( $post ) {
		$event = Event::get( $post->ID );
		$types = $event ? array_values( $event->get_ticket_types() ) : array();
		if ( ! $types && 'auto-draft' === $post->post_status ) {
			$types[] = Event::normalize_ticket_type(
				array(
					'id'   => '',
					'name' => __( 'General admission', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				)
			);
		}
		echo '<div class="sc-ev-rows" data-sc-ev-rows="tickets">';
		foreach ( $types as $i => $type ) {
			$this->ticket_row( (string) $i, $type, $event );
		}
		echo '</div>';
		echo '<template data-sc-ev-template="tickets">';
		$this->ticket_row( '__i__', Event::normalize_ticket_type( array() ) );
		echo '</template>';
		echo '<datalist id="sc-ev-attendee-types">';
		foreach ( array( __( 'Adult', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Child', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Student', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Senior', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Member', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'VIP', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) as $label ) {
			echo '<option value="' . esc_attr( $label ) . '"></option>';
		}
		echo '</datalist>';
		printf( '<p><button type="button" class="button" data-sc-ev-add="tickets">%s</button></p>', esc_html__( 'Add ticket type', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		echo '<details class="sc-ev-help-box"><summary>' . esc_html__( 'How dynamic pricing works', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</summary><ul>';
		$help = array(
			'until 2026-11-01: 20 Early bird' => __( 'This price until the end of that day (add a time: until 2026-11-01 18:00: 20).', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'from 2026-12-10: 40 Door price'  => __( 'This price from that day on.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'after 75%: 35'                   => __( 'Once 75% of the seats are taken (this ticket type’s limit, else the event capacity).', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'after 100 sold: 35'              => __( 'Once 100 tickets of this type are taken.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'qty 5: 18 Group rate'            => __( 'Each ticket costs this when buying 5 or more of this type.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
		foreach ( $help as $code => $text ) {
			echo '<li><code>' . esc_html( $code ) . '</code> ' . esc_html( $text ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'The last line that applies sets the price. Words after the price are shown to buyers. Tiered prices by attendee type: add a ticket type for each (Adult, Child…).', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p></details>';
	}

	/**
	 * One attendee field row.
	 *
	 * @param string $i     Index.
	 * @param array  $field Field.
	 */
	protected function field_row( $i, array $field ) {
		$n     = 'sidccofo_ev_fields[' . $i . ']';
		$types = array(
			'text'     => __( 'Text', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'textarea' => __( 'Long text', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'email'    => __( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'tel'      => __( 'Phone', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'number'   => __( 'Number', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'date'     => __( 'Date', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'select'   => __( 'Dropdown', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'radio'    => __( 'Choose one', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'checkbox' => __( 'Checkbox(es)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'file'     => __( 'File upload', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
		?>
		<tr data-sc-ev-row>
			<td><input type="hidden" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo esc_attr( (string) ( $field['id'] ?? '' ) ); ?>">
				<input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( (string) ( $field['label'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Dietary needs', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" aria-label="<?php esc_attr_e( 'Label', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></td>
			<td><select name="<?php echo esc_attr( $n ); ?>[type]" aria-label="<?php esc_attr_e( 'Type', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">
				<?php foreach ( $types as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) ( $field['type'] ?? 'text' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select></td>
			<td><input type="text" name="<?php echo esc_attr( $n ); ?>[options]" value="<?php echo esc_attr( is_array( $field['options'] ?? null ) ? implode( ' | ', $field['options'] ) : (string) ( $field['options'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Vegetarian | Vegan | Halal', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" aria-label="<?php esc_attr_e( 'Choices', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></td>
			<td><input type="text" name="<?php echo esc_attr( $n ); ?>[help]" value="<?php echo esc_attr( (string) ( $field['help'] ?? '' ) ); ?>" aria-label="<?php esc_attr_e( 'Help text', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></td>
			<td class="sc-ev-center"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?> aria-label="<?php esc_attr_e( 'Required', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></td>
			<td><button type="button" class="button-link sc-ev-row__remove" data-sc-ev-remove aria-label="<?php esc_attr_e( 'Remove field', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>">✕</button></td>
		</tr>
		<?php
	}

	/**
	 * Attendee information mode and custom fields.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function attendees_box( $post ) {
		$event  = Event::get( $post->ID );
		$mode   = $event ? $event->get_attendee_info() : 'name';
		$fields = $event ? array_values( $event->get_fields() ) : array();
		$modes  = array(
			'buyer'            => __( 'Nothing extra: tickets are in the buyer’s name', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'name'             => __( 'Name of each attendee', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'name_email'       => __( 'Name and email of each attendee', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'name_email_phone' => __( 'Name, email and phone of each attendee', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
		?>
		<p><label for="sc-ev-mode"><strong><?php esc_html_e( 'Ask at checkout for', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></strong></label><br>
			<select id="sc-ev-mode" name="sidccofo_ev[attendee_info]">
				<?php foreach ( $modes as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $mode, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><strong><?php esc_html_e( 'Custom fields (asked for each ticket)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></strong></p>
		<table class="widefat striped sc-ev-fields">
			<thead><tr>
				<th><?php esc_html_e( 'Label', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
				<th><?php esc_html_e( 'Type', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
				<th><?php esc_html_e( 'Choices (separate with |)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
				<th><?php esc_html_e( 'Help text', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
				<th class="sc-ev-center"><?php esc_html_e( 'Required', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></th>
			</tr></thead>
			<tbody data-sc-ev-rows="fields">
				<?php
				foreach ( $fields as $i => $field ) {
					$this->field_row( (string) $i, $field );
				}
				?>
			</tbody>
		</table>
		<template data-sc-ev-template="fields"><?php $this->field_row( '__i__', array() ); ?></template>
		<p><button type="button" class="button" data-sc-ev-add="fields"><?php esc_html_e( 'Add field', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button></p>
		<p class="description">
			<?php
			/* translators: 1: file types, 2: size. */
			echo esc_html( sprintf( __( 'File uploads accept %1$s up to %2$s MB (Events → Settings). Files are stored privately and only store managers can download them.', 'sidcraft-commerce-for-sidcraft-page-builder' ), implode( ', ', Attendees::allowed_extensions() ), number_format_i18n( Attendees::max_mb(), 1 ) ) );
			?>
		</p>
		<?php
	}

	/**
	 * Live map of the venue. Follows the Venue, Address and coordinate fields
	 * as they are typed (sc-events-admin.js) and shows the map the event page
	 * will show.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function location_box( $post ) {
		$m        = static function ( $key ) use ( $post ) {
			return (string) get_post_meta( $post->ID, '_sidccofo_ev_' . $key, true );
		};
		$label    = trim( implode( ', ', array_filter( array( $m( 'venue' ), $m( 'address' ) ) ) ) );
		$coords   = is_numeric( $m( 'lat' ) ) && is_numeric( $m( 'lng' ) );
		$provider = (string) \SidcraftCommerce\setting( 'ev_map_provider', 'google' );
		$src      = ( '' !== $label || $coords ) && 'none' !== $provider ? Event::map_embed_url( $label, $m( 'lat' ), $m( 'lng' ) ) : '';
		$settings = admin_url( 'edit.php?post_type=' . Post_Types::PRODUCT . '&page=sidccofo-settings&tab=events' );
		$hidden   = '0' === $m( 'show_map' );
		?>
		<div class="sc-ev-mapbox<?php echo $hidden ? ' is-off' : ''; ?>" data-sc-ev-mapbox>
			<iframe class="sc-ev-mapbox__frame" data-sc-ev-map-frame title="<?php esc_attr_e( 'Venue map preview', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" <?php echo '' !== $src ? 'src="' . esc_url( $src ) . '"' : 'hidden'; ?>></iframe>
			<div class="sc-ev-mapbox__empty" data-sc-ev-map-empty <?php echo '' !== $src ? 'hidden' : ''; ?>>
				<svg viewBox="0 0 320 110" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
					<path d="M0 45H320M0 90H320M45 0V110M135 0V110M225 0V110M300 0V110" fill="none" stroke="#dcdcde" stroke-width="1"/>
					<path d="M222 62v-8" stroke="#8c8f94" stroke-width="2" stroke-linecap="round"/>
					<circle cx="222" cy="50" r="8" fill="#e2245a"/>
					<circle cx="219.5" cy="47.5" r="2.5" fill="#fff" opacity=".6"/>
				</svg>
				<span><?php echo 'none' === $provider ? esc_html__( 'Maps are turned off.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : esc_html__( 'Type a venue or address to see the map.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span>
			</div>
			<p class="sc-ev-mapbox__off" data-sc-ev-map-off <?php echo $hidden ? '' : 'hidden'; ?>><?php esc_html_e( 'Not shown on the event page (“Show a map” is off).', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		</div>
		<p class="sc-ev-muted sc-ev-mapbox__note">
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: link to Events → Settings. */
					esc_html__( 'Google Maps or OpenStreetMap — configured under %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'<a href="' . esc_url( $settings ) . '">' . esc_html__( 'Events → Settings', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a>'
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * Sales summary and tools.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function sales_box( $post ) {
		$event = Event::get( $post->ID );
		if ( ! $event || 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the event to start selling tickets.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
			return;
		}
		$tickets = Ticket::for_event( $event->get_id() );
		$valid   = 0;
		$in      = 0;
		foreach ( $tickets as $t ) {
			if ( 'cancelled' !== $t->get_status() ) {
				++$valid;
			}
			if ( 'checked_in' === $t->get_status() ) {
				++$in;
			}
		}
		$cap = $event->get_capacity();
		echo '<ul class="sc-ev-stats">';
		/* translators: 1: seats held, 2: capacity. */
		echo '<li><strong>' . esc_html( $cap ? sprintf( __( '%1$d of %2$d seats taken', 'sidcraft-commerce-for-sidcraft-page-builder' ), $event->get_held(), $cap ) : sprintf( /* translators: %d: seats. */ _n( '%d seat taken', '%d seats taken', $event->get_held(), 'sidcraft-commerce-for-sidcraft-page-builder' ), $event->get_held() ) ) . '</strong></li>';
		/* translators: %d: tickets. */
		echo '<li>' . esc_html( sprintf( _n( '%d ticket issued', '%d tickets issued', $valid, 'sidcraft-commerce-for-sidcraft-page-builder' ), $valid ) ) . '</li>';
		/* translators: 1: checked in, 2: issued. */
		echo '<li>' . esc_html( sprintf( __( '%1$d of %2$d checked in', 'sidcraft-commerce-for-sidcraft-page-builder' ), $in, $valid ) ) . '</li>';
		echo '</ul>';
		if ( $cap ) {
			$pct = min( 100, (int) round( $event->get_held() / max( 1, $cap ) * 100 ) );
			echo '<div class="sc-ev-bar" role="img" aria-label="' . esc_attr( $pct . '%' ) . '"><span style="width:' . (int) $pct . '%"></span></div>';
		}
		echo '<p class="sc-ev-tools">';
		printf( '<a class="button" href="%1$s">%2$s</a> ', esc_url( admin_url( 'edit.php?post_type=' . Events::TICKET . '&sidccofo_event=' . $event->get_id() ) ), esc_html__( 'Attendees', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		printf( '<a class="button" href="%1$s">%2$s</a> ', esc_url( admin_url( 'edit.php?post_type=' . Events::EVENT . '&page=sidccofo-checkin&event=' . $event->get_id() ) ), esc_html__( 'Check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		printf( '<a class="button" href="%1$s">%2$s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sidccofo_ev_export&event=' . $event->get_id() ), 'sidccofo_ev_export' ) ), esc_html__( 'Export CSV', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		echo '</p><p class="sc-ev-tools">';
		printf( '<a href="%1$s">%2$s</a> · ', esc_url( $this->action_url( 'recount', $event->get_id() ) ), esc_html__( 'Recount seats', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		printf( '<a href="%1$s" data-sc-ev-confirm="%3$s">%2$s</a>', esc_url( $this->action_url( 'remind', $event->get_id() ) ), esc_html__( 'Email a reminder now', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_attr__( 'Email every ticket holder a reminder now?', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Shortcodes:', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '<br><code>[sidccofo_event_tickets event_id="' . (int) $event->get_id() . '"]</code><br><code>[sidccofo_event_details event_id="' . (int) $event->get_id() . '"]</code></p>';
	}

	/**
	 * Signed admin action link.
	 *
	 * @param string $do Action.
	 * @param int    $id Object ID.
	 * @return string
	 */
	protected function action_url( $do, $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'sidccofo_ev_action',
					'do'     => $do,
					'id'     => (int) $id,
				),
				admin_url( 'admin-post.php' )
			),
			'sidccofo_ev_' . $do . '_' . (int) $id
		);
	}

	/**
	 * Parse a datetime-local value into the stored format.
	 *
	 * @param string $v Value.
	 * @return string Y-m-d H:i or ''.
	 */
	protected static function dt_clean( $v ) {
		$v = sanitize_text_field( (string) $v );
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $v, $m ) ) {
			return $m[1] . ' ' . $m[2];
		}
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v . ' 00:00' : '';
	}

	/**
	 * Save the event and sync its ticket placeholders.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function save_event( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! isset( $_POST['_sidccofo_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_event_nonce'] ) ), 'sidccofo_save_event' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			// REST / quick edit: keep derived data and placeholders in sync.
			$event = Event::get( $post_id );
			if ( $event && 'trash' !== $post->post_status ) {
				update_post_meta( $post_id, '_sidccofo_ev_ends', $event->get_end() ? wp_date( 'Y-m-d H:i', $event->get_end() ) : '' );
				Placeholders::sync( $event );
			}
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		$raw     = isset( $_POST['sidccofo_ev'] ) && is_array( $_POST['sidccofo_ev'] ) ? wp_unslash( $_POST['sidccofo_ev'] ) : array();
		$text    = static function ( $key ) use ( $raw ) {
			return sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) );
		};
		$old_start = (string) get_post_meta( $post_id, '_sidccofo_ev_start', true );
		$old_end   = (string) get_post_meta( $post_id, '_sidccofo_ev_end', true );
		$start     = self::dt_clean( $raw['start'] ?? '' );
		$end       = self::dt_clean( $raw['end'] ?? '' );
		if ( '' !== $end && '' !== $start && $end < $start ) {
			$end = '';
			set_transient( 'sidccofo_ev_notice_' . get_current_user_id(), __( 'The end was before the start, so it was cleared.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 60 );
		}
		$lat = is_numeric( $raw['lat'] ?? '' ) ? (string) max( -90, min( 90, (float) $raw['lat'] ) ) : '';
		$lng = is_numeric( $raw['lng'] ?? '' ) ? (string) max( -180, min( 180, (float) $raw['lng'] ) ) : '';
		$mode = $text( 'attendee_info' );

		$meta = array(
			'start'           => $start,
			'end'             => $end,
			'all_day'         => empty( $raw['all_day'] ) ? 0 : 1,
			'venue'           => $text( 'venue' ),
			'address'         => $text( 'address' ),
			'lat'             => $lat,
			'lng'             => $lng,
			'show_map'        => empty( $raw['show_map'] ) ? 0 : 1,
			'online'          => empty( $raw['online'] ) ? 0 : 1,
			'zoom_url'        => esc_url_raw( (string) ( $raw['zoom_url'] ?? '' ), array( 'https', 'http' ) ),
			'zoom_id'         => $text( 'zoom_id' ),
			'zoom_passcode'   => $text( 'zoom_passcode' ),
			'zoom_auto'       => empty( $raw['zoom_auto'] ) ? 0 : 1,
			'online_url'      => esc_url_raw( (string) ( $raw['online_url'] ?? '' ), array( 'https', 'http' ) ),
			'organizer'       => $text( 'organizer' ),
			'organizer_email' => sanitize_email( (string) ( $raw['organizer_email'] ?? '' ) ),
			'whatsapp_number' => preg_replace( '/[^\d+\s\-()]/', '', $text( 'whatsapp_number' ) ),
			'whatsapp_group'  => esc_url_raw( (string) ( $raw['whatsapp_group'] ?? '' ), array( 'https' ) ),
			'capacity'        => max( 0, absint( $raw['capacity'] ?? 0 ) ),
			'close_at_start'  => empty( $raw['close_at_start'] ) ? 0 : 1,
			'show_remaining'  => empty( $raw['show_remaining'] ) ? 0 : 1,
			'hide_auto'       => empty( $raw['hide_auto'] ) ? 0 : 1,
			'attendee_info'   => in_array( $mode, array( 'buyer', 'name', 'name_email', 'name_email_phone' ), true ) ? $mode : 'name',
		);
		// Ticket types become products anyone can buy, and Zoom meetings use the
		// store's account: only store managers set those (an Author or Editor
		// can still write the event itself).
		$manager = current_user_can( manage_cap() );
		if ( ! $manager ) {
			unset( $meta['zoom_auto'] );
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, '_sidccofo_ev_' . $key, $value );
		}

		// Ticket types.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		$rows    = isset( $_POST['sidccofo_ev_tickets'] ) && is_array( $_POST['sidccofo_ev_tickets'] ) ? wp_unslash( $_POST['sidccofo_ev_tickets'] ) : array();
		$tickets = array();
		$ids     = array();
		foreach ( $rows as $row ) {
			$row  = (array) $row;
			$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			if ( '' === $name && '' === trim( (string) ( $row['price'] ?? '' ) ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id || isset( $ids[ $id ] ) ) {
				$id = 't' . strtolower( wp_generate_password( 6, false, false ) );
			}
			$ids[ $id ] = true;
			$tickets[]  = array(
				'id'            => $id,
				'name'          => '' !== $name ? $name : __( 'Ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'attendee_type' => sanitize_text_field( (string) ( $row['attendee_type'] ?? '' ) ),
				'description'   => sanitize_text_field( (string) ( $row['description'] ?? '' ) ),
				'price'         => parse_price( (string) ( $row['price'] ?? '' ) ),
				'capacity'      => max( 0, absint( $row['capacity'] ?? 0 ) ),
				'min'           => max( 0, absint( $row['min'] ?? 0 ) ),
				'max'           => max( 0, absint( $row['max'] ?? 0 ) ),
				'sale_start'    => self::dt_clean( $row['sale_start'] ?? '' ),
				'sale_end'      => self::dt_clean( $row['sale_end'] ?? '' ),
				'rules'         => sanitize_textarea_field( (string) ( $row['rules'] ?? '' ) ),
				'product_id'    => absint( $row['product_id'] ?? 0 ),
			);
		}
		if ( $manager ) {
			update_post_meta( $post_id, '_sidccofo_ev_tickets', $tickets );
		}

		// Attendee fields.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		$rows   = isset( $_POST['sidccofo_ev_fields'] ) && is_array( $_POST['sidccofo_ev_fields'] ) ? wp_unslash( $_POST['sidccofo_ev_fields'] ) : array();
		$fields = array();
		$fids   = array();
		foreach ( $rows as $row ) {
			$row   = (array) $row;
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id || isset( $fids[ $id ] ) ) {
				$id = substr( sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) ), 0, 24 );
				$id = '' !== $id && ! isset( $fids[ $id ] ) ? $id : 'f' . strtolower( wp_generate_password( 5, false, false ) );
			}
			$fids[ $id ] = true;
			$type        = sanitize_key( (string) ( $row['type'] ?? 'text' ) );
			$fields[]    = array(
				'id'       => $id,
				'label'    => $label,
				'type'     => in_array( $type, Event::field_types(), true ) ? $type : 'text',
				'options'  => implode( ' | ', array_filter( array_map( 'trim', explode( '|', sanitize_text_field( (string) ( $row['options'] ?? '' ) ) ) ), 'strlen' ) ),
				'help'     => sanitize_text_field( (string) ( $row['help'] ?? '' ) ),
				'required' => empty( $row['required'] ) ? 0 : 1,
			);
		}
		update_post_meta( $post_id, '_sidccofo_ev_fields', $fields );

		Event::forget( $post_id );
		$event = Event::get( $post_id );
		update_post_meta( $post_id, '_sidccofo_ev_ends', $event->get_end() ? wp_date( 'Y-m-d H:i', $event->get_end() ) : '' );
		Placeholders::sync( $event );

		/**
		 * Fires after an event is saved from its screen.
		 *
		 * @param Event $event        Event.
		 * @param bool  $changed_time Start or end changed.
		 */
		do_action( 'sidcraft_commerce_event_saved', Event::get( $post_id ), $old_start !== $start || $old_end !== $end );
	}

	/**
	 * Notices (event saved with a cleared end, actions done).
	 */
	public function notices() {
		$notice = get_transient( 'sidccofo_ev_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'sidccofo_ev_notice_' . get_current_user_id() );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( (string) $notice ) . '</p></div>';
		}
	}

	/*
	------------------------------------------------------------------
	 * Events list
	 * ----------------------------------------------------------------
	 */

	/**
	 * Columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function event_columns( $cols ) {
		$new = array();
		foreach ( $cols as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['sc_ev_date']  = __( 'When', 'sidcraft-commerce-for-sidcraft-page-builder' );
				$new['sc_ev_where'] = __( 'Where', 'sidcraft-commerce-for-sidcraft-page-builder' );
				$new['sc_ev_sold']  = __( 'Sold', 'sidcraft-commerce-for-sidcraft-page-builder' );
				$new['sc_ev_in']    = __( 'Checked in', 'sidcraft-commerce-for-sidcraft-page-builder' );
			}
		}
		unset( $new['date'] );
		return $new;
	}

	/**
	 * Column cell.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Post.
	 */
	public function event_column( $col, $post_id ) {
		$event = Event::get( $post_id );
		if ( ! $event ) {
			return;
		}
		switch ( $col ) {
			case 'sc_ev_date':
				echo esc_html( $event->get_date_label() ? $event->get_date_label() : '—' );
				if ( $event->is_past() ) {
					echo '<br><span class="sc-ev-muted">' . esc_html__( 'Ended', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span>';
				}
				break;
			case 'sc_ev_where':
				$parts = array();
				if ( $event->has_location() ) {
					$parts[] = '' !== $event->get_venue() ? $event->get_venue() : $event->get_address();
				}
				if ( $event->is_online() ) {
					$parts[] = __( 'Online', 'sidcraft-commerce-for-sidcraft-page-builder' );
				}
				echo esc_html( $parts ? implode( ' + ', $parts ) : '—' );
				break;
			case 'sc_ev_sold':
				$cap = $event->get_capacity();
				printf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( 'edit.php?post_type=' . Events::TICKET . '&sidccofo_event=' . $post_id ) ), esc_html( $event->get_held() . ' / ' . ( $cap ? $cap : '∞' ) ) );
				break;
			case 'sc_ev_in':
				$all = Ticket::for_event( $post_id );
				$in  = count(
					array_filter(
						$all,
						static function ( Ticket $t ) {
							return 'checked_in' === $t->get_status();
						}
					)
				);
				$valid = count(
					array_filter(
						$all,
						static function ( Ticket $t ) {
							return 'cancelled' !== $t->get_status();
						}
					)
				);
				echo esc_html( $in . ' / ' . $valid );
				break;
		}
	}

	/**
	 * Sortable by start.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function event_sortable( $cols ) {
		$cols['sc_ev_date'] = 'sc_ev_start';
		return $cols;
	}

	/**
	 * Order the events list by start date (newest first by default).
	 *
	 * @param \WP_Query $query Query.
	 */
	public function event_list_order( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() || Events::EVENT !== $query->get( 'post_type' ) ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( '' === $orderby || 'sc_ev_start' === $orderby ) {
			$query->set( 'meta_key', '_sidccofo_ev_start' );
			$query->set( 'orderby', 'meta_value' );
			if ( '' === (string) $query->get( 'order' ) ) {
				$query->set( 'order', 'DESC' );
			}
		}
	}

	/**
	 * "Attendees" and "Check-in" row actions on events.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( Events::EVENT === $post->post_type ) {
			$actions['sc_attendees'] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Events::TICKET . '&sidccofo_event=' . $post->ID ) ) . '">' . esc_html__( 'Attendees', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a>';
			$actions['sc_checkin']   = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Events::EVENT . '&page=sidccofo-checkin&event=' . $post->ID ) ) . '">' . esc_html__( 'Check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a>';
		}
		if ( Events::TICKET === $post->post_type ) {
			$ticket = Ticket::get( $post->ID );
			if ( $ticket ) {
				unset( $actions['inline hide-if-no-js'] );
				if ( 'valid' === $ticket->get_status() ) {
					$actions['sc_checkin'] = '<a href="' . esc_url( $this->action_url( 'checkin', $post->ID ) ) . '">' . esc_html__( 'Check in', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a>';
				} elseif ( 'checked_in' === $ticket->get_status() ) {
					$actions['sc_undo'] = '<a href="' . esc_url( $this->action_url( 'undo', $post->ID ) ) . '">' . esc_html__( 'Undo check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a>';
				}
				$actions['sc_pdf']    = '<a href="' . esc_url( $ticket->get_pdf_url() ) . '">' . esc_html__( 'PDF', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a>';
				$actions['sc_resend'] = '<a href="' . esc_url( $this->action_url( 'resend_ticket', $post->ID ) ) . '">' . esc_html__( 'Email ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a>';
			}
		}
		return $actions;
	}

	/*
	------------------------------------------------------------------
	 * Attendees list (tickets)
	 * ----------------------------------------------------------------
	 */

	/**
	 * Columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function ticket_columns( $cols ) {
		return array(
			'cb'           => $cols['cb'] ?? '<input type="checkbox">',
			'title'        => __( 'Ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_attendee'  => __( 'Attendee', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_event'     => __( 'Event', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_type'      => __( 'Ticket type', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_order'     => __( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_status'    => __( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'date'         => __( 'Issued', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Cell.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Post.
	 */
	public function ticket_column( $col, $post_id ) {
		$ticket = Ticket::get( $post_id );
		if ( ! $ticket ) {
			return;
		}
		switch ( $col ) {
			case 'sc_attendee':
				echo esc_html( $ticket->get_attendee_name() );
				if ( $ticket->get_attendee_email() ) {
					echo '<br><span class="sc-ev-muted">' . esc_html( $ticket->get_attendee_email() ) . '</span>';
				}
				break;
			case 'sc_event':
				$event = $ticket->get_event();
				if ( $event ) {
					printf( '<a href="%1$s">%2$s</a><br><span class="sc-ev-muted">%3$s</span>', esc_url( admin_url( 'edit.php?post_type=' . Events::TICKET . '&sidccofo_event=' . $event->get_id() ) ), esc_html( $event->get_name() ), esc_html( $event->get_date_label( false ) ) );
				}
				break;
			case 'sc_type':
				echo esc_html( $ticket->get_type_name() . ( '' !== (string) $ticket->meta( 'attendee_type' ) ? ' · ' . $ticket->meta( 'attendee_type' ) : '' ) );
				break;
			case 'sc_order':
				$order = (int) $ticket->meta( 'order' );
				echo $order ? '<a href="' . esc_url( (string) get_edit_post_link( $order ) ) . '">#' . (int) $order . '</a>' : '—';
				break;
			case 'sc_status':
				printf( '<span class="sc-ev-pill sc-ev-pill--%1$s">%2$s</span>', esc_attr( $ticket->get_status() ), esc_html( $ticket->get_status_label() ) );
				if ( $ticket->get_checked_in() ) {
					echo '<br><span class="sc-ev-muted">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ticket->get_checked_in() ) ) . '</span>';
				}
				break;
		}
	}

	/**
	 * Event and status filters above the Attendees list.
	 *
	 * @param string $post_type Post type.
	 */
	public function ticket_filters( $post_type ) {
		if ( Events::TICKET !== $post_type ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List filters.
		$current = isset( $_GET['sidccofo_event'] ) ? absint( $_GET['sidccofo_event'] ) : 0;
		$status  = isset( $_GET['sidccofo_status'] ) ? sanitize_key( wp_unslash( $_GET['sidccofo_status'] ) ) : '';
		// phpcs:enable
		$events = get_posts(
			array(
				'post_type'      => Events::EVENT,
				'post_status'    => array( 'publish', 'draft', 'private', 'future' ),
				'posts_per_page' => 200,
				'meta_key'       => '_sidccofo_ev_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			)
		);
		echo '<select name="sidccofo_event"><option value="0">' . esc_html__( 'All events', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</option>';
		foreach ( $events as $e ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $e->ID, selected( $current, $e->ID, false ), esc_html( get_the_title( $e ) ) );
		}
		echo '</select><select name="sidccofo_status"><option value="">' . esc_html__( 'Any status', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</option>';
		foreach ( array( 'valid' => __( 'Valid', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checked_in' => __( 'Checked in', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'cancelled' => __( 'Cancelled', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) as $key => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( $status, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
		if ( $current ) {
			printf( ' <a class="button" href="%1$s">%2$s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sidccofo_ev_export&event=' . $current ), 'sidccofo_ev_export' ) ), esc_html__( 'Export CSV', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
	}

	/**
	 * Apply the filters; search also matches attendee names and emails.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function ticket_query( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() || Events::TICKET !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List filters.
		if ( ! empty( $_GET['sidccofo_event'] ) ) {
			$query->set( 'post_parent', absint( $_GET['sidccofo_event'] ) );
		}
		$meta = array();
		if ( ! empty( $_GET['sidccofo_status'] ) ) {
			$meta[] = array(
				'key'   => '_sidccofo_tk_status',
				'value' => sanitize_key( wp_unslash( $_GET['sidccofo_status'] ) ),
			);
		}
		$search = (string) $query->get( 's' );
		if ( '' !== $search ) {
			$by_code = Ticket::find( $search );
			if ( $by_code ) {
				$query->set( 's', '' );
				$query->set( 'post__in', array( $by_code->get_id() ) );
			} else {
				$query->set( 's', '' );
				$meta[] = array(
					'relation' => 'OR',
					array(
						'key'     => '_sidccofo_tk_name',
						'value'   => $search,
						'compare' => 'LIKE',
					),
					array(
						'key'     => '_sidccofo_tk_email',
						'value'   => $search,
						'compare' => 'LIKE',
					),
				);
			}
		}
		// phpcs:enable
		if ( $meta ) {
			$query->set( 'meta_query', $meta );
		}
	}

	/**
	 * Bulk actions.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public function ticket_bulk_actions( $actions ) {
		unset( $actions['edit'] );
		return array(
			'sc_checkin' => __( 'Check in', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sc_undo'    => __( 'Undo check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		) + $actions;
	}

	/**
	 * Handle bulk check-in / undo.
	 *
	 * @param string $redirect Redirect.
	 * @param string $action   Action.
	 * @param int[]  $ids      IDs.
	 * @return string
	 */
	public function ticket_bulk( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( 'sc_checkin', 'sc_undo' ), true ) || ! Events::user_can_check_in() ) {
			return $redirect;
		}
		$done = 0;
		foreach ( (array) $ids as $id ) {
			$ticket = Ticket::get( (int) $id );
			if ( ! $ticket ) {
				continue;
			}
			if ( 'sc_checkin' === $action && 'ok' === $ticket->check_in() ) {
				++$done;
			} elseif ( 'sc_undo' === $action && 'checked_in' === $ticket->get_status() ) {
				$ticket->undo_check_in();
				++$done;
			}
		}
		/* translators: %d: number of tickets. */
		set_transient( 'sidccofo_ev_notice_' . get_current_user_id(), sprintf( _n( '%d ticket updated.', '%d tickets updated.', $done, 'sidcraft-commerce-for-sidcraft-page-builder' ), $done ), 60 );
		return $redirect;
	}

	/**
	 * Ticket screen boxes.
	 */
	public function ticket_boxes() {
		remove_meta_box( 'submitdiv', Events::TICKET, 'side' );
		add_meta_box( 'sidccofo-ticket', __( 'Ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'ticket_box' ), Events::TICKET, 'normal', 'high' );
		add_meta_box( 'sidccofo-ticket-actions', __( 'Check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'ticket_actions_box' ), Events::TICKET, 'side', 'high' );
	}

	/**
	 * Ticket details (attendee name/email/phone are editable).
	 *
	 * @param \WP_Post $post Post.
	 */
	public function ticket_box( $post ) {
		$ticket = Ticket::get( $post->ID );
		if ( ! $ticket ) {
			return;
		}
		wp_nonce_field( 'sidccofo_save_ticket', '_sidccofo_ticket_nonce' );
		$event = $ticket->get_event();
		echo '<div class="sc-ev-ticket-admin"><div class="sc-ev-ticket-admin__qr">' . QR::svg( $ticket->get_qr_payload(), 150 ) . '<code>' . esc_html( $ticket->get_code() ) . '</code></div><div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated SVG.
		echo '<p><strong>' . esc_html( $event ? $event->get_name() : '' ) . '</strong><br>' . esc_html( $event ? $event->get_date_label() : '' ) . '<br>' . esc_html( $ticket->get_type_name() ) . '</p>';
		echo '<div class="sc-pd">';
		printf( '<p><label for="sc-tk-name">%1$s</label><input type="text" id="sc-tk-name" name="sidccofo_tk[name]" value="%2$s"></p>', esc_html__( 'Attendee name', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_attr( (string) $ticket->meta( 'name' ) ) );
		printf( '<p><label for="sc-tk-email">%1$s</label><input type="email" id="sc-tk-email" name="sidccofo_tk[email]" value="%2$s"></p>', esc_html__( 'Attendee email', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_attr( (string) $ticket->meta( 'email' ) ) );
		printf( '<p><label for="sc-tk-phone">%1$s</label><input type="tel" id="sc-tk-phone" name="sidccofo_tk[phone]" value="%2$s"></p>', esc_html__( 'Attendee phone', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_attr( (string) $ticket->meta( 'phone' ) ) );
		echo '</div>';
		if ( $ticket->get_fields() ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $ticket->get_fields() as $row ) {
				$value = esc_html( (string) $row['value'] );
				if ( '' !== (string) ( $row['file'] ?? '' ) ) {
					$value = '<a href="' . esc_url( Attendees::file_url( (string) $row['file'] ) ) . '">' . $value . '</a>';
				}
				echo '<tr><th>' . esc_html( (string) $row['label'] ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			}
			echo '</tbody></table>';
		}
		echo '<p><a class="button" href="' . esc_url( $ticket->get_url() ) . '" target="_blank">' . esc_html__( 'Open ticket page', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a> <a class="button" href="' . esc_url( $ticket->get_pdf_url() ) . '">' . esc_html__( 'PDF', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></p>';
		if ( $ticket->get_log() ) {
			echo '<details><summary>' . esc_html__( 'History', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</summary><ul>';
			foreach ( array_reverse( $ticket->get_log() ) as $row ) {
				$user = $row['user'] ? get_userdata( (int) $row['user'] ) : null;
				echo '<li>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) . ' — ' . $row['note'] . ( $user ? ' (' . $user->display_name . ')' : '' ) ) . '</li>';
			}
			echo '</ul></details>';
		}
		echo '</div></div>';
	}

	/**
	 * Status and actions.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function ticket_actions_box( $post ) {
		$ticket = Ticket::get( $post->ID );
		if ( ! $ticket ) {
			return;
		}
		printf( '<p><span class="sc-ev-pill sc-ev-pill--%1$s">%2$s</span></p>', esc_attr( $ticket->get_status() ), esc_html( $ticket->get_status_label() ) );
		if ( 'valid' === $ticket->get_status() ) {
			printf( '<p><a class="button button-primary button-large" href="%1$s">%2$s</a></p>', esc_url( $this->action_url( 'checkin', $post->ID ) ), esc_html__( 'Check in', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} elseif ( 'checked_in' === $ticket->get_status() ) {
			printf( '<p><a class="button" href="%1$s">%2$s</a></p>', esc_url( $this->action_url( 'undo', $post->ID ) ), esc_html__( 'Undo check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		if ( 'cancelled' === $ticket->get_status() ) {
			printf( '<p><a class="button" href="%1$s">%2$s</a></p>', esc_url( $this->action_url( 'restore_ticket', $post->ID ) ), esc_html__( 'Make valid again', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} else {
			printf( '<p><a href="%1$s" class="submitdelete" data-sc-ev-confirm="%3$s">%2$s</a></p>', esc_url( $this->action_url( 'cancel_ticket', $post->ID ) ), esc_html__( 'Cancel ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ), esc_attr__( 'Cancel this ticket? It will no longer get in.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $this->action_url( 'resend_ticket', $post->ID ) ), esc_html__( 'Email this ticket again', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		submit_button( __( 'Save attendee details', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'secondary', 'save', false );
	}

	/**
	 * Save attendee edits.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 */
	public function save_ticket( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! isset( $_POST['_sidccofo_ticket_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_ticket_nonce'] ) ), 'sidccofo_save_ticket' ) || ! current_user_can( manage_cap() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field.
		$raw = isset( $_POST['sidccofo_tk'] ) && is_array( $_POST['sidccofo_tk'] ) ? wp_unslash( $_POST['sidccofo_tk'] ) : array();
		update_post_meta( $post_id, '_sidccofo_tk_name', sanitize_text_field( (string) ( $raw['name'] ?? '' ) ) );
		update_post_meta( $post_id, '_sidccofo_tk_email', sanitize_email( (string) ( $raw['email'] ?? '' ) ) );
		update_post_meta( $post_id, '_sidccofo_tk_phone', sanitize_text_field( (string) ( $raw['phone'] ?? '' ) ) );
	}

	/*
	------------------------------------------------------------------
	 * Order screen
	 * ----------------------------------------------------------------
	 */

	/**
	 * Tickets box on orders that contain tickets.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function order_box( $post ) {
		$order = Order::get( $post->ID );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( Placeholders::is_placeholder( (int) $item['product_id'] ) ) {
				add_meta_box( 'sidccofo-order-tickets', __( 'Event tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_order_box' ), Post_Types::ORDER, 'normal', 'default' );
				return;
			}
		}
	}

	/**
	 * Tickets and attendee details of an order.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_order_box( $post ) {
		$order   = Order::get( $post->ID );
		$tickets = Ticket::for_order( $post->ID );
		if ( ! $tickets ) {
			echo '<p>' . esc_html( $order->is_paid() ? __( 'No tickets were issued for this order.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Tickets are issued when the order is paid (Processing or Completed).', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) . '</p>';
			if ( $order->is_paid() ) {
				printf( '<p><a class="button" href="%1$s">%2$s</a></p>', esc_url( $this->action_url( 'issue', $post->ID ) ), esc_html__( 'Issue tickets now', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			}
			$data = Attendees::for_order( $post->ID );
			if ( $data ) {
				echo '<p class="description">' . esc_html__( 'Attendee details were collected at checkout and will be on the tickets.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
			}
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Attendee', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Details', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th></tr></thead><tbody>';
		foreach ( $tickets as $ticket ) {
			$details = array();
			foreach ( $ticket->get_fields() as $row ) {
				$value = esc_html( (string) $row['value'] );
				if ( '' !== (string) ( $row['file'] ?? '' ) ) {
					$value = '<a href="' . esc_url( Attendees::file_url( (string) $row['file'] ) ) . '">' . $value . '</a>';
				}
				$details[] = '<strong>' . esc_html( (string) $row['label'] ) . ':</strong> ' . $value;
			}
			printf(
				'<tr><td><a href="%1$s"><code>%2$s</code></a><br>%3$s</td><td>%4$s<br><span class="sc-ev-muted">%5$s</span></td><td>%6$s</td><td><span class="sc-ev-pill sc-ev-pill--%7$s">%8$s</span></td></tr>',
				esc_url( (string) get_edit_post_link( $ticket->get_id() ) ),
				esc_html( $ticket->get_code() ),
				esc_html( ( $ticket->get_event() ? $ticket->get_event()->get_name() . ' · ' : '' ) . $ticket->get_type_name() ),
				esc_html( $ticket->get_attendee_name() ),
				esc_html( $ticket->get_attendee_email() . ( $ticket->get_attendee_phone() ? ' · ' . $ticket->get_attendee_phone() : '' ) ),
				wp_kses_post( implode( '<br>', $details ) ),
				esc_attr( $ticket->get_status() ),
				esc_html( $ticket->get_status_label() )
			);
		}
		echo '</tbody></table>';
		printf(
			'<p><a class="button" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
			esc_url( $this->action_url( 'resend', $post->ID ) ),
			esc_html__( 'Email tickets to the customer again', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			esc_url( Ticketing::order_pdf_url( $order ) ),
			esc_html__( 'Download PDF', 'sidcraft-commerce-for-sidcraft-page-builder' )
		);
	}

	/*
	------------------------------------------------------------------
	 * Actions and export
	 * ----------------------------------------------------------------
	 */

	/**
	 * admin-post.php?action=sidccofo_ev_action&do=…&id=…
	 */
	public function handle_action() {
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
		check_admin_referer( 'sidccofo_ev_' . $do . '_' . $id );
		$checkin_only = in_array( $do, array( 'checkin', 'undo' ), true );
		if ( ! ( $checkin_only ? Events::user_can_check_in() : current_user_can( manage_cap() ) ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 403 );
		}
		$msg = '';
		switch ( $do ) {
			case 'checkin':
			case 'undo':
			case 'cancel_ticket':
			case 'restore_ticket':
			case 'resend_ticket':
				$ticket = Ticket::get( $id );
				if ( ! $ticket ) {
					break;
				}
				if ( 'checkin' === $do ) {
					$res = $ticket->check_in();
					$msg = 'ok' === $res ? __( 'Checked in.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : ( 'already' === $res ? __( 'Already checked in.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'This ticket was cancelled.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
				} elseif ( 'undo' === $do ) {
					$ticket->undo_check_in();
					$msg = __( 'Check-in undone.', 'sidcraft-commerce-for-sidcraft-page-builder' );
				} elseif ( 'cancel_ticket' === $do ) {
					$user = wp_get_current_user();
					/* translators: %s: user. */
					$ticket->cancel( sprintf( __( 'Cancelled by %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $user->display_name ) );
					$msg = __( 'Ticket cancelled.', 'sidcraft-commerce-for-sidcraft-page-builder' );
				} elseif ( 'restore_ticket' === $do ) {
					$ticket->restore();
					$msg = __( 'Ticket is valid again.', 'sidcraft-commerce-for-sidcraft-page-builder' );
				} else {
					$to  = $ticket->get_attendee_email();
					$ok  = Mailer::send( $ticket->get_order(), array( $ticket ), $to, array( 'heading' => __( 'Your ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'kind' => 'attendee' ) );
					/* translators: %s: email. */
					$msg = $ok ? sprintf( __( 'Ticket emailed to %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $to ) : __( 'The email could not be sent.', 'sidcraft-commerce-for-sidcraft-page-builder' );
				}
				break;
			case 'resend':
			case 'issue':
				$order = Order::get( $id );
				if ( $order ) {
					if ( 'issue' === $do ) {
						$tickets = Ticketing::issue( $order );
						/* translators: %d: tickets. */
						$msg = sprintf( _n( '%d ticket issued.', '%d tickets issued.', count( $tickets ), 'sidcraft-commerce-for-sidcraft-page-builder' ), count( $tickets ) );
					} else {
						$msg = Mailer::send_order( $order ) ? __( 'Tickets emailed.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'The email could not be sent.', 'sidcraft-commerce-for-sidcraft-page-builder' );
					}
				}
				break;
			case 'recount':
				$event = Event::get( $id );
				if ( $event ) {
					$counts = Placeholders::recount( $event );
					/* translators: %d: seats. */
					$msg = sprintf( __( 'Seats recounted from orders: %d taken.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array_sum( $counts ) );
				}
				break;
			case 'remind':
				$event = Event::get( $id );
				if ( $event ) {
					$sent = Mailer::send_reminders( $event );
					/* translators: %d: emails. */
					$msg = sprintf( _n( '%d reminder sent.', '%d reminders sent.', $sent, 'sidcraft-commerce-for-sidcraft-page-builder' ), $sent );
				}
				break;
		}
		if ( '' !== $msg ) {
			set_transient( 'sidccofo_ev_notice_' . get_current_user_id(), $msg, 60 );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . Events::EVENT ) );
		exit;
	}

	/**
	 * CSV of an event's attendees.
	 */
	public function export() {
		check_admin_referer( 'sidccofo_ev_export' );
		if ( ! current_user_can( manage_cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 403 );
		}
		$event = Event::get( isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0 );
		if ( ! $event ) {
			wp_die( esc_html__( 'Event not found.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 404 );
		}
		$fields = $event->get_fields();
		$head   = array(
			__( 'Ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Checked in', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Ticket type', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Attendee type', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Attendee', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Phone', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Buyer', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Buyer email', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
		foreach ( $fields as $field ) {
			$head[] = $field['label'];
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="attendees-' . sanitize_title( $event->get_name() ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.
		fputcsv( $out, $head, ',', '"', '\\' );
		$safe = static function ( $v ) {
			$v = (string) $v;
			return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v; // No spreadsheet formulas.
		};
		foreach ( Ticket::for_event( $event->get_id() ) as $ticket ) {
			$order   = $ticket->get_order();
			$billing = $order ? $order->get_billing() : array();
			$values  = array();
			foreach ( $ticket->get_fields() as $row ) {
				$values[ $row['id'] ] = $row['value'];
			}
			$line = array(
				$ticket->get_code(),
				$ticket->get_status_label(),
				$ticket->get_checked_in() ? wp_date( 'Y-m-d H:i', $ticket->get_checked_in() ) : '',
				$ticket->get_type_name(),
				(string) $ticket->meta( 'attendee_type' ),
				$ticket->get_attendee_name(),
				$ticket->get_attendee_email(),
				$ticket->get_attendee_phone(),
				$order ? $order->get_id() : '',
				trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) ),
				$order ? $order->get_email() : '',
			);
			foreach ( $fields as $field ) {
				$line[] = $values[ $field['id'] ] ?? '';
			}
			fputcsv( $out, array_map( $safe, $line ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/*
	------------------------------------------------------------------
	 * Check-in screen
	 * ----------------------------------------------------------------
	 */

	/**
	 * Scanner page: camera (BarcodeDetector), USB scanners and typed codes.
	 */
	public function checkin_page() {
		if ( ! Events::user_can_check_in() ) {
			return;
		}
		$current = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$events  = Events::query(
			array(
				'when'  => 'all',
				'limit' => 60,
				'from'  => wp_date( 'Y-m-d H:i', time() - 14 * DAY_IN_SECONDS ),
			)
		);
		?>
		<div class="wrap sc-ev-checkin" data-sc-ev-checkin>
			<h1><?php esc_html_e( 'Check-in', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h1>
			<div class="sc-ev-checkin__bar">
				<label for="sc-ev-checkin-event"><?php esc_html_e( 'Event', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<select id="sc-ev-checkin-event" data-sc-ev-checkin-event>
					<option value="0"><?php esc_html_e( 'Any event', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></option>
					<?php foreach ( $events as $event ) : ?>
						<option value="<?php echo (int) $event->get_id(); ?>" <?php selected( $current, $event->get_id() ); ?>><?php echo esc_html( $event->get_name() . ' — ' . $event->get_date_label( false ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<form class="sc-ev-checkin__form" data-sc-ev-checkin-form>
				<label for="sc-ev-checkin-code" class="screen-reader-text"><?php esc_html_e( 'Ticket code', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
				<input type="text" id="sc-ev-checkin-code" class="sc-ev-checkin__input" placeholder="<?php esc_attr_e( 'Scan or type a ticket code', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus data-sc-ev-checkin-code>
				<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Check in', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
				<button type="button" class="button button-hero" data-sc-ev-camera><?php esc_html_e( 'Scan with camera', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
			</form>
			<div class="sc-ev-checkin__video" hidden data-sc-ev-video-wrap><video playsinline muted data-sc-ev-video></video></div>
			<div class="sc-ev-checkin__result" data-sc-ev-checkin-result role="status" aria-live="assertive"></div>
			<h2><?php esc_html_e( 'This session', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h2>
			<ol class="sc-ev-checkin__log" data-sc-ev-checkin-log></ol>
			<p class="description"><?php esc_html_e( 'Tip: any phone camera can scan a ticket. It opens the ticket page, where staff who are logged in see a Check in button.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		</div>
		<?php
	}

	/*
	------------------------------------------------------------------
	 * Settings
	 * ----------------------------------------------------------------
	 */

	/**
	 * Add the Events tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function settings_tab( $tabs ) {
		$out = array();
		foreach ( $tabs as $slug => $label ) {
			if ( 'emails' === $slug ) {
				$out['events'] = __( 'Events', 'sidcraft-commerce-for-sidcraft-page-builder' );
			}
			$out[ $slug ] = $label;
		}
		if ( ! isset( $out['events'] ) ) {
			$out['events'] = __( 'Events', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		return $out;
	}

	/**
	 * Settings sections on the Events tab.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public function settings( $sections ) {
		$sections['ev_general'] = array(
			'tab'    => 'events',
			'title'  => __( 'Events and tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'intro'  => __( 'Tickets are sold through your store’s checkout, so currency, taxes, coupons and payment methods come from the other tabs.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'fields' => array(
				'ev_slug'           => array( __( 'Event page address', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'The part of the URL before the event name, e.g. "event" gives /event/diwali-gala/.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'ev_after_add'      => array(
					__( 'After “Get tickets”', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					array(
						'checkout' => __( 'Go to checkout', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'cart'     => __( 'Go to the cart', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'stay'     => __( 'Stay on the event page', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					),
				),
				'ev_show_remaining' => array( __( 'Show tickets left', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Default for new events.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'ev_map_provider'   => array(
					__( 'Maps', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					array(
						'google' => __( 'Google Maps', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'osm'    => __( 'OpenStreetMap (needs coordinates)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'none'   => __( 'No maps', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					),
				),
				'ev_google_maps_key' => array( __( 'Google Maps Embed API key (optional)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( 'Maps work without a key. A key uses the official Embed API (restrict it to your domain).', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'ev_reminder_hours' => array( __( 'Reminder email (hours before)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', __( '0 = no reminders. Sent by the hourly store job.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			),
		);
		$sections['ev_tickets'] = array(
			'tab'    => 'events',
			'title'  => __( 'Ticket delivery', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'fields' => array(
				'ev_code_type'       => array(
					__( 'Code on tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					array(
						'qr'      => __( 'QR code', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'barcode' => __( 'Barcode (Code 128)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'both'    => __( 'QR code and barcode', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					),
				),
				'ev_attach_pdf'      => array( __( 'Attach PDF tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
				'ev_attach_ics'      => array( __( 'Attach a calendar file (.ics)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
				'ev_email_attendees' => array( __( 'Email each attendee their own ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'When an attendee email differs from the buyer’s.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'ev_email_images'    => array(
					__( 'QR images in emails', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					array(
						'embed' => __( 'Embedded in the email (recommended)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'link'  => __( 'Linked images (for mail plugins that don’t support embedding)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					),
				),
				'ev_pdf_color'       => array( __( 'Ticket colour', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', '#7c5cfc' ),
				'ev_pdf_image'       => array( __( 'Show the event image on PDF tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'JPEG images only.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				'ev_pdf_footer'      => array( __( 'Ticket terms (printed at the bottom)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'textarea' ),
			),
		);
		$sections['ev_uploads'] = array(
			'tab'    => 'events',
			'title'  => __( 'Attendee file uploads', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'fields' => array(
				'ev_upload_types'  => array( __( 'Allowed file types', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'jpg, jpeg, png, gif, webp, pdf, doc, docx' ),
				'ev_upload_max_mb' => array( __( 'Largest file (MB)', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', '5' ),
			),
		);
		$sections['ev_zoom'] = array(
			'tab'    => 'events',
			'title'  => __( 'Zoom', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'intro'  => __( 'Optional. Create a Server-to-Server OAuth app in the Zoom App Marketplace (scopes: meeting:write:admin or meeting:write) to create meetings from the event screen. Pasting a meeting link works without this.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'fields' => array(
				'zoom_account_id'    => array( __( 'Account ID', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text' ),
				'zoom_client_id'     => array( __( 'Client ID', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text' ),
				'zoom_client_secret' => array( __( 'Client secret', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'password' ),
			),
		);
		$sections['ev_whatsapp'] = array(
			'tab'    => 'events',
			'title'  => __( 'WhatsApp messages', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'intro'  => __( 'Optional. Sends an approved WhatsApp Cloud API template to the buyer’s phone when tickets are issued. Template body variables: {{1}} first name, {{2}} event, {{3}} date, {{4}} ticket link.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'fields' => array(
				'wa_enabled'         => array( __( 'Send tickets on WhatsApp', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
				'wa_phone_number_id' => array( __( 'Phone number ID', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text' ),
				'wa_token'           => array( __( 'Access token', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'password' ),
				'wa_template'        => array( __( 'Template name', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text' ),
				'wa_language'        => array( __( 'Template language code', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'en_US' ),
			),
		);
		return $sections;
	}
}
