/**
 * Axle Review in the block editor:
 *  - the "Share for review" panel: create, copy, revoke links and see their activity;
 *  - the pre-publish panel listing open notes when the publish gate is on;
 *  - a watcher that puts reviewers' new notes on their blocks while the editor is open,
 *    so the next save keeps them;
 *  - ?axr_note=<id> in the URL selects the block that note is on.
 *
 * Hooks (wp.hooks, namespace axleReview), read when used:
 *  - filter axleReview.expiryChoices ( choices ): [ { value: seconds, label } ].
 *  - action axleReview.linkCreated ( result ) after a link was created.
 *  - action axleReview.noteAttachedInEditor ( { id, clientId } ).
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
	const { PanelRow, Button, TextControl, SelectControl, ToggleControl, TextareaControl, Notice, Spinner } = wp.components;
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
		password_fail: __( 'Wrong password', 'axle-review' ),
		note: __( 'Left a note', 'axle-review' ),
		reply_seen: __( 'Saw replies', 'axle-review' ),
		denied: __( 'Refused', 'axle-review' ),
	};

	const when = ( iso ) => ( iso ? new Date( iso ).toLocaleString() : '' );

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
			events.slice( 0, 20 ).map( ( event ) => el( 'li', { key: event.id }, ( EVENTS[ event.type ] || event.type ) + ', ' + when( event.created_at ) + ( event.browser ? ', ' + event.browser : '' ) ) )
		);
	}

	function LinkItem( { link, onRevoke } ) {
		const [ open, setOpen ] = useState( false );
		const views = link.max_views ? sprintf( /* translators: 1: views, 2: limit. */ __( '%1$d of %2$d views', 'axle-review' ), link.views, link.max_views ) : sprintf( /* translators: %d: views. */ _n( '%d view', '%d views', link.views, 'axle-review' ), link.views );
		const notes = sprintf( /* translators: %d: notes. */ _n( '%d note', '%d notes', link.notes, 'axle-review' ), link.notes );

		return el(
			'li',
			{ className: 'axr-link axr-link--' + link.status },
			el( 'p', { className: 'axr-link__head' }, el( 'strong', null, link.label || sprintf( /* translators: %d: link ID. */ __( 'Link %d', 'axle-review' ), link.id ) ), ' ', el( 'span', { className: 'axr-link__status' }, STATUS[ link.status ] || link.status ) ),
			el( 'p', { className: 'axr-link__meta' }, [ views, notes, link.email, sprintf( /* translators: %s: date. */ __( 'until %s', 'axle-review' ), when( link.expires_at ) ) ].filter( Boolean ).join( ', ' ) ),
			el(
				'p',
				{ className: 'axr-link__actions' },
				el( Button, { variant: 'link', onClick: () => setOpen( ! open ), 'aria-expanded': open }, __( 'Activity', 'axle-review' ) ),
				'revoked' !== link.status && el( Button, { variant: 'link', isDestructive: true, onClick: () => onRevoke( link ) }, __( 'Revoke', 'axle-review' ) )
			),
			open && el( Activity, { link } )
		);
	}

	function CreateForm( { postId, defaults, expiry, onCreated } ) {
		const choices = expiryChoices( expiry );
		const [ label, setLabel ] = useState( '' );
		const [ expires, setExpires ] = useState( String( defaults.expires ) );
		const [ views, setViews ] = useState( String( defaults.max_views ) );
		const [ email, setEmail ] = useState( '' );
		const [ password, setPassword ] = useState( '' );
		const [ allowNotes, setAllowNotes ] = useState( true );
		const [ shareNotes, setShareNotes ] = useState( !! defaults.share );
		const [ sendTo, setSendTo ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );

		const create = () => {
			setBusy( true );
			setError( '' );
			apiFetch( {
				path: '/axle-review/v1/links',
				method: 'POST',
				data: { post: postId, label, expires: Number( expires ), max_views: Number( views ) || 0, email, password, allow_notes: allowNotes, share_notes: shareNotes, send_to: sendTo },
			} )
				.then( ( result ) => {
					setBusy( false );
					setLabel( '' );
					setEmail( '' );
					setPassword( '' );
					setSendTo( '' );
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
			'div',
			{ className: 'axr-create' },
			error && el( Notice, { status: 'error', isDismissible: false }, error ),
			el( TextControl, { label: __( 'Label', 'axle-review' ), help: __( 'Who it is for, such as "Legal".', 'axle-review' ), value: label, onChange: setLabel, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
			el( SelectControl, { label: __( 'Expires after', 'axle-review' ), value: expires, options: choices, onChange: setExpires, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
			el( SelectControl, { label: __( 'Opens', 'axle-review' ), value: views, options: viewChoices, onChange: setViews, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
			el( TextControl, { label: __( 'Lock to an email (optional)', 'axle-review' ), help: __( 'The reviewer gets a one-time code there before the page opens.', 'axle-review' ), type: 'email', value: email, onChange: setEmail, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
			el( TextControl, { label: __( 'Password (optional)', 'axle-review' ), type: 'password', autoComplete: 'new-password', value: password, onChange: setPassword, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
			el( ToggleControl, { label: __( 'Reviewer can leave notes', 'axle-review' ), checked: allowNotes, onChange: setAllowNotes, __nextHasNoMarginBottom: true } ),
			el( ToggleControl, { label: __( 'Show them notes other reviewers left', 'axle-review' ), checked: shareNotes, onChange: setShareNotes, __nextHasNoMarginBottom: true } ),
			el( TextControl, { label: __( 'Email the link to (optional)', 'axle-review' ), type: 'email', value: sendTo, onChange: setSendTo, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
			el( Button, { variant: 'primary', className: 'axr-create__submit', onClick: create, isBusy: busy, disabled: busy }, __( 'Create link', 'axle-review' ) )
		);
	}

	function NewUrl( { result } ) {
		const [ copied, setCopied ] = useState( false );
		const copy = () => {
			const done = () => setCopied( true );
			if ( window.navigator.clipboard ) {
				window.navigator.clipboard.writeText( result.url ).then( done, done );
			} else {
				done();
			}
		};
		return el(
			Notice,
			{ status: 'success', isDismissible: false, className: 'axr-new-url' },
			el( 'p', null, el( 'strong', null, __( 'Link created. Copy it now: it is not shown again.', 'axle-review' ) ) ),
			el( TextControl, { label: __( 'Review link', 'axle-review' ), value: result.url, readOnly: true, onChange: () => {}, onFocus: ( event ) => event.target.select(), __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } ),
			el( Button, { variant: 'secondary', onClick: copy }, copied ? __( 'Copied', 'axle-review' ) : __( 'Copy link', 'axle-review' ) ),
			result.emailed && el( 'p', null, __( 'The link was emailed.', 'axle-review' ) )
		);
	}

	function SharePanel() {
		const { postId, status, isNew } = useSelect( ( store ) => {
			const editor = store( 'core/editor' );
			return { postId: editor.getCurrentPostId(), status: editor.getEditedPostAttribute( 'status' ), isNew: editor.isEditedPostNew() };
		}, [] );
		const [ data, setData ] = useState( null );
		const [ created, setCreated ] = useState( null );
		const [ error, setError ] = useState( '' );

		const load = useCallback( () => {
			if ( ! postId || isNew ) {
				return;
			}
			apiFetch( { path: addQueryArgs( '/axle-review/v1/links', { post: postId } ) } )
				.then( setData )
				.catch( ( problem ) => setError( problem.message ) );
		}, [ postId, isNew ] );

		useEffect( load, [ load ] );

		if ( ! config.canShare ) {
			return null;
		}

		let body;
		if ( isNew ) {
			body = el( 'p', null, __( 'Save the draft first, then share it.', 'axle-review' ) );
		} else if ( [ 'publish', 'private', 'trash' ].includes( status ) ) {
			body = el( 'p', null, __( 'Review links stop working once a post is published, private or in the trash.', 'axle-review' ) );
		} else if ( error ) {
			body = el( Notice, { status: 'error', isDismissible: false }, error );
		} else if ( ! data ) {
			body = el( Spinner );
		} else {
			const revoke = ( link ) => {
				apiFetch( { path: '/axle-review/v1/links/' + link.id, method: 'DELETE' } ).then( load );
			};
			const revokeAll = () => {
				apiFetch( { path: '/axle-review/v1/links/revoke-all', method: 'POST', data: { post: postId } } ).then( load );
			};
			const live = data.links.filter( ( link ) => 'revoked' !== link.status && 'expired' !== link.status );

			body = el(
				Fragment,
				null,
				data.paused && el( Notice, { status: 'warning', isDismissible: false }, __( 'Review links are paused on this site. Nobody can open them right now.', 'axle-review' ) ),
				created && el( NewUrl, { result: created } ),
				data.links.length
					? el( 'ul', { className: 'axr-links' }, data.links.map( ( link ) => el( LinkItem, { key: link.id, link, onRevoke: revoke } ) ) )
					: el( 'p', { className: 'axr-muted' }, __( 'No review links yet. Create one to let someone without an account read this draft and leave notes on it.', 'axle-review' ) ),
				live.length > 1 && el( PanelRow, null, el( Button, { variant: 'secondary', isDestructive: true, onClick: revokeAll }, __( 'Revoke all links', 'axle-review' ) ) ),
				el( 'h3', { className: 'axr-create__title' }, __( 'New link', 'axle-review' ) ),
				el( CreateForm, {
					postId,
					defaults: data.default,
					expiry: data.expiry,
					onCreated: ( result ) => {
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
					setMessage( __( 'You can publish now. Your reason was added to each open note.', 'axle-review' ) );
					load();
				} )
				.catch( ( problem ) => {
					setBusy( false );
					setMessage( problem.message );
				} );
		};

		return el(
			PluginPrePublishPanel,
			{ title: __( 'Open notes', 'axle-review' ), initialOpen: true, className: 'axr-gate-panel' },
			el( Notice, { status: data.override ? 'info' : 'warning', isDismissible: false }, data.override ? __( 'You chose to publish with open notes.', 'axle-review' ) : data.message ),
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
						el( Button, { variant: 'link', onClick: () => showNote( note.id ) }, __( 'Show', 'axle-review' ) )
					)
				)
			),
			config.canOverride &&
				! data.override &&
				el(
					'div',
					{ className: 'axr-override' },
					el( TextareaControl, { label: __( 'Reason to publish with open notes', 'axle-review' ), value: reason, onChange: setReason, __nextHasNoMarginBottom: true } ),
					el( Button, { variant: 'secondary', isDestructive: true, onClick: override, disabled: busy || ! reason.trim(), isBusy: busy }, __( 'Publish with open notes', 'axle-review' ) )
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

	window.setTimeout( attachPending, 1500 );
	window.setInterval( attachPending, Math.max( 5, Number( config.poll ) || 20 ) * 1000 );
	window.axleReviewEditor.attachPending = attachPending;
} )( window.wp, window.axleReviewEditor );
