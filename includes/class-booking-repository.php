<?php
namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores one row per successful form submission.
 */
class Booking_Repository {

	/**
	 * Records a booking against a slot.
	 *
	 * This does NOT touch the slot's booked count: the seat is expected to have
	 * been taken already by Slot_Repository::reserve(), which is what makes the
	 * capacity check safe under concurrent submissions. Call reserve() first if
	 * you are creating a booking outside the Elementor form flow, or the counts
	 * will drift. cancel() releases the seat, so the pair is reserve/cancel.
	 *
	 * @param array $data slot_id, event_id, seats, name, email, phone, payload, form_name, post_id.
	 * @return int|false Booking id, or false on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$slot = Slot_Repository::get( isset( $data['slot_id'] ) ? $data['slot_id'] : 0 );
		if ( ! $slot ) {
			return false;
		}

		$payload = isset( $data['payload'] ) ? $data['payload'] : array();
		$phone   = sanitize_text_field( isset( $data['phone'] ) ? $data['phone'] : '' );

		$inserted = $wpdb->insert(
			Installer::bookings_table(),
			array(
				'slot_id'    => (int) $slot->id,
				'event_id'   => (int) $slot->event_id,
				'slot_date'  => $slot->slot_date,
				'slot_time'  => $slot->slot_time,
				'slot_end'   => $slot->slot_end ? $slot->slot_end : null,
				'seats'      => max( 1, absint( isset( $data['seats'] ) ? $data['seats'] : 1 ) ),
				'name'       => sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' ),
				'email'      => sanitize_email( isset( $data['email'] ) ? $data['email'] : '' ),
				'phone'      => $phone,
				// Stored alongside the number as typed so duplicate lookups are a
				// plain indexed equality rather than a scan with string functions.
				'phone_norm' => Duplicate_Guard::normalize_phone( $phone ),
				'payload'    => wp_json_encode( $payload ),
				'status'     => 'confirmed',
				'form_name'  => sanitize_text_field( isset( $data['form_name'] ) ? $data['form_name'] : '' ),
				'post_id'    => absint( isset( $data['post_id'] ) ? $data['post_id'] : 0 ),
				'ip'         => self::client_ip(),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		$booking_id = (int) $wpdb->insert_id;

		/**
		 * Fires once a booking row exists and its seats are held.
		 *
		 * @param int    $booking_id
		 * @param object $slot
		 * @param array  $data
		 */
		do_action( 'ebs_booking_created', $booking_id, $slot, $data );

		return $booking_id;
	}

	/**
	 * The earliest booking matching a submitted email or phone.
	 *
	 * Matching is an OR across whichever of the two values is non-empty, which is
	 * what Duplicate_Guard's "match on email, phone, or both" setting means. Both
	 * empty returns null rather than matching every row.
	 *
	 * @param array{event_id?:int,email?:string,phone_norm?:string,include_cancelled?:bool} $args
	 *        event_id 0 searches every event.
	 * @return object|null
	 */
	public static function find_duplicate( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'event_id'          => 0,
				'email'             => '',
				'phone_norm'        => '',
				'include_cancelled' => false,
			)
		);

		$email = (string) $args['email'];
		$phone = (string) $args['phone_norm'];

		if ( '' === $email && '' === $phone ) {
			return null;
		}

		$table  = Installer::bookings_table();
		$where  = array();
		$params = array();

		if ( $args['event_id'] ) {
			$where[]  = 'event_id = %d';
			$params[] = absint( $args['event_id'] );
		}

		if ( ! $args['include_cancelled'] ) {
			$where[] = "status <> 'cancelled'";
		}

		$match = array();

		if ( '' !== $email ) {
			// Plain equality rather than LOWER( email ): the column's collation is
			// case-insensitive (get_charset_collate gives a _ci collation), and
			// wrapping the column in a function would stop the event_email index
			// being used.
			$match[]  = 'email = %s';
			$params[] = $email;
		}

		if ( '' !== $phone ) {
			$match[]  = 'phone_norm = %s';
			$params[] = $phone;
		}

		$where[] = '( ' . implode( ' OR ', $match ) . ' )';

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT 1';

		return $wpdb->get_row( $wpdb->prepare( $sql, $params ) );
	}

	public static function get( $booking_id ) {
		global $wpdb;

		$table = Installer::bookings_table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $booking_id ) ) );
	}

	/**
	 * Cancels a booking and hands its seats back to the slot.
	 *
	 * Guarded on the current status so a double-click on "cancel" cannot release
	 * the same seats twice.
	 */
	public static function cancel( $booking_id ) {
		global $wpdb;

		$table      = Installer::bookings_table();
		$booking_id = absint( $booking_id );

		$booking = self::get( $booking_id );
		if ( ! $booking || 'cancelled' === $booking->status ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'cancelled' WHERE id = %d AND status <> 'cancelled'",
				$booking_id
			)
		);

		if ( $wpdb->rows_affected < 1 ) {
			return false;
		}

		Slot_Repository::release( $booking->slot_id, $booking->seats );

		do_action( 'ebs_booking_cancelled', $booking_id, $booking );

		return true;
	}

	/**
	 * Removes a booking outright and hands its seats back to the slot.
	 *
	 * Unlike cancel(), which keeps the row as a record, this leaves nothing
	 * behind. The row goes first and the seats are released afterwards: if the
	 * delete fails the seats stay held, which is the safe way round -- releasing
	 * first and then failing to delete would let the same seat be sold twice.
	 *
	 * A booking that was already cancelled gave its seats back at that point, so
	 * it is not credited a second time.
	 *
	 * @return bool True when a row was removed.
	 */
	public static function delete( $booking_id ) {
		global $wpdb;

		$booking_id = absint( $booking_id );
		$booking    = self::get( $booking_id );

		if ( ! $booking ) {
			return false;
		}

		$deleted = $wpdb->delete( Installer::bookings_table(), array( 'id' => $booking_id ), array( '%d' ) );

		if ( ! $deleted ) {
			return false;
		}

		if ( 'cancelled' !== $booking->status ) {
			Slot_Repository::release( $booking->slot_id, $booking->seats );
		}

		/**
		 * Fires after a booking row has been deleted and its seats returned.
		 *
		 * @param int    $booking_id
		 * @param object $booking    The row as it was before deletion.
		 */
		do_action( 'ebs_booking_deleted', $booking_id, $booking );

		return true;
	}

	/**
	 * @param array $args event_id, slot_id, status, search, per_page, page.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'event_id' => 0,
				'slot_id'  => 0,
				'status'   => '',
				'search'   => '',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		$table = Installer::bookings_table();
		$where = array( '1=1' );
		$params = array();

		if ( $args['event_id'] ) {
			$where[]  = 'event_id = %d';
			$params[] = absint( $args['event_id'] );
		}

		if ( $args['slot_id'] ) {
			$where[]  = 'slot_id = %d';
			$params[] = absint( $args['slot_id'] );
		}

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( name LIKE %s OR email LIKE %s OR phone LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;

		$rows_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( $per_page, $offset ) );

		return array(
			'items' => $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ) ),
			'total' => $total,
		);
	}

	public static function delete_for_event( $event_id ) {
		global $wpdb;

		return $wpdb->delete( Installer::bookings_table(), array( 'event_id' => absint( $event_id ) ), array( '%d' ) );
	}

	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return substr( $ip, 0, 100 );
	}
}
