( function () {
	'use strict';

	var data = window.ebsEditor;
	if ( ! data ) {
		return;
	}

	var i18n = data.i18n || {};
	var root = document.getElementById( 'ebs-editor' );
	var jsonInput = document.getElementById( 'ebs_slots_json' );
	var activeInput = document.getElementById( 'ebs_editor_active' );

	if ( ! root || ! jsonInput || ! activeInput ) {
		return;
	}

	// slots :: { 'YYYY-MM-DD': [ { time, capacity, booked } ] }
	var slots = {};
	var selected = null;
	var viewYear;
	var viewMonth;

	function t( key, fallback ) {
		return i18n[ key ] || fallback || '';
	}

	function pad( n ) {
		return n < 10 ? '0' + n : '' + n;
	}

	function ymd( year, month, day ) {
		return year + '-' + pad( month + 1 ) + '-' + pad( day );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function toMinutes( value ) {
		var m = /^(\d{1,2}):(\d{2})$/.exec( ( value || '' ).trim() );
		if ( ! m ) {
			return null;
		}
		var h = parseInt( m[ 1 ], 10 );
		var mi = parseInt( m[ 2 ], 10 );
		if ( h > 23 || mi > 59 ) {
			return null;
		}
		return h * 60 + mi;
	}

	function fromMinutes( total ) {
		return pad( Math.floor( total / 60 ) ) + ':' + pad( total % 60 );
	}

	function sortDay( date ) {
		if ( ! slots[ date ] ) {
			return;
		}
		slots[ date ].sort( function ( a, b ) {
			return ( toMinutes( a.time ) || 0 ) - ( toMinutes( b.time ) || 0 );
		} );
	}

	function load() {
		( data.slots || [] ).forEach( function ( slot ) {
			if ( ! slots[ slot.date ] ) {
				slots[ slot.date ] = [];
			}
			slots[ slot.date ].push( {
				time: slot.time,
				end: slot.end || '',
				capacity: slot.capacity,
				booked: slot.booked || 0
			} );
		} );

		Object.keys( slots ).forEach( sortDay );
	}

	function serialise() {
		var flat = [];
		Object.keys( slots ).forEach( function ( date ) {
			slots[ date ].forEach( function ( slot ) {
				flat.push( { date: date, time: slot.time, end: slot.end || '', capacity: slot.capacity } );
			} );
		} );
		jsonInput.value = JSON.stringify( flat );
		activeInput.value = '1';
	}

	function defaultCapacity() {
		var field = document.getElementById( 'ebs_default_capacity' );
		var value = field ? parseInt( field.value, 10 ) : NaN;
		return isNaN( value ) ? ( data.defaultCapacity || 25 ) : value;
	}

	function defaultDuration() {
		var field = document.getElementById( 'ebs_default_duration' );
		var value = field ? parseInt( field.value, 10 ) : NaN;
		if ( isNaN( value ) ) {
			value = data.defaultDuration;
		}
		return isNaN( value ) || value < 0 ? 0 : value;
	}

	// A zero length means the slot has no end time; a slot never runs past midnight.
	function endFor( startMinutes, length ) {
		if ( ! length ) {
			return '';
		}
		return fromMinutes( Math.min( startMinutes + length, 23 * 60 + 59 ) );
	}

	/* ---------------------------------------------------------------- calendar */

	function buildCalendar() {
		var wrap = el( 'div', 'ebs-cal' );

		var head = el( 'div', 'ebs-cal-head' );
		var prev = el( 'button', 'button ebs-cal-nav', '‹' );
		prev.type = 'button';
		prev.title = t( 'prevMonth', 'Previous month' );

		var next = el( 'button', 'button ebs-cal-nav', '›' );
		next.type = 'button';
		next.title = t( 'nextMonth', 'Next month' );

		var title = el( 'span', 'ebs-cal-title', data.months[ viewMonth ] + ' ' + viewYear );

		prev.addEventListener( 'click', function () {
			viewMonth--;
			if ( viewMonth < 0 ) {
				viewMonth = 11;
				viewYear--;
			}
			render();
		} );

		next.addEventListener( 'click', function () {
			viewMonth++;
			if ( viewMonth > 11 ) {
				viewMonth = 0;
				viewYear++;
			}
			render();
		} );

		head.appendChild( prev );
		head.appendChild( title );
		head.appendChild( next );
		wrap.appendChild( head );

		var grid = el( 'div', 'ebs-cal-grid' );
		var start = data.startOfWeek || 0;

		for ( var w = 0; w < 7; w++ ) {
			grid.appendChild( el( 'div', 'ebs-cal-dow', data.weekdays[ ( start + w ) % 7 ] ) );
		}

		var first = new Date( viewYear, viewMonth, 1 );
		var offset = ( first.getDay() - start + 7 ) % 7;
		var daysInMonth = new Date( viewYear, viewMonth + 1, 0 ).getDate();

		for ( var blank = 0; blank < offset; blank++ ) {
			grid.appendChild( el( 'div', 'ebs-cal-day ebs-is-empty' ) );
		}

		for ( var day = 1; day <= daysInMonth; day++ ) {
			grid.appendChild( buildDay( day ) );
		}

		wrap.appendChild( grid );
		return wrap;
	}

	function buildDay( day ) {
		var date = ymd( viewYear, viewMonth, day );
		var dayslots = slots[ date ] || [];

		var cell = el( 'button', 'ebs-cal-day' );
		cell.type = 'button';
		cell.appendChild( el( 'span', 'ebs-cal-num', day ) );

		if ( dayslots.length ) {
			cell.classList.add( 'ebs-has-slots' );
			cell.appendChild( el( 'span', 'ebs-cal-badge', dayslots.length ) );
		}

		if ( date === data.today ) {
			cell.classList.add( 'ebs-is-today' );
		}

		if ( date === selected ) {
			cell.classList.add( 'ebs-is-selected' );
		}

		if ( date < data.today ) {
			cell.classList.add( 'ebs-is-past' );
		}

		cell.addEventListener( 'click', function () {
			selected = date;
			render();
		} );

		return cell;
	}

	/* ------------------------------------------------------------- day editor */

	function buildEditor() {
		var panel = el( 'div', 'ebs-day' );

		if ( ! selected ) {
			panel.appendChild( el( 'p', 'ebs-day-empty', t( 'pickADay', 'Pick a day in the calendar to add time slots.' ) ) );
			return panel;
		}

		var heading = t( 'slotsFor', 'Slots for %s' ).replace( '%s', formatDate( selected ) );
		panel.appendChild( el( 'h3', 'ebs-day-title', heading ) );

		var list = slots[ selected ] || [];

		if ( ! list.length ) {
			panel.appendChild( el( 'p', 'ebs-day-empty', t( 'noSlots', 'No slots on this day yet.' ) ) );
		} else {
			panel.appendChild( buildSlotTable( list ) );
		}

		panel.appendChild( buildDayActions( list ) );
		return panel;
	}

	function buildSlotTable( list ) {
		var table = el( 'table', 'widefat striped ebs-day-table' );
		var thead = el( 'thead' );
		var hrow = el( 'tr' );

		[ t( 'time', 'Start' ), t( 'endTime', 'End' ), t( 'capacity', 'Capacity' ), t( 'booked', 'Booked' ), '' ].forEach( function ( label ) {
			hrow.appendChild( el( 'th', null, label ) );
		} );

		thead.appendChild( hrow );
		table.appendChild( thead );

		var tbody = el( 'tbody' );

		list.forEach( function ( slot, index ) {
			tbody.appendChild( buildSlotRow( slot, index ) );
		} );

		table.appendChild( tbody );
		return table;
	}

	function buildSlotRow( slot, index ) {
		var row = el( 'tr' );

		var timeCell = el( 'td' );
		var timeInput = document.createElement( 'input' );
		timeInput.type = 'time';
		timeInput.value = slot.time;
		timeInput.className = 'ebs-time-input';
		timeInput.addEventListener( 'change', function () {
			var next = ( timeInput.value || '' ).slice( 0, 5 );
			if ( toMinutes( next ) === null ) {
				timeInput.value = slot.time;
				return;
			}

			var clash = ( slots[ selected ] || [] ).some( function ( other, i ) {
				return i !== index && other.time === next;
			} );

			if ( clash ) {
				window.alert( t( 'duplicateTime', 'There is already a slot at that time.' ) );
				timeInput.value = slot.time;
				return;
			}

			// Shift the end time along so the slot keeps its length.
			if ( slot.end ) {
				var length = toMinutes( slot.end ) - toMinutes( slot.time );
				slot.end = endFor( toMinutes( next ), length > 0 ? length : defaultDuration() );
			}

			slot.time = next;
			sortDay( selected );
			render();
		} );
		timeCell.appendChild( timeInput );
		row.appendChild( timeCell );

		var endCell = el( 'td' );
		var endInput = document.createElement( 'input' );
		endInput.type = 'time';
		endInput.value = slot.end || '';
		endInput.className = 'ebs-time-input';
		endInput.addEventListener( 'change', function () {
			var next = ( endInput.value || '' ).slice( 0, 5 );

			if ( next === '' ) {
				slot.end = '';
				serialise();
				flagUnsaved();
				return;
			}

			if ( toMinutes( next ) === null || toMinutes( next ) <= toMinutes( slot.time ) ) {
				window.alert( t( 'endBeforeStart', 'The end time must be after the start time.' ) );
				endInput.value = slot.end || '';
				return;
			}

			slot.end = next;
			serialise();
			flagUnsaved();
		} );
		endCell.appendChild( endInput );
		row.appendChild( endCell );

		var capCell = el( 'td' );
		var capInput = document.createElement( 'input' );
		capInput.type = 'number';
		capInput.min = '0';
		capInput.step = '1';
		capInput.value = slot.capacity;
		capInput.className = 'small-text';
		capInput.addEventListener( 'change', function () {
			var value = parseInt( capInput.value, 10 );
			slot.capacity = isNaN( value ) || value < 0 ? 0 : value;
			capInput.value = slot.capacity;
			serialise();
			flagUnsaved();
		} );
		capCell.appendChild( capInput );

		if ( slot.capacity === 0 ) {
			capCell.appendChild( el( 'span', 'ebs-hint', ' ' + t( 'unlimited', 'Unlimited' ) ) );
		}

		row.appendChild( capCell );

		var bookedCell = el( 'td' );
		bookedCell.appendChild( el( 'span', null, String( slot.booked || 0 ) ) );
		if ( slot.capacity > 0 && slot.booked >= slot.capacity ) {
			bookedCell.appendChild( el( 'span', 'ebs-pill ebs-pill-full', t( 'full', 'Full' ) ) );
		}
		row.appendChild( bookedCell );

		var actionCell = el( 'td' );
		var remove = el( 'button', 'button-link ebs-remove', t( 'remove', 'Remove' ) );
		remove.type = 'button';
		remove.addEventListener( 'click', function () {
			// A slot with bookings is closed rather than deleted server-side, so
			// warn before it disappears from the list.
			if ( slot.booked > 0 && ! window.confirm( t( 'hasBookings', 'This slot already has bookings. Continue?' ) ) ) {
				return;
			}
			slots[ selected ].splice( index, 1 );
			if ( ! slots[ selected ].length ) {
				delete slots[ selected ];
			}
			render();
			flagUnsaved();
		} );
		actionCell.appendChild( remove );
		row.appendChild( actionCell );

		return row;
	}

	function buildDayActions( list ) {
		var actions = el( 'div', 'ebs-day-actions' );

		/* add one slot */
		var addRow = el( 'div', 'ebs-action-row' );
		var addTime = document.createElement( 'input' );
		addTime.type = 'time';
		addTime.className = 'ebs-time-input';
		addTime.value = suggestNextTime( list );

		var addEnd = document.createElement( 'input' );
		addEnd.type = 'time';
		addEnd.className = 'ebs-time-input';
		addEnd.value = endFor( toMinutes( addTime.value ) || 0, defaultDuration() );

		// Typing a start time moves the end along by the default length.
		addTime.addEventListener( 'change', function () {
			var start = toMinutes( ( addTime.value || '' ).slice( 0, 5 ) );
			if ( start !== null ) {
				addEnd.value = endFor( start, defaultDuration() );
			}
		} );

		var addCap = document.createElement( 'input' );
		addCap.type = 'number';
		addCap.min = '0';
		addCap.className = 'small-text';
		addCap.value = defaultCapacity();

		var addBtn = el( 'button', 'button button-primary', t( 'addSlot', 'Add a slot' ) );
		addBtn.type = 'button';
		addBtn.addEventListener( 'click', function () {
			addSlot(
				( addTime.value || '' ).slice( 0, 5 ),
				( addEnd.value || '' ).slice( 0, 5 ),
				parseInt( addCap.value, 10 )
			);
		} );

		addRow.appendChild( labelled( t( 'time', 'Start' ), addTime ) );
		addRow.appendChild( labelled( t( 'endTime', 'End' ), addEnd ) );
		addRow.appendChild( labelled( t( 'capacity', 'Capacity' ), addCap ) );
		addRow.appendChild( addBtn );
		actions.appendChild( addRow );

		/* generate a range */
		var bulk = el( 'div', 'ebs-action-row ebs-action-bulk' );
		bulk.appendChild( el( 'strong', 'ebs-action-heading', t( 'bulkAdd', 'Add a range of slots' ) ) );

		var from = document.createElement( 'input' );
		from.type = 'time';
		from.className = 'ebs-time-input';
		from.value = '09:00';

		var to = document.createElement( 'input' );
		to.type = 'time';
		to.className = 'ebs-time-input';
		to.value = '17:00';

		var every = document.createElement( 'input' );
		every.type = 'number';
		every.min = '5';
		every.step = '5';
		every.value = '30';
		every.className = 'small-text';

		var bulkCap = document.createElement( 'input' );
		bulkCap.type = 'number';
		bulkCap.min = '0';
		bulkCap.className = 'small-text';
		bulkCap.value = defaultCapacity();

		var genBtn = el( 'button', 'button', t( 'generate', 'Generate' ) );
		genBtn.type = 'button';
		genBtn.addEventListener( 'click', function () {
			generate( from.value, to.value, parseInt( every.value, 10 ), parseInt( bulkCap.value, 10 ) );
		} );

		var bulkFields = el( 'div', 'ebs-action-row' );
		bulkFields.appendChild( labelled( t( 'from', 'From' ), from ) );
		bulkFields.appendChild( labelled( t( 'to', 'To' ), to ) );
		bulkFields.appendChild( labelled( t( 'every', 'Every' ), every, t( 'minutes', 'minutes' ) ) );
		bulkFields.appendChild( labelled( t( 'capacity', 'Capacity' ), bulkCap ) );
		bulkFields.appendChild( genBtn );
		bulk.appendChild( bulkFields );
		actions.appendChild( bulk );

		if ( ! list.length ) {
			return actions;
		}

		/* capacity in bulk */
		var capRow = el( 'div', 'ebs-action-row ebs-action-bulk' );
		capRow.appendChild( el( 'strong', 'ebs-action-heading', t( 'defaultCapacity', 'Set capacity' ) ) );

		var allCap = document.createElement( 'input' );
		allCap.type = 'number';
		allCap.min = '0';
		allCap.className = 'small-text';
		allCap.value = defaultCapacity();

		var applyDay = el( 'button', 'button', t( 'applyAllDay', 'Apply to every slot on this day' ) );
		applyDay.type = 'button';
		applyDay.addEventListener( 'click', function () {
			applyCapacity( parseInt( allCap.value, 10 ), false );
		} );

		var applyAll = el( 'button', 'button', t( 'applyAllDays', 'Apply to every slot on every day' ) );
		applyAll.type = 'button';
		applyAll.addEventListener( 'click', function () {
			applyCapacity( parseInt( allCap.value, 10 ), true );
		} );

		var capFields = el( 'div', 'ebs-action-row' );
		capFields.appendChild( allCap );
		capFields.appendChild( applyDay );
		capFields.appendChild( applyAll );
		capRow.appendChild( capFields );
		actions.appendChild( capRow );

		/* copy + clear */
		var tail = el( 'div', 'ebs-action-row ebs-action-tail' );

		var copyDate = document.createElement( 'input' );
		copyDate.type = 'date';

		var copyBtn = el( 'button', 'button', t( 'copy', 'Copy' ) );
		copyBtn.type = 'button';
		copyBtn.addEventListener( 'click', function () {
			copyDay( copyDate.value );
		} );

		tail.appendChild( labelled( t( 'copyTo', 'Copy this day to…' ), copyDate ) );
		tail.appendChild( copyBtn );

		var clear = el( 'button', 'button-link-delete ebs-clear', t( 'clearDay', 'Remove all slots on this day' ) );
		clear.type = 'button';
		clear.addEventListener( 'click', function () {
			if ( ! window.confirm( t( 'clearConfirm', 'Remove every slot on this day?' ) ) ) {
				return;
			}
			delete slots[ selected ];
			render();
			flagUnsaved();
		} );

		tail.appendChild( clear );
		actions.appendChild( tail );

		return actions;
	}

	function labelled( labelText, input, suffix ) {
		var wrap = el( 'label', 'ebs-labelled' );
		wrap.appendChild( el( 'span', 'ebs-labelled-text', labelText ) );

		var field = el( 'span', 'ebs-labelled-field' );
		field.appendChild( input );

		if ( suffix ) {
			field.appendChild( el( 'span', 'ebs-labelled-suffix', suffix ) );
		}

		wrap.appendChild( field );
		return wrap;
	}

	/* ---------------------------------------------------------------- actions */

	function addSlot( time, end, capacity ) {
		if ( ! selected || toMinutes( time ) === null ) {
			return;
		}

		if ( end && ( toMinutes( end ) === null || toMinutes( end ) <= toMinutes( time ) ) ) {
			window.alert( t( 'endBeforeStart', 'The end time must be after the start time.' ) );
			return;
		}

		if ( ! slots[ selected ] ) {
			slots[ selected ] = [];
		}

		if ( slots[ selected ].some( function ( s ) { return s.time === time; } ) ) {
			window.alert( t( 'duplicateTime', 'There is already a slot at that time.' ) );
			return;
		}

		slots[ selected ].push( {
			time: time,
			end: end || '',
			capacity: isNaN( capacity ) || capacity < 0 ? defaultCapacity() : capacity,
			booked: 0
		} );

		sortDay( selected );
		render();
		flagUnsaved();
	}

	function generate( from, to, step, capacity ) {
		var start = toMinutes( ( from || '' ).slice( 0, 5 ) );
		var end = toMinutes( ( to || '' ).slice( 0, 5 ) );

		if ( start === null || end === null || isNaN( step ) || step < 1 || end <= start ) {
			window.alert( t( 'badRange', 'Check the start time, end time and interval.' ) );
			return;
		}

		if ( ! slots[ selected ] ) {
			slots[ selected ] = [];
		}

		var cap = isNaN( capacity ) || capacity < 0 ? defaultCapacity() : capacity;
		var added = 0;

		// The end time closes the last slot rather than starting one.
		for ( var m = start; m + step <= end; m += step ) {
			var time = fromMinutes( m );
			var exists = slots[ selected ].some( function ( s ) { return s.time === time; } );
			if ( exists ) {
				continue;
			}
			slots[ selected ].push( { time: time, end: fromMinutes( m + step ), capacity: cap, booked: 0 } );
			added++;
		}

		if ( ! added ) {
			window.alert( t( 'badRange', 'Check the start time, end time and interval.' ) );
			return;
		}

		sortDay( selected );
		render();
		flagUnsaved();
	}

	function applyCapacity( capacity, everyDay ) {
		if ( isNaN( capacity ) || capacity < 0 ) {
			return;
		}

		var targets = everyDay ? Object.keys( slots ) : [ selected ];

		targets.forEach( function ( date ) {
			( slots[ date ] || [] ).forEach( function ( slot ) {
				slot.capacity = capacity;
			} );
		} );

		render();
		flagUnsaved();
	}

	function copyDay( target ) {
		if ( ! target || ! selected || target === selected || ! slots[ selected ] ) {
			return;
		}

		if ( ! slots[ target ] ) {
			slots[ target ] = [];
		}

		slots[ selected ].forEach( function ( slot ) {
			if ( slots[ target ].some( function ( s ) { return s.time === slot.time; } ) ) {
				return;
			}
			// booked starts at zero: this is a new slot, not the same one.
			slots[ target ].push( { time: slot.time, end: slot.end || '', capacity: slot.capacity, booked: 0 } );
		} );

		sortDay( target );
		selected = target;

		var parts = target.split( '-' );
		viewYear = parseInt( parts[ 0 ], 10 );
		viewMonth = parseInt( parts[ 1 ], 10 ) - 1;

		render();
		flagUnsaved();
	}

	// Start the next slot where the previous one finished.
	function suggestNextTime( list ) {
		if ( ! list.length ) {
			return '09:00';
		}

		var last = list[ list.length - 1 ];
		var after = last.end ? toMinutes( last.end ) : toMinutes( last.time ) + ( defaultDuration() || 30 );

		return fromMinutes( Math.min( after || 0, 23 * 60 + 59 ) );
	}

	function formatDate( date ) {
		var parts = date.split( '-' );
		return parseInt( parts[ 2 ], 10 ) + ' ' + data.months[ parseInt( parts[ 1 ], 10 ) - 1 ] + ' ' + parts[ 0 ];
	}

	function flagUnsaved() {
		var note = root.querySelector( '.ebs-unsaved' );
		if ( note ) {
			note.classList.add( 'ebs-is-visible' );
		}
	}

	/* ----------------------------------------------------------------- render */

	function render() {
		root.innerHTML = '';

		var layout = el( 'div', 'ebs-editor-layout' );
		layout.appendChild( buildCalendar() );
		layout.appendChild( buildEditor() );
		root.appendChild( layout );

		var note = el( 'p', 'ebs-unsaved', t( 'saveReminder', 'Remember to update the event to save your changes.' ) );
		root.appendChild( note );

		serialise();
	}

	function init() {
		load();

		var dates = Object.keys( slots ).sort();
		var startDate = dates.length ? dates[ 0 ] : data.today;
		var parts = startDate.split( '-' );

		viewYear = parseInt( parts[ 0 ], 10 );
		viewMonth = parseInt( parts[ 1 ], 10 ) - 1;
		selected = dates.length ? dates[ 0 ] : null;

		render();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
