<?php
/**
 * Event Booking Slots — diagnostics
 *
 * Drop this file in the plugin folder and open:
 *   /wp-admin/admin.php?page=ebs-diagnostics
 * (Administrators only. Safe to leave installed; it only reads.)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_submenu_page(
		'edit.php?post_type=' . \EBS\Event_Post_Type::POST_TYPE,
		'Diagnostics', 'Diagnostics', 'manage_options', 'ebs-diagnostics',
		'ebs_render_diagnostics'
	);
} );

function ebs_render_diagnostics() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nope.' );
	}

	global $wpdb;
	$out = array();
	$ok  = function ( $b ) { return $b ? 'OK' : 'PROBLEM'; };

	$out['plugin version (PHP constant)'] = defined( 'EBS_VERSION' ) ? EBS_VERSION : 'undefined';
	$out['plugin version (file header)']  = get_file_data( EBS_FILE, array( 'v' => 'Version' ) )['v'];
	$out['-- if those two differ, OPcache is serving stale code --'] = '';

	$out['Elementor']     = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'NOT ACTIVE';
	$out['Elementor Pro'] = defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : 'NOT ACTIVE';
	$out['PHP']           = PHP_VERSION;

	$types = (array) apply_filters( 'elementor_pro/forms/field_types', array() );
	$out['booking field registered'] = $ok( isset( $types['ebs_booking_slot'] ) );

	foreach ( array( 'ebs_slots', 'ebs_bookings' ) as $t ) {
		$full = $wpdb->prefix . $t;
		$out[ "table {$full}" ] = $ok( (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) );
	}

	$out['slot_end column'] = $ok( in_array( 'slot_end', $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}ebs_slots" ), true ) );
	$out['db version option'] = get_option( 'ebs_db_version', '(unset)' );

	$out['bookings total']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ebs_bookings" );
	$out['bookings with blank name']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ebs_bookings WHERE name = ''" );
	$out['bookings with blank email'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ebs_bookings WHERE email = ''" );

	$out['WP timezone'] = get_option( 'timezone_string' ) ?: ( 'UTC offset ' . get_option( 'gmt_offset' ) );
	$out['today (site time)'] = current_time( 'Y-m-d' );

	if ( function_exists( 'opcache_get_status' ) ) {
		$s = @opcache_get_status( false );
		if ( $s ) {
			$out['opcache full']        = $ok( ! $s['cache_full'] );
			$out['opcache free bytes']  = number_format( $s['memory_usage']['free_memory'] );
			$c = opcache_get_configuration()['directives'];
			$out['opcache validate_timestamps'] = var_export( $c['opcache.validate_timestamps'], true );
		}
	}

	$caches = array( 'WP Rocket' => defined( 'WP_ROCKET_VERSION' ), 'LiteSpeed Cache' => defined( 'LSCWP_V' ), 'W3TC' => defined( 'W3TC' ), 'WP Super Cache' => defined( 'WPCACHEHOME' ) );
	$out['page caches active'] = implode( ', ', array_keys( array_filter( $caches ) ) ) ?: 'none detected';

	echo '<div class="wrap"><h1>Event Booking Slots — diagnostics</h1><table class="widefat striped" style="max-width:900px">';
	foreach ( $out as $k => $v ) {
		printf( '<tr><td style="width:320px"><strong>%s</strong></td><td><code>%s</code></td></tr>', esc_html( $k ), esc_html( is_bool( $v ) ? var_export( $v, true ) : (string) $v ) );
	}
	echo '</table>';

	$rows = $wpdb->get_results( "SELECT id, slot_date, slot_time, name, email, phone, form_name, created_at, payload FROM {$wpdb->prefix}ebs_bookings ORDER BY id DESC LIMIT 10" );
	echo '<h2>Last 10 bookings</h2><table class="widefat striped"><thead><tr><th>#</th><th>When</th><th>Name</th><th>Email</th><th>Phone</th><th>Form</th><th>Payload</th></tr></thead><tbody>';
	if ( ! $rows ) {
		echo '<tr><td colspan="7">No bookings recorded at all.</td></tr>';
	}
	foreach ( (array) $rows as $r ) {
		printf( '<tr><td>%d</td><td>%s %s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code style="font-size:11px">%s</code></td></tr>',
			$r->id, esc_html( $r->slot_date ), esc_html( substr( $r->slot_time, 0, 5 ) ),
			esc_html( $r->name ?: '(blank)' ), esc_html( $r->email ?: '(blank)' ), esc_html( $r->phone ?: '(blank)' ),
			esc_html( $r->form_name ?: '(blank)' ), esc_html( mb_substr( (string) $r->payload, 0, 300 ) ) );
	}
	echo '</tbody></table></div>';
}
