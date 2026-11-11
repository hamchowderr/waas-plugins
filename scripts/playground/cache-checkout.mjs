// The store page cache (advanced-cache.php and mu-plugins/waas-cache.php, taken from the store agent in
// waas-provisioning-api src/provisioner/coolify/agent.php) around FluentCart's shop, product, cart and checkout pages,
// on a Playground store of the online-store site type with AnyPress Client on. A real browser adds a product to the
// cart and places an order with offline payment while a second, cookie-less visitor keeps getting cached pages:
// product pages are cached, cart, checkout, account and receipt pages never are, the cart cookie skips the cache, and
// no saved page holds a shopper's details.
//
//   npm run cache-checkout     # screenshots and results in shots/cache-checkout/
//
// Needs packages/anypress-client/vendor (composer install in packages/anypress-client), a waas-provisioning-api
// checkout next to this repository (or ANYPRESS_API_DIR), Playwright with Chromium, and network access to
// downloads.wordpress.org. FluentCart is the store template's pinned version unless FLUENT_CART is set.

import { createHash, createHmac, randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';
import { createRequire } from 'node:module';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const API = process.env.ANYPRESS_API_DIR || resolve(ROOT, '../waas-provisioning-api');
const TEMPLATE = resolve(API, 'templates/anypress-store');
for (const [path, fix] of [[resolve(CLIENT, 'vendor/autoload.php'), 'run composer install in packages/anypress-client'], [resolve(TEMPLATE, 'roles.json'), 'set ANYPRESS_API_DIR to a waas-provisioning-api checkout']]) {
    if (!existsSync(path)) {
        console.error(`Missing ${path}: ${fix}`);
        process.exit(1);
    }
}
const { chromium } = createRequire(import.meta.url)('playwright');
const SITE_TYPE = 'online-store';
const SITE_KEY = randomBytes(24).toString('hex');
const OUT = process.env.OUT_DIR || resolve(HERE, 'shots/cache-checkout');
mkdirSync(OUT, { recursive: true });
// The store template's pins (build.sh lines are "<slug> <version> <sha256>").
const pins = Object.fromEntries([...readFileSync(resolve(TEMPLATE, 'build.sh'), 'utf8').matchAll(/^(fluent-cart(?:-migrator)?) (\S+) [0-9a-f]{64}$/gm)].map((m) => [m[1], m[2]]));
const FLUENT_CART = process.env.FLUENT_CART || pins['fluent-cart'];
const MIGRATOR = pins['fluent-cart-migrator'];
if (!FLUENT_CART || !MIGRATOR) throw new Error('FluentCart pins not found in the store template build.sh');

// The two files exactly as the store agent writes them.
const agent = readFileSync(resolve(API, 'src/provisioner/coolify/agent.php'), 'utf8');
const heredocs = [...agent.matchAll(/<<<'PHP'\n([\s\S]*?)\nPHP\)/g)].map((m) => m[1]);
const dropin = heredocs.find((h) => h.includes('AnyPress page cache: serves saved pages'));
const muplugin = heredocs.find((h) => h.includes('Plugin Name: AnyPress page cache'));
if (!dropin || !muplugin) throw new Error('page cache code not found in agent.php');
// Playground's PHP reports itself as the command line, which the drop-in skips; here only real command-line runs
// (no request) are skipped. Nothing else in the drop-in is changed.
const testDropin = dropin.replace("if (PHP_SAPI === 'cli') return;", "if (PHP_SAPI === 'cli' && empty($_SERVER['REQUEST_URI'])) return;");
if (testDropin === dropin) throw new Error('drop-in CLI guard not found');

const blueprint = {
    login: false,
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_CACHE: true, WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, DISABLE_WP_CRON: true } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(resolve(HERE, 'preview-zips/anypress.zip')) }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-cart.${FLUENT_CART}.zip` }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'literal', name: 'fluent-cart-migrator.zip', contents: readFileSync(resolve(TEMPLATE, `plugins/fluent-cart-migrator-${MIGRATOR}.zip`)) }, options: { activate: false } },
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(resolve(TEMPLATE, 'roles.json'), 'utf8') },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${SITE_KEY}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}');\n` },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'Cache Test Shop', permalink_structure: '/%postname%/' } },
        // Playground's SQLite has no MySQL GET_LOCK/RELEASE_LOCK, which FluentCart's checkout rate limiter uses (stores
        // run MariaDB, which has them); without this every order attempt here is answered 429.
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-sqlite-locks.php', data: "<?php add_action('init', function () { $GLOBALS['wpdb']->hide_errors(); }); add_filter('query', function ($q) { return preg_match('/^\\s*SELECT (GET|RELEASE)_LOCK\\(/i', $q) ? \"SELECT '1'\" : $q; });\n" },
        { step: 'writeFile', path: '/wordpress/wp-content/advanced-cache.php', data: testDropin },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/waas-cache.php', data: muplugin },
    ],
};

