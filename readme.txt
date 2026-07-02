=== Jamie's Front-End Editor for Content Teams ===
Contributors: jamiemarsland
Tags: frontend editing, inline editor, interactivity api, block editor
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let your content team edit page and post text directly on the front end. Click any text block to edit it — block markup is preserved.

== Description ==

Jamie's Front-End Editor for Content Teams lets your team edit page and post content directly from the front end of your WordPress site. No need to open the block editor — just click any text and start typing.

Built on the WordPress Interactivity API with no build step required.

**Features:**

* Click any paragraph or heading to edit it inline, right on the live page
* Block markup is fully preserved on save
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

== Changelog ==

= 0.1 =
* Initial release.
