// End-to-end test of AnyPress Export on WordPress Playground. Boots WordPress with AnyPress Export from this
// checkout on a deliberately messy site (a page cache drop-in, cache and backup folders, posts full of quotes,
// backslashes and emoji, a plugin's own table without a primary key, more rows than one database batch and a
// file too big to pack in one request), then moves it to a stand-in AnyPress API run by this script, in many
// short requests as the Tools page does, and checks the archive that arrives.
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
        if (req.method === 'PUT' && req.url === '/upload/archive') {
            api.archive = body;
            return json(200, {});
        }
        const data = body.length ? JSON.parse(body.toString()) : {};
        if (data.code !== importCode) return json(401, { message: 'Unknown import code.' });
        if (req.url === '/imports/upload') {
            return json(200, { uploadUrl: `${apiBase}/upload/archive`, maxBytes: 200 * 1024 * 1024, store: { domain: 'moved.anypress.site' } });
        }
        if (req.url === '/imports/start') {
            api.source = data.source;
            return json(200, { status: 'moving' });
        }
        if (req.url === '/imports/status') return json(200, { status: 'moving', store: {} });
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
        { step: 'defineWpConfigConsts', consts: { ANYPRESS_EXPORT_ALLOW_HTTP: true } },
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
wp_mkdir_p(WP_CONTENT_DIR . '/uploads/2026/10');
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
let expected;

await check('the move runs to the end in short requests', async () => {
    expected = await php(`
global $wpdb;
echo wp_json_encode([
    'bigMd5' => md5_file(WP_CONTENT_DIR . '/uploads/2026/10/big.bin'),
    'rows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}shop_log"),
]);`);
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
        expect(!next.error, `request ${requests}: ${next.error}`);
        if (next.fileOffset > 0) splitFile = true;
        step = next.step;
    }
    expect(api.archive, 'nothing was uploaded');
    expect(api.calls.join(' ').match(/POST \/imports\/upload.*PUT \/upload\/archive.*POST \/imports\/start/), `calls: ${api.calls.join(', ')}`);
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
