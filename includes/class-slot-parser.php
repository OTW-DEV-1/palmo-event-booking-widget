<?php
namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses the admin slot-definition textarea into concrete slot rows.
 *
 * Line format:  DATE | TIME(S) | CAPACITY
 *
 *   2026-09-01 | 09:00            | 25     one slot, ending after the default length
 *   2026-09-01 | 09:00,10:00      | 25     two slots, same capacity
 *   2026-09-01 | 09:00-13:00      | 25     ONE slot running 09:00 to 13:00
 *   2026-09-01 | 09:00-13:00 /30  | 25     that range split into 30 minute slots
 *   2026-09-02 | 10:00            |        capacity falls back to the event default
 *   # anything after a hash is a comment
 *
 * Dates accept Y-m-d or d/m/Y. Times accept H:MM or HH:MM.
 */
class Slot_Parser {

	const DEFAULT_DURATION = 30;

	/**
	 * @return array{slots:array<int,array{date:string,time:string,end:?string,capacity:int}>,errors:string[]}
	 */
	public static function parse( $text, $default_capacity = 25, $default_duration = self::DEFAULT_DURATION ) {
		$slots  = array();
		$errors = array();
		$seen   = array();

		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );

		foreach ( $lines as $index => $raw_line ) {
			$line_no = $index + 1;

			// Strip comments and surrounding whitespace.
			$line = trim( preg_replace( '/#.*$/', '', $raw_line ) );
			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );

			if ( count( $parts ) < 2 ) {
				/* translators: %d: line number in the slot definition textarea. */
				$errors[] = sprintf( __( 'Line %d: expected "date | time | capacity".', 'event-booking-slots' ), $line_no );
				continue;
			}

			$date = self::normalize_date( $parts[0] );
			if ( ! $date ) {
				/* translators: 1: line number, 2: the unrecognised date text. */
				$errors[] = sprintf( __( 'Line %1$d: "%2$s" is not a valid date.', 'event-booking-slots' ), $line_no, $parts[0] );
				continue;
			}

			$capacity = isset( $parts[2] ) && '' !== $parts[2] ? absint( $parts[2] ) : absint( $default_capacity );

			$times = self::expand_times( $parts[1], $default_duration );
			if ( is_wp_error( $times ) ) {
				/* translators: 1: line number, 2: the parser error message. */
				$errors[] = sprintf( __( 'Line %1$d: %2$s', 'event-booking-slots' ), $line_no, $times->get_error_message() );
				continue;
			}