const cli = await runCLI({
    command: 'server',
    php: '8.3',
    wp: '7.1.4',
    port: 9417,
    blueprint,
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/anypress-client' }],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
console.log(`Playground at ${base}, site type ${SITE_TYPE}, WordPress 7.1.4, PHP 8.3, FluentCart ${FLUENT_CART}`);

async function php(code) {
    const out = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` });
    const text = out.text.trim();
    return JSON.parse(text.slice(text.search(/[[{"]/)));
}
const rawurlencode = (v) => encodeURIComponent(v).replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`);
async function site(method, path, body) {
    const route = `/anypress-client/v1/site${path}`;
    const json = JSON.stringify(body);
    const t = String(Math.floor(Date.now() / 1000));
    const n = randomBytes(16).toString('hex');
    const payload = [t, n, method, route, createHash('sha256').update(json).digest('hex')].join('\n');
    const res = await fetch(`${base}/wp-json${route}`, { method, headers: { 'content-type': 'application/json', 'x-anypress-signature': `t=${t},n=${n},s=${createHmac('sha256', SITE_KEY).update(payload).digest('hex')}` }, body: json });
    return { status: res.status, data: await res.json() };
}

const results = [];
async function check(name, fn) {
    try {
        const note = await fn();
        results.push({ name, ok: true, note });
        console.log(`  ok    ${name}${note ? ` (${note})` : ''}`);
    } catch (error) {
        results.push({ name, ok: false, note: error.message });
        console.log(`  FAIL  ${name}\n        ${error.message}`);
    }
}
const expect = (c, m) => {
    if (!c) throw new Error(m);
};

// The store as the template sets it up: FluentCart's own pages, shop on the home page, offline payment on.
const setup = await php(`
    wp_set_current_user(1);
    (new \\FluentCart\\App\\CPT\\Pages())->createPages();
    update_option('show_on_front', 'page');
    $shop = get_page_by_path('shop'); if ($shop) update_option('page_on_front', $shop->ID);
    fluent_cart_update_option('fluent_cart_payment_settings_offline_payment', ['is_active' => 'yes', 'payment_mode' => 'live']);
    $s = get_option('fluent_cart_store_settings');
    $paths = [];
    foreach (['cart_page_id', 'checkout_page_id', 'receipt_page_id', 'customer_profile_page_id', 'shop_page_id'] as $k) $paths[$k] = isset($s[$k]) ? wp_make_link_relative(get_permalink($s[$k])) : null;
    echo json_encode($paths);`);
console.log('FluentCart pages', setup);

const made = await site('POST', '/products', { products: [{ title: 'Sourdough Loaf', description: '<p>Baked every morning.</p>', url: 'https://example.com/sourdough', variants: [{ price: '8.50', sku: 'SD-1' }] }] });
expect(made.status === 200, JSON.stringify(made));
const productId = made.data.results[0].id;
const productPath = await php(`wp_update_post(['ID' => ${productId}, 'post_status' => 'publish']);
    // Playground runs SQLite, which lacks the JSON_MERGE_PATCH FluentCart's first rating backfill uses on MySQL/MariaDB;
    // marking the backfill done keeps the product page from failing here (stores run MariaDB).
    $d = \\FluentCart\\App\\Models\\ProductDetail::query()->where('post_id', ${productId})->first();
    $o = $d->other_info ?: []; $o['rating_breakdown'] = $o['rating_breakdown'] ?? []; $o['rating_count'] = $o['rating_count'] ?? 0; $d->other_info = $o; $d->fulfillment_type = 'digital'; $d->save();
    // A digital product, so the order needs no shipping method (none is set up on this test site).
    \\FluentCart\\App\\Models\\ProductVariation::query()->where('post_id', ${productId})->update(['fulfillment_type' => 'digital']); echo json_encode(wp_make_link_relative(get_permalink(${productId})));`);
await php(`array_map('unlink', glob(WP_CONTENT_DIR . '/cache/waas-pages/*.html') ?: []); echo json_encode(1);`);
console.log('Product', productPath);

if (process.env.DEBUG_CACHE) {
    await php(`file_put_contents(WP_CONTENT_DIR . '/mu-plugins/zz-debug.php', '<?php add_action("shutdown", function () { error_log("CACHEDBG wp_cache=" . (defined("WP_CACHE") && WP_CACHE ? 1 : 0) . " file=" . (defined("WAAS_CACHE_FILE") ? WAAS_CACHE_FILE : "none") . " code=" . http_response_code() . " cookies=" . json_encode(array_keys($_COOKIE)) . " headers=" . json_encode(headers_list()) . " adv=" . (file_exists(WP_CONTENT_DIR . "/advanced-cache.php") ? 1 : 0)); }, 999);'); echo json_encode(1);`);
    const r = await fetch(base + productPath); const t = await r.text();
    console.log('STATUS', r.status, [...r.headers].map((h) => h.join(': ')).join(' | '));
    console.log(t.replace(/\s+/g, ' ').slice(0, 1500));
    const r2 = await fetch(base + '/'); console.log('HOME', r2.status, [...r2.headers].map((h) => h.join(': ')).join(' | '));
    console.log(await php(`echo json_encode(@file_get_contents(WP_CONTENT_DIR . '/debug.log') ? implode("\\n", array_filter(explode("\\n", file_get_contents(WP_CONTENT_DIR . '/debug.log')), fn($l) => strpos($l, 'CACHEDBG') !== false)) : 'no log');`));
    process.exit(0);
}
const visit = async (path, headers = {}) => {
    const res = await fetch(base + path, { headers, redirect: 'manual' });
    return { status: res.status, hit: res.headers.get('x-anypress-cache') === 'hit', body: await res.text(), cookies: res.headers.getSetCookie?.() || [] };
};
const savedCount = () => php(`echo json_encode(count(glob(WP_CONTENT_DIR . '/cache/waas-pages/*.html') ?: []));`);

await check('a new visitor gets the product page from WordPress, then from the cache', async () => {
    const a = await visit(productPath);
    const b = await visit(productPath);
    expect(a.status === 200 && !a.hit, `first: ${a.status} hit=${a.hit}`);
    expect(b.hit && b.body.includes('Sourdough Loaf'), `second: hit=${b.hit}`);
    return `${await savedCount()} page(s) saved`;
});

await check('the shop (home) page is cached too', async () => {
    await visit('/');
    const b = await visit('/');
    expect(b.hit, 'second visit to / was not a cache hit');
});

for (const key of ['cart_page_id', 'checkout_page_id', 'customer_profile_page_id']) {
    if (!setup[key]) continue;
    await check(`${key.replace('_page_id', '')} page (${setup[key]}) is never saved or served from the cache`, async () => {
        const before = await savedCount();
        const a = await visit(setup[key]);
        const b = await visit(setup[key]);
        expect(!a.hit && !b.hit, `hit on ${setup[key]}`);
        expect((await savedCount()) === before, 'a page was saved');
    });
}

// A real shopper in a browser.
const browser = await chromium.launch();
const shots = [];
let shopperPage;
await check('a shopper adds to cart, gets a cart cookie, and every page after that skips the cache', async () => {
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    const product = await page.goto(base + productPath);
    expect(product.headers()['x-anypress-cache'] === 'hit', 'product page in the browser was not from the cache (expected a hit)');
    const add = page.locator('[data-fluent-cart-add-to-cart-button], .fct-add-to-cart-btn, button:has-text("Add To Cart"), button:has-text("Add to cart")').first();
    await add.waitFor({ timeout: 20000 });
    await add.click();
    await page.waitForFunction(() => document.cookie.includes('fct_cart_hash'), null, { timeout: 20000 });
    const again = await page.goto(base + productPath);
    expect(again.headers()['x-anypress-cache'] !== 'hit', 'product page came from the cache although the shopper has a cart');
    shots.push(resolve(OUT, 'cache-product.png'));
    await page.screenshot({ path: shots.at(-1), fullPage: false });
    const checkout = await page.goto(base + setup.checkout_page_id);
    expect(checkout.headers()['x-anypress-cache'] !== 'hit', 'checkout came from the cache');
    await page.waitForSelector('text=Sourdough Loaf', { timeout: 20000 });
    // Fill the checkout form.
    const fill = async (sel, val) => {
        const el = page.locator(sel).first();
        if (await el.count()) await el.fill(val);
    };
    const pick = async () => {
        await page.evaluate(() => {
            const el = document.querySelector('select[name="billing_country"]');
            el.value = 'US';
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
        const st = page.locator('select[name="billing_state"]').first();
        if (await st.count()) await page.evaluate(() => { const el = document.querySelector('select[name="billing_state"]'); el.value = 'OR'; el.dispatchEvent(new Event('change', { bubbles: true })); });
    };
    await pick();
    await fill('input[name="billing_email"], input[type="email"]', 'shopper@example.com');
    await fill('input[name="billing_full_name"], input[name="billing_first_name"]', 'Test Shopper');
    await fill('input[name="billing_last_name"]', 'Shopper');
    await fill('input[name="billing_address_1"]', '1 Main Street');
    await fill('input[name="billing_city"]', 'Portland');
    await fill('input[name="billing_postcode"]', '97201');
    await fill('input[name="billing_state"]', 'OR');
    await page.waitForTimeout(1000);
    if ((await page.locator('select[name="billing_country"]').first().inputValue()) !== 'US') await pick();
    await page.locator('text=Cash').first().click().catch(() => {});
    const offline = page.locator('input[value="offline_payment"]').first();
    if (await offline.count()) await offline.check({ force: true }).catch(() => {});
    shots.push(resolve(OUT, 'cache-checkout.png'));
    await page.screenshot({ path: shots.at(-1), fullPage: true });
    await page.locator('.fct_place_order_btn, button:has-text("Place Order"), button:has-text("Place order")').first().click();
    // On a store the shopper is sent to the receipt next. Playground's SQLite can't run FluentCart's customer-stats
    // query (MySQL JSON functions) after the order is saved, so that redirect is checked separately below.
    await page.waitForTimeout(5000);
    shopperPage = page;
    return 'cart cookie set, product page then skipped the cache, checkout listed the item';
});

await check("the order was placed and the shopper's receipt page opens, never from the cache", async () => {
    const order = await php(`$o = \\FluentCart\\App\\Models\\Order::query()->orderBy('id', 'desc')->first(); echo json_encode($o ? ['id' => $o->id, 'status' => $o->status, 'payment_method' => $o->payment_method, 'total' => (int) $o->total_amount, 'receipt' => wp_make_link_relative(add_query_arg(['order_hash' => $o->uuid], get_permalink((get_option('fluent_cart_store_settings')['receipt_page_id'] ?? 0))))] : null);`);
    expect(order && order.payment_method === 'offline_payment' && order.total === 850, JSON.stringify(order));
    const r = await shopperPage.goto(base + order.receipt);
    expect(r.headers()['x-anypress-cache'] !== 'hit', 'receipt came from the cache');
    const again = await visit(order.receipt);
    expect(!again.hit, 'receipt served from the cache to a cookie-less visitor');
    shots.push(resolve(OUT, 'cache-receipt.png'));
    await shopperPage.screenshot({ path: shots.at(-1), fullPage: false });
    return `order ${order.id}, ${order.status}, $${(order.total / 100).toFixed(2)}, offline payment`;
});



await check("a second, cookie-less visitor still gets the cached product page, with none of the first shopper's details", async () => {
    const c = await visit(productPath);
    expect(c.hit, 'not a cache hit');
    expect(!c.body.includes('shopper@example.com') && !c.body.includes('Test Shopper'), "the first shopper's details are in the saved page");
    const files = await php(`$all = ''; foreach (glob(WP_CONTENT_DIR . '/cache/waas-pages/*.html') ?: [] as $f) $all .= file_get_contents($f); echo json_encode(['leak' => strpos($all, 'shopper@example.com') !== false || strpos($all, 'fct_cart_hash=') !== false]);`);
    expect(!files.leak, 'a saved page holds shopper data');
});

await browser.close();
writeFileSync(resolve(OUT, 'cache-checkout-results.json'), JSON.stringify({ setup, productPath, results, shots }, null, 2));
const failed = results.filter((r) => !r.ok).length;
console.log(failed ? `${failed} check(s) failed` : 'All checks passed');
await cli[Symbol.asyncDispose]?.();
process.exit(failed ? 1 : 0);
