// End-to-end test of the shop switch (`wp waas shop switch` and the check-shop / switch-shop abilities) on WordPress
// Playground. Boots shop-switch/blueprint.json (WooCommerce, FluentCart and the FluentCart Migrator add-on) with WaaS
// Client from this checkout, seeds a WooCommerce shop (shop-switch/seed.php), then runs the checks, the dry run, the
// switch, the switch again, the abilities and the undo, and compares the result with WooCommerce. It runs twice, on a
// fresh site each time: WooCommerce orders in posts (HPOS off), then in its own order tables (HPOS on).
//
//   cd scripts/playground && npm ci
//   npm run shop-switch                      # both runs, exit 1 on a failure
//   npm run shop-switch -- --hpos=yes        # one run only (yes or no)
//   npm run shop-switch -- --hpos=no --serve # leave the site running at http://127.0.0.1:9402 (admin / password)
//
// Needs packages/waas-client/vendor (composer install in packages/waas-client), and network access to
// downloads.wordpress.org and addons-cdn.fluentcart.com (the add-on's zip, which isn't on wordpress.org).
// Playground stores the database in SQLite, not MariaDB: this checks the PHP and WordPress behaviour.

import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';
import { validateBlueprintDeclaration, wpCLI } from '@wp-playground/blueprints';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/waas-client');
const WP = process.env.PLAYGROUND_WP || '7.1.3';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9402);
const SERVE = process.argv.includes('--serve');
const ONLY = (process.argv.find((arg) => arg.startsWith('--hpos=')) || '').slice(7);
const MODES = ONLY === 'yes' || ONLY === 'no' ? [ONLY] : ['no', 'yes'];
const USER_TOKEN = randomBytes(24).toString('hex');
// Optional: a folder to keep the command's reports in.
const REPORTS = process.env.SHOP_REPORT_DIR;
const keep = (name, text) => REPORTS && (mkdirSync(REPORTS, { recursive: true }), writeFileSync(resolve(REPORTS, name), typeof text === 'string' ? text : JSON.stringify(text, null, 2)));

if (!existsSync(resolve(CLIENT, 'vendor/autoload.php'))) {
    console.error('packages/waas-client/vendor is missing: run composer install in packages/waas-client first.');
    process.exit(1);
}

