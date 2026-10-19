<?php
/**
 * Calendar files: one event as .ics (Apple, Outlook, Google import) and a
 * subscription feed of all upcoming events that Google Calendar, Apple
 * Calendar and Outlook keep in sync.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Class Calendar
 */
class Calendar {

	/**
	 * Escape a text value.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	protected static function esc( $s ) {
		$s = wp_strip_all_tags( (string) $s );
		return str_replace( array( '\\', ';', ',', "\r\n", "\n", "\r" ), array( '\\\\', '\\;', '\\,', '\\n', '\\n', '' ), $s );
	}

	/**
	 * Fold a content line at 75 octets.
	 *
	 * @param string $line Line.
	 * @return string
	 */
	protected static function fold( $line ) {
		$out = '';
		while ( strlen( $line ) > 75 ) {
			$cut = 75;
			// Never split a UTF-8 sequence.
			while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
				--$cut;
			}
			$out .= substr( $line, 0, $cut ) . "\r\n ";
			$line = substr( $line, $cut );
		}
		return $out . $line . "\r\n";
	}

	/**
	 * VEVENT lines for an event.
	 *
	 * @param Event  $event     Event.
	 * @param bool   $with_join Include the join link (for ticket holders).
	 * @param string $extra     Extra description text (e.g. ticket code).
	 * @return string
	 */
	public static function vevent( Event $event, $with_join = false, $extra = '' ) {
		$start = $event->get_start();
		if ( ! $start ) {
			return '';
		}
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$desc  = $event->get_summary( 60 );
		$join  = $with_join ? $event->get_join_url() : '';
		if ( '' !== $join ) {
			/* translators: %s: URL. */
			$desc .= "\n\n" . sprintf( __( 'Join online: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $join );
			$zoom  = $event->get_zoom();
			if ( '' !== $zoom['id'] ) {
				/* translators: 1: meeting ID, 2: passcode. */
				$desc .= "\n" . sprintf( __( 'Meeting ID: %1$s  Passcode: %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $zoom['id'], $zoom['passcode'] );
			}
		}
		if ( '' !== $extra ) {
			$desc .= "\n\n" . $extra;
		}
		$desc .= "\n\n" . $event->get_permalink();

		$lines   = array();
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:sidccofo-event-' . $event->get_id() . '@' . $host;
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
		$lines[] = 'LAST-MODIFIED:' . gmdate( 'Ymd\THis\Z', (int) get_post_modified_time( 'U', true, $event->get_post() ) );
		if ( $event->is_all_day() ) {
			$lines[] = 'DTSTART;VALUE=DATE:' . wp_date( 'Ymd', $start );
			$lines[] = 'DTEND;VALUE=DATE:' . wp_date( 'Ymd', $event->get_end() + 1 );
		} else {
			$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis\Z', $start );
			$lines[] = 'DTEND:' . gmdate( 'Ymd\THis\Z', $event->get_end() );
		}
		$lines[] = 'SUMMARY:' . self::esc( $event->get_name() );
		$lines[] = 'DESCRIPTION:' . self::esc( $desc );
		$location = $event->has_location() ? $event->get_location_label() : $join;
		if ( '' !== $location ) {
			$lines[] = 'LOCATION:' . self::esc( $location );
		}
		if ( $event->has_coordinates() ) {
			$lines[] = 'GEO:' . (float) $event->meta( 'lat' ) . ';' . (float) $event->meta( 'lng' );
		}
		$lines[] = 'URL:' . self::esc( $event->get_permalink() );
		if ( '' !== $event->get_organizer_email() ) {
			$lines[] = 'ORGANIZER;CN=' . self::esc( '' !== $event->get_organizer() ? $event->get_organizer() : get_bloginfo( 'name' ) ) . ':mailto:' . sanitize_email( $event->get_organizer_email() );
		}
		$lines[] = 'STATUS:CONFIRMED';
		$lines[] = 'END:VEVENT';

		return implode( '', array_map( array( __CLASS__, 'fold' ), $lines ) );
	}

	/**
	 * Wrap VEVENTs in a VCALENDAR.
	 *
	 * @param string $events VEVENT lines.
	 * @param string $name   Calendar name.
	 * @return string
	 */
	public static function wrap( $events, $name = '' ) {
		$head = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Sidcraft Commerce//Events//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
		);
		if ( '' !== $name ) {
			$head[] = 'X-WR-CALNAME:' . self::esc( $name );
			$head[] = 'X-WR-TIMEZONE:' . wp_timezone_string();
			$head[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT6H';
		}
		return implode( '', array_map( array( __CLASS__, 'fold' ), $head ) ) . $events . "END:VCALENDAR\r\n";
	}

	/**
	 * One event as an .ics file.
	 *
	 * @param Event  $event     Event.
	 * @param bool   $with_join Include the join link.
	 * @param string $extra     Extra description.
	 * @return string
	 */
	public static function event_ics( Event $event, $with_join = false, $extra = '' ) {
		return self::wrap( self::vevent( $event, $with_join, $extra ) );
	}

	/**
	 * Feed of upcoming events (and the last 30 days).
	 *
	 * @param string $category Category slug.
	 * @return string
	 */
	public static function feed( $category = '' ) {
		$events = Events::query(
			array(
				'when'     => 'all',
				'category' => $category,
				'limit'    => 300,
				'from'     => wp_date( 'Y-m-d H:i', time() - 30 * DAY_IN_SECONDS ),
			)
		);
		$body   = '';
		foreach ( $events as $event ) {
			$body .= self::vevent( $event );
		}
		return self::wrap( $body, get_bloginfo( 'name' ) );
	}

	/**
	 * Feed URL (webcal:// for one-click subscribe).
	 *
	 * @param string $category Category slug.
	 * @param bool   $webcal   Use webcal://.
	 * @return string
	 */
	public static function feed_url( $category = '', $webcal = false ) {
		$args = array( 'sidccofo_events_ics' => 1 );
		if ( '' !== $category ) {
			$args['cat'] = sanitize_title( $category );
		}
		$url = add_query_arg( $args, home_url( '/' ) );
		return $webcal ? preg_replace( '#^https?://#', 'webcal://', $url ) : $url;
	}

	/**
	 * "Subscribe in Google Calendar" link for the feed.
	 *
	 * @param string $category Category slug.
	 * @return string
	 */
	public static function google_subscribe_url( $category = '' ) {
		return 'https://calendar.google.com/calendar/render?cid=' . rawurlencode( self::feed_url( $category, true ) );
	}

	/**
	 * Month grid data.
	 *
	 * @param string $month    Y-m.
	 * @param string $category Category slug.
	 * @return array{month:string,weeks:array,prev:string,next:string,label:string}
	 */
	public static function month( $month, $category = '' ) {
		$tz    = wp_timezone();
		$first = \DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', $tz );
		if ( ! $first ) {
			$first = new \DateTimeImmutable( 'first day of this month 00:00', $tz );
		}
		$last      = $first->modify( 'last day of this month' )->setTime( 23, 59 );
		$start_dow = (int) get_option( 'start_of_week', 1 );
		$offset    = ( (int) $first->format( 'w' ) - $start_dow + 7 ) % 7;
		$grid_from = $first->modify( '-' . $offset . ' days' );

		$events = Events::query(
			array(
				'when'     => 'all',
				'category' => $category,
				'limit'    => 300,
				'from'     => $grid_from->format( 'Y-m-d H:i' ),
				'to'       => $last->modify( '+6 days' )->format( 'Y-m-d H:i' ),
			)
		);

		$by_day = array();
		foreach ( $events as $event ) {
			$s   = ( new \DateTimeImmutable( '@' . $event->get_start() ) )->setTimezone( $tz )->setTime( 0, 0 );
			$e   = ( new \DateTimeImmutable( '@' . $event->get_end() ) )->setTimezone( $tz )->setTime( 0, 0 );
			$day = $s;
			$guard = 0;
			while ( $day <= $e && $guard < 62 ) {
				$by_day[ $day->format( 'Y-m-d' ) ][] = $event;
				$day = $day->modify( '+1 day' );
				++$guard;
			}
		}

		$weeks = array();
		$day   = $grid_from;
		do {
			$week = array();
			for ( $i = 0; $i < 7; $i++ ) {
				$key    = $day->format( 'Y-m-d' );
				$week[] = array(
					'date'    => $key,
					'day'     => (int) $day->format( 'j' ),
					'in'      => $day->format( 'Y-m' ) === $first->format( 'Y-m' ),
					'today'   => wp_date( 'Y-m-d' ) === $key,
					'events'  => $by_day[ $key ] ?? array(),
				);
				$day = $day->modify( '+1 day' );
			}
			$weeks[] = $week;
		} while ( $day <= $last );

		$names = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$names[] = $GLOBALS['wp_locale']->get_weekday_abbrev( $GLOBALS['wp_locale']->get_weekday( ( $start_dow + $i ) % 7 ) );
		}

		return array(
			'month'    => $first->format( 'Y-m' ),
			'label'    => wp_date( 'F Y', $first->getTimestamp() ),
			'prev'     => $first->modify( '-1 month' )->format( 'Y-m' ),
			'next'     => $first->modify( '+1 month' )->format( 'Y-m' ),
			'weeks'    => $weeks,
			'weekdays' => $names,
			'events'   => $events,
		);
	}
}
