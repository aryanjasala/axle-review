/**
 * Axle Review: Dashboard > Notes.
 *
 * Filter axleReview.inboxColumns ( columns ): the column keys to show, from
 * note, author, post, block, age, replies and status. Read once the page has
 * loaded, so scripts that load later can hook in. The note column always stays.
 */
( function ( hooks ) {
	'use strict';

	const ALL = [ 'note', 'author', 'post', 'block', 'age', 'replies', 'status' ];

	function apply() {
		const table = document.querySelector( '.axr-inbox .wp-list-table' );
		if ( ! table || ! hooks ) {
			return;
		}

		const wanted = hooks.applyFilters( 'axleReview.inboxColumns', ALL.slice() );
		const keep = Array.isArray( wanted ) ? wanted.concat( [ 'note' ] ) : ALL;

		ALL.forEach( ( column ) => {
			table.querySelectorAll( '.column-' + column ).forEach( ( cell ) => {
				cell.hidden = ! keep.includes( column );
			} );
		} );
	}

	window.axleReviewInbox = { apply };

	const live = document.querySelector( '.axr-live' );
	if ( live && window.wp && window.wp.a11y ) {
		window.wp.a11y.speak( live.textContent.replace( /\s+/g, ' ' ).trim(), 'assertive' );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', apply );
	} else {
		apply();
	}
} )( window.wp && window.wp.hooks );
