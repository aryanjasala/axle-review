/**
 * Axle Review in the block editor:
 *  - the "Share for review" panel: create, copy, revoke links and see their activity;
 *  - the review state: who approved, who asked for changes, and what was edited after an approval;
 *  - the pre-publish panel listing open notes and missing approvals when the publish gate is on;
 *  - a watcher that puts reviewers' new notes on their blocks while the editor is open,
 *    so the next save keeps them;
 *  - ?axr_note=<id> in the URL selects the block that note is on.
 *
 * Hooks (wp.hooks, namespace axleReview), read when used:
 *  - filter axleReview.expiryChoices ( choices ): [ { value: seconds, label } ].
 *  - action axleReview.linkCreated ( result ) after a link was created.
 *  - action axleReview.noteAttachedInEditor ( { id, clientId } ).
 *  - action axleReview.askedAgain ( result ) after a reviewer was asked to look again.
 */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! config ) {
		return;
	}

	const el = wp.element.createElement;
	const { Fragment, useState, useEffect, useCallback } = wp.element;
	const { __, _n, sprintf } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PanelRow, Button, TextControl, SelectControl, ToggleControl, TextareaControl, Notice, Spinner, Modal } = wp.components;
	const { useSelect, select, dispatch } = wp.data;
	const apiFetch = wp.apiFetch;
	const { addQueryArgs } = wp.url;
	const { applyFilters, doAction } = wp.hooks;
	const editorApi = wp.editor || {};
	const PluginDocumentSettingPanel = editorApi.PluginDocumentSettingPanel || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	const PluginPrePublishPanel = editorApi.PluginPrePublishPanel || ( wp.editPost && wp.editPost.PluginPrePublishPanel );

	const STATUS = {
		active: __( 'Active', 'axle-review' ),
		used: __( 'Used up', 'axle-review' ),
		expired: __( 'Expired', 'axle-review' ),
		revoked: __( 'Revoked', 'axle-review' ),
	};
	const EVENTS = {
		open: __( 'Opened', 'axle-review' ),
		code_sent: __( 'Code sent', 'axle-review' ),
		code_ok: __( 'Right code', 'axle-review' ),
		code_fail: __( 'Wrong code', 'axle-review' ),
		guess_alert: __( 'Locked after many wrong tries', 'axle-review' ),
		password_fail: __( 'Wrong password', 'axle-review' ),
		note: __( 'Left a note', 'axle-review' ),
		reply_seen: __( 'Saw replies', 'axle-review' ),
		denied: __( 'Refused', 'axle-review' ),
		decision: __( 'Answered', 'axle-review' ),
	};
	const DECISION = {
		approved: __( 'Approved', 'axle-review' ),
		changes_requested: __( 'Changes requested', 'axle-review' ),
	};

	const UNITS = [
		[ 'year', 31536000 ],
		[ 'month', 2592000 ],
		[ 'day', 86400 ],
		[ 'hour', 3600 ],
		[ 'minute', 60 ],
	];

	/**
	 * "5 minutes ago" in the page language; the exact time goes in the title.
	 *
	 * @param {string} iso ISO 8601 time.
	 * @return {string} Relative text.
	 */
	function relative( iso ) {
		const seconds = Math.round( ( new Date( iso ).getTime() - Date.now() ) / 1000 );
		if ( Math.abs( seconds ) < 60 ) {
			return __( 'just now', 'axle-review' );
		}
		const unit = UNITS.find( ( entry ) => Math.abs( seconds ) >= entry[ 1 ] ) || UNITS[ UNITS.length - 1 ];
		try {
			return new Intl.RelativeTimeFormat( document.documentElement.lang || undefined, { numeric: 'auto' } ).format( Math.round( seconds / unit[ 1 ] ), unit[ 0 ] );
		} catch ( error ) {
			return new Date( iso ).toLocaleString();
		}
	}

	function Time( { iso, format } ) {
		if ( ! iso ) {
			return null;
		}
		const text = relative( iso );
		return el( 'time', { dateTime: iso, title: new Date( iso ).toLocaleString() }, format ? format( text ) : text );
	}

	/**
	 * The step before anything that revokes or overwrites: a core modal that says what will change and
	 * whether it can be undone, in place of a browser dialog.
	 */
	function Confirm( { title, message, label, onConfirm, onCancel } ) {
		return el(
			Modal,
			{ title, onRequestClose: onCancel, size: 'small', className: 'axr-confirm-modal' },
			el( 'p', null, message ),
			el(
				'div',
				{ className: 'axr-confirm-modal__actions' },
				el( Button, { variant: 'secondary', onClick: onCancel }, __( 'Cancel', 'axle-review' ) ),
				el( Button, { variant: 'primary', isDestructive: true, onClick: onConfirm }, label )
			)
		);
	}

	function notify( message, status ) {
		const notices = dispatch( 'core/notices' );
		if ( notices && notices.createNotice ) {
			notices.createNotice( status || 'success', message, { type: 'snackbar', isDismissible: true } );
		}
	}

	/* Blocks and notes */

	function noteIds( block ) {
		const raw = block && block.attributes && block.attributes.metadata ? block.attributes.metadata.noteId : null;
		return ( Array.isArray( raw ) ? raw : [ raw ] ).map( Number ).filter( ( id ) => id > 0 );
	}

	function walk( blocks, callback, prefix = '' ) {
		blocks.forEach( ( block, index ) => {
			const path = prefix ? prefix + '.' + index : String( index );
			callback( block, path );
			walk( block.innerBlocks || [], callback, path );
		} );
	}

	function findBlock( test ) {
		let found = null;
		walk( select( 'core/block-editor' ).getBlocks(), ( block, path ) => {
			if ( ! found && test( block, path ) ) {
				found = block;
			}
		} );
		return found;
	}

	function blockWithNote( id ) {
		return findBlock( ( block ) => noteIds( block ).includes( Number( id ) ) );
	}

	function showNote( id ) {
		const block = blockWithNote( id );
		if ( ! block ) {
			return false;
		}
		dispatch( 'core/block-editor' ).selectBlock( block.clientId );
		const area = dispatch( 'core/interface' );
		if ( area && area.enableComplementaryArea ) {
			area.enableComplementaryArea( 'core', 'edit-post/collab-sidebar' );
		}
		return true;
	}

	/**
	 * Puts reviewers' notes on their blocks in the open editor. The server has
	 * already written them to post_content; without this, saving the editor's
	 * older copy of the content would drop them.
	 */
	function attachPending( force ) {
		if ( false === window.axleReviewEditor.watch && true !== force ) {
			return;
		}
		const postId = select( 'core/editor' ).getCurrentPostId();
		if ( ! postId || select( 'core/editor' ).isSavingPost() ) {
			return;
		}

		apiFetch( { path: addQueryArgs( '/axle-review/v1/notes/pending', { post: postId } ) } )
			.then( ( items ) => {
				let changed = false;
				( items || [] ).forEach( ( item ) => {
					if ( blockWithNote( item.id ) ) {
						return;
					}
					const block = findBlock( ( candidate, path ) => path === item.path ) || findBlock( () => true );
					if ( ! block ) {
						return;
					}
					const metadata = Object.assign( {}, block.attributes.metadata || {} );
					const current = metadata.noteId;
					if ( Array.isArray( current ) ) {
						metadata.noteId = current.concat( [ item.id ] );
					} else if ( current ) {
						if ( 'array' !== item.shape ) {
							return;
						}
						metadata.noteId = [ Number( current ), item.id ];
					} else {
						metadata.noteId = 'array' === item.shape ? [ item.id ] : item.id;
					}
					dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, { metadata } );
					changed = true;
					doAction( 'axleReview.noteAttachedInEditor', { id: item.id, clientId: block.clientId } );
				} );
				if ( changed ) {
					const core = dispatch( 'core' );
					if ( core.invalidateResolutionForStoreSelector ) {
						core.invalidateResolutionForStoreSelector( 'getEntityRecords' );
					}
				}
			} )
			.catch( () => {} );
	}

	/* Share for review */

	function expiryChoices( fromServer ) {
		const choices = Object.keys( fromServer || {} ).map( ( seconds ) => ( { value: String( seconds ), label: fromServer[ seconds ] } ) );
		const filtered = applyFilters( 'axleReview.expiryChoices', choices );
		return Array.isArray( filtered ) && filtered.length ? filtered : choices;
	}

	function Activity( { link } ) {
		const [ events, setEvents ] = useState( null );
		useEffect( () => {
			apiFetch( { path: '/axle-review/v1/links/' + link.id + '/activity' } )
				.then( setEvents )
				.catch( () => setEvents( [] ) );
		}, [ link.id ] );

		if ( null === events ) {
			return el( Spinner );
		}
		if ( ! events.length ) {
			return el( 'p', { className: 'axr-muted' }, __( 'Nobody has opened this link yet.', 'axle-review' ) );
		}
		return el(
			'ul',
			{ className: 'axr-activity' },
			events.slice( 0, 20 ).map( ( event ) => el( 'li', { key: event.id }, ( EVENTS[ event.type ] || event.type ) + ', ', el( Time, { iso: event.created_at } ), event.browser ? ', ' + event.browser : '' ) )
		);
	}

	function LinkItem( { link, onRevoke } ) {
		const [ open, setOpen ] = useState( false );
		const views = link.max_views ? sprintf( /* translators: 1: views, 2: limit. */ __( '%1$d of %2$d views', 'axle-review' ), link.views, link.max_views ) : sprintf( /* translators: %d: views. */ _n( '%d view', '%d views', link.views, 'axle-review' ), link.views );
		const notes = sprintf( /* translators: %d: notes. */ _n( '%d note', '%d notes', link.notes, 'axle-review' ), link.notes );
		const ended = 'expired' === link.status || 'revoked' === link.status;
		// One translatable sentence, so languages can order the words as they need.
		const expiry = ( text ) => ( ended ? sprintf( /* translators: %s: a time such as "2 days ago". */ __( 'ended %s', 'axle-review' ), text ) : sprintf( /* translators: %s: a time such as "in 7 days". */ __( 'expires %s', 'axle-review' ), text ) );

		return el(
			'li',
			{ className: 'axr-link axr-link--' + link.status },
			el( 'p', { className: 'axr-link__head' }, el( 'strong', null, link.label || sprintf( /* translators: %d: link ID. */ __( 'Link %d', 'axle-review' ), link.id ) ), ' ', el( 'span', { className: 'axr-link__status' }, STATUS[ link.status ] || link.status ) ),
			el( 'p', { className: 'axr-link__meta' }, [ views, notes, link.email ].filter( Boolean ).join( ', ' ) + ', ', el( Time, { iso: link.expires_at, format: expiry } ) ),
			el(
				'p',
				{ className: 'axr-link__actions' },
				el( Button, { variant: 'link', onClick: () => setOpen( ! open ), 'aria-expanded': open }, __( 'Activity', 'axle-review' ) ),
				'revoked' !== link.status && el( Button, { variant: 'link', isDestructive: true, onClick: () => onRevoke( link ) }, __( 'Revoke', 'axle-review' ) )
			),
			open && el( Activity, { link } )
		);
	}

	/**
	 * "Create a review link": a button that opens a modal with the two things everyone sets (Label, Expires)
	 * and the rest under "More options".
	 */
	function CreateForm( { postId, defaults, expiry, onCreated } ) {
		const choices = expiryChoices( expiry );
		const [ open, setOpen ] = useState( false );
		const [ more, setMore ] = useState( false );
		const [ label, setLabel ] = useState( '' );
		const [ expires, setExpires ] = useState( String( defaults.expires ) );
		const [ views, setViews ] = useState( String( defaults.max_views ) );
		const [ email, setEmail ] = useState( '' );
		const [ password, setPassword ] = useState( '' );
		const [ allowNotes, setAllowNotes ] = useState( true );
		const [ shareNotes, setShareNotes ] = useState( !! defaults.share );
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );

		const close = () => {
			setOpen( false );
			setMore( false );
			setError( '' );
		};

		const create = ( event ) => {
			if ( event && event.preventDefault ) {
				event.preventDefault();
			}
			setBusy( true );
			setError( '' );
			apiFetch( {
				path: '/axle-review/v1/links',
				method: 'POST',
				// One address: the link is locked to it, and the link is emailed to it.
				data: { post: postId, label, expires: Number( expires ), max_views: Number( views ) || 0, email, password, allow_notes: allowNotes, share_notes: shareNotes, send_to: email },
			} )
				.then( ( result ) => {
					setBusy( false );
					setLabel( '' );
					setEmail( '' );
					setPassword( '' );
					close();
					doAction( 'axleReview.linkCreated', result );
					onCreated( result );
				} )
				.catch( ( problem ) => {
					setBusy( false );
					setError( problem.message || __( 'The link could not be created.', 'axle-review' ) );
				} );
		};

		const viewChoices = [
			{ value: '0', label: __( 'Unlimited', 'axle-review' ) },
			{ value: '1', label: __( 'Once', 'axle-review' ) },
			{ value: '3', label: __( '3 times', 'axle-review' ) },
			{ value: '10', label: __( '10 times', 'axle-review' ) },
		];
		if ( ! viewChoices.some( ( choice ) => choice.value === String( defaults.max_views ) ) ) {
			viewChoices.push( { value: String( defaults.max_views ), label: String( defaults.max_views ) } );
		}

		return el(
			Fragment,
			null,
			el( Button, { variant: 'secondary', className: 'axr-create__open', onClick: () => setOpen( true ) }, __( 'Create a review link', 'axle-review' ) ),
			open &&
				el(
					Modal,
					{ title: __( 'Create a review link', 'axle-review' ), onRequestClose: close, className: 'axr-create-modal', size: 'medium' },
					el(
						'form',
						{ className: 'axr-create', onSubmit: create },
						error && el( Notice, { status: 'error', isDismissible: false }, error ),
						el( TextControl, { label: __( 'Label', 'axle-review' ), help: __( 'Who it is for, such as "Legal".', 'axle-review' ), value: label, onChange: setLabel, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
						el( SelectControl, { label: __( 'Expires after', 'axle-review' ), value: expires, options: choices, onChange: setExpires, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
						el( 'p', null, el( Button, { variant: 'link', onClick: () => setMore( ! more ), 'aria-expanded': more }, more ? __( 'Fewer options', 'axle-review' ) : __( 'More options', 'axle-review' ) ) ),
						more &&
							el(
								'div',
								{ className: 'axr-create__more' },
								el( TextControl, { label: __( 'Only this person can open it', 'axle-review' ), help: __( 'Their email address. We email them the link, and they need a one-time code sent to this address to open it. Leave empty for anyone with the link.', 'axle-review' ), type: 'email', value: email, onChange: setEmail, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
								el( SelectControl, { label: __( 'Opens', 'axle-review' ), value: views, options: viewChoices, onChange: setViews, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
								el( TextControl, { label: __( 'Password (optional)', 'axle-review' ), type: 'password', autoComplete: 'new-password', value: password, onChange: setPassword, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
								el( ToggleControl, { label: __( 'Reviewer can leave notes', 'axle-review' ), checked: allowNotes, onChange: setAllowNotes, __nextHasNoMarginBottom: true } ),
								el( ToggleControl, { label: __( 'Show them notes other reviewers left', 'axle-review' ), checked: shareNotes, onChange: setShareNotes, __nextHasNoMarginBottom: true } )
							),
						el(
							'div',
							{ className: 'axr-confirm-modal__actions' },
							el( Button, { variant: 'tertiary', onClick: close }, __( 'Cancel', 'axle-review' ) ),
							el( Button, { variant: 'primary', type: 'submit', className: 'axr-create__submit', isBusy: busy, disabled: busy }, __( 'Create link', 'axle-review' ) )
						)
					)
				)
		);
	}

	function NewUrl( { result, again } ) {
		const [ copied, setCopied ] = useState( false );
		const copy = () => {
			const done = () => {
				setCopied( true );
				notify( __( 'Link copied.', 'axle-review' ) );
			};
			if ( window.navigator.clipboard ) {
				window.navigator.clipboard.writeText( result.url ).then( done, done );
			} else {
				done();
			}
		};
		return el(
			'div',
			{ className: 'axr-new-url' },
			el( Notice, { status: 'success', isDismissible: false }, again ? __( 'The reviewer has a new link. Copy it now: it is not shown again, and the old link no longer works.', 'axle-review' ) : __( 'Link created. Copy it now: it is not shown again.', 'axle-review' ) ),
			el( TextControl, { label: __( 'Review link', 'axle-review' ), value: result.url, readOnly: true, onChange: () => {}, onFocus: ( event ) => event.target.select(), __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
			el( Button, { variant: 'secondary', onClick: copy }, copied ? __( 'Copied', 'axle-review' ) : __( 'Copy link', 'axle-review' ) ),
			result.emailed && el( 'p', null, __( 'The link was emailed.', 'axle-review' ) )
		);
	}

	/* Review state */

	function ChangeList( { changes } ) {
		const parts = [];
		( changes.changed || [] ).forEach( ( item ) => parts.push( sprintf( /* translators: %s: block type. */ __( '%s changed', 'axle-review' ), item.label ) ) );
		( changes.added || [] ).forEach( ( item ) => parts.push( sprintf( /* translators: %s: block type. */ __( '%s added', 'axle-review' ), item.label ) ) );
		( changes.removed || [] ).forEach( ( item ) => parts.push( sprintf( /* translators: %s: block type. */ __( '%s removed', 'axle-review' ), item.label ) ) );
		( changes.meta || [] ).forEach( ( label ) => parts.push( sprintf( /* translators: %s: part of the post, such as the title. */ __( '%s changed', 'axle-review' ), label ) ) );
		if ( ! parts.length ) {
			return null;
		}
		return el(
			'ul',
			{ className: 'axr-changes-list' },
			parts.slice( 0, 12 ).map( ( text, index ) => el( 'li', { key: index }, text ) ),
			parts.length > 12 && el( 'li', { key: 'more' }, sprintf( /* translators: %d: number of further changes. */ __( 'and %d more', 'axle-review' ), parts.length - 12 ) )
		);
	}

	function Decision( { item, onAsk } ) {
		const tone = 'approved' === item.decision && ! item.edited ? 'good' : 'warning';
		const edited = item.edited ? ( 'approved' === item.decision ? __( 'Edited since approval', 'axle-review' ) : __( 'Edited since', 'axle-review' ) ) : '';

		return el(
			'li',
			{ className: 'axr-decision' },
			el(
				'p',
				{ className: 'axr-decision__head' },
				el( 'strong', null, item.reviewer || item.label ),
				' ',
				item.decision ? el( 'span', { className: 'axr-decision__state axr-decision__state--' + tone }, DECISION[ item.decision ] || item.decision ) : el( 'span', { className: 'axr-decision__state' }, __( 'No answer yet', 'axle-review' ) ),
				item.decided && el( Fragment, null, ' ', el( Time, { iso: item.decided } ) )
			),
			item.comment && el( 'p', { className: 'axr-decision__comment' }, item.comment ),
			edited && el( 'p', { className: 'axr-decision__edited' }, el( 'strong', null, edited ), item.changes ? ':' : '' ),
			item.changes && el( ChangeList, { changes: item.changes } ),
			item.asked && el( 'p', { className: 'axr-muted' }, __( 'Asked to look again', 'axle-review' ), ' ', el( Time, { iso: item.asked } ) ),
			item.can_ask && el( 'p', { className: 'axr-decision__actions' }, el( Button, { variant: 'secondary', onClick: () => onAsk( item ) }, __( 'Ask again', 'axle-review' ) ) )
		);
	}

	function ReviewState( { postId, refreshKey, onAsked } ) {
		const [ state, setState ] = useState( null );
		const [ error, setError ] = useState( '' );
		const [ asking, setAsking ] = useState( null );
		const [ busy, setBusy ] = useState( false );

		const load = useCallback( () => {
			apiFetch( { path: addQueryArgs( '/axle-review/v1/review', { post: postId } ) } )
				.then( ( data ) => {
					setError( '' );
					setState( data );
				} )
				.catch( ( problem ) => setError( problem.message || __( 'The review state could not be loaded. Reload the editor to try again.', 'axle-review' ) ) );
		}, [ postId ] );
		useEffect( load, [ load, refreshKey ] );

		if ( error ) {
			return el( Notice, { status: 'error', isDismissible: false }, error );
		}
		if ( ! state ) {
			return el( 'p', { 'aria-busy': 'true' }, el( Spinner ) );
		}

		const ask = () => {
			setBusy( true );
			apiFetch( { path: '/axle-review/v1/links/' + asking.link + '/ask-again', method: 'POST' } )
				.then( ( result ) => {
					setBusy( false );
					setAsking( null );
					doAction( 'axleReview.askedAgain', result );
					notify( result.emailed ? __( 'Asked again, and emailed the new link.', 'axle-review' ) : __( 'Asked again. Copy the new link now.', 'axle-review' ) );
					onAsked( result );
					load();
				} )
				.catch( ( problem ) => {
					setBusy( false );
					setAsking( null );
					notify( problem.message || __( 'The reviewer could not be asked again.', 'axle-review' ), 'error' );
				} );
		};

		return el(
			Fragment,
			null,
			el( 'p', { className: 'axr-state__text', role: 'status' }, el( 'strong', null, state.text || __( 'Nobody was asked to review this draft yet.', 'axle-review' ) ) ),
			! state.revisions && el( Notice, { status: 'info', isDismissible: false }, __( 'Revisions are off for this post. Reviewers who come back still see which blocks changed, but not what the blocks said before.', 'axle-review' ) ),
			state.decisions.length > 0 && el( 'ul', { className: 'axr-decisions' }, state.decisions.map( ( item ) => el( Decision, { key: item.link, item, onAsk: setAsking } ) ) ),
			asking &&
				el( Confirm, {
					title: __( 'Ask this reviewer again', 'axle-review' ),
					message: __( 'The reviewer gets a new link and sees only what changed since they answered. The old link stops working. This cannot be undone.', 'axle-review' ),
					label: busy ? __( 'Asking', 'axle-review' ) : __( 'Ask again', 'axle-review' ),
					onConfirm: ask,
					onCancel: () => setAsking( null ),
				} )
		);
	}

	/* Changes to a live page */

	/**
	 * The "change to a live page" part of the panel. On a published post: ask for review of a change,
	 * or follow the open one, or undo the last published change. On a working copy: what it is, whether
	 * the gate holds it back, and Publish the change.
	 */
	function ChangesPanel( { postId, status, saving } ) {
		const [ state, setState ] = useState( null );
		const [ gate, setGate ] = useState( null );
		const [ error, setError ] = useState( '' );
		const [ busy, setBusy ] = useState( '' );
		const [ confirm, setConfirm ] = useState( null );
		const [ reason, setReason ] = useState( '' );

		const load = useCallback( () => {
			apiFetch( { path: addQueryArgs( '/axle-review/v1/changes', { post: postId } ) } )
				.then( ( data ) => {
					setState( data );
					if ( 'copy' === data.role ) {
						apiFetch( { path: addQueryArgs( '/axle-review/v1/gate', { post: postId } ) } ).then( setGate ).catch( () => setGate( null ) );
					}
				} )
				.catch( () => setState( { role: 'none' } ) );
		}, [ postId ] );
		useEffect( load, [ load ] );

		// The gate's answer can change after every save.
		useEffect( () => {
			if ( ! saving && state && 'copy' === state.role ) {
				apiFetch( { path: addQueryArgs( '/axle-review/v1/gate', { post: postId } ) } ).then( setGate ).catch( () => {} );
			}
		}, [ saving ] ); // eslint-disable-line react-hooks/exhaustive-deps

		if ( ! state || 'none' === state.role ) {
			return null;
		}

		const fail = ( problem, fallback ) => {
			setBusy( '' );
			setError( ( problem && problem.message ) || fallback );
			return problem;
		};

		const request = () => {
			setBusy( 'request' );
			setError( '' );
			apiFetch( { path: '/axle-review/v1/changes', method: 'POST', data: { post: postId } } )
				.then( ( result ) => {
					doAction( 'axleReview.changeRequested', result );
					window.location.assign( result.editUrl );
				} )
				.catch( ( problem ) => {
					if ( problem && 'axle_review_change_exists' === problem.code && problem.data && problem.data.copy ) {
						load();
					}
					fail( problem, __( 'The working copy could not be made.', 'axle-review' ) );
				} );
		};

		const publish = ( overwrite ) => {
			setBusy( 'publish' );
			setError( '' );
			setConfirm( null );
			const save = select( 'core/editor' ).isEditedPostDirty() ? dispatch( 'core/editor' ).savePost() : Promise.resolve();
			Promise.resolve( save )
				.then( () => apiFetch( { path: '/axle-review/v1/changes/publish', method: 'POST', data: { post: postId, overwrite: !! overwrite } } ) )
				.then( ( result ) => {
					doAction( 'axleReview.changePublished', result );
					window.location.assign( result.editUrl );
				} )
				.catch( ( problem ) => {
					if ( problem && 'axle_review_live_changed' === problem.code ) {
						setBusy( '' );
						setConfirm( 'overwrite' );
						return;
					}
					fail( problem, __( 'The change could not be published.', 'axle-review' ) );
				} );
		};

		const override = () => {
			setBusy( 'override' );
			apiFetch( { path: '/axle-review/v1/gate/override', method: 'POST', data: { post: postId, reason } } )
				.then( () => {
					setBusy( '' );
					publish( false );
				} )
				.catch( ( problem ) => fail( problem, __( 'The reason could not be saved.', 'axle-review' ) ) );
		};

		const discard = () => {
			setBusy( 'discard' );
			setConfirm( null );
			apiFetch( { path: '/axle-review/v1/changes/discard', method: 'POST', data: { post: postId } } )
				.then( ( result ) => window.location.assign( result.editUrl || config.linksUrl ) )
				.catch( ( problem ) => fail( problem, __( 'The working copy could not be discarded.', 'axle-review' ) ) );
		};

		const undo = ( overwrite ) => {
			setBusy( 'undo' );
			setError( '' );
			setConfirm( null );
			apiFetch( { path: '/axle-review/v1/changes/undo', method: 'POST', data: { post: postId, overwrite: !! overwrite } } )
				.then( () => window.location.reload() )
				.catch( ( problem ) => {
					if ( problem && 'axle_review_undo_edited' === problem.code ) {
						setBusy( '' );
						setConfirm( 'undo-edited' );
						return;
					}
					fail( problem, __( 'The change could not be undone.', 'axle-review' ) );
				} );
		};

		const notice = error && el( Notice, { status: 'error', isDismissible: false }, error );
		const blocked = gate && gate.enabled && gate.blocks;

		if ( 'copy' === state.role ) {
			const live = state.original;
			return el(
				Fragment,
				null,
				el(
					'div',
					{ className: 'axr-change' },
					el( 'p', { className: 'axr-change__text' }, el( 'strong', null, live && live.title ? sprintf( /* translators: %s: title of the live page. */ __( 'A change to "%s"', 'axle-review' ), live.title ) : __( 'A change to a live page', 'axle-review' ) ) ),
					el( 'p', { className: 'axr-muted' }, __( 'The live page does not change until you publish the change. Share this copy with a review link first.', 'axle-review' ) ),
					live && live.url && el( 'p', null, el( 'a', { href: live.url, target: '_blank', rel: 'noopener noreferrer' }, __( 'View the live page', 'axle-review' ) ) ),
					state.liveChanged && el( Notice, { status: 'warning', isDismissible: false }, __( 'The live page was edited after this copy was made. Publishing the change replaces those edits. They stay in the undo.', 'axle-review' ) ),
					notice,
					blocked && gate.message && el( Notice, { status: 'warning', isDismissible: false }, gate.message ),
					blocked &&
						gate.canOverride &&
						! gate.override &&
						el(
							'div',
							{ className: 'axr-override' },
							el( TextareaControl, { label: __( 'Reason to publish anyway', 'axle-review' ), value: reason, onChange: setReason, __nextHasNoMarginBottom: true } ),
							el( Button, { variant: 'secondary', onClick: override, disabled: !! busy || ! reason.trim(), isBusy: 'override' === busy }, __( 'Publish the change anyway', 'axle-review' ) )
						),
					el(
						'p',
						{ className: 'axr-change__actions' },
						state.canPublish && ( ! blocked || gate.override ) && el( Button, { variant: 'primary', className: 'axr-change__publish', onClick: () => setConfirm( 'publish' ), disabled: !! busy, isBusy: 'publish' === busy }, __( 'Publish the change', 'axle-review' ) ),
						el( Button, { variant: 'link', isDestructive: true, onClick: () => setConfirm( 'discard' ), disabled: !! busy }, __( 'Discard this copy', 'axle-review' ) )
					)
				),
				'publish' === confirm &&
					el( Confirm, {
						title: __( 'Publish the change', 'axle-review' ),
						message: live && live.title ? sprintf( /* translators: %s: title of the live page. */ __( 'The live page "%s" is replaced by this copy right away, with its address, comments and categories kept. The version that is live now is kept, and you can undo this. This copy is deleted.', 'axle-review' ), live.title ) : __( 'The live page is replaced by this copy right away, with its address, comments and categories kept. The version that is live now is kept, and you can undo this. This copy is deleted.', 'axle-review' ),
						label: __( 'Publish the change', 'axle-review' ),
						onConfirm: () => publish( false ),
						onCancel: () => setConfirm( null ),
					} ),
				'overwrite' === confirm &&
					el( Confirm, {
						title: __( 'Replace edits to the live page', 'axle-review' ),
						message: __( 'The live page was edited after this copy was made. Publishing the change replaces those edits with this copy. They stay in the undo.', 'axle-review' ),
						label: __( 'Publish and replace them', 'axle-review' ),
						onConfirm: () => publish( true ),
						onCancel: () => setConfirm( null ),
					} ),
				'discard' === confirm &&
					el( Confirm, {
						title: __( 'Discard this copy', 'axle-review' ),
						message: __( 'The copy, its review links and its notes are deleted. The live page is not touched. This cannot be undone.', 'axle-review' ),
						label: __( 'Discard copy', 'axle-review' ),
						onConfirm: discard,
						onCancel: () => setConfirm( null ),
					} )
			);
		}

		// A live post.
		return el(
			Fragment,
			null,
			el(
				'div',
				{ className: 'axr-change' },
				notice,
				state.copy
					? el(
							Fragment,
							null,
							el( 'p', { className: 'axr-change__text' }, el( 'strong', null, __( 'A change to this page is in review.', 'axle-review' ) ) ),
							state.copy.editUrl ? el( 'p', null, el( 'a', { href: state.copy.editUrl }, __( 'Open the working copy', 'axle-review' ) ) ) : el( 'p', { className: 'axr-muted' }, __( 'Someone else is working on it.', 'axle-review' ) )
					  )
					: state.canRequest &&
					  el(
							Fragment,
							null,
							el( 'p', { className: 'axr-muted' }, __( 'This page is live, so it has no review links. To have a change reviewed first, work on a copy: reviewers see what changed, and one click puts it live.', 'axle-review' ) ),
							el( Button, { variant: 'secondary', className: 'axr-change__request', onClick: request, disabled: !! busy, isBusy: 'request' === busy }, __( 'Ask for review of a change', 'axle-review' ) )
					  ),
				state.undo && state.undo.available && el( 'p', { className: 'axr-change__undo' }, __( 'A reviewed change went live', 'axle-review' ), ' ', el( Time, { iso: state.undo.time } ), '. ', el( Button, { variant: 'link', onClick: () => ( state.undo.edited ? setConfirm( 'undo-edited' ) : setConfirm( 'undo' ) ), disabled: !! busy }, __( 'Undo that change', 'axle-review' ) ) )
			),
			'undo' === confirm &&
				el( Confirm, {
					title: __( 'Undo the last change', 'axle-review' ),
					message: __( 'This page goes back to how it was before the last reviewed change: its text, excerpt, custom fields and featured image. Notes stay.', 'axle-review' ),
					label: __( 'Undo the change', 'axle-review' ),
					onConfirm: () => undo( false ),
					onCancel: () => setConfirm( null ),
				} ),
			'undo-edited' === confirm &&
				el( Confirm, {
					title: __( 'Undo the last change', 'axle-review' ),
					message: __( 'This page was edited after the change went live. Undoing the change also removes those edits. Notes stay.', 'axle-review' ),
					label: __( 'Undo and remove those edits', 'axle-review' ),
					onConfirm: () => undo( true ),
					onCancel: () => setConfirm( null ),
				} )
		);
	}

	/* Share for review */

	function SharePanel() {
		const { postId, status, isNew, saving } = useSelect( ( store ) => {
			const editor = store( 'core/editor' );
			return { postId: editor.getCurrentPostId(), status: editor.getEditedPostAttribute( 'status' ), isNew: editor.isEditedPostNew(), saving: editor.isSavingPost() };
		}, [] );
		const [ data, setData ] = useState( null );
		const [ created, setCreated ] = useState( null );
		const [ again, setAgain ] = useState( false );
		const [ error, setError ] = useState( '' );
		const [ revoking, setRevoking ] = useState( null );
		const [ stateKey, setStateKey ] = useState( 0 );

		const load = useCallback( () => {
			if ( ! postId || isNew ) {
				return;
			}
			apiFetch( { path: addQueryArgs( '/axle-review/v1/links', { post: postId } ) } )
				.then( ( result ) => {
					// Only a post with a live link can get reviewer notes, so only then does the editor ask for them.
					window.axleReviewEditor.live = ( result.links || [] ).some( ( link ) => 'active' === link.status || 'used' === link.status );
					setData( result );
				} )
				.catch( ( problem ) => setError( problem.message || __( 'The links could not be loaded. Reload the editor to try again.', 'axle-review' ) ) );
		}, [ postId, isNew ] );

		useEffect( load, [ load ] );

		// Saving can change what a reviewer approved, so the state is read again after each save.
		useEffect( () => {
			if ( ! saving ) {
				setStateKey( ( key ) => key + 1 );
			}
		}, [ saving ] );

		if ( ! config.canShare ) {
			return null;
		}

		let body;
		if ( isNew ) {
			body = el( 'p', null, __( 'Save the draft first, then share it.', 'axle-review' ) );
		} else if ( [ 'publish', 'private', 'trash' ].includes( status ) ) {
			body = el(
				Fragment,
				null,
				'trash' !== status && el( ChangesPanel, { postId, status, saving } ),
				el( 'p', { className: 'axr-muted' }, __( 'Review links stop working once a post is published, private or in the trash.', 'axle-review' ) )
			);
		} else if ( error ) {
			body = el( Notice, { status: 'error', isDismissible: false }, error );
		} else if ( ! data ) {
			body = el( 'p', { 'aria-busy': 'true' }, el( Spinner ) );
		} else {
			const revoke = () => {
				const target = revoking;
				setRevoking( null );
				apiFetch( { path: '/axle-review/v1/links/' + ( 'all' === target ? 'revoke-all' : target.id ), method: 'all' === target ? 'POST' : 'DELETE', data: 'all' === target ? { post: postId } : undefined } )
					.then( () => {
						notify( 'all' === target ? __( 'Every link of this post was revoked.', 'axle-review' ) : __( 'The link was revoked. It cannot be turned back on.', 'axle-review' ) );
						load();
						setStateKey( ( key ) => key + 1 );
					} )
					.catch( ( problem ) => notify( problem.message || __( 'The link could not be revoked. Try again.', 'axle-review' ), 'error' ) );
			};
			const live = data.links.filter( ( link ) => 'revoked' !== link.status && 'expired' !== link.status );
			const name = revoking && 'all' !== revoking ? revoking.label || sprintf( /* translators: %d: link ID. */ __( 'Link %d', 'axle-review' ), revoking.id ) : '';

			body = el(
				Fragment,
				null,
				data.paused && el( Notice, { status: 'warning', isDismissible: false }, __( 'Review links are paused on this site. Nobody can open them right now.', 'axle-review' ) ),
				el( ChangesPanel, { postId, status, saving } ),
				el( ReviewState, {
					postId,
					refreshKey: stateKey,
					onAsked: ( result ) => {
						setCreated( result );
						setAgain( true );
						load();
					},
				} ),
				created && el( NewUrl, { result: created, again } ),
				data.links.length
					? el( 'ul', { className: 'axr-links' }, data.links.map( ( link ) => el( LinkItem, { key: link.id, link, onRevoke: setRevoking } ) ) )
					: el( 'p', { className: 'axr-muted' }, __( 'No review links yet. Create one to let someone without an account read this draft, leave notes on it and approve it.', 'axle-review' ) ),
				live.length > 1 && el( PanelRow, null, el( Button, { variant: 'secondary', isDestructive: true, onClick: () => setRevoking( 'all' ) }, __( 'Revoke all links', 'axle-review' ) ) ),
				revoking &&
					el( Confirm, {
						title: 'all' === revoking ? __( 'Revoke every link', 'axle-review' ) : __( 'Revoke this link', 'axle-review' ),
						message: 'all' === revoking ? __( 'Nobody can open the draft through any of its links any more. This cannot be undone.', 'axle-review' ) : sprintf( /* translators: %s: link label. */ __( 'Anyone who has "%s" can no longer open the draft. This cannot be undone.', 'axle-review' ), name ),
						label: 'all' === revoking ? __( 'Revoke all links', 'axle-review' ) : __( 'Revoke link', 'axle-review' ),
						onConfirm: revoke,
						onCancel: () => setRevoking( null ),
					} ),
				el( CreateForm, {
					postId,
					defaults: data.default,
					expiry: data.expiry,
					onCreated: ( result ) => {
						setAgain( false );
						setCreated( result );
						load();
					},
				} ),
				el( 'p', { className: 'axr-share__all' }, el( 'a', { href: config.linksUrl }, __( 'All review links', 'axle-review' ) ) )
			);
		}

		return el( PluginDocumentSettingPanel, { name: 'axle-review-share', title: __( 'Share for review', 'axle-review' ), className: 'axr-share' }, body );
	}

	/* Pre-publish: open notes */

	function GatePanel() {
		const postId = useSelect( ( store ) => store( 'core/editor' ).getCurrentPostId(), [] );
		const [ data, setData ] = useState( null );
		const [ reason, setReason ] = useState( '' );
		const [ message, setMessage ] = useState( '' );
		const [ busy, setBusy ] = useState( false );

		const load = useCallback( () => {
			apiFetch( { path: addQueryArgs( '/axle-review/v1/gate', { post: postId } ) } )
				.then( setData )
				.catch( () => setData( null ) );
		}, [ postId ] );
		useEffect( load, [ load ] );

		if ( ! data || ! data.blocks ) {
			return null;
		}

		const override = () => {
			setBusy( true );
			apiFetch( { path: '/axle-review/v1/gate/override', method: 'POST', data: { post: postId, reason } } )
				.then( () => {
					setBusy( false );
					setMessage( data.notes.length ? __( 'You can publish now. Your reason was added to each open note.', 'axle-review' ) : __( 'You can publish now. Your reason was added to the post as a note.', 'axle-review' ) );
					load();
				} )
				.catch( ( problem ) => {
					setBusy( false );
					setMessage( problem.message );
				} );
		};

		return el(
			PluginPrePublishPanel,
			{ title: data.notes.length ? __( 'Open notes', 'axle-review' ) : __( 'Approvals', 'axle-review' ), initialOpen: true, className: 'axr-gate-panel' },
			el( Notice, { status: data.override ? 'info' : 'warning', isDismissible: false }, data.override ? ( data.notes.length ? __( 'You chose to publish with open notes.', 'axle-review' ) : __( 'You chose to publish without enough approvals.', 'axle-review' ) ) : data.message ),
			el(
				'ul',
				{ className: 'axr-gate-notes' },
				data.notes.map( ( note ) =>
					el(
						'li',
						{ key: note.id },
						el( 'strong', null, note.guest ? sprintf( /* translators: %s: reviewer name. */ __( 'Guest: %s', 'axle-review' ), note.author ) : note.author ),
						': ',
						note.text.slice( 0, 140 ),
						' ',
						note.path ? el( Button, { variant: 'link', onClick: () => showNote( note.id ) }, __( 'Show', 'axle-review' ) ) : el( 'a', { href: addQueryArgs( config.inboxUrl, { post_id: postId } ) }, __( 'Open in Notes', 'axle-review' ) )
					)
				)
			),
			config.canOverride &&
				! data.override &&
				el(
					'div',
					{ className: 'axr-override' },
					el( TextareaControl, { label: data.notes.length ? __( 'Reason to publish with open notes', 'axle-review' ) : __( 'Reason to publish without enough approvals', 'axle-review' ), value: reason, onChange: setReason, __nextHasNoMarginBottom: true } ),
					el( Button, { variant: 'secondary', isDestructive: true, onClick: override, disabled: busy || ! reason.trim(), isBusy: busy }, data.notes.length ? __( 'Publish with open notes', 'axle-review' ) : __( 'Publish without enough approvals', 'axle-review' ) )
				),
			message && el( 'p', { role: 'status' }, message )
		);
	}

	function Root() {
		return el( Fragment, null, PluginDocumentSettingPanel && el( SharePanel ), PluginPrePublishPanel && el( GatePanel ) );
	}

	registerPlugin( 'axle-review', { render: Root, icon: null } );

	/* Start the watcher, and open ?axr_note= once the blocks are in. */
	const wanted = new URLSearchParams( window.location.search ).get( 'axr_note' );
	let tries = 0;
	const opener = window.setInterval( () => {
		tries++;
		if ( ! wanted || showNote( wanted ) || tries > 40 ) {
			window.clearInterval( opener );
		}
	}, 500 );

	// One check after load, then again only while the post has a live link and the tab is visible.
	window.setTimeout( attachPending, 1500 );
	window.setInterval( () => {
		if ( window.axleReviewEditor.live && 'hidden' !== document.visibilityState ) {
			attachPending();
		}
	}, Math.max( 5, Number( config.poll ) || 20 ) * 1000 );
	document.addEventListener( 'visibilitychange', () => {
		if ( window.axleReviewEditor.live && 'visible' === document.visibilityState ) {
			attachPending();
		}
	} );
	window.axleReviewEditor.attachPending = attachPending;
} )( window.wp, window.axleReviewEditor );
