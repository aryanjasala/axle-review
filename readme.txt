=== Axle Review ===
Contributors: aryanjasala
Tags: notes, review, draft, preview, feedback
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Share drafts through private links. Reviewers without an account leave notes on blocks, approve the draft, and see what changed when they come back.

== Description ==

WordPress 6.9 added Notes: editors leave comments on blocks in the editor, reply, and resolve them. But only people with an account can see a draft, and only people who can edit it can leave a note. Clients, legal teams and outside experts usually have neither.

Draft link plugins let them read the draft. Feedback plugins let them comment, in a second comment system your editors have to check as well. Axle Review closes that gap: a reviewer opens a private link, clicks a block and writes a note, and it becomes a normal core Note on that block. Editors reply and resolve it in the Notes sidebar, like any other note. A reviewer can also approve the draft or ask for changes, and if anyone edits the draft after an approval, the approval says so.

**Review links:**

* Create links from the "Share for review" panel in the editor, under a draft in the posts list, with WP-CLI or with an ability.
* Expiry from one hour to 30 days, a view limit (open once, a few times, or unlimited), an optional password, and an optional email lock ("Only this person can open it"): the reviewer gets a one-time code at that address, so a forwarded link is no use to anyone else.
* The code and password pages are WordPress's own login screen: the same box, buttons and site icon. Step 1 shows the address with its middle hidden and one button. Step 2 shows the code field and "Send a new code". A second click does not make the first email useless.
* If someone keeps getting the code wrong, you get an email, and they cannot lock the real reviewer out.
* The link is shown once. Only a hash of it is stored.
* See when each link was opened, from which browser family, and how many notes came through it. Revoke one link or all of a post's links.
* Links stop working when the post is published, made private or trashed, and when the person who created them is deleted. A **Pause all links** button turns every link off at once after a leak.

**Review a change to a live page:**

* Links stop when a post is published, so the most common job, changing a live page, used to get no review. Choose "Ask for review of a change" on a published post and you get a working copy: a draft that points to the live page.
* Share the copy with a review link like any draft. Reviewers see it with changes marked from the live page, and the publish gate waits for approvals and open notes.
* "Publish the change" puts it live in one click. The page keeps its address, comments and categories, takes the copy's content, custom fields and featured image, and the copy's notes move over. The version that was live is kept, and "Undo that change" brings it back.
* If someone edited the live page after the copy was made, publishing asks first. It works from WP-CLI and with abilities too.

**Reviewing:**

* The reviewer sees the draft in your theme, like a published post, under a review bar that looks like WordPress.
* "Leave a note" outlines the blocks. Click one (or Tab to it and press Enter), write the note, send. Text selected in the block is quoted in the note. It works on phones too.
* Reviewers see their own notes and your replies on their next visit, in a sidebar laid out like Notes in the editor. They do not see other reviewers' notes, unless you turn that on for a link.
* **Approve** or **Request changes**, with their name and a comment. One answer per link, which they can change until the link ends. Asking for changes needs a comment.
* A reviewer who comes back sees a bar such as "5 changes since your visit on Monday". Changed blocks are outlined, new blocks are marked, removed blocks are listed, and each changed block has a button for its earlier text. Next and previous buttons jump from change to change.

**Sign-off that notices later edits:**

* The editor panel and the posts list show the review state: "2 of 3 approved" or "Changes requested by Priya", with each decision, name and time.
* Each approval remembers the blocks, title, excerpt and featured image it covered. Edit the post afterwards and the approval says "Edited since approval" and lists the blocks that changed. A note on a block does not count as an edit.
* Ask again with one click. The reviewer gets a new link, by email when the link has an address, and sees only what changed since they answered.
* Every answer is also a Note on the post, so it shows in Dashboard > Notes.

**For editors:**

* A reviewer's note is a core Note on the block they clicked, with their name. If an editor has the post open, the note shows up on its block within seconds, and saving the older copy does not drop it.
* **Dashboard > Notes**: every note on the site, and the Review links you shared, in one menu item. Unresolved, On my posts, From reviewers, Resolved, All, Review links, and Mentions me on WordPress 7.1 and later, with the filters next to Bulk actions as on Comments. Reply, resolve and reopen from the list, or open the note in the editor.
* A **Notes** column on the posts list with the open notes per post.
* **Publish gate** (off by default): a post with open notes cannot be published or scheduled until they are resolved. You can also ask for a number of approvals, and choose whether an approval of an older version still counts. Editors can publish anyway with a reason, which is added as a reply to each open note, or as a note on the post when only approvals are missing.
* **Alerts**: each user picks instant email, an hourly or daily digest, or nothing, for reviewer notes on their posts and replies to notes they started. Digests list mentions too. A Slack webhook can get a message for each reviewer note and each publish past the gate.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

