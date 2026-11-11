// Smoke test of AnyPress Client on WordPress Playground (WordPress and PHP running inside Node: no Docker, no
// database server). Boots WordPress with AnyPress Client from this checkout, the AnyPress theme and the store
// template's starter pages, then calls the site-editing routes the AnyPress connector uses (signed with a
// throwaway site key), the same actions as WordPress abilities, and the "AnyPress website" MCP server once the
// owner switch turns on the MCP Adapter, and the dashboard's "Log in to WordPress" link (Api\AdminLogin), and checks the answers.
//
//   cd scripts/playground && npm ci
//   npm run smoke             # run the checks, exit 1 on a failure
//   npm run serve             # same site, left running at http://127.0.0.1:9400 to look around (admin / password)
//
// Needs Node 24.18+ and packages/anypress-client/vendor (composer install in packages/anypress-client).
// Environment:
//   PLAYGROUND_WP, PLAYGROUND_PHP   WordPress and PHP versions (default 7.1.3 and 8.3, what the store template uses)
//   STARTER_PAGES                   local copy of anypress-patterns.php; default: the API repo's main branch
//   PLAYGROUND_PORT                 default 9400
//
// Playground stores the database in SQLite, not MariaDB, so this checks our PHP and WordPress behaviour,
// not database-specific SQL. Final checks still belong on a real store.

import { createHash, createHmac, randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const WP = process.env.PLAYGROUND_WP || '7.1.4';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9400);
const SERVE = process.argv.includes('--serve');
// The AnyPress theme, as new stores get it (the preview zip, kept at the latest release).
const THEME = resolve(ROOT, 'scripts/playground/preview-zips/anypress.zip');
// AnyPress Blocks, which every store has (carousel, popup, counter...): the preview zip too.
const BLOCKS = resolve(ROOT, 'scripts/playground/preview-zips/anypress-blocks.zip');
// WordPress's MCP Adapter, as the store template ships it: installed, off until the owner turns it on.
const MCP_ADAPTER = 'https://downloads.wordpress.org/plugin/mcp-adapter.0.7.1.zip';
const STARTER_PAGES_URL =
    'https://raw.githubusercontent.com/hamchowderr/waas-provisioning-api/main/templates/anypress-store/mu-plugins/anypress-patterns.php';
// A throwaway key for this test site only; real stores get theirs from the API as ANYPRESS_SITE_KEY.
const SITE_KEY = randomBytes(24).toString('hex');
// Stands in for a signed-in user on the abilities checks: requests carrying it act as that user.
const USER_TOKEN = randomBytes(24).toString('hex');

if (!existsSync(resolve(CLIENT, 'vendor/autoload.php'))) {
    console.error('packages/anypress-client/vendor is missing: run composer install in packages/anypress-client first.');
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
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'installPlugin', pluginData: { resource: 'literal', name: 'anypress-blocks.zip', contents: readFileSync(BLOCKS) }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: MCP_ADAPTER }, options: { activate: false } },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/anypress-patterns.php', data: starterPages },
        // The business details a purchase leaves on a store, with the checkout's optional "what does your business do".
        {
            step: 'setSiteOptions',
            options: {
                anypress_store_owner: JSON.stringify({ name: 'Rosa Diaz', phone: '555-0100', city: 'Austin', state: 'TX', country: 'US', about: 'A small bakery: sourdough and cakes to order.' }),
                anypress_tenant_roles: ['website'],
            },
        },
        {
            // Stores get the site key as an environment variable on their container; here a must-use plugin sets it.
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/anypress-test-site-key.php',
            data: `<?php putenv('ANYPRESS_SITE_KEY=${SITE_KEY}');\n`,
        },
        {
            // Test only: X-Test-User: <token>:<login> signs the request in as that user, and a contributor exists
            // (can't edit pages or styles) to check the abilities' permissions.
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/anypress-test-users.php',
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
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/anypress-client' }],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}`);

/** Calls a site route as the AnyPress API does: X-AnyPress-Signature over time, nonce, method, route and body hash. */
async function site(method, path, body, { sign = true } = {}) {
    const route = `/anypress-client/v1/site${path}`;
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
        headers['x-anypress-signature'] = `t=${t},n=${n},s=${createHmac('sha256', SITE_KEY).update(payload).digest('hex')}`;
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
    const payload = [t, n, 'GET', '/anypress-client/v1/site', createHash('sha256').update('').digest('hex')].join('\n');
    const header = `t=${t},n=${n},s=${createHmac('sha256', SITE_KEY).update(payload).digest('hex')}`;
    const url = `${base}/wp-json/anypress-client/v1/site`;
    const first = await fetch(url, { headers: { 'x-anypress-signature': header } });
    const second = await fetch(url, { headers: { 'x-anypress-signature': header } });
    expect(first.status === 200 && second.status === 401, `first ${first.status}, second ${second.status}`);
});

