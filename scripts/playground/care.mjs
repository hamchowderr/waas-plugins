// Care packages on the shop (AnyPress Host, FluentCartCare) on WordPress Playground with FluentCart and Fluent Support:
// "Create the care products" makes drafts; a plan's product page offers care; the pick leads the website's Care
// section on the account; its link opens FluentCart's checkout for that package; once paid the package belongs to the
// website, "Ask for a change" files a Fluent Support ticket, the ticket's sidebar shows the package and time left, and
// logged time comes off the month. This is the storefront (shop.anypress.io), not a customer store.
//
//   npm run care        # screenshots in shots/care/

import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const HOST = resolve(HERE, '../../packages/anypress-host');
const OUT = resolve(HERE, 'shots/care');
mkdirSync(OUT, { recursive: true });

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${process.env.PLAYGROUND_WP || '7.1.4'}.zip`,
    php: '8.3',
    port: Number(process.env.PLAYGROUND_PORT || 9451),
    blueprint: {
        steps: [
            { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: '/tmp/debug.log', WP_DEBUG_DISPLAY: false } },
            { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(resolve(HERE, 'preview-zips/anypress.zip')) }, options: { activate: true } },
            { step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'fluent-cart' }, options: { activate: true } },
            { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-support.${process.env.FLUENT_SUPPORT || '2.5.0'}.zip` }, options: { activate: true } },
            { step: 'mkdir', path: '/wordpress/wp-content/mu-plugins' },
            { step: 'cp', fromPath: '/wordpress/wp-content/anypress-domains/fake-api.php', toPath: '/wordpress/wp-content/mu-plugins/fake-api.php' },
            { step: 'cp', fromPath: '/wordpress/wp-content/anypress-shop/sqlite-json.php', toPath: '/wordpress/wp-content/mu-plugins/0-sqlite-json.php' },
            { step: 'activatePlugin', pluginPath: 'anypress-host/anypress-host.php' },
        ],
    },
    mount: [
        { hostPath: HOST, vfsPath: '/wordpress/wp-content/plugins/anypress-host' },
        { hostPath: resolve(HERE, 'care'), vfsPath: '/wordpress/wp-content/anypress-preview' },
        { hostPath: resolve(HERE, 'domains'), vfsPath: '/wordpress/wp-content/anypress-domains' },
        { hostPath: resolve(HERE, 'shop-preview'), vfsPath: '/wordpress/wp-content/anypress-shop' },
    ],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
const php = async (code) => (await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` })).text;
console.log(await php("require '/wordpress/wp-content/anypress-preview/seed.php';"));
await php('flush_rewrite_rules(false);');

let failed = false;
const check = (ok, what, detail = '') => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${what}${ok || !detail ? '' : `: ${detail}`}`);
    if (!ok) failed = true;
};
const NS = '\\AnyPressHost\\Commerce\\FluentCart\\';
const boot = `
    $store = new ${NS}FluentCartStore(); $adapter = new ${NS}FluentCartAdapter($store);
    $packs = new ${NS}FluentCartEmailPacks(new \\AnyPressHost\\Core\\ProvisioningApi(new \\AnyPressHost\\Core\\HttpService(new \\AnyPressHost\\Core\\ConfigService())), $adapter, $store);
    $care = new ${NS}FluentCartCare($adapter, $store, $packs);
    $website = (int) \\FluentCart\\App\\Models\\SubscriptionMeta::query()->where('meta_key', 'anypress_tenant_external_id')->value('subscription_id');
`;
const products = JSON.parse(await php(`${boot} echo wp_json_encode(array_map(fn ($p) => [$p['title'], array_column($p['variations'], 'price')], array_merge($care->packages(), $care->services())));`));
console.log('care products:', JSON.stringify(products));
check(products.length === 6 && products[1][0] === 'Care Plus' && products[1][1].join() === '19900,199000', 'the button makes 3 packages (monthly + yearly at 10x) and 3 services');

