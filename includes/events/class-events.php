<?php
/**
 * Event management and ticketing for Sidcraft Commerce.
 *
 *   - Events (sidccofo_event) with dates, venue + map, online details (Zoom,
 *     WhatsApp), organiser, capacity and any number of ticket types.
 *   - Each ticket type is sold through a hidden product placeholder, so the
 *     store's checkout, currency, taxes, coupons and gateways handle tickets.
 *   - Paid orders issue tickets (sidccofo_ticket) with unique codes, QR codes
 *     and barcodes; buyers get an email with PDF tickets and a calendar file.
 *   - Staff check attendees in by scanning the QR code with any phone camera,
 *     the Check-in screen (camera or USB scanner) or the Attendees list.
 *   - Display: Units for Sidcraft Page Builder, Gutenberg blocks and shortcodes
 *     (event list, calendar, details, tickets, map, my tickets).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use SidcraftCommerce\Plugin;
use function SidcraftCommerce\manage_cap;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Events
 */
class Events {

	const EVENT    = 'sidccofo_event';
	const CATEGORY = 'sidccofo_event_cat';
	const TICKET   = 'sidccofo_ticket';

	/**
	 * Attach hooks and start the services.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register' ), 1 );
		add_action( 'init', array( $this, 'maybe_flush' ), 100 );
		add_action( 'sidcraft_commerce_register_assets', array( $this, 'register_assets' ) );
		add_filter( 'sidcraft_commerce_core_units', array( $this, 'units' ) ); // Modules drops them when Events is off.

		// Tickets already sold keep working with the module off: their links,
		// PDFs and check-in, and cancelled or refunded orders give seats back.
		// The ticket products stay hidden, and can't be bought (Modules).
		( new Placeholders() )->hooks();
		( new Ticketing() )->hooks();
		if ( ! \SidcraftCommerce\Modules::enabled( 'events' ) ) {
			add_action( 'template_redirect', array( new Frontend(), 'endpoints' ), 1 );
			return;
		}

		( new Frontend() )->hooks();
		( new Integrations() )->hooks();
		( new Blocks() )->hooks();
		if ( is_admin() ) {
			( new Admin() )->hooks();
		}
	}

	/**
	 * Capability for checking attendees in: store managers, plus any role
	 * given "sidccofo_check_in" (door staff).
	 *
	 * @return bool
	 */
	public static function user_can_check_in() {
		$ok = current_user_can( manage_cap() ) || current_user_can( 'sidccofo_check_in' );
		return (bool) apply_filters( 'sidcraft_commerce_user_can_check_in', $ok );
	}