/** A "Log in to WordPress" link as the AnyPress API makes it (src/wplogin.ts): t, n, s over LOGIN and the route. */
function loginLink(key = SITE_KEY, t = String(Math.floor(Date.now() / 1000))) {
    const route = '/anypress-client/v1/admin-login';
    const n = randomBytes(16).toString('hex');
    const payload = [t, n, 'LOGIN', route, createHash('sha256').update('').digest('hex')].join('\n');
    return `${base}/wp-json${route}?${new URLSearchParams({ t, n, s: createHmac('sha256', key).update(payload).digest('hex') })}`;
}

await check('log in to WordPress: a signed link signs in once and opens wp-admin', async () => {
    const link = loginLink();
    const first = await fetch(link, { redirect: 'manual' });
    // As a browser keeps them: WordPress first clears every auth cookie, then sets the new ones, so the last one wins.
    const jar = new Map(first.headers.getSetCookie().map((c) => c.split(';')[0].split('=')).map(([k, ...v]) => [k, v.join('=')]));
    const cookies = [...jar].filter(([, v]) => v !== '%20').map(([k, v]) => `${k}=${v}`);
    expect(first.status === 302, `first: HTTP ${first.status}`);
    expect(new URL(first.headers.get('location'), base).pathname === '/wp-admin/', `went to ${first.headers.get('location')}`);
    expect(cookies.some((c) => c.startsWith('wordpress_logged_in_')), `cookies: ${cookies.join('; ')}`);
    // The cookie it set really is a signed-in admin session.
    const admin = await fetch(`${base}/wp-admin/`, { headers: { cookie: cookies.join('; ') }, redirect: 'manual' });
    expect(admin.status === 200, `wp-admin with the cookie: HTTP ${admin.status} to ${admin.headers.get('location')}`);
    const again = await fetch(link, { redirect: 'manual' });
    expect(again.status === 403 && !again.headers.getSetCookie().some((c) => c.startsWith('wordpress_logged_in_') && !c.includes('=%20')), `used twice: HTTP ${again.status}`);
});

await check('log in to WordPress: unsigned, wrong-key and old links are refused', async () => {
    for (const [what, link] of [
        ['unsigned', `${base}/wp-json/anypress-client/v1/admin-login`],
        ['another key', loginLink(randomBytes(24).toString('hex'))],
        ['10 minutes old', loginLink(SITE_KEY, String(Math.floor(Date.now() / 1000) - 600))],
    ]) {
        const res = await fetch(link, { redirect: 'manual' });
        expect(res.status === 403, `${what}: HTTP ${res.status}`);
        expect(!res.headers.getSetCookie().some((c) => c.startsWith('wordpress_logged_in_') && !c.includes('=%20')), `${what}: signed in`);
    }
});

