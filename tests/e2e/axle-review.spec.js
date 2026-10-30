// End-to-end tests. Start the test site first: npm run env:start
// Each test checks the outcome in the database through WP-CLI as well as on screen.
const { test, expect, wp, wpEval, openEditor, logInAs, appPasswordClient } = require( './utils' );
const { installFixture, clearReview, createDraft, createLink, guestNote, mail, createPeople, removePeople, removePosts, seedReview, blocks } = require( './seed' );

const NO_COOKIES = { storageState: { cookies: [], origins: [] } };

/** Runs SQL and returns the rows as arrays of strings. */
const sql = ( query ) =>
	wp( 'db', 'query', query, '--skip-column-names' )
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => line.split( '\t' ) );
const one = ( query ) => ( sql( query )[ 0 ] || [] )[ 0 ];
const link = ( id ) => JSON.parse( wpEval( `echo wp_json_encode( ( new AryanX\\Axle\\Review\\Links() )->get( ${ id } ) );` ) );
const notesOn = ( postId ) => JSON.parse( wp( 'comment', 'list', '--type=note', `--post_id=${ postId }`, '--status=all', '--fields=comment_ID,user_id,comment_approved,comment_parent,comment_author,comment_content', '--format=json' ) );
const blockOf = ( postId, noteId ) => wpEval( `echo (string) AryanX\\Axle\\Review\\Anchor::find_note( parse_blocks( get_post( ${ postId } )->post_content ), ${ noteId } );` );
const meta = ( noteId, key ) => {
	try {
		return wp( 'comment', 'meta', 'get', String( noteId ), key );
	} catch ( error ) {
		return '';
	}
};
const hasMentions = () => '1' === wpEval( 'echo function_exists( "wp_get_note_mentioned_user_ids" ) ? 1 : 0;' );

/** A logged-out page in its own browser context. */
async function guest( browser ) {
	const context = await browser.newContext( NO_COOKIES );
	return { context, page: await context.newPage() };
}

/** Opens the "Share for review" panel in the editor sidebar. */
async function openSharePanel( page ) {
	const toggle = page.getByRole( 'button', { name: 'Share for review' } );
	if ( ! ( await toggle.isVisible().catch( () => false ) ) ) {
		await page.getByRole( 'button', { name: 'Settings', exact: true } ).first().click();
		await page.getByRole( 'tab', { name: /^Post$/ } ).click().catch( () => {} );
	}
	if ( 'true' !== ( await toggle.getAttribute( 'aria-expanded' ) ) ) {
		await toggle.click();
	}
}

