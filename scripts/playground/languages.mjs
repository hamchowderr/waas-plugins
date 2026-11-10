// End-to-end test of AnyPress Client's Languages switch (Polylang) on WordPress Playground, as a store of one site type
// (SITE_TYPE: online-store by default, or community): the AnyPress theme, that type's Fluent plugin from wordpress.org at
// the store template's version, Polylang installed but switched off (as the store template ships it), and AnyPress
// Client from this checkout with ANYPRESS_TENANT_ROLES set. Requests are signed as the AnyPress API signs them.
// Checks: Languages turns Polylang on and sets it up once (Spanish added, the site's own language the default,
// /es/ addresses, translation files downloaded, a switcher in the menu); a page's Spanish copy is made as a linked
// draft and shows at /es/ once published; the menu link opens the copy in Spanish; turning Languages off takes the
// switcher out and lets the plan switch Polylang off. It also reports what the Fluent plugin shows in Spanish.
//
//   cd scripts/playground && npm ci
//   npm run languages                          # online-store; exit 1 on a failure
//   SITE_TYPE=community npm run languages
//   npm run languages -- --serve               # leave the site running at http://127.0.0.1:9404 (admin / password)
//
// Needs packages/anypress-client/vendor (composer install), a waas-provisioning-api checkout next to this repository
// (its store template's roles.json; or ANYPRESS_API_DIR), and network access to downloads.wordpress.org and
// api.wordpress.org (Polylang, the Fluent plugin and the translation files).

import { createHash, createHmac, randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const API = process.env.ANYPRESS_API_DIR || resolve(ROOT, '../waas-provisioning-api');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.3';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9404);
const SERVE = process.argv.includes('--serve');
const SITE_TYPE = process.env.SITE_TYPE || 'online-store';
const SITE_KEY = randomBytes(24).toString('hex');
// The store template's versions (waas-provisioning-api templates/anypress-store/build.sh).
const POLYLANG = 'polylang.3.9.2.zip';
const FLUENT = {
    'online-store': { zip: 'fluent-cart.2.2.0.zip', slug: 'fluent-cart' },
    community: { zip: 'fluent-community.2.12.0.zip', slug: 'fluent-community' },
}[SITE_TYPE];

if (!FLUENT) {
    console.error(`SITE_TYPE must be online-store or community, not ${SITE_TYPE}.`);
    process.exit(1);
}
for (const [path, fix] of [[resolve(CLIENT, 'vendor/autoload.php'), 'run composer install in packages/anypress-client'], [resolve(API, 'templates/anypress-store/roles.json'), 'set ANYPRESS_API_DIR to a waas-provisioning-api checkout']]) {
    if (!existsSync(path)) {
        console.error(`${path} is missing: ${fix} first.`);
        process.exit(1);
    }
}

const blueprint = {
    landingPage: '/wp-admin/index.php?page=anypress-your-plan',
    login: SERVE,
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/${FLUENT.zip}` }, options: { activate: true } },
        // Installed switched off, as the store template ships it.
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/${POLYLANG}` }, options: { activate: false } },
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(resolve(API, 'templates/anypress-store/roles.json'), 'utf8') },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${SITE_KEY}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}');\n` },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        ...extraCertificates(),
        { step: 'setSiteOptions', options: { blogname: 'Playground Bakery', permalink_structure: '/%postname%/' } },
    ],
};

/** Behind a TLS-inspecting proxy (NODE_EXTRA_CA_CERTS set), WordPress must trust the proxy's certificate too. */
function extraCertificates() {
    const extra = process.env.NODE_EXTRA_CA_CERTS;
    if (!extra || !existsSync(extra)) return [];
    return [
        { step: 'writeFile', path: '/wordpress/wp-content/extra-ca.crt', data: readFileSync(extra, 'utf8') },
        {
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/0-test-certificates.php',
            data: `<?php
add_filter('http_request_args', function ($args) {
    $bundle = WP_CONTENT_DIR . '/test-ca-bundle.crt';
    if (!file_exists($bundle)) {
        file_put_contents($bundle, file_get_contents(ABSPATH . WPINC . '/certificates/ca-bundle.crt') . "\\n" . file_get_contents(WP_CONTENT_DIR . '/extra-ca.crt'));
    }
    $args['sslcertificates'] = $bundle;
    return $args;
});
`,
        },
    ];
}

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${WP}.zip`,
    php: PHP,
    port: PORT,
    blueprint,
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/anypress-client' }],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}, site type ${SITE_TYPE}`);

