// End-to-end test of AnyPress Export on WordPress Playground. Boots WordPress with AnyPress Export from this
// checkout on a deliberately messy site (a page cache drop-in, cache and backup folders, posts full of quotes,
// backslashes and emoji, a plugin's own table without a primary key, more rows than one database batch, rows near
// 1 MB, a file too big to pack in one request, a licence key in wp-config.php and a linked folder), then moves it to
// a stand-in AnyPress API run by this script, in many short requests as the Tools page does, and checks the archive
// that arrives. The stand-in API also hands out an expired upload link first and drops the answer to the first
// start, as real networks sometimes do.
//
//   cd scripts/playground && npm ci
//   npm run export
//
// Needs Node 24.18+, `tar` on the PATH, and packages/anypress-export/vendor (composer install there).
// Environment: PLAYGROUND_WP, PLAYGROUND_PHP (default 7.1.3 and 8.3), PLAYGROUND_PORT (default 9401).
//
// Playground keeps the database in SQLite behind WordPress's MySQL queries, so this checks the plugin's steps,
// resuming and packing, not MariaDB itself. Final checks still belong on a real move.

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PLUGIN = resolve(ROOT, 'packages/anypress-export');
const WP = process.env.PLAYGROUND_WP || '7.1.3';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9401);
const BIG_FILE_BYTES = 3 * 1024 * 1024;
const EXTRA_ROWS = 1200; // more than SqlDump::BATCH (500), so the table is read in several batches
// Rows as big as page-builder data: each INSERT must stay near 1 MB so the import stays under max_allowed_packet.
const BIG_ROWS = 12;
const BIG_ROW_BYTES = 700 * 1024;
const LICENCE_VALUE = 'gf-licence-value-never-exported';
// Request time budget: short, so the database, the file list and the big file are split over many requests.
const STEP_SECONDS = 0.05;

if (!existsSync(resolve(PLUGIN, 'vendor/autoload.php'))) {
    console.error('packages/anypress-export/vendor is missing: run composer install in packages/anypress-export first.');
    process.exit(1);
}

// The stand-in AnyPress API: the three import calls and the upload link.
const api = { calls: [], archive: null, source: null };
const server = createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
        const body = Buffer.concat(chunks);
        api.calls.push(`${req.method} ${req.url}`);
        const json = (status, data) => {
            res.writeHead(status, { 'content-type': 'application/json' });
            res.end(JSON.stringify(data));
        };
        if (req.method === 'PUT' && req.url.startsWith('/upload/archive')) {
            // The first upload link has "expired" (as a 12-hour link left overnight would): the plugin must ask for a new one.
            if (req.url === '/upload/archive-1') return json(403, { message: 'Request has expired' });
            api.archive = body;
            api.uploadedTo = req.url;
            return json(200, {});
        }
        const data = body.length ? JSON.parse(body.toString()) : {};
        if (data.code !== importCode) return json(401, { message: 'Unknown import code.' });
        if (req.url === '/imports/upload') {
            if (api.started) return json(409, { message: 'This site is already being moved in.' });
            api.links = (api.links || 0) + 1;
            return json(200, { uploadUrl: `${apiBase}/upload/archive-${api.links}`, maxBytes: 200 * 1024 * 1024, store: { domain: 'moved.anypress.site' } });
        }
        if (req.url === '/imports/start') {
            if (api.started) return json(409, { message: 'This site is already being moved in.' });
            // The move starts, but the answer never reaches the plugin (the request dies): its retry gets 409.
            api.started = true;
            api.source = data.source;
            return json(502, { message: 'Bad gateway' });
        }
        if (req.url === '/imports/status') return json(200, { status: api.started ? 'moving' : 'waiting', store: {} });
        json(404, { message: 'Not found' });
    });
});
await new Promise((ok) => server.listen(0, '127.0.0.1', ok));
const apiBase = `http://127.0.0.1:${server.address().port}`;
const importCode = `AP1.${Buffer.from(JSON.stringify({ u: apiBase, t: 'test-token' })).toString('base64url')}`;

// Content that tends to break exports.
const TRICKY = `Quotes ' " \` and a backslash \\ and \\' together, 50% off at /shop?q=a%20b, 日本語, emoji 🍜 and <b>bold</b>`;

