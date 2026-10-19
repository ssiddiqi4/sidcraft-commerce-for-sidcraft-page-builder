<?php
/**
 * Event model: a typed wrapper around an sidccofo_event post.
 *
 * Stored meta (prefix _sidccofo_ev_):
 *   start, end (Y-m-d H:i, site time zone), all_day,
 *   venue, address, lat, lng, show_map,
 *   online, online_url, zoom_url, zoom_id, zoom_passcode, zoom_auto,
 *   whatsapp_group, whatsapp_number,
 *   organizer, organizer_email,
 *   capacity (0 = no event-wide limit), close_at_start, show_remaining,
 *   attendee_info (buyer|name|name_email|name_email_phone),
 *   tickets (array of ticket types), fields (array of attendee fields),
 *   hide_auto (layout built with Sidcraft Page Builder), reminded (ts).
 *
 * Each ticket type is sold through a hidden placeholder product, so stock,
 * currency, tax, coupons, gift cards and every payment gateway work as for
 * any other product (see Placeholders).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use function SidcraftCommerce\format_price;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Event
 */
class Event {

	/**
	 * Post.
	 *
	 * @var \WP_Post
	 */
	protected $post;

	/**
	 * Per-request cache.
	 *
	 * @var Event[]
	 */
	protected static $cache = array();

	/**
	 * Get an event.
	 *
	 * @param int $id Post ID.
	 * @return Event|null
	 */
	public static function get( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		// A translation is the original event (one set of tickets and seats),
		// shown in the visitor's language.
		$id  = \SidcraftCommerce\Multilingual::original_id( $id );
		$key = get_current_blog_id() . ':' . $id . '|' . \SidcraftCommerce\Multilingual::current_language();
		if ( ! isset( self::$cache[ $key ] ) ) {
			$post = get_post( $id );
			if ( ! $post || Events::EVENT !== $post->post_type ) {
				return null;
			}
			self::$cache[ $key ] = new self( $post );
		}
		return self::$cache[ $key ];
	}

	/**
	 * Drop from the cache (after saving).
	 *
	 * @param int $id Post ID.
	 */
	public static function forget( $id ) {
		$id = absint( $id );
		foreach ( array_unique( array( $id, \SidcraftCommerce\Multilingual::original_id( $id ) ) ) as $one ) {
			foreach ( array_keys( self::$cache ) as $key ) {
				if ( 0 === strpos( $key, get_current_blog_id() . ':' . $one . '|' ) ) {
					unset( self::$cache[ $key ] );
				}
			}
		}
	}

	/**
	 * Meta that can be translated: the translation's own value wins when set.
	 */
	const TRANSLATABLE = array( 'venue', 'address', 'organizer' );

	/**
	 * The post shown to visitors: the translation in the current language,
	 * else the event itself.
	 *
	 * @return \WP_Post
	 */
	public function get_display_post() {
		if ( null === $this->display ) {
			$this->display = $this->post;
			$id            = \SidcraftCommerce\Multilingual::current_id( $this->post->ID );
			if ( $id !== (int) $this->post->ID ) {
				$tr = get_post( $id );
				if ( $tr && Events::EVENT === $tr->post_type ) {
					$this->display = $tr;
				}
			}
		}
		return $this->display;
	}

	/**
	 * Translation shown to visitors.
	 *
	 * @var \WP_Post|null
	 */
	protected $display = null;

	/**
	 * Constructor.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function __construct( \WP_Post $post ) {
		$this->post = $post;
	}

	/**
	 * Meta value.
	 *
	 * @param string $key Key without prefix.
	 * @return mixed
	 */
	public function meta( $key ) {
		if ( in_array( $key, self::TRANSLATABLE, true ) && $this->get_display_post() !== $this->post ) {
			$own = get_post_meta( $this->get_display_post()->ID, '_sidccofo_ev_' . $key, true );
			if ( '' !== trim( (string) $own ) ) {
				return $own;
			}
		}
		return get_post_meta( $this->post->ID, '_sidccofo_ev_' . $key, true );
	}

