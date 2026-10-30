// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp } = require( './utils' );
const { STORAGE_STATE } = require( './utils/config' );
const { prepareSite, recordDemo } = require( './utils/media' );
const { seedReview, unseedReview } = require( './seed' );

test( 'demo: a reviewer leaves a note on a block, and an editor resolves it', async ( { browser } ) => {
	test.setTimeout( 180_000 );
	prepareSite( 'Bean There Coffee' );
	const seeded = await seedReview();
	const { cookies } = require( STORAGE_STATE );

	try {
		await recordDemo( browser, async ( page, demo ) => {
			// The reviewer has no account.
			await page.context().clearCookies();
			await page.goto( seeded.agency.url );
			await expect( page.locator( '#axr-toolbar' ) ).toBeVisible();
			await demo.start();
			await demo.pause( 1500 );

			await demo.click( page.getByRole( 'button', { name: 'Leave a note' } ), 500 );
			const block = page.locator( '[data-axr-block="2"]' );
			await demo.moveTo( block );
			await demo.pause( 600 );
			await demo.click( block, 400 );
			const dialog = page.getByRole( 'dialog', { name: 'Leave a note' } );
			await demo.type( dialog.getByLabel( 'Your name' ), 'Studio Kite' );
			await demo.type( dialog.getByLabel( 'Your note' ), 'Can we add the cut-off time for next-day delivery?' );
			await demo.click( dialog.getByRole( 'button', { name: 'Send note' } ), 300 );
			await expect( page.locator( '.axr-toolbar__status' ) ).toHaveText( 'Note sent. Thank you.' );
			await demo.pause( 1800 );

			// The editor sees it in Dashboard > Notes, as a core Note.
			await page.context().addCookies( cookies );
			await page.goto( '/wp-admin/index.php?page=axle-review-notes&view=guests' );
			const row = page.locator( 'tr', { hasText: 'Guest: Studio Kite' } ).first();
			await expect( row ).toBeVisible();
			await demo.moveTo( row );
			await demo.pause( 2000 );

			await row.hover();
			await demo.click( row.getByRole( 'link', { name: 'Resolve' } ), 300 );
			await expect( page.locator( '.notice-success' ) ).toContainText( 'resolved' );
			expect( wp( 'comment', 'list', '--type=note', '--status=approve', '--search=cut-off time', '--format=count' ) ).toBe( '1' );
			await demo.pause( 2200 );
		} );
	} finally {
		unseedReview();
	}
} );
