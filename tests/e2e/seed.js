// Content and helpers shared by the specs, the accessibility screens, the
// screenshots and the demo: drafts with blocks, review links, reviewer notes.
const { wp, wpEval } = require( './utils' );

const sql = ( query ) => wp( 'db', 'query', query );

const PARAGRAPHS = [
	'Our spring menu starts on 1 April, with three new single origin coffees and a seasonal cold brew.',
	'Every bag is roasted in Pune on the day it ships, so it reaches you within a week of roasting.',
	'Orders over 1,500 rupees ship free anywhere in India, and returns are free within 14 days.',
	'Visit the shop on Saturday mornings for a free tasting of the new range.',
];

/** Block markup for paragraphs, with a heading first. */
function blocks( paragraphs = PARAGRAPHS, heading = 'What is new this spring' ) {
	const parts = [ `<!-- wp:heading -->\n<h2 class="wp-block-heading">${ heading }</h2>\n<!-- /wp:heading -->` ];
	for ( const text of paragraphs ) {
		parts.push( `<!-- wp:paragraph -->\n<p>${ text }</p>\n<!-- /wp:paragraph -->` );
	}
	return parts.join( '\n\n' );
}

/** Copies the fixture into mu-plugins. */
function installFixture() {
	wpEval( 'wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . "/axle-review/tests/e2e/fixtures/axle-review-fixture.php", WPMU_PLUGIN_DIR . "/axle-review-fixture.php" );' );
}

/** Removes the fixture. */
function removeFixture() {
	wpEval( '@unlink( WPMU_PLUGIN_DIR . "/axle-review-fixture.php" );' );
}

/** Empties the plugin's tables, settings, notes and caught mail. */
function clearReview() {
	sql( 'TRUNCATE TABLE wp_axle_review_links' );
	sql( 'TRUNCATE TABLE wp_axle_review_events' );
	for ( const name of [ 'axle_review_settings', 'axle_review_paused', 'axle_review_slack', 'axle_review_slack_status', 'axle_review_cache_warning', 'axr_fixture_mail' ] ) {
		try {
			wp( 'option', 'delete', name );
		} catch ( error ) {
			// Not set.
		}
	}
	wpEval( 'foreach ( get_comments( array( "type" => "note", "status" => "all", "fields" => "ids", "number" => 1000 ) ) as $id ) { wp_delete_comment( $id, true ); }' );
	wp( 'transient', 'delete', '--all' );
}

/**
 * Creates a draft.
 *
 * @param {string} title   Title.
 * @param {string} content Block markup. Default: a heading and four paragraphs.
 * @param {string} type    Post type.
 * @return {number} Post ID.
 */
function createDraft( title = 'Spring menu announcement', content = blocks(), type = 'post' ) {
	return Number( wp( 'post', 'create', `--post_type=${ type }`, `--post_title=${ title }`, `--post_content=${ content }`, '--post_status=draft', '--post_author=1', '--porcelain' ) );
}

/**
 * Creates a review link with WP-CLI.
 *
 * @param {number}   postId Post ID.
 * @param {string[]} args   Extra options, such as '--views=1'.
 * @return {{id: number, url: string}} Link.
 */
function createLink( postId, args = [] ) {
	const url = wp( 'axle-review', 'link', 'create', String( postId ), '--porcelain', '--user=admin', ...args );
	const id = Number( wp( 'db', 'query', 'SELECT MAX(id) FROM wp_axle_review_links', '--skip-column-names' ) );
	return { id, url, path: url.replace( /^https?:\/\/[^/]+/, '' ) };
}

/**
 * Leaves a reviewer note through the guest REST route, as a logged-out browser would.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @param {string}                             url     Review link URL.
 * @param {Object}                             note    text, name, path, quote, parent.
 * @return {Promise<Object>} Response body.
 */
