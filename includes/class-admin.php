<?php
namespace EBS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event edit screen (slot definition + live slot table) and the bookings list.
 */
class Admin {

	const NONCE      = 'ebs_save_event';
	const BULK_NONCE = 'ebs_bulk_bookings';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Event_Post_Type::POST_TYPE, array( __CLASS__, 'save_event' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'add_bookings_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_ebs_cancel_booking', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_ebs_delete_booking', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_ebs_bulk_bookings', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_ebs_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_parse_notice' ) );

		add_filter( 'manage_' . Event_Post_Type::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Event_Post_Type::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
	}

	public static function enqueue( $hook ) {
		$screen      = get_current_screen();
		$is_event    = $screen && Event_Post_Type::POST_TYPE === $screen->post_type;
		$is_bookings = false !== strpos( (string) $hook, 'ebs-bookings' );

		if ( ! $is_event && ! $is_bookings ) {
			return;
		}

		wp_enqueue_style( 'ebs-admin', Assets::url( 'assets/css/admin.css' ), array(), Assets::version( 'assets/css/admin.css' ) );

		if ( $is_bookings ) {
			// Only the confirm-before-cancel handler. Kept in a file rather than an
			// onclick attribute so the page carries no inline script.
			wp_enqueue_script(
				'ebs-admin-bookings',
				Assets::url( 'assets/js/admin-bookings.js' ),
				array(),
				Assets::version( 'assets/js/admin-bookings.js' ),
				true
			);

			wp_localize_script(
				'ebs-admin-bookings',
				'ebsBookings',
				array(
					'endpoint' => esc_url_raw( rest_url( Admin_Rest::NAMESPACE_V1 . '/bookings/bulk' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'i18n'     => array(
						'cancel'        => __( 'Cancel this booking and free the seat?', 'event-booking-slots' ),
						'delete'        => __( 'Delete this booking permanently? The seat is freed and the record cannot be recovered.', 'event-booking-slots' ),
						/* translators: %s: number of bookings. */
						'bulkCancel'    => __( 'Cancel %s bookings and free their seats?', 'event-booking-slots' ),
						/* translators: %s: number of bookings. */
						'bulkDelete'    => __( 'Delete %s bookings permanently? Their seats are freed and the records cannot be recovered.', 'event-booking-slots' ),
						'chooseAction'  => __( 'Choose an action first.', 'event-booking-slots' ),
						'chooseRows'    => __( 'Select at least one booking first.', 'event-booking-slots' ),
						/* translators: %s: number of bookings. */
						'selected'      => __( '%s selected', 'event-booking-slots' ),
						'working'       => __( 'Working…', 'event-booking-slots' ),
						'failed'        => __( 'That did not work. Nothing was changed — reload the page and try again.', 'event-booking-slots' ),
						'cancelledPill' => __( 'Cancelled', 'event-booking-slots' ),
						'noBookings'    => __( 'No bookings found.', 'event-booking-slots' ),
					),
				)
			);
		}

		if ( ! $is_event || 'post' !== $screen->base ) {
			return;
		}

		global $wp_locale, $post;

		$event_id = $post ? (int) $post->ID : 0;
		$slots    = array();

		// Only open slots. A closed slot still holds bookings, and feeding it to the
		// editor would put it back in the saved payload -- which sync() would treat
		// as "still wanted" and reopen, silently undoing the admin's removal.
		foreach ( Slot_Repository::for_event( $event_id, true ) as $slot ) {
			$slots[] = array(
				'date'     => $slot->slot_date,
				'time'     => substr( $slot->slot_time, 0, 5 ),
				'end'      => $slot->slot_end ? substr( $slot->slot_end, 0, 5 ) : '',
				'capacity' => (int) $slot->capacity,
				'booked'   => (int) $slot->booked,
				'status'   => $slot->status,
			);
		}

		wp_enqueue_script(
			'ebs-admin-editor',
			Assets::url( 'assets/js/admin-editor.js' ),
			array(),
			Assets::version( 'assets/js/admin-editor.js' ),
			true
		);

		wp_localize_script(
			'ebs-admin-editor',
			'ebsEditor',
			array(
				'slots'           => $slots,
				'defaultCapacity' => Event_Post_Type::default_capacity( $event_id ),
				'defaultDuration' => Event_Post_Type::default_duration( $event_id ),
				'startOfWeek'     => (int) get_option( 'start_of_week' ),
				'months'          => array_values( $wp_locale->month ),
				'weekdays'        => array_values( $wp_locale->weekday_initial ),
				'today'           => current_time( 'Y-m-d' ),
				'i18n'            => array(
					'slotsFor'        => __( 'Slots for %s', 'event-booking-slots' ),
					'pickADay'        => __( 'Pick a day in the calendar to add time slots.', 'event-booking-slots' ),
					'noSlots'         => __( 'No slots on this day yet.', 'event-booking-slots' ),
					'time'            => __( 'Start', 'event-booking-slots' ),
					'endTime'         => __( 'End', 'event-booking-slots' ),
					'slotLength'      => __( 'Length', 'event-booking-slots' ),
					'endBeforeStart'  => __( 'The end time must be after the start time.', 'event-booking-slots' ),
					'capacity'        => __( 'Capacity', 'event-booking-slots' ),
					'booked'          => __( 'Booked', 'event-booking-slots' ),
					'addSlot'         => __( 'Add a slot', 'event-booking-slots' ),
					'remove'          => __( 'Remove', 'event-booking-slots' ),
					'bulkAdd'         => __( 'Add a range of slots', 'event-booking-slots' ),
					'from'            => __( 'From', 'event-booking-slots' ),
					'to'              => __( 'To', 'event-booking-slots' ),
					'every'           => __( 'Every', 'event-booking-slots' ),
					'minutes'         => __( 'minutes', 'event-booking-slots' ),
					'generate'        => __( 'Generate', 'event-booking-slots' ),
					'applyAllDay'     => __( 'Apply to every slot on this day', 'event-booking-slots' ),
					'applyAllDays'    => __( 'Apply to every slot on every day', 'event-booking-slots' ),
					'defaultCapacity' => __( 'Set capacity', 'event-booking-slots' ),
					'clearDay'        => __( 'Remove all slots on this day', 'event-booking-slots' ),
					'copyTo'          => __( 'Copy this day to…', 'event-booking-slots' ),
					'copy'            => __( 'Copy', 'event-booking-slots' ),
					'duplicateTime'   => __( 'There is already a slot at that time.', 'event-booking-slots' ),
					'badRange'        => __( 'Check the start time, end time and interval.', 'event-booking-slots' ),
					'hasBookings'     => __( 'This slot already has bookings. Removing it will cancel nothing, but it will stop accepting new ones. Continue?', 'event-booking-slots' ),
					'clearConfirm'    => __( 'Remove every slot on this day?', 'event-booking-slots' ),
					/* translators: %d: number of slots. */
					'slotCount'       => __( '%d slots', 'event-booking-slots' ),
					'oneSlot'         => __( '1 slot', 'event-booking-slots' ),
					'unlimited'       => __( 'Unlimited', 'event-booking-slots' ),
					'full'            => __( 'Full', 'event-booking-slots' ),
					'prevMonth'       => __( 'Previous month', 'event-booking-slots' ),
					'nextMonth'       => __( 'Next month', 'event-booking-slots' ),
					'saveReminder'    => __( 'Remember to update the event to save your changes.', 'event-booking-slots' ),
				),
			)
		);
	}

	public static function add_meta_boxes() {
		add_meta_box(
			'ebs-slot-definition',
			__( 'Dates & time slots', 'event-booking-slots' ),
			array( __CLASS__, 'render_definition_box' ),
			Event_Post_Type::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'ebs-slot-status',
			__( 'Current availability', 'event-booking-slots' ),
			array( __CLASS__, 'render_status_box' ),
			Event_Post_Type::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'ebs-usage',
			__( 'How to use', 'event-booking-slots' ),
			array( __CLASS__, 'render_usage_box' ),
			Event_Post_Type::POST_TYPE,
			'side',
			'default'
		);
	}

	public static function render_definition_box( $post ) {
		wp_nonce_field( self::NONCE, 'ebs_nonce' );

		$definition = Event_Post_Type::definition( $post->ID );
		$capacity   = Event_Post_Type::default_capacity( $post->ID );
		$duration   = Event_Post_Type::default_duration( $post->ID );
		?>
		<p class="ebs-default-cap">
			<label for="ebs_default_capacity"><strong><?php esc_html_e( 'Default capacity per slot', 'event-booking-slots' ); ?></strong></label>
			<input type="number" min="0" step="1" id="ebs_default_capacity" name="ebs_default_capacity" value="<?php echo esc_attr( $capacity ); ?>" class="small-text" />
			<span class="description"><?php esc_html_e( '0 means unlimited.', 'event-booking-slots' ); ?></span>

			<label for="ebs_default_duration"><strong><?php esc_html_e( 'Default slot length', 'event-booking-slots' ); ?></strong></label>
			<input type="number" min="0" step="5" id="ebs_default_duration" name="ebs_default_duration" value="<?php echo esc_attr( $duration ); ?>" class="small-text" />
			<span class="description"><?php esc_html_e( 'Minutes. Sets the end time of each new slot. 0 for a start time only.', 'event-booking-slots' ); ?></span>
		</p>

		<div id="ebs-editor" class="ebs-editor">
			<p class="ebs-editor-loading"><?php esc_html_e( 'Loading the calendar…', 'event-booking-slots' ); ?></p>
		</div>

		<input type="hidden" name="ebs_slots_json" id="ebs_slots_json" value="" />
		<input type="hidden" name="ebs_editor_active" id="ebs_editor_active" value="0" />

		<details class="ebs-advanced">
			<summary><?php esc_html_e( 'Advanced: paste a whole schedule as text', 'event-booking-slots' ); ?></summary>

			<p class="description"><?php esc_html_e( 'Use this to set up many dates at once. Editing the text here and saving replaces everything in the calendar above.', 'event-booking-slots' ); ?></p>

			<textarea id="ebs_slot_definition" name="ebs_slot_definition" rows="8" class="large-text code" spellcheck="false" placeholder="2026-09-01 | 09:00-13:00 /30 | 25"><?php echo esc_textarea( $definition ); ?></textarea>
			<input type="hidden" name="ebs_definition_original" value="<?php echo esc_attr( $definition ); ?>" />

			<div class="ebs-syntax">
				<p><strong><?php esc_html_e( 'One line per date:', 'event-booking-slots' ); ?></strong> <code>date | time | capacity</code></p>
				<ul>
					<li><code>2026-09-01 | 09:00 | 25</code> &mdash; <?php esc_html_e( 'one slot, ending after the default length', 'event-booking-slots' ); ?></li>
					<li><code>2026-09-01 | 09:00,10:00,11:00 | 25</code> &mdash; <?php esc_html_e( 'three such slots', 'event-booking-slots' ); ?></li>
					<li><code>2026-09-01 | 09:00-13:00 | 25</code> &mdash; <?php esc_html_e( 'one slot running 09:00 to 13:00', 'event-booking-slots' ); ?></li>
					<li><code>2026-09-01 | 09:00-13:00 /30 | 25</code> &mdash; <?php esc_html_e( 'that range split into 30 minute slots', 'event-booking-slots' ); ?></li>
					<li><code># lines starting with a hash are ignored</code></li>
				</ul>
			</div>
		</details>
		<?php
	}

	public static function render_status_box( $post ) {
		$slots = Slot_Repository::for_event( $post->ID );

		if ( empty( $slots ) ) {
			echo '<p>' . esc_html__( 'No slots yet. Fill in the box above and save.', 'event-booking-slots' ) . '</p>';
			return;
		}

		$bookings_url = admin_url( 'edit.php?post_type=' . Event_Post_Type::POST_TYPE . '&page=ebs-bookings&event_id=' . $post->ID );
		?>
		<table class="widefat striped ebs-slot-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'event-booking-slots' ); ?></th>
					<th><?php esc_html_e( 'Time', 'event-booking-slots' ); ?></th>
					<th><?php esc_html_e( 'Booked', 'event-booking-slots' ); ?></th>
					<th><?php esc_html_e( 'Capacity', 'event-booking-slots' ); ?></th>
					<th><?php esc_html_e( 'Left', 'event-booking-slots' ); ?></th>
					<th><?php esc_html_e( 'Status', 'event-booking-slots' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $slots as $slot ) : ?>
				<?php
				$unlimited = 0 === (int) $slot->capacity;
				$left      = $unlimited ? null : max( 0, (int) $slot->capacity - (int) $slot->booked );
				$is_full   = ! $unlimited && $left < 1;
				?>
				<tr class="<?php echo $is_full ? 'ebs-is-full' : ''; ?>">
					<td><?php echo esc_html( $slot->slot_date ); ?></td>
					<td><?php echo wp_kses_post( Slot_Repository::time_range_html( $slot ) ); ?></td>
					<td><?php echo esc_html( $slot->booked ); ?></td>
					<td><?php echo $unlimited ? '&infin;' : esc_html( $slot->capacity ); ?></td>
					<td><?php echo $unlimited ? '&infin;' : esc_html( $left ); ?></td>
					<td>
						<?php if ( 'open' !== $slot->status ) : ?>
							<span class="ebs-pill ebs-pill-closed"><?php esc_html_e( 'Closed', 'event-booking-slots' ); ?></span>
						<?php elseif ( $is_full ) : ?>
							<span class="ebs-pill ebs-pill-full"><?php esc_html_e( 'Full', 'event-booking-slots' ); ?></span>
						<?php else : ?>
							<span class="ebs-pill ebs-pill-open"><?php esc_html_e( 'Open', 'event-booking-slots' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( (int) $slot->booked > 0 ) : ?>
							<a href="<?php echo esc_url( $bookings_url . '&slot_id=' . $slot->id ); ?>"><?php esc_html_e( 'View bookings', 'event-booking-slots' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><a class="button" href="<?php echo esc_url( $bookings_url ); ?>"><?php esc_html_e( 'All bookings for this event', 'event-booking-slots' ); ?></a></p>
		<?php
	}

	public static function render_usage_box( $post ) {
		?>
		<p><?php esc_html_e( 'Add the "Booking Slot" field to any Elementor form, then pick this event in the field settings.', 'event-booking-slots' ); ?></p>
		<p><?php esc_html_e( 'Event ID:', 'event-booking-slots' ); ?> <code><?php echo esc_html( $post->ID ); ?></code></p>
		<p class="description"><?php esc_html_e( 'Full slots are hidden from the dropdown automatically, and the capacity is re-checked on the server when the form is submitted.', 'event-booking-slots' ); ?></p>
		<?php
	}

	public static function save_event( $post_id, $post ) {
		if ( ! isset( $_POST['ebs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ebs_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$capacity = isset( $_POST['ebs_default_capacity'] ) ? absint( wp_unslash( $_POST['ebs_default_capacity'] ) ) : 25;
		update_post_meta( $post_id, Event_Post_Type::META_CAPACITY, $capacity );

		$duration = isset( $_POST['ebs_default_duration'] ) ? absint( wp_unslash( $_POST['ebs_default_duration'] ) ) : 30;
		update_post_meta( $post_id, Event_Post_Type::META_DURATION, $duration );

		// Not sanitize_textarea_field(): it strips the newlines the syntax depends
		// on. The value is escaped on output and only ever read by Slot_Parser,
		// which validates every token.
		$definition = isset( $_POST['ebs_slot_definition'] ) ? wp_kses( wp_unslash( $_POST['ebs_slot_definition'] ), array() ) : '';
		$definition = self::normalize_newlines( $definition );

		// Compare against the value that was rendered into the box in this same
		// request, not against the stored meta. Browsers submit textareas with CRLF
		// line endings while the stored copy has LF, so a stored-value comparison
		// reports "changed" on every multi-line save -- which silently sent every
		// calendar edit down the text path and reset the capacities.
		$original = isset( $_POST['ebs_definition_original'] ) ? wp_kses( wp_unslash( $_POST['ebs_definition_original'] ), array() ) : '';
		$original = self::normalize_newlines( $original );

		$text_changed  = trim( $definition ) !== trim( $original ) && '' !== trim( $definition );
		$editor_active = ! empty( $_POST['ebs_editor_active'] );

		update_post_meta( $post_id, Event_Post_Type::META_DEFINITION, $definition );

		// Pasting into the advanced box is an explicit override; otherwise the
		// calendar is the source of truth.
		if ( $text_changed ) {
			$parsed = Slot_Parser::parse( $definition, $capacity, $duration );
			Slot_Repository::sync( $post_id, $parsed['slots'] );
			self::store_parse_errors( $post_id, $parsed['errors'] );
			return;
		}

		// A missing or empty payload here means the editor never initialised
		// (JS blocked, script error). Syncing an empty list would silently delete
		// every slot on the event, so leave the existing schedule untouched.
		if ( ! $editor_active ) {
			return;
		}

		$raw    = isset( $_POST['ebs_slots_json'] ) ? wp_unslash( $_POST['ebs_slots_json'] ) : '';
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return;
		}

		$slots  = array();
		$errors = array();

		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['date'] ) || empty( $entry['time'] ) ) {
				continue;
			}

			$date = self::sanitize_date( $entry['date'] );
			$time = self::sanitize_time( $entry['time'] );

			if ( ! $date || ! $time ) {
				continue;
			}

			$end = isset( $entry['end'] ) ? self::sanitize_time( $entry['end'] ) : false;

			$slots[] = array(
				'date'     => $date,
				'time'     => $time,
				'end'      => $end && $end > $time ? $end : null,
				'capacity' => isset( $entry['capacity'] ) && '' !== $entry['capacity'] ? absint( $entry['capacity'] ) : $capacity,
			);
		}

		// The slots table has a UNIQUE key on date+time, so collapse duplicates
		// rather than letting the insert fail. First one wins, matching how
		// Slot_Parser resolves a repeated line.
		$unique = array();
		foreach ( $slots as $slot ) {
			$key = $slot['date'] . ' ' . $slot['time'];

			if ( ! isset( $unique[ $key ] ) ) {
				$unique[ $key ] = $slot;
			}
		}

		Slot_Repository::sync( $post_id, array_values( $unique ) );
		self::store_parse_errors( $post_id, $errors );

		// Keep the text version in step so the advanced box mirrors the calendar.
		update_post_meta( $post_id, Event_Post_Type::META_DEFINITION, self::to_definition_text( array_values( $unique ) ) );
	}

	/**
	 * Textareas come back with CRLF; everything stored and parsed uses LF.
	 */
	private static function normalize_newlines( $value ) {
		return str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
	}

	private static function sanitize_date( $value ) {
		$value = trim( (string) $value );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : false;
	}

	private static function sanitize_time( $value ) {
		$value = trim( (string) $value );

		if ( ! preg_match( '/^(\d{1,2}):(\d{2})/', $value, $m ) ) {
			return false;
		}

		if ( (int) $m[1] > 23 || (int) $m[2] > 59 ) {
			return false;
		}

		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	/**
	 * Renders the current slot list back into the pipe syntax so the advanced
	 * box mirrors the calendar. Slots are grouped by date, then by end time and
	 * capacity, so a run of identical slots collapses onto one line.
	 */
	private static function to_definition_text( array $slots ) {
		$by_date = array();

		foreach ( $slots as $slot ) {
			$by_date[ $slot['date'] ][] = $slot;
		}

		ksort( $by_date );

		$lines = array();

		foreach ( $by_date as $date => $entries ) {
			$grouped = array();

			foreach ( $entries as $entry ) {
				$end = empty( $entry['end'] ) ? '' : $entry['end'];

				// A slot with an end time cannot share a comma-separated line, so
				// it gets a group of its own keyed by its own start time.
				$key = '' === $end ? 'open:' . (int) $entry['capacity'] : 'range:' . $entry['time'] . ':' . $end . ':' . (int) $entry['capacity'];

				$grouped[ $key ][] = $entry;
			}

			foreach ( $grouped as $key => $group ) {
				$capacity = (int) $group[0]['capacity'];

				if ( 0 === strpos( $key, 'range:' ) ) {
					$lines[] = $date . ' | ' . $group[0]['time'] . '-' . $group[0]['end'] . ' | ' . $capacity;
					continue;
				}

				$times = wp_list_pluck( $group, 'time' );
				sort( $times );

				$lines[] = $date . ' | ' . implode( ',', $times ) . ' | ' . $capacity;
			}
		}

		return implode( "\n", $lines );
	}

	private static function store_parse_errors( $post_id, array $errors ) {
		if ( ! empty( $errors ) ) {
			set_transient( 'ebs_parse_errors_' . $post_id, $errors, 60 );
			return;
		}

		delete_transient( 'ebs_parse_errors_' . $post_id );
	}

	public static function show_parse_notice() {
		$screen = get_current_screen();
		if ( ! $screen || Event_Post_Type::POST_TYPE !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id ) {
			return;
		}

		$errors = get_transient( 'ebs_parse_errors_' . $post_id );
		if ( empty( $errors ) ) {
			return;
		}

		delete_transient( 'ebs_parse_errors_' . $post_id );

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Some slot lines were skipped:', 'event-booking-slots' ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['ebs_slots']    = __( 'Slots', 'event-booking-slots' );
				$new['ebs_booked']   = __( 'Booked / Capacity', 'event-booking-slots' );
			}
		}

		return $new;
	}

	public static function column_content( $column, $post_id ) {
		if ( 'ebs_slots' !== $column && 'ebs_booked' !== $column ) {
			return;
		}

		$slots = Slot_Repository::for_event( $post_id );

		if ( 'ebs_slots' === $column ) {
			echo esc_html( count( $slots ) );
			return;
		}

		$booked   = 0;
		$capacity = 0;
		foreach ( $slots as $slot ) {
			$booked   += (int) $slot->booked;
			$capacity += (int) $slot->capacity;
		}

		echo esc_html( $booked . ' / ' . ( $capacity ?: '∞' ) );
	}

	public static function add_bookings_page() {
		add_submenu_page(
			'edit.php?post_type=' . Event_Post_Type::POST_TYPE,
			__( 'Bookings', 'event-booking-slots' ),
			__( 'Bookings', 'event-booking-slots' ),
			'edit_posts',
			'ebs-bookings',
			array( __CLASS__, 'render_bookings_page' )
		);
	}

	public static function render_bookings_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to view bookings.', 'event-booking-slots' ) );
		}

		$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
		$slot_id  = isset( $_GET['slot_id'] ) ? absint( $_GET['slot_id'] ) : 0;
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 50;

		$result = Booking_Repository::query(
			array(
				'event_id' => $event_id,
				'slot_id'  => $slot_id,
				'search'   => $search,
				'page'     => $paged,
				'per_page' => $per_page,
			)
		);

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=ebs_export&event_id=' . $event_id . '&slot_id=' . $slot_id ),
			'ebs_export'
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Bookings', 'event-booking-slots' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'event-booking-slots' ); ?></a>
			<hr class="wp-header-end" />

			<form method="get">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( Event_Post_Type::POST_TYPE ); ?>" />
				<input type="hidden" name="page" value="ebs-bookings" />
				<p class="search-box">
					<select name="event_id">
						<option value="0"><?php esc_html_e( 'All events', 'event-booking-slots' ); ?></option>
						<?php foreach ( Event_Post_Type::selectable() as $id => $title ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $event_id, $id ); ?>><?php echo esc_html( $title ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name, email or phone', 'event-booking-slots' ); ?>" />
					<?php submit_button( __( 'Filter', 'event-booking-slots' ), '', '', false ); ?>
				</p>
			</form>

			<div class="ebs-bulk-notice notice" role="status" aria-live="polite" hidden><p></p></div>

			<?php
			// Posts to admin-post.php so bulk actions still work with no JavaScript.
			// The script below intercepts the submit and uses the REST route instead,
			// which is the same work without redrawing the page.
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ebs-bookings-form">
				<input type="hidden" name="action" value="ebs_bulk_bookings" />
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( self::current_url() ); ?>" />
				<?php wp_nonce_field( self::BULK_NONCE ); ?>

			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<label for="ebs-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'event-booking-slots' ); ?></label>
					<select name="bulk_action" id="ebs-bulk-action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'event-booking-slots' ); ?></option>
						<option value="cancel"><?php esc_html_e( 'Cancel', 'event-booking-slots' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete permanently', 'event-booking-slots' ); ?></option>
					</select>
					<button type="submit" class="button action ebs-bulk-apply"><?php esc_html_e( 'Apply', 'event-booking-slots' ); ?></button>
					<span class="ebs-bulk-count" aria-live="polite"></span>
				</div>
			</div>

			<table class="widefat striped ebs-bookings-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<label class="screen-reader-text" for="ebs-select-all"><?php esc_html_e( 'Select all bookings', 'event-booking-slots' ); ?></label>
							<input type="checkbox" id="ebs-select-all" />
						</td>
						<th><?php esc_html_e( 'When', 'event-booking-slots' ); ?></th>
						<th><?php esc_html_e( 'Event', 'event-booking-slots' ); ?></th>
						<th><?php esc_html_e( 'Name', 'event-booking-slots' ); ?></th>
						<th><?php esc_html_e( 'Email', 'event-booking-slots' ); ?></th>
						<th><?php esc_html_e( 'Phone', 'event-booking-slots' ); ?></th>
						<th><?php esc_html_e( 'Status', 'event-booking-slots' ); ?></th>
						<th><?php esc_html_e( 'Submitted', 'event-booking-slots' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $result['items'] ) ) : ?>
					<tr class="ebs-no-bookings"><td colspan="9"><?php esc_html_e( 'No bookings found.', 'event-booking-slots' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $result['items'] as $booking ) : ?>
						<tr data-ebs-booking="<?php echo esc_attr( $booking->id ); ?>">
							<th scope="row" class="check-column">
								<label class="screen-reader-text" for="ebs-cb-<?php echo esc_attr( $booking->id ); ?>">
									<?php
									printf(
										/* translators: %s: the person's name. */
										esc_html__( 'Select the booking for %s', 'event-booking-slots' ),
										esc_html( '' !== $booking->name ? $booking->name : $booking->email )
									);
									?>
								</label>
								<input type="checkbox" id="ebs-cb-<?php echo esc_attr( $booking->id ); ?>" class="ebs-cb" name="booking_ids[]" value="<?php echo esc_attr( $booking->id ); ?>" />
							</th>
							<td><strong><?php echo esc_html( $booking->slot_date ) . ' ' . wp_kses_post( Slot_Repository::time_range_html( $booking ) ); ?></strong></td>
							<td><?php echo esc_html( get_the_title( $booking->event_id ) ); ?></td>
							<td><?php echo esc_html( $booking->name ); ?></td>
							<td><?php echo esc_html( $booking->email ); ?></td>
							<td><?php echo esc_html( $booking->phone ); ?></td>
							<td data-ebs-cell="status">
								<?php if ( 'cancelled' === $booking->status ) : ?>
									<span class="ebs-pill ebs-pill-closed"><?php esc_html_e( 'Cancelled', 'event-booking-slots' ); ?></span>
								<?php else : ?>
									<span class="ebs-pill ebs-pill-open"><?php esc_html_e( 'Confirmed', 'event-booking-slots' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $booking->created_at ); ?></td>
							<td data-ebs-cell="actions">
								<?php if ( 'cancelled' !== $booking->status ) : ?>
									<a class="button button-small" data-ebs-confirm="cancel" data-ebs-action="cancel" data-ebs-id="<?php echo esc_attr( $booking->id ); ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebs_cancel_booking&booking_id=' . $booking->id ), 'ebs_cancel_' . $booking->id ) ); ?>"><?php esc_html_e( 'Cancel', 'event-booking-slots' ); ?></a>
								<?php endif; ?>
								<a class="button button-small ebs-delete" data-ebs-confirm="delete" data-ebs-action="delete" data-ebs-id="<?php echo esc_attr( $booking->id ); ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebs_delete_booking&booking_id=' . $booking->id ), 'ebs_delete_' . $booking->id ) ); ?>"><?php esc_html_e( 'Delete', 'event-booking-slots' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			</form>

			<?php
			$pages = (int) ceil( $result['total'] / $per_page );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	public static function handle_cancel() {
		$booking_id = isset( $_GET['booking_id'] ) ? absint( $_GET['booking_id'] ) : 0;

		check_admin_referer( 'ebs_cancel_' . $booking_id );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to cancel bookings.', 'event-booking-slots' ) );
		}

		Booking_Repository::cancel( $booking_id );

		self::redirect_back();
	}

	/**
	 * Removes a booking for good and frees its seat.
	 *
	 * Same capability as cancelling and exporting: anyone who can reach this
	 * screen can already read every booking on it. The confirmation prompt is
	 * what guards against a mis-click, since this one cannot be undone.
	 */
	public static function handle_delete() {
		$booking_id = isset( $_GET['booking_id'] ) ? absint( $_GET['booking_id'] ) : 0;

		check_admin_referer( 'ebs_delete_' . $booking_id );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to delete bookings.', 'event-booking-slots' ) );
		}

		Booking_Repository::delete( $booking_id );

		self::redirect_back();
	}

	/**
	 * Bulk cancel or delete without JavaScript.
	 *
	 * The screen normally does this through the REST route so the page does not
	 * have to be redrawn, but the form posts here so the feature still works when
	 * the script is blocked, delayed or has failed.
	 */
	public static function handle_bulk() {
		check_admin_referer( self::BULK_NONCE );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to change bookings.', 'event-booking-slots' ) );
		}

		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = isset( $_POST['booking_ids'] ) ? (array) wp_unslash( $_POST['booking_ids'] ) : array();
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( in_array( $action, array( 'cancel', 'delete' ), true ) && $ids ) {
			// The same ceiling the REST route applies, for the same reason.
			$ids = array_slice( $ids, 0, Admin_Rest::MAX_PER_REQUEST );

			foreach ( $ids as $id ) {
				if ( 'delete' === $action ) {
					Booking_Repository::delete( $id );
					continue;
				}

				Booking_Repository::cancel( $id );
			}
		}

		self::redirect_back();
	}

	/**
	 * This screen's own URL, filters and page number included, for a form to
	 * return to once it has posted elsewhere.
	 */
	private static function current_url() {
		$url = admin_url( 'edit.php?post_type=' . Event_Post_Type::POST_TYPE . '&page=ebs-bookings' );

		foreach ( array( 'event_id', 'slot_id', 'paged' ) as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				$url = add_query_arg( $key, absint( $_GET[ $key ] ), $url );
			}
		}

		if ( ! empty( $_GET['s'] ) ) {
			$url = add_query_arg( 's', rawurlencode( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ), $url );
		}

		return $url;
	}

