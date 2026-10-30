// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const fs = require( 'fs' );
const { test, expect, wp, openEditor } = require( './utils' );
const { STORAGE_STATE } = require( './utils/config' );
const { prepareSite, shot } = require( './utils/media' );
const { seedReview, unseedReview } = require( './seed' );

/** Opens the Share for review panel in the document sidebar. */
async function openSharePanel( page ) {
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' );
		if ( ! window.wp.data.select( 'core/editor' ).isEditorPanelOpened( 'axle-review/axle-review-share' ) ) {
			window.wp.data.dispatch( 'core/editor' ).toggleEditorPanelOpened( 'axle-review/axle-review-share' );
		}
	} );
	await expect( page.locator( '.axr-share .axr-link' ).first() ).toBeVisible();
}

test.describe( 'Screenshots', () => {
	let context;
	let page;
	let seeded;

	test.beforeAll( async ( { browser } ) => {
		test.setTimeout( 120_000 );
		prepareSite( 'Bean There Coffee' );
		seeded = await seedReview();
		context = await browser.newContext( { storageState: STORAGE_STATE, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		page = await context.newPage();
	} );

	test.afterAll( async () => {
		await context.close();
		unseedReview();
	} );

	test( '1: the Share for review panel', async () => {
		await openEditor( page, seeded.menu );
		await openSharePanel( page );
		await page.getByLabel( 'Label' ).fill( 'Agency' );
		await page.getByLabel( 'Opens' ).selectOption( '1' );
		await page.getByRole( 'button', { name: 'Create link' } ).click();
		await expect( page.locator( '.axr-new-url' ) ).toBeVisible();
		await page.locator( '.axr-share' ).evaluate( ( panel ) => panel.scrollIntoView() );
		await shot( page, 1 );
	} );

	test( '2: a reviewer leaving a note', async ( { browser } ) => {
		const reviewer = await browser.newContext( { storageState: { cookies: [], origins: [] }, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		const tab = await reviewer.newPage();
		await tab.goto( seeded.agency.url );
		await tab.getByRole( 'button', { name: 'Leave a note' } ).click();
		const block = tab.locator( '[data-axr-block="2"]' );
		await block.scrollIntoViewIfNeeded();
		await block.click();
		const dialog = tab.getByRole( 'dialog', { name: 'Leave a note' } );
		await dialog.getByLabel( 'Your name' ).fill( 'Studio Kite' );
		await dialog.getByLabel( 'Your note' ).fill( 'Can we add the cut-off time for next-day delivery here?' );
		await block.hover();
		await shot( tab, 2, { keepHover: true } );
		await reviewer.close();
	} );

	test( '3: Dashboard > Notes', async () => {
		await page.goto( '/wp-admin/index.php?page=axle-review-notes' );
		await expect( page.locator( '.axr-inbox tbody tr' ) ).toHaveCount( 3 );
		await shot( page, 3 );
	} );

	test( '4: the gate before publishing', async () => {
		wp( 'option', 'update', 'axle_review_settings', '{"gate_enabled":true}', '--format=json' );
		await openEditor( page, seeded.menu );
		await page.getByRole( 'button', { name: 'Publish', exact: true } ).first().click();
		await expect( page.locator( '.axr-gate-panel .axr-override' ) ).toBeVisible();
		await page.locator( '.axr-gate-panel' ).getByLabel( 'Reason to publish with open notes' ).fill( 'Legal confirmed the shipping terms by phone.' );
		await shot( page, 4 );
		wp( 'option', 'delete', 'axle_review_settings' );
	} );

	test( '5: Tools > Review Links', async () => {
		await page.goto( '/wp-admin/tools.php?page=axle-review-links' );
		await expect( page.locator( '.axr-links tbody tr' ).first() ).toBeVisible();
		await shot( page, 5 );
	} );

	test( '6: Settings > Writing > Review', async () => {
		wp( 'option', 'update', 'axle_review_settings', '{"gate_enabled":true,"gate_post_types":["post"]}', '--format=json' );
		await page.goto( '/wp-admin/options-writing.php#axle-review' );
		await page.locator( '#axle-review' ).scrollIntoViewIfNeeded();
		await page.evaluate( () => window.scrollBy( 0, -60 ) );
		await shot( page, 6 );
		wp( 'option', 'delete', 'axle_review_settings' );
		expect( fs.existsSync( require( 'path' ).join( __dirname, '..', '..', '.wordpress-org', 'screenshot-6.png' ) ) ).toBe( true );
	} );
} );
