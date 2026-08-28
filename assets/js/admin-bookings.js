/**
 * Bookings list screen.
 *
 * Only job: confirm before a cancel or delete link is followed. Lives here
 * rather than in an onclick attribute so the admin page ships no inline script.
 */
( function () {
	'use strict';

	var strings = window.ebsBookingsI18n || {};

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '[data-ebs-confirm]' );

		if ( ! link ) {
			return;
		}

		var message = strings[ link.getAttribute( 'data-ebs-confirm' ) ];

		// An unknown action name must not fall through unconfirmed.
		if ( ! window.confirm( message || 'Are you sure?' ) ) {
			event.preventDefault();
		}
	} );
} )();
