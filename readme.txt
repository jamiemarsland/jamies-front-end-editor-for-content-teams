=== Jamie's Front-End Editor for Content Teams ===
Contributors: jamiemarsland
Tags: frontend editing, inline editor, interactivity api, block editor
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let your content team edit page and post text directly on the front end. Click any text block to edit it — block markup is preserved.

== Description ==

Jamie's Front-End Editor for Content Teams lets your team edit page and post content directly from the front end of your WordPress site. No need to open the block editor — just click any text and start typing.

Built on the WordPress Interactivity API with no build step required.

Give your team precise, bounded editing: let them fix the copy that should change, and lock down the parts that shouldn't.

**Features:**

* Click any paragraph or heading to edit it inline, right on the live page
* Block markup is fully preserved on save
* Lock a whole page from front-end editing with a single checkbox
* Lock individual blocks or whole sections, using the block's own Lock option (padlock) or a CSS class. Locks cover everything nested inside and are enforced on save, not just hidden in the UI
* Edits are recorded as native WordPress block notes, so there's an audit trail
* Admin settings to choose which block types are editable
* Option to restrict chosen roles to front-end-only editing (no wp-admin access)
* Secure — only users with edit permissions see the editing UI

== Installation ==

1. Upload the `jamies-front-end-editor-for-content-teams` folder to `/wp-content/plugins/`, or install through the Plugins screen.
2. Activate the plugin through the Plugins menu.
3. Go to Settings > Front-End Editor to configure editable blocks and restricted roles.

== Frequently Asked Questions ==

= Does this work with any theme? =

Yes. The plugin uses the `render_block` filter and works with any block theme.

= Does it preserve block markup? =

Yes. Edits are saved per-block via a custom REST endpoint that parses and serializes blocks server-side.

= What blocks can be edited? =

By default, paragraphs and headings. You can configure this in Settings > Front-End Editor.

= Who can edit content on the front end? =

Only logged-in users who already have permission to edit the post. The editing UI is never shown to visitors.

= Can I stop my team editing certain content? =

Yes. You can lock a whole page (a checkbox in the "Front-End Editing" box when editing the page), or lock a single block/section using the block's own Lock option (the padlock in its Options menu) or by adding the CSS class "fie-no-edit" in its Advanced panel. A locked block, and everything nested inside it, becomes read-only on the front end. Locks are enforced when saving, so they can't be bypassed.

== Changelog ==

= 0.4 =
* Fixed a bug where pressing Enter while editing could insert invalid markup and cause a "block validation" error in the block editor. Line breaks are now saved cleanly.
* Concurrent editing is now safe. Front-end editing takes WordPress's native post lock, so two people (whether on the front end or in the block editor) can no longer edit the same page at the same time and silently overwrite each other. Anyone else who opens the page sees a read-only "So-and-so is currently editing this page" notice, and as a backstop the save is rejected if the content changed underneath the editor.
* Added an Advanced setting to enable front-end editing for other (non-core) block types on your site, such as blocks from your theme or other plugins. Tick the ones you want in Settings > Front-End Editor. This works best for blocks that show their text in a normal heading or paragraph tag; incompatible blocks simply do nothing, so nothing breaks.
* Documentation: the plugin description now covers the editing locks added in 0.3.

= 0.3 =
* Added editing locks for finer control over what your team can change. Lock a whole page with a checkbox in the "Front-End Editing" box, or lock a single block or section using the block's own Lock option (padlock) or the CSS class "fie-no-edit" (locks that block and everything inside it). Locks are enforced on save, not just hidden in the UI.

= 0.2 =
* Added a live demo preview (WordPress Playground blueprint).

= 0.1 =
* Initial release.