	/**
	 * Back to the list the action was started from, keeping its filters and page.
	 */
	private static function redirect_back() {
		// The bulk form carries the list's own URL, because a POST's referer is the
		// screen it came from but the row links have no such field. wp_safe_redirect
		// refuses anything off-site either way.
		$target = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';

		if ( '' === $target ) {
			$target = wp_get_referer();
		}

		wp_safe_redirect( $target ? $target : admin_url( 'edit.php?post_type=' . Event_Post_Type::POST_TYPE . '&page=ebs-bookings' ) );
		exit;
	}

	public static function handle_export() {
		check_admin_referer( 'ebs_export' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to export bookings.', 'event-booking-slots' ) );
		}

		// Shared hosts commonly cap max_execution_time at 30s. Exporting thousands of
		// rows decodes JSON and looks up titles per row, so lift the ceiling where the
		// host allows it and keep the per-row work cheap below.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		wp_raise_memory_limit( 'admin' );

		$result = Booking_Repository::query(
			array(
				'event_id' => isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0,
				'slot_id'  => isset( $_GET['slot_id'] ) ? absint( $_GET['slot_id'] ) : 0,
				'per_page' => 10000,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=bookings-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		// BOM so Excel opens UTF-8 names correctly.
		fwrite( $out, "\xEF\xBB\xBF" );

		// Decode each payload once and keep it: the previous version decoded every
		// row twice, which is the slowest part of a large export.
		$payloads   = array();
		$extra_keys = array();

		foreach ( $result['items'] as $index => $booking ) {
			$payload            = json_decode( (string) $booking->payload, true );
			$payloads[ $index ] = is_array( $payload ) ? $payload : array();

			foreach ( array_keys( $payloads[ $index ] ) as $key ) {
				$extra_keys[ $key ] = true;
			}
		}

		$extra_keys = array_keys( $extra_keys );

		// One title lookup per event rather than per booking.
		$titles = array();

		fputcsv( $out, array_merge( array( 'Date', 'Start', 'End', 'Event', 'Name', 'Email', 'Phone', 'Seats', 'Status', 'Submitted' ), $extra_keys ) );

		foreach ( $result['items'] as $index => $booking ) {
			$payload = $payloads[ $index ];

			if ( ! isset( $titles[ $booking->event_id ] ) ) {
				$titles[ $booking->event_id ] = get_the_title( $booking->event_id );
			}

			$row = array(
				$booking->slot_date,
				substr( $booking->slot_time, 0, 5 ),
				$booking->slot_end ? substr( $booking->slot_end, 0, 5 ) : '',
				$titles[ $booking->event_id ],
				$booking->name,
				$booking->email,
				$booking->phone,
				$booking->seats,
				$booking->status,
				$booking->created_at,
			);

			foreach ( $extra_keys as $key ) {
				$value = isset( $payload[ $key ] ) ? $payload[ $key ] : '';
				$row[] = is_scalar( $value ) ? $value : wp_json_encode( $value );
			}

			fputcsv( $out, $row );
		}

		fclose( $out );
		exit;
	}
}
