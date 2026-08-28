( function () {
	'use strict';

	var i18n = window.ebsI18n || {};
	var uid = 0;

	function text( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function el( tag, className, content ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( content !== undefined ) {
			node.textContent = content;
		}
		return node;
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function setMessage( field, message, isError ) {
		var node = field.querySelector( '[data-ebs-role="message"]' );
		if ( ! node ) {
			return;
		}
		node.textContent = message || '';
		node.classList.toggle( 'ebs-is-error', !! isError );
	}

	/**
	 * The field groups that follow this one and should stay hidden until a slot
	 * is chosen.
	 *
	 * In a multi-step form every step's fields are siblings in the same wrapper,
	 * and Elementor decides which step is on screen. Hiding past a step boundary
	 * would fight that -- and would leave the next step's fields disabled after
	 * Elementor had shown them. So the list stops at the next step marker, and in
	 * a stepped form where the booking field ends its step, nothing is hidden at
	 * all: Elementor is already doing the progressive disclosure.
	 */
	function laterGroups( field ) {
		var group = field.closest( '.elementor-field-group' );
		var wrapper = field.closest( '.elementor-form-fields-wrapper' );

		if ( ! group || ! wrapper ) {
			return [];
		}

		var all = Array.prototype.slice.call( wrapper.children );
		var index = all.indexOf( group );

		if ( index === -1 ) {
			return [];
		}

		var out = [];

		for ( var i = index + 1; i < all.length; i++ ) {
			if ( all[ i ].classList.contains( 'elementor-field-type-step' ) ) {
				break;
			}
			out.push( all[ i ] );
		}

		return out;
	}

	function setGroupsHidden( groups, hidden ) {
		groups.forEach( function ( group ) {
			group.classList.toggle( 'ebs-hidden-until-slot', hidden );

			// A hidden but still-required input makes the browser block submit with
			// "not focusable", so disable them while they are out of view. Hidden
			// inputs are exempt from validation and must keep submitting (UTM etc).
			group.querySelectorAll( 'input, select, textarea, button' ).forEach( function ( input ) {
				if ( input.type === 'hidden' ) {
					return;
				}
				input.disabled = hidden;
			} );
		} );
	}

	/**
	 * The step container that follows the one holding this field.
	 *
	 * Elementor rebuilds a multi-step form at runtime: each .elementor-field-type-step
	 * becomes an .e-form__step that swallows the field groups after it, and steps are
	 * shown by toggling .elementor-hidden. This is only looked up when a slot is
	 * actually picked, by which point that rebuild has certainly happened.
	 */
	function nextStep( field ) {
		var step = field.closest( '.e-form__step' );

		if ( ! step ) {
			return null;
		}

		var node = step.nextElementSibling;

		while ( node && ! node.classList.contains( 'e-form__step' ) ) {
			node = node.nextElementSibling;
		}

		return node;
	}

	/**
	 * Puts "the time you chose" at the top of the following step, so the visitor can
	 * still see it while filling in their details.
	 */
	function showSummary( field, config, dateLabel, timeLabel ) {
		if ( ! config.summary ) {
			return;
		}

		var target = nextStep( field );

		if ( ! target ) {
			return;
		}

		var box = target.querySelector( '.ebs-summary' );

		if ( ! box ) {
			// Deliberately NOT .elementor-field-group: when Elementor moves to a step it
			// focuses the first field group, and a group with no input inside gets the
			// focus itself -- which would take it away from the first real field and
			// draw a focus ring around a read-only line of text.
			box = el( 'div', 'ebs-summary' );
			box.appendChild( el( 'span', 'ebs-summary-label', text( 'chosen', 'Your chosen time' ) ) );
			box.appendChild( el( 'strong', 'ebs-summary-value' ) );
			target.insertBefore( box, target.firstChild );
		}

		var value = box.querySelector( '.ebs-summary-value' );
		value.textContent = dateLabel + ', ' + timeLabel;
		value.setAttribute( 'dir', 'auto' );
	}

	function clearSummary( field ) {
		var target = nextStep( field );
		var box = target ? target.querySelector( '.ebs-summary' ) : null;

		if ( box ) {
			box.remove();
		}
	}

	function initField( field ) {
		if ( field.dataset.ebsReady === '1' ) {
			return;
		}
		field.dataset.ebsReady = '1';

		var config;
		try {
			config = JSON.parse( field.dataset.ebsConfig || '{}' );
		} catch ( e ) {
			return;
		}

		var dateSelect = field.querySelector( '[data-ebs-role="date"]' );
		var slotBox = field.querySelector( '[data-ebs-role="slots"]' );

		if ( ! dateSelect || ! slotBox || ! config.eventId ) {
			return;
		}

		var dates = [];
		var currentDateLabel = '';
		var groups = config.revealFields ? laterGroups( field ) : [];

		// Set by the post-submission refresh below: the date and slot the visitor
		// had chosen, to be put back once the new availability arrives.
		var restore = null;

		if ( field.dataset.ebsRestore ) {
			try {
				restore = JSON.parse( field.dataset.ebsRestore );
			} catch ( e ) {
				restore = null;
			}

			delete field.dataset.ebsRestore;
		}

		/**
		 * Re-checks a slot after a refresh. Silently does nothing when that slot has
		 * since filled up or closed, which leaves the visitor on their date with
		 * nothing selected -- the honest outcome, and the one the error was about.
		 */
		function reselect( slotId ) {
			if ( ! slotId || ! /^\d+$/.test( String( slotId ) ) ) {
				return;
			}

			var radio = slotBox.querySelector( '.ebs-slot-radio[value="' + slotId + '"]' );

			if ( ! radio || radio.disabled ) {
				return;
			}

			radio.checked = true;
			radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		function reveal( show ) {
			if ( groups.length ) {
				setGroupsHidden( groups, ! show );
			}
		}

		function findDate( value ) {
			for ( var i = 0; i < dates.length; i++ ) {
				if ( dates[ i ].date === value ) {
					return dates[ i ];
				}
			}
			return null;
		}

		function renderSlots( dateValue ) {
			clear( slotBox );
			reveal( false );
			clearSummary( field );

			var entry = findDate( dateValue );

			if ( ! entry ) {
				slotBox.hidden = true;
				return;
			}

			slotBox.hidden = false;
			currentDateLabel = entry.label;

			if ( config.heading ) {
				slotBox.appendChild( el( 'p', 'ebs-slots-heading', config.heading ) );
			}

			// No header row: the two columns are a radio and the time, which need no
			// labelling, and a stray English "TIME / PLACES LEFT" header cannot be
			// translated away by the page's own language.
			var table = el( 'table', 'ebs-slots-table' );
			var body = el( 'tbody' );

			entry.slots.forEach( function ( slot ) {
				body.appendChild( buildRow( slot ) );
			} );

			table.appendChild( body );
			slotBox.appendChild( table );
		}

		function buildRow( slot ) {
			var row = el( 'tr', 'ebs-slot-row' );
			var id = 'ebs-slot-' + ( ++uid );

			var radio = document.createElement( 'input' );
			radio.type = 'radio';
			radio.name = config.fieldName;
			radio.value = slot.id;
			radio.id = id;
			radio.className = 'ebs-slot-radio';

			if ( config.required ) {
				radio.required = true;
			}

			if ( slot.full ) {
				radio.disabled = true;
				row.classList.add( 'ebs-is-full' );
			}

			radio.addEventListener( 'change', function () {
				if ( ! radio.checked ) {
					return;
				}

				slotBox.querySelectorAll( '.ebs-slot-row' ).forEach( function ( r ) {
					r.classList.remove( 'ebs-is-selected' );
				} );

				row.classList.add( 'ebs-is-selected' );
				setMessage( field, '' );
				reveal( true );
				showSummary( field, config, currentDateLabel, slot.label );
			} );

			var timeCell = el( 'td', 'ebs-col-time' );

			if ( config.hideRadio ) {
				// The radio moves into the visible cell rather than its column being
				// hidden. A required input inside a display:none cell is not rendered,
				// so the browser cannot focus it to report the validation failure and
				// silently refuses to submit the form. Kept here it is still in the
				// layout -- clipped to a point by CSS -- so validation and keyboard
				// focus both behave normally.
				timeCell.appendChild( radio );
			} else {
				var pick = el( 'td', 'ebs-col-pick' );
				pick.appendChild( radio );
				row.appendChild( pick );
			}

			var label = el( 'label', 'ebs-time', slot.label );
			label.setAttribute( 'for', id );

			// "09:00 – 10:00" is bidirectionally neutral, so an RTL page flips it to
			// read "10:00 – 09:00". Isolating it as LTR keeps the written order.
			label.setAttribute( 'dir', 'ltr' );
			timeCell.appendChild( label );

			if ( slot.full ) {
				timeCell.appendChild( el( 'span', 'ebs-left ebs-is-full-tag', text( 'full', 'Full' ) ) );
			} else if ( config.showRemaining && slot.remaining !== null && slot.remaining !== undefined ) {
				var template = config.remainingText || text( 'left', '%d places left' );
				timeCell.appendChild( el( 'span', 'ebs-left', template.replace( '%d', slot.remaining ) ) );
			}

			row.appendChild( timeCell );

			// The whole row is a click target, not just the small radio.
			row.addEventListener( 'click', function ( event ) {
				if ( radio.disabled || event.target === radio || event.target.tagName === 'LABEL' ) {
					return;
				}
				radio.checked = true;
				radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );

			return row;
		}

		function renderDates() {
			clear( dateSelect );

			// Only renderSlots() used to empty this, so re-initialising the field left
			// the previous times on screen: the date select showed its placeholder
			// while the old list, and the old ticked slot, sat underneath it. The
			// paths below that do select a date re-render it immediately.
			clear( slotBox );
			slotBox.hidden = true;

			if ( ! dates.length ) {
				dateSelect.appendChild( new Option( text( 'noDates', 'No dates are available right now.' ), '' ) );
				dateSelect.disabled = true;
				slotBox.hidden = true;
				return;
			}

			dateSelect.appendChild( new Option( config.datePlaceholder, '' ) );
			dates.forEach( function ( entry ) {
				dateSelect.appendChild( new Option( entry.label, entry.date ) );
			} );

			dateSelect.disabled = false;

			// Put the visitor back where they were after a post-submission refresh,
			// so updated availability does not cost them their choice.
			if ( restore && restore.date && findDate( restore.date ) ) {
				var wantedDate = restore.date;
				var wantedSlot = restore.slot;

				restore = null;

				dateSelect.value = wantedDate;
				renderSlots( wantedDate );
				reselect( wantedSlot );

				return;
			}

			// With a single date there is nothing to choose, so open it straight away.
			if ( dates.length === 1 ) {
				dateSelect.value = dates[ 0 ].date;
				renderSlots( dates[ 0 ].date );
			}
		}

		dateSelect.addEventListener( 'change', function () {
			renderSlots( dateSelect.value );
		} );

		reveal( false );

		var url = config.restUrl +
			( config.restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'event_id=' + encodeURIComponent( config.eventId ) +
			'&show_full=' + ( config.showFull ? '1' : '0' ) +
			'&scarcity=' + ( config.scarcity ? '1' : '0' ) +
			'&scarcity_min=' + encodeURIComponent( config.scarcityMin || 2 ) +
			'&scarcity_max=' + encodeURIComponent( config.scarcityMax || 7 );

		fetch( url, { credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				dates = ( data && data.dates ) || [];
				renderDates();
			} )
			.catch( function () {
				clear( dateSelect );
				dateSelect.appendChild( new Option( text( 'loadError', 'Could not load the available dates. Please refresh the page.' ), '' ) );
				dateSelect.disabled = true;
				slotBox.hidden = true;
				setMessage( field, text( 'loadError', 'Could not load the available dates. Please refresh the page.' ), true );
			} );
	}

	function initAll( root ) {
		( root || document ).querySelectorAll( '.ebs-slot-field' ).forEach( initField );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	} else {
		initAll();
	}

	// Elementor popups and the editor preview inject forms after page load.
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'elementor/popup/show elementor/render/start', function () {
			setTimeout( initAll, 0 );
		} );
	}

	/* --- "Do not reset the form after sending" ---------------------------- */

	/**
	 * Stops Elementor emptying the fields once a submission succeeds.
	 *
	 * Elementor clears the form with jQuery's $form.trigger( 'reset' ), which runs
	 * the jQuery handlers and then calls the element's own reset(). That native
	 * reset() fires a real, cancellable `reset` event of its own -- so cancelling
	 * that event stops the clearing, whichever route asked for it: jQuery's
	 * trigger, a direct form.reset(), or a plain reset button.
	 *
	 * Deliberately native rather than a jQuery handler. This script opts out of
	 * "delay JavaScript" optimisers (WP Rocket and friends) so the availability
	 * fetch is not held back, which means it can run BEFORE a delayed jQuery
	 * exists -- and a `if ( window.jQuery )` binding at load time would then
	 * silently never happen. Nothing here needs jQuery at all.
	 *
	 * Capture phase so it is reached before anything on the form can stop the
	 * event travelling, and preventDefault() rather than returning false, which
	 * would also stop it reaching whatever else is listening.
	 */
	document.addEventListener( 'reset', function ( event ) {
		var form = event.target;

		if ( ! form || ! form.querySelector || ! keepsStep( form ) ) {
			return;
		}

		event.preventDefault();
	}, true );

	/* --- ... and the multi-step half of the same option ------------------- */

	var STEP = {
		wrapper:   '.elementor-field-type-step',
		hidden:    'elementor-hidden',
		indicator: '.e-form__indicators__indicator',
		state:     'e-form__indicators__indicator--state-',
		meter:     '.e-form__indicators__indicator__progress__meter',
		cssVar:    '--e-form-steps-indicator-progress-meter-width',
		inlineErr: '.elementor-form-help-inline'
	};

	// Forms whose step reset has already been unhooked.
	var resetDetached = new WeakSet();

	// Fallback only: the step a form was showing when it was submitted.
	var stepAtSubmit = new WeakMap();
	var watchedForms = new WeakSet();

	function keepsStep( form ) {
		return !! form.querySelector( '.ebs-slot-field[data-ebs-keep-step]' );
	}

	function visibleStep( form ) {
		var steps = form.querySelectorAll( STEP.wrapper );
		var index = -1;

		steps.forEach( function ( step, i ) {
			if ( ! step.classList.contains( STEP.hidden ) ) {
				index = i;
			}
		} );

		return index;
	}

	/**
	 * Unhooks Elementor's own "back to step one" handler for this form.
	 *
	 * Elementor's multi-step handler binds submit -> resetForm(), and resetForm()
	 * does two things: it re-hides the steps AND sets its internal currentStep to
	 * zero. Undoing only the visible half is what the first version of this option
	 * did, and it left Elementor believing it was on step one while the visitor was
	 * looking at step three. The next goToStep() -- which is exactly what a failed
	 * submission triggers -- then hid a step that was already hidden and revealed
	 * another, showing two steps at once.
	 *
	 * The handler instance itself is out of reach: Elementor only keeps handler
	 * instances in edit mode, so its state cannot be corrected from here. Removing
	 * the handler is therefore the only way to keep Elementor's state and its DOM
	 * telling the same story -- and it is the better fix anyway, because a reset
	 * that never happens needs no undoing and cannot flicker.
	 *
	 * Identified by the method name in the bound function's source. Minifiers do
	 * not rename class methods by default, so "resetForm" survives the build; if a
	 * future version defeats that, this returns false and the caller falls back.
	 *
	 * Runs during the capture phase of the submit event, which is before any of the
	 * form's own handlers, so the removal takes effect for this very submission.
	 */
	function detachStepReset( form ) {
		if ( resetDetached.has( form ) ) {
			return true;
		}

		var $ = window.jQuery;

		// jQuery is guaranteed here in a way it is not at load time: Elementor's
		// own form script is mid-submit, so it has certainly loaded.
		if ( ! $ || ! $._data ) {
			return false;
		}

		var events = $._data( form, 'events' );
		var bound = events && events.submit;

		if ( ! bound ) {
			return false;
		}

		var detached = false;

		bound.slice().forEach( function ( entry ) {
			if ( entry.handler && String( entry.handler ).indexOf( 'resetForm' ) !== -1 ) {
				$( form ).off( 'submit', entry.handler );
				detached = true;
			}
		} );

		if ( detached ) {
			resetDetached.add( form );
		}

		return detached;
	}

	function syncIndicators( form, index, total ) {
		form.querySelectorAll( STEP.indicator ).forEach( function ( indicator, i ) {
			indicator.classList.remove( STEP.state + 'inactive', STEP.state + 'active', STEP.state + 'completed' );
			indicator.classList.add( STEP.state + ( i < index ? 'completed' : ( i === index ? 'active' : 'inactive' ) ) );
		} );

		var meter = form.querySelector( STEP.meter );

		if ( meter && total ) {
			var percent = Math.ceil( ( ( index + 1 ) / total ) * 100 ) + '%';
			meter.textContent = percent;

			// Elementor sets the variable on the widget, not the form.
			var widget = form.closest( '.elementor-widget-form' ) || form;
			widget.style.setProperty( STEP.cssVar, percent );
		}
	}

	/**
	 * Fallback for when the reset could not be unhooked: put the step back.
	 */
	function restoreStep( form, index ) {
		var steps = form.querySelectorAll( STEP.wrapper );

		if ( ! steps.length || index < 0 || index >= steps.length ) {
			return;
		}

		steps.forEach( function ( step, i ) {
			step.classList.toggle( STEP.hidden, i !== index );
		} );

		syncIndicators( form, index, steps.length );
	}

	/**
	 * Guarantees a step form is never showing two steps at once.
	 *
	 * Only ever acts when the form is already in a state no step form should be in,
	 * so it cannot interfere with normal navigation. When a submission was rejected
	 * the step holding the inline error wins, which is the one Elementor itself
	 * would choose and the one the visitor has to see to fix anything.
	 */
	function normaliseSteps( form ) {
		var steps = form.querySelectorAll( STEP.wrapper );

		if ( steps.length < 2 ) {
			return;
		}

		var shown = [];

		steps.forEach( function ( step, i ) {
			if ( ! step.classList.contains( STEP.hidden ) ) {
				shown.push( i );
			}
		} );

		if ( shown.length < 2 ) {
			return;
		}

		var target = -1;
		var errored = form.querySelector( STEP.inlineErr );
		var owner = errored && errored.closest ? errored.closest( STEP.wrapper ) : null;

		steps.forEach( function ( step, i ) {
			if ( owner && step === owner ) {
				target = i;
			}
		} );

		if ( target < 0 ) {
			target = shown[ shown.length - 1 ];
		}

		steps.forEach( function ( step, i ) {
			step.classList.toggle( STEP.hidden, i !== target );
		} );

		syncIndicators( form, target, steps.length );
	}

	/**
	 * Watches for Elementor's response landing: the success or error message, or an
	 * inline field error. Used to run the safety net, and to apply the fallback
	 * restore when the reset could not be unhooked.
	 */
	function watchResponse( form ) {
		if ( watchedForms.has( form ) || ! window.MutationObserver ) {
			return;
		}

		watchedForms.add( form );

		new window.MutationObserver( function ( mutations ) {
			var landed = false;

			mutations.forEach( function ( mutation ) {
				mutation.addedNodes.forEach( function ( node ) {
					if ( node.classList && node.classList.contains( 'elementor-message' ) ) {
						landed = true;
					}
				} );
			} );

			if ( ! landed ) {
				return;
			}

			var index = stepAtSubmit.get( form );

			// Fallback path only, and only for a form that actually succeeded: a
			// rejection belongs on Elementor's error step, not back where the
			// visitor was.
			if ( index !== undefined && form.querySelector( '.elementor-message-success' ) ) {
				stepAtSubmit.delete( form );
				restoreStep( form, index );
			}

			normaliseSteps( form );
		} ).observe( form, { childList: true, subtree: true } );
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form || ! form.querySelector || ! keepsStep( form ) ) {
			return;
		}

		watchResponse( form );

		// Preferred: Elementor never resets the steps, so its state stays true and
		// there is nothing to put back.
		if ( detachStepReset( form ) ) {
			stepAtSubmit.delete( form );
			return;
		}

		stepAtSubmit.set( form, visibleStep( form ) );
	}, true );

	// Fallback only. Bubble phase on document, so the form's own handlers have all
	// run and the dispatch is still open -- nothing is drawn in between.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form || ! form.querySelector || ! keepsStep( form ) ) {
			return;
		}

		var index = stepAtSubmit.get( form );

		if ( index !== undefined ) {
			restoreStep( form, index );
		}
	} );

	// A submission rejected server-side (slot just filled up) needs fresh numbers.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.classList || ! form.classList.contains( 'elementor-form' ) ) {
			return;
		}

		setTimeout( function () {
			// A successful send with "do not reset the form" on: nothing about the
			// booking can have changed under the visitor, so leave the field exactly
			// as they left it rather than rebuilding it under them.
			if ( form.querySelector( '.ebs-slot-field[data-ebs-keep-step]' ) &&
				form.querySelector( '.elementor-message-success' ) ) {
				return;
			}

			form.querySelectorAll( '.ebs-slot-field' ).forEach( function ( field ) {
				// A rejected submission does need fresh numbers -- someone may have
				// taken the last seat. With "do not reset the form" on, the chosen
				// date and time are carried across the rebuild, so the visitor gets
				// the new availability without losing what they had picked.
				if ( field.hasAttribute( 'data-ebs-keep-step' ) ) {
					var chosen = field.querySelector( '.ebs-slot-radio:checked' );
					var picked = field.querySelector( '[data-ebs-role="date"]' );

					field.dataset.ebsRestore = JSON.stringify( {
						date: picked ? picked.value : '',
						slot: chosen ? chosen.value : ''
					} );
				}

				field.dataset.ebsReady = '0';
				initField( field );
			} );
		}, 1500 );
	}, true );
}() );
