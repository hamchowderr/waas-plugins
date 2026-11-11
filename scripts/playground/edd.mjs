// End-to-end test of the shop switch's Easy Digital Downloads source (`wp anypress shop switch --from=edd` and the
// check-shop / switch-shop abilities) on WordPress Playground: a website about to move in, with the AnyPress theme,
// Easy Digital Downloads 3 and FluentCart from wordpress.org, the FluentCart Migrator add-on from FluentCart's add-ons
// page and AnyPress Client from this checkout (or AnyPress Move, which carries the same code). It is not set up as a
// store: on a store, AnyPress Client keeps only the site type's plugins on, so EDD (needed while its shop is read) is
// switched off. The shop switch runs on the website before it moves in.
// edd/seed.php writes an EDD shop through EDD's own functions: 3 downloads (one with two price options), a discount
// code, 3 customers (one who never ordered), 3 completed orders, and pages with EDD's shortcodes and blocks.
// Checks: the dry run reports the shop and the pages and changes nothing; the switch has the add-on copy the shop
// (products, orders, customers, the coupon) and swaps EDD's shortcodes and blocks for FluentCart's, with the buy
// buttons pointing at the copied products; EDD's own data is left alone; running again adds nothing; the abilities
// need an administrator; undo puts every page back.
//
//   cd scripts/playground && npm ci
//   npm run edd               # exit 1 on a failure
//   npm run edd -- --serve    # leave the site running at http://127.0.0.1:9412 (admin / password)
//
// Needs packages/anypress-client/vendor (composer install in packages/anypress-client), and network access to
// downloads.wordpress.org and addons-cdn.fluentcart.com (the add-on's zip, which isn't on wordpress.org).
// Playground stores the database in SQLite, not MariaDB: this checks the PHP and WordPress behaviour.

import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';
import { wpCLI } from '@wp-playground/blueprints';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.4';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9412);
const SERVE = process.argv.includes('--serve');
const EDD = process.env.EDD || '3.7.3';
const FLUENT_CART = process.env.FLUENT_CART || '2.3.1';
const USER_TOKEN = randomBytes(24).toString('hex');
const REPORTS = process.env.EDD_REPORT_DIR;
const keep = (name, text) => REPORTS && (mkdirSync(REPORTS, { recursive: true }), writeFileSync(resolve(REPORTS, name), typeof text === 'string' ? text : JSON.stringify(text, null, 2)));

if (!existsSync(resolve(CLIENT, 'vendor/autoload.php'))) {
    console.error('packages/anypress-client/vendor is missing: run composer install in packages/anypress-client first.');
    process.exit(1);
}

