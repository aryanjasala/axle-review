/**
 * Axle Review: the reviewer's bar, note form and notes sidebar on a review page.
 *
 * Plain JavaScript, no framework: it runs on the theme's front end. Everything
 * it draws lives in one #axr-root element, styled to look like WordPress's own
 * Notes in the editor. Filters (wp.hooks, namespace axleReview) are read when
 * they are used, so scripts that load later can still hook in:
 *  - axleReview.blockSelector ( selector ): which elements count as a block in note mode.
 *  - axleReview.popoverFields ( fields ): the note form fields, from name, email and note.
 * Actions: axleReview.noteSubmitted ( note, element ) after a note was saved,
 * axleReview.decisionSubmitted ( decision ) after the reviewer approved or asked for changes.
 * Filter: axleReview.changesBar ( changes ) to change what the "changed since your visit" bar lists.
 */
( function ( config, hooks ) {
	'use strict';

	if ( ! config || window.axleReviewLoaded ) {
		return;
	}
	window.axleReviewLoaded = true;

	const S = config.strings;
	const NAME_KEY = 'axr-name-' + config.link;
	const SVG = 'http://www.w3.org/2000/svg';
	// Shapes from the WordPress icon set (GPL), so the bar matches the editor.
	const ICONS = {
		comment: 'M18 4H6c-1.1 0-2 .9-2 2v12.9c0 .6.5 1.1 1.1 1.1.3 0 .5-.1.8-.3L8.5 17H18c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm.5 11c0 .3-.2.5-.5.5H7.9l-2.4 2.4V6c0-.3.2-.5.5-.5h12c.3 0 .5.2.5.5v9z',
		plus: 'M11 12.5V17.5H12.5V12.5H17.5V11H12.5V6H11V11H6V12.5H11Z',
		close: 'M12 13.06l3.712 3.713 1.061-1.06L13.061 12l3.712-3.712-1.06-1.06L12 10.938 8.288 7.227l-1.061 1.06L10.939 12l-3.712 3.712 1.06 1.061L12 13.061z',
		check: 'M16.5 7.5 10 13.9l-2.5-2.4-1 1 3.5 3.6 7.5-7.6z',
	};
	// How long a passing message stays on screen.
	const MESSAGE_TIME = 8000;
	const AVATAR_COLOURS = 6;

	let noteMode = false;
	let popover = null;
	let popoverHost = null;
	let anchor = null;
	let panel = null;
	let lastFocus = null;
	let selectionText = '';
	let messageTimer = 0;

	const applyFilters = ( name, value, ...args ) => ( hooks ? hooks.applyFilters( name, value, ...args ) : value );
	const doAction = ( name, ...args ) => hooks && hooks.doAction( name, ...args );

	function storedName() {
		try {
			return window.sessionStorage.getItem( NAME_KEY ) || config.reviewer || '';
		} catch ( error ) {
			return config.reviewer || '';
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

	function icon( name ) {
		const svg = document.createElementNS( SVG, 'svg' );
		svg.setAttribute( 'class', 'axr-icon' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'width', '24' );
		svg.setAttribute( 'height', '24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		const path = document.createElementNS( SVG, 'path' );
		path.setAttribute( 'd', ICONS[ name ] );
		svg.appendChild( path );
		return svg;
	}

	/**
	 * A button with a text label, and an icon before it when one is named.
	 *
	 * @param {string} kind       Extra classes: axr-button--primary, --tertiary, --tool, --outline, --small.
	 * @param {string} label      Visible text.
	 * @param {Object} attributes Extra attributes.
	 * @param {string} iconName   Icon, or empty.
	 * @return {HTMLButtonElement} Button, with its label element as button.label.
	 */
	function button( kind, label, attributes = {}, iconName = '' ) {
		const text = el( 'span', { class: 'axr-button__text', text: label } );
		const node = el( 'button', Object.assign( { type: 'button', class: ( 'axr-button ' + kind ).trim() }, attributes ), [ iconName ? icon( iconName ) : null, text ] );
		node.label = text;
		return node;
	}

	/** A button that only shows an icon, named for assistive technology. */
	function iconButton( label, iconName, kind = '' ) {
		return el( 'button', { type: 'button', class: ( 'axr-button axr-button--icon axr-button--tertiary ' + kind ).trim(), 'aria-label': label, title: label }, [ icon( iconName ) ] );
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

	/* The bar across the top */

	const root = el( 'div', { id: 'axr-root', class: 'axr-root' } );
	const top = el( 'div', { class: 'axr-top' } );
	const status = el( 'p', { class: 'axr-snackbar axr-toolbar__status', role: 'status', 'aria-live': 'polite' } );
	const who = el( 'span', { class: 'axr-toolbar__who' } );
	const info = el( 'p', { class: 'axr-toolbar__info' } );
	const decisionLine = el( 'p', { class: 'axr-toolbar__decision' } );
	const canNote = config.allowNotes && config.hasBlocks;
	const canDecide = !! config.decide;

	const modeButton = button( 'axr-button--tool', S.leaveNote, { id: 'axr-note-mode', 'aria-pressed': 'false' }, 'plus' );
	const mineButton = button( 'axr-button--tool', S.myNotes, { id: 'axr-my-notes', 'aria-expanded': 'false' }, 'comment' );
	// The check mark shows once that answer was given (the stylesheet hides it until then).
	const changesButton = button( 'axr-button--outline axr-button--answer', S.changes, { id: 'axr-request-changes' }, 'check' );
	const approveButton = button( 'axr-button--primary axr-button--answer', S.approve, { id: 'axr-approve' }, 'check' );

	const toolbar = el( 'div', { class: 'axr-toolbar', id: 'axr-toolbar', role: 'region', 'aria-label': S.title }, [
		el( 'div', { class: 'axr-toolbar__bar' }, [
			el( 'span', { class: 'axr-toolbar__title' }, [ icon( 'comment' ), el( 'span', { class: 'axr-toolbar__name', text: S.title } ) ] ),
			who,
			el( 'span', { class: 'axr-toolbar__space' } ),
			canNote ? modeButton : null,
			config.allowNotes ? mineButton : null,
			canDecide ? changesButton : null,
			canDecide ? approveButton : null,
		] ),
		decisionLine,
		info,
	] );
	top.appendChild( toolbar );
	root.appendChild( top );
	root.appendChild( status );

	if ( ! config.allowNotes ) {
		info.textContent = S.notesOff;
	} else if ( ! config.hasBlocks ) {
		info.textContent = S.noBlocks;
	}

	/**
	 * Shows a message in the corner and reads it to screen readers.
	 *
	 * @param {string}  message Text, or empty to clear.
	 * @param {boolean} stay    Keep it until it is replaced.
	 */
	function say( message, stay = false ) {
		window.clearTimeout( messageTimer );
		status.textContent = message;
		if ( message && ! stay ) {
			messageTimer = window.setTimeout( () => {
				if ( status.textContent === message ) {
					status.textContent = noteMode ? S.noteMode : '';
				}
			}, MESSAGE_TIME );
		}
	}

	status.addEventListener( 'click', () => say( noteMode ? S.noteMode : '', true ) );

	function showWho() {
		const name = storedName();
		who.textContent = name ? S.reviewingAs.replace( '%s', name ) : '';
	}

	/**
	 * Keeps the page clear of the bar: tells the stylesheet how tall the bar is
	 * and where the WordPress admin bar ends, when a signed-in person has one.
	 */
	function layout() {
		const html = document.documentElement;
		const adminBar = document.getElementById( 'wpadminbar' );
		const adminHeight = adminBar ? adminBar.offsetHeight : 0;
		const adminBottom = adminBar ? Math.max( 0, Math.round( adminBar.getBoundingClientRect().bottom ) ) : 0;
		const height = top.offsetHeight;
		html.style.setProperty( '--axr-admin-height', adminHeight + 'px' );
		html.style.setProperty( '--axr-admin', adminBottom + 'px' );
		html.style.setProperty( '--axr-top', height + 'px' );
		// Block themes place sticky headers below this height.
		html.style.setProperty( '--wp-admin--admin-bar--height', adminBottom + height + 'px' );
		placePopover();
	}

	function setNoteMode( on ) {
		noteMode = on;
		document.body.classList.toggle( 'axr-noting', on );
		modeButton.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		modeButton.label.textContent = on ? S.stopNotes : S.leaveNote;
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
		say( on ? S.noteMode : '', true );
		if ( ! on ) {
			closePopover();
		}
	}

	modeButton.addEventListener( 'click', () => setNoteMode( ! noteMode ) );
	mineButton.addEventListener( 'click', () => ( panel ? closePanel() : openPanel() ) );
	approveButton.addEventListener( 'click', () => openDecision( 'approved', approveButton ) );
	changesButton.addEventListener( 'click', () => openDecision( 'changes_requested', changesButton ) );

	/* Picking a block */

	function blockFor( target ) {
		if ( ! target || ! target.closest || target.closest( '#axr-root' ) ) {
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
		if ( 'Tab' === event.key && popover && popover.hasAttribute( 'aria-modal' ) ) {
			keepFocusIn( popover, event );
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

	/** Tab stays inside an open modal dialog. */
	function keepFocusIn( container, event ) {
		const stops = Array.from( container.querySelectorAll( 'button, input, textarea, [href], [tabindex]:not([tabindex="-1"])' ) ).filter(
			( node ) => ! node.disabled && '-1' !== node.getAttribute( 'tabindex' ) && node.offsetParent
		);
		if ( ! stops.length ) {
			return;
		}
		const first = stops[ 0 ];
		const last = stops[ stops.length - 1 ];
		const active = document.activeElement;
		if ( event.shiftKey && ( active === first || ! container.contains( active ) ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && ( active === last || ! container.contains( active ) ) ) {
			event.preventDefault();
			first.focus();
		}
	}

	/* The note form */

	function fieldsWanted() {
		const fields = applyFilters( 'axleReview.popoverFields', [ 'name', 'note' ], config );
		const list = Array.isArray( fields ) ? fields : [ 'name', 'note' ];
		// The note is always there, and a locked link already knows the email.
		return [ 'name', 'email', 'note' ].filter( ( field ) => 'note' === field || ( list.includes( field ) && ! ( 'email' === field && config.locked ) ) );
	}

	function field( id, label, control ) {
		control.classList.add( 'axr-input' );
		return el( 'div', { class: 'axr-field' }, [ el( 'label', { for: id, class: 'axr-label', text: label } ), control ] );
	}

	/**
	 * Puts the note form next to its block and always inside the window: beside
	 * the block when the margin is wide enough, so the text stays readable, else
	 * below it, else above it. Small screens use a sheet at the bottom instead,
	 * which the stylesheet places.
	 */
	function placePopover() {
		if ( ! popover || ! anchor ) {
			return;
		}
		if ( window.matchMedia( '(max-width: 600px)' ).matches ) {
			popover.style.top = '';
			popover.style.left = '';
			return;
		}
		const gap = 12;
		const edge = 8;
		const rect = anchor.getBoundingClientRect();
		const width = popover.offsetWidth;
		const height = popover.offsetHeight;
		const floor = top.getBoundingClientRect().bottom + edge;
		const ceiling = window.innerHeight - edge;
		const docked = panel && window.matchMedia( '(min-width: 960px)' ).matches ? panel.getBoundingClientRect() : null;
		const rtl = 'rtl' === window.getComputedStyle( document.documentElement ).direction;
		const minLeft = docked && rtl ? docked.right + gap : gap;
		const maxLeft = ( docked && ! rtl ? docked.left : document.documentElement.clientWidth ) - width - gap;

		const beside = rtl ? rect.left - gap - width : rect.right + gap;
		let x = rtl ? rect.right - width : rect.left;
		let y = rect.bottom + gap;
		if ( beside >= minLeft && beside <= maxLeft ) {
			x = beside;
			y = Math.min( rect.top, ceiling - height );
		} else if ( y + height > ceiling ) {
			const above = rect.top - gap - height;
			y = above >= floor ? above : ceiling - height;
		}
		popover.style.top = Math.max( floor, y ) + 'px';
		popover.style.left = Math.max( minLeft, Math.min( x, maxLeft ) ) + 'px';
	}

	/**
	 * Scrolls the page up just enough for the note form to fit under its block,
	 * so the text being discussed stays in view while the reviewer writes.
	 */
	function makeRoom() {
		if ( ! popover || ! anchor ) {
			return;
		}
		const gap = 12;
		const edge = 8;
		const rect = anchor.getBoundingClientRect();
		const height = popover.offsetHeight;
		const floor = top.getBoundingClientRect().bottom + edge;
		const short = rect.bottom + gap + height - ( window.innerHeight - edge );
		const spare = rect.top - floor;
		if ( short > 0 && spare > 0 ) {
			window.scrollBy( 0, Math.min( short, spare ) );
		}
	}

	function closePopover() {
		if ( popover ) {
			popoverHost.remove();
			popover = null;
			popoverHost = null;
			anchor = null;
			document.querySelectorAll( '.axr-block--active' ).forEach( ( node ) => node.classList.remove( 'axr-block--active' ) );
			if ( lastFocus && document.contains( lastFocus ) ) {
				lastFocus.focus();
			}
		}
	}

	function openPopover( block, quote ) {
		closePopover();
		lastFocus = block;
		anchor = block;
		block.classList.add( 'axr-block--active' );

		const fields = fieldsWanted();
		const error = el( 'p', { class: 'axr-popover__error', role: 'alert' } );
		const name = el( 'input', { type: 'text', id: 'axr-name', name: 'name', autocomplete: 'name', maxlength: '100', required: true } );
		name.value = storedName();
		const email = el( 'input', { type: 'email', id: 'axr-email', name: 'email', autocomplete: 'email', maxlength: '100' } );
		const note = el( 'textarea', { id: 'axr-note', name: 'note', rows: '4', maxlength: String( config.maxLength ), required: true } );
		const trap = el( 'input', { type: 'text', id: 'axr-website', name: 'website', tabindex: '-1', autocomplete: 'off' } );
		const send = button( 'axr-button--primary', S.submit, { type: 'submit' } );
		const cancel = button( 'axr-button--tertiary', S.cancel );

		const form = el( 'form', { class: 'axr-popover__form', novalidate: true }, [
			quote ? el( 'p', { class: 'axr-popover__quote' }, [ el( 'span', { text: S.quoted + ' ' } ), el( 'q', { text: quote } ) ] ) : null,
			fields.includes( 'name' ) ? field( 'axr-name', S.name, name ) : null,
			fields.includes( 'email' ) ? field( 'axr-email', S.email, email ) : null,
			field( 'axr-note', S.note, note ),
			el( 'p', { class: 'axr-trap', 'aria-hidden': 'true' }, [ el( 'label', { for: 'axr-website', text: S.website } ), trap ] ),
			error,
			el( 'div', { class: 'axr-popover__actions' }, [ cancel, send ] ),
		] );

		popover = el( 'div', { class: 'axr-popover', role: 'dialog', 'aria-labelledby': 'axr-popover-title' }, [ el( 'h2', { id: 'axr-popover-title', class: 'axr-popover__title', text: S.leaveNote } ), form ] );
		popoverHost = popover;
		root.appendChild( popover );
		makeRoom();
		placePopover();

		cancel.addEventListener( 'click', closePopover );
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			error.textContent = '';
			if ( ! note.value.trim() || ( fields.includes( 'name' ) && ! name.value.trim() ) ) {
				error.textContent = S.missing;
				placePopover();
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
					closePopover();
					say( S.sent );
					if ( panel ) {
						openPanel();
					}
				} )
				.catch( ( problem ) => {
					send.disabled = false;
					error.textContent = problem.message || S.failed;
					placePopover();
				} );
		} );

		( fields.includes( 'name' ) && ! name.value ? name : note ).focus( { preventScroll: true } );
	}

	/* The notes sidebar */

	function closePanel() {
		if ( panel ) {
			panel.remove();
			panel = null;
			document.documentElement.classList.remove( 'axr-panel-open' );
			mineButton.setAttribute( 'aria-expanded', 'false' );
			mineButton.focus();
			placePopover();
		}
	}

	/** "2 hours ago", in the page's language. */
	function ago( date ) {
		const then = Date.parse( /[zZ]$|[+-]\d\d:?\d\d$/.test( date ) ? date : date + 'Z' );
		if ( Number.isNaN( then ) ) {
			return null;
		}
		const seconds = Math.round( ( then - Date.now() ) / 1000 );
		let text = '';
		try {
			const format = new Intl.RelativeTimeFormat( document.documentElement.lang || undefined, { numeric: 'auto' } );
			const unit = [
				[ 'year', 31536000 ],
				[ 'month', 2592000 ],
				[ 'week', 604800 ],
				[ 'day', 86400 ],
				[ 'hour', 3600 ],
				[ 'minute', 60 ],
			].find( ( [ , size ] ) => Math.abs( seconds ) >= size );
			text = unit ? format.format( Math.round( seconds / unit[ 1 ] ), unit[ 0 ] ) : format.format( 0, 'second' );
		} catch ( error ) {
			text = new Date( then ).toLocaleDateString();
		}
		return el( 'time', { class: 'axr-note__time', datetime: new Date( then ).toISOString(), title: new Date( then ).toLocaleString(), text } );
	}

	/** A round badge with the author's initial; the ring colour follows the name. */
	function avatar( author ) {
		const name = String( author || '' ).trim();
		let sum = 0;
		for ( const letter of name ) {
			sum += letter.codePointAt( 0 );
		}
		return el( 'span', { class: 'axr-avatar axr-avatar--' + ( sum % AVATAR_COLOURS ), 'aria-hidden': 'true' }, [ el( 'span', { class: 'axr-avatar__initial', text: ( Array.from( name )[ 0 ] || '?' ).toUpperCase() } ) ] );
	}

	function noteView( item ) {
		const paragraphs = String( item.text || '' )
			.split( /\n{2,}/ )
			.filter( ( text ) => text.trim() )
			.map( ( text ) => el( 'p', { class: 'axr-note__text', text } ) );
		return el( 'div', { class: 'axr-note' }, [
			el( 'div', { class: 'axr-note__head' }, [ avatar( item.author ), el( 'div', { class: 'axr-note__meta' }, [ el( 'strong', { class: 'axr-note__author', text: item.author } ), item.date ? ago( item.date ) : null ] ) ] ),
			...paragraphs,
		] );
	}

	function thread( item ) {
		const children = [ noteView( item ) ];
		if ( item.replies.length ) {
			children.push(
				el(
					'ul',
					{ class: 'axr-thread__replies' },
					item.replies.map( ( reply ) => el( 'li', {}, [ noteView( reply ) ] ) )
				)
			);
		}

		if ( item.mine && 'open' === item.status ) {
			const id = 'axr-reply-' + item.id;
			const text = el( 'textarea', { id, class: 'axr-input', rows: '2', maxlength: String( config.maxLength ), placeholder: S.reply } );
			const send = button( 'axr-button--primary axr-button--compact', S.reply, { type: 'submit' } );
			const form = el( 'form', { class: 'axr-thread__reply' }, [ el( 'label', { for: id, class: 'axr-sr', text: S.reply } ), text, el( 'div', { class: 'axr-popover__actions' }, [ send ] ) ] );
			form.addEventListener( 'submit', ( event ) => {
				event.preventDefault();
				if ( ! text.value.trim() ) {
					text.focus();
					return;
				}
				send.disabled = true;
				api( 'POST', 'notes', { text: text.value, name: storedName(), parent: item.id, render: config.render } )
					.then( ( data ) => {
						doAction( 'axleReview.noteSubmitted', data, null );
						openPanel();
					} )
					.catch( ( problem ) => {
						send.disabled = false;
						say( problem.message || S.failed );
					} );
			} );
			children.push( form );
		}

		return el( 'li', { class: 'axr-thread axr-thread--' + item.status }, children );
	}

	function openPanel() {
		if ( panel ) {
			panel.remove();
		}
		const list = el( 'div', { class: 'axr-panel__list' } );
		const close = iconButton( S.close, 'close' );
		panel = el( 'div', { class: 'axr-panel', id: 'axr-panel', role: 'dialog', 'aria-labelledby': 'axr-panel-title' }, [
			el( 'div', { class: 'axr-panel__head' }, [ el( 'h2', { id: 'axr-panel-title', text: S.notesHeading } ), close ] ),
			list,
		] );
		close.addEventListener( 'click', closePanel );
		root.appendChild( panel );
		document.documentElement.classList.add( 'axr-panel-open' );
		mineButton.setAttribute( 'aria-expanded', 'true' );
		mineButton.setAttribute( 'aria-controls', 'axr-panel' );
		close.focus();
		placePopover();

		api( 'GET', 'notes' )
			.then( ( items ) => {
				list.textContent = '';
				if ( ! items.length ) {
					list.appendChild( el( 'p', { class: 'axr-panel__empty', text: S.noNotes } ) );
					return;
				}
				const open = items.filter( ( item ) => 'resolved' !== item.status );
				const resolved = items.filter( ( item ) => 'resolved' === item.status );
				if ( open.length ) {
					list.appendChild( el( 'ul', { class: 'axr-panel__threads' }, open.map( thread ) ) );
				}
				if ( resolved.length ) {
					list.appendChild( el( 'h3', { class: 'axr-panel__separator' }, [ el( 'span', { text: S.resolved } ) ] ) );
					list.appendChild( el( 'ul', { class: 'axr-panel__threads' }, resolved.map( thread ) ) );
				}
			} )
			.catch( ( problem ) => {
				list.textContent = '';
				list.appendChild( el( 'p', { class: 'axr-panel__empty', text: problem.message || S.failed } ) );
			} );
	}

	/* Approve or request changes */

	function showDecision() {
		const decision = config.decision || {};
		let text = '';
		if ( decision.decision ) {
			text = ( 'approved' === decision.decision ? S.youApproved : S.youChanges ).replace( '%s', decision.reviewer || '' ).replace( /^, /, '' );
			if ( decision.edited ) {
				text += ' ' + S.editedSince;
			}
		}
		decisionLine.textContent = text;
		decisionLine.className = 'axr-toolbar__decision' + ( decision.decision ? ' axr-toolbar__decision--' + ( decision.edited ? 'edited' : decision.decision ) : '' );
		approveButton.setAttribute( 'aria-pressed', 'approved' === decision.decision ? 'true' : 'false' );
		changesButton.setAttribute( 'aria-pressed', 'changes_requested' === decision.decision ? 'true' : 'false' );
	}

	function openDecision( kind, trigger ) {
		closePopover();
		lastFocus = trigger;
		const approving = 'approved' === kind;
		const known = storedName();
		const error = el( 'p', { class: 'axr-popover__error', role: 'alert' } );
		const name = el( 'input', { type: 'text', id: 'axr-decide-name', name: 'name', autocomplete: 'name', maxlength: '100', required: true } );
		name.value = known;
		const comment = el( 'textarea', { id: 'axr-decide-comment', name: 'comment', rows: '4', maxlength: String( config.maxLength ) } );
		if ( ! approving ) {
			comment.setAttribute( 'required', '' );
		}
		const send = button( 'axr-button--primary', approving ? S.sendApprove : S.sendChanges, { type: 'submit' } );
		const cancel = button( 'axr-button--tertiary', S.cancel );
		const close = iconButton( S.close, 'close' );
		const heading = approving ? S.approveTitle : S.changesTitle;

		const form = el( 'form', { class: 'axr-popover__form', novalidate: true }, [
			known ? el( 'p', { class: 'axr-popover__who', text: S.reviewingAs.replace( '%s', known ) } ) : field( 'axr-decide-name', S.name, name ),
			field( 'axr-decide-comment', approving ? S.comment : S.commentNeeded, comment ),
			error,
			el( 'div', { class: 'axr-popover__actions' }, [ cancel, send ] ),
		] );

		popover = el( 'div', { class: 'axr-popover axr-popover--fixed', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'axr-decide-title' }, [
			el( 'div', { class: 'axr-popover__head' }, [ el( 'h2', { id: 'axr-decide-title', class: 'axr-popover__title', text: heading } ), close ] ),
			form,
		] );
		popoverHost = el( 'div', { class: 'axr-overlay' }, [ popover ] );
		popoverHost.addEventListener( 'mousedown', ( event ) => {
			if ( event.target === popoverHost ) {
				closePopover();
			}
		} );
		root.appendChild( popoverHost );

		cancel.addEventListener( 'click', closePopover );
		close.addEventListener( 'click', closePopover );
		form.addEventListener( 'submit', ( event ) => {
			event.preventDefault();
			error.textContent = '';
			const reviewer = known || name.value.trim();
			if ( ! reviewer ) {
				error.textContent = S.nameNeeded;
				name.focus();
				return;
			}
			if ( ! approving && ! comment.value.trim() ) {
				error.textContent = S.commentNeeded;
				comment.focus();
				return;
			}
			send.disabled = true;
			send.setAttribute( 'aria-busy', 'true' );
			api( 'POST', 'decision', { decision: kind, name: reviewer, comment: comment.value, seen: ( config.visit && config.visit.hash ) || '' } )
				.then( ( data ) => {
					config.decision = data;
					config.reviewer = data.reviewer || reviewer;
					storeName( config.reviewer );
					showWho();
					showDecision();
					doAction( 'axleReview.decisionSubmitted', data );
					closePopover();
					say( approving ? S.approvedDone : S.changesDone );
				} )
				.catch( ( problem ) => {
					send.disabled = false;
					send.removeAttribute( 'aria-busy' );
					error.textContent = problem.message || S.failed;
				} );
		} );

		( known ? comment : name ).focus();
	}

	/* What changed since the last visit */

	function startChanges() {
		const visit = config.visit || {};
		const filtered = applyFilters( 'axleReview.changesBar', visit.changes );
		const changes = filtered && 'object' === typeof filtered ? filtered : null;
		if ( ! changes || ! visit.summary ) {
			return;
		}

		const targets = [];
		const place = ( entry, kind ) => {
			if ( ! /^[0-9.]+$/.test( entry.path ) ) {
				return;
			}
			const node = document.querySelector( '[data-axr-block="' + entry.path + '"]' );
			if ( ! node ) {
				return;
			}
			node.classList.add( 'axr-changed', 'axr-changed--' + kind );
			node.setAttribute( 'data-axr-mark', 'new' === kind ? S.markNew : S.markChanged );
			if ( 'static' === window.getComputedStyle( node ).position ) {
				node.style.position = 'relative';
			}
			targets.push( { node, entry } );

			if ( entry.before ) {
				const text = el( 'span', { class: 'axr-before__text', id: 'axr-before-' + entry.path.replace( /\./g, '-' ), hidden: true } );
				text.textContent = S.before + ' ' + entry.before;
				const toggle = el( 'button', { type: 'button', class: 'axr-button axr-before__toggle', 'aria-expanded': 'false', 'aria-controls': text.id, text: S.showBefore } );
				toggle.addEventListener( 'click', () => {
					const open = text.hasAttribute( 'hidden' );
					text.toggleAttribute( 'hidden', ! open );
					toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
					toggle.textContent = open ? S.hideBefore : S.showBefore;
				} );
				node.insertAdjacentElement( 'afterend', el( 'p', { class: 'axr-before' }, [ toggle, text ] ) );
			}
		};
		( changes.changed || [] ).forEach( ( entry ) => place( entry, 'changed' ) );
		( changes.added || [] ).forEach( ( entry ) => place( entry, 'new' ) );
		targets.sort( ( a, b ) => ( a.node.compareDocumentPosition( b.node ) & 4 ? -1 : 1 ) );

		let at = -1;
		const live = el( 'p', { class: 'axr-sr', role: 'status' } );
		const go = ( step ) => {
			if ( ! targets.length ) {
				return;
			}
			at = ( at + step + targets.length ) % targets.length;
			const target = targets[ at ];
			target.node.setAttribute( 'tabindex', '-1' );
			target.node.scrollIntoView( { block: 'center' } );
			target.node.focus( { preventScroll: true } );
			live.textContent = at + 1 + ' / ' + targets.length + ': ' + ( target.entry.label || '' );
		};

		const previous = button( 'axr-button--tertiary axr-button--compact', S.previous );
		const next = button( 'axr-button--compact', S.next );
		const close = iconButton( S.close, 'close', 'axr-changes__close' );
		previous.addEventListener( 'click', () => go( -1 ) );
		next.addEventListener( 'click', () => go( 1 ) );

		const extras = [];
		if ( ( changes.removed || [] ).length ) {
			const list = el(
				'ul',
				{ class: 'axr-changes__removed' },
				changes.removed.map( ( entry ) => el( 'li', {}, [ el( 'strong', { text: ( entry.label || '' ) + ': ' } ), el( 'span', { text: entry.before || S.noBefore } ) ] ) )
			);
			extras.push( el( 'details', { class: 'axr-changes__details' }, [ el( 'summary', { text: S.removedHead + ' (' + changes.removed.length + ')' } ), list ] ) );
		}
		if ( ( changes.meta || [] ).length ) {
			extras.push( el( 'p', { class: 'axr-changes__meta', text: S.metaHead + ' ' + changes.meta.join( ', ' ) } ) );
		}
		if ( ! visit.revisions && ( changes.changed || [] ).length ) {
			extras.push( el( 'p', { class: 'axr-changes__meta', text: S.noBefore } ) );
		}

		const bar = el( 'div', { class: 'axr-changes', id: 'axr-changes', role: 'region', 'aria-label': S.changedTitle }, [
			el( 'p', { class: 'axr-changes__text', text: visit.summary } ),
			targets.length ? previous : null,
			targets.length ? next : null,
			close,
			live,
			...extras,
		] );
		close.addEventListener( 'click', () => {
			bar.remove();
			targets.forEach( ( target ) => {
				target.node.classList.remove( 'axr-changed', 'axr-changed--changed', 'axr-changed--new' );
				target.node.removeAttribute( 'data-axr-mark' );
			} );
			document.querySelectorAll( '.axr-before' ).forEach( ( node ) => node.remove() );
			layout();
		} );
		top.appendChild( bar );
	}

	/* Start */

	function start() {
		document.body.appendChild( root );
		document.documentElement.classList.add( 'axr-reviewing' );
		document.body.classList.add( 'axr-reviewing' );
		showWho();
		showDecision();
		startChanges();
		layout();

		let frame = 0;
		const relayout = () => {
			window.cancelAnimationFrame( frame );
			frame = window.requestAnimationFrame( layout );
		};
		window.addEventListener( 'resize', relayout );
		window.addEventListener( 'scroll', relayout, { passive: true, capture: true } );
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( relayout ).observe( top );
		}

		api( 'POST', 'ping', { render: config.render } ).catch( () => {} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )( window.axleReviewGuest, window.wp && window.wp.hooks );
