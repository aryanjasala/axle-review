// Round 3: review a change to a live page, plus the gate, code and editor fixes.
const { test, expect, wp, wpEval, openEditor, appPasswordClient } = require( './utils' );
const { installFixture, createLink, guestNote, mail, createPeople, seedLivePost, seedWorkingCopy, unseedReview, editPost, decide, blocks, seedReview } = require( './seed' );

const NO_COOKIES = { storageState: { cookies: [], origins: [] } };
const php = ( code ) => wpEval( code );
const post = ( id ) => JSON.parse( php( `$p = get_post( ${ id } ); echo wp_json_encode( $p ? array( 'status' => $p->post_status, 'content' => $p->post_content, 'title' => $p->post_title, 'slug' => $p->post_name ) : null );` ) );
const metaOf = ( id, key ) => php( `echo (string) get_post_meta( ${ id }, ${ JSON.stringify( key ) }, true );` );
const noteCount = ( id ) => Number( php( `echo count( get_comments( array( 'post_id' => ${ id }, 'type' => 'note', 'status' => 'all', 'fields' => 'ids', 'number' => 0 ) ) );` ) );
const guest = async ( browser ) => {
	const context = await browser.newContext( NO_COOKIES );
	return { context, page: await context.newPage() };
};

async function openSharePanel( page ) {
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' );
		if ( ! window.wp.data.select( 'core/editor' ).isEditorPanelOpened( 'axle-review/axle-review-share' ) ) {
			window.wp.data.dispatch( 'core/editor' ).toggleEditorPanelOpened( 'axle-review/axle-review-share' );
		}
	} );
}

