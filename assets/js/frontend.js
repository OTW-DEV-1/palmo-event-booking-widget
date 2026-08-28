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
	 * Elementor clears the form with jQuery's $form.trigger( 'reset' ). That runs
	 * the jQuery handlers and only then calls the element's own reset() -- and it
	 * skips that call if a handler prevented the default. So preventing it here is
	 * enough, and it is the only thing that is: trigger() never dispatches a native
	 * reset event, so addEventListener( 'reset', ... ) is never called at all and
	 * cannot stop anything. jQuery is a hard dependency of Elementor Pro's own form
	 * script, so it is always present wherever this field can appear.
	 *
	 * preventDefault() rather than returning false, which would also stop the event
	 * reaching anything else listening further up.
	 */
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'reset', 'form.elementor-form', function ( event ) {
			if ( this.querySelector( '.ebs-slot-field[data-ebs-keep-step]' ) ) {
				event.preventDefault();
			}
		} );
	}

	/* --- ... and the multi-step half of the same option ------------------- */

	var STEP = {
		wrapper:   '.elementor-field-type-step',
		hidden:    'elementor-hidden',
		indicator: '.e-form__indicators__indicator',
		state:     'e-form__indicators__indicator--state-',
		meter:     '.e-form__indicators__indicator__progress__meter',
		cssVar:    '--e-form-steps-indicator-progress-meter-width'
	};

	// Which step each form was showing when it was submitted.
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
	 * Puts the form back on the step it was showing, and brings the indicators and
	 * the progress bar along so the header does not contradict the body.
	 *
	 * Every lookup is guarded: if a future Elementor release renames any of this,
	 * the option quietly stops working rather than throwing on every submission.
	 */
	function restoreStep( form, index ) {
		var steps = form.querySelectorAll( STEP.wrapper );

		if ( ! steps.length || index < 0 || index >= steps.length ) {
			return;
		}

		steps.forEach( function ( step, i ) {
			step.classList.toggle( STEP.hidden, i !== index );
		} );

		var indicators = form.querySelectorAll( STEP.indicator );

		indicators.forEach( function ( indicator, i ) {
			indicator.classList.remove( STEP.state + 'inactive', STEP.state + 'active', STEP.state + 'completed' );
			indicator.classList.add( STEP.state + ( i < index ? 'completed' : ( i === index ? 'active' : 'inactive' ) ) );
		} );

		var meter = form.querySelector( STEP.meter );

		if ( meter ) {
			var percent = Math.ceil( ( ( index + 1 ) / steps.length ) * 100 ) + '%';
			meter.textContent = percent;

			// Elementor sets the variable on the widget, not the form.
			var widget = form.closest( '.elementor-widget-form' ) || form;
			widget.style.setProperty( STEP.cssVar, percent );
		}
	}

	/**
	 * Elementor resets a multi-step form to step one on submit, then appends the
	 * success message at the bottom. On a long form that leaves the visitor looking
	 * at step one with no sign anything happened.
	 *
	 * The reset cannot be prevented from outside Elementor, so it is undone -- and
	 * the timing is the whole point. Waiting for the response would let the browser
	 * paint step one first, which is the visible jump to the top and back. Instead
	 * the step is noted in the capture phase and put back in the bubble phase of
	 * the very same submit event, after Elementor's handlers on the form have run
	 * but before the dispatch is over. No paint can happen inside a dispatch, so
	 * step one is never shown.
	 *
	 * Only on success. A failed submission is left alone, because Elementor moves
	 * to whichever step holds the invalid field and that is where the visitor needs
	 * to be -- including when our own duplicate check is what rejected it. That
	 * move happens when the response lands, well after this, so it wins.
	 */
	function watchForSuccess( form ) {
		if ( watchedForms.has( form ) || ! window.MutationObserver ) {
			return;
		}

		watchedForms.add( form );

		new window.MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				mutation.addedNodes.forEach( function ( node ) {
					if ( ! node.classList || ! node.classList.contains( 'elementor-message-success' ) ) {
						return;
					}

					var index = stepAtSubmit.get( form );

					if ( index === undefined ) {
						return;
					}

					stepAtSubmit.delete( form );

					// Backstop. The bubble-phase handler below has almost certainly
					// put the step back already, in which case this changes nothing;
					// it only earns its keep if a future Elementor moves its own
					// reset later than the submit event.
					restoreStep( form, index );
				} );
			} );
		} ).observe( form, { childList: true } );
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form || ! form.querySelector || ! keepsStep( form ) ) {
			return;
		}

		stepAtSubmit.set( form, visibleStep( form ) );
		watchForSuccess( form );
	}, true );

	// Bubble phase, on document rather than the form: the form's own listeners --
	// Elementor's step reset among them -- have all run by the time an ancestor
	// sees the event, and the dispatch is still in progress, so the step goes back
	// before anything is drawn.
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
			// "Do not reset the form" means exactly that: rebuilding the slot list
			// here would throw away the chosen time and re-hide the fields the
			// choice revealed, undoing the option a moment after it worked. Only
			// skipped once the send actually succeeded -- a rejected submission
			// still needs fresh numbers, which is what this refresh is for.
			if ( form.querySelector( '.ebs-slot-field[data-ebs-keep-step]' ) &&
				form.querySelector( '.elementor-message-success' ) ) {
				return;
			}

			form.querySelectorAll( '.ebs-slot-field' ).forEach( function ( field ) {
				field.dataset.ebsReady = '0';
				initField( field );
			} );
		}, 1500 );
	}, true );
}() );
