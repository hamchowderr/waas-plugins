# AnyPress Export

Moves a WordPress site from any host into an AnyPress store.

## Using it

1. On AnyPress, choose **Move a site in**. It creates a store that waits for your site and shows an import code starting with `AP1.`. The code works for 7 days.
2. On the site you're moving, install this plugin (Plugins → Add New → Upload Plugin) and activate it.
3. Open **Tools → Move to AnyPress**, paste the code and choose **Send this site to AnyPress**. Keep the page open until the upload finishes; if it closes, open it again and it carries on where it stopped.
4. AnyPress sets the store up from the upload. When it's done, check the site on its new address, then point your domain at it. You can delete this plugin from the old site afterwards.

From the command line, in one go and with no time limit:

```
wp anypress-export send AP1.eyJ1Ijoi...
wp anypress-export status --wait
```

Nothing on the old site is changed or deleted. The export is built in a folder under `wp-content/uploads` that the web can't open, and removed once it is uploaded (or when you cancel).

## What moves

Everything in the database, and everything in `wp-content`: plugins, themes, must-use plugins, uploads and languages. Caches and backup plugins' archives are left out. On AnyPress the site keeps its own plugins and themes; drop-ins from the old host (object caches, page caches) and managed hosts' must-use plugins are removed, since the store has its own.

## Requirements

- WordPress 5.6+ and PHP 7.4+ with zlib and cURL (almost every host has both).
- Room in the uploads folder for the export (about the size of the site, compressed).
- A single site. Multisite networks can't be moved yet.
- The export must fit the AnyPress store's storage. If it doesn't, the plugin says so; choose a larger size and start again.

## Development

```
composer install
composer test
```

End to end on WordPress Playground (WordPress and PHP in Node; Node 24.18+): with `composer install --no-dev` here, `cd scripts/playground && npm ci && npm run export` builds a messy site (cache drop-in, cache and backup folders, tricky text, a table without a primary key, a 3 MB upload), moves it to a stand-in AnyPress API in short requests and checks the archive. CI runs it on every pull request.

How it works: `Exporter` runs the steps (database, file list, pack, upload, start) as a state machine whose state is saved in the `anypress_export_state` option between requests. `SqlDump` writes the database in batches, `TarGz` writes a gzipped tar that grows one gzip member per request (so a large file can be split across requests at any byte), and `Api` talks to the AnyPress API with the import code as its only credential. For a local API on plain HTTP, define `ANYPRESS_EXPORT_ALLOW_HTTP` as true.

License: GPL-3.0-or-later.
