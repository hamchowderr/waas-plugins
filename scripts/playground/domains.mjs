// Domains sold at the shop's own checkout (AnyPress Host, FluentCartDomains) on WordPress Playground, with a fake
// AnyPress API: a customer searches on their Website page, picks a name, fills in the owner, and lands on FluentCart's
// checkout with a yearly "Domain" subscription; paying it registers the domain through the API, and a renewal renews it.
//
//   npm run domains        # screenshots in shots/domains/

import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const HOST = resolve(HERE, '../../packages/anypress-host');
const OUT = resolve(HERE, 'shots/domains');
mkdirSync(OUT, { recursive: true });

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${process.env.PLAYGROUND_WP || '7.1.4'}.zip`,
    php: '8.3',
    port: Number(process.env.PLAYGROUND_PORT || 9431),
    blueprint: {
        steps: [
            { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: '/tmp/debug.log', WP_DEBUG_DISPLAY: false } },
            { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(resolve(HERE, 'preview-zips/anypress.zip')) }, options: { activate: true } },
            { step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'fluent-cart' }, options: { activate: true } },
            { step: 'mkdir', path: '/wordpress/wp-content/mu-plugins' },
            { step: 'cp', fromPath: '/wordpress/wp-content/anypress-preview/fake-api.php', toPath: '/wordpress/wp-content/mu-plugins/fake-api.php' },
            { step: 'cp', fromPath: '/wordpress/wp-content/anypress-shop/sqlite-json.php', toPath: '/wordpress/wp-content/mu-plugins/0-sqlite-json.php' },
            { step: 'activatePlugin', pluginPath: 'anypress-host/anypress-host.php' },
        ],
    },
    mount: [
        { hostPath: HOST, vfsPath: '/wordpress/wp-content/plugins/anypress-host' },
        { hostPath: resolve(HERE, 'domains'), vfsPath: '/wordpress/wp-content/anypress-preview' },
        { hostPath: resolve(HERE, 'shop-preview'), vfsPath: '/wordpress/wp-content/anypress-shop' },
    ],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
const php = async (code) => (await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` })).text;
console.log(await php("require '/wordpress/wp-content/anypress-preview/seed.php';"));
await php("flush_rewrite_rules(false);");

const fail = async (msg) => {
    console.error('FAIL:', msg);
    console.error(await php("echo @file_get_contents('/tmp/debug.log');"));
    process.exit(1);
};

const { chromium } = createRequire(import.meta.url)('playwright');
const browser = await chromium.launch();
for (const [label, width, height] of [['desk', 1440, 900], ['phone', 390, 844]]) {
    const page = await browser.newPage({ viewport: { width, height } });
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'shopper');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForLoadState('networkidle');

    const portal = await php("echo \\FluentCart\\App\\Services\\URL::getCustomerDashboardUrl('website');");
    await page.goto(portal.trim(), { waitUntil: 'networkidle' });
    const section = page.locator('details.anypress-acct-domains');
    if (!(await section.count())) { await page.screenshot({ path: resolve(OUT, 'debug.png'), fullPage: true }); console.log((await page.innerText('body')).slice(0, 1500)); await fail('no "Get your own domain" section on the Website page'); }
    await section.locator('summary').click();
    await section.locator('input[type=search]').fill('mybakery');
    await section.locator('button[type=submit]').click();
    await page.waitForLoadState('networkidle');
    const list = page.locator('.anypress-acct-domain-list li');
    if ((await list.count()) !== 2) await fail(`expected 2 available names, got ${await list.count()}`);
    const first = await list.first().innerText();
    // API $15 then $17 a year, plus the shop's $3: one yearly price of $20.
    if (!first.includes('mybakery.com') || !first.includes('$20.00 a year')) await fail(`price line: ${first}`);
    await page.screenshot({ path: resolve(OUT, `${label}-search.png`), fullPage: true });

    await page.fill('input[name="contact[phone]"]', '+1.5125550100');
    await page.fill('input[name="contact[address1]"]', '1 Main St');
    await page.fill('input[name="contact[city]"]', 'Austin');
    await page.fill('input[name="contact[state]"]', 'TX');
    await page.fill('input[name="contact[zip]"]', '78701');
    await page.click('.anypress-acct-domain-buy button[type=submit]');
    await page.waitForLoadState('networkidle');
    const checkout = await page.content();
    if (!checkout.includes('Domain mybakery.com')) console.log((await page.innerText('body')).slice(0, 1200));
    if (!checkout.includes('Domain mybakery.com')) await fail(`checkout does not show the domain (at ${page.url()})`);
    await page.screenshot({ path: resolve(OUT, `${label}-checkout.png`), fullPage: true });
    await page.close();
}
await browser.close();

