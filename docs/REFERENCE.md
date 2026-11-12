# Reference

## Review links

A link is a URL like `https://example.com/?axle_review=<token>`. The token is 32 random bytes in base64url. Only its HMAC (SHA-256, keyed with `wp_salt( 'auth' )`) is stored, so the URL is shown once, when the link is made, and never again.

Each link has:

- an expiry, from one hour to 30 days (default 7 days, from Settings > Writing > Review)
- a view limit: 0 is unlimited, 1 opens once. A view is a new session, counted atomically, so two people opening a view-once link at the same moment cannot both get in. A session lasts 12 hours or until the link expires, so the reviewer can come back to it
- an optional email lock: the reviewer asks for a one-time code (6 digits, valid 10 minutes) sent to that address, then enters it. A forwarded link still asks for the code
- an optional password, stored with `wp_hash_password()`
- whether the reviewer may leave notes, and whether they see notes other reviewers left

Five wrong codes or passwords lock the link, and the visitor's IP address, out for 15 minutes. At most five codes are sent per link in 15 minutes.

A link stops working when it expires, reaches its view limit (open sessions keep working), is revoked, when its post is published, made private or trashed (`axle_review_kill_link_on_status`), when the post is deleted (its links and their activity are deleted too), or when the user who made it is deleted. **Pause all links** on Tools > Review Links turns every link off until it is resumed; on multisite a network admin can pause every site from Network Admin > Settings > Review Links.

A link that does not open shows a small page with the reason (expired, used, revoked, paused) and HTTP status 404. An unknown token gets the same page with a generic message.

## The review page

The theme renders the draft like a published post. The main query asks for the post with its own status, which `WP_Query` lets through, so nothing pretends the post is published. Every review response sends:

- `Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0` and core's no-cache headers
- `X-Robots-Tag: noindex, nofollow`, and a `noindex, nofollow` robots meta tag
- `Referrer-Policy: no-referrer`
- the `DONOTCACHEPAGE` constant, which page caching plugins respect

Comments are closed on the review page. A post with a post password still asks for it.

The page shows the draft and nothing else about the site. There is no admin bar, even for a signed-in visitor. Every query other than the main one (widgets, query loops, shortcodes) only sees public posts and attachments, so a block that lists posts cannot list other drafts. The head carries no shortlink, canonical link, REST or oEmbed discovery link, which would give away the draft's address.

The toolbar has **Leave a note**, **View notes I left**, **Approve** and **Request changes** (see Sign-off below). In note mode every block of the post is outlined: click one, or Tab to it and press Enter, to open the note popover. Escape closes the popover, then leaves note mode. Text selected in the block travels with the note as a quote. The reviewer's name is kept for the browser session.

The review render marks each block of the post with `data-axr-block` (its path), `data-axr-hash` (a fingerprint of its name and text) and `data-axr-name` (the block name), using `WP_HTML_Tag_Processor` on the block's first tag.

## Sign-off

A reviewer can answer with **Approve** or **Request changes**. Requesting changes needs a comment. Their name is asked once and stored with the link. One answer per link, and they can change it until the link ends. A read-only link (notes turned off) cannot answer (`axle_review_link_can_decide`), and one link takes at most 20 answers an hour.

Each answer is stored on the link row: the decision, name, comment, when, and a **fingerprint** of the post. The fingerprint is a hash of the title, excerpt, featured image and one hash per block (name, attributes and its own HTML). It holds no text. A Note attached to a block is not an edit: `metadata.noteId` is left out of the hash. Moving a block is not a change either.

The page sends the fingerprint of what the reviewer was looking at. If the draft changed while they read it, the answer is refused with a message to reload, so nobody approves text they have not seen.

The answer is mirrored as a Note on the post, so it shows in Dashboard > Notes (and in the "From reviewers" view): an approval is a resolved note, a request for changes is an open note, so it also counts for the publish gate. Editors get the same instant email, digest and Slack message as for any reviewer note. The note is not on a block, and reviewers never see it in their own list.

### When the post is edited later