Part of [Axle](https://axlewp.com/) by AryanX, a family of free plugins for running WordPress sites safely.

= Why WordPress 6.9 =

Block Notes and the Abilities API both arrived in WordPress 6.9. Mentions in notes arrived in 7.1, so the "Mentions me" view only shows from 7.1. On 6.9 and 7.0 a block holds one note thread; a reviewer note on a block that already has one goes on the first block that has none, starting with the text it was about.

= Privacy =

Reviewers leave a name, a note, an answer with a comment, and sometimes an email address. Each link keeps when it was opened, an anonymised IP address and the browser family (such as Firefox, never the full user agent) for 90 days. Opening a link sets one cookie for that link, `axr_` followed by the link number, which keeps the reviewer in that one draft for up to 12 hours. A link with a code and a password sets a second one, `axr_step_` followed by the link number, for 15 minutes. Neither is used for tracking. The plugin takes part in Tools > Export Personal Data and Tools > Erase Personal Data by email address, and adds suggested text to Settings > Privacy. Erasing replaces the reviewer's name and email on their notes with "Reviewer" and keeps the note text.

= WP-CLI =

* `wp axle-review link create <post>`, `link list`, `link revoke`, `link activity`, `link ask-again`, `link pause` and `link resume` manage links.
* `wp axle-review review status <post>` shows who approved, who asked for changes and what was edited since.
* `wp axle-review change request`, `list`, `publish`, `discard` and `undo` work with working copies of live posts.
* `wp axle-review notes list`, `show`, `add`, `resolve`, `reopen` and `reattach` work with notes.
* `wp axle-review gate check <post>`, `digest send` and `prune` cover the rest.

= For developers =

Every default, limit and message has a filter, and every event fires an action. The editor and the review page have JavaScript hooks too. Abilities let an AI agent read notes, leave notes on blocks, check whether a post is ready to publish and read the review state. No ability can approve for a reviewer. The full list is in [the reference](https://github.com/aryanjasala/axle-review/blob/main/docs/REFERENCE.md).

== External services ==

The plugin sends nothing anywhere, with one exception: a Slack incoming webhook URL, if you enter one under Settings > Writing > Review. For each new reviewer note, Slack gets the reviewer's name, the post title, the start of the note and the editor link. Each time someone publishes with open notes, it gets who, the post title and their reason. Nothing is sent when the field is empty. Slack's [terms](https://slack.com/terms-of-service) and [privacy policy](https://slack.com/trust/privacy/privacy-policy) apply.

== Frequently Asked Questions ==

= Do reviewers need an account? =

No. They never become users, and the link never logs them in. It lets them read that one draft and leave notes on it, nothing else.

= Is a draft safe from page caches? =

Review pages send `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, nofollow` and the `DONOTCACHEPAGE` constant, which page caching plugins respect. If a cache or CDN still serves a review page twice, Site Health says so. Set it to skip URLs with the `axle_review` query parameter.

= What can a reviewer see? =

The draft as your theme renders it, their own notes and the replies to them. Not other drafts, not your editors' own notes, not other reviewers' email addresses. A review page has no admin bar, and blocks that list posts only list public ones. If the draft shows other private data through a block, such as a list of users, reviewers see that too, so check before sharing.

= What if an editor deletes the block a note was on? =

The note goes on the first block, starting with the text the reviewer quoted, so the context stays.

= Does it replace the emails WordPress sends? =

No. WordPress still emails the post author about notes made in the editor and, on 7.1 and later, people who are mentioned. Axle Review emails what WordPress does not: reviewer notes, replies to notes you started, and digests.

= What happens to an approval when someone edits the post? =

It stays, and it says "Edited since approval" with the blocks that changed, in the editor panel and on Dashboard > Notes > Review links. If you turn on approvals in the publish gate, an approval of an older version does not count unless you choose that it should. Put the post back as it was and the approval is fresh again.

= Why does Ask again change the link? =

Only a hash of the link is stored, so the address cannot be sent again. Ask again gives the link a new address, emails it when the link has an email address, and shows it to you once. The old address stops working.

= Where does the earlier text of a changed block come from? =

From WordPress revisions. If revisions are off for the post type, or an old revision was pruned, the reviewer still sees which blocks changed, but not what they said before. The editor panel tells you when revisions are off.

= Do scheduled posts go through the gate? =

When they are scheduled, yes. They are not checked again when WordPress publishes them on time.

= Does it work with page builders? =

The link works with any post type that has Notes support. Notes on blocks need content made of blocks; on a page built with Elementor or Bricks the reviewer can read the page, and the review bar says notes are not available.

= What happens to my live page while a change is in review? =

Nothing. The working copy is a separate draft. The live page keeps its address, comments and categories, and stays exactly as it is until you choose "Publish the change". Then it takes the copy's content, custom fields and featured image, and the copy's notes move to it. The copy is deleted.

= Can I take a published change back? =

Yes. "Undo that change" in the panel (or `wp axle-review change undo`) puts back the title, content, excerpt, custom fields and featured image from before the change. Notes stay where they are. If the page was edited after the change went live, it asks first.

= Do the publish gate and approvals apply to a change? =

Yes. With the gate on, a change cannot go live while its copy has open notes or too few approvals, and people with the override capability can publish anyway with a reason, as for a draft.

= Does it work on multisite? =

Yes, per site. A network admin can pause review links on every site at once from Network Admin > Settings > Review Links.

== Screenshots ==

1. The "Share for review" panel in the editor: the review state, links with their views and notes, and a new link made in a dialog.
2. A reviewer leaving a note on a block of the draft.
3. Dashboard > Notes: every open note, with reply, resolve and reopen, and the views and filters laid out like Comments.
4. The editor before publishing, with the gate holding back a post that has open notes.
5. Dashboard > Notes > Review links: every link on the site, with the pause button.
6. Settings > Writing > Review: the publish gate, link defaults and Slack.
7. The page a reviewer sees after asking for a code: WordPress's own login screen, with the code field and "Send a new code".
8. A working copy of a live page in the editor: share it, then "Publish the change" when it is approved.

== Changelog ==

= 1.1.0 =
* Fix: only list and count notes on posts the user may edit
* New: a review page that looks like WordPress, with a notes sidebar and dialogs that fit on phones
* Fix: write relative times in lower case so they read well mid-sentence
* New: approvals that notice later edits, and changes since a visit
* Fix: ask before revoking review links

= 1.0.0 =
* First release.
