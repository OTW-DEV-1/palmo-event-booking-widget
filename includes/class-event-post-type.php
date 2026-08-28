<?php
namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Event" post type. One event holds a set of dates and their time slots.
 */
class Event_Post_Type {

	const POST_TYPE = 'ebs_event';

	const META_DEFINITION = '_ebs_slot_definition';
	const META_CAPACITY   = '_ebs_default_capacity';
	const META_DURATION   = '_ebs_default_duration';

	public static function register() {
		$labels = array(
			'name'               => __( 'Booking Events', 'event-booking-slots' ),
			'singular_name'      => __( 'Booking Event', 'event-booking-slots' ),
			'add_new'            => __( 'Add Event', 'event-booking-slots' ),
			'add_new_item'       => __( 'Add Event', 'event-booking-slots' ),
			'edit_item'          => __( 'Edit Event', 'event-booking-slots' ),
			'new_item'           => __( 'New Event', 'event-booking-slots' ),
			'view_item'          => __( 'View Event', 'event-booking-slots' ),
			'search_items'       => __( 'Search Events', 'event-booking-slots' ),
			'not_found'          => __( 'No events yet.', 'event-booking-slots' ),
			'not_found_in_trash' => __( 'No events in the trash.', 'event-booking-slots' ),
			'menu_name'          => __( 'Bookings', 'event-booking-slots' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'            => $labels,
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'menu_icon'         => 'dashicons-calendar-alt',
				'menu_position'     => 26,
				'supports'          => array( 'title' ),
				'capability_type'   => 'post',
				'map_meta_cap'      => true,
				'has_archive'       => false,
				'rewrite'           => false,
				'show_in_rest'      => false,
				'delete_with_user'  => false,
			)
		);
	}

	public static function default_capacity( $event_id ) {
		$value = get_post_meta( $event_id, self::META_CAPACITY, true );

		return '' === $value ? 25 : absint( $value );
	}

	/**
	 * Minutes a slot lasts when no explicit end time is given. 0 means the slot
	 * has a start time only.
	 */
	public static function default_duration( $event_id ) {
		$value = get_post_meta( $event_id, self::META_DURATION, true );

		return '' === $value ? 30 : absint( $value );
	}

	public static function definition( $event_id ) {
		return (string) get_post_meta( $event_id, self::META_DEFINITION, true );
	}

	/**
	 * Events that still have at least one open slot, for the Elementor control dropdown.
	 */
	public static function selectable() {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => 200,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$options = array();
		foreach ( $posts as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}

		return $options;
	}

	/**
	 * Slots and bookings live in custom tables, so they need clearing by hand
	 * when an event is permanently deleted.
	 */
	public static function on_delete( $post_id ) {
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}

		Slot_Repository::delete_for_event( $post_id );
		Booking_Repository::delete_for_event( $post_id );
	}
}
