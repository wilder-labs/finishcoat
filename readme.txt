=== Finishcoat ===
Contributors: wilderlabs
Tags: client feedback, website feedback, annotations, design review, comments
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your client finishes the page. You keep the final say. Pinned notes, replies and photo ideas on your own site, behind a review code.

== Description ==

Finishcoat turns any WordPress site into a review page for your client. They open a review link, click a Suggestions tab, and leave notes right on the page. You read them in wp-admin, reply, and decide what changes.

Everything stays on your site. Finishcoat makes no outside requests: no account, no tracking, no fonts or scripts from anywhere else. Notes, replies, photos and settings are stored in your WordPress database and uploads folder, and email alerts go out through your site's own mail.

= What your client can do =

* Leave a note on any page, or pin it to the exact spot it is about. Pins show as numbered dots.
* Reply to a note, and read your replies.
* Attach up to 3 photos to a note or a reply: a screenshot, a logo, a photo they want used.
* Try their own photo in any spot with "Try my photo here". It shows in place right away, cropped like the original, and only in their browser. Nothing is uploaded until they press "Suggest this photo", which turns it into a note pinned to that spot with a before and after pair.

= What you get =

* A Client notes menu with the list of notes, a Waiting view and a count bubble, so you see what needs an answer.
* A Photos screen with every photo, a Download original button and Delete.
* An email for each client note and reply, with photo thumbnails and a link that opens the note on the page.
* Studio mode: when you are logged in as an editor or administrator, you reply, set a status (New, Noted, In progress, Done) and delete right on the page.

= Review codes keep it private =

A visitor needs a review code to see or send notes. You get one code on activation and can add up to 10, each with a label such as "Client" or "Marketing lead". The label shows next to each note, so you know who sent what. Send each person their review link. Opening it saves the code in their browser for 90 days and removes it from the address bar.

= Who sees the tab =

Choose Everyone, Logged-in users only, or Only people who open the review link (the default, so a live site never shows the tab by accident). Skip pages by ID, or use the `[finishcoat]` shortcode. `[finishcoat board="yes"]` shows the board of every note where you place it.

= Source code =

Every file in the plugin is the original, human-readable source. Nothing is minified, compiled or bundled, and there is no build step: the JavaScript and CSS in the `assets` folder are the files we edit. The plugin's source code is also public at https://github.com/wilder-labs/finishcoat.

== Installation ==

1. Pick where the review happens. Building or redesigning? Install Finishcoat on a staging copy of the site (most hosts make one in a click), so your client reviews before anything goes public. Fine-tuning a site that's already live? Install it on the live site and keep "Only people who open the review link", so regular visitors never see the tab.
2. Install Finishcoat from Plugins > Add New, or upload the `finishcoat` folder to `/wp-content/plugins/`.
3. Activate it. A first review code, labeled Client, is made for you.
4. Go to Client notes > Settings. Check the email address for alerts and press Send test email.
5. Under Review codes, press Copy review link and send the link to your client.
6. When the client signs off, revoke their code or deactivate Finishcoat. Notes and photos stay saved unless you choose to delete them on uninstall.

== Frequently Asked Questions ==

= Should I use it on a staging site or my live site? =

Both work. For a new build or a redesign, a staging copy is best: your client reviews the finished design before launch, and nothing they try touches the public site. On a live site, keep the default "Only people who open the review link" so the tab appears only for people you send the link to, and they still need their review code. On a staging site, also tick Settings > Reading > "Discourage search engines" so the copy stays out of Google.


= Where are notes and photos stored? =

In your own WordPress site. Notes and replies are private posts in your database. Photos go to `wp-content/uploads/finishcoat/`: a display copy and a thumbnail with location and camera data removed, and the original file as sent in a `_originals` folder with a random name. On Apache a rule blocks direct access to that folder; on nginx, add a rule that denies `/wp-content/uploads/finishcoat/_originals/`. Editors download originals through wp-admin.

= Who can see the notes? =

Visitors with a working review code, and logged-in editors and administrators. Notes never appear in search results, feeds or the REST API.

= How do review codes work? =

A code is 8 letters and numbers, shown like K7QM-4XRT, with no 0, O, 1, I or L to mix up. You can rename, revoke or regenerate a code at any time. A revoked or regenerated code stops working at once, and the person is asked for a new one. Wrong codes are limited to 10 an hour per visitor.

= Does it slow my site? =

In the default mode, visitors without a review code get one small inline script of about 2 KB and nothing else. The tab's script and styles load only for people with a code and for logged-in editors. Nothing is loaded from other servers.

= Does it work with Divi, Elementor and block themes? =

Yes. The tab and panel live in their own shadow DOM, so your theme's styles do not change them and they do not change your theme. They use your theme's fonts. The tab stays hidden inside the Divi, Elementor and other page builder editors.

= What does Pro add? =

Finishcoat Pro is a separate plugin, sold at wilderlabs.studio. It puts a suggested photo in place on the page for you to approve or reset, adds a photo library your client can pick from, photo links, small text changes shown as tracked changes, a Review screen with a weekly summary, and review for more than one client site from one place. Everything in this plugin works fully without it.

= How do I contact you? =

Email finishcoat@wilderlabs.studio, or post in the WordPress.org support forum for Finishcoat.

== Privacy ==

Finishcoat makes no outside requests and sends nothing to Wilder Labs or anyone else.

* What is stored: the name a visitor types, their message, the page address and title, where a pin sits, the label of the review code they used, and any photos they send.
* Where: in your WordPress database and in `wp-content/uploads/finishcoat/`.
* How long: until you delete it. Deleting the plugin keeps everything, unless you tick "Delete all notes and photos when the plugin is deleted" in Settings.
* IP addresses: used only as a one-way hash, for rate limits, kept in a transient for at most an hour. They are never stored with a note.
* In the visitor's browser: their name, their review code (for 90 days) and whether the tab hint was shown, in localStorage. No cookies are set.
* Photos: display copies have location and camera data removed. Originals are kept as sent, so they can still hold that data, and only editors can download them.

Finishcoat adds a suggested paragraph to Settings > Privacy for your privacy policy.

== Screenshots ==

1. Your client opens one review link and sees the Suggestions tab and numbered pins.
2. A note pinned to the exact spot it is about, shown in the panel.
3. Try my photo here shows their photo in place, only in their browser.
4. The board shows a suggested photo as before and after, with your reply.
5. All client notes in wp-admin, with a Waiting view, photos and Download original.
6. A visitor needs a review code; you manage labeled codes in Settings.

== Changelog ==

= 1.0.0 =
* First release: notes, pins, replies, photos on notes, Try my photo here, the board, review codes, email alerts, the Notes and Photos screens, and Settings.

== Upgrade Notice ==

= 1.0.0 =
First release.
