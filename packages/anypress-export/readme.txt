=== AnyPress Export ===
Contributors: chowderr
Tags: migration, move site, export, backup, anypress
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Moves this WordPress site to AnyPress: paste the import code AnyPress gave you and the plugin sends your database and files over.

== Description ==

1. On AnyPress, choose Move a site in. It shows an import code starting with AP1.
2. Install and activate this plugin on the site you are moving.
3. Open Tools > Move to AnyPress, paste the code and choose Send this site to AnyPress. Keep the page open until the upload finishes; if it closes, open it again and it carries on where it stopped.
4. AnyPress sets the store up. Check the site on its new address, then point your domain at it.

Nothing on this site is changed or deleted. The export is built in a folder under the uploads folder that the web can't open, and removed once it is uploaded or when you cancel.

From WP-CLI: `wp anypress-export send <code>` and `wp anypress-export status --wait`.

= What it sends, and where =

The plugin sends this site's database and its `wp-content` folder (plugins, themes, must-use plugins, uploads, languages; caches and backup archives are left out) to the AnyPress address inside the import code you paste, and nowhere else. Nothing is sent until you paste a code and start. AnyPress: https://anypress.io

== Changelog ==

= 1.0.2 =
* The export page's script is a separate file and its text can be translated.
* Messages are translatable; code follows the WordPress coding and Plugin Check rules.

= 1.0.1 =
* Fix: percent signs in the database are kept as they are.

= 1.0.0 =
* First release.
