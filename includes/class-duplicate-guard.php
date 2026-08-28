<?php
declare(strict_types=1);

namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether a submission is a repeat booking by somebody who already has
 * one, and produces the message the visitor sees when it is.
 *
 * The rule the site owner configures under Bookings -> Settings is "match on
 * email, phone, or both", so this is deliberately an OR: any ticked field that
 * already has a booking blocks the submission.
 */
class Duplicate_Guard {

	/**
	 * Phone numbers are typed inconsistently -- "050-123 4567", "+972 50 1234567"
	 * and "0501234567" are one number to a human and three strings to MySQL. This
	 * reduces them to a comparable form: digits only, keeping the last 9, which
	 * drops both a country code and a trunk zero.
	 *
	 * Very short strings are rejected outright: a two-digit "phone" would match
	 * half the table.
	 *
	 * @return string Empty when the input is not usable for matching.
	 */
	public static function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );
		$digits = is_string( $digits ) ? $digits : '';

		if ( strlen( $digits ) > 9 ) {
			$digits = substr( $digits, -9 );
		}

		if ( strlen( $digits ) < 6 ) {
			$digits = '';
		}

		/**
		 * Filters the comparable form of a phone number.
		 *
		 * Return '' to exclude a number from duplicate matching. Sites outside
		 * Israel may want a different rule here -- change it before any bookings
		 * exist, or the stored column and the incoming value will disagree.
		 *
		 * @param string $digits Normalized number.
		 * @param string $phone  The number as the visitor typed it.
		 */
		return (string) apply_filters( 'ebs_normalize_phone', $digits, $phone );
	}

	/**
	 * Emails are matched case-insensitively; the column collation already is, but
	 * folding here keeps the stored and compared forms identical.
	 */
	public static function normalize_email( string $email ): string {
		$email = sanitize_email( $email );

		return $email ? strtolower( $email ) : '';
	}

	public static function is_enabled(): bool {
		return ! empty( Settings::get( 'duplicate_email' ) ) || ! empty( Settings::get( 'duplicate_phone' ) );
	}

	/**
	 * Finds an existing booking that makes this submission a duplicate.
	 *
	 * @param int    $event_id Event being booked into.
	 * @param string $email    Submitted email, raw.
	 * @param string $phone    Submitted phone, raw.
	 * @return object|null The earlier booking row, or null when there is none.
	 */
	public static function find( int $event_id, string $email, string $phone ) {
		if ( ! self::is_enabled() ) {
			return null;
		}

		$match_email = ! empty( Settings::get( 'duplicate_email' ) ) ? self::normalize_email( $email ) : '';
		$match_phone = ! empty( Settings::get( 'duplicate_phone' ) ) ? self::normalize_phone( $phone ) : '';

		// Nothing usable was submitted -- an email-only form with the phone check
		// on, for instance. Not a duplicate, and not an error either.
		if ( '' === $match_email && '' === $match_phone ) {
			return null;
		}

		$existing = Booking_Repository::find_duplicate(
			array(
				'event_id'          => 'site' === Settings::get( 'duplicate_scope' ) ? 0 : $event_id,
				'email'             => $match_email,
				'phone_norm'        => $match_phone,
				'include_cancelled' => ! empty( Settings::get( 'duplicate_cancelled' ) ),
			)
		);

		/**
		 * Filters the duplicate decision.
		 *
		 * Return null to let a submission through that the settings would block,
		 * or a booking row to block one they would allow.
		 *
		 * @param object|null $existing
		 * @param int         $event_id
		 * @param string      $email
		 * @param string      $phone
		 */
		return apply_filters( 'ebs_duplicate_booking', $existing, $event_id, $email, $phone );
	}

	/**
	 * The configured message with its placeholders filled in.
	 *
	 * Values are escaped individually before substitution, so a name containing
	 * markup cannot inject anything; the surrounding message is administrator
	 * content already restricted by Settings::allowed_message_html().
	 *
	 * @param object|null $existing  The booking the visitor already has.
	 * @param array{name:string,email:string,phone:string} $submitted
	 */
	public static function message( $existing, array $submitted = array() ): string {
		$message = (string) Settings::get( 'duplicate_message' );

		$event_id = $existing && isset( $existing->event_id ) ? (int) $existing->event_id : 0;

		$date = '';
		$time = '';

		if ( $existing && ! empty( $existing->slot_date ) ) {
			// An empty date_format / time_format would silently drop the date out of
			// the message, so fall back to WordPress's own defaults.
			$date_format = (string) get_option( 'date_format' );
			$time_format = (string) get_option( 'time_format' );

			$date = Slot_Repository::format_local( $existing->slot_date, $existing->slot_time, '' !== $date_format ? $date_format : 'F j, Y' );
			$time = Slot_Repository::format_local( $existing->slot_date, $existing->slot_time, '' !== $time_format ? $time_format : 'H:i' );
		}

		$replacements = array(
			'{name}'  => isset( $submitted['name'] ) ? (string) $submitted['name'] : ( $existing->name ?? '' ),
			'{email}' => isset( $submitted['email'] ) ? (string) $submitted['email'] : ( $existing->email ?? '' ),
			'{phone}' => isset( $submitted['phone'] ) ? (string) $submitted['phone'] : ( $existing->phone ?? '' ),
			'{event}' => $event_id ? (string) get_the_title( $event_id ) : '',
			'{date}'  => $date,
			'{time}'  => $time,
		);

		$replacements = array_map( 'esc_html', $replacements );

		$message = strtr( $message, $replacements );

		return wp_kses( $message, Settings::allowed_message_html() );
	}
}
