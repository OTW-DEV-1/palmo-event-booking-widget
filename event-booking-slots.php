<?php
/**
 * Plugin Name:       Event Booking Slots
 * Description:       Event days with capped time slots, exposed to Elementor Forms as a custom "Booking Slot" field.
 * Version:           0.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            OTW Design
 * Text Domain:       event-booking-slots
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EBS_VERSION', '0.5.0' );
define( 'EBS_FILE', __FILE__ );
define( 'EBS_PATH', plugin_dir_path( __FILE__ ) );
define( 'EBS_URL', plugin_dir_url( __FILE__ ) );

require_once EBS_PATH . 'includes/class-assets.php';
require_once EBS_PATH . 'includes/class-settings.php';
require_once EBS_PATH . 'includes/class-installer.php';
require_once EBS_PATH . 'includes/class-slot-parser.php';
require_once EBS_PATH . 'includes/class-slot-repository.php';
require_once EBS_PATH . 'includes/class-booking-repository.php';
require_once EBS_PATH . 'includes/class-duplicate-guard.php';
require_once EBS_PATH . 'includes/class-event-post-type.php';
require_once EBS_PATH . 'includes/class-admin.php';
require_once EBS_PATH . 'includes/class-rest-controller.php';
require_once EBS_PATH . 'includes/class-admin-rest.php';
require_once EBS_PATH . 'includes/class-plugin.php';

// Optional read-only diagnostics screen; simply delete the file to remove it.
if ( is_admin() && file_exists( EBS_PATH . 'diagnostics.php' ) ) {
	require_once EBS_PATH . 'diagnostics.php';
}

register_activation_hook( __FILE__, array( '\EBS\Installer', 'activate' ) );

add_action( 'plugins_loaded', array( '\EBS\Plugin', 'instance' ) );
