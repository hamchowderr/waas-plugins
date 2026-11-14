// AnyPress Client's Offers (FluentCart > Offers) on a Playground store of the online-store site type: the owner makes
// an offer page from the Offers screen and points a second product at a thank-you page; a shopper buys the first
// product and FluentCart's own after-payment link (fluent_cart/payment/success_url) opens the offer, with FluentCart's
// checkout button for the offer and the shopper's receipt on it; "No Thanks" goes on to the thank-you page and still
// shows the receipt; the offer's checkout button opens FluentCart's checkout for the offer; a product without a rule
// keeps FluentCart's own receipt page.
//
//   npm run offers     # screenshots and results in shots/offers/
//
// Needs packages/anypress-client/vendor (composer install in packages/anypress-client), a waas-provisioning-api
// checkout next to this repository (or ANYPRESS_API_DIR), Playwright with Chromium, and network access to
// downloads.wordpress.org. FluentCart is the store template's pinned version unless FLUENT_CART is set.

import { createHash, createHmac, randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { runCLI } from '@wp-playground/cli';

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
const OUT = process.env.OUT_DIR || resolve(HERE, 'shots/offers');
mkdirSync(OUT, { recursive: true });
const pins = Object.fromEntries([...readFileSync(resolve(TEMPLATE, 'build.sh'), 'utf8').matchAll(/^(fluent-cart(?:-migrator)?) (\S+) [0-9a-f]{64}$/gm)].map((m) => [m[1], m[2]]));
const FLUENT_CART = process.env.FLUENT_CART || pins['fluent-cart'];
const MIGRATOR = pins['fluent-cart-migrator'];
if (!FLUENT_CART || !MIGRATOR) throw new Error('FluentCart pins not found in the store template build.sh');

const blueprint = {
    login: false,
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, DISABLE_WP_CRON: true } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(resolve(HERE, 'preview-zips/anypress.zip')) }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-cart.${FLUENT_CART}.zip` }, options: { activate: true } },
        // The product route makes products with FluentCart's importer (its Migrator add-on), as on a store.
        { step: 'installPlugin', pluginData: { resource: 'literal', name: 'fluent-cart-migrator.zip', contents: readFileSync(resolve(TEMPLATE, `plugins/fluent-cart-migrator-${MIGRATOR}.zip`)) }, options: { activate: false } },
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(resolve(TEMPLATE, 'roles.json'), 'utf8') },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${SITE_KEY}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}');\n` },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'Offers Test Bakery', permalink_structure: '/%postname%/' } },
        // Playground's SQLite has no MySQL GET_LOCK/RELEASE_LOCK, which FluentCart's checkout rate limiter uses (stores
        // run MariaDB); without this every order attempt is answered 429.
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-sqlite-locks.php', data: "<?php add_action('init', function () { $GLOBALS['wpdb']->hide_errors(); }); add_filter('query', function ($q) { return preg_match('/^\\s*SELECT (GET|RELEASE)_LOCK\\(/i', $q) ? \"SELECT '1'\" : $q; });\n" },
    ],
};

