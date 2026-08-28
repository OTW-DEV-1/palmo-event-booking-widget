<?php
namespace EBS\Elementor;

use EBS\Duplicate_Guard;
use EBS\Event_Post_Type;
use EBS\Slot_Repository;
use Elementor\Controls_Manager;
use ElementorPro\Modules\Forms\Fields\Field_Base;
use ElementorPro\Modules\Forms\Classes\Form_Record;
use ElementorPro\Modules\Forms\Classes\Ajax_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A "Booking Slot" field for Elementor Pro forms: a date dropdown plus a time
 * dropdown, backed by an event's slots.
 *
 * The field submits the slot id. During validation that id is checked and the
 * seat is reserved, then the submitted value is swapped for a readable
 * "1 September 2026, 09:00" so webhooks, emails and submissions stay legible.
 */
class Slot_Field extends Field_Base {

	/**
	 * Slot ids reserved during validation, keyed by the record object.
	 *
	 * Held here so new_record() can turn the reservation into a booking row, and
	 * so anything still unclaimed at shutdown can be handed back.
	 *
	 * @var array<int,array{slot_id:int,seats:int,claimed:bool}>
	 */
	private static $reservations = array();

	public function get_type() {
		return 'ebs_booking_slot';
	}

	public function get_name() {
		return esc_html__( 'Booking Slot', 'event-booking-slots' );
	}

