<?php
declare(strict_types=1);

namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated endpoint behind the bookings list.
 *
 * Kept apart from Rest_Controller, which is the public availability endpoint and
 * deliberately open to everyone. Nothing here is.
 *
 * One route serves both a single row and a bulk selection: a single cancel is
 * just a selection of one, so there is one permission check, one capability
 * rule and one response shape to reason about rather than two of each.
 */
class Admin_Rest {

	const NAMESPACE_V1 = 'ebs/v1';

	/**
	 * A single request cannot touch more than this many bookings.
	 *
	 * Each one is its own UPDATE plus a slot adjustment, so an unbounded list
	 * would be a way to tie up the database from a single click.
	 */
	const MAX_PER_REQUEST = 200;

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/bookings/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'ids'         => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
					'bulk_action' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'cancel', 'delete' ),
					),
				),
			)
		);
	}

	/**
	 * Same capability as the screen itself and as the non-JavaScript handlers, so
	 * moving these actions to fetch() cannot widen who may perform them.
	 *
	 * REST cookie authentication also requires a valid X-WP-Nonce header, which
	 * WordPress checks before this runs.
	 */
	public static function can_manage(): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function handle( \WP_REST_Request $request ) {
		$action = (string) $request->get_param( 'bulk_action' );

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', (array) $request->get_param( 'ids' ) )
				)
			)
		);

		if ( empty( $ids ) ) {
			return new \WP_Error(
				'ebs_nothing_selected',
				__( 'No bookings were selected.', 'event-booking-slots' ),
				array( 'status' => 400 )
			);
		}

		$skipped = array();

		if ( count( $ids ) > self::MAX_PER_REQUEST ) {
			$skipped = array_slice( $ids, self::MAX_PER_REQUEST );
			$ids     = array_slice( $ids, 0, self::MAX_PER_REQUEST );
		}

		$done   = array();
		$failed = array();

		foreach ( $ids as $id ) {
			$ok = 'delete' === $action
				? Booking_Repository::delete( $id )
				: Booking_Repository::cancel( $id );

			if ( $ok ) {
				$done[] = $id;
				continue;
			}

			// Already cancelled, or already gone. Not an error worth failing the
			// whole request over -- the row simply ends up in the state asked for.
			$failed[] = $id;
		}

		return rest_ensure_response(
			array(
				'action'  => $action,
				'done'    => $done,
				'failed'  => $failed,
				'skipped' => array_values( $skipped ),
				'message' => self::message( $action, count( $done ), count( $failed ), count( $skipped ) ),
			)
		);
	}

	/**
	 * What the visitor of the screen is told afterwards.
	 */
	private static function message( string $action, int $done, int $failed, int $skipped ): string {
		if ( 'delete' === $action ) {
			/* translators: %s: number of bookings. */
			$text = sprintf( _n( '%s booking deleted.', '%s bookings deleted.', $done, 'event-booking-slots' ), number_format_i18n( $done ) );
		} else {
			/* translators: %s: number of bookings. */
			$text = sprintf( _n( '%s booking cancelled.', '%s bookings cancelled.', $done, 'event-booking-slots' ), number_format_i18n( $done ) );
		}

		if ( $failed ) {
			$text .= ' ' . sprintf(
				/* translators: %s: number of bookings. */
				_n( '%s was already in that state and was left alone.', '%s were already in that state and were left alone.', $failed, 'event-booking-slots' ),
				number_format_i18n( $failed )
			);
		}

		if ( $skipped ) {
			$text .= ' ' . sprintf(
				/* translators: %s: number of bookings. */
				_n( '%s was not reached: select fewer at a time.', '%s were not reached: select fewer at a time.', $skipped, 'event-booking-slots' ),
				number_format_i18n( $skipped )
			);
		}

		return $text;
	}
}
