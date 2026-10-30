// Smoke test of WaaS Client on WordPress Playground (WordPress and PHP running inside Node: no Docker, no
// database server). Boots WordPress with WaaS Client from this checkout, the Ollie theme and the store
// template's starter pages, then calls the site-editing routes the AnyPress connector uses (signed with a
// throwaway site key), the same actions as WordPress abilities, and the "AnyPress website" MCP server once the
// owner switch turns on the MCP Adapter, and checks the answers.
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
// WordPress's MCP Adapter, as the store template ships it: installed, off until the owner turns it on.
const MCP_ADAPTER = 'https://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip';
const STARTER_PAGES_URL =
    'https://raw.githubusercontent.com/hamchowderr/waas-provisioning-api/main/templates/anypress-store/mu-plugins/anypress-patterns.php';
// A throwaway key for this test site only; real stores get theirs from the API as WAAS_SITE_KEY.
const SITE_KEY = randomBytes(24).toString('hex');
// Stands in for a signed-in user on the abilities checks: requests carrying it act as that user.
const USER_TOKEN = randomBytes(24).toString('hex');

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
        { step: 'installPlugin', pluginData: { resource: 'url', url: MCP_ADAPTER }, options: { activate: false } },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/anypress-patterns.php', data: starterPages },
        {
            // Stores get the site key as an environment variable on their container; here a must-use plugin sets it.
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/waas-test-site-key.php',
            data: `<?php putenv('WAAS_SITE_KEY=${SITE_KEY}');\n`,
        },
        {
            // Test only: X-Test-User: <token>:<login> signs the request in as that user, and a contributor exists
            // (can't edit pages or styles) to check the abilities' permissions.
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/waas-test-users.php',
            data: `<?php
add_action('init', function () {
    if (!get_user_by('login', 'contributor')) {
        wp_insert_user(['user_login' => 'contributor', 'user_pass' => wp_generate_password(), 'role' => 'contributor']);
    }
});
add_filter('determine_current_user', function ($id) {
    [$token, $login] = array_pad(explode(':', $_SERVER['HTTP_X_TEST_USER'] ?? '', 2), 2, '');
    $user = hash_equals('${USER_TOKEN}', $token) ? get_user_by('login', $login) : false;
    return $user ? $user->ID : $id;
}, 30);
`,
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

/** Runs an ability through WordPress's abilities REST API, as the given user (or nobody). */
async function ability(name, input, { as = 'admin', method } = {}) {
    const info = abilities.get(name);
    const verb = method ?? (info?.meta?.annotations?.readonly ? 'GET' : 'POST');
    let url = `${base}/wp-json/wp-abilities/v1/abilities/${name}/run`;
    const headers = { 'content-type': 'application/json' };
    if (as) headers['x-test-user'] = `${USER_TOKEN}:${as}`;
    let body;
    if (verb === 'GET') {
        if (input) url += `?${new URLSearchParams(Object.entries(input).map(([k, v]) => [`input[${k}]`, String(v)]))}`;
    } else {
        body = JSON.stringify({ input });
    }
    const res = await fetch(url, { method: verb, headers, body });
    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        data = text;
    }
    return { status: res.status, data };
}
const abilities = new Map();

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