Every save of the post, and every change of the featured image, compares the post with each approval. An approval that no longer matches is marked outdated once (`axle_review_approval_outdated`) and shows **Edited since approval** with the list of changed blocks, in the editor panel, on Tools > Review Links and on the posts list ("1 of 3 approved, 1 edited since approval"). Put the post back and the approval is fresh again.

**Ask again** (editor panel, Tools > Review Links, `wp axle-review link ask-again <id>`) gives the link a new token, because only a hash of the URL is kept. The new URL is shown once and emailed to the link's address when it has one. The old URL stops working. A link that has less than three days left is extended to three days (`axle_review_reask_days`). The reviewer then sees only what changed since their answer.

### The publish gate and approvals

Settings > Writing > Review > Publish gate has **Approvals needed** (0 asks for none) and **Approvals of an older version of the post do not count** (on by default). The gate looks at the post as it will be after the save, so an approval does not cover an edit made in the same click that publishes. A revoked link's approval never counts. Too few approvals returns the error `axle_review_approvals_missing`, HTTP 409. Users with the override capability can publish anyway with a reason, which is logged as a resolved note on the post. Nothing changes while the gate is off.

## What changed since your last visit

Each link keeps two snapshots of the post: the one from the reviewer's last finished visit, which is the baseline for this visit, and the one from the latest page load. A new session promotes the latest load to baseline, so reloading the page keeps the highlights and the next visit starts fresh. After **Ask again**, the baseline is the reviewer's answer.

On a return visit a bar says "5 changes since your visit on Monday", with **Next change** and **Previous change** (focus moves to the block, and the position is announced), **Close**, and the removed blocks and changed title, excerpt or featured image listed. Changed blocks are outlined and marked "Changed", new blocks "New". Each changed block has **Show previous text**.

The old text comes from the newest core revision with the same content as the snapshot. With revisions off for the post type (or `wp_revisions_to_keep` at 0), or when a revision was pruned, the bar still marks the changed blocks and says the earlier text is not available. The editor panel tells editors when revisions are off.

## Reviewer notes become core Notes

A note from the toolbar goes to `POST /wp-json/axle-review/v1/guest/notes`. It needs the link's signed session cookie (`axr_<link id>`, HttpOnly, SameSite=Lax) and a nonce bound to that session, has a honeypot field, a minimum time on the page (3 seconds) and a limit of 30 notes and replies per link per hour. It never sets a current user.

The note is saved with `wp_insert_comment()` as a core Note: `comment_type` `note`, `comment_approved` 0 (open), `user_id` 0, the reviewer's name, and their email (the link's address when the link is email-locked). The text is plain text, stripped of tags and capped at 2,000 characters. Then its ID goes into the block's `metadata.noteId` in `post_content`:

1. the block at the path, when its fingerprint matches
2. else any block with that fingerprint (the block moved)
3. else the first top-level block that can take a note, and the note starts with the quoted text (the block was deleted). `axle_review_guest_note_orphaned` fires

On WordPress 7.1 and later `noteId` is an array, as core writes it; on 6.9 and 7.0 it is a single number, so a block holds one note thread and a block that has one is skipped. The content is written with `wp_update_post()` under a 10-second lock, without a revision, and without kses (the request has no user, and kses would strip what editors may use, such as embeds).

**An editor with the post open.** Their next save would overwrite `post_content` and drop the new ID. So each reviewer note keeps the comment meta `_axr_pending_attach`. On every save of the post (`wp_insert_post_data`), any pending note missing from the saved content goes back on its block. The open editor also asks `GET /axle-review/v1/notes/pending` every 20 seconds and puts new notes on their blocks with `updateBlockAttributes`, so the editor shows them and its next save has them. A save that already has the note clears the flag. Flags older than a day are dropped. `wp axle-review notes reattach <post>` retries by hand.

Editors reply, resolve and reopen reviewer notes in the Notes sidebar like any note. The reviewer sees replies to their own threads (same session, or the same verified email on another link) on their next visit, and can reply in them. They do not see other reviewers' threads unless the link allows it, and never editors' own notes.

## Dashboard > Notes

Needs `edit_posts` (`axle_review_inbox_capability`). Each row is checked with `edit_post` on its post; users without `edit_others_posts` see notes on their own posts.