const blueprint = {
    login: false,
    steps: [
        { step: 'activatePlugin', pluginPath: 'anypress-export/anypress-export.php' },
        // A licence key in wp-config.php: the preflight lists its name (never its value) to add again after the move.
        { step: 'defineWpConfigConsts', consts: { ANYPRESS_EXPORT_ALLOW_HTTP: true, GF_LICENSE_KEY: LICENCE_VALUE }, method: 'rewrite-wp-config' },
        // What the old host leaves behind, which must not move: a page cache drop-in, caches and backup archives.
        { step: 'writeFile', path: '/wordpress/wp-content/advanced-cache.php', data: '<?php // old host page cache' },
        { step: 'mkdir', path: '/wordpress/wp-content/cache/pages' },
        { step: 'writeFile', path: '/wordpress/wp-content/cache/pages/index.html', data: 'cached page' },
        { step: 'mkdir', path: '/wordpress/wp-content/updraft' },
        { step: 'writeFile', path: '/wordpress/wp-content/updraft/backup.zip', data: 'old backup' },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/old-host-helper.php', data: '<?php // a must-use plugin' },
        {
            step: 'runPHP',
            code: `<?php
require '/wordpress/wp-load.php';
global $wpdb;
wp_insert_post(['post_title' => 'Imported post', 'post_status' => 'publish', 'post_content' => wp_slash(${JSON.stringify(TRICKY)})]);
update_option('anypress_test_settings', ['nested' => ['quote' => "it's", 'slash' => '\\\\', 'emoji' => '🍜']]);
$wpdb->query("CREATE TABLE {$wpdb->prefix}shop_log (message TEXT, at DATETIME)");
for ($i = 0; $i < ${EXTRA_ROWS}; $i++) {
    $wpdb->insert($wpdb->prefix . 'shop_log', ['message' => "row $i", 'at' => '2026-10-20 00:00:00']);
}
$wpdb->query("CREATE TABLE {$wpdb->prefix}big_rows (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, data LONGTEXT, PRIMARY KEY (id))");
for ($i = 0; $i < ${BIG_ROWS}; $i++) {
    $wpdb->insert($wpdb->prefix . 'big_rows', ['data' => str_repeat(chr(97 + $i), ${BIG_ROW_BYTES})]);
}
wp_mkdir_p(WP_CONTENT_DIR . '/uploads/2026/10');
// A folder kept elsewhere and linked in: the export can't follow it, so the preflight lists it.
wp_mkdir_p('/tmp/shared-media');
@symlink('/tmp/shared-media', WP_CONTENT_DIR . '/uploads/shared');
$fh = fopen(WP_CONTENT_DIR . '/uploads/2026/10/big.bin', 'wb');
for ($i = 0; $i < ${BIG_FILE_BYTES / 65536}; $i++) fwrite($fh, random_bytes(65536));
fclose($fh);
`,
        },
    ],
};

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${WP}.zip`,
    php: PHP,
    port: PORT,
    blueprint,
    mount: [{ hostPath: PLUGIN, vfsPath: '/wordpress/wp-content/plugins/anypress-export' }],
    verbosity: 'quiet',
});
console.log(`WordPress ${WP} on PHP ${PHP}, stand-in AnyPress API at ${apiBase}`);

/** Runs PHP in the site (a request of its own, like each request from the Tools page) and returns its JSON output. */
async function php(body) {
    const res = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php';\n${body}` });
    const text = res.text;
    try {
        return JSON.parse(text);
    } catch {
        throw new Error(`PHP said: ${text.slice(0, 500)} ${res.errors ?? ''}`);
    }
}

const results = [];
async function check(name, fn) {
    try {
        await fn();
        results.push([true, name]);
        console.log(`  ok    ${name}`);
    } catch (error) {
        results.push([false, name]);
        console.log(`  FAIL  ${name}\n        ${error.message}`);
    }
}
function expect(condition, message) {
    if (!condition) throw new Error(message);
}

let requests = 0;
let splitFile = false;
let startRetried = false;
let expected;

