// Care packages on the shop (AnyPress Host, FluentCartCare) on WordPress Playground with FluentCart and Fluent Support:
// "Create the care products" makes drafts; a plan's product page offers care; the pick leads the website's Care
// section on the account; its link opens FluentCart's checkout for that package; once paid the package belongs to the
// website, "Ask for a change" files a Fluent Support ticket, the ticket's sidebar shows the package and time left, and
// logged time comes off the month. This is the storefront (shop.anypress.io), not a customer store.
//
//   npm run care        # screenshots in shots/care/

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const HOST = resolve(HERE, '../../packages/anypress-host');
const OUT = resolve(HERE, 'shots/care');
// Desktop, laptop, tablet and phone.
const SIZES = [['desktop', 1440, 900], ['laptop', 1280, 800], ['tablet', 820, 1180], ['phone', 390, 844]];
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
check(products.length === 7 && products[1][0] === 'Care Plus' && products[1][1].join() === '19900,199000' && products[6][0] === 'Extra hour for care customers' && products[6][1].join() === '10000', 'the button makes 3 packages (monthly + yearly at 10x) and 4 services, the extra hour at $100 for care customers');

const { chromium } = createRequire(import.meta.url)('playwright');
const browser = await chromium.launch();
let carePlusMonthly = 0;
for (const [label, width, height] of SIZES) {
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
    const tier = section.locator('.anypress-acct-care-tier.is-picked');
    check((await tier.count()) === 1 && (await tier.innerText()).includes('Care Plus') && (await tier.innerText()).includes('Your pick'), `${label}: the pick from the product page is marked`);
    check((await section.locator('.anypress-acct-care-offer:visible').count()) === 3, `${label}: three packages, monthly prices showing`);
    await section.locator('.anypress-acct-care-period label', { hasText: 'Yearly' }).click();
    check((await tier.locator('.is-yearly').innerText()).includes('$1,990'), `${label}: Yearly shows the yearly price`);
    await page.screenshot({ path: resolve(OUT, `${label}-2b-account-offer-yearly.png`), fullPage: true });
    await section.locator('.anypress-acct-care-period label', { hasText: 'Monthly' }).click();
    const first = tier.locator('.anypress-acct-care-offer:visible');
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

// 4. Paying for the package: FluentCart's offline payment can't take subscriptions, and card payments need a payment
// account, so the order and subscription are made here and go through the Host plugin's own handlers.
const paid = await php(`${boot}
    $cart = \\FluentCart\\App\\Models\\Cart::query()->where('cart_group', 'instant')->orderBy('created_at', 'desc')->first();
    $customer = \\FluentCart\\App\\Models\\Customer::query()->where('email', 'shopper@example.com')->first();
    $variation = \\FluentCart\\App\\Models\\ProductVariation::query()->find(${carePlusMonthly});
    $order = \\FluentCart\\App\\Models\\Order::query()->create(['customer_id' => $customer->id, 'status' => 'processing', 'payment_status' => 'paid', 'type' => 'subscription', 'total_amount' => 19900, 'currency' => 'USD']);
    \\FluentCart\\App\\Models\\OrderItem::query()->create(['order_id' => $order->id, 'post_id' => $variation->post_id, 'object_id' => $variation->id, 'quantity' => 1, 'unit_price' => 19900, 'line_total' => 19900, 'payment_type' => 'subscription', 'post_title' => 'Care Plus', 'title' => 'Monthly']);
    \\FluentCart\\App\\Models\\Subscription::query()->create(['customer_id' => $customer->id, 'parent_order_id' => $order->id, 'product_id' => $variation->post_id, 'variation_id' => $variation->id, 'item_name' => 'Care Plus', 'status' => 'active', 'billing_interval' => 'monthly', 'recurring_amount' => 19900, 'recurring_total' => 19900, 'bill_times' => 0]);
    $mails = [];
    add_filter('pre_wp_mail', function ($short, $atts) use (&$mails) { $mails[] = [$atts['to'], $atts['subject']]; return true; }, 10, 2);
    $packs->save_website_on_order(['cart' => $cart, 'order' => $order]);
    $order = \\FluentCart\\App\\Models\\Order::query()->find($order->id);
    $care->order_paid(['order' => $order]);
    echo wp_json_encode(['mails' => $mails, 'chosen' => $store->get_order_meta($order, '_anypress_email_pack_website'), 'website' => $website, 'summary' => $care->summary($website)]);
`);
console.log('paid:', paid);
const after = JSON.parse(paid.slice(paid.indexOf('{')));
check(Number(after.chosen) === after.website, 'checkout kept the website on the order');
check(after.summary?.package === 'Care Plus' && after.summary.left === 120, 'once paid, Care Plus belongs to the website with 2 h this month');
check((after.mails ?? []).filter((m) => m[0] === 'shopper@example.com' && m[1] === 'Care Plus is on for your website').length === 1, 'the owner gets the "Care is on" email', JSON.stringify(after.mails));

// 4b. A one-time service bought for real: FluentCart's checkout with "Pay later", then the shop's "Mark as paid" in
// FluentCart, which runs the Host plugin's order_paid_done handlers as any payment does.
const hourUrl = (await php(`${boot} $hour = array_values(array_filter($care->services(), fn ($s) => $s['title'] === 'One hour of changes'))[0]; echo add_query_arg('anypress_website', $website, $hour['variations'][0]['checkout_url']);`)).trim();
{
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'shopper');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForLoadState('networkidle');
    await page.goto(hourUrl, { waitUntil: 'networkidle' });
    {
        const country = page.locator('select[name="billing_country"]');
        if (await country.count()) {
            await country.selectOption('US', { force: true });
            await country.dispatchEvent('change');
            await page.waitForLoadState('networkidle');
        }
        const state = page.locator('select[name="billing_state"]');
        if (await state.count()) {
            await state.selectOption('CA', { force: true }).catch(() => {});
            await state.dispatchEvent('change').catch(() => {});
        }
        for (const input of await page.locator('form input[required]:visible, form textarea[required]:visible, form input[name="billing_state"]:visible').all()) {
            if ((await input.inputValue()) !== '') continue;
            const name = (await input.getAttribute('name')) ?? '';
            await input.fill(name.includes('email') ? 'shopper@example.com' : name.includes('state') ? 'CA' : name.includes('postcode') ? '94110' : name.includes('phone') ? '5551234567' : 'Ada Baker');
        }
        const offline = page.locator('input[value="offline_payment"]');
        if (await offline.count()) await offline.first().check({ force: true });
        const before = page.url();
        await page.locator('[data-fluent-cart-checkout-page-checkout-button], button:has-text("Place order")').first().click();
        await page.waitForURL((url) => url.toString() !== before, { timeout: 30000 }).catch(() => {});
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: resolve(OUT, `desktop-4b-service-order.png`), fullPage: true });
        check(page.url() !== before, 'placing the order with "Pay later" goes on to the receipt', (await page.innerText('body')).slice(0, 300));
    }
    await context.close();
}
const orderId = Number((await php(`echo (int) \\FluentCart\\App\\Models\\Order::query()->where('payment_method', 'offline_payment')->orderBy('id', 'desc')->value('id');`)).trim());
check(orderId > 0, 'the checkout made an offline order', String(orderId));
{
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    await page.goto(`${base}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForLoadState('networkidle');
    const result = await page.evaluate(async ({ base, orderId }) => {
        const nonce = await (await fetch(`${base}/wp-admin/admin-ajax.php?action=rest-nonce`)).text();
        const res = await fetch(`${base}/wp-json/fluent-cart/v2/orders/${orderId}/mark-as-paid`, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
            body: JSON.stringify({ payment_method: 'offline_payment', vendor_charge_id: '', mark_paid_note: 'Paid by bank transfer' }),
        });
        return [res.status, (await res.text()).slice(0, 300)];
    }, { base, orderId });
    check(result[0] === 200, 'FluentCart\'s "Mark as paid" takes the order', JSON.stringify(result));
    await context.close();
}
const service = JSON.parse((await php(`${boot}
    // FluentCart runs order_paid_done from Action Scheduler, as WP-Cron would on the shop.
    if (class_exists('ActionScheduler_QueueRunner')) { ActionScheduler_QueueRunner::instance()->run('care-check'); }
    $ticket = \\FluentSupport\\App\\Models\\Ticket::query()->orderBy('id', 'desc')->first();
    echo wp_json_encode(['extra' => $care->summary($website)['extra'], 'title' => $ticket ? $ticket->title : '', 'tags' => $ticket ? $ticket->tags()->pluck('title')->all() : [], 'done' => (bool) \\FluentCart\\App\\Models\\Order::query()->find(${orderId})->getMeta('_anypress_care_done')]);
`)).replace(/^[^{]*/, ''));
check(service.extra === 60, 'once paid, the hour is added to the website as extra time', JSON.stringify(service));
check(service.title === 'One hour of changes: sams-studio.anypress.site' && service.tags.includes('One hour of changes'), 'and filed as a tagged Fluent Support ticket', JSON.stringify(service));

// 5. The account with care: time left and "Ask for a change".
for (const [label, width, height] of SIZES) {
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
    check((await section.innerText()).includes('Extra hour for care customers · $100'), `${label}: the card offers the extra hour at the care price`);
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
    $mails = [];
    add_filter('pre_wp_mail', function ($short, $atts) use (&$mails) { $mails[] = $atts['subject']; return true; }, 10, 2);
    $care->log_time($website, 40, 'Ticket #' . $ticket->id . ': more');
    $care->log_time($website, 5, 'Ticket #' . $ticket->id . ': last bit');
    $desk = new ${NS}FluentCartCareDesk($adapter, $store, $care);
    $before = count($desk->late_requests());
    $ticket->waiting_since = (new DateTimeImmutable('-6 days', wp_timezone()))->format('Y-m-d H:i:s');
    $ticket->save();
    $late = $desk->late_requests();
    echo wp_json_encode(['mails' => $mails, 'late_before' => $before, 'late' => $late, 'logged' => $after['anypress_care']['sections'][1] ?? null, 'history' => $care->history($website), 'title' => $ticket->title, 'priority' => $ticket->priority, 'tags' => $ticket->tags()->pluck('title')->all(), 'widget' => $widgets['anypress_care'] ?? null, 'left' => $care->summary($website)['left']]);
`);
console.log('desk:', desk);
const team = JSON.parse(desk.slice(desk.indexOf('{')));
check(team.title === 'Care Plus: change for sams-studio.anypress.site', 'the request is a Fluent Support ticket named for the package and website', team.title);
check(team.widget?.sections?.[0]?.items?.[0]?.subtitle === 'Care Plus · answer within 1 business day', 'the ticket sidebar shows the package and answer time');
check(team.priority === 'medium' && (team.tags || []).includes('Care Plus'), 'the ticket has Care Plus\'s priority (medium) and a "Care Plus" tag', JSON.stringify([team.priority, team.tags]));
check(team.left === 25, 'logged time comes off the month (50 + 40 + 5 of 2 h)', String(team.left));
check(team.history?.map((e) => e.minutes).join() === '5,40,50' && team.history[0].by !== '', 'the website keeps a time log with who logged it', JSON.stringify(team.history));
check(team.logged?.items?.[0]?.detail === '50 min', 'the ticket sidebar lists the time logged', JSON.stringify(team.logged));
check(team.mails?.length === 1 && team.mails[0] === '30 min of care time left this month', 'at a quarter left the owner gets "time running low", once', JSON.stringify(team.mails));
check(team.late_before === 0 && team.late?.length === 1 && team.late[0].package === 'Care Plus', 'a request waiting past Care Plus\'s 1 business day shows as late', JSON.stringify([team.late_before, team.late]));

// 6b. Early next month: the monthly report, for a website that had care all of last month.
const report = JSON.parse((await php(`${boot}
    $mails = [];
    add_filter('pre_wp_mail', function ($short, $atts) use (&$mails) { $mails[] = [$atts['subject'], $atts['message']]; return true; }, 10, 2);
    $last = ${NS}FluentCartCareEmails::last_month();
    $store->update_subscription_meta($website, ${NS}FluentCartCare::SINCE_META, $last);
    $log = $care->history($website);
    foreach ($log as $i => $entry) { $log[$i]['at'] = $last . '-15T12:00:00+00:00'; }
    $store->update_subscription_meta($website, ${NS}FluentCartCare::LOG_META, $log);
    $emails = new ${NS}FluentCartCareEmails($adapter, $store, $care);
    echo wp_json_encode(['sent' => [$emails->send_reports(), $emails->send_reports()], 'mails' => $mails, 'scheduled' => (bool) wp_next_scheduled(${NS}FluentCartCareEmails::REPORT_HOOK)]);
`)).replace(/^[^{]*/, ''));
check(report.sent.join() === '1,0', 'the monthly report goes out once', JSON.stringify(report.sent));
check(/care report$/.test(report.mails?.[0]?.[0] ?? '') && report.mails[0][1].includes('1 h 35 min of your 2 h') && report.mails[0][1].includes('last bit (5 min)'), 'the report lists last month\'s work and time', report.mails?.[0]?.[0]);
check(report.scheduled, 'the daily report check is scheduled');
writeFileSync(resolve(OUT, 'report-email.html'), report.mails?.[0]?.[1] ?? '');

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
    const row = page.locator('.ap-care-site.is-focus');
    check(logUrl.includes('&log=') && (await row.count()) === 1, 'the sidebar\'s Log time opens the website\'s row', logUrl);
    const focused = await row.locator('input[name="minutes"][autofocus]').count();
    check(focused === 1, 'the minutes box is the one focused', String(focused));
    check((await row.innerText()).includes('50 min'), 'the row shows its time log');
    check((await page.locator('.ap-tabs a[aria-current="page"]').innerText()).trim() === 'Care', 'Care is a tab of the AnyPress pages');
    check((await page.innerText('body')).includes('Waiting longer than promised'), 'the Care page lists the late request');
    for (const [label, width, height] of SIZES) {
        await page.setViewportSize({ width, height });
        await page.screenshot({ path: resolve(OUT, `${label}-7-care-page.png`), fullPage: true });
    }
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    check(overflow <= 0, 'the Care page fits a phone without sideways scrolling', String(overflow));
    await context.close();
}

await browser.close();
if (failed) console.error(await php("echo @file_get_contents('/tmp/debug.log');"));
console.log(failed ? 'FAILED' : 'OK: care products, product page, account, checkout, ticket and time');
await cli[Symbol.asyncDispose]?.();
process.exit(failed ? 1 : 0);
