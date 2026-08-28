<?php
declare(strict_types=1);

namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings, stored as one option array and edited through the Settings API.
 */
class Settings {

	const OPTION = 'ebs_settings';
	const GROUP  = 'ebs_settings_group';
	const PAGE   = 'ebs-settings';

	/**
	 * Cached copy so a submission that reads settings several times during
	 * validation does not hit get_option() repeatedly.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'duplicate_email'     => 1,
			'duplicate_phone'     => 1,
			'duplicate_scope'     => 'event',
			'duplicate_cancelled' => 0,
			'duplicate_message'   => __( 'You already have a booking for this event. Please contact us if you need to change it.', 'event-booking-slots' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}

		return self::$cache;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	public static function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Event_Post_Type::POST_TYPE,
			__( 'Booking Settings', 'event-booking-slots' ),
			__( 'Settings', 'event-booking-slots' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'ebs_duplicates',
			__( 'Duplicate bookings', 'event-booking-slots' ),
			array( __CLASS__, 'section_intro' ),
			self::PAGE
		);

		add_settings_field(
			'duplicate_match',
			__( 'Match on', 'event-booking-slots' ),
			array( __CLASS__, 'field_match' ),
			self::PAGE,
			'ebs_duplicates'
		);

		add_settings_field(
			'duplicate_scope',
			__( 'Look for duplicates in', 'event-booking-slots' ),
			array( __CLASS__, 'field_scope' ),
			self::PAGE,
			'ebs_duplicates',
			array( 'label_for' => 'ebs_duplicate_scope' )
		);

		add_settings_field(
			'duplicate_cancelled',
			__( 'Cancelled bookings', 'event-booking-slots' ),
			array( __CLASS__, 'field_cancelled' ),
			self::PAGE,
			'ebs_duplicates'
		);

		add_settings_field(
			'duplicate_message',
			__( 'Message shown to the visitor', 'event-booking-slots' ),
			array( __CLASS__, 'field_message' ),
			self::PAGE,
			'ebs_duplicates',
			array( 'label_for' => 'ebs_duplicate_message' )
		);
	}

	public static function section_intro(): void {
		echo '<p>' . esc_html__( 'Stops the same person booking the same event twice. The check runs when the form is submitted, before the seat is held.', 'event-booking-slots' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Email and phone are read from the submitted form fields. Phone numbers are compared on their digits only, so "050-123 4567" and "+972 50 1234567" count as the same number.', 'event-booking-slots' ) . '</p>';
	}

	public static function field_match(): void {
		$all = self::all();
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Fields to match duplicates on', 'event-booking-slots' ); ?></legend>

			<label for="ebs_duplicate_email">
				<input type="checkbox" id="ebs_duplicate_email" name="<?php echo esc_attr( self::OPTION ); ?>[duplicate_email]" value="1" <?php checked( ! empty( $all['duplicate_email'] ) ); ?> />
				<?php esc_html_e( 'Email address', 'event-booking-slots' ); ?>
			</label>
			<br />
			<label for="ebs_duplicate_phone">
				<input type="checkbox" id="ebs_duplicate_phone" name="<?php echo esc_attr( self::OPTION ); ?>[duplicate_phone]" value="1" <?php checked( ! empty( $all['duplicate_phone'] ) ); ?> />
				<?php esc_html_e( 'Phone number', 'event-booking-slots' ); ?>
			</label>

			<p class="description"><?php esc_html_e( 'A submission is blocked when either of the ticked fields already has a booking. Untick both to turn duplicate checking off.', 'event-booking-slots' ); ?></p>
		</fieldset>
		<?php
	}

	public static function field_scope(): void {
		$scope = (string) self::get( 'duplicate_scope' );
		?>
		<select id="ebs_duplicate_scope" name="<?php echo esc_attr( self::OPTION ); ?>[duplicate_scope]">
			<option value="event" <?php selected( $scope, 'event' ); ?>><?php esc_html_e( 'This event only', 'event-booking-slots' ); ?></option>
			<option value="site" <?php selected( $scope, 'site' ); ?>><?php esc_html_e( 'Every event on the site', 'event-booking-slots' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( '"This event only" lets someone book a different event with the same details, which is usually what you want.', 'event-booking-slots' ); ?></p>
		<?php
	}

	public static function field_cancelled(): void {
		?>
		<label for="ebs_duplicate_cancelled">
			<input type="checkbox" id="ebs_duplicate_cancelled" name="<?php echo esc_attr( self::OPTION ); ?>[duplicate_cancelled]" value="1" <?php checked( ! empty( self::get( 'duplicate_cancelled' ) ) ); ?> />
			<?php esc_html_e( 'Count cancelled bookings as duplicates too', 'event-booking-slots' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Off by default, so somebody whose booking you cancelled can book again.', 'event-booking-slots' ); ?></p>
		<?php
	}

	public static function field_message(): void {
		$message = (string) self::get( 'duplicate_message' );
		?>
		<textarea id="ebs_duplicate_message" name="<?php echo esc_attr( self::OPTION ); ?>[duplicate_message]" rows="4" class="large-text"><?php echo esc_textarea( $message ); ?></textarea>

		<p class="description">
			<?php esc_html_e( 'Shown on the form, next to the date and time field, instead of a confirmation. These placeholders are replaced:', 'event-booking-slots' ); ?>
			<code>{name}</code> <code>{email}</code> <code>{phone}</code> <code>{event}</code> <code>{date}</code> <code>{time}</code>
		</p>
		<p class="description">
			<?php esc_html_e( 'The date and time are those of the booking the visitor already has. Basic formatting is allowed: bold, italic, line breaks and links.', 'event-booking-slots' ); ?>
		</p>
		<?php
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();

		$scope = isset( $input['duplicate_scope'] ) ? sanitize_key( (string) $input['duplicate_scope'] ) : 'event';

		$message = isset( $input['duplicate_message'] ) ? (string) wp_unslash( $input['duplicate_message'] ) : '';
		$message = trim( wp_kses( $message, self::allowed_message_html() ) );

		return array(
			// Unchecked boxes are simply absent from the POST body.
			'duplicate_email'     => empty( $input['duplicate_email'] ) ? 0 : 1,
			'duplicate_phone'     => empty( $input['duplicate_phone'] ) ? 0 : 1,
			'duplicate_scope'     => in_array( $scope, array( 'event', 'site' ), true ) ? $scope : 'event',
			'duplicate_cancelled' => empty( $input['duplicate_cancelled'] ) ? 0 : 1,
			// An empty box would leave the visitor with a silent failure, so fall
			// back to the shipped wording rather than storing nothing.
			'duplicate_message'   => '' === $message ? (string) $defaults['duplicate_message'] : $message,
		);
	}

	/**
	 * The message is written by an administrator and rendered on the frontend, so
	 * it keeps light formatting but no scripts, styles, iframes or event handlers.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_message_html(): array {
		return array(
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
			'span'   => array(),
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			),
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'event-booking-slots' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Booking Settings', 'event-booking-slots' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