	/**
	 * ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return (int) $this->post->ID;
	}

	/**
	 * Post.
	 *
	 * @return \WP_Post
	 */
	public function get_post() {
		return $this->post;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_name() {
		return get_the_title( $this->get_display_post() );
	}

	/**
	 * Permalink.
	 *
	 * @return string
	 */
	public function get_permalink() {
		return (string) get_permalink( $this->get_display_post() );
	}

	/**
	 * Published?
	 *
	 * @return bool
	 */
	public function is_published() {
		return 'publish' === $this->post->post_status;
	}

	/**
	 * Short summary (excerpt or trimmed content).
	 *
	 * @param int $words Words.
	 * @return string
	 */
	public function get_summary( $words = 30 ) {
		$post = $this->get_display_post();
		$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		return wp_trim_words( $text, $words );
	}

	/*
	------------------------------------------------------------------
	 * Dates
	 * ----------------------------------------------------------------
	 */

	/**
	 * Parse a stored local date-time into a timestamp.
	 *
	 * @param string $local Y-m-d H:i (or Y-m-d).
	 * @return int 0 when empty.
	 */
	public static function to_ts( $local ) {
		$local = trim( (string) $local );
		if ( '' === $local ) {
			return 0;
		}
		$fmt = strlen( $local ) > 10 ? 'Y-m-d H:i' : 'Y-m-d';
		$dt  = \DateTimeImmutable::createFromFormat( '!' . $fmt, substr( $local, 0, 16 ), wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/**
	 * All-day event?
	 *
	 * @return bool
	 */
	public function is_all_day() {
		return (bool) $this->meta( 'all_day' );
	}

	/**
	 * Start timestamp.
	 *
	 * @return int
	 */
	public function get_start() {
		return self::to_ts( (string) $this->meta( 'start' ) );
	}

	/**
	 * End timestamp (start + 2 h when not set; end of day for all-day events).
	 *
	 * @return int
	 */
	public function get_end() {
		$end   = self::to_ts( (string) $this->meta( 'end' ) );
		$start = $this->get_start();
		if ( $this->is_all_day() ) {
			$day = $end ? $end : $start;
			return $day ? (int) ( new \DateTimeImmutable( '@' . $day ) )->setTimezone( wp_timezone() )->setTime( 23, 59, 59 )->getTimestamp() : 0;
		}
		if ( $end && $end >= $start ) {
			return $end;
		}
		return $start ? $start + 2 * HOUR_IN_SECONDS : 0;
	}

	/**
	 * Has the event finished?
	 *
	 * @return bool
	 */
	public function is_past() {
		$end = $this->get_end();
		return $end && $end < time();
	}

	/**
	 * Has it started?
	 *
	 * @return bool
	 */
	public function has_started() {
		$start = $this->get_start();
		return $start && $start <= time();
	}

	/**
	 * Date range for display, e.g. "Sat, Nov 14, 2026 · 7:00 pm – 10:00 pm".
	 *
	 * @param bool $with_time Include times.
	 * @return string
	 */
	public function get_date_label( $with_time = true ) {
		$start = $this->get_start();
		if ( ! $start ) {
			return '';
		}
		$end      = $this->get_end();
		$dfmt     = (string) apply_filters( 'sidcraft_commerce_event_date_format', 'D, ' . get_option( 'date_format' ) );
		$tfmt     = (string) get_option( 'time_format' );
		$same_day = wp_date( 'Y-m-d', $start ) === wp_date( 'Y-m-d', $end );

		if ( $this->is_all_day() ) {
			return $same_day ? wp_date( $dfmt, $start ) : wp_date( $dfmt, $start ) . ' – ' . wp_date( $dfmt, $end );
		}
		if ( ! $with_time ) {
			return wp_date( $dfmt, $start );
		}
		if ( $same_day ) {
			return wp_date( $dfmt, $start ) . ' · ' . wp_date( $tfmt, $start ) . ' – ' . wp_date( $tfmt, $end );
		}
		return wp_date( $dfmt . ' ' . $tfmt, $start ) . ' – ' . wp_date( $dfmt . ' ' . $tfmt, $end );
	}

	/**
	 * Time-zone label (e.g. "EDT"), shown next to times for online events.
	 *
	 * @return string
	 */
	public function get_timezone_label() {
		$start = $this->get_start();
		return $start ? wp_date( 'T', $start ) : '';
	}

	/*
	------------------------------------------------------------------
	 * Place
	 * ----------------------------------------------------------------
	 */

	/**
	 * Venue name.
	 *
	 * @return string
	 */
	public function get_venue() {
		return (string) $this->meta( 'venue' );
	}

	/**
	 * Address.
	 *
	 * @return string
	 */
	public function get_address() {
		return (string) $this->meta( 'address' );
	}

	/**
	 * Venue + address on one line.
	 *
	 * @return string
	 */
	public function get_location_label() {
		return trim( implode( ', ', array_filter( array( $this->get_venue(), $this->get_address() ) ) ) );
	}

	/**
	 * Has a physical place.
	 *
	 * @return bool
	 */
	public function has_location() {
		return '' !== $this->get_address() || '' !== $this->get_venue() || $this->has_coordinates();
	}

	/**
	 * Has latitude/longitude.
	 *
	 * @return bool
	 */
	public function has_coordinates() {
		return is_numeric( $this->meta( 'lat' ) ) && is_numeric( $this->meta( 'lng' ) );
	}

	/**
	 * Show the map on the event page.
	 *
	 * @return bool
	 */
	public function shows_map() {
		$flag = $this->meta( 'show_map' );
		return ( '' === $flag || (bool) $flag ) && $this->has_location() && 'none' !== setting( 'ev_map_provider', 'google' );
	}

	/**
	 * Map iframe URL (Google Maps or OpenStreetMap).
	 *
	 * @return string
	 */
	public function get_map_embed_url() {
		return self::map_embed_url( $this->get_location_label(), $this->meta( 'lat' ), $this->meta( 'lng' ) );
	}

	/**
	 * Map iframe URL for a place. Shared with the event screen's location
	 * preview (sc-events-admin.js builds the same URLs while typing).
	 *
	 * @param string $label Venue + address.
	 * @param mixed  $lat   Latitude ('' = none).
	 * @param mixed  $lng   Longitude ('' = none).
	 * @return string
	 */
	public static function map_embed_url( $label, $lat, $lng ) {
		$provider = (string) setting( 'ev_map_provider', 'google' );
		$coords   = is_numeric( $lat ) && is_numeric( $lng );
		$query    = $coords ? $lat . ',' . $lng : (string) $label;
		if ( 'osm' === $provider && $coords ) {
			$lat = (float) $lat;
			$lng = (float) $lng;
			$d   = 0.006;
			return add_query_arg(
				array(
					'bbox'   => rawurlencode( ( $lng - $d ) . ',' . ( $lat - $d ) . ',' . ( $lng + $d ) . ',' . ( $lat + $d ) ),
					'layer'  => 'mapnik',
					'marker' => rawurlencode( $lat . ',' . $lng ),
				),
				'https://www.openstreetmap.org/export/embed.html'
			);
		}
		$key = trim( (string) setting( 'ev_google_maps_key', '' ) );
		if ( '' !== $key ) {
			return add_query_arg(
				array(
					'key' => rawurlencode( $key ),
					'q'   => rawurlencode( $query ),
				),
				'https://www.google.com/maps/embed/v1/place'
			);
		}
		return add_query_arg(
			array(
				'q'      => rawurlencode( $query ),
				'output' => 'embed',
			),
			'https://maps.google.com/maps'
		);
	}

	/**
	 * "Get directions" link.
	 *
	 * @return string
	 */
	public function get_directions_url() {
		$query = $this->has_coordinates() ? $this->meta( 'lat' ) . ',' . $this->meta( 'lng' ) : $this->get_location_label();
		return add_query_arg(
			array(
				'api'         => 1,
				'destination' => rawurlencode( $query ),
			),
			'https://www.google.com/maps/dir/'
		);
	}

	/*
	------------------------------------------------------------------
	 * Online
	 * ----------------------------------------------------------------
	 */

	/**
	 * Online (or hybrid) event.
	 *
	 * @return bool
	 */
	public function is_online() {
		return (bool) $this->meta( 'online' );
	}

	/**
	 * Join link for ticket holders (Zoom first, else the generic link).
	 *
	 * @return string
	 */
	public function get_join_url() {
		$zoom = (string) $this->meta( 'zoom_url' );
		return '' !== $zoom ? $zoom : (string) $this->meta( 'online_url' );
	}

	/**
	 * Zoom meeting details for ticket holders.
	 *
	 * @return array{url:string,id:string,passcode:string}
	 */
	public function get_zoom() {
		return array(
			'url'      => (string) $this->meta( 'zoom_url' ),
			'id'       => (string) $this->meta( 'zoom_id' ),
			'passcode' => (string) $this->meta( 'zoom_passcode' ),
		);
	}

	/**
	 * WhatsApp group invite link (for ticket holders).
	 *
	 * @return string
	 */
	public function get_whatsapp_group() {
		return (string) $this->meta( 'whatsapp_group' );
	}

	/**
	 * Organiser WhatsApp number, digits only.
	 *
	 * @return string
	 */
	public function get_whatsapp_number() {
		return preg_replace( '/\D+/', '', (string) $this->meta( 'whatsapp_number' ) );
	}

	/**
	 * "Message the organiser on WhatsApp" link.
	 *
	 * @return string
	 */
	public function get_whatsapp_chat_url() {
		$number = $this->get_whatsapp_number();
		if ( '' === $number ) {
			return '';
		}
		/* translators: %s: event name. */
		return 'https://wa.me/' . $number . '?text=' . rawurlencode( sprintf( __( 'Hi! I have a question about %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_name() ) );
	}

	/**
	 * Organiser name.
	 *
	 * @return string
	 */
	public function get_organizer() {
		return (string) $this->meta( 'organizer' );
	}

	/**
	 * Organiser email.
	 *
	 * @return string
	 */
	public function get_organizer_email() {
		return (string) $this->meta( 'organizer_email' );
	}

	/*
	------------------------------------------------------------------
	 * Tickets and capacity
	 * ----------------------------------------------------------------
	 */

	/**
	 * Ticket types, normalised.
	 *
	 * @return array[] id => [ id, name, attendee_type, description, price, capacity, min, max,
	 *                 sale_start, sale_end, rules, product_id ].
	 */
	public function get_ticket_types() {
		$raw = $this->meta( 'tickets' );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			$type = self::normalize_ticket_type( (array) $row );
			if ( '' !== $type['id'] ) {
				$out[ $type['id'] ] = $type;
			}
		}

		/**
		 * Filter an event's ticket types.
		 *
		 * @param array[] $out   Ticket types.
		 * @param Event   $event Event.
		 */
		return (array) apply_filters( 'sidcraft_commerce_event_ticket_types', $out, $this );
	}

	/**
	 * One ticket type.
	 *
	 * @param string $id Ticket type id.
	 * @return array|null
	 */
	public function get_ticket_type( $id ) {
		$types = $this->get_ticket_types();
		return $types[ (string) $id ] ?? null;
	}

	/**
	 * Fill defaults for a ticket type row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function normalize_ticket_type( array $row ) {
		return array(
			'id'            => sanitize_key( (string) ( $row['id'] ?? '' ) ),
			'name'          => (string) ( $row['name'] ?? '' ),
			'attendee_type' => (string) ( $row['attendee_type'] ?? '' ),
			'description'   => (string) ( $row['description'] ?? '' ),
			'price'         => (string) ( $row['price'] ?? '' ),
			'capacity'      => max( 0, (int) ( $row['capacity'] ?? 0 ) ),
			'min'           => max( 0, (int) ( $row['min'] ?? 0 ) ),
			'max'           => max( 0, (int) ( $row['max'] ?? 0 ) ),
			'sale_start'    => (string) ( $row['sale_start'] ?? '' ),
			'sale_end'      => (string) ( $row['sale_end'] ?? '' ),
			'rules'         => (string) ( $row['rules'] ?? '' ),
			'product_id'    => absint( $row['product_id'] ?? 0 ),
		);
	}

	/**
	 * Event-wide seat limit (0 = none; each ticket type may still have its own).
	 *
	 * @return int
	 */
	public function get_capacity() {
		return max( 0, (int) $this->meta( 'capacity' ) );
	}

	/**
	 * Seats held by orders (placed, paid, on hold), across all ticket types.
	 *
	 * @return int
	 */
	public function get_held() {
		$held = 0;
		foreach ( $this->get_ticket_types() as $type ) {
			if ( $type['product_id'] ) {
				$held += Placeholders::held( $type['product_id'] );
			}
		}
		return $held;
	}

	/**
	 * Seats left event-wide (null = unlimited).
	 *
	 * @return int|null
	 */
	public function get_remaining() {
		$cap = $this->get_capacity();
		return $cap ? max( 0, $cap - $this->get_held() ) : null;
	}

	/**
	 * Seats left for one ticket type, taking the event limit into account
	 * (null = unlimited).
	 *
	 * @param array $type Ticket type.
	 * @return int|null
	 */
	public function get_type_remaining( array $type ) {
		$left = null;
		if ( $type['capacity'] ) {
			$left = max( 0, $type['capacity'] - ( $type['product_id'] ? Placeholders::held( $type['product_id'] ) : 0 ) );
		}
		$event_left = $this->get_remaining();
		if ( null !== $event_left ) {
			$left = null === $left ? $event_left : min( $left, $event_left );
		}
		return $left;
	}

	/**
	 * Is a ticket type on sale right now? Returns '' when yes, else the reason.
	 *
	 * @param array $type Ticket type.
	 * @return string
	 */
	public function type_unavailable_reason( array $type ) {
		if ( ! $this->is_published() ) {
			return __( 'This event is not open for registration.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( '' === trim( $type['price'] ) ) {
			return __( 'Not on sale yet.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		$now = time();
		$end = self::to_ts( $type['sale_end'] );
		// A ticket type's own "Sales end" wins over "Stop selling when the event starts".
		if ( $this->is_past() || ( ! $end && $this->closes_at_start() && $this->has_started() ) ) {
			return __( 'Registration has closed.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		$start = self::to_ts( $type['sale_start'] );
		if ( $start && $start > $now ) {
			/* translators: %s: date and time. */
			return sprintf( __( 'On sale from %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start ) );
		}
		if ( $end && $end < $now ) {
			return __( 'Sales have ended.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		$left = $this->get_type_remaining( $type );
		if ( null !== $left && $left < 1 ) {
			return __( 'Sold out.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		return '';
	}

	/**
	 * Registration closes when the event starts (default yes).
	 *
	 * @return bool
	 */
	public function closes_at_start() {
		$flag = $this->meta( 'close_at_start' );
		return '' === $flag || (bool) $flag;
	}

	/**
	 * Show "N left".
	 *
	 * @return bool
	 */
	public function shows_remaining() {
		$flag = $this->meta( 'show_remaining' );
		return '' === $flag ? (bool) setting( 'ev_show_remaining', 1 ) : (bool) $flag;
	}

	/**
	 * Anything on sale?
	 *
	 * @return bool
	 */
	public function is_on_sale() {
		foreach ( $this->get_ticket_types() as $type ) {
			if ( '' === $this->type_unavailable_reason( $type ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every ticket type sold out?
	 *
	 * @return bool
	 */
	public function is_sold_out() {
		$types = $this->get_ticket_types();
		if ( ! $types ) {
			return false;
		}
		foreach ( $types as $type ) {
			$left = $this->get_type_remaining( $type );
			if ( null === $left || $left > 0 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * "From $20" / "Free" / '' for listings.
	 *
	 * @return string
	 */
	public function get_price_label() {
		$prices = array();
		foreach ( $this->get_ticket_types() as $type ) {
			if ( '' !== trim( $type['price'] ) ) {
				$prices[] = Pricing::shopper( $this, $type )['price'];
			}
		}
		if ( ! $prices ) {
			return '';
		}
		$min = min( $prices );
		$max = max( $prices );
		if ( $max <= 0 ) {
			return __( 'Free', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( $min === $max ) {
			return format_price( $min );
		}
		if ( $min <= 0 ) {
			/* translators: %s: highest price. */
			return sprintf( __( 'Free – %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $max ) );
		}
		/* translators: %s: lowest price. */
		return sprintf( __( 'From %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $min ) );
	}

	/*
	------------------------------------------------------------------
	 * Attendee information
	 * ----------------------------------------------------------------
	 */

	/**
	 * What to ask for each ticket: buyer | name | name_email | name_email_phone.
	 *
	 * @return string
	 */
	public function get_attendee_info() {
		$mode = (string) $this->meta( 'attendee_info' );
		return in_array( $mode, array( 'buyer', 'name', 'name_email', 'name_email_phone' ), true ) ? $mode : 'name';
	}

	/**
	 * Custom attendee fields.
	 *
	 * @return array[] Each: id, label, type, required, options (string[]), help.
	 */
	public function get_fields() {
		$raw = $this->meta( 'fields' );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			$row = (array) $row;
			$id  = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id || '' === trim( (string) ( $row['label'] ?? '' ) ) ) {
				continue;
			}
			$type = (string) ( $row['type'] ?? 'text' );
			$out[ $id ] = array(
				'id'       => $id,
				'label'    => (string) $row['label'],
				'type'     => in_array( $type, self::field_types(), true ) ? $type : 'text',
				'required' => ! empty( $row['required'] ),
				'options'  => array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $row['options'] ?? '' ) ) ), 'strlen' ) ),
				'help'     => (string) ( $row['help'] ?? '' ),
			);
		}

		/**
		 * Filter the attendee fields collected for an event's tickets.
		 *
		 * @param array[] $out   Fields.
		 * @param Event   $event Event.
		 */
		return (array) apply_filters( 'sidcraft_commerce_event_attendee_fields', $out, $this );
	}

	/**
	 * Field types offered.
	 *
	 * @return string[]
	 */
	public static function field_types() {
		return array( 'text', 'textarea', 'email', 'tel', 'number', 'date', 'select', 'radio', 'checkbox', 'file' );
	}

	/**
	 * Does checkout need an attendee section for this event?
	 *
	 * @return bool
	 */
	public function collects_attendees() {
		return 'buyer' !== $this->get_attendee_info() || (bool) $this->get_fields();
	}

	/*
	------------------------------------------------------------------
	 * Calendar links
	 * ----------------------------------------------------------------
	 */

	/**
	 * "Add to Google Calendar" link.
	 *
	 * @param bool $with_join Include the join link (ticket holders only).
	 * @return string
	 */
	public function get_google_calendar_url( $with_join = false ) {
		$start = $this->get_start();
		if ( ! $start ) {
			return '';
		}
		if ( $this->is_all_day() ) {
			$dates = wp_date( 'Ymd', $start ) . '/' . wp_date( 'Ymd', $this->get_end() + 1 );
		} else {
			$dates = gmdate( 'Ymd\THis\Z', $start ) . '/' . gmdate( 'Ymd\THis\Z', $this->get_end() );
		}
		$details = $this->get_summary( 40 ) . "\n\n" . $this->get_permalink();
		if ( $with_join && '' !== $this->get_join_url() ) {
			/* translators: %s: URL. */
			$details .= "\n\n" . sprintf( __( 'Join: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $this->get_join_url() );
		}
		return add_query_arg(
			array(
				'action'   => 'TEMPLATE',
				'text'     => rawurlencode( $this->get_name() ),
				'dates'    => $dates,
				'details'  => rawurlencode( $details ),
				'location' => rawurlencode( $this->has_location() ? $this->get_location_label() : $this->get_join_url() ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/**
	 * .ics download link.
	 *
	 * @return string
	 */
	public function get_ics_url() {
		return add_query_arg( 'sidccofo_event_ics', $this->get_id(), home_url( '/' ) );
	}
}