function rawurlencode(value) {
    return encodeURIComponent(value).replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`);
}

/** Calls a site route as the AnyPress API does: X-AnyPress-Signature over time, nonce, method, route and body hash. */
async function site(method, path, body, { sign = true } = {}) {
    const route = `/anypress-client/v1/site${path}`;
    const json = body === undefined ? '' : JSON.stringify(body);
    const headers = { 'content-type': 'application/json' };
    if (sign) {
        const t = String(Math.floor(Date.now() / 1000));
        const n = randomBytes(16).toString('hex');
        const payload = [t, n, method, route, createHash('sha256').update(json).digest('hex')].join('\n');
        headers['x-anypress-signature'] = `t=${t},n=${n},s=${createHmac('sha256', SITE_KEY).update(payload).digest('hex')}`;
    }
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

async function php(code) {
    const out = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` });
    const text = out.text.trim();
    return JSON.parse(text.slice(text.search(/[[{]/)));
}

const page = async (path) => {
    const res = await fetch(`${base}${path}`, { redirect: 'follow' });
    return { status: res.status, url: res.url, html: await res.text() };
};

const results = [];
async function check(name, fn) {
    try {
        await fn();
        results.push(true);
        console.log(`  ok    ${name}`);
    } catch (error) {
        results.push(false);
        console.log(`  FAIL  ${name}\n        ${error.message}`);
    }
}
const expect = (condition, message) => {
    if (!condition) throw new Error(message);
};
const short = (value) => JSON.stringify(value).slice(0, 700);
/** The first visit after Languages is turned on finishes its set-up; waits up to a minute for it. */
async function untilReady() {
    let current;
    for (let i = 0; i < 30; i++) {
        await page('/');
        current = (await site('GET', '/languages')).data;
        if (current.ready) break;
        await new Promise((r) => setTimeout(r, 2000));
    }
    return current;
}
const active = () => php(`echo json_encode(array_values((array) get_option('active_plugins')));`);

let about;
let menuLink;
await check('before: Languages is off and Polylang stays off', async () => {
    const res = await site('GET', '/languages');
    expect(res.status === 200 && res.data.on === false && res.data.available === true, short(res));
    expect(!(await active()).includes('polylang/polylang.php'), 'Polylang is on');
});

await check('a published About page linked from the menu', async () => {
    const res = await site('POST', '/pages', { title: 'About', status: 'publish', content: '<!-- wp:paragraph --><p>We bake bread.</p><!-- /wp:paragraph -->' });
    expect(res.status === 200, short(res));
    about = res.data;
    menuLink = `<!-- wp:navigation-link {"label":"About","type":"page","id":${about.id},"url":"${about.link}","kind":"post-type"} /-->`;
    const menu = await php(`$id = wp_insert_post(['post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Header', 'post_content' => '${menuLink.replace(/'/g, "\\'")}']); echo json_encode([$id]);`);
    expect(menu[0] > 0, short(menu));
});

await check('bad input is refused', async () => {
    const unknown = await site('PUT', '/languages', { on: true, languages: ['xx_YY'] });
    const none = await site('PUT', '/languages', { on: true, languages: [] });
    const notBool = await site('PUT', '/languages', { on: 'yes' });
    const unsigned = await site('PUT', '/languages', { on: true, languages: ['es_ES'] }, { sign: false });
    expect(unknown.status === 400 && none.status === 400 && notBool.status === 400 && unsigned.status === 401, short({ unknown, none, notBool, unsigned }));
});

let state;
await check('turning Languages on with Spanish sets Polylang up once', async () => {
    const res = await site('PUT', '/languages', { on: true, languages: ['es_ES'] });
    expect(res.status === 200 && res.data.on === true, short(res));
    state = await untilReady();
    expect(state.ready === true, short(state));
    expect(state.languages.map((l) => `${l.code}:${l.default}`).join() === 'en:true,es:false', short(state));
    expect((await active()).includes('polylang/polylang.php'), 'Polylang is not on');
    const pll = await php(`$o = get_option('polylang'); echo json_encode(['force' => $o['force_lang'], 'hide' => $o['hide_default'], 'browser' => $o['browser'], 'wizard' => (bool) get_transient('pll_activation_redirect'), 'about' => pll_get_post_language(${about.id})]);`);
    expect(pll.force === 1 && pll.hide === true && pll.browser === false && pll.wizard === false && pll.about === 'en', short(pll));
});

await check('the menu has a language switcher', async () => {
    const menus = (await site('GET', '/menus')).data;
    expect(menus.some((m) => m.content.includes('polylang/navigation-language-switcher')), short(menus));
});

let copy;
await check("an About page's Spanish copy is a linked draft, made once", async () => {
    const res = await site('POST', `/pages/${about.id}/translations`, { language: 'es' });
    expect(res.status === 200 && res.data.created === true && res.data.status === 'draft' && res.data.language === 'es', short(res));
    copy = res.data;
    expect(copy.translations.en === about.id, short(copy));
    const again = await site('POST', `/pages/${about.id}/translations`, { language: 'es' });
    expect(again.data.created === false && again.data.id === copy.id, short(again));
    const same = await site('POST', `/pages/${about.id}/translations`, { language: 'en' });
    const wrong = await site('POST', `/pages/${about.id}/translations`, { language: 'zz' });
    expect(same.status === 400 && wrong.status === 400, short({ same, wrong }));
});

await check('once translated and published, the copy shows at /es/ and the page list shows both languages', async () => {
    const res = await site('PUT', `/pages/${copy.id}`, { title: 'Sobre nosotros', slug: 'sobre-nosotros', status: 'publish', content: '<!-- wp:paragraph --><p>Horneamos pan.</p><!-- /wp:paragraph -->' });
    expect(res.status === 200, short(res));
    const es = await page('/es/sobre-nosotros/');
    expect(es.status === 200 && es.html.includes('Horneamos pan.') && /<html[^>]+lang="es-ES"/.test(es.html), `${es.status} ${es.url}`);
    const en = await page('/about/');
    expect(en.status === 200 && en.html.includes('We bake bread.') && /<html[^>]+lang="en-US"/.test(en.html), `${en.status} ${en.url}`);
    const list = (await site('GET', '/pages')).data;
    expect(list.find((p) => p.id === about.id)?.translations?.es === copy.id && list.find((p) => p.id === copy.id)?.language === 'es', short(list));
});

await check('on a Spanish page the menu link opens the Spanish copy with its title', async () => {
    const es = await page('/es/sobre-nosotros/');
    expect(es.html.includes('/es/sobre-nosotros/') && es.html.includes('>Sobre nosotros<'), es.html.match(/<nav[\s\S]{0,1500}/)?.[0] ?? 'no menu');
    const en = await page('/about/');
    expect(!/class="wp-block-navigation-item__label">Sobre nosotros</.test(en.html), 'English page shows the Spanish label');
});

const findings = {};
await check('report: translation files and what the Fluent plugin shows in Spanish', async () => {
    findings.files = await php(`echo json_encode(array_map('basename', array_merge(glob(WP_LANG_DIR . '/*es_ES*.mo') ?: [], glob(WP_LANG_DIR . '/plugins/*es_ES*.mo') ?: [], glob(WP_LANG_DIR . '/plugins/*es_ES*.l10n.php') ?: [], glob(WP_LANG_DIR . '/*es_ES*.l10n.php') ?: [], glob(WP_LANG_DIR . '/themes/*es_ES*') ?: [])));`);
    expect(findings.files.some((f) => f.startsWith('es_ES')), short(findings.files));
    findings.fluentFiles = findings.files.filter((f) => f.startsWith(FLUENT.slug));
    findings.fluentWords = await php(`switch_to_locale('es_ES'); load_plugin_textdomain('${FLUENT.slug}'); $out = []; foreach (['Cart', 'Checkout', 'Add to Cart', 'Members', 'Spaces', 'Search'] as $w) { $out[$w] = __($w, '${FLUENT.slug}'); } echo json_encode($out);`);
});

await check('turning Languages off takes the switcher out and the plan switches Polylang off', async () => {
    const res = await site('PUT', '/languages', { on: false });
    expect(res.status === 200 && res.data.on === false, short(res));
    await page('/');
    const menus = (await site('GET', '/menus')).data;
    expect(!menus.some((m) => m.content.includes('polylang/navigation-language-switcher')), short(menus));
    expect(!(await active()).includes('polylang/polylang.php'), 'Polylang is still on');
    const kept = await php(`echo json_encode([get_post_status(${copy.id})]);`);
    expect(kept[0] === 'publish', short(kept));
});

await check('turning it back on links the pages again', async () => {
    await site('PUT', '/languages', { on: true, languages: ['es_ES'] });
    state = await untilReady();
    const list = (await site('GET', '/pages')).data;
    expect(state.ready && list.find((p) => p.id === about.id)?.translations?.es === copy.id, short({ state, list }));
});

console.log(`\nFindings (${SITE_TYPE}): ${JSON.stringify(findings, null, 1)}`);
const failed = results.filter((ok) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed (site type ${SITE_TYPE})`);
if (!SERVE) {
    process.exit(failed ? 1 : 0);
}
