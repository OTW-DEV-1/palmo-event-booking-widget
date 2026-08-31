/**
 * Bookings list screen.
 *
 * Cancelling and deleting go through the REST route so the page is not redrawn
 * for every single row, and the same route takes a whole selection at once.
 *
 * Everything here is an enhancement: the row links and the bulk form both work
 * on their own if this script never runs, which is why the links keep their real
 * href and the form keeps its real action.
 */
( function () {
	'use strict';

	var config = window.ebsBookings || {};
	var text = config.i18n || {};

	function t( key, fallback ) {
		return text[ key ] || fallback;
	}

	var table = document.querySelector( '.ebs-bookings-table' );
	var form = document.querySelector( '.ebs-bookings-form' );

	if ( ! table || ! form || ! config.endpoint || ! window.fetch ) {
		// Without any one of these the plain form is still there and still works,
		// so the safest thing this script can do is nothing at all.
		return;
	}

	var notice = document.querySelector( '.ebs-bulk-notice' );
	var counter = document.querySelector( '.ebs-bulk-count' );
	var selectAll = document.getElementById( 'ebs-select-all' );
	var busy = false;

	function say( message, isError ) {
		if ( ! notice ) {
			return;
		}

		notice.querySelector( 'p' ).textContent = message;
		notice.classList.toggle( 'notice-error', !! isError );
		notice.classList.toggle( 'notice-success', ! isError );
		notice.hidden = false;
	}

	function boxes() {
		return Array.prototype.slice.call( table.querySelectorAll( '.ebs-cb' ) );
	}

	function checked() {
		return boxes().filter( function ( box ) {
			return box.checked;
		} );
	}

	function refreshCount() {
		var count = checked().length;

		if ( counter ) {
			counter.textContent = count ? t( 'selected', '%s selected' ).replace( '%s', count ) : '';
		}

		if ( selectAll ) {
			var all = boxes();
			selectAll.checked = all.length > 0 && count === all.length;

			// Neither all nor none: show the third state rather than lying.
			selectAll.indeterminate = count > 0 && count < all.length;
		}
	}

	function row( id ) {
		return table.querySelector( '[data-ebs-booking="' + id + '"]' );
	}

	/**
	 * Reflects a completed action on the rows it applied to, so the table matches
	 * the database without being fetched again.
	 */
	function applyResult( action, ids ) {
		ids.forEach( function ( id ) {
			var tr = row( id );

			if ( ! tr ) {
				return;
			}

			if ( 'delete' === action ) {
				tr.parentNode.removeChild( tr );
				return;
			}

			var status = tr.querySelector( '[data-ebs-cell="status"]' );

			if ( status ) {
				status.innerHTML = '';
				var pill = document.createElement( 'span' );
				pill.className = 'ebs-pill ebs-pill-closed';
				pill.textContent = t( 'cancelledPill', 'Cancelled' );
				status.appendChild( pill );
			}

			// A cancelled booking cannot be cancelled again; deleting it still can.
			var cancelLink = tr.querySelector( '[data-ebs-action="cancel"]' );

			if ( cancelLink ) {
				cancelLink.parentNode.removeChild( cancelLink );
			}

			var box = tr.querySelector( '.ebs-cb' );

			if ( box ) {
				box.checked = false;
			}
		} );

		if ( ! table.querySelector( '[data-ebs-booking]' ) ) {
			var body = table.querySelector( 'tbody' );
			var empty = document.createElement( 'tr' );
			var cell = document.createElement( 'td' );

			cell.colSpan = 9;
			cell.textContent = t( 'noBookings', 'No bookings found.' );
			empty.className = 'ebs-no-bookings';
			empty.appendChild( cell );
			body.appendChild( empty );
		}

		refreshCount();
	}

	function send( action, ids ) {
		if ( busy ) {
			return;
		}

		busy = true;
		say( t( 'working', 'Working…' ), false );
		form.classList.add( 'ebs-is-busy' );

		fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				// Cookie authentication for the REST API needs this; without it
				// WordPress treats the request as coming from a logged-out visitor.
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify( { bulk_action: action, ids: ids } )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( ( data && data.message ) || 'HTTP ' + response.status );
					}
					return data;
				} );
			} )
			.then( function ( data ) {
				// Only the ids the server confirmed. A row it refused stays as it
				// is, rather than the screen claiming something that did not happen.
				applyResult( data.action, data.done || [] );
				say( data.message || '', false );
			} )
			.catch( function ( error ) {
				say( error.message || t( 'failed', 'That did not work.' ), true );
			} )
			.then( function () {
				busy = false;
				form.classList.remove( 'ebs-is-busy' );
			} );
	}

	// --- single row actions ---------------------------------------------------

	table.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '[data-ebs-action]' );

		if ( ! link ) {
			return;
		}

		var action = link.getAttribute( 'data-ebs-action' );
		var id = link.getAttribute( 'data-ebs-id' );

		if ( ! id ) {
			return;
		}

		event.preventDefault();

		if ( ! window.confirm( t( action, 'Are you sure?' ) ) ) {
			return;
		}

		send( action, [ parseInt( id, 10 ) ] );
	} );

	// --- selection ------------------------------------------------------------

	if ( selectAll ) {
		selectAll.addEventListener( 'change', function () {
			boxes().forEach( function ( box ) {
				box.checked = selectAll.checked;
			} );
			refreshCount();
		} );
	}

	table.addEventListener( 'change', function ( event ) {
		if ( event.target.classList.contains( 'ebs-cb' ) ) {
			refreshCount();
		}
	} );

	// Shift-click to take a range, as the rest of the WordPress admin does.
	var lastTouched = null;

	table.addEventListener( 'click', function ( event ) {
		var box = event.target;

		if ( ! box.classList || ! box.classList.contains( 'ebs-cb' ) ) {
			return;
		}

		var all = boxes();

		if ( event.shiftKey && lastTouched !== null ) {
			var from = all.indexOf( box );
			var to = all.indexOf( lastTouched );

			if ( from > -1 && to > -1 ) {
				all.slice( Math.min( from, to ), Math.max( from, to ) + 1 ).forEach( function ( each ) {
					each.checked = box.checked;
				} );
			}
		}

		lastTouched = box;
		refreshCount();
	} );

	// --- bulk apply -----------------------------------------------------------

	form.addEventListener( 'submit', function ( event ) {
		var action = form.querySelector( '#ebs-bulk-action' ).value;
		var ids = checked().map( function ( box ) {
			return parseInt( box.value, 10 );
		} );

		// Let the plain form through when there is nothing to intercept, so the
		// visitor gets the same complaint either way.
		if ( ! action ) {
			event.preventDefault();
			say( t( 'chooseAction', 'Choose an action first.' ), true );
			return;
		}

		if ( ! ids.length ) {
			event.preventDefault();
			say( t( 'chooseRows', 'Select at least one booking first.' ), true );
			return;
		}

		event.preventDefault();

		var question = 'delete' === action
			? t( 'bulkDelete', 'Delete %s bookings permanently?' )
			: t( 'bulkCancel', 'Cancel %s bookings?' );

		if ( ! window.confirm( question.replace( '%s', ids.length ) ) ) {
			return;
		}

		send( action, ids );
	} );

	refreshCount();
}() );