const blueprint = JSON.parse(readFileSync(resolve(HERE, 'shop-switch/blueprint.json'), 'utf8'));
const validation = await validateBlueprintDeclaration(blueprint);
if (!validation.valid) {
    console.error('shop-switch/blueprint.json is not a valid Blueprint:', JSON.stringify(validation.errors, null, 2));
    process.exit(1);
}
const WP_ZIP = `https://wordpress.org/wordpress-${WP}.zip`;
blueprint.preferredVersions = { php: PHP, wp: WP_ZIP };
blueprint.login = SERVE;
// No background updates or cron: a core update mid-test puts the site in maintenance mode.
blueprint.steps.unshift({ step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } });
blueprint.steps.push({
    step: 'writeFile',
    path: '/wordpress/wp-content/mu-plugins/shop-test-users.php',
    data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user; an editor exists to check permissions.
add_action('init', function () {
    if (!get_user_by('login', 'editor')) {
        wp_insert_user(['user_login' => 'editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
    }
});
add_filter('determine_current_user', function ($id) {
    [$token, $login] = array_pad(explode(':', $_SERVER['HTTP_X_TEST_USER'] ?? '', 2), 2, '');
    $user = hash_equals('${USER_TOKEN}', $token) ? get_user_by('login', $login) : false;
    return $user ? $user->ID : $id;
}, 30);
`,
});

const results = [];
let failedTotal = 0;
for (const hpos of MODES) {
    await runMode(hpos);
}
console.log(`\n${results.length - failedTotal} passed, ${failedTotal} failed`);
if (!SERVE) {
    process.exit(failedTotal ? 1 : 0);
}

async function runMode(hpos) {
    console.log(`\nHPOS ${hpos === 'yes' ? 'on' : 'off'}`);
    const cli = await runCLI({
        command: 'server',
        wp: WP_ZIP,
        php: PHP,
        port: PORT,
        blueprint,
        mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/waas-client' }],
        verbosity: 'quiet',
    });
    const base = cli.serverUrl.replace(/\/$/, '');
    console.log(`WordPress ${WP} on PHP ${PHP} at ${base}`);

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
    async function check(name, fn) {
        try {
            await fn();
            results.push([true, name]);
            console.log(`  ok    ${name}`);
        } catch (error) {
            results.push([false, name]);
            failedTotal++;
            console.log(`  FAIL  ${name}\n        ${error.message}`);
        }
    }
    const expect = (condition, message) => {
        if (!condition) throw new Error(message);
    };
    const short = (value) => JSON.stringify(value).slice(0, 700);
    const row = (report, item) => report.compare.find((r) => r.item === item);

    /** FluentCart's row counts, the galleries, the zones and WooCommerce's own state. */
    async function counts() {
        return php(`global $wpdb; $n = fn ($t) => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$t");
        $galleries = [];
        foreach (get_posts(['post_type' => 'fluent-products', 'numberposts' => -1, 'post_status' => 'any']) as $p) {
            $g = get_post_meta($p->ID, 'fluent-products-gallery-image', true);
            $galleries[$p->post_title] = array_column(is_array($g) ? $g : [], 'id');
        }
        echo json_encode([
            'products' => $n('posts WHERE post_type = "fluent-products"'), 'variations' => $n('fct_product_variations'), 'orders' => $n('fct_orders'),
            'customers' => $n('fct_customers'), 'addresses' => $n('fct_customer_addresses'), 'coupons' => $n('fct_coupons'),
            'transactions' => $n('fct_order_transactions'), 'zones' => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fct_shipping_zones", ARRAY_A),
            'methods' => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fct_shipping_methods ORDER BY id", ARRAY_A),
            'shared_users' => $wpdb->get_col("SELECT user_id FROM {$wpdb->prefix}fct_customers WHERE user_id > 0 GROUP BY user_id HAVING COUNT(*) > 1"),
            'carol' => $wpdb->get_row("SELECT id, email, purchase_count, ltv FROM {$wpdb->prefix}fct_customers WHERE email = 'carol@example.com'", ARRAY_A),
            'galleries' => $galleries,
            'woo_orders' => count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'type' => 'shop_order', 'status' => 'any'])),
            'woo_products' => count(wc_get_products(['limit' => -1, 'return' => 'ids', 'status' => 'any'])),
            'active' => get_option('active_plugins'),
            'hpos' => \\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled(),
        ]);`);
    }

    // ---- Seed the WooCommerce shop.
    const seed = await (async () => {
        const out = await cli.playground.run({ code: readFileSync(resolve(HERE, 'shop-switch/seed.php'), 'utf8').replace('<?php', `<?php $GLOBALS['shop_test_hpos'] = '${hpos}';`) });
        const text = out.text.trim();
        return JSON.parse(text.slice(text.lastIndexOf('{"orders"')));
    })();
    const woo = await php(`$g = []; foreach (wc_get_products(['limit' => -1, 'type' => ['simple', 'variable']]) as $p) { $g[$p->get_name()] = array_merge([(int) $p->get_image_id()], array_map('intval', $p->get_gallery_image_ids())); } echo json_encode($g);`);

    await check('seed: the WooCommerce shop is in place', async () => {
        expect(!seed.errors.length, short(seed.errors));
        expect(seed.hpos_enabled === (hpos === 'yes'), `HPOS ${seed.hpos_enabled}`);
        expect(Object.keys(seed.orders).length === 8 && Object.keys(woo).length === 10, short(seed));
    });

    let dry;
    await check('dry run: reports the shop, the HPOS state and what the switch would do, and changes nothing', async () => {
        const before = await counts();
        dry = await wp('wp waas shop switch --dry-run --format=json --user=admin');
        keep(`hpos-${hpos}-dry-run.json`, dry);
        const p = dry.preflight;
        expect(p.ready === true && p.blockers.length === 0, `preflight ${short(p)}`);
        expect(p.hpos.enabled === (hpos === 'yes'), `hpos ${short(p.hpos)}`);
        const c = p.counts;
        expect(c.products === 10 && c.variable_products === 2 && c.variations === 5 && c.orders === 8 && c.guest_orders === 2 && c.refunds === 2
            && c.coupons === 2 && c.registered_customers === 3 && c.tax_rates === 2 && c.shipping_zones === 1, `counts ${short(c)}`);
        expect(p.subscriptions.in_use === false, `subscriptions ${short(p.subscriptions)}`);
        expect(p.migrator_addon && p.warnings.some((w) => w.includes('beta')) === p.migrator_addon.includes('beta'), `add-on ${short(p)}`);
        expect(dry.migration.done === false && dry.fixes.shipping.zones[0].status === 'will be copied', short(dry.migration) + short(dry.fixes.shipping));
        expect(dry.review.product_tags.length === 5, `tags ${short(dry.review.product_tags)}`);
        const after = await counts();
        expect(after.products === 0 && after.orders === 0 && after.zones.length === 0 && before.orders === 0, `changed: ${short(after)}`);
    });

    await check('without the add-on: the check says where to get it and the switch refuses', async () => {
        await php(`deactivate_plugins('fluent-cart-migrator/fluent-cart-migrator.php');`);
        const inactive = await wp('wp waas shop switch --dry-run --format=json --user=admin');
        expect(inactive.preflight.ready === false && inactive.preflight.blockers.some((b) => b.includes('installed but not active')), short(inactive.preflight));
        const refused = await php(`$r = (new WaaSClient\\Shop\\ShopSwitch())->run(); echo json_encode(is_wp_error($r) ? ['code' => $r->get_error_code(), 'message' => $r->get_error_message()] : ['report' => true]);`);
        expect(refused.code === 'waas_shop_not_ready' && refused.message.includes('not active'), short(refused));
        // Not installed at all: WordPress's plugin list (its cache, for this one request) without the add-on.
        const missing = await php(`$plugins = get_plugins(); unset($plugins['fluent-cart-migrator/fluent-cart-migrator.php']); wp_cache_set('plugins', ['' => $plugins], 'plugins');
            echo json_encode(WaaSClient\\Shop\\ShopSwitch::preflight());`);
        expect(missing.ready === false && missing.blockers.some((b) => b.includes('fluentcart.com/fluentcart-addons') && b.includes('fluent-cart-migrator.zip')), short(missing.blockers));
        const activated = await php(`$r = activate_plugin('fluent-cart-migrator/fluent-cart-migrator.php'); echo json_encode(['error' => is_wp_error($r) ? $r->get_error_message() : null]);`);
        expect(!activated.error, short(activated));
        const back = await wp('wp waas shop switch --dry-run --format=json --user=admin');
        expect(back.preflight.ready === true, short(back.preflight));
    });

    let report;
    await check('switch: the add-on copies the shop, and the gaps are filled', async () => {
        const table = await wp('wp waas shop switch --user=admin');
        keep(`hpos-${hpos}-switch.txt`, table);
        expect(table.includes('WooCommerce and FluentCart compared') && !/database error|Error:/.test(table), table.slice(-1500));
        report = await wp('wp waas shop switch --dry-run --format=json --user=admin');
        keep(`hpos-${hpos}-after.json`, report);
        expect(report.migration.done === true, short(report.migration));
        const now = await counts();
        expect(now.products === 10 && now.variations === 13 && now.orders === 8 && now.coupons === 2, `counts ${short(now)}`);
        expect(now.woo_orders === 8 && now.woo_products >= 10, `WooCommerce changed: ${short(now)}`);
        expect(now.active.includes('woocommerce/woocommerce.php'), 'WooCommerce was turned off');
        expect(now.hpos === (hpos === 'yes'), `HPOS changed: ${now.hpos}`);
    });

    await check('gallery images: every WooCommerce gallery image is in the FluentCart gallery', async () => {
        const now = await counts();
        for (const [name, ids] of Object.entries(woo)) {
            expect(JSON.stringify(now.galleries[name]) === JSON.stringify(ids), `${name}: ${short(now.galleries[name])} (WooCommerce ${short(ids)})`);
        }
    });

    await check('shipping: the zone and its flat rate and free shipping are in FluentCart', async () => {
        const now = await counts();
        expect(now.zones.length === 1 && now.zones[0].name === 'United States' && now.zones[0].region === 'US', short(now.zones));
        const flat = now.methods.find((m) => m.type === 'flat_rate');
        const free = now.methods.find((m) => m.type === 'free_shipping');
        expect(now.methods.length === 2 && flat && free, short(now.methods));
        expect(Number(flat.amount) === 10 && Number(flat.is_enabled) === 1 && JSON.parse(flat.settings).configure_rate === 'per_order', short(flat));
        expect(Number(free.is_enabled) === 1 && Number(free.zone_id) === Number(now.zones[0].id), short(free));
    });

    await check('customers: Carol is one FluentCart customer with both orders', async () => {
        const now = await counts();
        expect(now.shared_users.length === 0, `shared ${short(now.shared_users)}`);
        expect(now.customers === 5, `customers ${now.customers}`);
        expect(Number(now.carol.purchase_count) === 2, short(now.carol));
    });

    await check('review: product tags and the differing tax rates are listed, and no rate is changed', async () => {
        expect(report.review.product_tags.length === 5 && report.review.product_tags.every((t) => t.tags === 'summer'), short(report.review.product_tags));
        const rates = report.review.tax_rates;
        expect(rates.length === 2, short(rates));
        expect(rates.every((r) => r.differences.some((d) => d.startsWith('Name:')) && !r.differences.some((d) => d.startsWith('Rate:'))), short(rates));
        const fc = await php(`global $wpdb; echo json_encode($wpdb->get_results("SELECT country, state, rate, name FROM {$wpdb->prefix}fct_tax_rates WHERE (country = 'US' AND state = 'CA') OR country = 'GB'", ARRAY_A));`);
        expect(fc.some((r) => r.name === 'California State Tax (Standard)') && fc.some((r) => r.name === 'GB Standard Tax'), short(fc));
    });

    await check('compare: products, SKUs, orders, totals, refunds, coupons and customers all match', async () => {
        for (const item of ['Products', 'SKUs', 'Orders', 'Sum of order totals', 'Refunds', 'Coupons', 'Customers']) {
            const r = row(report, item);
            expect(r && r.same, `${item}: ${short(r)}`);
        }
        expect(row(report, 'Sum of order totals').fluentcart === '472.83', short(row(report, 'Sum of order totals')));
        expect(row(report, 'Refunds').woocommerce === '2 (30.00)', short(row(report, 'Refunds')));
    });

    await check('running it again adds nothing', async () => {
        const before = await counts();
        const again = await wp('wp waas shop switch --format=json --user=admin');
        keep(`hpos-${hpos}-again.json`, again);
        const after = await counts();
        expect(Object.values(again.migration.steps).filter((s) => s === 'already done').length >= 3, short(again.migration));
        expect(again.fixes.gallery_images.images === 0 && again.fixes.customers.length === 0, short(again.fixes));
        expect(again.fixes.shipping.zones.every((z) => z.status === 'already copied'), short(again.fixes.shipping));
        for (const key of ['products', 'variations', 'orders', 'customers', 'addresses', 'coupons', 'transactions']) {
            expect(after[key] === before[key], `${key}: ${before[key]} -> ${after[key]}`);
        }
        expect(after.zones.length === 1 && after.methods.length === 2, short([after.zones, after.methods]));
        expect(JSON.stringify(after.galleries) === JSON.stringify(before.galleries), 'galleries changed');
    });

    await check('abilities: check-shop is read-only, switch-shop needs an administrator', async () => {
        const check = await ability('anypress/check-shop', undefined, { method: 'GET' });
        expect(check.status === 200 && check.data.dry_run === true && check.data.preflight.ready === true, `check-shop: HTTP ${check.status} ${short(check.data)}`);
        const editor = await ability('anypress/switch-shop', { undo: false }, { as: 'editor' });
        expect(editor.status === 403, `editor: HTTP ${editor.status}`);
        const nobody = await ability('anypress/check-shop', undefined, { as: null, method: 'GET' });
        expect(nobody.status === 401, `signed out: HTTP ${nobody.status}`);
        const run = await ability('anypress/switch-shop', { undo: false });
        expect(run.status === 200 && run.data.fixes.gallery_images.images === 0, `switch-shop: HTTP ${run.status} ${short(run.data)}`);
    });

    await check('undo takes the fixes back out; switching again puts them back', async () => {
        const undo = await wp('wp waas shop switch --undo --format=json --user=admin');
        keep(`hpos-${hpos}-undo.json`, undo);
        expect(undo.gallery_images === 4 && undo.shipping.zones === 1 && undo.shipping.methods === 2 && undo.customers === 1, short(undo));
        const undone = await counts();
        expect(undone.zones.length === 0 && undone.methods.length === 0, short(undone.zones));
        expect(undone.customers === 6 && undone.shared_users.length === 1, `customers ${undone.customers} ${short(undone.shared_users)}`);
        expect(undone.galleries['T-Shirt'].length === 1 && undone.orders === 8, short(undone.galleries));
        const redo = await ability('anypress/switch-shop', { undo: false });
        expect(redo.status === 200 && redo.data.fixes.gallery_images.images === 6 && redo.data.fixes.customers.length === 1, short(redo.data.fixes));
        const after = await counts();
        expect(after.customers === 5 && after.zones.length === 1 && after.methods.length === 2, short(after));
        expect(Number(after.carol.purchase_count) === 2, short(after.carol));
    });

    await check('no PHP warnings or errors from WaaS Client', async () => {
        const log = await php(`$f = WP_CONTENT_DIR . '/debug.log'; echo json_encode(['log' => file_exists($f) ? file_get_contents($f) : '']);`);
        keep(`hpos-${hpos}-debug.log`, log.log);
        const ours = log.log.split('\n').filter((line) => /waas-client|WaaSClient/.test(line));
        expect(!ours.length, ours.slice(0, 5).join('\n'));
    });

    if (SERVE) {
        console.log(`Left running at ${base} (Ctrl+C to stop).`);
        await new Promise(() => {});
    }
    await cli[Symbol.asyncDispose]();
}