await check('the move runs to the end in short requests', async () => {
    expected = await php(`
global $wpdb;
echo wp_json_encode([
    'bigMd5' => md5_file(WP_CONTENT_DIR . '/uploads/2026/10/big.bin'),
    'rows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}shop_log"),
    'posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),
]);`);
    const preflight = await php(`echo wp_json_encode(AnyPressExport\\Plugin::preflight(true));`);
    expect(Array.isArray(preflight.notes), `no notes for the page: ${JSON.stringify(preflight).slice(0, 300)}`);
    expect(preflight.configConstants.includes('GF_LICENSE_KEY'), `constants: ${preflight.configConstants}`);
    console.log(`        the page's notes: ${preflight.notes.map((g) => `${g.title} (${g.items.length})`).join('; ')}`);
    const begun = await php(`
try {
    $state = AnyPressExport\\Plugin::begin(${JSON.stringify(importCode)});
    echo wp_json_encode(['step' => $state['step']]);
} catch (Throwable $e) {
    echo wp_json_encode(['error' => $e->getMessage()]);
}`);
    expect(!begun.error, begun.error);
    for (let step = begun.step; step !== 'moving'; ) {
        expect(++requests < 2000, 'still not done after 2000 requests');
        const next = await php(`
try {
    $state = AnyPressExport\\Plugin::step(get_option(AnyPressExport\\Plugin::OPTION), ${STEP_SECONDS});
    echo wp_json_encode(['step' => $state['step'], 'fileOffset' => $state['fileOffset'] ?? 0] + AnyPressExport\\Exporter::progress($state));
} catch (Throwable $e) {
    echo wp_json_encode(['error' => $e->getMessage()]);
}`);
        if (next.error && next.error.includes('Bad gateway') && !startRetried) {
            startRetried = true; // the stand-in's first start; the plugin's next request must recover from its 409
            continue;
        }
        expect(!next.error, `request ${requests}: ${next.error}`);
        if (next.fileOffset > 0) splitFile = true;
        step = next.step;
    }
    expect(api.archive, 'nothing was uploaded');
    expect(
        api.calls.join(' ').match(/POST \/imports\/upload PUT \/upload\/archive-1 POST \/imports\/upload PUT \/upload\/archive-2 POST \/imports\/start POST \/imports\/start POST \/imports\/status/),
        `calls: ${api.calls.join(', ')}`,
    );
    expect(startRetried, 'the first start never failed');
    console.log(`        ${requests} requests, ${(api.archive.length / 1024 / 1024).toFixed(1)} MB uploaded`);
});

const dir = mkdtempSync(join(tmpdir(), 'anypress-export-'));
let files = [];
await check('the archive opens and holds waas.json, database.sql and wp-content', async () => {
    writeFileSync(join(dir, 'export.tar.gz'), api.archive);
    execFileSync('tar', ['-xzf', 'export.tar.gz'], { cwd: dir });
    files = execFileSync('tar', ['-tzf', 'export.tar.gz'], { cwd: dir }).toString().trim().split('\n');
    expect(files[0] === 'waas.json' && files[1] === 'database.sql', `first entries: ${files.slice(0, 3).join(', ')}`);
    const site = JSON.parse(readFileSync(join(dir, 'waas.json'), 'utf8'));
    expect(site.tablePrefix === 'wp_' && site.wordPressVersion === WP && site.multisite === false, JSON.stringify(site));
    expect(api.source?.home && api.source.sizeBytes === api.archive.length, `start sent ${JSON.stringify(api.source)}`);
});

await check('waas.json: per-table row counts and the preflight report', async () => {
    const raw = readFileSync(join(dir, 'waas.json'), 'utf8');
    const site = JSON.parse(raw);
    expect(site.tables?.wp_shop_log === EXTRA_ROWS, `wp_shop_log: ${site.tables?.wp_shop_log}`);
    expect(site.tables?.wp_big_rows === BIG_ROWS, `wp_big_rows: ${site.tables?.wp_big_rows}`);
    expect(site.tables?.wp_posts === expected.posts, `wp_posts: ${site.tables?.wp_posts}, wanted ${expected.posts}`);
    const report = site.preflight;
    expect(report && Array.isArray(report.notes), 'no preflight in waas.json');
    expect(report.configConstants.includes('GF_LICENSE_KEY'), `constants: ${report.configConstants}`);
    expect(!raw.includes(LICENCE_VALUE), 'a wp-config value went into waas.json');
    expect(report.php?.extensions?.length > 0 && report.wordPressVersion === WP, 'no PHP extensions or WordPress version');
    expect(report.plugins.some((p) => p.file === 'anypress-export/anypress-export.php'), 'active plugins missing');
    expect(report.files?.complete && report.files.uploadsBytes >= BIG_FILE_BYTES, `files: ${JSON.stringify(report.files)}`);
    expect(report.disk && 'free' in report.disk && report.disk.need > 0, `disk: ${JSON.stringify(report.disk)}`);
    const links = report.files.symlinks.map((l) => l.path);
    if (links.length) {
        expect(links.includes('uploads/shared'), `symlinks: ${links}`);
        expect(report.notes.some((g) => g.id === 'behind'), 'the linked folder is not in the notes');
    } else {
        console.log('        (this Playground could not make a symbolic link; the unit tests cover them)');
    }
});