| View | Lists |
| --- | --- |
| Unresolved | Open top-level notes (the default) |
| Mentions me | Notes and replies that mention you (WordPress 7.1+): a `LIKE` on `user-<id>`, then `wp_get_note_mentioned_user_ids()` |
| On my posts | Notes on posts you wrote |
| From reviewers | Notes left through review links |
| Resolved | Resolved top-level notes |
| All | Open and resolved |

Filters: post type, note author, date, and post (from the Notes column). Each row: the note, its author (users by name, reviewers as "Guest: name"), the post with a link that opens the editor on that note, the block type, age, replies and status. Row actions: Open in editor, Resolve, Reopen, and an inline reply. Bulk Resolve and Reopen.

Resolve and reopen write what the editor writes: the note's status (`hold` or `approve`) and an empty child note with `_wp_note_status` (`resolved` or `reopen`). Counts are cached in the object cache (group `axle_review`) until a note changes.

## Publish gate

Off by default; Settings > Writing > Review. When on, a post with open top-level notes cannot move to `publish` or `future`:

- block editor and REST: `rest_pre_insert_{post_type}` returns the error `axle_review_unresolved_notes`, HTTP 409, with the note IDs in `data.notes`
- classic editor and code: `wp_insert_post_data` keeps the old status and the user sees a notice

Options: which post types (default: all with Notes support), whether reviewer notes count, whether notes whose block was deleted count. The editor's pre-publish panel lists the open notes with a **Show** button. Users with `edit_others_posts` (`axle_review_gate_override_capability`) can publish anyway after giving a reason; the reason is added as a reply to each open note and the override lasts 10 minutes. Scheduled posts are checked when they are scheduled, not when cron publishes them.

## Alerts

Each user picks on their profile: **Right away** (default), **Hourly digest**, **Daily digest** (8:00 site time) or **Off**.

- **Reviewer notes on my posts**: the post author by default (`axle_review_notify_recipients`)
- **Replies to notes I started**: the user who started the thread
- **Mentions** (7.1+): in digests only, since core emails mentions as they happen