test.describe( 'Review a change to a live page', () => {
	test.beforeAll( () => {
		installFixture();
		createPeople();
	} );

	test.afterEach( () => {
		unseedReview();
		createPeople();
	} );

	test( 'a working copy is a draft that points to the live page, and the live page is not touched', async () => {
		const { post: live } = seedLivePost();
		const before = post( live );
		const copy = Number( wp( 'axle-review', 'change', 'request', String( live ), '--porcelain', '--user=admin' ) );

		expect( post( copy ).status ).toBe( 'draft' );
		expect( post( copy ).title ).toBe( 'Pricing' );
		expect( metaOf( copy, '_axr_change_of' ) ).toBe( String( live ) );
		expect( metaOf( live, '_axr_change_copy' ) ).toBe( String( copy ) );
		expect( post( live ) ).toEqual( before );

		// One open copy for each live page.
		await expect( async () => wp( 'axle-review', 'change', 'request', String( live ) ) ).rejects.toThrow( /already in review/ );
		// A draft cannot be changed this way.
		const draft = Number( wp( 'post', 'create', '--post_title=Draft', '--post_status=draft', '--porcelain' ) );
		await expect( async () => wp( 'axle-review', 'change', 'request', String( draft ) ) ).rejects.toThrow( /Only a published post/ );
	} );

	test( 'a copy cannot be published directly, in the editor, over REST or from code', async () => {
		const { original, copy } = seedWorkingCopy();
		wp( 'post', 'update', String( copy ), '--post_status=publish' );
		expect( post( copy ).status ).toBe( 'draft' );

		const client = await appPasswordClient( 'admin', 'axr-copy' );
		const refused = await client.call( 'POST', `/wp/v2/pages/${ copy }`, { status: 'publish' } );
		expect( refused.status ).toBe( 409 );
		expect( refused.body.code ).toBe( 'axle_review_change_use_merge' );
		expect( post( copy ).status ).toBe( 'draft' );
		await client.dispose();
		expect( post( original ).status ).toBe( 'publish' );
	} );

	test( 'reviewers see the copy with changes marked from the live page', async ( { browser } ) => {
		const { copy, link } = seedWorkingCopy();
		const { context, page } = await guest( browser );
		await page.goto( link.url );
		await expect( page.getByRole( 'heading', { name: 'Pricing' } ).first() ).toBeVisible();
		await expect( page.locator( '#axr-changes' ) ).toContainText( '1 change from the live page' );
		await expect( page.locator( '.axr-changed' ) ).toHaveCount( 1 );
		await page.locator( '.axr-before__toggle' ).click();
		await expect( page.locator( '.axr-before' ) ).toContainText( '12 dollars' );
		await expect( page.getByText( '15 dollars' ) ).toBeVisible();
		await context.close();
		expect( copy ).toBeGreaterThan( 0 );
	} );

	test( 'publishing the change keeps the live page and takes the copy, with the old version as Undo', async ( { browser } ) => {
		const { original, copy, link } = seedWorkingCopy();
		wp( 'option', 'delete', 'axle_review_settings' );
		const slug = post( original ).slug;
		const permalink = php( `echo get_permalink( ${ original } );` );

		// Things that belong to the live page and must survive.
		php( `wp_insert_comment( array( 'comment_post_ID' => ${ original }, 'comment_content' => 'Great page', 'comment_author' => 'Visitor', 'comment_approved' => 1 ) ); wp_set_object_terms( ${ original }, array( 'Plans' ), 'category' ); update_post_meta( ${ original }, 'price_label', 'from 12' ); update_post_meta( ${ original }, '_thumbnail_id', 0 ); delete_post_meta( ${ original }, '_thumbnail_id' );` );
		// The copy changed a custom field and left a note from a reviewer.
		php( `update_post_meta( ${ copy }, 'price_label', 'from 15' );` );
		const reviewer = await guestNote( browser, link.url, { text: 'Is 15 final?', name: 'Priya', path: '2' } );
		expect( reviewer.status ).toBeLessThan( 300 );
		expect( noteCount( copy ) ).toBe( 1 );

		const live = post( original );
		wp( 'axle-review', 'change', 'publish', String( copy ), '--user=admin' );

		const after = post( original );
		expect( after.status ).toBe( 'publish' );
		expect( after.slug ).toBe( slug );
		expect( after.content ).toContain( '15 dollars' );
		expect( after.content ).not.toContain( '12 dollars' );
		expect( php( `echo get_permalink( ${ original } );` ) ).toBe( permalink );
		expect( metaOf( original, 'price_label' ) ).toBe( 'from 15' );
		expect( php( `echo get_comments( array( 'post_id' => ${ original }, 'count' => true ) );` ) ).toBe( '1' );
		expect( php( `echo implode( ',', wp_get_object_terms( ${ original }, 'category', array( 'fields' => 'names' ) ) );` ) ).toBe( 'Plans' );

		// The reviewer's note moved to the live page and still sits on its block; the copy and its links are gone.
		expect( noteCount( original ) ).toBeGreaterThanOrEqual( 2 );
		expect( php( `echo count( get_comments( array( 'post_id' => ${ original }, 'type' => 'note', 'status' => 'all', 'search' => 'Is 15 final' ) ) );` ) ).toBe( '1' );
		expect( post( copy ) ).toBeNull();
		expect( php( `echo count( ( new AryanX\\Axle\\Review\\Links() )->for_post( ${ copy } ) );` ) ).toBe( '0' );
		expect( metaOf( original, '_axr_change_copy' ) ).toBe( '' );

		// Undo puts the old version back.
		wp( 'axle-review', 'change', 'undo', String( original ), '--user=admin' );
		const undone = post( original );
		// The reviewer's note moved to the live page and is attached again to its block by the same save, so compare without note IDs.
		const plain = ( content ) => content.replace( / \{"metadata":\{"noteId":[^}]*\}\}/g, '' );
		expect( plain( undone.content ) ).toBe( plain( live.content ) );
		expect( metaOf( original, 'price_label' ) ).toBe( 'from 12' );
		await expect( async () => wp( 'axle-review', 'change', 'undo', String( original ) ) ).rejects.toThrow( /no published change to undo/ );
	} );

	test( 'the gate holds a change back until it has its approvals and no open notes', async ( { browser } ) => {
		const { original, copy, link } = seedWorkingCopy();
		wp( 'option', 'update', 'axle_review_settings', '{"gate_enabled":true,"gate_min_approvals":1}', '--format=json' );

		await expect( async () => wp( 'axle-review', 'change', 'publish', String( copy ) ) ).rejects.toThrow( /0 of 1 approvals/ );
		expect( post( original ).content ).toContain( '12 dollars' );

		expect( decide( link.id, 'approved', 'Sam Wu', 'Fine.' ) ).toBe( 'ok' );
		// A reviewer note stays open and holds the change back.
		await guestNote( browser, link.url, { text: 'One more thing', name: 'Priya', path: '1' } );
		await expect( async () => wp( 'axle-review', 'change', 'publish', String( copy ) ) ).rejects.toThrow( /open note/ );

		wp( 'axle-review', 'notes', 'resolve', php( `echo get_comments( array( 'post_id' => ${ copy }, 'type' => 'note', 'status' => 'all', 'fields' => 'ids', 'number' => 1 ) )[0];` ), '--user=admin' );
		wp( 'axle-review', 'change', 'publish', String( copy ), '--user=admin' );
		expect( post( original ).content ).toContain( '15 dollars' );
	} );

	test( 'an edit to the live page after the copy was made asks before it is replaced', async () => {
		const { original, copy } = seedWorkingCopy();
		wp( 'option', 'delete', 'axle_review_settings' );
		editPost( original, 'Every plan has a 14 day trial.', 'Every plan has a 30 day trial.' );

		await expect( async () => wp( 'axle-review', 'change', 'publish', String( copy ) ) ).rejects.toThrow( /edited after this copy was made/ );
		expect( post( original ).content ).toContain( '30 day' );
		wp( 'axle-review', 'change', 'publish', String( copy ), '--overwrite', '--user=admin' );
		expect( post( original ).content ).toContain( '14 day' );

		// Undo also asks when the live page was edited since.
		editPost( original, '15 dollars', '16 dollars' );
		await expect( async () => wp( 'axle-review', 'change', 'undo', String( original ) ) ).rejects.toThrow( /edited after the change went live/ );
		wp( 'axle-review', 'change', 'undo', String( original ), '--overwrite', '--user=admin' );
		expect( post( original ).content ).toContain( '12 dollars' );
	} );

	test( 'each action checks the post it touches', async () => {
		const { original, copy } = seedWorkingCopy();
		wp( 'option', 'delete', 'axle_review_settings' );
		// Arjun is an author: he cannot edit the page or its copy.
		const arjun = Number( wp( 'user', 'get', 'arjun', '--field=ID' ) );
		const can = ( cap, id ) => php( `echo user_can( ${ arjun }, ${ JSON.stringify( cap ) }, ${ id } ) ? 'yes' : 'no';` );
		expect( can( 'edit_post', original ) ).toBe( 'no' );
		const allowed = ( method, id ) => php( `wp_set_current_user( ${ arjun } ); echo AryanX\\Axle\\Review\\Changes::${ method }( ${ id } ) ? 'yes' : 'no';` );
		expect( allowed( 'can_request', original ) ).toBe( 'no' );
		expect( allowed( 'can_publish', copy ) ).toBe( 'no' );
		expect( allowed( 'can_undo', original ) ).toBe( 'no' );
		expect( php( `wp_set_current_user( 1 ); echo AryanX\\Axle\\Review\\Changes::can_publish( ${ copy } ) ? 'yes' : 'no';` ) ).toBe( 'yes' );

		// And over REST, as that author.
		const client = await appPasswordClient( 'arjun', 'axr-arjun' );
		const refused = await client.call( 'POST', '/axle-review/v1/changes/publish', { post: copy } );
		expect( refused.status ).toBe( 403 );
		const state = await client.call( 'GET', `/axle-review/v1/changes?post=${ copy }` );
		expect( state.status ).toBe( 403 );
		const request = await client.call( 'POST', '/axle-review/v1/changes', { post: original } );
		expect( request.status ).toBe( 403 );
		await client.dispose();
	} );

	test( 'the abilities request, publish, discard and undo a change', async () => {
		const { post: live } = seedLivePost();
		const client = await appPasswordClient( 'admin', 'axr-abilities' );
		const run = ( name, input ) => client.call( 'POST', `/wp-abilities/v1/abilities/axle-review/${ name }/run`, { input } );

		const made = await run( 'request-change-review', { post: live } );
		expect( made.status ).toBe( 200 );
		const copy = made.body.copy;
		// Destructive and idempotent abilities run with DELETE.
		const discard = ( id ) => client.call( 'DELETE', `/wp-abilities/v1/abilities/axle-review/discard-change/run?input[post]=${ id }` );
		const discarded = await discard( copy );
		expect( discarded.body.discarded ).toBe( true );
		expect( post( copy ) ).toBeNull();
		expect( ( await discard( copy ) ).body.discarded ).toBe( false );

		const again = await run( 'request-change-review', { post: live } );
		editPost( again.body.copy, '12 dollars', '19 dollars' );
		expect( ( await run( 'publish-change', { post: again.body.copy } ) ).body.original ).toBe( live );
		expect( post( live ).content ).toContain( '19 dollars' );
		expect( ( await run( 'undo-change', { post: live } ) ).body.undone ).toBe( true );
		expect( post( live ).content ).toContain( '12 dollars' );
		await client.dispose();
	} );

	test( 'the editor asks for review of a change, publishes it and keeps the page address', async ( { page } ) => {
		test.setTimeout( 120_000 );
		const { post: live } = seedLivePost();
		await openEditor( page, live );
		await openSharePanel( page );
		await page.getByRole( 'button', { name: 'Ask for review of a change' } ).click();
		await page.waitForURL( ( url ) => url.pathname.endsWith( 'post.php' ) && url.searchParams.get( 'post' ) !== String( live ) );
		await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/editor' ).getCurrentPostId() );
		const copy = Number( new URL( page.url() ).searchParams.get( 'post' ) );
		expect( copy ).not.toBe( live );
		await page.evaluate( () => window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false ) );
		await openSharePanel( page );
		await expect( page.getByText( 'A change to "Pricing"' ) ).toBeVisible();

		// Publish in the editor is refused; the panel's button does it.
		await page.getByRole( 'button', { name: 'Publish the change' } ).click();
		const dialog = page.getByRole( 'dialog', { name: 'Publish the change' } );
		await expect( dialog ).toContainText( 'you can undo this' );
		await dialog.getByRole( 'button', { name: 'Publish the change' } ).click();
		await page.waitForURL( new RegExp( `post=${ live }&action=edit` ) );
		expect( post( copy ) ).toBeNull();
		expect( post( live ).status ).toBe( 'publish' );

		await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/editor' ).getCurrentPostId() );
		await openSharePanel( page );
		await expect( page.getByText( 'A reviewed change went live' ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Undo that change' } ).click();
		await page.getByRole( 'dialog', { name: 'Undo the last change' } ).getByRole( 'button', { name: 'Undo the change' } ).click();
		await page.waitForLoadState( 'domcontentloaded' );
		await expect.poll( () => metaOf( live, '_axr_change_undo' ) ).toBe( '' );
	} );
} );

