// Smoke test of WaaS Client on WordPress Playground (WordPress and PHP running inside Node: no Docker, no
// database server). Boots WordPress with WaaS Client from this checkout, the Ollie theme and the store
// template's starter pages, then calls the site-editing routes the AnyPress connector uses, signed with a
// throwaway site key, and checks the answers.
//
//   cd scripts/playground && npm ci
//   npm run smoke             # run the checks, exit 1 on a failure
//   npm run serve             # same site, left running at http://127.0.0.1:9400 to look around (admin / password)
//
// Needs Node 24.18+ and packages/waas-client/vendor (composer install in packages/waas-client).
// Environment:
//   PLAYGROUND_WP, PLAYGROUND_PHP   WordPress and PHP versions (default 7.1.3 and 8.3, what the store template uses)
//   STARTER_PAGES                   local copy of anypress-patterns.php; default: the API repo's main branch
//   PLAYGROUND_PORT                 default 9400
//
// Playground stores the database in SQLite, not MariaDB, so this checks our PHP and WordPress behaviour,
// not database-specific SQL. Final checks still belong on a real store.

import { createHash, createHmac, randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const CLIENT = resolve(ROOT, 'packages/waas-client');
const WP = process.env.PLAYGROUND_WP || '7.1.3';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9400);
const SERVE = process.argv.includes('--serve');
const OLLIE = 'https://downloads.wordpress.org/theme/ollie.1.6.5.zip';
const STARTER_PAGES_URL =
    'https://raw.githubusercontent.com/hamchowderr/waas-provisioning-api/main/templates/anypress-store/mu-plugins/anypress-patterns.php';
// A throwaway key for this test site only; real stores get theirs from the API as WAAS_SITE_KEY.
const SITE_KEY = randomBytes(24).toString('hex');

if (!existsSync(resolve(CLIENT, 'vendor/autoload.php'))) {
    console.error('packages/waas-client/vendor is missing: run composer install in packages/waas-client first.');
    process.exit(1);
}

const starterPages = process.env.STARTER_PAGES
    ? { resource: 'literal', name: 'anypress-patterns.php', contents: readFileSync(process.env.STARTER_PAGES, 'utf8') }
    : { resource: 'url', url: STARTER_PAGES_URL };

const blueprint = {
    landingPage: '/wp-admin/',
    // Only when serving: Playground's auto-login redirects cookie-less requests, and the checks must show the
    // signature alone is enough.
    login: SERVE,
    steps: [
        { step: 'installTheme', themeData: { resource: 'url', url: OLLIE }, options: { activate: true } },
        { step: 'activatePlugin', pluginPath: 'waas-client/index.php' },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/anypress-patterns.php', data: starterPages },
        {
            // Stores get the site key as an environment variable on their container; here a must-use plugin sets it.
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/waas-test-site-key.php',
            data: `<?php putenv('WAAS_SITE_KEY=${SITE_KEY}');\n`,
        },
        { step: 'setSiteOptions', options: { blogname: 'Playground Store', permalink_structure: '/%postname%/' } },
    ],
};

