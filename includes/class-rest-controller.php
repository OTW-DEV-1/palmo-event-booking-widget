<?php
namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public availability endpoint.
 *
 * The field markup is rendered with the page (and may be cached), so the actual
 * numbers are always fetched from here, uncached, on page load.
 */
class Rest_Controller {

	const NAMESPACE_V1 = 'ebs/v1';

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/availability',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'event_id'  => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return absint( $value ) > 0;
						},
					),
					'show_full' => array(
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'scarcity'  => array(
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'scarcity_min' => array(
						'required'          => false,
						'default'           => 2,
						'sanitize_callback' => 'absint',
					),
					'scarcity_max' => array(
						'required'          => false,
						'default'           => 7,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public static function get_availability( \WP_REST_Request $request ) {
		$event_id = absint( $request->get_param( 'event_id' ) );
		$event    = get_post( $event_id );

		if ( ! $event || Event_Post_Type::POST_TYPE !== $event->post_type || 'publish' !== $event->post_status ) {
			return new \WP_Error(
				'ebs_event_not_found',
				__( 'This booking event is not available.', 'event-booking-slots' ),
				array( 'status' => 404 )
			);
		}

		$slots = Slot_Repository::for_event( $event_id, true );
		$dates = array();

		// Slots whose date has already passed are dropped so the dropdown never
		// offers a day in the past.
		$today = current_time( 'Y-m-d' );

		foreach ( $slots as $slot ) {
			if ( $slot->slot_date < $today ) {
				continue;
			}

			$unlimited = 0 === (int) $slot->capacity;
			$remaining = $unlimited ? null : max( 0, (int) $slot->capacity - (int) $slot->booked );

			if ( ! $unlimited && $remaining < 1 && ! self::show_full_slots( $event_id, $request ) ) {
				continue;
			}

			if ( ! isset( $dates[ $slot->slot_date ] ) ) {
				$dates[ $slot->slot_date ] = array(
					'date'  => $slot->slot_date,
					'label' => self::format_date( $slot->slot_date ),
					'slots' => array(),
				);
			}

			$dates[ $slot->slot_date ]['slots'][] = array(
				'id'        => (int) $slot->id,
				'time'      => substr( $slot->slot_time, 0, 5 ),
				'end'       => $slot->slot_end ? substr( $slot->slot_end, 0, 5 ) : '',
				'label'     => self::format_range( $slot ),
				'remaining' => self::displayed_remaining( $slot, $remaining, $request ),
				'full'      => ! $unlimited && $remaining < 1,
			);
		}

		// A date whose every slot filled up should not linger in the list.
		$dates = array_values(
			array_filter(
				$dates,
				function ( $date ) {
					return ! empty( $date['slots'] );
				}
			)
		);

		$response = rest_ensure_response(
			array(
				'event_id' => $event_id,
				'dates'    => $dates,
			)
		);

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		self::forbid_caching();

		return $response;
	}

	/**
	 * The number shown to the visitor as "places left".
	 *
	 * With scarcity display off this is simply the true count.
	 *
	 * With it on, the slot is given a fixed pseudo-random figure in the configured
	 * range and the visitor is shown whichever is LOWER, that figure or the truth.
	 * Taking the minimum has two consequences that matter:
	 *
	 *   - the number shown can never exceed the places that actually exist, so a
	 *     slot is never made to look more available than it is;
	 *   - it only ever falls. As real bookings come in, the true count eventually
	 *     drops below the fixed figure and from then on the visitor sees the truth,
	 *     with no jump upwards on the way.
	 *
	 * The figure is derived from the slot id, so it stays the same on every request
	 * and across visitors rather than changing on each page load.
	 *
	 * NOTE: this displays a number that is not the real availability. See the
	 * consumer-protection note in readme.txt before enabling it.
	 */
	private static function displayed_remaining( $slot, $remaining, $request ) {
		if ( null === $remaining || ! $request || ! $request->get_param( 'scarcity' ) ) {
			return $remaining;
		}

		$min = max( 1, absint( $request->get_param( 'scarcity_min' ) ) );
		$max = max( $min, absint( $request->get_param( 'scarcity_max' ) ) );

		$fixed = self::slot_pseudo_random( (int) $slot->id, $min, $max );

		return min( $fixed, (int) $remaining );
	}

	/**
	 * A number in [$min, $max] that is stable for a given slot. Derived from the
	 * slot id and the site's auth salt so it is not guessable from the id alone.
	 */
	private static function slot_pseudo_random( $slot_id, $min, $max ) {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : ABSPATH;
		$hash = crc32( 'ebs-scarcity-' . $slot_id . '-' . $salt );

		return $min + ( abs( $hash ) % ( ( $max - $min ) + 1 ) );
	}

	/**
	 * Headers alone are not always enough: a full-page cache sitting in front of
	 * WordPress (LiteSpeed Cache is the common one on LiteSpeed servers) can be
	 * configured to cache REST responses. Stale numbers here would show visitors
	 * wrong "places left" -- the booking itself is still safe, because capacity is
	 * enforced on submit, but the display would lie.
	 */
	private static function forbid_caching() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// No-ops when the plugin is not installed.
		do_action( 'litespeed_control_set_nocache', 'event booking availability is per-request' );
		do_action( 'wpo_cache_exclude', 'ebs-availability' );

		nocache_headers();
	}

	/**
	 * Whether fully booked slots stay in the list, rendered as unavailable, or
	 * disappear entirely. The field asks for what it wants; the filter can still
	 * override site-wide.
	 */
	private static function show_full_slots( $event_id, $request = null ) {
		$show = $request ? (bool) $request->get_param( 'show_full' ) : false;

		/**
		 * @param bool $show
		 * @param int  $event_id
		 */
		return (bool) apply_filters( 'ebs_show_full_slots', $show, $event_id );
	}

	private static function format_date( $date ) {
		return Slot_Repository::format_local( $date, '00:00', get_option( 'date_format' ) );
	}

	/**
	 * "9:00 am – 9:30 am", or just the start when the slot has no end time.
	 */
	private static function format_range( $slot ) {
		$start = self::format_time( $slot->slot_date, $slot->slot_time );

		if ( empty( $slot->slot_end ) ) {
			return $start;
		}

		return $start . ' – ' . self::format_time( $slot->slot_date, $slot->slot_end );
	}

	private static function format_time( $slot_date, $time ) {
		return Slot_Repository::format_local( $slot_date, $time, get_option( 'time_format' ) );
	}
}