// The Ollie promotion patterns were hidden by anypress-patterns.php until new stores moved to the AnyPress theme
// (waas-provisioning-api #163), which dropped that code; stores no longer run Ollie, so nothing hides them now.
await check('patterns: starter pages are listed', async () => {
    const res = await site('GET', '/patterns?category=anypress/pages');
    expectStatus(res, 200);
    const names = res.data.map((p) => p.name);
    expect(names.length >= 4, `only ${names.length} starter pages: ${names.join(', ')}`);
    expect(names.every((n) => n.startsWith('anypress/')), `unexpected: ${names.join(', ')}`);
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

await check('abilities: listed with all three safety hints', async () => {
    const res = await fetch(`${base}/wp-json/wp-abilities/v1/abilities?category=anypress&per_page=100`, {
        headers: { 'x-test-user': `${USER_TOKEN}:admin` },
    });
    const list = await res.json();
    expect(res.status === 200 && Array.isArray(list), `HTTP ${res.status}: ${JSON.stringify(list).slice(0, 300)}`);
    for (const a of list) abilities.set(a.name, a);
    // 18 site-editing abilities, plus check-forms and switch-forms (the forms switch, tested by forms.mjs).
    expect(list.length === 20, `${list.length} abilities: ${list.map((a) => a.name).join(', ')}`);
    const unsure = list.filter((a) => ['readonly', 'destructive', 'idempotent'].some((k) => typeof a.meta?.annotations?.[k] !== 'boolean'));
    expect(!unsure.length, `missing hints: ${unsure.map((a) => a.name).join(', ')}`);
});

await check('abilities: refused when signed out, or without the capability', async () => {
    const nobody = await ability('anypress/get-site', undefined, { as: null });
    expect(nobody.status === 401, `signed out: HTTP ${nobody.status}`);
    const contributor = await ability('anypress/update-styles', { styles: { color: { background: '#000000' } } }, { as: 'contributor' });
    expect(contributor.status === 403, `contributor: HTTP ${contributor.status}`);
});

await check('abilities: overview, then a page built and undone', async () => {
    const site = await ability('anypress/get-site');
    expectStatus(site, 200);
    expect(site.data.name === 'Playground Store' && Array.isArray(site.data.pages), JSON.stringify(site.data).slice(0, 300));
    const page = await ability('anypress/create-page', { title: 'Abilities page', content: '<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->' });
    expectStatus(page, 200);
    const id = page.data.id;
    const added = await ability('anypress/add-page-section', { id, content: '<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->' });
    expectStatus(added, 200);
    expect(added.data.outline.length === 2, `outline has ${added.data.outline.length} sections`);
    const read = await ability('anypress/get-page', { id });
    expect(read.status === 200 && read.data.content.includes('Two'), `get-page: HTTP ${read.status}`);
    const removed = await ability('anypress/remove-page-section', { id, section: 0 });
    expect(removed.status === 200 && !removed.data.content.includes('One'), `remove: HTTP ${removed.status}`);
    const revisions = await ability('anypress/list-page-revisions', { id });
    expect(revisions.status === 200 && revisions.data.length >= 2, `revisions: HTTP ${revisions.status}, ${revisions.data.length}`);
    const restored = await ability('anypress/restore-page-revision', { id, revision: revisions.data[revisions.data.length - 1].id });
    expectStatus(restored, 200);
    const after = await ability('anypress/get-page', { id });
    expect(after.data.content.includes('One') && !after.data.content.includes('Two'), 'restore did not put the first version back');
});

await check('abilities: styles, patterns and header', async () => {
    const styles = await ability('anypress/update-styles', { styles: { color: { text: '#111111' } } });
    expect(styles.status === 200 && styles.data.siteChanges.styles?.color?.text === '#111111', `update-styles: HTTP ${styles.status}`);
    const patterns = await ability('anypress/find-patterns', { category: 'anypress/pages' });
    expect(patterns.status === 200 && patterns.data.length >= 4, `find-patterns: HTTP ${patterns.status}`);
    const header = await ability('anypress/get-site-part', { area: 'header' });
    expectStatus(header, 200);
});

/**
 * One MCP request (JSON-RPC over the adapter's HTTP transport) to the "AnyPress website" server. 2025-11-25 is the
 * session handshake (initialize, then Mcp-Session-Id); 2026-07-28 is stateless and mirrors the method and tool name
 * in headers.
 */
let mcpSession;
async function mcp(method, params = {}, { as = 'admin', version = '2025-11-25' } = {}) {
    const headers = { 'content-type': 'application/json', accept: 'application/json, text/event-stream' };
    if (as) headers['x-test-user'] = `${USER_TOKEN}:${as}`;
    if (version === '2026-07-28') {
        headers['mcp-protocol-version'] = version;
        headers['mcp-method'] = method;
        if (params.name) headers['mcp-name'] = params.name;
        params = {
            ...params,
            _meta: {
                'io.modelcontextprotocol/protocolVersion': version,
                'io.modelcontextprotocol/clientCapabilities': {},
                'io.modelcontextprotocol/clientInfo': { name: 'smoke', version: '1' },
            },
        };
    } else if (method !== 'initialize' && mcpSession) {
        headers['mcp-session-id'] = mcpSession;
        headers['mcp-protocol-version'] = version;
    }
    const res = await fetch(`${base}/wp-json/mcp/anypress`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ jsonrpc: '2.0', id: Math.floor(Math.random() * 1e6), method, params }),
    });
    const session = res.headers.get('mcp-session-id');
    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        data = text;
    }
    return { status: res.status, data, session };
}
const INIT = { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'smoke', version: '1' } };