	/**
	 * Adds the field's own settings to the form widget's field repeater.
	 */
	public function update_controls( $widget ) {
		$elementor = \ElementorPro\Plugin::elementor();

		$control_data = $elementor->controls_manager->get_control_from_stack( $widget->get_unique_name(), 'form_fields' );

		if ( is_wp_error( $control_data ) ) {
			return;
		}

		$events = Event_Post_Type::selectable();

		$field_controls = array(
			'ebs_event_id'          => array(
				'name'         => 'ebs_event_id',
				'label'        => esc_html__( 'Booking event', 'event-booking-slots' ),
				'type'         => Controls_Manager::SELECT,
				'options'      => array( '' => esc_html__( '— Select an event —', 'event-booking-slots' ) ) + $events,
				'default'      => '',
				'description'  => empty( $events )
					? esc_html__( 'No published booking events yet. Create one under Bookings first.', 'event-booking-slots' )
					: esc_html__( 'Dates and time slots come from this event.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_date_placeholder'  => array(
				'name'         => 'ebs_date_placeholder',
				'label'        => esc_html__( 'Date placeholder', 'event-booking-slots' ),
				'type'         => Controls_Manager::TEXT,
				'default'      => '',
				'placeholder'  => esc_html__( 'Choose a date', 'event-booking-slots' ),
				'description'  => esc_html__( 'Leave empty to use the site language.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_time_placeholder'  => array(
				'name'         => 'ebs_time_placeholder',
				'label'        => esc_html__( 'Time placeholder', 'event-booking-slots' ),
				'type'         => Controls_Manager::TEXT,
				'default'      => '',
				'placeholder'  => esc_html__( 'Choose a time', 'event-booking-slots' ),
				'description'  => esc_html__( 'Leave empty to use the site language.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_reveal_fields'     => array(
				'name'         => 'ebs_reveal_fields',
				'label'        => esc_html__( 'Reveal the rest of the form after a slot is picked', 'event-booking-slots' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'description'  => esc_html__( 'Hides every field below this one, and the submit button, until a time slot is chosen.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_show_full'         => array(
				'name'         => 'ebs_show_full',
				'label'        => esc_html__( 'List fully booked slots', 'event-booking-slots' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'description'  => esc_html__( 'Shows them greyed out and unselectable. Turn off to hide them completely.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_scarcity'          => array(
				'name'         => 'ebs_scarcity',
				'label'        => esc_html__( 'Show a reduced places-left figure', 'event-booking-slots' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'description'  => esc_html__( 'Displays a fixed low number per slot instead of the true count, until real availability falls below it. The number shown is never higher than the places that actually exist. Check your local consumer-protection rules before using this.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_scarcity_min'      => array(
				'name'         => 'ebs_scarcity_min',
				'label'        => esc_html__( 'Lowest figure', 'event-booking-slots' ),
				'type'         => Controls_Manager::NUMBER,
				'default'      => 2,
				'min'          => 1,
				'condition'    => array( 'field_type' => $this->get_type(), 'ebs_scarcity' => 'yes' ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_scarcity_max'      => array(
				'name'         => 'ebs_scarcity_max',
				'label'        => esc_html__( 'Highest figure', 'event-booking-slots' ),
				'type'         => Controls_Manager::NUMBER,
				'default'      => 7,
				'min'          => 1,
				'condition'    => array( 'field_type' => $this->get_type(), 'ebs_scarcity' => 'yes' ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_summary'           => array(
				'name'         => 'ebs_summary',
				'label'        => esc_html__( 'Repeat the chosen time on the next step', 'event-booking-slots' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'description'  => esc_html__( 'Multi-step forms only. Shows the date and time the visitor picked at the top of the following step.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_slots_heading'     => array(
				'name'         => 'ebs_slots_heading',
				'label'        => esc_html__( 'Heading above the time list', 'event-booking-slots' ),
				'type'         => Controls_Manager::TEXT,
				'default'      => '',
				'description'  => esc_html__( 'Leave empty for no heading.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_remaining_text'    => array(
				'name'         => 'ebs_remaining_text',
				'label'        => esc_html__( 'Places-left wording', 'event-booking-slots' ),
				'type'         => Controls_Manager::TEXT,
				'default'      => '',
				'placeholder'  => esc_html__( '%d places left', 'event-booking-slots' ),
				'description'  => esc_html__( 'Use %d where the number should go. Leave empty to use the site language.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_keep_step'         => array(
				'name'         => 'ebs_keep_step',
				'label'        => esc_html__( 'Do not reset the form after sending', 'event-booking-slots' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'description'  => esc_html__( 'Elementor empties every field once a form is sent, and sends a multi-step form back to step one. Turn this on to leave the form exactly as the visitor left it, with the confirmation beside their answers.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_hide_radio'        => array(
				'name'         => 'ebs_hide_radio',
				'label'        => esc_html__( 'Hide the radio circle', 'event-booking-slots' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'description'  => esc_html__( 'Each time becomes a plain clickable row. The chosen one is highlighted instead of ticked.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
			'ebs_show_remaining'    => array(
				'name'         => 'ebs_show_remaining',
				'label'        => esc_html__( 'Show places left', 'event-booking-slots' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'description'  => esc_html__( 'Appends "(3 left)" to each time option.', 'event-booking-slots' ),
				'condition'    => array( 'field_type' => $this->get_type() ),
				'tab'          => 'content',
				'inner_tab'    => 'form_fields_content_tab',
				'tabs_wrapper' => 'form_fields_tabs',
			),
		);

		$control_data['fields'] = $this->inject_field_controls( $control_data['fields'], $field_controls );

		$widget->update_control( 'form_fields', $control_data );
	}

	/**
	 * Renders the day picker and an empty container for the slot table.
	 *
	 * The table itself is built by frontend.js from the REST endpoint, so a cached
	 * page never shows stale availability. The chosen slot is submitted as a radio
	 * group carrying Elementor's own field name.
	 */
	public function render( $item, $item_index, $form ) {
		$event_id = ! empty( $item['ebs_event_id'] ) ? absint( $item['ebs_event_id'] ) : 0;

		if ( ! $event_id ) {
			if ( current_user_can( 'edit_posts' ) ) {
				echo '<p class="ebs-notice ebs-notice-error">' . esc_html__( 'Booking Slot field: pick an event in the field settings.', 'event-booking-slots' ) . '</p>';
			}
			return;
		}

		// An optional booking field lets a visitor submit with no slot chosen: the
		// form succeeds, the webhook fires, the redirect happens -- and nothing is
		// booked, because there is nothing to book. Almost always a misconfiguration,
		// so say so where whoever built the form will see it.
		if ( empty( $item['required'] ) && current_user_can( 'edit_posts' ) ) {
			echo '<p class="ebs-notice ebs-notice-error">' . esc_html__( 'Booking Slot field: this field is not set to Required, so visitors can submit without choosing a slot and no booking will be recorded. Only you can see this message.', 'event-booking-slots' ) . '</p>';
		}

		// Belt and braces for the editor preview; the real enqueue happens on
		// wp_enqueue_scripts so it survives Elementor's element cache.
		wp_enqueue_style( 'ebs-frontend' );
		wp_enqueue_script( 'ebs-frontend' );

		$config = array(
			'eventId'         => $event_id,
			'restUrl'         => esc_url_raw( rest_url( \EBS\Rest_Controller::NAMESPACE_V1 . '/availability' ) ),
			'fieldName'       => $form->get_attribute_name( $item ),
			'fieldId'         => $form->get_attribute_id( $item ),
			'required'        => ! empty( $item['required'] ),
			'revealFields'    => ! empty( $item['ebs_reveal_fields'] ),
			'heading'         => ! empty( $item['ebs_slots_heading'] ) ? $item['ebs_slots_heading'] : '',
			'remainingText'   => ! empty( $item['ebs_remaining_text'] ) ? $item['ebs_remaining_text'] : '',
			'summary'         => ! empty( $item['ebs_summary'] ),
			'scarcity'        => ! empty( $item['ebs_scarcity'] ),
			'scarcityMin'     => isset( $item['ebs_scarcity_min'] ) && '' !== $item['ebs_scarcity_min'] ? absint( $item['ebs_scarcity_min'] ) : 2,
			'scarcityMax'     => isset( $item['ebs_scarcity_max'] ) && '' !== $item['ebs_scarcity_max'] ? absint( $item['ebs_scarcity_max'] ) : 7,
			'showFull'        => ! empty( $item['ebs_show_full'] ),
			'showRemaining'   => ! empty( $item['ebs_show_remaining'] ),
			'hideRadio'       => ! empty( $item['ebs_hide_radio'] ),
			'keepStep'        => ! empty( $item['ebs_keep_step'] ),
			'datePlaceholder' => self::resolve_text( $item, 'ebs_date_placeholder', 'Choose a date' ),
			'timePlaceholder' => self::resolve_text( $item, 'ebs_time_placeholder', 'Choose a time' ),
		);
		?>
		<?php
		// Set here rather than by the script so the list never renders with circles
		// for a frame before JavaScript removes them.
		$wrapper_class = 'ebs-slot-field' . ( $config['hideRadio'] ? ' ebs-no-radio' : '' );
		?>
		<div
			class="<?php echo esc_attr( $wrapper_class ); ?>"
			<?php echo $config['keepStep'] ? 'data-ebs-keep-step' : ''; ?>
			data-ebs-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<select
				id="<?php echo esc_attr( $config['fieldId'] ); ?>"
				class="elementor-field-textual ebs-date-select"
				data-ebs-role="date"
				aria-label="<?php echo esc_attr( $config['datePlaceholder'] ); ?>"
				disabled>
				<option value=""><?php esc_html_e( 'Loading…', 'event-booking-slots' ); ?></option>
			</select>

			<div class="ebs-slots" data-ebs-role="slots" hidden></div>

			<p class="ebs-slot-message" data-ebs-role="message" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * Returns a visitor-facing string in the site's language.
	 *
	 * Elementor stores a control's default into the saved form data the moment the
	 * field is added, so an English default becomes a literal that no translation
	 * can reach. Anything still matching the original English default is therefore
	 * treated as "not customised" and translated, which also repairs forms that were
	 * built before the defaults were emptied.
	 */
	private static function resolve_text( $item, $key, $english ) {
		$value = isset( $item[ $key ] ) ? trim( (string) $item[ $key ] ) : '';

		if ( '' === $value || $value === $english ) {
			return __( $english, 'event-booking-slots' ); // phpcs:ignore WordPress.WP.I18n
		}

		return $value;
	}

	/**
	 * Server-side capacity check. This, not the greyed-out option in the
	 * dropdown, is what actually enforces the limit.
	 */
	public function validation( $field, Form_Record $record, Ajax_Handler $ajax_handler ) {
		$field_id = $field['id'];
		$value    = isset( $field['value'] ) ? trim( (string) $field['value'] ) : '';

		if ( '' === $value ) {
			if ( ! empty( $field['required'] ) ) {
				$ajax_handler->add_error( $field_id, esc_html__( 'Please choose a date and time.', 'event-booking-slots' ) );
				return;
			}

			// Not required, so the submission is allowed through -- but no slot means
			// no booking row, which is what "the form worked and nothing saved" looks
			// like from the outside. Record why.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[event-booking-slots] submission accepted with no slot chosen (field is not marked Required), so no booking was recorded.' );
			}

			return;
		}

		$slot_id = absint( $value );
		$slot    = $slot_id ? Slot_Repository::get( $slot_id ) : null;

		if ( ! $slot ) {
			$ajax_handler->add_error( $field_id, esc_html__( 'That time slot no longer exists. Please pick another one.', 'event-booking-slots' ) );
			return;
		}

		$settings = $this->get_field_settings( $record, $field_id );
		$event_id = isset( $settings['ebs_event_id'] ) ? absint( $settings['ebs_event_id'] ) : 0;

		// Stops a crafted request from booking a slot belonging to a different event.
		if ( $event_id && (int) $slot->event_id !== $event_id ) {
			$ajax_handler->add_error( $field_id, esc_html__( 'That time slot is not part of this booking.', 'event-booking-slots' ) );
			return;
		}

		if ( 'open' !== $slot->status ) {
			$ajax_handler->add_error( $field_id, esc_html__( 'That time slot is closed. Please pick another one.', 'event-booking-slots' ) );
			return;
		}

		// Deliberately before reserve(): a rejected duplicate must never hold a
		// seat, not even for the length of the request.
		if ( $this->reject_duplicate( $field_id, (int) $slot->event_id, $record, $ajax_handler ) ) {
			return;
		}

		// Atomic: if two submissions race for the last seat, only one gets it.
		if ( ! Slot_Repository::reserve( $slot->id, 1 ) ) {
			$ajax_handler->add_error( $field_id, esc_html__( 'Sorry, that time slot just filled up. Please choose another one.', 'event-booking-slots' ) );
			return;
		}

		self::$reservations[ spl_object_id( $record ) ] = array(
			'slot_id' => (int) $slot->id,
			'seats'   => 1,
			'claimed' => false,
		);

		// Swap the raw slot id for something a human reading the webhook or the
		// notification email can actually understand.
		$record->update_field( $field_id, 'value', self::slot_label( $slot ) );
	}

	/**
	 * Blocks a submission from somebody who already holds a booking.
	 *
	 * The email and phone are read from the other fields of the same submission
	 * using the very same resolver that fills the bookings table, so the value
	 * checked and the value stored can never disagree.
	 *
	 * @return bool True when the submission was rejected.
	 */
	private function reject_duplicate( $field_id, $event_id, Form_Record $record, Ajax_Handler $ajax_handler ) {
		if ( ! Duplicate_Guard::is_enabled() ) {
			return false;
		}

		$fields  = $record->get( 'fields' );
		$contact = Elementor_Module::contact_from_fields( is_array( $fields ) ? $fields : array() );

		$existing = Duplicate_Guard::find( (int) $event_id, (string) $contact['email'], (string) $contact['phone'] );

		if ( ! $existing ) {
			return false;
		}

		$message = Duplicate_Guard::message( $existing, $contact );

		// add_error() only, deliberately. Form_Record::validate() aborts on a
		// non-empty $ajax_handler->errors, and Elementor then appends its own
		// generic banner text; adding the same wording through add_error_message()
		// as well would show the visitor two copies of it, ours followed by
		// "An error occurred". The field-level message is rendered as HTML, which
		// is why Settings restricts it with wp_kses.
		$ajax_handler->add_error( $field_id, $message );

		/**
		 * Fires when a submission is turned away as a duplicate.
		 *
		 * @param object $existing The booking that already exists.
		 * @param array  $contact  name / email / phone as submitted.
		 * @param int    $event_id
		 */
		do_action( 'ebs_duplicate_rejected', $existing, $contact, (int) $event_id );

		return true;
	}

	/**
	 * Turns the reservation made during validation into a stored booking.
	 */
	public static function claim_reservation( Form_Record $record ) {
		$key = spl_object_id( $record );

		if ( ! isset( self::$reservations[ $key ] ) || self::$reservations[ $key ]['claimed'] ) {
			return null;
		}

		self::$reservations[ $key ]['claimed'] = true;

		return self::$reservations[ $key ];
	}

	/**
	 * Hands back any seat that was reserved but never turned into a booking,
	 * e.g. because a later validation step rejected the submission.
	 */
	public static function release_unclaimed() {
		foreach ( self::$reservations as $key => $reservation ) {
			if ( $reservation['claimed'] ) {
				continue;
			}

			Slot_Repository::release( $reservation['slot_id'], $reservation['seats'] );
			unset( self::$reservations[ $key ] );
		}
	}

	/**
	 * What the webhook, the notification email and the submissions table show
	 * instead of a bare slot id.
	 */
	public static function slot_label( $slot ) {
		$date = \EBS\Slot_Repository::format_local( $slot->slot_date, $slot->slot_time, get_option( 'date_format' ) );
		$time = \EBS\Slot_Repository::format_local( $slot->slot_date, $slot->slot_time, get_option( 'time_format' ) );

		$label = $date . ', ' . $time;

		if ( ! empty( $slot->slot_end ) ) {
			$label .= ' – ' . \EBS\Slot_Repository::format_local( $slot->slot_date, $slot->slot_end, get_option( 'time_format' ) );
		}

		return $label;
	}

	/**
	 * Finds this field's editor settings inside the form's saved field list.
	 */
	private function get_field_settings( Form_Record $record, $field_id ) {
		$fields = $record->get( 'form_settings' );
		$fields = isset( $fields['form_fields'] ) ? $fields['form_fields'] : array();

		foreach ( $fields as $field ) {
			if ( isset( $field['custom_id'] ) && $field['custom_id'] === $field_id ) {
				return $field;
			}
		}

		return array();
	}
}