await check('site: name and theme', async () => {
    const res = await site('GET', '');
    expectStatus(res, 200);
    expect(res.data.name === 'Playground Store', `name ${res.data.name}`);
    expect(res.data.theme === 'AnyPress', `theme ${res.data.theme}`);
});

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

await check('styles: read the theme palette, change the background', async () => {
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

await check('business: what the site says, and the checkout answer as what the business does', async () => {
    const res = await site('GET', '/business');
    expectStatus(res, 200);
    const { site: facts, about } = res.data;
    expect(facts.name === 'Playground Store', `name ${facts.name}`);
    expect(facts.siteType === 'website', `siteType ${facts.siteType}`);
    expect(facts.owner?.name === 'Rosa Diaz' && facts.owner?.city === 'Austin' && facts.owner?.country === 'United States', `owner ${JSON.stringify(facts.owner)}`);
    expect(typeof facts.language === 'string' && typeof facts.timezone === 'string', 'language and time zone');
    expect(about.what === 'A small bakery: sourdough and cakes to order.', `what ${about.what}`);
});

await check('business: the owner’s facts are saved, cleaned, cleared and undone', async () => {
    const saved = await site('PATCH', '/business', { about: { who: 'Families in north Austin<script>x</script>', hours: 'Tue–Sat 7–3', voice: 'warm' } });
    expectStatus(saved, 200);
    expect(saved.data.about.who === 'Families in north Austin', `who ${saved.data.about.who}`);
    expect(saved.data.about.hours === 'Tue–Sat 7–3', `hours ${saved.data.about.hours}`);
    expect(saved.data.updated, 'no updated time');
    const cleared = await site('PATCH', '/business', { about: { voice: '' } });
    expect(cleared.data.about.voice === undefined, 'voice not cleared');
    const undone = await site('POST', '/business/undo');
    expect(undone.data.about.voice === 'warm', `undo gave ${JSON.stringify(undone.data.about)}`);
    expectStatus(await site('PATCH', '/business', { about: { nothing: 'x' } }), 400);
    expectStatus(await site('GET', '/business', undefined, { sign: false }), 401);
});

await check('abilities: listed with all three safety hints', async () => {
    const res = await fetch(`${base}/wp-json/wp-abilities/v1/abilities?category=anypress&per_page=100`, {
        headers: { 'x-test-user': `${USER_TOKEN}:admin` },
    });
    const list = await res.json();
    expect(res.status === 200 && Array.isArray(list), `HTTP ${res.status}: ${JSON.stringify(list).slice(0, 300)}`);
    for (const a of list) abilities.set(a.name, a);
    // 23 site-editing abilities and 1 guide (an MCP resource), plus check-forms and switch-forms (the forms switch, tested by forms.mjs),
    // check-shop and switch-shop (shop-switch.mjs), check-crm and switch-crm (crm-switch.mjs), and check- and switch-
    // support, affiliate and community (moving.mjs), check-course and switch-course (courses.mjs), and check-circle and
    // switch-circle (circle.mjs), check-calendly and switch-calendly (calendly.mjs), check-memberpress and
    // switch-memberpress (memberpress.mjs), and get-languages and translate-page (languages.mjs).
    expect(list.length === 46, `${list.length} abilities: ${list.map((a) => a.name).join(', ')}`);
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

await check('library: pattern categories name the kits and shared sections', async () => {
    const res = await site('GET', '/patterns/categories');
    expectStatus(res, 200);
    const names = res.data.map((c) => c.name);
    expect(names.includes('anypress/pages'), `no starter pages in ${names.join(', ')}`);
    expect(names.filter((n) => n.startsWith('anypress/kit-')).length >= 16, `kit categories: ${names.filter((n) => n.startsWith('anypress/kit-')).join(', ')}`);
    expect(names.some((n) => n.startsWith('anypress/blocks-')), 'no anypress/blocks-* categories');
    const ridge = res.data.find((c) => c.name === 'anypress/kit-home-services');
    expect(ridge?.patterns > 0 && /Ridge/.test(ridge.label), `home services kit: ${JSON.stringify(ridge)}`);
});

await check('library: AnyPress Blocks are listed with their settings and an example pattern', async () => {
    const res = await site('GET', '/blocks');
    expectStatus(res, 200);
    const carousel = res.data.find((b) => b.name === 'anypress/carousel');
    expect(carousel && carousel.settings?.perView?.type === 'number', `carousel: ${JSON.stringify(carousel)}`);
    expect(carousel.usedIn?.length > 0, 'carousel has no example pattern');
    const popup = res.data.find((b) => b.name === 'anypress/popup');
    expect(popup, `blocks: ${res.data.map((b) => b.name).join(', ')}`);
    expect(!res.data.some((b) => !b.name.startsWith('anypress/')), 'lists blocks that are not AnyPress Blocks');
});

await check('library: style variations list kits, palettes, fonts and section styles', async () => {
    const res = await site('GET', '/styles/variations');
    expectStatus(res, 200);
    const kinds = (kind) => res.data.variations.filter((v) => v.kind === kind);
    expect(kinds('kit').length >= 16, `kits: ${kinds('kit').map((v) => v.slug).join(', ')}`);
    expect(kinds('colors').length >= 6 && kinds('fonts').length >= 4, `colors ${kinds('colors').length}, fonts ${kinds('fonts').length}`);
    expect(kinds('kit').every((v) => v.colors?.primary), 'a kit without its colours');
    const noHome = kinds('kit').filter((v) => !v.homePage || !v.patternCategory).map((v) => v.slug);
    expect(noHome.length === 0, `kits without their home page or category: ${noHome.join(', ')}`);
    const classes = res.data.blockStyles.map((s) => s.className);
    expect(classes.includes('is-style-section-dark'), `block styles: ${classes.join(', ')}`);
});

await check('library: switching on a kit, then a palette, keeps a revision', async () => {
    const kit = await site('POST', '/styles/variations/kit-ridge');
    expectStatus(kit, 200);
    expect(kit.data.applied?.kind === 'kit', `applied ${JSON.stringify(kit.data.applied)}`);
    const list = await site('GET', '/styles/variations');
    const ridge = list.data.variations.find((v) => v.slug === 'kit-ridge');
    expect(ridge?.active === true, `Ridge not on: ${JSON.stringify(ridge)}`);
    const palette = Object.values(kit.data.palette).flat();
    expect(palette.find((c) => c.slug === 'primary')?.color?.toLowerCase() === ridge.colors.primary.toLowerCase(), `palette is not the kit's: ${JSON.stringify(kit.data.palette).slice(0, 200)}`);
    const fontsBefore = JSON.stringify(kit.data.siteChanges.styles.typography ?? null);
    const forest = await site('POST', '/styles/variations/forest');
    expectStatus(forest, 200);
    const after = await site('GET', '/styles/variations');
    expect(after.data.variations.find((v) => v.slug === 'forest')?.active === true, 'forest palette not on');
    expect(JSON.stringify(forest.data.siteChanges.styles.typography ?? null) === fontsBefore, 'a palette changed the fonts');
    const fonts = await site('POST', '/styles/variations/editorial');
    expect(fonts.status === 200 && fonts.data.applied?.kind === 'fonts', `editorial: ${JSON.stringify(fonts.data).slice(0, 200)}`);
    const both = await site('GET', '/styles/variations');
    const on = both.data.variations.filter((v) => v.active).map((v) => v.slug);
    expect(on.includes('forest') && on.includes('editorial'), `on after a palette and a font set: ${on.join(', ')}`);
    const missing = await site('POST', '/styles/variations/no-such-look');
    expectStatus(missing, 404);
    const revisions = await cli.playground.run({
        code: `<?php require '/wordpress/wp-load.php'; echo count(wp_get_post_revisions(WP_Theme_JSON_Resolver::get_user_global_styles_post_id()));`,
    });
    expect(Number(revisions.text.trim()) >= 2, `global styles revisions: ${revisions.text}`);
});

await check('abilities: library tools, and a contributor may not switch the look', async () => {
    const categories = await ability('anypress/list-pattern-categories');
    expect(categories.status === 200 && categories.data.length > 16, `list-pattern-categories: HTTP ${categories.status}`);
    const blocks = await ability('anypress/list-blocks');
    expect(blocks.status === 200 && blocks.data.some((b) => b.name === 'anypress/popup'), `list-blocks: HTTP ${blocks.status}`);
    const looks = await ability('anypress/list-style-variations');
    expect(looks.status === 200 && looks.data.variations.length > 16, `list-style-variations: HTTP ${looks.status}`);
    const apply = await ability('anypress/apply-style-variation', { slug: 'kit-hearth' });
    expect(apply.status === 200 && apply.data.applied?.slug === 'kit-hearth', `apply-style-variation: HTTP ${apply.status} ${JSON.stringify(apply.data).slice(0, 200)}`);
    const contributor = await ability('anypress/apply-style-variation', { slug: 'kit-ridge' }, { as: 'contributor' });
    expect(contributor.status === 403, `contributor: HTTP ${contributor.status}`);
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
    const toolAbilities = [...abilities.values()].filter((a) => a.meta?.mcp?.type !== 'resource').length;
    expect(tools.length === toolAbilities && toolAbilities > 0, `${tools.length} tools for ${toolAbilities} abilities`);
    const getSite = tools.find((tool) => /get-site$/.test(tool.name));
    expect(getSite, `no get-site tool in ${tools.map((tool) => tool.name).join(', ')}`);
    expect(getSite.annotations?.readOnlyHint === true, `get-site annotations ${JSON.stringify(getSite.annotations)}`);
    const call = await mcp('tools/call', { name: getSite.name, arguments: {} }, { version });
    expectStatus(call, 200);
    expect(!call.data.result?.isError && JSON.stringify(call.data.result).includes('Playground Store'), `get-site: ${JSON.stringify(call.data).slice(0, 300)}`);
}

await check('MCP: the plan turns the adapter on by itself, with no switch', async () => {
    // What a store's page load does (RolesManager on init): a store with a plan, here the website site type.
    const out = await cli.playground.run({
        code: `<?php
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
putenv('ANYPRESS_IS_TENANT=true');
wp_mkdir_p(AnyPressClient\\Features\\AdminRolesSettings::ROLES_FILE_DIR_PATH);
file_put_contents(AnyPressClient\\Features\\AdminRolesSettings::ROLES_FILE_PATH, json_encode(['website' => ['title' => 'Website', 'plugins' => ['anypress-client/anypress-client.php']]]));
(new AnyPressClient\\Features\\RolesManager())->activate_enabled_plugins();
echo json_encode(['active' => is_plugin_active('mcp-adapter/mcp-adapter.php')]);
`,
    });
    const state = JSON.parse(out.text.slice(out.text.indexOf('{')));
    expect(state.active === true, `MCP Adapter after a store page load: ${out.text.slice(0, 200)}`);
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

await check('MCP: the build guide is a resource the app can read', async () => {
    const init = await mcp('initialize', INIT);
    mcpSession = init.session;
    const list = await mcp('resources/list');
    expectStatus(list, 200);
    const guide = (list.data.result?.resources ?? []).find((r) => r.uri === 'skill://anypress-website/SKILL.md');
    expect(guide, `resources: ${JSON.stringify(list.data).slice(0, 300)}`);
    const read = await mcp('resources/read', { uri: guide.uri });
    const text = JSON.stringify(read.data.result ?? {});
    expect(text.includes('apply-style-variation') && text.includes('preview-pattern'), `read: ${text.slice(0, 300)}`);
});

await check('preview: a signed link shows a kit home page in the kit\'s look, and runs out', async () => {
    const kits = await site('GET', '/styles/variations');
    const home = { name: kits.data.variations.find((v) => v.slug === 'kit-hearth').homePage };
    const res = await site('GET', `/preview?name=${encodeURIComponent(home.name)}&variation=kit-hearth`);
    expectStatus(res, 200);
    const url = new URL(res.data.url);
    const page = await fetch(`${base}${url.pathname}${url.search}`);
    const html = await page.text();
    expect(page.status === 200 && html.includes('wp-site-blocks') && html.includes('anypress-preview'), `preview page: HTTP ${page.status} ${html.slice(0, 200)}`);
    expect(!/X-Frame-Options/i.test([...page.headers.keys()].join(' ')) && page.headers.get('content-security-policy') === 'frame-ancestors *', 'preview cannot be framed');
    const looks = await site('GET', '/styles/variations');
    const hearth = looks.data.variations.find((v) => v.slug === 'kit-hearth');
    expect(html.toLowerCase().includes(hearth.colors.primary.toLowerCase()), 'the preview is not in the Hearth colours');
    if (process.env.SHOTS) {
        // Pictures of the preview page, for a person to look at (SHOTS=<folder>).
        const { chromium } = createRequire(import.meta.url)('playwright');
        const browser = await chromium.launch();
        for (const [name, viewport] of [['desktop', { width: 1280, height: 900 }], ['phone', { width: 390, height: 844 }]]) {
            const tab = await browser.newPage({ viewport, deviceScaleFactor: 2 });
            await tab.goto(`${base}${url.pathname}${url.search}`, { waitUntil: 'networkidle' });
            await tab.screenshot({ path: `${process.env.SHOTS}/preview-${home.name.replace('/', '-')}-${name}.png`, fullPage: name === 'desktop' });
        }
        await browser.close();
    }
    const forged = await fetch(`${base}/?anypress_preview=${url.searchParams.get('anypress_preview').replace(/.$/, 'x')}`);
    expect(forged.status === 403, `forged token: HTTP ${forged.status}`);
    const missing = await site('GET', '/preview?name=anypress/no-such-pattern');
    expectStatus(missing, 404);
    const viaAbility = await ability('anypress/preview-pattern', { name: home.name });
    expect(viaAbility.status === 200 && viaAbility.data.url.includes('anypress_preview='), `preview-pattern: HTTP ${viaAbility.status}`);
});

await check("brand: AnyPress Client by default, the reseller's brand once anypress_brand_name is set", async () => {
    const names = async (brand) => {
        const out = await cli.playground.run({
            code: `<?php
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
update_option('anypress_brand_name', ${JSON.stringify(brand)});
$all = apply_filters('all_plugins', get_plugins());
$mine = $all[ANYPRESS_CLIENT_BASENAME];
echo json_encode(['name' => $mine['Name'], 'author' => $mine['Author'], 'menu' => AnyPressClient\\Core\\Brand::plugin_name(), 'mcp' => AnyPressClient\\Core\\Brand::name() . ' website']);
`,
        });
        return JSON.parse(out.text.slice(out.text.indexOf('{')));
    };
    const plain = await names('');
    expect(plain.name === 'AnyPress Client' && plain.menu === 'AnyPress Client' && plain.author === 'Otaku Solutions', `no brand: ${JSON.stringify(plain)}`);
    const branded = await names('KnowBS');
    expect(branded.name === 'KnowBS Client' && branded.menu === 'KnowBS Client' && branded.author === 'KnowBS' && branded.mcp === 'KnowBS website', `KnowBS: ${JSON.stringify(branded)}`);
    await names('');
});

const failed = results.filter(([ok]) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);

if (SERVE) {
    console.log(`Left running at ${base} (Ctrl+C to stop).`);
} else {
    await cli[Symbol.asyncDispose]();
    process.exit(failed ? 1 : 0);
}