/** The tools listed, the get-site tool among them, and get-site's answer. */
async function mcpTools(version) {
    const list = await mcp('tools/list', {}, { version });
    expectStatus(list, 200);
    const tools = list.data.result?.tools ?? [];
    expect(tools.length === abilities.size && abilities.size > 0, `${tools.length} tools for ${abilities.size} abilities`);
    const getSite = tools.find((tool) => /get-site$/.test(tool.name));
    expect(getSite, `no get-site tool in ${tools.map((tool) => tool.name).join(', ')}`);
    expect(getSite.annotations?.readOnlyHint === true, `get-site annotations ${JSON.stringify(getSite.annotations)}`);
    const call = await mcp('tools/call', { name: getSite.name, arguments: {} }, { version });
    expectStatus(call, 200);
    expect(!call.data.result?.isError && JSON.stringify(call.data.result).includes('Playground Store'), `get-site: ${JSON.stringify(call.data).slice(0, 300)}`);
}

await check('MCP: no server while the owner has it off', async () => {
    const res = await mcp('initialize', {});
    expectStatus(res, 404);
});

await check('MCP: the owner turns it on from Your plan', async () => {
    // What the Turn on button posts: the McpServer::save handler, as an administrator with a valid nonce.
    const out = await cli.playground.run({
        code: `<?php
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user(1);
$_POST = $_REQUEST = ['mcp' => 'on', '_wpnonce' => wp_create_nonce('waas_client_mcp')];
add_filter('wp_redirect', function () { echo json_encode(['on' => get_option('waas_client_mcp'), 'active' => is_plugin_active('mcp-adapter/mcp-adapter.php')]); return false; });
(new WaaSClient\\Features\\McpServer())->save();
`,
    });
    const state = JSON.parse(out.text.slice(out.text.indexOf('{')));
    expect(state.on === '1' && state.active === true, `after Turn on: ${out.text.slice(0, 200)}`);
});

await check('MCP: refused when signed out, or without edit_pages', async () => {
    const out = await mcp('initialize', INIT, { as: null });
    expect(out.status === 401 || out.status === 403, `signed out: HTTP ${out.status}`);
    const contributor = await mcp('initialize', INIT, { as: 'contributor' });
    expect(contributor.status === 401 || contributor.status === 403, `contributor: HTTP ${contributor.status}`);
});

await check('MCP 2025-11-25: initialize, every site ability is a tool, get-site runs', async () => {
    const init = await mcp('initialize', INIT);
    expectStatus(init, 200);
    expect(init.data.result?.serverInfo?.name === 'AnyPress website', `serverInfo ${JSON.stringify(init.data).slice(0, 300)}`);
    mcpSession = init.session;
    await mcpTools('2025-11-25');
});

await check('MCP 2026-07-28: discover, every site ability is a tool, get-site runs', async () => {
    const discover = await mcp('server/discover', {}, { version: '2026-07-28' });
    expectStatus(discover, 200);
    expect(JSON.stringify(discover.data.result ?? {}).includes('AnyPress website'), `discover ${JSON.stringify(discover.data).slice(0, 300)}`);
    await mcpTools('2026-07-28');
});

const failed = results.filter(([ok]) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);

if (SERVE) {
    console.log(`Left running at ${base} (Ctrl+C to stop).`);
} else {
    await cli[Symbol.asyncDispose]();
    process.exit(failed ? 1 : 0);
}
