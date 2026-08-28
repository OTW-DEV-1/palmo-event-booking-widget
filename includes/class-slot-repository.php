<?php
namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes slot rows, and owns the capacity accounting.
 */
class Slot_Repository {

	/**
	 * Writes the parsed slot list for an event.
	 *
	 * Existing rows are updated in place so their booked counts survive an edit.
	 * Rows the admin removed from the textarea are deleted, unless somebody has
	 * already booked them -- those are closed instead, so the bookings keep a
	 * slot to point at.
	 *
	 * @param int   $event_id
	 * @param array $slots Output of Slot_Parser::parse()['slots'].
	 * @return array{created:int,updated:int,deleted:int,kept:int}
	 */
	public static function sync( $event_id, array $slots ) {
		global $wpdb;

		$table    = Installer::slots_table();
		$event_id = absint( $event_id );
		$stats    = array(
			'created' => 0,
			'updated' => 0,
			'deleted' => 0,
			'kept'    => 0,
		);

		$existing = array();
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d", $event_id ) );
		foreach ( $rows as $row ) {
			$existing[ $row->slot_date . ' ' . substr( $row->slot_time, 0, 5 ) ] = $row;
		}

		$order = 0;
		$keys  = array();

		foreach ( $slots as $slot ) {
			$key    = $slot['date'] . ' ' . $slot['time'];
			$keys[] = $key;
			++$order;

			if ( isset( $existing[ $key ] ) ) {
				$row = $existing[ $key ];

				$wpdb->update(
					$table,
					array(
						'slot_end'   => self::normalize_end( $slot ),
						'capacity'   => absint( $slot['capacity'] ),
						'sort_order' => $order,
						'status'     => 'open',
					),
					array( 'id' => $row->id ),
					array( '%s', '%d', '%d', '%s' ),
					array( '%d' )
				);

				++$stats['updated'];
				continue;
			}

			$wpdb->insert(
				$table,
				array(
					'event_id'   => $event_id,
					'slot_date'  => $slot['date'],
					'slot_time'  => $slot['time'] . ':00',
					'slot_end'   => self::normalize_end( $slot ),
					'capacity'   => absint( $slot['capacity'] ),
					'booked'     => 0,
					'status'     => 'open',
					'sort_order' => $order,
				),
				array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d' )
			);

			++$stats['created'];
		}

		foreach ( $existing as $key => $row ) {
			if ( in_array( $key, $keys, true ) ) {
				continue;
			}

			// Anything that has been booked, or was closed earlier, is kept: live and
			// cancelled bookings both still point at the row. Only an untouched,
			// still-open slot is safe to delete outright.
			if ( (int) $row->booked > 0 || 'open' !== $row->status ) {
				if ( 'closed' !== $row->status ) {
					$wpdb->update( $table, array( 'status' => 'closed' ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
				}

				++$stats['kept'];
				continue;
			}

			$wpdb->delete( $table, array( 'id' => $row->id ), array( '%d' ) );
			++$stats['deleted'];
		}

		return $stats;
	}

	/**
	 * An end time is optional, so an empty one is stored as NULL rather than as
	 * 00:00:00, which MySQL would otherwise read as midnight.
	 */
	private static function normalize_end( array $slot ) {
		if ( empty( $slot['end'] ) ) {
			return null;
		}

		if ( ! preg_match( '/^(\d{1,2}):(\d{2})/', trim( $slot['end'] ), $m ) ) {
			return null;
		}

		if ( (int) $m[1] > 23 || (int) $m[2] > 59 ) {
			return null;
		}

		// A slot cannot finish before it starts.
		if ( ! empty( $slot['time'] ) && sprintf( '%02d:%02d', $m[1], $m[2] ) <= substr( $slot['time'], 0, 5 ) ) {
			return null;
		}

		return sprintf( '%02d:%02d:00', $m[1], $m[2] );
	}

	/**
	 * "09:00 – 09:30", or just "09:00" when the slot has no end time.
	 */
	public static function time_range( $slot, $separator = ' – ' ) {
		$start = substr( $slot->slot_time, 0, 5 );

		if ( empty( $slot->slot_end ) ) {
			return $start;
		}

		return $start . $separator . substr( $slot->slot_end, 0, 5 );
	}

	/**
	 * A slot's date + time as a real moment in the site's own timezone.
	 *
	 * Slot times are wall-clock ("09:00 means nine in the morning where the event
	 * is"), not instants. strtotime() would read them in PHP's default timezone --
	 * UTC under WordPress -- and wp_date() would then shift them into the site's
	 * timezone, moving every displayed time by the UTC offset. On a UTC+3 site that
	 * turned an 09:00 slot into 12:00 in emails and webhooks.
	 *
	 * @return \DateTimeImmutable|null
	 */
	public static function local_datetime( $date, $time ) {
		$time = substr( (string) $time, 0, 5 );

		if ( ! $date || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return null;
		}

		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $time, wp_timezone() );

		return $dt ?: null;
	}

	/**
	 * Formats a slot's date/time with a WordPress format string, in site time.
	 */
	public static function format_local( $date, $time, $format ) {
		$dt = self::local_datetime( $date, $time );

		return $dt ? wp_date( $format, $dt->getTimestamp(), wp_timezone() ) : substr( (string) $time, 0, 5 );
	}

	/**
	 * The same range wrapped so an RTL page cannot flip it into "10:00 – 09:00".
	 */
	public static function time_range_html( $slot, $separator = ' – ' ) {
		return '<bdi dir="ltr">' . esc_html( self::time_range( $slot, $separator ) ) . '</bdi>';
	}

	public static function get( $slot_id ) {
		global $wpdb;

		$table = Installer::slots_table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $slot_id ) ) );
	}

	/**
	 * All slots for an event, oldest date first.
	 */
	public static function for_event( $event_id, $only_open = false ) {
		global $wpdb;

		$table = Installer::slots_table();
		$sql   = "SELECT * FROM {$table} WHERE event_id = %d";

		if ( $only_open ) {
			$sql .= " AND status = 'open'";
		}

		$sql .= ' ORDER BY slot_date ASC, slot_time ASC';

		return $wpdb->get_results( $wpdb->prepare( $sql, absint( $event_id ) ) );
	}

	/**
	 * Reserves seats on a slot.
	 *
	 * The whole check-and-increment is a single conditional UPDATE, so two
	 * submissions racing for the last seat cannot both win: the loser's
	 * WHERE clause stops matching and it affects zero rows.
	 *
	 * @return bool True when the seats were reserved.
	 */
	public static function reserve( $slot_id, $seats = 1 ) {
		global $wpdb;

		$table   = Installer::slots_table();
		$slot_id = absint( $slot_id );
		$seats   = max( 1, absint( $seats ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET booked = booked + %d
				 WHERE id = %d
				   AND status = 'open'
				   AND ( capacity = 0 OR booked + %d <= capacity )",
				$seats,
				$slot_id,
				$seats
			)
		);

		return $wpdb->rows_affected > 0;
	}

	/**
	 * Gives seats back, e.g. when a booking is cancelled or a submission failed
	 * after the seats were already taken. Clamped so the count cannot go negative.
	 */
	public static function release( $slot_id, $seats = 1 ) {
		global $wpdb;

		$table = Installer::slots_table();
		$seats = max( 1, absint( $seats ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET booked = GREATEST( CAST( booked AS SIGNED ) - %d, 0 ) WHERE id = %d",
				$seats,
				absint( $slot_id )
			)
		);

		return $wpdb->rows_affected > 0;
	}

	public static function remaining( $slot ) {
		if ( is_numeric( $slot ) ) {
			$slot = self::get( $slot );
		}

		if ( ! $slot ) {
			return 0;
		}

		if ( 0 === (int) $slot->capacity ) {
			return PHP_INT_MAX;
		}

		return max( 0, (int) $slot->capacity - (int) $slot->booked );
	}

	public static function is_available( $slot, $seats = 1 ) {
		if ( is_numeric( $slot ) ) {
			$slot = self::get( $slot );
		}

		if ( ! $slot || 'open' !== $slot->status ) {
			return false;
		}

		return self::remaining( $slot ) >= max( 1, absint( $seats ) );
	}

	public static function delete_for_event( $event_id ) {
		global $wpdb;

		$table = Installer::slots_table();

		return $wpdb->delete( $table, array( 'event_id' => absint( $event_id ) ), array( '%d' ) );
	}
}