	/**
	 * Register post types and the category taxonomy.
	 */
	public function register() {
		$cap  = manage_cap();
		$slug = sanitize_title( (string) setting( 'ev_slug', 'event' ) );
		$slug = '' !== $slug ? $slug : 'event';

		register_post_type(
			self::EVENT,
			apply_filters(
				'sidcraft_commerce_event_post_type_args',
				array(
					'labels'          => array(
						'name'               => __( 'Events', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'singular_name'      => __( 'Event', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'menu_name'          => __( 'Events', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'all_items'          => __( 'All Events', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'add_new'            => __( 'Add New Event', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'add_new_item'       => __( 'Add event', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'edit_item'          => __( 'Edit event', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'view_item'          => __( 'View event', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'search_items'       => __( 'Search events', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'not_found'          => __( 'No events yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'not_found_in_trash' => __( 'No events in the trash.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					),
					'public'          => true,
					'has_archive'     => false,
					'menu_icon'       => 'dashicons-tickets-alt',
					'menu_position'   => 57,
					'show_in_rest'    => true,
					'capability_type' => 'post',
					'rewrite'         => array(
						'slug'       => $slug,
						'with_front' => false,
					),
					'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
				)
			)
		);

		register_taxonomy(
			self::CATEGORY,
			self::EVENT,
			array(
				'labels'            => array(
					'name'          => __( 'Event categories', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name' => __( 'Event category', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'menu_name'     => __( 'Categories', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'       => $slug . '-category',
					'with_front' => false,
				),
			)
		);

		register_post_type(
			self::TICKET,
			array(
				'labels'       => array(
					'name'          => __( 'Attendees', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'singular_name' => __( 'Ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'menu_name'     => __( 'Attendees', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'all_items'     => __( 'Attendees', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'edit_item'     => __( 'Ticket', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'search_items'  => __( 'Search tickets', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'not_found'     => __( 'No tickets issued yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'edit.php?post_type=' . self::EVENT,
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
					'create_posts'           => 'do_not_allow', // Tickets come from paid orders.
				),
			)
		);

		$meta = array(
			'_sidccofo_ev_start'   => 'string',
			'_sidccofo_ev_end'     => 'string',
			'_sidccofo_ev_venue'   => 'string',
			'_sidccofo_ev_address' => 'string',
		);
		foreach ( $meta as $key => $type ) {
			register_post_meta(
				self::EVENT,
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
	 * Make sure the two Events flags read on every request are autoloaded.
	 */
	public static function autoload_options() {
		// Both are read on every request. Before 1.3.1 they were saved with autoload
		// off, which cost a query each; switch existing ones over once.
		if ( ! function_exists( 'wp_set_option_autoload_values' ) ) {
			return;
		}
		$all  = wp_load_alloptions();
		$keys = array();
		foreach ( array( 'sidcraft_commerce_events_defaults', 'sidcraft_commerce_events_slug' ) as $key ) {
			if ( ! isset( $all[ $key ] ) ) {
				$keys[ $key ] = true;
			}
		}
		if ( $keys ) {
			// Changes only options that exist and are not autoloaded yet.
			wp_set_option_autoload_values( $keys );
		}
	}

	/**
	 * Store the Events settings defaults once, so the settings tab shows
	 * (and keeps) them the first time it is saved.
	 */
	public static function ensure_defaults() {
		if ( get_option( 'sidcraft_commerce_events_defaults' ) ) {
			return;
		}
		$settings = (array) get_option( 'sidcraft_commerce_settings', array() );
		$defaults = array(
			'ev_slug'            => 'event',
			'ev_after_add'       => 'checkout',
			'ev_show_remaining'  => 1,
			'ev_map_provider'    => 'google',
			'ev_reminder_hours'  => '24',
			'ev_code_type'       => 'qr',
			'ev_attach_pdf'      => 1,
			'ev_attach_ics'      => 1,
			'ev_email_attendees' => 0,
			'ev_email_images'    => 'embed',
			'ev_pdf_color'       => '#7c5cfc',
			'ev_pdf_image'       => 1,
			'ev_upload_types'    => 'jpg, jpeg, png, gif, webp, pdf, doc, docx',
			'ev_upload_max_mb'   => '5',
			'wa_language'        => 'en_US',
		);
		update_option( 'sidcraft_commerce_settings', $settings + $defaults );
		update_option( 'sidcraft_commerce_events_defaults', 1, true );
	}

	/**
	 * Flush rewrite rules once after the events slug changes or on first run.
	 */
	public function maybe_flush() {
		self::autoload_options();
		self::ensure_defaults();
		$slug = (string) setting( 'ev_slug', 'event' );
		if ( get_option( 'sidcraft_commerce_events_slug' ) !== $slug ) {
			update_option( 'sidcraft_commerce_events_slug', $slug, true );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Front-end styles and scripts (loaded only where an event Unit is used).
	 *
	 * @param \SidcraftCommerce\Assets $assets Assets.
	 */
	public function register_assets( $assets ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Action signature.
		$url = SIDCRAFT_COMMERCE_URL . 'assets/';
		wp_register_style( 'sidccofo-events', $url . 'css/sc-events.css', array( 'sidccofo-commerce' ), SIDCRAFT_COMMERCE_VERSION );
		wp_register_script(
			'sidccofo-events',
			$url . 'js/sc-events.js',
			array( 'sidccofo-cart' ),
			SIDCRAFT_COMMERCE_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'sidccofo-events',
			'sidccofoEvents',
			array(
				'i18n' => array(
					'adding'    => __( 'Adding tickets…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'choose'    => __( 'Choose at least one ticket.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'uploading' => __( 'Uploading…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'uploaded'  => __( 'Uploaded', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'error'     => __( 'Something went wrong. Please try again.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'free'      => __( 'Free', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'copied'    => __( 'Copied', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					/* translators: %s: total price. */
					'oneTicket'   => __( '1 ticket · %s', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					/* translators: 1: number of tickets, 2: total price. */
					'manyTickets' => __( '%1$d tickets · %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
			)
		);
	}

	/**
	 * Add the event Units to the core list.
	 *
	 * @param array $units Units.
	 * @return array
	 */
	public function units( $units ) {
		$units[] = new Units\Event_List();
		$units[] = new Units\Event_Calendar();
		$units[] = new Units\Event_Details();
		$units[] = new Units\Event_Tickets();
		$units[] = new Units\Event_Map();
		$units[] = new Units\My_Tickets();
		return $units;
	}

	/**
	 * Upcoming (or past) published events.
	 *
	 * @param array $args when (upcoming|past|all), category (slug), limit, from, to (Y-m-d H:i bounds on the start/end).
	 * @return Event[]
	 */
	public static function query( array $args = array() ) {
		$args  = wp_parse_args(
			$args,
			array(
				'when'     => 'upcoming',
				'category' => '',
				'limit'    => 12,
				'from'     => '',
				'to'       => '',
			)
		);
		$now   = wp_date( 'Y-m-d H:i' );
		$query = array(
			'post_type'      => self::EVENT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $args['limit'] > 0 ? (int) $args['limit'] : 100,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_sidccofo_ev_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorted by start date.
			'orderby'        => 'meta_value',
			'order'          => 'past' === $args['when'] ? 'DESC' : 'ASC',
			'meta_query'     => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
		if ( 'upcoming' === $args['when'] ) {
			$query['meta_query'][] = array(
				'key'     => '_sidccofo_ev_ends',
				'value'   => $now,
				'compare' => '>=',
			);
		} elseif ( 'past' === $args['when'] ) {
			$query['meta_query'][] = array(
				'key'     => '_sidccofo_ev_ends',
				'value'   => $now,
				'compare' => '<',
			);
		}
		if ( '' !== $args['to'] ) {
			$query['meta_query'][] = array(
				'key'     => '_sidccofo_ev_start',
				'value'   => $args['to'],
				'compare' => '<=',
			);
		}
		if ( '' !== $args['from'] ) {
			$query['meta_query'][] = array(
				'key'     => '_sidccofo_ev_ends',
				'value'   => $args['from'],
				'compare' => '>=',
			);
		}
		if ( '' !== (string) $args['category'] ) {
			$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => self::CATEGORY,
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', explode( ',', (string) $args['category'] ) ),
				),
			);
		}

		/**
		 * Filter the event query.
		 *
		 * @param array $query WP_Query args.
		 * @param array $args  Request.
		 */
		$query = apply_filters( 'sidcraft_commerce_events_query_args', $query, $args );
		return array_values( array_filter( array_map( array( Event::class, 'get' ), get_posts( $query ) ) ) );
	}

	/**
	 * The event a single-event Unit should show: explicit ID, else the current event page.
	 *
	 * @param array $settings Unit settings.
	 * @return Event|null
	 */
	public static function resolve( array $settings = array() ) {
		$event = null;
		if ( ! empty( $settings['event_id'] ) ) {
			$event = Event::get( absint( $settings['event_id'] ) );
		}
		if ( ! $event ) {
			$post = get_post();
			if ( $post && self::EVENT === $post->post_type ) {
				$event = Event::get( $post->ID );
			}
		}
		return apply_filters( 'sidcraft_commerce_resolve_event', $event, $settings );
	}

	/**
	 * Template shortcut.
	 *
	 * @param string $name Template.
	 * @param array  $args Args.
	 * @return string
	 */
	public static function template( $name, array $args = array() ) {
		return Plugin::instance()->templates->render( $name, $args );
	}
}
