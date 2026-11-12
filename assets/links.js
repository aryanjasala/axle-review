/**
 * Axle Review: Tools > Review Links and the network page.
 *
 *  - The copy button for a new link.
 *  - The in-place confirm step before anything that cannot be undone, in place of a browser dialog.
 *    A trigger carries data-axr-confirm (what will change, and whether it can be undone) and
 *    data-axr-confirm-label (the button that goes ahead). A bulk form carries
 *    data-axr-bulk-action (the action that needs a confirm) and data-axr-bulk-confirm
 *    with %d for the number of rows.
 *  - Results are announced to screen readers.
 */
( function () {
	'use strict';

	const a11y = window.wp && window.wp.a11y;
	const speak = ( text ) => {
		if ( a11y && a11y.speak && text ) {
			a11y.speak( text, 'assertive' );
		}
	};

	const live = document.querySelector( '.axr-live' );
	if ( live ) {
		speak( live.textContent.replace( /\s+/g, ' ' ).trim() );
	}

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
			speak( button.getAttribute( 'data-copied' ) );
		};
		if ( window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( field.value ).then( done, done );
		} else {
			done();
		}
	} );

	/* Confirm */

	let current = null;

	function close( restore ) {
		if ( ! current ) {
			return;
		}
		current.box.remove();
		if ( restore && current.trigger && document.contains( current.trigger ) ) {
			current.trigger.focus();
		}
		current = null;
	}

	function make( tag, attributes, text ) {
		const node = document.createElement( tag );
		Object.keys( attributes || {} ).forEach( ( key ) => node.setAttribute( key, attributes[ key ] ) );
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function ask( trigger, message, label, proceed ) {
		close( false );
		const id = 'axr-confirm-' + Date.now();
		const text = make( 'p', { id, tabindex: '-1' }, message );
		const yes = make( 'button', { type: 'button', class: 'button axle-button--danger' }, label );
		const no = make( 'button', { type: 'button', class: 'button' }, ( window.axleReviewAdmin && window.axleReviewAdmin.cancel ) || 'Cancel' );
		const actions = make( 'p', { class: 'axle-actions' } );
		actions.appendChild( yes );
		actions.appendChild( document.createTextNode( ' ' ) );
		actions.appendChild( no );
		const box = make( 'div', { class: 'axle-confirm axle-confirm--danger', role: 'group', 'aria-labelledby': id } );
		box.appendChild( text );
		box.appendChild( actions );

		const rowActions = trigger.closest( '.row-actions' );
		const after = rowActions || trigger.closest( '.axr-confirm-after' ) || trigger;
		after.insertAdjacentElement( 'afterend', box );

		current = { box, trigger };
		yes.addEventListener( 'click', () => {
			close( false );
			proceed();
		} );
		no.addEventListener( 'click', () => close( true ) );
		box.addEventListener( 'keydown', ( event ) => {
			if ( 'Escape' === event.key ) {
				close( true );
			}
		} );
		text.focus();
		speak( message );
	}

	document.addEventListener(
		'click',
		( event ) => {
			const trigger = event.target.closest( '[data-axr-confirm]' );
			if ( ! trigger || trigger.hasAttribute( 'data-axr-confirmed' ) ) {
				return;
			}
			event.preventDefault();
			const label = trigger.getAttribute( 'data-axr-confirm-label' ) || trigger.textContent.trim();
			ask( trigger, trigger.getAttribute( 'data-axr-confirm' ), label, () => {
				if ( 'A' === trigger.tagName ) {
					window.location.href = trigger.href;
					return;
				}
				trigger.setAttribute( 'data-axr-confirmed', '1' );
				if ( trigger.form && trigger.form.requestSubmit ) {
					trigger.form.requestSubmit( trigger );
				} else {
					trigger.click();
				}
			} );
		},
		true
	);

	document.addEventListener( 'submit', ( event ) => {
		const form = event.target;
		const action = form.getAttribute && form.getAttribute( 'data-axr-bulk-action' );
		if ( ! action || form.hasAttribute( 'data-axr-confirmed' ) ) {
			return;
		}
		const top = form.elements.action;
		const bottom = form.elements.action2;
		const chosen = top && '-1' !== top.value ? top.value : bottom ? bottom.value : '';
		if ( chosen !== action ) {
			return;
		}
		const count = form.querySelectorAll( 'tbody input[type="checkbox"]:checked' ).length;
		if ( ! count ) {
			return;
		}
		event.preventDefault();
		const submitter = event.submitter || form.querySelector( '#doaction' );
		const message = ( form.getAttribute( 'data-axr-bulk-confirm' ) || '' ).replace( '%d', String( count ) );
		ask( submitter, message, form.getAttribute( 'data-axr-bulk-label' ) || chosen, () => {
			form.setAttribute( 'data-axr-confirmed', '1' );
			form.requestSubmit( submitter );
		} );
	} );
} )();