const plugin = (url) => ({ step: 'installPlugin', pluginData: { resource: 'url', url }, options: { activate: true } });
const blueprint = {
    landingPage: '/wp-admin/admin.php?page=fluent-cart',
    login: SERVE,
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } },
        { step: 'writeFile', path: '/tmp/wp-cli.phar', data: { resource: 'url', url: 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        plugin(`https://downloads.wordpress.org/plugin/easy-digital-downloads.${EDD}.zip`),
        plugin(`https://downloads.wordpress.org/plugin/fluent-cart.${FLUENT_CART}.zip`),
        plugin('https://addons-cdn.fluentcart.com/fluent-cart-migrator.zip'),
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'EDD switch test', permalink_structure: '/%postname%/' } },
        {
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/edd-test-users.php',
            data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user; an editor exists to check permissions.
add_action('init', function () {
    if (!get_user_by('login', 'editor')) {
        wp_insert_user(['user_login' => 'editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
    }
});
// SQLite can't run a few of the FluentCart Migrator add-on's MySQL-only counts (COLLATE utf8mb4_unicode_520_ci); keep
// wpdb's printed errors out of the REST answers. A store's MariaDB runs them.
add_action('rest_api_init', fn () => $GLOBALS['wpdb']->suppress_errors(true));
add_filter('determine_current_user', function ($id) {
    [$token, $login] = array_pad(explode(':', $_SERVER['HTTP_X_TEST_USER'] ?? '', 2), 2, '');
    $user = hash_equals('${USER_TOKEN}', $token) ? get_user_by('login', $login) : false;
    return $user ? $user->ID : $id;
}, 30);
`,
        },
    ],
};

const WP_ZIP = `https://wordpress.org/wordpress-${WP}.zip`;
const cli = await runCLI({
    command: 'server',
    wp: WP_ZIP,
    php: PHP,
    port: PORT,
    blueprint,
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/anypress-client' }],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
console.log(`WordPress ${WP} on PHP ${PHP} at ${base} (not a store: the website before it moves in), EDD ${EDD}, FluentCart ${FLUENT_CART}`);

async function php(code) {
    const out = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; wp_set_current_user(1); ${code}` });
    const text = out.text.trim();
    const start = text.search(/[[{]/);
    if (start < 0) return text;
    try {
        return JSON.parse(text.slice(start));
    } catch {
        return text;
    }
}
async function wp(command) {
    let text;
    try {
        text = (await wpCLI(cli.playground, { command })).text.trim();
    } catch (error) {
        text = String(error.message ?? error);
    }
    if (command.includes('--format=json')) {
        const start = text.indexOf('{\n');
        try {
            return JSON.parse(text.slice(start < 0 ? text.indexOf('{') : start));
        } catch (error) {
            keep('last-not-json.txt', text);
            throw new Error(`not JSON (${error.message}): ${text.slice(0, 300)} ... ${text.slice(-500)}`);
        }
    }
    return text;
}
async function ability(name, input, { as = 'admin', method = 'POST' } = {}) {
    let url = `${base}/wp-json/wp-abilities/v1/abilities/${name}/run`;
    const headers = { 'content-type': 'application/json' };
    if (as) headers['x-test-user'] = `${USER_TOKEN}:${as}`;
    let body;
    if (method === 'GET') {
        if (input) url += `?${new URLSearchParams(Object.entries(input).map(([k, v]) => [`input[${k}]`, String(v)]))}`;
    } else {
        body = JSON.stringify(input === undefined ? {} : { input });
    }
    const res = await fetch(url, { method, headers, body });
    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        data = text;
    }
    return { status: res.status, data };
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
const expect = (condition, message) => {
    if (!condition) throw new Error(message);
};
const short = (value) => JSON.stringify(value).slice(0, 700);

/** FluentCart's rows, EDD's own counts and every seeded page's content. */
async function counts(seed) {
    return php(`global $wpdb; $n = fn ($t) => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$t");
    $pages = [];
    foreach (${JSON.stringify(seed ? Object.values(seed.pages).concat(seed.checkout_page ? [seed.checkout_page] : []) : [])} as $id) { $pages[$id] = get_post_field('post_content', $id); }
    $map = [];
    foreach (get_posts(['post_type' => 'download', 'numberposts' => -1, 'fields' => 'ids']) as $id) { $map[$id] = (int) get_post_meta($id, '_fcart_migrated_id', true); }
    echo json_encode([
        'products' => $n('posts WHERE post_type = "fluent-products"'), 'variations' => $n('fct_product_variations'), 'orders' => $n('fct_orders'),
        'customers' => $n('fct_customers'), 'coupons' => $n('fct_coupons'), 'transactions' => $n('fct_order_transactions'),
        'emails' => $wpdb->get_col("SELECT email FROM {$wpdb->prefix}fct_customers ORDER BY email"),
        'edd_orders' => $n('edd_orders'), 'edd_downloads' => $n('posts WHERE post_type = "download"'), 'edd_customers' => $n('edd_customers'),
        'map' => $map, 'pages' => $pages, 'revisions' => $n('posts WHERE post_type = "revision"'),
        'active' => get_option('active_plugins'),
    ]);`);
}

// ---- Seed the EDD shop.
const seed = await (async () => {
    let out;
    try {
        out = await cli.playground.run({ code: readFileSync(resolve(HERE, 'edd/seed.php'), 'utf8') });
    } catch {
        const log = await php(`$f = WP_CONTENT_DIR . '/debug.log'; echo json_encode(['log' => file_exists($f) ? file_get_contents($f) : '']);`);
        return { errors: [`the seed failed: ${String(log.log).split('\n').filter((l) => /PHP (Fatal|Parse)|Uncaught/.test(l)).join('\n').slice(0, 1500)}`], pages: {} };
    }
    const text = out.text.trim();
    try {
        return JSON.parse(text.slice(text.lastIndexOf('{"downloads"')));
    } catch {
        return { errors: [text.slice(-1500)], pages: {} };
    }
})();
const page = (now, key) => now.pages[seed.pages[key]] || '';

await check('seed: the EDD shop is in place', async () => {
    expect(!seed.errors.length, short(seed.errors));
    const now = await counts(seed);
    expect(now.edd_orders === 3 && now.edd_downloads === 3 && now.edd_customers === 3, short(now));
});

let before;
await check('dry run: reports the shop and the pages it would switch, and changes nothing', async () => {
    before = await counts(seed);
    const dry = await wp('wp anypress shop switch --from=edd --dry-run --format=json --user=admin');
    keep('dry-run.json', dry);
    const p = dry.preflight;
    expect(p.ready === true && p.blockers.length === 0 && p.edd === EDD && p.migrator_addon, `preflight ${short(p)}`);
    expect(p.counts.products === 3 && p.counts.orders === 3 && p.counts.coupons === 1, `counts ${short(p.counts)}`);
    expect(dry.migration.done === false, short(dry.migration));
    const titles = dry.pages.map((r) => r.title);
    expect(['Store', 'My Account', 'The Course'].every((t) => titles.includes(t)) && !titles.includes('About'), short(dry.pages));
    expect(dry.pages.every((r) => r.status === 'will be switched'), short(dry.pages));
    // Before the add-on runs, the buy buttons have no FluentCart product to point at.
    expect(dry.not_switched.some((r) => r.what.includes('purchase_link')) && dry.not_switched.some((r) => r.what.includes('edd/buy-button')), short(dry.not_switched));
    const after = await counts(seed);
    expect(after.products === 0 && after.orders === 0 && JSON.stringify(after.pages) === JSON.stringify(before.pages), `changed: ${short(after)}`);
});

let report;
await check('switch: the add-on copies the shop into FluentCart, and EDD is left as it was', async () => {
    const table = await wp('wp anypress shop switch --from=edd --user=admin');
    keep('switch.txt', table);
    // SQLite can't run a few of the add-on's MySQL-only counts (COLLATE utf8mb4_unicode_520_ci), which print database
    // errors here but not on a store's MariaDB.
    expect(!/Fatal|Uncaught|^Error:/m.test(table), table.slice(-1500));
    report = await wp('wp anypress shop switch --from=edd --dry-run --format=json --user=admin');
    keep('after.json', report);
    expect(report.migration.done === true, short(report.migration));
    const now = await counts(seed);
    expect(now.products === 3 && now.orders === 3 && now.coupons === 1, `counts ${short(now)}`);
    // Carol never ordered: the add-on's "customers without orders" step finds her with a MySQL-only query, so on
    // SQLite only the buyers can be checked.
    expect(['alice@anypress.example', 'bob@anypress.example'].every((e) => now.emails.includes(e)), `customers ${short(now.emails)}`);
    expect(Object.values(now.map).every((id) => id > 0), `products ${short(now.map)}`);
    expect(now.edd_orders === 3 && now.edd_downloads === 3 && now.edd_customers === 3, `EDD changed: ${short(now)}`);
    expect(now.active.includes('easy-digital-downloads/easy-digital-downloads.php'), 'EDD was turned off');
});

await check('pages: EDD shortcodes and blocks are FluentCart\'s, buy buttons point at the copied products', async () => {
    const now = await counts(seed);
    const store = page(now, 'store');
    const account = page(now, 'account');
    const course = page(now, 'course');
    expect(store.includes('[fluent_cart_products]') && store.includes(`[fluent_cart_product_card product_id="${now.map[seed.downloads.ebook]}"]`) && !store.includes('[downloads') && !store.includes('[purchase_link'), store);
    expect((account.match(/\[fluent_cart_customer_profile\]/g) || []).length === 1 && !/\[(purchase|download)_history|edd_profile_editor/.test(account), account);
    expect(course.includes(`[fluent_cart_product_card product_id="${now.map[seed.downloads.course]}"]`) && course.includes('[fluent_cart_checkout]') && !course.includes('wp:edd/'), course);
    expect(page(now, 'plain') === before.pages[seed.pages.plain], 'the page without EDD changed');
    expect(now.revisions > before.revisions, `no revisions kept (${before.revisions} -> ${now.revisions})`);
    expect(report.not_switched.length === 0, short(report.not_switched));
});

await check('the switched pages show FluentCart', async () => {
    const res = await fetch(`${base}/?page_id=${seed.pages.course}`);
    const html = await res.text();
    expect(res.status === 200 && !html.includes('[fluent_cart_') && /fluent-cart|fct[-_]/.test(html), `HTTP ${res.status} ${html.slice(0, 300)}`);
});

await check('running it again adds nothing', async () => {
    const was = await counts(seed);
    const again = await wp('wp anypress shop switch --from=edd --format=json --user=admin');
    keep('again.json', again);
    const after = await counts(seed);
    expect(Object.values(again.migration.steps).filter((s) => s === 'already done').length >= 3, short(again.migration));
    expect(again.pages.length === 0, short(again.pages));
    for (const key of ['products', 'variations', 'orders', 'customers', 'coupons', 'transactions']) {
        expect(after[key] === was[key], `${key}: ${was[key]} -> ${after[key]}`);
    }
});

await check('abilities: check-shop from EDD is read-only, switch-shop needs an administrator', async () => {
    const look = await ability('anypress/check-shop', { from: 'edd' }, { method: 'GET' });
    expect(look.status === 200 && look.data.dry_run === true && look.data.from === 'edd' && look.data.preflight.ready === true, `check-shop: HTTP ${look.status} ${short(look.data)}`);
    const editor = await ability('anypress/switch-shop', { from: 'edd', undo: false }, { as: 'editor' });
    expect(editor.status === 403, `editor: HTTP ${editor.status}`);
    const nobody = await ability('anypress/check-shop', { from: 'edd' }, { as: null, method: 'GET' });
    expect(nobody.status === 401, `signed out: HTTP ${nobody.status}`);
});

await check('undo puts the EDD shortcodes and blocks back on every page; switching again swaps them again', async () => {
    const undo = await wp('wp anypress shop switch --from=edd --undo --format=json --user=admin');
    keep('undo.json', undo);
    // EDD's own pages (Checkout, Receipt, Order History, Confirmation, Products) were switched too.
    expect(undo.pages.length >= 3 && undo.pages.every((r) => r.status === 'put back'), short(undo.pages));
    const undone = await counts(seed);
    for (const key of ['store', 'account', 'course']) {
        expect(page(undone, key) === before.pages[seed.pages[key]], `${key}: ${page(undone, key)}`);
    }
    expect(undone.products === 3 && undone.orders === 3, 'undo removed what the add-on copied');
    const redo = await ability('anypress/switch-shop', { from: 'edd', undo: false });
    expect(redo.status === 200 && redo.data.pages.length === undo.pages.length && redo.data.pages.every((r) => r.status === 'switched'), `HTTP ${redo.status} ${short(redo.data)}`);
});

await check('no PHP warnings or errors from AnyPress Client', async () => {
    const log = await php(`$f = WP_CONTENT_DIR . '/debug.log'; echo json_encode(['log' => file_exists($f) ? file_get_contents($f) : '']);`);
    keep('debug.log', log.log);
    // One entry per [date] line; the add-on's SQLite-only database errors (above) name our code in their backtrace.
    const ours = log.log.split(/\n(?=\[)/).filter((entry) => /anypress-client|AnyPressClient/.test(entry) && !/WordPress database error/.test(entry));
    expect(!ours.length, ours.slice(0, 3).join('\n').slice(0, 1500));
});

const failed = results.filter(([ok]) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
if (SERVE) {
    console.log(`Left running at ${base} (Ctrl+C to stop).`);
    await new Promise(() => {});
}
await cli[Symbol.asyncDispose]();
process.exit(failed ? 1 : 0);
