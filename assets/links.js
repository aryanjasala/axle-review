/**
 * Axle Review: the copy button for a new link on Tools > Review Links.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.axr-copy' );
		if ( ! button ) {
			return;
		}

		const field = document.getElementById( button.getAttribute( 'data-target' ) );
		if ( ! field ) {
			return;
		}

		field.select();
		const done = () => {
			button.textContent = button.getAttribute( 'data-copied' ) || button.textContent;
		};
		if ( window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( field.value ).then( done, done );
		} else {
			done();
		}
	} );
} )();
