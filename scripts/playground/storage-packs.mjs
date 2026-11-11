// Storage packs sold at the shop (AnyPress Host, FluentCartStoragePacks) on WordPress Playground, with a fake AnyPress
// API: the customer's Website page links to the $5 a month pack's checkout for that website; the pack's feed (started,
// cancelled), a pause and a resume each tell the store its pack count (PUT /v1/tenants/storage-packs), quantities add
// up, a cancelled pack counts until its paid month ends, and a failed call is retried.
//
//   npm run storage-packs   # screenshots in shots/storage-packs/

import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const HOST = resolve(HERE, '../../packages/anypress-host');
const OUT = resolve(HERE, 'shots/storage-packs');
mkdirSync(OUT, { recursive: true });

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${process.env.PLAYGROUND_WP || '7.1.4'}.zip`,
    php: '8.3',
    port: Number(process.env.PLAYGROUND_PORT || 9432),
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
        { hostPath: resolve(HERE, 'storage-packs'), vfsPath: '/wordpress/wp-content/anypress-preview' },
        { hostPath: resolve(HERE, 'shop-preview'), vfsPath: '/wordpress/wp-content/anypress-shop' },
    ],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
const php = async (code) => (await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` })).text;
console.log(await php("require '/wordpress/wp-content/anypress-preview/seed.php';"));
await php('flush_rewrite_rules(false);');
const ids = JSON.parse(await php("echo wp_json_encode(get_option('storage_check_ids'));"));

const fail = async (msg) => {
    console.error('FAIL:', msg);
    console.error(await php("echo @file_get_contents('/tmp/debug.log');"));
    process.exit(1);
};

// The Website page: an "Add 25 GB storage" link to the pack's checkout, carrying the website.
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
    const link = page.locator('a.anypress-buy-emails', { hasText: 'Add 25 GB storage' });
    if (!(await link.count())) { await page.screenshot({ path: resolve(OUT, 'debug.png'), fullPage: true }); console.log((await page.innerText('body')).slice(0, 1500)); await fail('no "Add 25 GB storage" link on the Website page'); }
    const href = await link.getAttribute('href');
    if (!href.includes(`item_id=${ids.variation}`) || !href.includes(`anypress_website=${ids.website}`)) await fail(`link: ${href}`);
    await page.screenshot({ path: resolve(OUT, `${label}-website-page.png`), fullPage: true });

    await link.click();
    await page.waitForLoadState('networkidle');
    if (!(await page.content()).includes('25 GB more storage')) { console.log((await page.innerText('body')).slice(0, 1200)); await fail(`checkout does not show the pack (at ${page.url()})`); }
    await page.screenshot({ path: resolve(OUT, `${label}-checkout.png`), fullPage: true });
    await page.close();
}
await browser.close();

// The checkout kept the website on the cart; the order gets it from there.
const cartWebsite = await php(`
    $cart = \\FluentCart\\App\\Models\\Cart::query()->where('cart_group', 'instant')->orderBy('created_at', 'desc')->first();
    echo (int) ($cart->checkout_data['anypress_email_pack_website'] ?? 0);
`);
if (Number(cartWebsite) !== ids.website) await fail(`the cart keeps website ${cartWebsite}, not ${ids.website}`);