			foreach ( $times as $time ) {
				$key = $date . ' ' . $time['time'];

				// A duplicated date+time would collide on the table's UNIQUE key, so
				// collapse it here and tell the admin rather than failing at save time.
				if ( isset( $seen[ $key ] ) ) {
					/* translators: 1: line number, 2: date and time, e.g. "2026-09-01 09:00". */
					$errors[] = sprintf( __( 'Line %1$d: %2$s is listed more than once, the first capacity wins.', 'event-booking-slots' ), $line_no, $key );
					continue;
				}

				$seen[ $key ] = true;
				$slots[]      = array(
					'date'     => $date,
					'time'     => $time['time'],
					'end'      => $time['end'],
					'capacity' => $capacity,
				);
			}
		}

		return array(
			'slots'  => $slots,
			'errors' => $errors,
		);
	}

	/**
	 * Accepts Y-m-d or d/m/Y and returns Y-m-d, or false when the date is not real.
	 */
	private static function normalize_date( $value ) {
		$value = trim( $value );

		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m ) ) {
			list( , $year, $month, $day ) = $m;
		} elseif ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m ) ) {
			list( , $day, $month, $year ) = $m;
		} else {
			return false;
		}

		if ( ! checkdate( (int) $month, (int) $day, (int) $year ) ) {
			return false;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Turns a time expression into a list of slots, each with a start and an
	 * optional end.
	 *
	 *   "09:00"           -> one slot, ending after $default_duration minutes
	 *   "09:00,10:00"     -> two such slots
	 *   "09:00-13:00"     -> one slot running the whole range
	 *   "09:00-13:00 /30" -> that range split into 30 minute slots
	 *
	 * @return array<int,array{time:string,end:?string}>|\WP_Error
	 */
	private static function expand_times( $value, $default_duration = self::DEFAULT_DURATION ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return new \WP_Error( 'ebs_no_time', __( 'no time given.', 'event-booking-slots' ) );
		}

		// A trailing "/N" splits any range on this line into N-minute slots.
		$step = 0;
		if ( preg_match( '#/\s*(\d+)\s*$#', $value, $m ) ) {
			$step  = (int) $m[1];
			$value = trim( preg_replace( '#/\s*\d+\s*$#', '', $value ) );

			if ( $step < 1 ) {
				return new \WP_Error( 'ebs_bad_step', __( 'the slot length must be at least 1 minute.', 'event-booking-slots' ) );
			}
		}

		$default_duration = max( 0, (int) $default_duration );
		$slots            = array();

		foreach ( array_map( 'trim', explode( ',', $value ) ) as $chunk ) {
			if ( '' === $chunk ) {
				continue;
			}

			if ( strpos( $chunk, '-' ) !== false ) {
				$parsed = self::expand_range( $chunk, $step );

				if ( is_wp_error( $parsed ) ) {
					return $parsed;
				}

				$slots = array_merge( $slots, $parsed );
				continue;
			}

			$minute = self::to_minutes( $chunk );
			if ( null === $minute ) {
				/* translators: %s: the unrecognised time text. */
				return new \WP_Error( 'ebs_bad_time', sprintf( __( '"%s" is not a valid time.', 'event-booking-slots' ), $chunk ) );
			}

			$length = $step > 0 ? $step : $default_duration;

			$slots[] = array(
				'time' => self::to_time_string( $minute ),
				'end'  => self::end_for( $minute, $length ),
			);
		}

		if ( empty( $slots ) ) {
			return new \WP_Error( 'ebs_no_time', __( 'no time given.', 'event-booking-slots' ) );
		}

		return $slots;
	}

	/**
	 * @return array<int,array{time:string,end:?string}>|\WP_Error
	 */
	private static function expand_range( $chunk, $step ) {
		list( $start_raw, $end_raw ) = array_map( 'trim', explode( '-', $chunk, 2 ) );

		$start = self::to_minutes( $start_raw );
		$end   = self::to_minutes( $end_raw );

		if ( null === $start || null === $end ) {
			/* translators: %s: the unrecognised time range text. */
			return new \WP_Error( 'ebs_bad_range', sprintf( __( '"%s" is not a valid time range.', 'event-booking-slots' ), $chunk ) );
		}

		if ( $end <= $start ) {
			/* translators: %s: the time range text. */
			return new \WP_Error( 'ebs_backwards_range', sprintf( __( '"%s" ends before it starts.', 'event-booking-slots' ), $chunk ) );
		}

		// Without an explicit step the range is a single slot.
		if ( $step < 1 ) {
			return array(
				array(
					'time' => self::to_time_string( $start ),
					'end'  => self::to_time_string( $end ),
				),
			);
		}

		$slots = array();

		// The end time closes the last slot rather than starting one.
		for ( $minute = $start; $minute + $step <= $end; $minute += $step ) {
			$slots[] = array(
				'time' => self::to_time_string( $minute ),
				'end'  => self::to_time_string( $minute + $step ),
			);
		}

		if ( empty( $slots ) ) {
			/* translators: %s: the time range text. */
			return new \WP_Error( 'ebs_range_too_short', sprintf( __( '"%s" is shorter than one slot.', 'event-booking-slots' ), $chunk ) );
		}

		return $slots;
	}

	/**
	 * A zero length means the slot has no end time; anything running past
	 * midnight is clamped to 23:59 rather than wrapping into the next day.
	 */
	private static function end_for( $start_minute, $length ) {
		if ( $length < 1 ) {
			return null;
		}

		return self::to_time_string( min( $start_minute + $length, ( 23 * 60 ) + 59 ) );
	}

	private static function to_minutes( $value ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $value ), $m ) ) {
			return null;
		}

		$hours   = (int) $m[1];
		$minutes = (int) $m[2];

		if ( $hours > 23 || $minutes > 59 ) {
			return null;
		}

		return ( $hours * 60 ) + $minutes;
	}

	private static function to_time_string( $minutes ) {
		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}
}
