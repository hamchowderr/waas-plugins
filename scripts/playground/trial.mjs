// One free trial per person (AnyPress Host, FluentCartTrialLimit) on WordPress Playground: a website plan with 7 free
// days shows the trial at checkout; for a customer who had a plan before, checkout takes the trial out of the cart,
// stops once with a note to reload, and the reloaded page charges the plan today. A new customer, and one who only left
// a checkout unfinished, keep the trial.
//
//   npm run trial   # screenshots in shots/trial/

import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const HOST = resolve(HERE, '../../packages/anypress-host');
const OUT = resolve(HERE, 'shots/trial');
mkdirSync(OUT, { recursive: true });

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${process.env.PLAYGROUND_WP || '7.1.4'}.zip`,
    php: '8.3',
    port: Number(process.env.PLAYGROUND_PORT || 9433),
    blueprint: {
        steps: [
            { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: '/tmp/debug.log', WP_DEBUG_DISPLAY: false } },
            { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(resolve(HERE, 'preview-zips/anypress.zip')) }, options: { activate: true } },
            { step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'fluent-cart' }, options: { activate: true } },
            { step: 'mkdir', path: '/wordpress/wp-content/mu-plugins' },
            { step: 'cp', fromPath: '/wordpress/wp-content/anypress-shop/sqlite-json.php', toPath: '/wordpress/wp-content/mu-plugins/0-sqlite-json.php' },
            { step: 'activatePlugin', pluginPath: 'anypress-host/anypress-host.php' },
        ],
    },
    mount: [
        { hostPath: HOST, vfsPath: '/wordpress/wp-content/plugins/anypress-host' },
        { hostPath: resolve(HERE, 'trial'), vfsPath: '/wordpress/wp-content/anypress-preview' },
        { hostPath: resolve(HERE, 'shop-preview'), vfsPath: '/wordpress/wp-content/anypress-shop' },
    ],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
const php = async (code) => {
    try {
        return (await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` })).text;
    } catch (error) {
        const log = (await cli.playground.run({ code: "<?php echo @file_get_contents('/tmp/debug.log');" })).text;
        console.error('PHP failed:', log.slice(-1500));
        process.exit(1);
    }
};
console.log(await php("require '/wordpress/wp-content/anypress-preview/seed.php';"));
const ids = JSON.parse(await php("echo wp_json_encode(get_option('trial_check_ids'));"));

let failed = false;
const check = (ok, label, detail = '') => { console.log(`${ok ? 'ok  ' : 'FAIL'} ${label}${detail ? `: ${detail}` : ''}`); if (!ok) failed = true; };

// Checkout's own validation hook, on this browser's cart (FluentCart's fct_cart_hash cookie), as FluentCart runs it when an order is placed.
const validate = (email, hash) => php(`
    $cart = \\FluentCart\\App\\Models\\Cart::query()->where('cart_hash', '${hash}')->first();
    $errors = apply_filters('fluent_cart/checkout/validate_data', [], ['data' => ['billing_email' => '${email}'], 'cart' => $cart]);
    $cart = \\FluentCart\\App\\Models\\Cart::query()->where('cart_hash', $cart->cart_hash)->first();
    $cart->refreshStalePrices();
    $line = array_values((array) $cart->cart_data)[0] ?? [];
    echo wp_json_encode(['errors' => $errors, 'trial_days' => (int) ($line['other_info']['trial_days'] ?? -1)]);
`);

const { chromium } = createRequire(import.meta.url)('playwright');
const browser = await chromium.launch();
for (const [label, width, height] of [['desk', 1440, 900], ['phone', 390, 844]]) {
    for (const [who, email, keeps] of [['new', 'new@example.com', true], ['unfinished', 'pending@example.com', true], ['returning', 'back@example.com', false]]) {
        const context = await browser.newContext({ viewport: { width, height } });
        const page = await context.newPage();
        // A cart with the plan in it, made with FluentCart's own line builder, handed to the browser as FluentCart's
        // cart cookie. (FluentCart's instant-checkout link takes a database lock that Playground's SQLite lacks.)
        const hash = (await php(`
            $variation = \\FluentCart\\App\\Models\\ProductVariation::query()->with(['product', 'product_detail'])->find(${ids.variation});
            $hash = md5('trial-${label}-${who}');
            \\FluentCart\\App\\Models\\Cart::query()->create(['cart_hash' => $hash, 'cart_group' => 'global', 'stage' => 'draft', 'cart_data' => [\\FluentCart\\App\\Helpers\\CartHelper::generateCartItemFromVariation($variation, 1)]]);
            echo $hash;
        `)).trim();
        await context.addCookies([{ name: 'fct_cart_hash', value: hash, url: base }]);
        const checkoutUrl = (await php("echo get_permalink((new \\FluentCart\\Api\\StoreSettings())->get('checkout_page_id'));")).trim();
        await page.goto(checkoutUrl, { waitUntil: 'networkidle' });
        const before = await page.innerText('body');
        check(before.includes('Free Trial: 7 days'), `${label} ${who}: checkout shows the trial`, before.includes('Free Trial') ? '' : before.slice(0, 300));
        const result = JSON.parse(await validate(email, hash));
        const stopped = Boolean(result.errors?.anypress_trial?.used);
        check(stopped === !keeps, `${label} ${who}: checkout ${keeps ? 'goes through' : 'stops once to reload'}`, JSON.stringify(result.errors));
        check(result.trial_days === (keeps ? 7 : 0), `${label} ${who}: cart keeps ${keeps ? '7' : '0'} free days after FluentCart re-prices it`, String(result.trial_days));

        await page.reload({ waitUntil: 'networkidle' });
        const after = await page.innerText('body');
        check(after.includes('Free Trial: 7 days') === keeps, `${label} ${who}: reloaded checkout ${keeps ? 'still shows' : 'no longer shows'} the trial`);
        if (!keeps) {
            check(JSON.parse(await validate(email, hash)).errors?.anypress_trial === undefined, `${label} ${who}: placing the order again goes through`);
        }
        await page.screenshot({ path: resolve(OUT, `${label}-${who}-checkout.png`), fullPage: true });
        await context.close();
    }
}
await browser.close();

const log = await php("echo @file_get_contents('/tmp/debug.log');");
check(!/Fatal|anypress-host[^\n]*(Warning|Notice)/.test(log), 'no PHP errors from AnyPress Host', log.slice(0, 400));
await cli[Symbol.asyncDispose]?.();
process.exit(failed ? 1 : 0);
