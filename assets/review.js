/**
 * Axle Review: the reviewer's toolbar and note popover on a review page.
 *
 * Plain JavaScript, no framework: it runs on the theme's front end. Filters
 * (wp.hooks, namespace axleReview) are read when they are used, so scripts
 * that load later can still hook in:
 *  - axleReview.blockSelector ( selector ): which elements count as a block in note mode.
 *  - axleReview.popoverFields ( fields ): the popover fields, from name, email and note.
 * Action: axleReview.noteSubmitted ( note, element ) after a note was saved.
 */
( function ( config, hooks ) {
	'use strict';

	if ( ! config || window.axleReviewLoaded ) {
		return;
	}
	window.axleReviewLoaded = true;

	const S = config.strings;
	const NAME_KEY = 'axr-name-' + config.link;
	let noteMode = false;
	let popover = null;
	let panel = null;
	let lastFocus = null;
	let selectionText = '';

	const applyFilters = ( name, value, ...args ) => ( hooks ? hooks.applyFilters( name, value, ...args ) : value );
	const doAction = ( name, ...args ) => hooks && hooks.doAction( name, ...args );

	function storedName() {
		try {
			return window.sessionStorage.getItem( NAME_KEY ) || '';
		} catch ( error ) {
			return '';
		}
	}

	function storeName( name ) {
		try {
			window.sessionStorage.setItem( NAME_KEY, name );
		} catch ( error ) {
			// Private mode: the name is asked again next time.
		}
	}

	/**
	 * The selector for blocks in note mode. Always limited to blocks the review
	 * render marked, so a filter can narrow it but not reach outside the post.
	 *
	 * @return {string} CSS selector.
	 */
	function blockSelector() {
		const selector = applyFilters( 'axleReview.blockSelector', '[data-axr-block]' );
		return 'string' === typeof selector && selector.trim() ? selector : '[data-axr-block]';
	}

	function blocks() {
		let found = [];
		try {
			found = Array.from( document.querySelectorAll( blockSelector() ) );
		} catch ( error ) {
			found = Array.from( document.querySelectorAll( '[data-axr-block]' ) );
		}
		return found.filter( ( element ) => element.hasAttribute( 'data-axr-block' ) );
	}

	function el( tag, attributes = {}, children = [] ) {
		const node = document.createElement( tag );
		Object.entries( attributes ).forEach( ( [ key, value ] ) => {
			if ( 'text' === key ) {
				node.textContent = value;
			} else if ( null !== value && undefined !== value && false !== value ) {
				node.setAttribute( key, true === value ? '' : value );
			}
		} );
		children.filter( Boolean ).forEach( ( child ) => node.appendChild( child ) );
		return node;
	}

	function api( method, path, body ) {
		const url = config.root + path + ( 'GET' === method ? ( path.includes( '?' ) ? '&' : '?' ) + 'link=' + config.link : '' );
		return window
			.fetch( url, {
				method,
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-Axle-Review-Nonce': config.nonce },
				body: 'GET' === method ? undefined : JSON.stringify( Object.assign( { link: config.link }, body || {} ) ),
			} )
			.then( ( response ) =>
				response.json().then( ( data ) => {
					if ( ! response.ok ) {
						throw new Error( ( data && data.message ) || S.failed );
					}
					return data;
				} )
			);
	}

	/* Toolbar */

	const status = el( 'p', { class: 'axr-toolbar__status', role: 'status', 'aria-live': 'polite' } );
	const who = el( 'span', { class: 'axr-toolbar__who' } );
	const modeButton = el( 'button', { type: 'button', class: 'axr-button axr-button--primary', id: 'axr-note-mode', 'aria-pressed': 'false', text: S.leaveNote } );
	const mineButton = el( 'button', { type: 'button', class: 'axr-button', id: 'axr-my-notes', text: S.myNotes } );
	const canNote = config.allowNotes && config.hasBlocks;

	const toolbar = el( 'div', { class: 'axr-toolbar', id: 'axr-toolbar', role: 'region', 'aria-label': S.title }, [
		el( 'span', { class: 'axr-toolbar__title', text: S.title } ),
		canNote ? modeButton : null,
		config.allowNotes ? mineButton : null,
		who,
		status,
	] );

	if ( ! config.allowNotes ) {
		status.textContent = S.notesOff;
	} else if ( ! config.hasBlocks ) {
		status.textContent = S.noBlocks;
	}

	function showWho() {
		const name = storedName();
		who.textContent = name ? S.reviewingAs.replace( '%s', name ) : '';
	}

	function setNoteMode( on ) {
		noteMode = on;
		document.body.classList.toggle( 'axr-noting', on );
		modeButton.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		modeButton.textContent = on ? S.stopNotes : S.leaveNote;
		blocks().forEach( ( block ) => {
			if ( on ) {
				if ( ! block.hasAttribute( 'tabindex' ) ) {
					block.setAttribute( 'tabindex', '0' );
					block.setAttribute( 'data-axr-tab', '1' );
				}
				block.classList.add( 'axr-block' );
			} else {
				if ( block.getAttribute( 'data-axr-tab' ) ) {
					block.removeAttribute( 'tabindex' );
					block.removeAttribute( 'data-axr-tab' );
				}
				block.classList.remove( 'axr-block' );
			}
		} );
		status.textContent = on ? S.noteMode : '';
		if ( ! on ) {
			closePopover();
		}
	}

	modeButton.addEventListener( 'click', () => setNoteMode( ! noteMode ) );
	mineButton.addEventListener( 'click', () => openPanel() );

	/* Picking a block */

	function blockFor( target ) {
		if ( ! target || ! target.closest || target.closest( '#axr-toolbar, .axr-popover, .axr-panel' ) ) {
			return null;
		}
		let element = target.closest( '[data-axr-block]' );
		const allowed = blocks();
		while ( element && ! allowed.includes( element ) ) {
			element = element.parentElement ? element.parentElement.closest( '[data-axr-block]' ) : null;
		}
		return element;
	}

	function selectedQuote( block ) {
		const selection = window.getSelection ? window.getSelection() : null;
		if ( ! selection || selection.isCollapsed || ! selection.rangeCount ) {
			return '';
		}
		const range = selection.getRangeAt( 0 );
		return block.contains( range.commonAncestorContainer ) ? selection.toString().trim().slice( 0, 500 ) : '';
	}

	document.addEventListener(
		'mouseup',
		( event ) => {
			const block = noteMode ? blockFor( event.target ) : null;
			selectionText = block ? selectedQuote( block ) : '';
		},
		true
	);

	document.addEventListener(
		'click',
		( event ) => {
			if ( ! noteMode ) {
				return;
			}
			const block = blockFor( event.target );
			if ( ! block ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			openPopover( block, selectionText || selectedQuote( block ) );
		},
		true
	);

	document.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key ) {
			if ( popover ) {
				closePopover();
			} else if ( panel ) {
				closePanel();
			} else if ( noteMode ) {
				setNoteMode( false );
				modeButton.focus();
			}
			return;
		}
		if ( noteMode && 'Enter' === event.key && ! popover ) {
			const block = blockFor( event.target );
			if ( block && block === event.target ) {
				event.preventDefault();
				openPopover( block, '' );
			}
		}
	} );

	/* The note popover */

	function fieldsWanted() {
		const fields = applyFilters( 'axleReview.popoverFields', [ 'name', 'note' ], config );
		const list = Array.isArray( fields ) ? fields : [ 'name', 'note' ];
		// The note is always there, and a locked link already knows the email.
		return [ 'name', 'email', 'note' ].filter( ( field ) => 'note' === field || ( list.includes( field ) && ! ( 'email' === field && config.locked ) ) );
	}

	function closePopover() {
		if ( popover ) {
			popover.remove();
			popover = null;
			document.querySelectorAll( '.axr-block--active' ).forEach( ( node ) => node.classList.remove( 'axr-block--active' ) );
			if ( lastFocus && document.contains( lastFocus ) ) {
				lastFocus.focus();
			}
		}
	}

	function openPopover( block, quote ) {
		closePopover();
		lastFocus = block;
		block.classList.add( 'axr-block--active' );

		const fields = fieldsWanted();
		const error = el( 'p', { class: 'axr-popover__error', role: 'alert' } );
		const name = el( 'input', { type: 'text', id: 'axr-name', name: 'name', autocomplete: 'name', maxlength: '100', required: true } );
		name.value = storedName();
		const email = el( 'input', { type: 'email', id: 'axr-email', name: 'email', autocomplete: 'email', maxlength: '100' } );
		const note = el( 'textarea', { id: 'axr-note', name: 'note', rows: '4', maxlength: String( config.maxLength ), required: true } );
		const trap = el( 'input', { type: 'text', id: 'axr-website', name: 'website', tabindex: '-1', autocomplete: 'off' } );
		const send = el( 'button', { type: 'submit', class: 'axr-button axr-button--primary', text: S.submit } );
		const cancel = el( 'button', { type: 'button', class: 'axr-button', text: S.cancel } );

		const form = el( 'form', { class: 'axr-popover__form', novalidate: true }, [
			quote ? el( 'p', { class: 'axr-popover__quote' }, [ el( 'span', { text: S.quoted + ' ' } ), el( 'q', { text: quote } ) ] ) : null,
			fields.includes( 'name' ) ? el( 'p', {}, [ el( 'label', { for: 'axr-name', text: S.name } ), name ] ) : null,
			fields.includes( 'email' ) ? el( 'p', {}, [ el( 'label', { for: 'axr-email', text: S.email } ), email ] ) : null,
			el( 'p', {}, [ el( 'label', { for: 'axr-note', text: S.note } ), note ] ),
			el( 'p', { class: 'axr-trap', 'aria-hidden': 'true' }, [ el( 'label', { for: 'axr-website', text: S.website } ), trap ] ),
			error,
			el( 'p', { class: 'axr-popover__actions' }, [ send, cancel ] ),
		] );

		popover = el( 'div', { class: 'axr-popover', role: 'dialog', 'aria-labelledby': 'axr-popover-title' }, [ el( 'h2', { id: 'axr-popover-title', class: 'axr-popover__title', text: S.leaveNote } ), form ] );
		document.body.appendChild( popover );

		const rect = block.getBoundingClientRect();
		const width = Math.min( 360, window.innerWidth - 24 );
		popover.style.top = Math.max( 8, window.scrollY + rect.bottom + 8 ) + 'px';
		popover.style.left = Math.max( 12, Math.min( window.scrollX + rect.left, window.scrollX + window.innerWidth - width - 12 ) ) + 'px';

		cancel.addEventListener( 'click', closePopover );
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			error.textContent = '';
			if ( ! note.value.trim() || ( fields.includes( 'name' ) && ! name.value.trim() ) ) {
				error.textContent = S.missing;
				( ! note.value.trim() ? note : name ).focus();
				return;
			}
			send.disabled = true;
			if ( name.value.trim() ) {
				storeName( name.value.trim() );
				showWho();
			}
			api( 'POST', 'notes', {
				text: note.value,
				name: name.value.trim(),
				email: email.value.trim(),
				path: block.getAttribute( 'data-axr-block' ) || '',
				hash: block.getAttribute( 'data-axr-hash' ) || '',
				block: block.getAttribute( 'data-axr-name' ) || '',
				quote,
				render: config.render,
				website: trap.value,
			} )
				.then( ( data ) => {
					block.classList.add( 'axr-has-note' );
					doAction( 'axleReview.noteSubmitted', data, block );
					status.textContent = S.sent;
					closePopover();
				} )
				.catch( ( problem ) => {
					send.disabled = false;
					error.textContent = problem.message || S.failed;
				} );
		} );

		( fields.includes( 'name' ) && ! name.value ? name : note ).focus();
	}

	/* My notes */

	function closePanel() {
		if ( panel ) {
			panel.remove();
			panel = null;
			mineButton.focus();
		}
	}

	function thread( item ) {
		const replies = el( 'ul', { class: 'axr-thread__replies' }, item.replies.map( ( reply ) => el( 'li', {}, [ el( 'strong', { text: reply.author + ': ' } ), el( 'span', { text: reply.text } ) ] ) ) );
		const children = [
			el( 'p', { class: 'axr-thread__meta' }, [ el( 'strong', { text: item.author } ), el( 'span', { class: 'axr-thread__status axr-thread__status--' + item.status, text: 'resolved' === item.status ? S.resolved : S.open } ) ] ),
			el( 'p', { class: 'axr-thread__text', text: item.text } ),
			item.replies.length ? replies : null,
		];

		if ( item.mine && 'open' === item.status ) {
			const id = 'axr-reply-' + item.id;
			const text = el( 'textarea', { id, rows: '2', maxlength: String( config.maxLength ) } );
			const button = el( 'button', { type: 'submit', class: 'axr-button', text: S.reply } );
			const form = el( 'form', { class: 'axr-thread__reply' }, [ el( 'label', { for: id, text: S.reply } ), text, button ] );
			form.addEventListener( 'submit', ( event ) => {
				event.preventDefault();
				if ( ! text.value.trim() ) {
					return;
				}
				button.disabled = true;
				api( 'POST', 'notes', { text: text.value, name: storedName(), parent: item.id, render: config.render } )
					.then( ( data ) => {
						doAction( 'axleReview.noteSubmitted', data, null );
						openPanel();
					} )
					.catch( ( problem ) => {
						button.disabled = false;
						status.textContent = problem.message || S.failed;
					} );
			} );
			children.push( form );
		}

		return el( 'li', { class: 'axr-thread' }, children );
	}

	function openPanel() {
		if ( panel ) {
			panel.remove();
		}
		const list = el( 'div', { class: 'axr-panel__list' } );
		const close = el( 'button', { type: 'button', class: 'axr-button', text: S.close } );
		panel = el( 'div', { class: 'axr-panel', role: 'dialog', 'aria-labelledby': 'axr-panel-title' }, [
			el( 'div', { class: 'axr-panel__head' }, [ el( 'h2', { id: 'axr-panel-title', text: S.notesHeading } ), close ] ),
			list,
		] );
		close.addEventListener( 'click', closePanel );
		document.body.appendChild( panel );
		close.focus();

		api( 'GET', 'notes' )
			.then( ( items ) => {
				list.textContent = '';
				if ( ! items.length ) {
					list.appendChild( el( 'p', { text: S.noNotes } ) );
					return;
				}
				list.appendChild( el( 'ul', { class: 'axr-panel__threads' }, items.map( thread ) ) );
			} )
			.catch( ( problem ) => {
				list.textContent = problem.message || S.failed;
			} );
	}

	/* Start */

	function start() {
		document.body.appendChild( toolbar );
		document.body.classList.add( 'axr-reviewing' );
		showWho();
		api( 'POST', 'ping', { render: config.render } ).catch( () => {} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )( window.axleReviewGuest, window.wp && window.wp.hooks );