async function guestNote( browser, url, note ) {
	const context = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
	const page = await context.newPage();
	await page.goto( url );
	const result = await page.evaluate( async ( input ) => {
		const config = window.axleReviewGuest;
		const block = input.path ? document.querySelector( `[data-axr-block="${ input.path }"]` ) : null;
		const response = await window.fetch( config.root + 'notes', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-Axle-Review-Nonce': config.nonce },
			body: JSON.stringify( {
				link: config.link,
				text: input.text,
				name: input.name || 'Priya Nair',
				path: input.path || '',
				hash: block ? block.getAttribute( 'data-axr-hash' ) : '',
				block: block ? block.getAttribute( 'data-axr-name' ) : '',
				quote: input.quote || '',
				parent: input.parent || 0,
				render: config.render,
			} ),
		} );
		return { status: response.status, body: await response.json() };
	}, note );
	await context.close();
	return result;
}

/** The emails the fixture caught. */
function mail() {
	try {
		return JSON.parse( wp( 'option', 'get', 'axr_fixture_mail', '--format=json' ) );
	} catch ( error ) {
		return [];
	}
}

/** Creates the editor and author used by some tests. */
function createPeople() {
	for ( const [ login, role, name ] of [
		[ 'maria', 'editor', 'Maria Chen' ],
		[ 'arjun', 'author', 'Arjun Rao' ],
	] ) {
		try {
			wp( 'user', 'get', login, '--field=ID' );
		} catch ( error ) {
			wp( 'user', 'create', login, `${ login }@example.com`, `--role=${ role }`, `--display_name=${ name }`, '--user_pass=demo-password-123' );
		}
	}
}

/** Removes them. */
function removePeople() {
	for ( const login of [ 'maria', 'arjun' ] ) {
		try {
			wp( 'user', 'delete', login, '--yes', '--reassign=1' );
		} catch ( error ) {
			// Already gone.
		}
	}
}

/** Deletes every post. */
function removePosts() {
	wpEval( 'foreach ( get_posts( array( "post_type" => array( "post", "page" ), "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) ) as $id ) { wp_delete_post( $id, true ); }' );
}

/**
 * A morning of review for the screens: two drafts, three links, reviewer
 * notes, an editor reply and a resolved note.
 *
 * @param {import('@playwright/test').Browser} browser Browser, for the reviewer notes. Without it, notes are added in PHP.
 * @return {Promise<Object>} IDs and URLs.
 */