const cli = await runCLI({
    command: 'server',
    // The release zip from wordpress.org, as the store template's build uses (Playground's default mirror isn't
    // reachable from every network).
    wp: `https://wordpress.org/wordpress-${WP}.zip`,
    php: PHP,
    port: PORT,
    blueprint,
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/waas-client' }],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}`);

/** Calls a site route as the AnyPress API does: X-Waas-Signature over time, nonce, method, route and body hash. */
async function site(method, path, body, { sign = true } = {}) {
    const route = `/waas-client/v1/site${path}`;
    const json = body === undefined ? '' : JSON.stringify(body);
    const headers = { 'content-type': 'application/json' };
    if (sign) {
        const t = String(Math.floor(Date.now() / 1000));
        const n = randomBytes(16).toString('hex');
        const [pathname, query = ''] = route.split('?');
        const sorted = [...new URLSearchParams(query)]
            .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
            .map(([k, v]) => `${rawurlencode(k)}=${rawurlencode(v)}`)
            .join('&');
        const signed = sorted ? `${pathname}?${sorted}` : pathname;
        const payload = [t, n, method, signed, createHash('sha256').update(json).digest('hex')].join('\n');
        headers['x-waas-signature'] = `t=${t},n=${n},s=${createHmac('sha256', SITE_KEY).update(payload).digest('hex')}`;
    }
    // Pretty /wp-json/ links as stores use: with ?rest_route= WordPress counts rest_route as a query parameter,
    // which the signature then covers.
    const res = await fetch(`${base}/wp-json${route}`, { method, headers, body: json || undefined });
    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        data = text;
    }
    return { status: res.status, data };
}

/** PHP's rawurlencode: like encodeURIComponent, but also encodes ! ' ( ) *. */
function rawurlencode(value) {
    return encodeURIComponent(value).replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`);
}

const results = [];
async function check(name, fn) {
    try {
        await fn();
        results.push([true, name]);
        console.log(`  ok    ${name}`);
    } catch (error) {
        results.push([false, name]);
        console.log(`  FAIL  ${name}\n        ${error.message}${error.cause ? ` (${error.cause.message ?? error.cause})` : ''}`);
    }
}
function expect(condition, message) {
    if (!condition) throw new Error(message);
}
function expectStatus(res, status) {
    expect(res.status === status, `HTTP ${res.status} (wanted ${status}): ${JSON.stringify(res.data).slice(0, 300)}`);
}

let pageId;
let firstRevision;

await check('unsigned requests are refused', async () => {
    const res = await site('GET', '', undefined, { sign: false });
    expectStatus(res, 401);
});

await check('a reused signature is refused', async () => {
    const t = String(Math.floor(Date.now() / 1000));
    const n = randomBytes(16).toString('hex');
    const payload = [t, n, 'GET', '/waas-client/v1/site', createHash('sha256').update('').digest('hex')].join('\n');
    const header = `t=${t},n=${n},s=${createHmac('sha256', SITE_KEY).update(payload).digest('hex')}`;
    const url = `${base}/wp-json/waas-client/v1/site`;
    const first = await fetch(url, { headers: { 'x-waas-signature': header } });
    const second = await fetch(url, { headers: { 'x-waas-signature': header } });
    expect(first.status === 200 && second.status === 401, `first ${first.status}, second ${second.status}`);
});

await check('site: name and theme', async () => {
    const res = await site('GET', '');
    expectStatus(res, 200);
    expect(res.data.name === 'Playground Store', `name ${res.data.name}`);
    expect(res.data.theme === 'Ollie', `theme ${res.data.theme}`);
});

await check('patterns: starter pages are listed and Ollie promotions hidden', async () => {
    const res = await site('GET', '/patterns?category=anypress/pages');
    expectStatus(res, 200);
    const names = res.data.map((p) => p.name);
    expect(names.length >= 4, `only ${names.length} starter pages: ${names.join(', ')}`);
    expect(names.every((n) => n.startsWith('anypress/')), `unexpected: ${names.join(', ')}`);
    const all = await site('GET', '/patterns?search=download');
    expect(!all.data.some((p) => p.name === 'ollie/page-download'), 'ollie/page-download is still offered');
});

await check('pages: create a draft from a starter page', async () => {
    const list = await site('GET', '/patterns?category=anypress/pages');
    const about = list.data.find((p) => p.name.includes('about')) ?? list.data[0];
    const pattern = await site('GET', `/patterns/${about.name}`);
    expectStatus(pattern, 200);
    const res = await site('POST', '/pages', { title: 'About us', content: pattern.data.content });
    expectStatus(res, 200);
    expect(res.data.status === 'draft', `status ${res.data.status}`);
    pageId = res.data.id;
});

await check('pages: read the outline', async () => {
    const res = await site('GET', `/pages/${pageId}`);
    expectStatus(res, 200);
    expect(res.data.outline.length >= 2, `outline has ${res.data.outline.length} sections`);
    expect(res.data.modified, 'no modified time');
});

await check('sections: replace section 0, scripts are stripped', async () => {
    const content =
        '<!-- wp:heading --><h2 class="wp-block-heading">Hello from Playground</h2><!-- /wp:heading -->' +
        '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->';
    const res = await site('PUT', `/pages/${pageId}/sections/0`, { content });
    expect(res.status === 200 || res.status === 201, `HTTP ${res.status}: ${JSON.stringify(res.data).slice(0, 300)}`);
    const page = await site('GET', `/pages/${pageId}`);
    expect(page.data.content.includes('Hello from Playground'), 'new heading missing');
    expect(!page.data.content.includes('<script'), 'script survived');
});

await check('revisions: the first edit can be undone', async () => {
    const res = await site('GET', `/pages/${pageId}/revisions`);
    expectStatus(res, 200);
    // The page as create_page made it, then the edit: the first change must be undoable.
    expect(res.data.length >= 2, `${res.data.length} revision(s); the page as it was before the edit is missing`);
    firstRevision = res.data[res.data.length - 1].id;
    const restore = await site('POST', `/pages/${pageId}/revisions/${firstRevision}/restore`);
    expectStatus(restore, 200);
    const page = await site('GET', `/pages/${pageId}`);
    expect(!page.data.content.includes('Hello from Playground'), 'restore did not put the old content back');
});

await check('styles: read Ollie palette, change the background', async () => {
    const before = await site('GET', '/styles');
    expectStatus(before, 200);
    expect(Object.keys(before.data.palette).length > 0, 'no palette');
    const res = await site('PATCH', '/styles', { styles: { color: { background: '#fafafa' } } });
    expectStatus(res, 200);
    const after = await site('GET', '/styles');
    expect(after.data.siteChanges.styles?.color?.background === '#fafafa', 'background not saved');
});

await check('header and footer: readable', async () => {
    for (const area of ['header', 'footer']) {
        const res = await site('GET', `/parts/${area}`);
        expectStatus(res, 200);
    }
});

await check('menus: listed', async () => {
    const res = await site('GET', '/menus');
    expectStatus(res, 200);
    expect(Array.isArray(res.data), 'not a list');
});

const failed = results.filter(([ok]) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);

if (SERVE) {
    console.log(`Left running at ${base} (Ctrl+C to stop).`);
} else {
    await cli[Symbol.asyncDispose]();
    process.exit(failed ? 1 : 0);
}
