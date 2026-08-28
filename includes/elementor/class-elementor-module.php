<?php
namespace EBS\Elementor;

use EBS\Booking_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the Booking Slot field into Elementor Pro forms.
 */
class Elementor_Module {

	public static function init() {
		add_action( 'elementor_pro/forms/fields/register', array( __CLASS__, 'register_field' ) );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'store_booking' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'elementor/preview/enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'protect_script_tag' ), 10, 2 );

		// Any seat reserved during validation that never became a booking goes back.
		// Routed through this class rather than pointing the hook straight at
		// Slot_Field: that class is only loaded once Elementor Pro registers the
		// field, and a hook aimed at a missing class is a fatal error in PHP 8.
		add_action( 'shutdown', array( __CLASS__, 'release_unclaimed' ) );
	}

	public static function is_pro_ready() {
		return class_exists( '\ElementorPro\Modules\Forms\Fields\Field_Base' );
	}

	public static function register_field( $registrar ) {
		if ( ! self::is_pro_ready() ) {
			return;
		}

		require_once EBS_PATH . 'includes/elementor/class-slot-field.php';

		$registrar->register( new Slot_Field() );
	}

	/**
	 * True only once Slot_Field has actually been loaded, without triggering an
	 * autoload attempt.
	 */
	private static function field_loaded() {
		return class_exists( '\EBS\Elementor\Slot_Field', false );
	}

	/**
	 * Hands back any seat reserved during validation that never became a booking.
	 */
	public static function release_unclaimed() {
		if ( ! self::field_loaded() ) {
			return;
		}

		Slot_Field::release_unclaimed();
	}

	/**
	 * Enqueued on every frontend page rather than from the field's render().
	 *
	 * Elementor caches rendered widget HTML (_elementor_element_cache), and on a
	 * cache hit render() never runs -- so an enqueue made there is skipped while
	 * the cached markup still appears. That left the dropdowns stuck on "Loading…"
	 * for every visitor after the first. Loading a few KB site-wide is the cheaper
	 * mistake, and the script exits immediately when no booking field is present.
	 */
	public static function register_assets() {
		wp_enqueue_style(
			'ebs-frontend',
			\EBS\Assets::url( 'assets/css/frontend.css' ),
			array(),
			\EBS\Assets::version( 'assets/css/frontend.css' )
		);

		wp_enqueue_script(
			'ebs-frontend',
			\EBS\Assets::url( 'assets/js/frontend.js' ),
			array(),
			\EBS\Assets::version( 'assets/js/frontend.js' ),
			true
		);

		wp_localize_script(
			'ebs-frontend',
			'ebsI18n',
			array(
				'noDates'    => esc_html__( 'No dates are available right now.', 'event-booking-slots' ),
				'loadError'  => esc_html__( 'Could not load the available dates. Please refresh the page.', 'event-booking-slots' ),
				'full'       => esc_html__( 'Full', 'event-booking-slots' ),
				/* translators: %d: number of places still available. */
				'left'       => esc_html__( '%d places left', 'event-booking-slots' ),
				'chosen'     => esc_html__( 'Your chosen time', 'event-booking-slots' ),
			)
		);
	}

	/**
	 * Opts our script out of "delay JavaScript until interaction" optimisers.
	 *
	 * Those hold every script back until the visitor scrolls or clicks. This one
	 * has to run on load, because until it does the date dropdown sits disabled
	 * showing "Loading…" -- so the visitor's first click lands on a dead control.
	 *
	 * Deferring and minifying are left alone: the script is a self-contained IIFE
	 * with no dependencies, so those are safe and worth keeping for performance.
	 *
	 * data-nowprocket  -> WP Rocket "Delay JavaScript execution"
	 * data-cfasync     -> Cloudflare Rocket Loader
	 * data-no-delay    -> several other optimisers use this convention
	 */
	public static function protect_script_tag( $tag, $handle ) {
		if ( 'ebs-frontend' !== $handle ) {
			return $tag;
		}

		return str_replace(
			'<script ',
			'<script data-nowprocket data-cfasync="false" data-no-delay ',
			$tag
		);
	}

	/**
	 * Writes the booking row for the seat reserved during validation.
	 */
	public static function store_booking( $record, $ajax_handler ) {
		if ( ! self::field_loaded() ) {
			return;
		}

		$reservation = Slot_Field::claim_reservation( $record );

		// Every other form on the site lands here too and leaves at this line.
		if ( ! $reservation ) {
			return;
		}

		$fields  = $record->get( 'fields' );
		$payload = array();

		foreach ( $fields as $id => $field ) {
			$label            = ! empty( $field['title'] ) ? $field['title'] : $id;
			$payload[ $label ] = is_array( $field['value'] ) ? implode( ', ', $field['value'] ) : $field['value'];
		}

		$form_settings = $record->get( 'form_settings' );
		$contact       = self::contact_from_fields( $fields );

		$booking_id = Booking_Repository::create(
			array(
				'slot_id'   => $reservation['slot_id'],
				'seats'     => $reservation['seats'],
				'name'      => $contact['name'],
				'email'     => $contact['email'],
				'phone'     => $contact['phone'],
				'payload'   => $payload,
				'form_name' => isset( $form_settings['form_name'] ) ? $form_settings['form_name'] : '',
				'post_id'   => get_the_ID() ? get_the_ID() : 0,
			)
		);

		if ( ! $booking_id ) {
			// The row could not be written, so do not keep the seat held.
			\EBS\Slot_Repository::release( $reservation['slot_id'], $reservation['seats'] );

			// Silent failure here looks like "the booking just vanished", so leave a
			// trace in the error log for whoever has to diagnose it.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'[event-booking-slots] booking row could not be written for slot %d; seat released.',
					$reservation['slot_id']
				) );
			}
		}
	}

	/**
	 * Best-effort pull of name / email / phone out of a submitted form.
	 *
	 * Used both when writing the booking row and when checking for duplicates, so
	 * the two always look at the same values -- otherwise a form could be blocked
	 * on an email that never made it into the stored row.
	 *
	 * @param array<string,array> $fields Form_Record::get( 'fields' ).
	 * @return array{name:string,email:string,phone:string}
	 */
	public static function contact_from_fields( array $fields ) {
		return array(
			'name'  => self::guess( $fields, array( 'text' ), array( 'name', 'שם', 'full name', 'fullname' ) ),
			'email' => self::guess( $fields, array( 'email' ), array( 'email', 'mail', 'אימייל' ) ),
			'phone' => self::guess( $fields, array( 'tel' ), array( 'phone', 'tel', 'mobile', 'טלפון' ) ),
		);
	}

	/**
	 * Best-effort pull of one value out of the submitted fields so the bookings
	 * table has readable columns. The full submission is kept in payload either
	 * way, so a miss here loses nothing.
	 */
	private static function guess( array $fields, array $types, array $keywords ) {
		foreach ( $fields as $field ) {
			if ( in_array( $field['type'], $types, true ) && ! empty( $field['value'] ) ) {
				return is_array( $field['value'] ) ? implode( ', ', $field['value'] ) : $field['value'];
			}
		}

		foreach ( $fields as $id => $field ) {
			$haystack = strtolower( $id . ' ' . ( isset( $field['title'] ) ? $field['title'] : '' ) );

			foreach ( $keywords as $keyword ) {
				if ( false !== strpos( $haystack, strtolower( $keyword ) ) && ! empty( $field['value'] ) ) {
					return is_array( $field['value'] ) ? implode( ', ', $field['value'] ) : $field['value'];
				}
			}
		}

		return '';
	}
}