// Pack subscriptions start, stack, pause, resume and cancel, through the Host plugin's own hooks.
const run = await php(`
    use AnyPressHost\\Commerce\\FluentCart\\AnyPressProvisioningIntegration as I;
    use AnyPressHost\\Commerce\\FluentCart\\FluentCartStoragePacks as P;
    use FluentCart\\App\\Models\\Order;
    use FluentCart\\App\\Models\\Subscription;
    $ids = get_option('storage_check_ids');
    $store = new \\AnyPressHost\\Commerce\\FluentCart\\FluentCartStore();
    $integration = new I(new \\AnyPressHost\\Core\\ProvisioningApi(new \\AnyPressHost\\Core\\HttpService(new \\AnyPressHost\\Core\\ConfigService())), $store);
    $feed = ['enabled' => 'yes', 'product_type' => 'storage_pack'];
    $sent = function () { $calls = get_option('fake_api_calls', []); $last = end($calls); return $last ? $last['method'] . ' ' . wp_parse_url($last['url'], PHP_URL_PATH) . ' packs=' . ($last['body']['packs'] ?? '?') : 'none'; };
    $buy = function (int $quantity) use ($ids, $store, $integration, $feed) {
        $order = Order::query()->create(['customer_id' => $ids['customer'], 'status' => 'processing', 'payment_status' => 'paid', 'type' => 'subscription', 'total_amount' => 500 * $quantity, 'currency' => 'USD']);
        $store->update_order_meta($order, '_anypress_email_pack_website', $ids['website']);
        $sub = Subscription::query()->create(['customer_id' => $ids['customer'], 'parent_order_id' => $order->id, 'product_id' => $ids['product'], 'variation_id' => $ids['variation'], 'item_name' => '25 GB more storage', 'quantity' => $quantity, 'status' => 'active', 'billing_interval' => 'monthly', 'recurring_amount' => 500, 'recurring_total' => 500 * $quantity, 'bill_times' => 0, 'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 20 * 86400)]);
        $integration->processAction($order, ['trigger' => 'subscription_activated', 'feed' => $feed, 'event_data' => ['subscription' => $sub]]);
        return [$order, $sub];
    };
    $out = [];
    [$order1, $pack1] = $buy(1);
    $out['bought one'] = $sent();
    $out['pack1 website'] = $store->get_subscription_meta($pack1->id, P::WEBSITE_META);
    [$order2, $pack2] = $buy(2);
    $out['bought two more'] = $sent();
    $pack2->status = 'paused'; $pack2->save();
    do_action('fluent_cart/subscription_paused', ['subscription' => $pack2]);
    $out['paused the two'] = $sent();
    $pack2->status = 'active'; $pack2->save();
    do_action('fluent_cart/subscription_resumed', ['subscription' => $pack2]);
    $out['resumed the two'] = $sent();
    $pack1->status = 'canceled'; $pack1->save();
    $integration->processAction($order1, ['trigger' => 'subscription_canceled', 'feed' => $feed, 'event_data' => ['subscription' => $pack1]]);
    $out['cancelled one, month still paid'] = $sent();
    $pack1->next_billing_date = gmdate('Y-m-d H:i:s', time() - 60); $pack1->save();
    do_action(P::SYNC_HOOK, $ids['website']);
    $out['its paid month ended'] = $sent();
    update_option('fake_api_down', 1);
    do_action(P::SYNC_HOOK, $ids['website']);
    $out['api down: tries'] = (int) $store->get_subscription_meta($ids['website'], P::ATTEMPTS_META);
    $out['retry scheduled'] = function_exists('as_next_scheduled_action') ? (bool) as_next_scheduled_action(P::SYNC_HOOK, [(int) $ids['website']]) : (bool) wp_next_scheduled(P::SYNC_HOOK, [(int) $ids['website']]);
    delete_option('fake_api_down');
    do_action(P::SYNC_HOOK, $ids['website']);
    $out['api back'] = $sent();
    $out['website still a website'] = \\AnyPressHost\\Commerce\\Commerce::require_adapter()->get_customer_subscription_ids(get_user_by('login', 'shopper')->ID);
    $out['quantity read'] = $store->get_subscription_quantity($pack2->id);
    echo wp_json_encode($out);
`);
console.log(run);
let out;
try { out = JSON.parse(run); } catch { await fail('the pack run did not finish'); }
const expect = {
    'bought one': 'PUT /v1/tenants/storage-packs packs=1',
    'pack1 website': ids.website,
    'bought two more': 'PUT /v1/tenants/storage-packs packs=3',
    'paused the two': 'PUT /v1/tenants/storage-packs packs=1',
    'resumed the two': 'PUT /v1/tenants/storage-packs packs=3',
    'cancelled one, month still paid': 'PUT /v1/tenants/storage-packs packs=3',
    'its paid month ended': 'PUT /v1/tenants/storage-packs packs=2',
    'api down: tries': 1,
    'retry scheduled': true,
    'api back': 'PUT /v1/tenants/storage-packs packs=2',
    'quantity read': 2,
};
for (const [key, want] of Object.entries(expect)) {
    if (String(out[key]) !== String(want)) await fail(`${key}: got ${JSON.stringify(out[key])}, want ${JSON.stringify(want)}`);
}
const notes = await php(`echo wp_json_encode(array_map(fn ($a) => $a['title'] . ': ' . $a['description'], \\FluentCart\\App\\Models\\Activity::query()->where('module_id', get_option('storage_check_ids')['website'])->get()->toArray()));`);
console.log('website notes:', notes);
console.log('OK: Website page link, checkout, start, stack, pause, resume, cancel to the end of the paid month, retry');
await cli[Symbol.asyncDispose]?.();
process.exit(0);