test.describe( 'Axle Review', () => {
	test.beforeAll( () => {
		installFixture();
		createPeople();
	} );

	test.beforeEach( () => {
		clearReview();
		removePosts();
	} );

	test.afterAll( () => {
		clearReview();
		removePosts();
		removePeople();
	} );

	test( 'a view-once link made in the editor opens the draft once, with no-index headers', async ( { page, browser } ) => {
		const postId = createDraft( 'Spring menu announcement' );
		await openEditor( page, postId );
		await openSharePanel( page );

		// The JS hooks: an extra expiry choice, and the created action.
		await page.evaluate( () => {
			window.wp.hooks.addFilter( 'axleReview.expiryChoices', 'axr-test', ( choices ) => choices.concat( [ { value: '7200', label: 'Two hours (test)' } ] ) );
			window.wp.hooks.addAction( 'axleReview.linkCreated', 'axr-test', ( result ) => {
				window.axrCreated = result.url;
			} );
		} );
		// Closing and opening the panel mounts the form again, which reads the filter.
		await page.getByRole( 'button', { name: 'Share for review' } ).click();
		await page.getByRole( 'button', { name: 'Share for review' } ).click();

		await page.getByLabel( 'Label' ).fill( 'Founder' );
		await page.getByLabel( 'Expires after' ).selectOption( '7200' );
		await page.getByLabel( 'Opens' ).selectOption( '1' );
		await page.getByRole( 'button', { name: 'Create link' } ).click();
		const urlField = page.getByLabel( 'Review link' );
		await expect( urlField ).toHaveValue( /axle_review=/ );
		const url = await urlField.inputValue();
		expect( await page.evaluate( () => window.axrCreated ) ).toBe( url );
		await expect( page.locator( '.axr-link' ).first() ).toContainText( 'Founder' );

		const id = Number( one( 'SELECT MAX(id) FROM wp_axle_review_links' ) );
		const row = link( id );
		expect( row.max_views ).toBe( 1 );
		expect( row.views ).toBe( 0 );
		expect( row.label ).toBe( 'Founder' );
		// Two hours, from the filtered choice.
		const lifetime = ( Date.parse( row.expires_at + 'Z' ) - Date.parse( row.created_at + 'Z' ) ) / 1000;
		expect( lifetime ).toBe( 7200 );
		// Only the hash is stored.
		expect( one( `SELECT COUNT(*) FROM wp_axle_review_links WHERE token_hash LIKE '%${ url.split( '=' )[ 1 ].slice( 0, 12 ) }%'` ) ).toBe( '0' );

		const first = await guest( browser );
		const response = await first.page.goto( url );
		expect( response.status() ).toBe( 200 );
		expect( response.headers()[ 'x-robots-tag' ] ).toContain( 'noindex' );
		expect( response.headers()[ 'cache-control' ] ).toContain( 'no-store' );
		await expect( first.page.getByRole( 'heading', { name: 'Spring menu announcement' } ) ).toBeVisible();
		await expect( first.page.locator( '#axr-toolbar' ) ).toBeVisible();
		await expect( first.page.locator( 'meta[name="robots"]' ) ).toHaveAttribute( 'content', /noindex/ );

		// The session keeps working on reload.
		await first.page.reload();
		await expect( first.page.getByRole( 'heading', { name: 'Spring menu announcement' } ) ).toBeVisible();
		await first.context.close();

		// Anyone else gets the used-up page.
		const second = await guest( browser );
		const refused = await second.page.goto( url );
		expect( refused.status() ).toBe( 404 );
		await expect( second.page.locator( '#axr-gate' ) ).toContainText( 'already used' );
		await expect( second.page.getByText( 'Spring menu announcement' ) ).toHaveCount( 0 );
		await second.context.close();

		expect( link( id ).views ).toBe( 1 );
		expect( link( id ).status ).toBe( 'used' );
		expect( one( `SELECT COUNT(*) FROM wp_axle_review_events WHERE link_id = ${ id } AND type = 'open'` ) ).toBe( '1' );

		// An unknown token gets the same refusal page.
		const stranger = await guest( browser );
		const unknown = await stranger.page.goto( '/?axle_review=' + 'x'.repeat( 43 ) );
		expect( unknown.status() ).toBe( 404 );
		await expect( stranger.page.locator( '#axr-gate' ) ).toContainText( 'does not work' );
		await stranger.context.close();
	} );

	test( 'an email-locked link asks for a code and locks out after five wrong ones', async ( { browser } ) => {
		const postId = createDraft( 'Legal terms update' );
		const { id, url } = createLink( postId, [ '--email=legal@example.com' ] );
		const { context, page } = await guest( browser );

		await page.goto( url );
		await expect( page.getByRole( 'heading', { name: 'Check your email' } ) ).toBeVisible();
		await expect( page.getByText( 'Legal terms update' ) ).toHaveCount( 0 );
		await page.getByRole( 'button', { name: 'Email me a code' } ).click();
		await expect( page.getByRole( 'status' ) ).toContainText( 'We sent a code' );

		const sent = mail().filter( ( item ) => item.to === 'legal@example.com' );
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].subject ).toContain( 'Your review code' );
		const code = sent[ 0 ].message.match( /\b(\d{6})\b/ )[ 1 ];
		const wrong = String( ( Number( code ) + 1 ) % 1000000 ).padStart( 6, '0' );

		for ( let i = 0; i < 5; i++ ) {
			await page.getByLabel( 'Code' ).fill( wrong );
			await page.getByRole( 'button', { name: 'Open the page' } ).click();
			await expect( page.getByRole( 'alert' ) ).toBeVisible();
		}
		// Locked: even the right code is refused now.
		await page.getByLabel( 'Code' ).fill( code );
		await page.getByRole( 'button', { name: 'Open the page' } ).click();
		await expect( page.getByRole( 'alert' ) ).toContainText( 'Too many tries' );
		expect( one( `SELECT COUNT(*) FROM wp_axle_review_events WHERE link_id = ${ id } AND type = 'code_fail'` ) ).toBe( '5' );
		expect( link( id ).views ).toBe( 0 );

		// After the lockout (cleared here), a new code opens the page.
		wp( 'transient', 'delete', '--all' );
		await page.getByRole( 'button', { name: 'Email me a code' } ).click();
		const fresh = mail().filter( ( item ) => item.to === 'legal@example.com' ).pop().message.match( /\b(\d{6})\b/ )[ 1 ];
		await page.getByLabel( 'Code' ).fill( fresh );
		await page.getByRole( 'button', { name: 'Open the page' } ).click();
		await expect( page.getByRole( 'heading', { name: 'Legal terms update' } ) ).toBeVisible();
		expect( link( id ).views ).toBe( 1 );
		expect( one( `SELECT COUNT(*) FROM wp_axle_review_events WHERE link_id = ${ id } AND type = 'code_ok'` ) ).toBe( '1' );

		// A forwarded link still asks for the code.
		const other = await guest( browser );
		await other.page.goto( url );
		await expect( other.page.getByRole( 'heading', { name: 'Check your email' } ) ).toBeVisible();
		await other.context.close();
		await context.close();
	} );

	test( 'a password link asks for the password', async ( { browser } ) => {
		const postId = createDraft( 'Board update' );
		const { url, id } = createLink( postId, [ '--password=open-sesame' ] );
		const { context, page } = await guest( browser );
		await page.goto( url );
		await page.getByLabel( 'Password' ).fill( 'wrong' );
		await page.getByRole( 'button', { name: 'Open the page' } ).click();
		await expect( page.getByRole( 'alert' ) ).toContainText( 'wrong' );
		await page.getByLabel( 'Password' ).fill( 'open-sesame' );
		await page.getByRole( 'button', { name: 'Open the page' } ).click();
		await expect( page.getByRole( 'heading', { name: 'Board update' } ) ).toBeVisible();
		expect( one( `SELECT COUNT(*) FROM wp_axle_review_events WHERE link_id = ${ id } AND type = 'password_fail'` ) ).toBe( '1' );
		await context.close();
	} );

	test( 'a reviewer leaves a note on the third paragraph, and the editor shows it on that block', async ( { page, browser } ) => {
		const postId = createDraft( 'Spring menu announcement' );
		const { url, id } = createLink( postId, [ '--label=Founder' ] );
		const reviewer = await guest( browser );
		await reviewer.page.goto( url );

		// The hooks: the popover gets an email field, and the action fires.
		await reviewer.page.evaluate( () => {
			window.wp.hooks.addFilter( 'axleReview.popoverFields', 'axr-test', ( fields ) => fields.concat( [ 'email' ] ) );
			window.wp.hooks.addAction( 'axleReview.noteSubmitted', 'axr-test', ( data ) => {
				window.axrSubmitted = data.id;
			} );
		} );

		await reviewer.page.getByRole( 'button', { name: 'Leave a note' } ).click();
		await expect( reviewer.page.getByRole( 'button', { name: 'Stop leaving notes' } ) ).toHaveAttribute( 'aria-pressed', 'true' );
		// The third paragraph: the heading is block 0.
		await reviewer.page.locator( '[data-axr-block="3"]' ).click();
		const dialog = reviewer.page.getByRole( 'dialog', { name: 'Leave a note' } );
		await expect( dialog ).toBeVisible();
		await dialog.getByLabel( 'Your name' ).fill( 'Priya Nair' );
		await dialog.getByLabel( 'Your email (optional)' ).fill( 'priya@example.com' );
		await dialog.getByLabel( 'Your note' ).fill( 'Should this say 1,500 rupees including tax?' );
		await dialog.getByRole( 'button', { name: 'Send note' } ).click();
		await expect( reviewer.page.locator( '.axr-toolbar__status' ) ).toHaveText( 'Note sent. Thank you.' );
		await expect( dialog ).toHaveCount( 0 );
		await expect( reviewer.page.locator( '.axr-toolbar__who' ) ).toHaveText( 'Reviewing as Priya Nair' );

		const notes = notesOn( postId );
		expect( notes ).toHaveLength( 1 );
		const note = notes[ 0 ];
		expect( Number( await reviewer.page.evaluate( () => window.axrSubmitted ) ) ).toBe( Number( note.comment_ID ) );
		expect( note.user_id ).toBe( '0' );
		expect( note.comment_approved ).toBe( '0' );
		expect( note.comment_author ).toBe( 'Priya Nair' );
		expect( wp( 'comment', 'get', note.comment_ID, '--field=comment_author_email' ) ).toBe( 'priya@example.com' );
		expect( blockOf( postId, note.comment_ID ) ).toBe( '3' );
		expect( meta( note.comment_ID, '_axr_link_id' ) ).toBe( String( id ) );
		expect( one( `SELECT COUNT(*) FROM wp_axle_review_events WHERE link_id = ${ id } AND type = 'note'` ) ).toBe( '1' );

		// The post author got the reviewer note email.
		expect( mail().some( ( item ) => item.to === 'admin@example.com' && item.subject.includes( 'A reviewer left a note' ) ) ).toBe( true );

		// The editor has it on the third paragraph, as a core note.
		await openEditor( page, postId );
		const onBlock = await page.evaluate( () => {
			const block = window.wp.data.select( 'core/block-editor' ).getBlocks()[ 3 ];
			const raw = block.attributes.metadata && block.attributes.metadata.noteId;
			return ( Array.isArray( raw ) ? raw : [ raw ] ).map( Number );
		} );
		expect( onBlock ).toContain( Number( note.comment_ID ) );
		const records = await page.evaluate( async ( post ) => window.wp.apiFetch( { path: `/wp/v2/comments?post=${ post }&type=note&status=all&context=edit` } ), postId );
		expect( records.map( ( record ) => record.id ) ).toContain( Number( note.comment_ID ) );

		// An editor replies; the reviewer sees the reply, and not other people's notes.
		wp( 'axle-review', 'notes', 'add', String( postId ), `--reply-to=${ note.comment_ID }`, '--text=Yes, including tax. Thanks!', '--user=admin' );
		wp( 'axle-review', 'notes', 'add', String( postId ), '--block=1', '--text=Internal: check with finance.', '--user=maria' );
		await reviewer.page.getByRole( 'button', { name: 'View notes I left' } ).click();
		const panel = reviewer.page.getByRole( 'dialog', { name: 'Your notes' } );
		await expect( panel ).toContainText( 'Yes, including tax. Thanks!' );
		await expect( panel ).not.toContainText( 'Internal: check with finance.' );
		expect( one( `SELECT COUNT(*) FROM wp_axle_review_events WHERE link_id = ${ id } AND type = 'reply_seen'` ) ).toBe( '1' );

		// The reviewer answers in their thread.
		await panel.getByLabel( 'Reply' ).fill( 'Great, thank you.' );
		await panel.getByRole( 'button', { name: 'Reply' } ).click();
		await expect( panel ).toContainText( 'Great, thank you.' );
		expect( notesOn( postId ).filter( ( item ) => item.comment_parent === note.comment_ID && item.user_id === '0' ) ).toHaveLength( 1 );
		await reviewer.context.close();
	} );

	test( 'the block selector filter decides what counts as a block in note mode', async ( { browser } ) => {
		const postId = createDraft();
		const { url } = createLink( postId );
		const { context, page } = await guest( browser );
		await page.goto( url );
		await page.evaluate( () => window.wp.hooks.addFilter( 'axleReview.blockSelector', 'axr-test', () => '[data-axr-name="core/heading"]' ) );
		await page.getByRole( 'button', { name: 'Leave a note' } ).click();
		await page.locator( '[data-axr-block="2"]' ).click();
		await expect( page.getByRole( 'dialog', { name: 'Leave a note' } ) ).toHaveCount( 0 );

		// Keyboard: Tab to the heading, Enter opens the popover, Escape closes it.
		await page.locator( '[data-axr-block="0"]' ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.getByRole( 'dialog', { name: 'Leave a note' } ) ).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( page.getByRole( 'dialog', { name: 'Leave a note' } ) ).toHaveCount( 0 );
		await expect( page.locator( '[data-axr-block="2"]' ) ).not.toHaveAttribute( 'tabindex', '0' );
		await context.close();
	} );

	test( 'an editor with the post open keeps a reviewer note when saving older content', async ( { page, browser } ) => {
		const postId = createDraft( 'Spring menu announcement' );
		const { url } = createLink( postId );
		await openEditor( page, postId );

		// Unsaved edit in the open editor. The watcher waits, so the save below sends older content.
		await page.evaluate( () => {
			window.axleReviewEditor.watch = false;
			const blocksNow = window.wp.data.select( 'core/block-editor' ).getBlocks();
			window.wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( blocksNow[ 1 ].clientId, { content: 'Our spring menu starts on 2 April.' } );
		} );

		const created = await guestNote( browser, url, { text: 'Is the date final?', path: '2' } );
		expect( created.status ).toBe( 200 );
		const noteId = created.body.id;
		expect( blockOf( postId, noteId ) ).toBe( '2' );
		expect( meta( noteId, '_axr_pending_attach' ) ).not.toBe( '' );

		// Saving the editor's copy, which does not have the note yet.
		await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).savePost() );
		await expect.poll( () => wp( 'post', 'get', String( postId ), '--field=post_content' ) ).toContain( '2 April' );
		expect( blockOf( postId, noteId ) ).toBe( '2' );
		expect( meta( noteId, '_axr_pending_attach' ) ).not.toBe( '' );

		// The watcher puts it on the block in the editor; the next save clears the flag.
		await page.evaluate( () => window.axleReviewEditor.attachPending( true ) );
		await expect
			.poll( () =>
				page.evaluate( ( id ) => {
					const block = window.wp.data.select( 'core/block-editor' ).getBlocks()[ 2 ];
					const raw = block.attributes.metadata && block.attributes.metadata.noteId;
					return ( Array.isArray( raw ) ? raw : [ raw ] ).map( Number ).includes( id );
				}, noteId )
			)
			.toBe( true );
		await page.evaluate( () => window.wp.data.dispatch( 'core/editor' ).savePost() );
		await expect.poll( () => meta( noteId, '_axr_pending_attach' ) ).toBe( '' );
		expect( blockOf( postId, noteId ) ).toBe( '2' );
		expect( wp( 'post', 'get', String( postId ), '--field=post_content' ) ).toContain( '2 April' );
	} );

	test( 'the Notes inbox lists reviewer notes, resolves, reopens and replies', async ( { page } ) => {
		const seeded = await seedReview();

		await page.goto( '/wp-admin/index.php?page=axle-review-notes' );
		await expect( page.locator( '.axr-inbox .subsubsub .current' ) ).toContainText( 'Unresolved' );
		const row = page.locator( 'tr', { hasText: 'Guest: Leela (Legal)' } );
		await expect( row ).toContainText( 'Spring menu announcement' );
		await expect( row ).toContainText( 'Paragraph' );
		await expect( page.locator( 'tr', { hasText: 'Shorter intro?' } ) ).toHaveCount( 0 );

		// The column filter hides columns.
		await page.evaluate( () => {
			window.wp.hooks.addFilter( 'axleReview.inboxColumns', 'axr-test', ( columns ) => columns.filter( ( column ) => 'block' !== column ) );
			window.axleReviewInbox.apply();
		} );
		await expect( page.locator( 'th.column-block' ).first() ).toBeHidden();

		await row.hover();
		await row.getByRole( 'link', { name: 'Resolve' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( '1 note resolved.' );
		expect( wp( 'comment', 'get', String( seeded.notes.price ), '--field=comment_approved' ) ).toBe( '1' );
		const activity = () => JSON.parse( wpEval( `echo wp_json_encode( array_map( function ( $c ) { return get_comment_meta( $c->comment_ID, '_wp_note_status', true ); }, get_comments( array( 'type' => 'note', 'parent' => ${ seeded.notes.price }, 'status' => 'all' ) ) ) );` ) );
		expect( activity() ).toEqual( [ 'resolved' ] );

		await page.goto( '/wp-admin/index.php?page=axle-review-notes&view=resolved' );
		const resolved = page.locator( 'tr', { hasText: 'Guest: Leela (Legal)' } );
		await resolved.hover();
		await resolved.getByRole( 'link', { name: 'Reopen' } ).click();
		expect( wp( 'comment', 'get', String( seeded.notes.price ), '--field=comment_approved' ) ).toBe( '0' );
		expect( activity().sort() ).toEqual( [ 'reopen', 'resolved' ] );

		// Inline reply.
		await page.goto( '/wp-admin/index.php?page=axle-review-notes&view=guests' );
		const thread = page.locator( 'tr', { hasText: 'Guest: Studio Kite' } );
		await thread.locator( 'summary' ).click();
		await thread.getByLabel( 'Your reply' ).fill( 'Without GST. I will say so.' );
		await thread.getByRole( 'button', { name: 'Send reply' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Reply added.' );
		expect( notesOn( seeded.shipping ).some( ( item ) => item.comment_parent === String( seeded.notes.rates ) && item.comment_content.includes( 'Without GST' ) ) ).toBe( true );

		// Bulk resolve.
		await page.goto( '/wp-admin/index.php?page=axle-review-notes' );
		await page.locator( '#cb-select-all-1' ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'resolve' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'notes resolved.' );
		expect( one( "SELECT COUNT(*) FROM wp_comments WHERE comment_type = 'note' AND comment_parent = 0 AND comment_approved = '0'" ) ).toBe( '0' );
		await expect( page.locator( '.axr-inbox' ) ).toContainText( 'No open notes' );

		// The Notes column on the posts list links to the inbox for that post.
		wp( 'axle-review', 'notes', 'reopen', String( seeded.notes.tasting ), '--user=admin' );
		await page.goto( '/wp-admin/edit.php?post_status=draft&post_type=post' );
		const count = page.locator( `#post-${ seeded.menu } .column-axr_notes a` );
		await expect( count ).toHaveText( '1 open' );
		await count.click();
		await expect( page.locator( '.axr-filter-post' ) ).toContainText( 'Spring menu announcement' );
		await expect( page.locator( 'tbody tr' ) ).toHaveCount( 1 );
	} );

	test( 'the "Mentions me" view lists notes that mention the user (7.1 and later)', async ( { browser } ) => {
		const postId = createDraft();
		if ( ! hasMentions() ) {
			const { context, page } = await logInAs( browser, 'maria', 'demo-password-123' );
			await page.goto( '/wp-admin/index.php?page=axle-review-notes' );
			await expect( page.locator( '.axr-inbox .subsubsub' ) ).not.toContainText( 'Mentions me' );
			await context.close();
			return;
		}

		const admin = await appPasswordClient( 'admin', 'axr-mentions' );
		const maria = wp( 'user', 'get', 'maria', '--field=ID' );
		const added = await admin.call( 'POST', '/wp-abilities/v1/abilities/axle-review/add-note/run', { input: { post: postId, text: 'Can you check the prices?', block: '2', mentions: [ Number( maria ) ] } } );
		expect( added.status ).toBe( 200 );
		expect( added.body.note.mentions ).toEqual( [ Number( maria ) ] );
		expect( wp( 'comment', 'get', String( added.body.note.id ), '--field=comment_content' ) ).toContain( `wp-note-mention user-${ maria }` );
		await admin.dispose();

		const { context, page } = await logInAs( browser, 'maria', 'demo-password-123' );
		await page.goto( '/wp-admin/index.php?page=axle-review-notes' );
		await page.locator( '.axr-inbox .subsubsub' ).getByRole( 'link', { name: 'Mentions me' } ).click();
		await expect( page.locator( 'tbody' ) ).toContainText( 'Can you check the prices?' );
		await context.close();
	} );

	test( 'the publish gate holds back posts with open notes, in the editor and over REST', async ( { page, browser } ) => {
		wp( 'option', 'update', 'axle_review_settings', '{"gate_enabled":true}', '--format=json' );
		const postId = createDraft( 'Spring menu announcement' );
		const { url, id } = createLink( postId );
		const created = await guestNote( browser, url, { text: 'The tasting is on Sundays now.', path: '4', name: 'Anil' } );
		const noteId = created.body.id;

		// The editor: the pre-publish panel lists the note, and publishing fails.
		await openEditor( page, postId );
		await page.getByRole( 'button', { name: 'Publish', exact: true } ).first().click();
		const panel = page.locator( '.axr-gate-panel' );
		await expect( panel ).toContainText( 'This post has 1 open note' );
		await expect( panel ).toContainText( 'Guest: Anil' );
		await page.locator( '.editor-post-publish-panel' ).getByRole( 'button', { name: 'Publish', exact: true } ).click();
		await expect( page.locator( '.components-snackbar, .components-notice' ).filter( { hasText: 'open note' } ).first() ).toBeVisible();
		expect( wp( 'post', 'get', String( postId ), '--field=post_status' ) ).toBe( 'draft' );

		// REST with an application password gets the error code.
		const client = await appPasswordClient( 'admin', 'axr-gate' );
		const refused = await client.call( 'POST', `/wp/v2/posts/${ postId }`, { status: 'publish' } );
		expect( refused.status ).toBe( 409 );
		expect( refused.body.code ).toBe( 'axle_review_unresolved_notes' );

		const readiness = await client.call( 'GET', `/wp-abilities/v1/abilities/axle-review/get-publish-readiness/run?input[post]=${ postId }` );
		expect( readiness.body.would_block ).toBe( true );
		expect( readiness.body.blocking.map( ( note ) => note.id ) ).toEqual( [ noteId ] );

		// Resolved: publishing works, and the post's links die.
		wp( 'axle-review', 'notes', 'resolve', String( noteId ), '--user=admin' );
		const published = await client.call( 'POST', `/wp/v2/posts/${ postId }`, { status: 'publish' } );
		expect( published.status ).toBe( 200 );
		expect( wp( 'post', 'get', String( postId ), '--field=post_status' ) ).toBe( 'publish' );
		expect( link( id ).status ).toBe( 'revoked' );
		await client.dispose();

		// Override with a reason.
		const second = createDraft( 'Holiday hours' );
		const secondLink = createLink( second );
		const open = ( await guestNote( browser, secondLink.url, { text: 'Typo in the last line.', path: '1' } ) ).body.id;
		await openEditor( page, second );
		await page.getByRole( 'button', { name: 'Publish', exact: true } ).first().click();
		await page.locator( '.axr-gate-panel' ).getByLabel( 'Reason to publish with open notes' ).fill( 'The typo is fixed in the copy, the note is stale.' );
		await page.locator( '.axr-gate-panel' ).getByRole( 'button', { name: 'Publish with open notes' } ).click();
		await expect( page.locator( '.axr-gate-panel' ) ).toContainText( 'You can publish now' );
		await page.locator( '.editor-post-publish-panel' ).getByRole( 'button', { name: 'Publish', exact: true } ).click();
		await expect.poll( () => wp( 'post', 'get', String( second ), '--field=post_status' ) ).toBe( 'publish' );
		expect( notesOn( second ).some( ( item ) => item.comment_parent === String( open ) && item.comment_content.includes( 'The typo is fixed' ) ) ).toBe( true );
	} );

	test( 'revoking, pausing and publishing turn links off', async ( { page, browser } ) => {
		const postId = createDraft( 'Spring menu announcement' );
		const first = createLink( postId, [ '--label=Agency' ] );
		const second = createLink( postId, [ '--label=Board' ] );

		await openEditor( page, postId );
		await openSharePanel( page );
		const item = page.locator( '.axr-link', { hasText: 'Agency' } );
		await item.getByRole( 'button', { name: 'Revoke' } ).click();
		await expect( item ).toContainText( 'Revoked' );
		expect( link( first.id ).status ).toBe( 'revoked' );
		expect( Number( link( first.id ).revoked_by ) ).toBe( 1 );

		const visitor = await guest( browser );
		await visitor.page.goto( first.url );
		await expect( visitor.page.locator( '#axr-gate' ) ).toContainText( 'turned off' );

		// Pause every link from Tools > Review Links.
		await page.goto( '/wp-admin/tools.php?page=axle-review-links' );
		await page.getByRole( 'button', { name: 'Pause all links' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Every review link is paused.' );
		expect( wp( 'option', 'get', 'axle_review_paused', '--format=json' ) ).toContain( '"by":1' );
		await visitor.page.goto( second.url );
		await expect( visitor.page.locator( '#axr-gate' ) ).toContainText( 'paused' );

		await page.getByRole( 'button', { name: 'Resume all links' } ).click();
		await visitor.page.goto( second.url );
		await expect( visitor.page.getByRole( 'heading', { name: 'Spring menu announcement' } ) ).toBeVisible();

		// Bulk revoke on the Tools screen.
		const third = createLink( postId, [ '--label=Press' ] );
		await page.goto( '/wp-admin/tools.php?page=axle-review-links&status=active' );
		await page.locator( '#cb-select-all-1' ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'revoke' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'revoked' );
		expect( link( third.id ).status ).toBe( 'revoked' );
		expect( link( second.id ).status ).toBe( 'revoked' );

		// Publishing revokes what is left.
		const fourth = createLink( postId );
		wp( 'post', 'update', String( postId ), '--post_status=publish' );
		expect( link( fourth.id ).status ).toBe( 'revoked' );
		await visitor.page.goto( fourth.url );
		await expect( visitor.page.locator( '#axr-gate' ) ).toBeVisible();
		await visitor.context.close();
	} );

	test( 'Tools > Review Links creates a link for a post and shows it once', async ( { page } ) => {
		const postId = createDraft( 'Spring menu announcement', blocks(), 'page' );
		await page.goto( '/wp-admin/edit.php?post_type=page' );
		const row = page.locator( `#post-${ postId }` );
		await row.hover();
		await row.getByRole( 'link', { name: 'Share for review' } ).click();
		await page.getByLabel( 'Label' ).fill( 'Agency' );
		await page.getByLabel( 'Email the link to' ).fill( 'studio@example.com' );
		await page.getByRole( 'button', { name: 'Create link' } ).click();
		const field = page.getByLabel( 'Review link' );
		await expect( field ).toHaveValue( /axle_review=/ );
		await expect( page.locator( '.axr-new-link' ) ).toContainText( 'Emailed to studio@example.com.' );
		const url = await field.inputValue();
		expect( mail().some( ( item ) => item.to === 'studio@example.com' && item.message.includes( url ) ) ).toBe( true );
		expect( one( `SELECT label FROM wp_axle_review_links WHERE post_id = ${ postId }` ) ).toBe( 'Agency' );

		await page.reload();
		await expect( page.getByLabel( 'Review link' ) ).toHaveCount( 0 );
	} );

	test( 'abilities add notes on blocks, list them, and follow edit permissions', async () => {
		const postId = createDraft( 'Spring menu announcement' );
		const admin = await appPasswordClient( 'admin', 'axr-abilities' );

		const added = await admin.call( 'POST', '/wp-abilities/v1/abilities/axle-review/add-note/run', { input: { post: postId, text: 'Tighten this sentence.', match: 'roasted in Pune' } } );
		expect( added.status ).toBe( 200 );
		expect( added.body.block ).toBe( '2' );
		expect( blockOf( postId, added.body.note.id ) ).toBe( '2' );
		expect( wp( 'comment', 'get', String( added.body.note.id ), '--field=user_id' ) ).toBe( '1' );

		const listed = await admin.call( 'GET', `/wp-abilities/v1/abilities/axle-review/list-notes/run?input[post]=${ postId }` );
		expect( listed.status ).toBe( 200 );
		expect( listed.body.notes.map( ( note ) => note.id ) ).toContain( added.body.note.id );

		const thread = await admin.call( 'GET', `/wp-abilities/v1/abilities/axle-review/get-note-thread/run?input[id]=${ added.body.note.id }` );
		expect( thread.body.block.name ).toBe( 'core/paragraph' );

		const resolved = await admin.call( 'POST', '/wp-abilities/v1/abilities/axle-review/resolve-note/run', { input: { id: added.body.note.id } } );
		expect( resolved.body.status ).toBe( 'resolved' );
		expect( wp( 'comment', 'get', String( added.body.note.id ), '--field=comment_approved' ) ).toBe( '1' );

		const made = await admin.call( 'POST', '/wp-abilities/v1/abilities/axle-review/create-review-link/run', { input: { post: postId, label: 'Agent', max_views: 1 } } );
		expect( made.status ).toBe( 200 );
		expect( made.body.url ).toContain( 'axle_review=' );
		const revoked = await admin.call( 'DELETE', `/wp-abilities/v1/abilities/axle-review/revoke-review-link/run?input[id]=${ made.body.link.id }` );
		expect( revoked.body.status ).toBe( 'revoked' );
		await admin.dispose();

		// An author cannot touch the admin's draft.
		const author = await appPasswordClient( 'arjun', 'axr-abilities' );
		const denied = await author.call( 'GET', `/wp-abilities/v1/abilities/axle-review/get-publish-readiness/run?input[post]=${ postId }` );
		expect( denied.status ).toBe( 403 );
		const noAdd = await author.call( 'POST', '/wp-abilities/v1/abilities/axle-review/add-note/run', { input: { post: postId, text: 'Hi', block: '1' } } );
		expect( noAdd.status ).toBe( 403 );
		await author.dispose();
		expect( notesOn( postId ) ).toHaveLength( 2 );
	} );

	test( 'WP-CLI, Site Health and the privacy tools', async ( { browser } ) => {
		const postId = createDraft( 'Spring menu announcement' );
		const url = wp( 'axle-review', 'link', 'create', String( postId ), '--label=CLI', '--expires=2d', '--views=3', '--email=legal@example.com', '--porcelain' );
		expect( url ).toContain( 'axle_review=' );
		const listed = JSON.parse( wp( 'axle-review', 'link', 'list', `--post=${ postId }`, '--format=json' ) );
		expect( listed[ 0 ] ).toMatchObject( { label: 'CLI', max_views: 3, email: 'legal@example.com', status: 'active' } );

		// gate check exits 1 while notes are open and the gate is on.
		const other = createLink( postId );
		const note = ( await guestNote( browser, other.url, { text: 'Check the date.', path: '1', name: 'Leela' } ) ).body.id;
		wpEval( `update_comment_meta( ${ note }, '_axr_email_verified', 1 ); wp_update_comment( array( 'comment_ID' => ${ note }, 'comment_author_email' => 'legal@example.com' ) );` );
		wp( 'option', 'update', 'axle_review_settings', '{"gate_enabled":true}', '--format=json' );
		expect( () => wp( 'axle-review', 'gate', 'check', String( postId ) ) ).toThrow();
		expect( JSON.parse( wp( 'axle-review', 'notes', 'list', `--post=${ postId }`, '--guests', '--format=json' ) )[ 0 ].id ).toBe( note );

		// Privacy: export and erase by email.
		const exported = JSON.parse( wpEval( 'echo wp_json_encode( apply_filters( "wp_privacy_personal_data_exporters", array() )["axle-review"]["callback"]( "legal@example.com", 1 ) );' ) );
		expect( exported.data.map( ( item ) => item.group_id ).sort() ).toEqual( [ 'axle-review-links', 'axle-review-notes' ] );
		wpEval( 'apply_filters( "wp_privacy_personal_data_erasers", array() )["axle-review"]["callback"]( "legal@example.com", 1 );' );
		expect( wp( 'comment', 'get', String( note ), '--field=comment_author' ) ).toBe( 'Reviewer' );
		expect( wp( 'comment', 'get', String( note ), '--field=comment_author_email' ) ).toBe( '' );
		expect( one( "SELECT COUNT(*) FROM wp_axle_review_links WHERE email = 'legal@example.com'" ) ).toBe( '0' );

		// Site Health flags a page cache that served a review page twice.
		wp( 'option', 'update', 'axle_review_cache_warning', String( Math.floor( Date.now() / 1000 ) ) );
		const health = JSON.parse( wpEval( 'echo wp_json_encode( AryanX\\Axle\\Review\\Plugin::instance() ? ( new AryanX\\Axle\\Review\\Health\\SiteHealth( new AryanX\\Axle\\Review\\Settings(), new AryanX\\Axle\\Review\\Links(), AryanX\\Axle\\Review\\Plugin::instance()->gate() ) )->run() : null );' ) );
		expect( health.status ).toBe( 'recommended' );
		expect( health.description ).toContain( 'page cache' );
	} );
} );