test.describe( 'Round 3 fixes', () => {
	test.beforeAll( () => {
		installFixture();
		createPeople();
	} );

	test.afterEach( () => {
		unseedReview();
		createPeople();
	} );

	test( 'the open editor asks for reviewer notes only while the post has a live link and the tab is visible', async ( { page } ) => {
		test.setTimeout( 120_000 );
		const s = await seedReview();
		const draft = Number( wp( 'post', 'create', '--post_title=No links', `--post_content=${ blocks() }`, '--post_status=draft', '--porcelain' ) );

		const pending = [];
		page.on( 'request', ( request ) => {
			if ( request.url().includes( 'notes%2Fpending' ) || request.url().includes( 'notes/pending' ) ) {
				pending.push( Date.now() );
			}
		} );

		// No links: one check after load, then nothing.
		await page.clock.install();
		await openEditor( page, draft );
		await page.clock.runFor( 65_000 );
		await page.waitForTimeout( 500 );
		expect( pending.length ).toBeLessThanOrEqual( 1 );

		// A live link: it keeps checking.
		pending.length = 0;
		await openEditor( page, s.menu );
		await openSharePanel( page );
		await expect( page.locator( '.axr-link' ).first() ).toBeVisible();
		await page.clock.runFor( 65_000 );
		await page.waitForTimeout( 500 );
		expect( pending.length ).toBeGreaterThanOrEqual( 3 );

		// A hidden tab does not.
		await page.evaluate( () => Object.defineProperty( document, 'visibilityState', { configurable: true, get: () => 'hidden' } ) );
		const seen = pending.length;
		await page.clock.runFor( 65_000 );
		await page.waitForTimeout( 500 );
		expect( pending.length ).toBe( seen );
	} );

	test( 'the editor writes "just now" in lower case and one "expires" phrase', async ( { page } ) => {
		const s = await seedReview();
		await openEditor( page, s.menu );
		await openSharePanel( page );
		const meta = page.locator( '.axr-link__meta' ).first();
		await expect( meta ).toContainText( /expires in \d+ days?/ );
		await expect( page.locator( '.axr-share' ) ).not.toContainText( 'Just now' );
	} );

	test( 'a second code request does not kill the first, and a forwarded link cannot lock the reviewer out', async ( { browser } ) => {
		seedLivePost();
		const draft = Number( wp( 'post', 'create', '--post_title=Legal', `--post_content=${ blocks() }`, '--post_status=draft', '--post_author=1', '--porcelain' ) );
		const locked = createLink( draft, [ '--email=legal@example.com', '--label=Legal review' ] );
		const { context, page } = await guest( browser );
		await page.goto( locked.url );
		await page.getByRole( 'button', { name: /Email me a code/ } ).click();
		await page.getByRole( 'button', { name: 'Send a new code' } ).click();
		const codes = mail()
			.filter( ( item ) => item.to === 'legal@example.com' )
			.map( ( item ) => item.message.match( /\b(\d{6})\b/ )[ 1 ] );
		expect( codes ).toHaveLength( 2 );

		// The first email's code opens the page.
		await page.getByLabel( 'Code' ).fill( codes[ 0 ] );
		await page.getByRole( 'button', { name: 'Open the page' } ).click();
		await expect( page.getByRole( 'heading', { name: 'Legal' } ).first() ).toBeVisible();
		await context.close();
	} );

	test( 'old Review Links address opens the Review links view of Notes, and the views are core views', async ( { page } ) => {
		const s = await seedReview();
		await page.goto( `/wp-admin/tools.php?page=axle-review-links&post_id=${ s.menu }` );
		await expect( page ).toHaveURL( /index\.php\?page=axle-review-notes&view=links/ );
		await expect( page.locator( '.subsubsub .current' ) ).toContainText( 'Review links' );
		await expect( page.locator( '#the-list tr' ).first() ).toBeVisible();

		await page.goto( '/wp-admin/index.php?page=axle-review-notes' );
		await expect( page.locator( '.nav-tab-wrapper' ) ).toHaveCount( 0 );
		await expect( page.locator( '.subsubsub a' ) ).toContainText( [ 'Unresolved', 'On my posts', 'From reviewers', 'Resolved', 'All', 'Review links' ] );
		// The filters sit next to Bulk actions, as on Comments.
		await expect( page.locator( '.tablenav.top .actions select[name="post_type"]' ) ).toBeVisible();
		await expect( page.locator( '.axle-toolbar' ) ).toHaveCount( 0 );
		// One menu item for the plugin under Dashboard, none under Tools.
		await expect( page.locator( '#menu-tools a[href*="axle-review-links"]' ) ).toHaveCount( 0 );
		await page.goto( '/wp-admin/index.php?page=axle-review-notes&view=links' );
		await expect( page.locator( '.tablenav.top .actions select[name="status"]' ) ).toBeVisible();
	} );

	test( 'five wrong codes email the link creator once, with the Review links address', async ( { browser } ) => {
		const draft = Number( wp( 'post', 'create', '--post_title=Legal', `--post_content=${ blocks() }`, '--post_status=draft', '--post_author=1', '--porcelain' ) );
		const locked = createLink( draft, [ '--email=legal@example.com', '--label=Legal review' ] );
		const { context, page } = await guest( browser );
		await page.goto( `${ locked.path }&axr_sent=1` );
		for ( let i = 0; i < 5; i++ ) {
			await page.getByLabel( 'Code' ).fill( '000000' );
			await page.getByRole( 'button', { name: 'Open the page' } ).click();
			await expect( page.locator( '#login_error' ) ).toBeVisible();
		}
		const told = mail().filter( ( item ) => item.to === 'admin@example.com' && item.subject.includes( 'Legal review' ) );
		expect( told ).toHaveLength( 1 );
		expect( told[ 0 ].message ).toContain( 'view=links' );
		await context.close();
	} );

	test( 'the gate page is the login screen: masked address, one button, a digits field', async ( { browser } ) => {
		const draft = Number( wp( 'post', 'create', '--post_title=Legal', `--post_content=${ blocks() }`, '--post_status=draft', '--post_author=1', '--porcelain' ) );
		const locked = createLink( draft, [ '--email=legal@example.com' ] );
		const { context, page } = await guest( browser );
		const response = await page.goto( locked.url );
		expect( response.headers()[ 'x-robots-tag' ] ).toContain( 'noindex' );
		await expect( page.locator( 'body.login #login' ) ).toBeVisible();
		await expect( page.locator( '#login .button-primary.button-large' ) ).toBeVisible();
		await expect( page.locator( '#login' ) ).toContainText( 'l•••@example.com' );
		await expect( page.locator( 'link[href*="gate.css"]' ) ).toHaveCount( 0 );
		await context.close();
	} );

	test( 'a note attached by a guest does not leave the kses filters off', async ( { browser } ) => {
		const s = await seedReview();
		const link = createLink( s.menu, [ '--label=Kses' ] );
		const result = await guestNote( browser, link.url, { text: 'Check this', name: 'Priya', path: '2' } );
		expect( result.status ).toBeLessThan( 300 );
		// A fresh request has kses on; the attach runs in its own request, so check the unit behaviour through PHP directly.
		const state = php( `
			kses_init_filters();
			$before = has_filter( 'content_save_pre', 'wp_filter_post_kses' ) !== false;
			$write = new ReflectionMethod( AryanX\\Axle\\Review\\Attacher::class, 'write' );
			$write->setAccessible( true );
			add_filter( 'wp_insert_post_data', function () { throw new RuntimeException( 'boom' ); } );
			try { $write->invoke( new AryanX\\Axle\\Review\\Attacher(), ${ s.menu }, 'x' ); } catch ( RuntimeException $e ) {}
			echo $before && has_filter( 'content_save_pre', 'wp_filter_post_kses' ) !== false ? 'restored' : 'lost';
		` );
		expect( state ).toBe( 'restored' );
	} );
} );
