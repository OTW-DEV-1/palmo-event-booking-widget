<?php
namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		Installer::maybe_upgrade();

		// Hooked to init rather than called here: WordPress 6.7+ emits a notice when
		// a text domain is loaded before init.
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );

		add_action( 'init', array( '\EBS\Event_Post_Type', 'register' ) );
		add_action( 'before_delete_post', array( '\EBS\Event_Post_Type', 'on_delete' ) );
		add_action( 'rest_api_init', array( '\EBS\Rest_Controller', 'register_routes' ) );

		if ( is_admin() ) {
			Admin::init();
			Settings::init();
		}

		// Deliberately NOT hooked to elementor/init. Elementor Pro builds its form
		// field registrar during its own elementor/init callback, and because
		// "elementor-pro" loads before this plugin its callback runs first -- a
		// listener added in ours would be attached after the registrar had already
		// fired, so the field would never register. Attaching here, at plugins_loaded,
		// guarantees we are listening whenever the registrar runs.
		$this->load_elementor();

		add_action( 'admin_notices', array( $this, 'maybe_warn_about_pro' ) );
	}

	/**
	 * Only registers hooks. Nothing here touches an Elementor class, so it is safe
	 * to run even when Elementor is not installed -- the hooks simply never fire.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'event-booking-slots', false, dirname( plugin_basename( EBS_FILE ) ) . '/languages' );
	}

	public function load_elementor() {
		require_once EBS_PATH . 'includes/elementor/class-elementor-module.php';

		Elementor\Elementor_Module::init();
	}

	/**
	 * The field lives inside Elementor Pro's form widget, so without Pro the
	 * events still work but nothing can display them.
	 */
	public function maybe_warn_about_pro() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || Event_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( did_action( 'elementor/loaded' ) && class_exists( '\ElementorPro\Modules\Forms\Fields\Field_Base' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Event Booking Slots needs Elementor Pro to add the "Booking Slot" field to forms. Events and slots below still work, but they cannot be shown on the site yet.', 'event-booking-slots' );
		echo '</p></div>';
	}
}