async function seedReview() {
	installFixture();
	clearReview();
	removePosts();
	createPeople();

	const menu = createDraft( 'Spring menu announcement' );
	const shipping = createDraft( 'New shipping rates', blocks( [ 'From 1 May, standard shipping costs 80 rupees on orders under 1,500 rupees.', 'Express delivery to Pune and Mumbai arrives the next working day.' ], 'Shipping from May' ), 'page' );

	const legal = createLink( menu, [ '--label=Legal review', '--expires=7d', '--email=legal@example.com' ] );
	const founder = createLink( menu, [ '--label=Founder', '--views=1' ] );
	const agency = createLink( shipping, [ '--label=Agency', '--expires=3d' ] );

	// Reviewer notes, made in PHP the way the guest route makes them.
	const php = ( postId, linkId, name, path, text, quote ) =>
		Number(
			wpEval( `
				$plugin = AryanX\\Axle\\Review\\Plugin::instance();
				$post   = get_post( ${ postId } );
				$blocks = parse_blocks( $post->post_content );
				$block  = AryanX\\Axle\\Review\\Anchor::at( $blocks, '${ path }' );
				$id     = $plugin->notes()->create_guest_note( array( 'post_id' => ${ postId }, 'link_id' => ${ linkId }, 'session' => 'seed', 'text' => ${ JSON.stringify( text ) }, 'name' => ${ JSON.stringify( name ) }, 'email' => '', 'anchor' => array( 'path' => '${ path }', 'hash' => AryanX\\Axle\\Review\\Anchor::hash( $block ), 'blockName' => $block['blockName'], 'quote' => ${ JSON.stringify( quote ) } ) ) );
				( new AryanX\\Axle\\Review\\Attacher() )->attach( $id );
				( new AryanX\\Axle\\Review\\Events() )->add( ${ linkId }, 'open' );
				( new AryanX\\Axle\\Review\\Events() )->add( ${ linkId }, 'note', $id );
				delete_comment_meta( $id, '_axr_pending_attach' );
				echo $id;
			` )
		);

	const notes = {
		price: php( menu, legal.id, 'Leela (Legal)', '3', 'Free shipping needs the terms linked here, and say it is for orders inside India only.', 'ship free anywhere in India' ),
		tasting: php( menu, founder.id, 'Anil (Founder)', '4', 'Can we say 9 to 11 am? People will ask.', 'Saturday mornings' ),
		rates: php( shipping, agency.id, 'Studio Kite', '1', 'Is 80 rupees with or without GST?', '80 rupees' ),
	};

	const reply = wp( 'axle-review', 'notes', 'add', String( menu ), '--reply-to=' + notes.tasting, '--text=Yes, 9 to 11. I will add it.', '--user=maria', '--porcelain' );
	const own = wp( 'axle-review', 'notes', 'add', String( menu ), '--block=1', '--text=Shorter intro?', '--user=maria', '--porcelain' );
	wp( 'axle-review', 'notes', 'resolve', own, '--user=admin' );
	wpEval( `wp_update_comment( array( 'comment_ID' => ${ notes.price }, 'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ), 'comment_date' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ) ) );` );

	return { menu, shipping, legal, founder, agency, notes, reply: Number( reply ), own: Number( own ) };
}

/** Undoes seedReview(). */
function unseedReview() {
	clearReview();
	removePosts();
	removePeople();
}

/** A draft and an open link, for the logged-out screens. */
function seedLink() {
	installFixture();
	clearReview();
	const post = createDraft();
	return createLink( post, [ '--label=Screen' ] );
}

/** A draft, a link that shows other reviewers' notes, and a note with an editor's reply. */
function seedLinkWithNote() {
	installFixture();
	clearReview();
	wp( 'option', 'update', 'axle_review_settings', '{"guest_sees_others":true}', '--format=json' );
	const post = createDraft();
	const link = createLink( post, [ '--label=Screen' ] );
	const note = wpEval( `
		$id = AryanX\\Axle\\Review\\Plugin::instance()->notes()->create_guest_note( array( 'post_id' => ${ post }, 'link_id' => ${ link.id }, 'session' => 'seed', 'text' => 'Can we say 9 to 11 am?', 'name' => 'Anil', 'anchor' => array( 'path' => '4', 'quote' => 'Saturday mornings' ) ) );
		( new AryanX\\Axle\\Review\\Attacher() )->attach( $id );
		echo $id;
	` );
	wp( 'axle-review', 'notes', 'add', String( post ), `--reply-to=${ note }`, '--text=Yes, 9 to 11.', '--user=admin' );
	return link;
}

/** A draft and an email-locked link. */
function seedLockedLink() {
	installFixture();
	clearReview();
	const post = createDraft();
	return createLink( post, [ '--email=legal@example.com' ] );
}

/** A draft and a revoked link. */
function seedRevokedLink() {
	const link = seedLink();
	wp( 'axle-review', 'link', 'revoke', String( link.id ) );
	return link;
}

/** Only an empty site: no notes and no links. */
function seedEmpty() {
	installFixture();
	clearReview();
	removePosts();
	return createDraft();
}

module.exports = {
	blocks,
	installFixture,
	removeFixture,
	clearReview,
	createDraft,
	createLink,
	guestNote,
	mail,
	createPeople,
	removePeople,
	removePosts,
	seedReview,
	unseedReview,
	seedLink,
	seedLinkWithNote,
	seedLockedLink,
	seedRevokedLink,
	seedEmpty,
};