const cli = await runCLI({
    command: 'server',
    php: '8.3',
    wp: '7.1.4',
    port: 9419,
    blueprint,
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/anypress-client' }],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
console.log(`Playground at ${base}, site type ${SITE_TYPE}, WordPress 7.1.4, PHP 8.3, FluentCart ${FLUENT_CART}`);

async function php(code) {
    const out = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` });
    const text = out.text.trim();
    return JSON.parse(text.slice(text.search(/[[{"0-9tfn]/)));
}
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

// The store as the template sets it up: FluentCart's own pages, offline payment on, a published thank-you page.
const setup = await php(`
    wp_set_current_user(1);
    (new \\FluentCart\\App\\CPT\\Pages())->createPages();
    fluent_cart_update_option('fluent_cart_payment_settings_offline_payment', ['is_active' => 'yes', 'payment_mode' => 'live']);
    $s = get_option('fluent_cart_store_settings');
    $thanks = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Thanks For Your Order', 'post_content' => "<!-- wp:heading --><h2 class=\\"wp-block-heading\\">Enjoy Your Bread</h2><!-- /wp:heading -->\\n<!-- wp:shortcode -->\\n[fluent_cart_receipt]\\n<!-- /wp:shortcode -->"]);
    echo json_encode(['checkout' => wp_make_link_relative(get_permalink($s['checkout_page_id'])), 'receipt' => wp_make_link_relative(get_permalink($s['receipt_page_id'])), 'thanks' => $thanks]);`);
console.log('Setup', setup);

const made = await site('POST', '/products', {
    products: [
        { title: 'Sourdough Loaf', description: '<p>Baked every morning.</p>', url: 'https://example.com/sourdough', variants: [{ price: '8.50', sku: 'SD-1' }] },
        { title: 'Butter Crock', description: '<p>Keeps butter soft.</p>', url: 'https://example.com/crock', variants: [{ price: '14.00', sku: 'BC-1' }] },
        { title: 'Rye Loaf', description: '<p>Dark and dense.</p>', url: 'https://example.com/rye', variants: [{ price: '9.00', sku: 'RY-1' }] },
    ],
});
expect(made.status === 200, JSON.stringify(made));
const [loaf, crock, rye] = made.data.results.map((r) => r.id);
await php(`foreach ([${loaf}, ${crock}, ${rye}] as $id) {
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        // SQLite lacks the JSON_MERGE_PATCH FluentCart's first rating backfill uses (stores run MariaDB).
        $d = \\FluentCart\\App\\Models\\ProductDetail::query()->where('post_id', $id)->first();
        $o = $d->other_info ?: []; $o['rating_breakdown'] = $o['rating_breakdown'] ?? []; $o['rating_count'] = $o['rating_count'] ?? 0; $d->other_info = $o; $d->fulfillment_type = 'digital'; $d->save();
        \\FluentCart\\App\\Models\\ProductVariation::query()->where('post_id', $id)->update(['fulfillment_type' => 'digital']);
    } echo json_encode(1);`);
const crockVariation = await php(`echo json_encode((int) \\FluentCart\\App\\Models\\ProductVariation::query()->where('post_id', ${crock})->first()->id);`);

const browser = await chromium.launch();
const shots = [];
const shot = async (page, name, full = true) => {
    shots.push(resolve(OUT, name));
    await page.screenshot({ path: shots.at(-1), fullPage: full });
};

// The owner, in wp-admin.
const admin = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
await admin.goto(`${base}/wp-login.php`);
await admin.fill('#user_login', 'admin');
await admin.fill('#user_pass', 'password');
await admin.click('#wp-submit');
await admin.waitForURL(/wp-admin/);

let offerPage;
await check('FluentCart > Offers is in the menu and makes an offer page for buyers of a product', async () => {
    await admin.goto(`${base}/wp-admin/admin.php?page=fluent-cart`);
    expect(await admin.locator('#adminmenu a[href="admin.php?page=anypress-offers"]').count(), 'no Offers item under FluentCart');
    await admin.goto(`${base}/wp-admin/admin.php?page=anypress-offers`);
    await shot(admin, 'offers-screen-empty.png');
    const form = admin.locator('form:has(input[name="action"][value="anypress_offers_new"])');
    await form.locator('select[name="after"]').selectOption(String(loaf));
    await form.locator('select[name="offer"]').selectOption(String(crockVariation));
    await form.locator('select[name="decline"]').selectOption(String(setup.thanks));
    await form.locator('input[type="submit"]').click();
    await admin.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 30000 });
    offerPage = Number(new URL(admin.url()).searchParams.get('post'));
    const saved = await php(`$p = get_post(${offerPage}); echo json_encode(['status' => $p->post_status, 'title' => $p->post_title, 'button' => strpos($p->post_content, '[fluent_cart_checkout_button variation_id="${crockVariation}"') !== false, 'receipt' => strpos($p->post_content, '[fluent_cart_receipt]') !== false, 'rules' => get_option('anypress_offers')]);`);
    expect(saved.status === 'draft' && saved.button && saved.receipt, JSON.stringify(saved));
    expect(JSON.stringify(saved.rules) === JSON.stringify([{ product: loaf, page: offerPage }]), JSON.stringify(saved.rules));
    return `draft "${saved.title}" opened in the editor`;
});

await check('the Offers table sends a second product to its own thank-you page, and warns about the draft', async () => {
    await admin.goto(`${base}/wp-admin/admin.php?page=anypress-offers`);
    expect(await admin.locator('text=Publish the page so buyers go there.').count(), 'no draft warning');
    await admin.locator('select[name="rules[1][product]"]').selectOption(String(crock));
    await admin.locator('select[name="rules[1][page]"]').selectOption(String(setup.thanks));
    await admin.locator('input[type="submit"][value="Save Offers"]').click();
    await admin.waitForURL(/anypress_saved=rules/);
    const rules = await php(`echo json_encode(get_option('anypress_offers'));`);
    expect(JSON.stringify(rules) === JSON.stringify([{ product: loaf, page: offerPage }, { product: crock, page: setup.thanks }]), JSON.stringify(rules));
    await php(`wp_update_post(['ID' => ${offerPage}, 'post_status' => 'publish']); echo json_encode(1);`);
    await admin.goto(`${base}/wp-admin/admin.php?page=anypress-offers`);
    await shot(admin, 'offers-screen.png');
    expect(!(await admin.locator('text=Publish the page so buyers go there.').count()), 'draft warning after publishing');
});

// The shopper buys the loaf with offline payment.
const shopper = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
// Playground's SQLite can't run the JSON query FluentCart's instant checkout link uses to reuse a cart (stores run
// MariaDB), so the shopper buys through the product page's Add To Cart and the checkout page, in a fresh browser each
// time so the cart holds only that product.
async function buy(product) {
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    await page.goto(base + (await php(`echo json_encode(wp_make_link_relative(get_permalink(${product})));`)));
    const add = page.locator('[data-fluent-cart-add-to-cart-button], .fct-add-to-cart-btn, button:has-text("Add To Cart"), button:has-text("Add to cart")').first();
    await add.waitFor({ timeout: 20000 });
    await add.click();
    await page.waitForFunction(() => document.cookie.includes('fct_cart_hash'), null, { timeout: 20000 });
    await page.goto(base + setup.checkout);
    await page.waitForSelector('select[name="billing_country"], input[name="billing_email"]', { timeout: 30000 });
    const fill = async (sel, val) => {
        const el = page.locator(sel).first();
        if (await el.count()) await el.fill(val);
    };
    await page.evaluate(() => {
        const el = document.querySelector('select[name="billing_country"]');
        if (el) {
            el.value = 'US';
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1500);
    await page.evaluate(() => {
        const el = document.querySelector('select[name="billing_state"]');
        if (el) {
            el.value = 'OR';
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    await fill('input[name="billing_email"], input[type="email"]', 'shopper@anypress.example');
    await fill('input[name="billing_full_name"], input[name="billing_first_name"]', 'Test Shopper');
    await fill('input[name="billing_last_name"]', 'Shopper');
    await fill('input[name="billing_address_1"]', '1 Main Street');
    await fill('input[name="billing_city"]', 'Portland');
    await fill('input[name="billing_postcode"]', '97201');
    // FluentCart reloads the address fields when the country changes, so the country is set again once the rest is in.
    for (let i = 0; i < 3 && (await page.locator('select[name="billing_country"]').first().inputValue().catch(() => 'US')) !== 'US'; i++) {
        await page.locator('select[name="billing_country"]').first().selectOption('US', { force: true }).catch(() => {});
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);
    }
    const state = page.locator('select[name="billing_state"]').first();
    if (await state.count()) await state.selectOption('OR', { force: true }).catch(() => {});
    else await fill('input[name="billing_state"]', 'OR');
    await page.locator('text=Cash').first().click().catch(() => {});
    const offline = page.locator('input[value="offline_payment"]').first();
    if (await offline.count()) await offline.check({ force: true }).catch(() => {});
    await shot(page, `checkout-${product}.png`);
    await page.locator('.fct_place_order_btn, button:has-text("Place Order"), button:has-text("Place order")').first().click();
    await page.waitForTimeout(6000);
    await shot(page, `checkout-${product}-after.png`);
    await page.context().close();
}
// FluentCart's after-payment link for the newest order, made by FluentCart itself (PaymentHelper::successUrl, which
// runs fluent_cart/payment/success_url). Playground's SQLite can't finish the redirect FluentCart sends after an
// offline order (its customer-stats query uses MySQL JSON functions), so the link is read here and opened next.
const afterPayment = () => php(`$o = \\FluentCart\\App\\Models\\Order::query()->orderBy('id', 'desc')->first(); $t = \\FluentCart\\App\\Models\\OrderTransaction::query()->where('order_id', $o->id)->orderBy('id', 'desc')->first();
    echo json_encode(['order' => $o->id, 'items' => $o->order_items->pluck('post_title'), 'url' => wp_make_link_relative((new \\FluentCart\\App\\Services\\Payments\\PaymentHelper('offline_payment'))->successUrl($t)), 'hash' => $t->uuid]);`);

let first;
await check('after paying for the loaf, FluentCart sends the shopper to the offer page, with the receipt link', async () => {
    await buy(loaf);
    first = await afterPayment();
    expect(first.items.includes('Sourdough Loaf'), JSON.stringify(first));
    const offerPath = await php(`echo json_encode(wp_make_link_relative(get_permalink(${offerPage})));`);
    expect(first.url.startsWith(offerPath) && first.url.includes(`trx_hash=${first.hash}`), `${first.url} (offer page ${offerPath})`);
    return first.url;
});

await check("the offer page shows the offer, FluentCart's checkout button and the shopper's receipt", async () => {
    await shopper.goto(base + first.url);
    await shopper.waitForSelector('text=Yes, I Want It', { timeout: 20000 });
    expect(await shopper.locator('text=Sourdough Loaf').count(), 'receipt for the loaf not on the offer page');
    expect(await shopper.locator('text=Butter Crock').count(), 'offer product name not on the page');
    await shot(shopper, 'offer-page-desktop.png');
    const phone = await (await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 })).newPage();
    await phone.goto(base + first.url);
    await phone.waitForSelector('text=Yes, I Want It', { timeout: 20000 });
    const wide = await phone.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    await shot(phone, 'offer-page-phone.png');
    expect(!wide, 'offer page scrolls sideways on a phone');
});

await check('"No Thanks" goes to the thank-you page and still shows the receipt', async () => {
    const href = await shopper.locator('a.wp-block-button__link:has-text("No Thanks")').getAttribute('href');
    expect(href.includes(`trx_hash=${first.hash}`), `No Thanks link lost the receipt: ${href}`);
    await shopper.locator('a.wp-block-button__link:has-text("No Thanks")').click();
    await shopper.waitForSelector('text=Enjoy Your Bread', { timeout: 20000 });
    expect(await shopper.locator('text=Sourdough Loaf').count(), 'receipt not shown on the thank-you page');
    await shot(shopper, 'thank-you-page.png');
    return href;
});

await check("the offer's Yes button opens FluentCart's checkout for the offer", async () => {
    await shopper.goto(base + first.url);
    await shopper.locator('text=Yes, I Want It').first().click();
    await shopper.waitForSelector('.fct-modal-checkout, [class*="modal"] >> text=Butter Crock, text=Butter Crock >> visible=true', { timeout: 30000 }).catch(() => {});
    await shopper.waitForTimeout(3000);
    const visible = await shopper.locator('text=Butter Crock').filter({ visible: true }).count();
    await shot(shopper, 'offer-checkout.png', false);
    expect(visible > 0, 'Butter Crock not in a visible checkout after Yes');
});

await check("after paying for the offer, the offer's own rule sends the shopper to the thank-you page", async () => {
    await buy(crock);
    const second = await afterPayment();
    const thanksPath = await php(`echo json_encode(wp_make_link_relative(get_permalink(${setup.thanks})));`);
    expect(second.items.includes('Butter Crock') && second.url.startsWith(thanksPath) && second.url.includes(second.hash), JSON.stringify(second));
    return second.url;
});

await check("a product without a rule keeps FluentCart's own receipt page", async () => {
    await buy(rye);
    const third = await afterPayment();
    expect(third.items.includes('Rye Loaf') && third.url.startsWith(setup.receipt), JSON.stringify(third));
    return third.url;
});

if (results.some((r) => !r.ok)) console.log('debug.log', await php(`echo json_encode(substr((string) @file_get_contents(WP_CONTENT_DIR . '/debug.log'), -3000));`));
await browser.close();
writeFileSync(resolve(OUT, 'offers-results.json'), JSON.stringify({ siteType: SITE_TYPE, fluentCart: FLUENT_CART, setup, results, shots }, null, 2));
const failed = results.filter((r) => !r.ok).length;
console.log(failed ? `${failed} check(s) failed` : 'All checks passed');
await cli[Symbol.asyncDispose]?.();
process.exit(failed ? 1 : 0);