await check('plugins, themes, must-use plugins and uploads move; caches, backups and drop-ins stay', async () => {
    const has = (prefix) => files.some((f) => f.startsWith(prefix));
    for (const kept of ['wp-content/plugins/anypress-export/', 'wp-content/themes/', 'wp-content/mu-plugins/old-host-helper.php', 'wp-content/uploads/2026/10/big.bin']) {
        expect(has(kept), `${kept} is missing`);
    }
    for (const left of ['wp-content/advanced-cache.php', 'wp-content/cache/', 'wp-content/updraft/', 'wp-content/uploads/anypress-export-']) {
        expect(!has(left), `${left} was exported`);
    }
});

await check('a file packed over several requests arrives whole', async () => {
    expect(splitFile, 'the big file fitted in one request; nothing was resumed mid-file');
    const md5 = createHash('md5').update(readFileSync(join(dir, 'wp-content/uploads/2026/10/big.bin'))).digest('hex');
    expect(md5 === expected.bigMd5, `big.bin differs (${md5}, wanted ${expected.bigMd5})`);
});

await check('database.sql: every table, every row, tricky text intact', async () => {
    const sql = readFileSync(join(dir, 'database.sql'), 'utf8');
    for (const table of ['wp_posts', 'wp_options', 'wp_shop_log']) {
        expect(sql.includes(`CREATE TABLE \`${table}\``), `no CREATE TABLE for ${table}`);
    }
    const logRows = (sql.match(/\('row \d+',/g) || []).length;
    expect(logRows === expected.rows && expected.rows === EXTRA_ROWS, `${logRows} shop_log rows, wanted ${expected.rows}`);
    // As MySQL escapes it: the text must come back exactly when the file is imported.
    const escaped = TRICKY.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
    const at = sql.indexOf('Quotes ');
    expect(sql.includes(escaped), `the imported post's text is not in the dump as written: ${sql.slice(at, at + 200)}`);
    expect(sql.includes('anypress_test_settings') && sql.includes('🍜'), 'the serialized option is missing');
    expect(sql.trimEnd().endsWith('SET FOREIGN_KEY_CHECKS = 1;'), 'the dump is not finished');
    expect(sql.includes('INSERT INTO `wp_shop_log` (`message`, `at`) VALUES'), 'INSERTs have no column list');
});

await check('big rows: every row arrives, and no INSERT is much over 1 MB', async () => {
    const sql = readFileSync(join(dir, 'database.sql'), 'latin1');
    const statements = sql.split(';\n').map((s) => s.trimStart()).filter((s) => s.startsWith('INSERT INTO `wp_big_rows`'));
    const largest = Math.max(...statements.map((s) => s.length));
    expect(statements.length === BIG_ROWS, `${statements.length} INSERTs for ${BIG_ROWS} rows of ${BIG_ROW_BYTES} bytes`);
    expect(largest < 1024 * 1024 + 1024, `an INSERT of ${largest} bytes`);
    for (let i = 0; i < BIG_ROWS; i++) {
        expect(sql.includes(String.fromCharCode(97 + i).repeat(BIG_ROW_BYTES)), `row ${i + 1} is missing or cut`);
    }
});

await check('the work folder is removed after the upload', async () => {
    const left = await php(`echo wp_json_encode(glob(wp_upload_dir(null, false)['basedir'] . '/anypress-export-*'));`);
    expect(Array.isArray(left) && left.length === 0, `left behind: ${JSON.stringify(left)}`);
});

rmSync(dir, { recursive: true, force: true });
server.close();
await cli[Symbol.asyncDispose]();

const failed = results.filter(([ok]) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