const { chromium } = createRequire(import.meta.url)('playwright');
const browser = await chromium.launch();
let carePlusMonthly = 0;
for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
    const context = await browser.newContext({ viewport: { width, height } });
    const page = await context.newPage();
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'shopper');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForLoadState('networkidle');

    // 1. The plan's product page.
    const productUrl = (await php(`echo get_permalink(get_page_by_path('community-small', OBJECT, 'fluent-products'));`)).trim();
    await page.goto(productUrl, { waitUntil: 'networkidle' });
    const box = page.locator('.anypress-care-box');
    check((await box.count()) === 1, `${label}: the plan page has the care box`);
    if (!(await box.count())) { console.log((await page.innerText('body')).slice(0, 1200)); continue; }
    check((await box.innerText()).includes('$199 a month'), `${label}: Care Plus shows $199 a month`);
    await box.locator('label', { hasText: 'Care Plus' }).click();
    const cookie = (await context.cookies()).find((c) => c.name === 'anypress_care_pick');
    carePlusMonthly = Number(cookie?.value || 0);
    check(carePlusMonthly > 0, `${label}: picking Care Plus is remembered`);
    await box.scrollIntoViewIfNeeded();
    await page.screenshot({ path: resolve(OUT, `${label}-1-product-page.png`), fullPage: true });

    // 2. The account's website card, before care.
    const portal = (await php("echo \\FluentCart\\App\\Services\\URL::getCustomerDashboardUrl('website');")).trim();
    await page.goto(portal, { waitUntil: 'networkidle' });
    const section = page.locator('details.anypress-acct-care');
    check((await section.count()) === 1, `${label}: the website card has a Care section`);
    await section.locator('summary').click();
    const first = section.locator('.anypress-acct-care-offer').first();
    check((await first.innerText()).includes('Care Plus') && (await first.innerText()).includes('Your pick'), `${label}: the pick from the product page comes first`);
    await page.screenshot({ path: resolve(OUT, `${label}-2-account-offer.png`), fullPage: true });

    // 3. Its link: FluentCart's checkout for Care Plus.
    await first.click();
    await page.waitForLoadState('networkidle');
    const checkout = await page.innerText('body');
    check(checkout.includes('Care Plus'), `${label}: the link opens checkout for Care Plus`, page.url());
    await page.screenshot({ path: resolve(OUT, `${label}-3-checkout.png`), fullPage: true });
    await context.close();
}

// 3b. Care stays out of the shop's product list, and checkout turns it away for a buyer with no website.
const shopUrl = (await php(`echo get_permalink(wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'All products', 'post_content' => '[fluent_cart_products]']));`)).trim();
{
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    await page.goto(shopUrl, { waitUntil: 'networkidle' });
    const list = await page.innerText('body');
    check(list.includes('Community') && !list.includes('Care Plus') && !list.includes('One hour of changes'), 'the shop list shows the plan but no care or services');
    await page.screenshot({ path: resolve(OUT, 'desktop-3b-shop-list.png'), fullPage: true });
    await context.close();
}
const gate = JSON.parse(await php(`${boot}
    $cart = \\FluentCart\\App\\Models\\Cart::query()->where('cart_group', 'instant')->orderBy('created_at', 'desc')->first();
    $ask = fn ($email) => apply_filters('fluent_cart/checkout/validate_data', [], ['data' => ['billing_email' => $email], 'cart' => $cart]);
    echo wp_json_encode(['stranger' => $ask('nobody@example.com'), 'owner' => $ask('shopper@example.com')]);
`));
check(Boolean(gate.stranger?.anypress_care?.no_website), 'checkout turns away care for an email with no website', JSON.stringify(gate));
check(Array.isArray(gate.owner) ? gate.owner.length === 0 : Object.keys(gate.owner).length === 0, 'checkout takes care for the website owner', JSON.stringify(gate));

// 4. Paying: an order and subscription for the checkout's cart, through the Host plugin's handlers.
const paid = await php(`${boot}
    $cart = \\FluentCart\\App\\Models\\Cart::query()->where('cart_group', 'instant')->orderBy('created_at', 'desc')->first();
    $customer = \\FluentCart\\App\\Models\\Customer::query()->where('email', 'shopper@example.com')->first();
    $variation = \\FluentCart\\App\\Models\\ProductVariation::query()->find(${carePlusMonthly});
    $order = \\FluentCart\\App\\Models\\Order::query()->create(['customer_id' => $customer->id, 'status' => 'processing', 'payment_status' => 'paid', 'type' => 'subscription', 'total_amount' => 19900, 'currency' => 'USD']);
    \\FluentCart\\App\\Models\\OrderItem::query()->create(['order_id' => $order->id, 'post_id' => $variation->post_id, 'object_id' => $variation->id, 'quantity' => 1, 'unit_price' => 19900, 'line_total' => 19900, 'payment_type' => 'subscription', 'post_title' => 'Care Plus', 'title' => 'Monthly']);
    \\FluentCart\\App\\Models\\Subscription::query()->create(['customer_id' => $customer->id, 'parent_order_id' => $order->id, 'product_id' => $variation->post_id, 'variation_id' => $variation->id, 'item_name' => 'Care Plus', 'status' => 'active', 'billing_interval' => 'monthly', 'recurring_amount' => 19900, 'recurring_total' => 19900, 'bill_times' => 0]);
    $packs->save_website_on_order(['cart' => $cart, 'order' => $order]);
    $order = \\FluentCart\\App\\Models\\Order::query()->find($order->id);
    $care->order_paid(['order' => $order]);
    echo wp_json_encode(['chosen' => $store->get_order_meta($order, '_anypress_email_pack_website'), 'website' => $website, 'summary' => $care->summary($website)]);
`);
console.log('paid:', paid);
const after = JSON.parse(paid.slice(paid.indexOf('{')));
check(Number(after.chosen) === after.website, 'checkout kept the website on the order');
check(after.summary?.package === 'Care Plus' && after.summary.left === 120, 'once paid, Care Plus belongs to the website with 2 h this month');

