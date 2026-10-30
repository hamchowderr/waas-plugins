# AnyPress Export

Moves a WordPress site from any host into an AnyPress store.

## Using it

1. On AnyPress, choose **Move a site in**. It creates a store that waits for your site and shows an import code starting with `AP1.`. The code works for 7 days.
2. On the site you're moving, install this plugin (Plugins → Add New → Upload Plugin) and activate it.
3. Open **Tools → Move to AnyPress**. Before you start, the page checks your site and shows a short list of anything to know (see below). Paste the code and choose **Send this site to AnyPress**. Keep the page open until the upload finishes; if it closes, open it again and it carries on where it stopped.
4. AnyPress sets the store up from the upload. When it's done, check the site on its new address, then point your domain at it. You can delete this plugin from the old site afterwards.

From the command line, in one go and with no time limit (best for sites over about 1 GB, whose upload can outlast a web page's time limits):

```
wp anypress-export send AP1.eyJ1Ijoi...
wp anypress-export status --wait
```

Nothing on the old site is changed or deleted. The export is built in a folder under `wp-content/uploads` that the web can't open, and removed once it is uploaded, when you cancel, when you deactivate the plugin, or after 7 days if a move is left unfinished.

## Before you start: the site check

The Tools page lists, in plain words:

- **Before you start**: what would stop the move (no zlib or cURL for PHP, a multisite network, a media folder outside `wp-content`), too little free disk space for the export, and a hint to use the command line for a large site.
- **We'll leave these behind**: database tables that don't start with your site's table prefix, database views, triggers and stored procedures, linked folders (symbolic links) and files the server won't let the plugin read.
- **Re-add these licence keys after the move**: the names of the constants your `wp-config.php` sets beyond WordPress's own (licence keys, plugin settings). `wp-config.php` doesn't move, so add them again on AnyPress. The plugin reads only their names, never their values.
- **Check these after the move**: cache plugins to turn off (AnyPress has its own page cache), plugins that send email with your own mail settings, overdue scheduled tasks that may run right after the move, and a site address with a folder in it.

The same check goes to AnyPress with the export, together with your PHP and WordPress versions, active plugins and PHP extensions, and how many rows each table has, so the move can be checked on the other side.

If a file is deleted or changes while it's being packed, the page lists it at the end so you can check it on AnyPress.

## What moves

Everything in the database, and everything in `wp-content`: plugins, themes, must-use plugins, uploads and languages. Caches and backup plugins' archives are left out. On AnyPress the site keeps its own plugins and themes; drop-ins from the old host (object caches, page caches) and managed hosts' must-use plugins are removed, since the store has its own.

## Requirements

- WordPress 5.6+ and PHP 7.4+ with zlib and cURL (almost every host has both).
- Room in the uploads folder for the export: about the size of your media plus twice the database. The site check tells you if there isn't enough.
- A single site. Multisite networks can't be moved yet.
- The export must fit the AnyPress store's storage. If it doesn't, the plugin says so; choose a larger size and start again.

## Development

```
composer install
composer test
```

End to end on WordPress Playground (WordPress and PHP in Node; Node 24.18+): with `composer install --no-dev` here, `cd scripts/playground && npm ci && npm run export` builds a messy site (cache drop-in, cache and backup folders, tricky text, a table without a primary key, rows near 1 MB, a 3 MB upload, a licence key in `wp-config.php`, a linked folder), moves it to a stand-in AnyPress API in short requests (with an expired upload link and a lost answer to the start call along the way) and checks the archive. CI runs it on every pull request.

How it works: `Exporter` runs the steps (preflight, file list, database, pack, upload, start) as a state machine whose state is saved in the `anypress_export_state` option between requests; each step stops within the request's time budget and carries on in the next. `Preflight` gathers the site check. `FileList` lists `wp-content` with a cursor kept in the state. `SqlDump` writes the database in batches sized by bytes, in primary-key order (or by offset for tables without one), with INSERTs of about 1 MB at most and explicit column lists. `TarGz` writes a gzipped tar that grows one gzip member per request (so a large file can be split across requests at any byte) and stops on a short write. `Api` talks to the AnyPress API with the import code as its only credential, and asks for a new upload link if the old one has expired. For a local API on plain HTTP, define `ANYPRESS_EXPORT_ALLOW_HTTP` as true.

License: GPL-3.0-or-later.