Core keeps sending its own emails (the post author's note email, mention emails); Axle Review sends what core does not. Notes saved outside REST, such as reviewer notes, get no core email.

**Slack**: one incoming webhook URL per site, stored in an option and never shown in full again. A message for each new reviewer note and each publish past the gate. Failures are kept for Site Health and fire `axle_review_slack_failed`.

## Hooks

### Filters

| Hook | Use |
| --- | --- |
| `axle_review_capability` | `( string $capability, int $post_id )`. Who may create and revoke links for a post, checked as a meta capability. Default `edit_post`. An empty value keeps the default |
| `axle_review_inbox_capability` | `( string $capability )`. Who sees Dashboard > Notes and Tools > Review Links. Default `edit_posts` |
| `axle_review_settings_capability` | `( string $capability )`. Who changes the settings and pauses links. Default `manage_options` |
| `axle_review_gate_override_capability` | `( string $capability )`. Who may publish past the gate with a reason. Default `edit_others_posts` |
| `axle_review_post_types` | `( string[] $types )`. Post types with links, the gate and the Notes column. Default: types with Notes support. Unknown types are dropped |
| `axle_review_expiry_choices` | `( array $choices )`. Seconds => label for new links. Choices under a minute or over the maximum are dropped |
| `axle_review_default_expiry` | `( int $seconds )`. Default 7 days (the setting). Clamped to the maximum |
| `axle_review_max_expiry` | `( int $seconds )`. Default 30 days, at least one hour |
| `axle_review_default_max_views` | `( int $views )`. Default 0 (unlimited), from the setting |
| `axle_review_code_length` | `( int $digits )`. Default 6, 4 to 10 |
| `axle_review_code_ttl` | `( int $seconds )`. Default 600, 60 to 3600 |
| `axle_review_code_max_tries` | `( int $tries )`. Wrong codes or passwords before a 15-minute lockout. Default 5, 1 to 20 |
| `axle_review_session_ttl` | `( int $seconds )`. Default 12 hours, 5 minutes to 30 days. The link's expiry still ends it |
| `axle_review_note_max_length` | `( int $characters )`. Default 2000, 1 to 20000 |
| `axle_review_notes_per_link_per_hour` | `( int $limit )`. Default 30, 1 to 1000 |
| `axle_review_min_seconds` | `( int $seconds )`. Least time between the page loading and a note. Default 3, 0 to 60 |
| `axle_review_guest_sees_others` | `( bool $share, int $link_id )`. Whether a reviewer sees other reviewers' notes. Default: the link's setting, off |
| `axle_review_kill_link_on_status` | `( string[] $statuses )`. Post statuses that revoke a post's links. Default `publish`, `private`, `trash` |
| `axle_review_note_content` | `( string $text, array $args )`. A reviewer's note before it is saved. Tags are stripped again after |
| `axle_review_anchor` | `( array\|null $resolved, WP_Comment $note, array $anchor, array $blocks )`. Where a reviewer note goes: `path`, `orphan`. Null leaves it unattached |
| `axle_review_note_id_shape` | `( string $shape )`. `scalar` or `array` for a block with no notes yet. Default `array` on 7.1+, else `scalar` |
| `axle_review_gate_enabled` | `( bool $enabled, WP_Post $post )`. Whether the gate applies to a post |
| `axle_review_gate_counts_note` | `( bool $counts, WP_Comment $note, WP_Post $post )`. Whether one open note holds publishing back |
| `axle_review_gate_message` | `( string $message, int $count, WP_Post $post )`. The error and notice text |
| `axle_review_state_text` | `( string $text, array $counts )`. The one-line review state, such as "2 of 3 approved". Empty when nobody was asked |
| `axle_review_gate_min_approvals` | `( int $required )`. Approvals the gate wants. Default from the setting, 0, clamped to 0 to 20 |
| `axle_review_gate_fresh_only` | `( bool $fresh_only )`. Whether approvals of an older version stop counting. Default from the setting, true |
| `axle_review_gate_approvals_message` | `( string $message, array $count, WP_Post $post )`. The error and notice text when approvals are missing |
| `axle_review_link_can_decide` | `( bool $allowed, array $link )`. Whether a link may approve or ask for changes. Default: the link lets the reviewer leave notes |
| `axle_review_decisions_per_link_per_hour` | `( int $limit )`. Default 20, 1 to 200 |
| `axle_review_reask_days` | `( int $days )`. The least a link has left after Ask again. Default 3, 1 to 30 |
| `axle_review_visit_changes` | `( array\|null $changes, array $link, WP_Post $post )`. What the review page shows as changed since the last visit |
| `axle_review_inbox_views` | `( array $views )`. Key => label. Unknown keys are ignored; remove a key to hide a view |
| `axle_review_inbox_per_page` | `( int $per_page )`. Default 20, 1 to 100 |
| `axle_review_notify_recipients` | `( int[] $user_ids, string $event, array $context )`. `guest_note` or `reply`. Each user's preference still applies |
| `axle_review_digest_schedule` | `( int $hour )`. Site-time hour of the daily digest. Default 8, 0 to 23. Read when the event is scheduled |
| `axle_review_email_subject` | `( string $subject, string $event, array $context )`. `code`, `link`, `guest_note`, `reply`, `digest`. Empty keeps the default |
| `axle_review_email_message` | `( string $message, string $event, array $context )`. Plain text body. Empty keeps the default |
| `axle_review_slack_payload` | `( array $payload, string $event, array $context )`. The JSON sent to Slack. An empty array sends nothing |
| `axle_review_event_retention_days` | `( int $days )`. Link activity kept. Default 90, at least 1 |
| `axle_review_anonymize_ip` | `( bool $anonymize )`. Default true: `wp_privacy_anonymize_ip()` |
| `axle_review_review_headers` | `( array $headers )`. Headers on review pages. `Cache-Control` and `X-Robots-Tag` are always kept |
| `axle_review_toolbar_strings` | `( array $strings )`. Toolbar and popover text by key. Missing or empty keys keep the default |
| `axle_review_denied_message` | `( string $message, string $reason )`. `unknown`, `expired`, `used`, `revoked`, `paused` |
| `axle_review_editor_poll_seconds` | `( int $seconds )`. How often an open editor checks for new reviewer notes. Default 20, 5 to 600 |
| `axle_review_erase_deletes_notes` | `( bool $delete, string $email )`. Whether the privacy eraser deletes a reviewer's notes instead of anonymising them. Default false |
| `axle_review_delete_guest_notes_on_uninstall` | `( bool $delete )`. Whether uninstalling deletes reviewer notes. Default false: they are core Notes |

Example: let reviewers see each other's notes on every link, and keep links alive after publishing for a post-launch check.

```php
add_filter( 'axle_review_guest_sees_others', '__return_true' );
add_filter( 'axle_review_kill_link_on_status', function ( $statuses ) {
	return array_diff( $statuses, array( 'publish' ) );
} );
```

### Actions

| Hook | Fires |
| --- | --- |
| `axle_review_link_created` | `( array $link, string $url )` after a link was made |
| `axle_review_link_opened` | `( array $link, array $session )` when a session started |
| `axle_review_link_used_up` | `( array $link )` when a link reached its view limit |
| `axle_review_link_revoked` | `( array $link, int $by )` after a link was revoked. `$by` is 0 when publishing, trashing or deleting the creator did it |
| `axle_review_links_paused` | `( int $user_id )` after every link was paused (site or network) |
| `axle_review_links_resumed` | `( int $user_id )` after links were resumed |
| `axle_review_code_sent` | `( array $link )` after an email code was sent |
| `axle_review_code_failed` | `( array $link )` after a wrong or expired code |
| `axle_review_access_denied` | `( string $reason, array\|null $link )` when a link was refused |
| `axle_review_guest_note_created` | `( WP_Comment $note, array $link )` after a reviewer note or reply was saved |
| `axle_review_guest_note_attached` | `( WP_Comment $note, array $attached )` after it went on its block: `path`, `orphan` |
| `axle_review_guest_note_orphaned` | `( WP_Comment $note, array $anchor )` when its block was gone |
| `axle_review_note_resolved` | `( WP_Comment $note, int $user_id )` after a resolve from the inbox, an ability or WP-CLI |
| `axle_review_note_reopened` | `( WP_Comment $note, int $user_id )` after a reopen from the same places |
| `axle_review_gate_blocked` | `( WP_Post $post, WP_Comment[] $notes, string $to )` when open notes held publishing back |
| `axle_review_approved` | `( array $link, WP_Post $post, string $previous )` after a reviewer approved. `$previous` is their earlier answer, empty for the first |
| `axle_review_changes_requested` | `( array $link, WP_Post $post, string $previous )` after a reviewer asked for changes |
| `axle_review_approval_outdated` | `( array $link, WP_Post $post, array $changes )` once, when a post was edited after an approval |
| `axle_review_reask_sent` | `( array $link, int $by, bool $emailed )` after a reviewer was asked to look again |
| `axle_review_gate_approvals_blocked` | `( WP_Post $post, array $approvals, string $to )` when too few approvals held publishing back |
| `axle_review_gate_overridden` | `( WP_Post $post, int $user_id, string $reason, WP_Comment[] $notes )` after someone chose to publish anyway |
| `axle_review_digest_sent` | `( int $user_id, array $items )` after a digest email |
| `axle_review_slack_failed` | `( array\|WP_Error $response, string $event )` when Slack refused a message |

### JavaScript (`wp.hooks`)

Read when used, so scripts that load later can hook in.

| Hook | Type | Where | Use |
| --- | --- | --- | --- |
| `axleReview.expiryChoices` | filter | editor panel | `( { value, label }[] choices )`. Read each time the form opens |
| `axleReview.blockSelector` | filter | review page | `( string selector )`. Which elements count as a block in note mode. Only elements the review render marked can match |
| `axleReview.popoverFields` | filter | review page | `( string[] fields, config )`. From `name`, `email`, `note`. The note is always there; email is left out on email-locked links |
| `axleReview.inboxColumns` | filter | Dashboard > Notes | `( string[] columns )`. Columns to show. The note column always stays |
| `axleReview.linkCreated` | action | editor panel | `( result )` with `url`, `link`, `emailed` |
| `axleReview.noteSubmitted` | action | review page | `( response, element )` after a note or reply was saved |
| `axleReview.decisionSubmitted` | action | review page | `( decision )` after the reviewer approved or asked for changes |
| `axleReview.changesBar` | filter | review page | `( changes )`. What the "changed since your visit" bar lists: `changed`, `added`, `removed`, `meta` |
| `axleReview.askedAgain` | action | editor panel | `( result )` with `url`, `emailed`, `link` after a reviewer was asked again |
| `axleReview.noteAttachedInEditor` | action | editor | `( { id, clientId } )` after a reviewer note was put on its block in an open editor |

```js
wp.hooks.addFilter( 'axleReview.blockSelector', 'my-site', () => '[data-axr-name="core/paragraph"], [data-axr-name="core/image"]' );
```

## Abilities

On WordPress 6.9 and later Axle Review registers [abilities](https://developer.wordpress.org/apis/abilities/) in the `axle-review` category, for AI agents through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through `/wp-abilities/v1/`. Each is marked public. They check the same capabilities as the screens. No ability pauses links, changes settings, publishes past the gate or approves a draft: only a reviewer can approve, through their own link.

| Ability | Does | Needs |
| --- | --- | --- |
| `axle-review/list-notes` | Read-only. Notes with `status` (unresolved, resolved, all), `post`, `post_type`, `mentions` (user ID, 7.1+), `guests`, `since`, `page`, `per_page` | the inbox capability; rows filtered by `edit_post` |
| `axle-review/get-note-thread` | Read-only. `id`: the note, replies, the block it is on (name, path, text) and the resolve/reopen history | `edit_post` on its post |
| `axle-review/add-note` | `post`, `text`, and `block` (a path such as `2` or `2.0`) or `match` (text the block contains), or `reply_to`. `mentions` (user IDs) writes core mention chips on 7.1+. The note is by the current user | `edit_post` |
| `axle-review/resolve-note` | Idempotent. `id` | `edit_post` on its post |
| `axle-review/reopen-note` | Idempotent. `id` | `edit_post` on its post |
| `axle-review/get-publish-readiness` | Read-only. `post`: open notes, missing approvals, and whether the gate would hold it back | `edit_post` |
| `axle-review/get-review-state` | Read-only. `post`: each reviewer's decision, name, comment and time, whether it is out of date, and which blocks changed since | `edit_post` |
| `axle-review/list-review-links` | Read-only. `post`, `status` | the inbox capability; rows filtered by the share capability |
| `axle-review/create-review-link` | `post`, `label`, `expires`, `max_views`, `email`, `allow_notes`. Returns the URL once | the share capability on the post |
| `axle-review/revoke-review-link` | Destructive, idempotent. `id` | the share capability on the link's post |

## REST routes

Under `/wp-json/axle-review/v1/`:

| Route | For |
| --- | --- |
| `GET`, `POST /guest/notes`, `POST /guest/ping` | the review page. Session cookie plus `X-Axle-Review-Nonce` |
| `GET`, `POST /links?post=`, `DELETE /links/<id>`, `GET /links/<id>/activity`, `POST /links/revoke-all` | the editor panel. The share capability |
| `GET /notes/pending?post=`, `GET /gate?post=`, `GET /review?post=`, `POST /gate/override` | the editor. `edit_post`; the override also needs the override capability |
| `POST /links/<id>/ask-again` | the editor panel. The share capability on the link's post |
| `POST /guest/decision` | the review page. Same session and nonce as notes |

## WP-CLI

```sh
wp axle-review link create <post> [--label=<label>] [--expires=7d] [--views=1] [--email=<email>] [--password[=<password>]] [--no-notes] [--send-to=<email>] [--porcelain]
wp axle-review link list [--post=<id>] [--status=active|used|expired|revoked] [--format=<format>]
wp axle-review link revoke <id>... [--post=<id>]
wp axle-review link ask-again <id> [--porcelain]   # new URL for a reviewer who answered; emailed when the link has an address
wp axle-review review status <post> [--format=<format>]
wp axle-review link activity <id> [--format=<format>]
wp axle-review link pause
wp axle-review link resume
wp axle-review notes list [--status=unresolved|resolved|all] [--post=<id>] [--mentions=<user>] [--guests] [--format=<format>]
wp axle-review notes show <id> [--format=json|yaml]
wp axle-review notes add <post> --text=<text> [--block=<path>] [--match=<text>] [--reply-to=<id>] --user=<user>
wp axle-review notes resolve <id>... --user=<user>
wp axle-review notes reopen <id>... --user=<user>
wp axle-review notes reattach <post>
wp axle-review gate check <post> [--format=<format>]   # exits 1 when the gate would hold the post back
wp axle-review digest send [--user=<user>] [--frequency=hourly|daily]
wp axle-review prune
```

`--expires` takes `90m`, `12h`, `7d`, `2w` or seconds. A bare `--password` reads the password from STDIN.

## Site Health

Critical when the tables are missing. Recommended when:

- active links live longer than 30 days (a filter raised the maximum)
- the last Slack message failed
- a page cache served a review page twice in the last week. Each page render has an ID that the toolbar reports once
- the gate holds back drafts that have not changed in two weeks

## Data

| Where | Contents |
| --- | --- |
| `{prefix}axle_review_links` table | One row per link: post, token hash, label, creator, created, expires, view limit, views, email (for codes), password hash, notes allowed, sharing, revoked when and by whom, last opened. Sign-off adds the reviewer's name, decision, comment, when, the mirrored note, when the approval went out of date, when they were asked again, and the snapshots of what they saw (hashes only, no text) |
| `{prefix}axle_review_events` table | Link activity: `open`, `code_sent`, `code_ok`, `code_fail`, `password_fail`, `note`, `reply_seen`, `denied`, `decision`, with the time, anonymised IP, browser family and note ID. Kept 90 days |
| Comment meta on reviewer notes | `_axr_link_id`, `_axr_anchor` (path, hash, block name, quote), `_axr_pending_attach`, `_axr_email_verified`, `_axr_session` (to find a reviewer's own threads), `_axr_orphaned`, `_axr_decision` (on the note that mirrors an answer) |
| User meta | `axle_review_notify` (instant, hourly, daily, off), `axle_review_inbox_seen`, `axle_review_digest_sent`, `axle_review_steps_dismissed` |
| Options | `axle_review_settings`, `axle_review_paused`, `axle_review_slack` (webhook), `axle_review_slack_status`, `axle_review_cache_warning`, `axle_review_db_version`; site option `axle_review_network_paused` |
| Transients | Email codes, failure counters, page render IDs, gate overrides |
| Cron | `axle_review_prune` (daily), `axle_review_digest_hourly`, `axle_review_digest_daily` |

Uninstalling drops both tables and deletes the options, user meta, comment meta, transients and cron events, on every site of a network. Reviewer notes stay, since they are core Notes now; `axle_review_delete_guest_notes_on_uninstall` deletes them.

## Privacy

- **Tools > Export Personal Data** by email: a reviewer's notes, and the links locked to that address with their name, answer, comment and activity
- **Tools > Erase Personal Data**: reviewer notes keep their text, with the name and email replaced by "Reviewer" (`axle_review_erase_deletes_notes` deletes them instead); links locked to the address are revoked and forget the address, the name and the comment; their activity is deleted
- **Settings > Privacy** gets suggested text
- IP addresses are anonymised before they are stored, and only the browser family is kept, never the user agent

## Limits

- Reviewers cannot mention site users, and cannot leave inline notes on a text selection; a selection travels as a quote in the note.
- On WordPress 6.9 and 7.0 a block holds one note thread. A reviewer note on a block that already has one goes on the first block without one.
- Notes need content made of blocks. A page built with Elementor or Bricks can be shared and read, but not noted.
- Blocks that list posts only show public posts on a review page. A block that shows other private data, such as a list of users, still shows it to reviewers.
- An approval covers the blocks, title, excerpt and featured image. It does not cover post meta, terms, the slug or the date.
- Ask again changes the link's URL, so the old one stops working.
- The editor watcher polls every 20 seconds. Real-time collaboration in core is not covered yet.
- Notes on templates and the Site Editor are not supported, as in core.