// 5. The account with care: time left and "Ask for a change".
for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
    const page = await browser.newPage({ viewport: { width, height } });
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'shopper');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForLoadState('networkidle');
    const portal = (await php("echo \\FluentCart\\App\\Services\\URL::getCustomerDashboardUrl('website');")).trim();
    await page.goto(portal, { waitUntil: 'networkidle' });
    const section = page.locator('details.anypress-acct-care');
    check((await section.locator('summary').innerText()).includes('2 h of 2 h left this month'), `${label}: the card shows this month's time`);
    if (label === 'desktop') {
        await section.locator('textarea').fill('Please add a "Spring workshop" page with a booking button, and put it in the menu.');
        await section.locator('button[type=submit]').click();
        await page.waitForLoadState('networkidle');
        check((await page.innerText('body')).includes('your request is in'), 'sending a request says so');
    }
    await page.screenshot({ path: resolve(OUT, `${label}-4-account-with-care.png`), fullPage: true });
    await page.close();
}

// 6. The team's side: the ticket, its sidebar card, and logging time.
const desk = await php(`${boot}
    $ticket = \\FluentSupport\\App\\Models\\Ticket::query()->orderBy('id', 'desc')->first();
    $widgets = \\FluentSupport\\App\\Services\\ProfileInfoService::getProfileExtraWidgets($ticket->customer);
    wp_set_current_user(1);
    $care->log_time($website, 50, 'Ticket #' . $ticket->id);
    $after = \\FluentSupport\\App\\Services\\ProfileInfoService::getProfileExtraWidgets($ticket->customer);
    echo wp_json_encode(['logged' => $after['anypress_care']['sections'][1] ?? null, 'history' => $care->history($website), 'title' => $ticket->title, 'priority' => $ticket->priority, 'tags' => $ticket->tags()->pluck('title')->all(), 'widget' => $widgets['anypress_care'] ?? null, 'left' => $care->summary($website)['left']]);
`);
console.log('desk:', desk);
const team = JSON.parse(desk.slice(desk.indexOf('{')));
check(team.title === 'Care Plus: change for sams-studio.anypress.site', 'the request is a Fluent Support ticket named for the package and website', team.title);
check(team.widget?.sections?.[0]?.items?.[0]?.subtitle === 'Care Plus · answer within 1 business day', 'the ticket sidebar shows the package and answer time');
check(team.priority === 'medium' && (team.tags || []).includes('Care Plus'), 'the ticket has Care Plus\'s priority (medium) and a "Care Plus" tag', JSON.stringify([team.priority, team.tags]));
check(team.left === 70, 'logged time comes off the month');
check(team.history?.[0]?.minutes === 50 && team.history[0].by !== '', 'the website keeps a time log with who logged it', JSON.stringify(team.history));
check(team.logged?.items?.[0]?.detail === '50 min', 'the ticket sidebar lists the time logged', JSON.stringify(team.logged));

// 7. AnyPress > Care, opened from the sidebar's "Log time": the website's row ready, with its time log.
{
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForLoadState('networkidle');
    const logUrl = team.widget?.links?.[0]?.url ?? '';
    await page.goto(logUrl, { waitUntil: 'networkidle' });
    const row = page.locator('tr.active');
    check(logUrl.includes('&log=') && (await row.count()) === 1, 'the sidebar\'s Log time opens the website\'s row', logUrl);
    const focused = await row.locator('input[name="minutes"][autofocus]').count();
    check(focused === 1, 'the minutes box is the one focused', String(focused));
    await row.locator('summary').click();
    check((await row.innerText()).includes('50 min'), 'the row shows its time log');
    await page.screenshot({ path: resolve(OUT, 'desktop-7-care-page.png'), fullPage: true });
    await context.close();
}

await browser.close();
if (failed) console.error(await php("echo @file_get_contents('/tmp/debug.log');"));
console.log(failed ? 'FAILED' : 'OK: care products, product page, account, checkout, ticket and time');
await cli[Symbol.asyncDispose]?.();
process.exit(failed ? 1 : 0);
