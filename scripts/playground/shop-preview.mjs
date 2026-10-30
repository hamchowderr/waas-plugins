// Builds a copy of the shop on WordPress Playground with its design (test-storefront/remote/design) and serves it, or
// takes screenshots of every page at desktop and phone width.
//
//   npm run shop                 # serve at http://127.0.0.1:9420 (admin / password)
//   npm run shop -- --shots      # build, screenshot each page into shots/shop/, exit
//
// ANYPRESS_THEME=<folder> uses a local AnyPress theme instead of the release the shop pins.

import { mkdirSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const DESIGN = resolve(HERE, '../test-storefront/remote/design');
const THEME = process.env.ANYPRESS_THEME ? resolve(process.env.ANYPRESS_THEME) : null;
const THEME_ZIP = 'https://github.com/hamchowderr/anypress-theme/releases/download/v0.6.0/anypress.zip';
const SHOTS = process.argv.includes('--shots');
const PORT = Number(process.env.PLAYGROUND_PORT || 9420);

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${process.env.PLAYGROUND_WP || '7.1.3'}.zip`,
    php: '8.3',
    port: PORT,
    blueprint: {
        login: !SHOTS,
        steps: [
            { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: '/tmp/debug.log', WP_DEBUG_DISPLAY: false } },
            THEME
                ? { step: 'activateTheme', themeFolderName: 'anypress' }
                : { step: 'installTheme', themeData: { resource: 'url', url: THEME_ZIP }, options: { activate: true } },
            { step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'fluent-cart' }, options: { activate: true } },
            { step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'betterdocs' }, options: { activate: true } },
            { step: 'mkdir', path: '/wordpress/wp-content/mu-plugins' },
            { step: 'cp', fromPath: '/wordpress/wp-content/anypress-design/anypress-shop.php', toPath: '/wordpress/wp-content/mu-plugins/anypress-shop.php' },
            { step: 'cp', fromPath: '/wordpress/wp-content/anypress-preview/sqlite-json.php', toPath: '/wordpress/wp-content/mu-plugins/0-sqlite-json.php' },
        ],
    },
    mount: [
        { hostPath: DESIGN, vfsPath: '/wordpress/wp-content/anypress-design' },
        { hostPath: resolve(HERE, 'shop-preview'), vfsPath: '/wordpress/wp-content/anypress-preview' },
        ...(THEME ? [{ hostPath: THEME, vfsPath: '/wordpress/wp-content/themes/anypress' }] : []),
    ],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
const seeded = await cli.playground.run({
    code: "<?php require '/wordpress/wp-load.php'; $design = '/wordpress/wp-content/anypress-design'; require '/wordpress/wp-content/anypress-preview/seed.php';",
});
console.log(seeded.text, seeded.errors || '');

if (!SHOTS) {
    console.log(`Shop preview at ${base} (admin / password). Ctrl+C to stop.`);
    await new Promise(() => {});
}

// Everything the design wrote, for a block validity check (block markup the editor would flag).
const dump = await cli.playground.run({
    code: "<?php require '/wordpress/wp-load.php'; $out = []; foreach (get_posts(['post_type' => ['page', 'fluent-products', 'wp_template', 'wp_template_part', 'wp_navigation'], 'numberposts' => -1, 'post_status' => 'any']) as $p) { $out[] = ['type' => $p->post_type, 'name' => $p->post_name, 'content' => $p->post_content]; } echo wp_json_encode($out);",
});
mkdirSync(resolve(HERE, 'shots/shop'), { recursive: true });
writeFileSync(resolve(HERE, 'shots/shop/content.json'), dump.text);

const { chromium } = createRequire(import.meta.url)('playwright');
const out = resolve(HERE, 'shots/shop');
mkdirSync(out, { recursive: true });
const pages = { home: '/', plans: '/shop/', plan: '/item/website-small/', pack: '/item/email-pack-10000/', cart: '/cart/', checkout: '/checkout/', account: '/account/', docs: '/docs/', terms: '/terms/' };
const browser = await chromium.launch();
for (const [name, width, height] of [['desk', 1440, 900], ['phone', 390, 844]]) {
    const page = await browser.newPage({ viewport: { width, height } });
    for (const [key, path] of Object.entries(pages)) {
        await page.goto(base + path, { waitUntil: 'networkidle' });
        if (key === 'checkout') {
            // Buy the Small website plan first, so checkout shows a real order.
            await page.goto(base + '/item/website-small/', { waitUntil: 'networkidle' });
            await page.locator('[data-fluent-cart-direct-checkout-button]').first().click();
            await page.waitForLoadState('networkidle');
        }
        if (key === 'plan') {
            // Monthly | Yearly must still pick the plan's yearly price for the buy button.
            await page.locator('.fct-product-tab-nav-item[data-tab="yearly"]').click();
            const href = await page.locator('[data-fluent-cart-direct-checkout-button]').first().getAttribute('href');
            console.log(`yearly tab: buy link ${href}`);
            await page.locator('.fct-product-tab-nav-item[data-tab="monthly"]').click();
        }
        if (key === 'docs') await page.waitForTimeout(3000);
        const wide = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        if (wide) console.log(`${key} ${name}: scrolls sideways`);
        await page.screenshot({ path: `${out}/${key}-${name}.png`, fullPage: true });
    }
    await page.close();
}
await browser.close();
const log = await cli.playground.run({ code: "<?php echo @file_get_contents('/tmp/debug.log');" });
if (log.text.trim()) console.log('debug.log:\n' + log.text.slice(-4000));
await cli[Symbol.asyncDispose]?.();
process.exit(0);