// Paying: an order for the checkout's cart is paid, then renewed, through the Host plugin's handlers.
const result = await php(`
    $cart = \\FluentCart\\App\\Models\\Cart::query()->where('cart_group', 'instant')->orderBy('created_at', 'desc')->first();
    $item = $cart ? $cart->cart_data[0] : null;
    echo wp_json_encode(['item' => $item]);
`);
console.log('cart item:', result);
const item = JSON.parse(result).item;
if (!item || item.other_info?.payment_type !== 'subscription' || item.other_info?.repeat_interval !== 'yearly') await fail('the cart item is not a yearly subscription');

const paid = await php(`
    $store = new \\AnyPressHost\\Commerce\\FluentCart\\FluentCartStore();
    $domains = new \\AnyPressHost\\Commerce\\FluentCart\\FluentCartDomains(
        new \\AnyPressHost\\Core\\ProvisioningApi(new \\AnyPressHost\\Core\\HttpService(new \\AnyPressHost\\Core\\ConfigService())),
        new \\AnyPressHost\\Commerce\\FluentCart\\FluentCartAdapter($store), $store
    );
    $cart = \\FluentCart\\App\\Models\\Cart::query()->where('cart_group', 'instant')->orderBy('created_at', 'desc')->first();
    $customer = \\FluentCart\\App\\Models\\Customer::query()->where('email', 'shopper@example.com')->first();
    // Orders made directly, without FluentCart's own events (its emails need a full checkout).
    $order = \\FluentCart\\App\\Models\\Order::query()->create(['customer_id' => $customer->id, 'status' => 'processing', 'payment_status' => 'paid', 'type' => 'subscription', 'total_amount' => 2000, 'currency' => 'USD']);
    $renewal = \\FluentCart\\App\\Models\\Order::query()->create(['customer_id' => $customer->id, 'status' => 'completed', 'payment_status' => 'paid', 'type' => 'renewal', 'parent_id' => $order->id, 'total_amount' => 2000, 'currency' => 'USD']);
    $domains->save_quote_on_order(['cart' => $cart, 'order' => $order]);
    $domains->order_paid(['order' => $order]);
    $domains->order_paid(['order' => $order]);
    $domains->renewed(['order' => $renewal, 'main_order' => $order]);
    echo wp_json_encode(get_option('fake_api_calls'));
`);
const calls = JSON.parse(paid).filter((c) => c.method === 'POST' && c.url.includes('/domains/'));
console.log('API calls:', JSON.stringify(calls, null, 1));
if (calls.length !== 2) await fail(`expected one buy and one renew, got ${calls.length} POSTs`);
if (!calls[0].url.includes('/domains/buy?externalId=ext-bakery') || !/^fluentcart-order-\d+-domain$/.test(calls[0].body.reference) || calls[0].body.contact.city !== 'Austin') await fail('buy call');
if (!calls[1].url.includes('/domains/bought/renew') || !/^fluentcart-renewal-\d+$/.test(calls[1].body.reference)) await fail('renew call');
console.log('OK: search, checkout, registration and renewal');
await cli[Symbol.asyncDispose]?.();
process.exit(0);
