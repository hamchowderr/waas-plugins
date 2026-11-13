// Builds the ready-made seller storefront (waas-provisioning-api templates/seller-storefront) on WordPress Playground the
// way its build.sh does, and serves it or screenshots it.
//
//   node storefront-preview.mjs                # serve at http://127.0.0.1:9430 (admin / password)
//   node storefront-preview.mjs --shots <dir>  # screenshot the pages and the seller's wp-admin, exit
//   SETUP='{"siteTypes":["online-store"],"audiences":["shops"]}' node storefront-preview.mjs ...  # as a seller's
//     application left it: the storefront's first visit narrows the shop to those site types and customers
//   BRAND='{"name":"…","color":"#2dd4bf","supportEmail":"…","logoFile":"logo.png","iconFile":"icon.png"}' node storefront-preview.mjs ...
//     with the seller's dashboard Branding applied: title, logo, icon, accent colour and Support mailbox
//
// API=<waas-provisioning-api checkout> (default ../../../waas-provisioning-api), HOST_ZIP=<anypress-host.zip>.

import { cpSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const API = resolve(process.env.API || resolve(HERE, '../../../waas-provisioning-api'));
const TEMPLATES = resolve(API, 'templates');
const ZIPS = resolve(HERE, 'preview-zips');
const HOST_ZIP = resolve(process.env.HOST_ZIP || resolve(ZIPS, 'anypress-host.zip'));
const SHOTS = process.argv.includes('--shots') ? resolve(process.argv[process.argv.indexOf('--shots') + 1]) : null;
const PORT = Number(process.env.PLAYGROUND_PORT || 9430);

// build.sh's PLUGINS list, read from the template so the preview uses the same pins.
const build = readFileSync(resolve(TEMPLATES, 'seller-storefront/build.sh'), 'utf8');
const pins = build.match(/^PLUGINS="\n([\s\S]*?)\n"/m)[1].trim().split('\n').map((l) => l.trim().split(/\s+/));

// What build.sh puts next to shop.php: the shop design with this template's brand.json, the store template's site types
// and the API's price list.
const STAGE = mkdtempSync(resolve(tmpdir(), 'storefront-shop-'));
const DESIGN = resolve(HERE, '../test-storefront/remote/design');
cpSync(DESIGN, resolve(STAGE, 'design'), { recursive: true });
cpSync(resolve(TEMPLATES, 'seller-storefront/brand.json'), resolve(STAGE, 'brand.json'));
cpSync(resolve(TEMPLATES, 'seller-storefront/shop.php'), resolve(STAGE, 'shop.php'));
cpSync(resolve(TEMPLATES, 'seller-storefront/pages.php'), resolve(STAGE, 'pages.php'));
cpSync(resolve(TEMPLATES, 'anypress-store/mu-plugins/anypress-patterns.php'), resolve(STAGE, 'anypress-patterns.php'));
cpSync(resolve(TEMPLATES, 'anypress-store/roles.json'), resolve(STAGE, 'roles.json'));
cpSync(resolve(TEMPLATES, 'seller-storefront/audiences.json'), resolve(STAGE, 'audiences.json'));
cpSync(resolve(TEMPLATES, 'seller-storefront/help.json'), resolve(STAGE, 'help.json'));
writeFileSync(resolve(STAGE, 'pricing.json'), execFileSync('node', ['--input-type=module', '-e', "const { PRICE_LIST } = await import(process.argv[1]); console.log(JSON.stringify(PRICE_LIST));", resolve(API, 'src/pricing.ts')]));

const literal = (file, name) => ({ resource: 'literal', name, contents: readFileSync(file) });
const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${process.env.PLAYGROUND_WP || '7.1.4'}.zip`,
    php: '8.3',
    port: PORT,
    blueprint: {
        login: !SHOTS,
        steps: [
            // A ready-made storefront has its key in wp-config (src/storefronts.ts); the address answers nothing here.
            { step: 'defineWpConfigConsts', consts: {
                WP_DEBUG: true, WP_DEBUG_LOG: '/tmp/debug.log', WP_DEBUG_DISPLAY: false,
                ANYPRESS_API_BASE_URL: 'http://127.0.0.1:9', ANYPRESS_API_KEY: 'preview', ANYPRESS_API_SECRET: 'preview',
                ANYPRESS_HOST_KEY_ENCRYPTION_KEY: 'preview-preview-preview-preview-',
            } },
            { step: 'setSiteOptions', options: { blogname: 'Brightside Websites', permalink_structure: '/%postname%/' } },
            { step: 'installTheme', themeData: literal(resolve(ZIPS, 'anypress.zip'), 'anypress.zip'), options: { activate: true } },
            ...pins.map(([slug, version]) => ({ step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/${slug}.${version}.zip` }, options: { activate: true } })),
            { step: 'installPlugin', pluginData: literal(resolve(ZIPS, 'fluent-toolkit.zip'), 'fluent-toolkit.zip'), options: { activate: true } },
            { step: 'installPlugin', pluginData: literal(resolve(ZIPS, 'anypress-blocks.zip'), 'anypress-blocks.zip'), options: { activate: true } },
            { step: 'installPlugin', pluginData: literal(HOST_ZIP, 'anypress-host.zip'), options: { activate: true } },
            { step: 'mkdir', path: '/wordpress/wp-content/mu-plugins' },
            { step: 'cp', fromPath: '/wordpress/wp-content/sf/seller-storefront/mu-plugins/seller-storefront.php', toPath: '/wordpress/wp-content/mu-plugins/seller-storefront.php' },
            { step: 'cp', fromPath: '/wordpress/wp-content/sf/seller-storefront/mu-plugins/seller-storefront-admin.php', toPath: '/wordpress/wp-content/mu-plugins/seller-storefront-admin.php' },
            { step: 'cp', fromPath: '/wordpress/wp-content/sf/anypress-store/mu-plugins/anypress-crm-lifecycle.php', toPath: '/wordpress/wp-content/mu-plugins/anypress-crm-lifecycle.php' },
            { step: 'cp', fromPath: '/wordpress/wp-content/sfshop/design/anypress-shop.php', toPath: '/wordpress/wp-content/mu-plugins/anypress-shop.php' },
            { step: 'cp', fromPath: '/wordpress/wp-content/preview/sqlite-json.php', toPath: '/wordpress/wp-content/mu-plugins/0-sqlite-json.php' },
        ],
    },
    mount: [
        { hostPath: TEMPLATES, vfsPath: '/wordpress/wp-content/sf' },
        { hostPath: STAGE, vfsPath: '/wordpress/wp-content/sfshop' },
        { hostPath: resolve(HERE, 'shop-preview'), vfsPath: '/wordpress/wp-content/preview' },
    ],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');

// build.sh's "Install and configure" and "Presets" steps.
const seeded = await cli.playground.run({ code: `<?php
require '/wordpress/wp-load.php';
wp_set_current_user(1);
foreach (get_posts(['post_type' => ['post', 'page'], 'post_status' => 'any', 'numberposts' => -1]) as $p) { wp_delete_post($p->ID, true); }
(new \\FluentCart\\App\\CPT\\Pages())->createPages();
update_option('show_on_front', 'page');
update_option('page_on_front', get_page_by_path('shop')->ID);
update_option('default_comment_status', 'closed');
update_option('blog_public', 0);
@mkdir('/wordpress/sfbuild'); @mkdir('/wordpress/crm');
copy('/wordpress/wp-content/sf/seller-storefront/presets.php', '/wordpress/sfbuild/presets.php');
copy('/wordpress/wp-content/sf/anypress-store/mu-plugins/anypress-patterns.php', '/wordpress/sfbuild/anypress-patterns.php');
copy('/wordpress/wp-content/sf/anypress-store/crm/setup.php', '/wordpress/crm/setup.php');
copy('/wordpress/wp-content/sf/seller-storefront/crm-emails.json', '/wordpress/crm/emails.json');
require '/wordpress/sfbuild/presets.php';
require '/wordpress/crm/setup.php';
\\FluentCrm\\App\\Models\\Funnel::query()->update(['status' => 'published']);
flush_rewrite_rules();
echo "seeded front=", get_post(get_option('page_on_front'))->post_name, "\\n";
` });
console.log(seeded.text, seeded.errors || '');
// build.sh's "Shop" step: plans, packs, the shop design and the pages around it.
const shop = await cli.playground.run({ code: "<?php require '/wordpress/wp-load.php'; wp_set_current_user(1); require '/wordpress/wp-content/sfshop/shop.php';" });
console.log(shop.text, shop.errors || '');
if (process.env.SETUP) {
    // What the API does for a seller's application (src/storefronts.ts) and what build.sh keeps for the first visit.
    const narrowed = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php';
update_option('anypress_storefront_setup', ${JSON.stringify(process.env.SETUP)});
update_option('admin_email', 'owner@brightside.example');
delete_option('seller_storefront_owner_applied');
@mkdir('/wordpress/wp-content/anypress-storefront');
foreach (['pages.php', 'brand.json', 'audiences.json', 'anypress-patterns.php'] as $f) { copy("/wordpress/wp-content/sfshop/$f", "/wordpress/wp-content/anypress-storefront/$f"); }
$copy = function ($from, $to) use (&$copy) { @mkdir($to); foreach (scandir($from) as $f) { if ($f[0] === '.') continue; is_dir("$from/$f") ? $copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f"); } };
$copy('/wordpress/wp-content/sfshop/design', '/wordpress/wp-content/anypress-storefront/design');
echo "setup ", get_option('anypress_storefront_setup'), "\\n";` });
    console.log(narrowed.text, narrowed.errors || '');
    const started = Date.now();
    const first = await fetch(`${base}/`);
    await first.text();
    // The first request after setup (often WordPress's own cron request) remakes the pages; wait for it to finish.
    for (let i = 0; i < 120; i++) {
        const done = await cli.playground.run({ code: "<?php require '/wordpress/wp-load.php'; echo get_option('seller_storefront_pages_made') ? 'yes' : 'no';" });
        if (done.text.trim() === 'yes') break;
        await new Promise((r) => setTimeout(r, 2000));
    }
    console.log(`pages remade ${first.status} in ${Math.round((Date.now() - started) / 1000)}s`);
    const after = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php';
echo 'plans published: ', implode(', ', get_posts(['post_type' => 'fluent-products', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'post_name', 'orderby' => 'name', 'order' => 'ASC']) ? array_map(fn ($p) => $p->post_name, get_posts(['post_type' => 'fluent-products', 'post_status' => 'publish', 'numberposts' => -1])) : []), "\\n";
$hub = get_page_by_path('who-we-help');
echo 'who we help: ', $hub ? $hub->post_status . ' ' . implode(', ', array_map(fn ($p) => $p->post_name, get_children(['post_parent' => $hub->ID, 'post_type' => 'page', 'post_status' => 'publish']))) : 'none', "\\n";
echo 'who we help pages: ', $hub ? implode(', ', array_map(fn ($p) => $p->post_name . '=' . $p->post_status, get_children(['post_parent' => $hub->ID, 'post_type' => 'page', 'post_status' => 'any']))) : '', "\\n";
echo 'kit log: ', implode(' | ', preg_grep('/Seller storefront|Fatal/', @file('/tmp/debug.log') ?: [])), "\\n";` });
    console.log(after.text, after.errors || '');
}

if (process.env.BRAND) {
    // What the API sends when the seller saves the dashboard's Branding page (src/branding.ts). Images given as files
    // are served by this Playground, as the API serves them.
    const brand = JSON.parse(process.env.BRAND);
    for (const kind of ['logo', 'icon']) {
        const file = brand[`${kind}File`];
        delete brand[`${kind}File`];
        if (!file) continue;
        await cli.playground.writeFile(`/wordpress/brand-${kind}.png`, new Uint8Array(readFileSync(file)));
        brand[`${kind}Url`] = `${base}/brand-${kind}.png?v=1`;
    }
    await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; update_option('anypress_storefront_brand', ${JSON.stringify(JSON.stringify(brand))});` });
    await (await fetch(`${base}/`)).text();
    const applied = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php';
$styles = json_decode(WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles(wp_get_theme())['post_content'] ?? '{}', true);
$primary = array_values(array_filter($styles['settings']['color']['palette']['theme'] ?? [], fn ($c) => $c['slug'] === 'primary'))[0]['color'] ?? '';
$box = class_exists('\\FluentSupport\\App\\Models\\MailBox') ? \\FluentSupport\\App\\Models\\MailBox::where('is_default', 'yes')->first() : null;
echo 'branding: title=', get_option('blogname'), ' logo=', wp_get_attachment_url((int) get_option('site_logo')) ?: '-', ' icon=', get_site_icon_url() ?: '-', ' primary=', $primary, ' mailbox=', $box ? $box->email : '-', "\\n";
echo 'branding log: ', implode(' | ', preg_grep('/branding/', @file('/tmp/debug.log') ?: [])), "\\n";` });
    console.log(applied.text, applied.errors || '');
}

if (!SHOTS) {
    console.log(`Storefront preview at ${base} (admin / password). Ctrl+C to stop.`);
    await new Promise(() => {});
}

const { chromium } = (await import('node:module')).createRequire(import.meta.url)('playwright');
mkdirSync(SHOTS, { recursive: true });
const browser = await chromium.launch();
const pages = (process.env.PAGES ?? ',plans,item/website-small,who-we-help,who-we-help/shops,who-we-help/churches,about,questions,contact,support,affiliates,book-a-call,docs,docs/use-your-own-domain,terms,checkout,account').split(',').filter((p, i) => p || i === 0);
for (const [label, viewport] of [['desktop', { width: 1440, height: 900 }], ['phone', { width: 390, height: 844 }]]) {
    const ctx = await browser.newContext({ viewport });
    const page = await ctx.newPage();
    for (const p of pages) {
        await page.goto(`${base}/${p}${p ? '/' : ''}`, { waitUntil: 'networkidle' }).catch(() => {});
        await page.screenshot({ path: `${SHOTS}/front-${p.replace(/\//g, '-') || 'home'}-${label}.png`, fullPage: true });
    }
    await ctx.close();
}
// The seller's wp-admin, signed in.
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
await page.goto(`${base}/wp-login.php`);
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'password');
await page.click('#wp-submit');
await page.waitForLoadState('networkidle');
const admin = ['index.php', 'admin.php?page=anypress-admin', 'admin.php?page=anypress-admin-settings', 'admin.php?page=anypress-admin-settings&tab=cancellations',
    'admin.php?page=anypress-admin-settings&tab=discount', 'admin.php?page=anypress-admin-settings&tab=domains', 'admin.php?page=anypress-admin-settings&tab=emails',
    'admin.php?page=anypress-care', 'admin.php?page=fluent-cart#/products', 'admin.php?page=fluent-cart#/settings', 'admin.php?page=fluentcrm-admin#/funnels', 'site-editor.php', 'plugins.php'];
for (const a of admin) {
    await page.goto(`${base}/wp-admin/${a}`, { waitUntil: 'networkidle', timeout: 60000 }).catch(() => {});
    await page.waitForTimeout(2500);
    await page.screenshot({ path: `${SHOTS}/admin-${a.replace(/[^a-z0-9]+/gi, '-')}.png`, fullPage: true });
}
const menu = await page.$$eval('#adminmenu > li > a', (as) => as.map((a) => a.innerText.trim().replace(/\s+/g, ' ')));
console.log('admin menu:', menu.join(' | '));
const submenus = await page.$$eval('#adminmenu .wp-submenu a', (as) => as.map((a) => a.innerText.trim().replace(/\s+/g, ' ')));
console.log('admin submenus:', submenus.join(' | '));
await page.goto(`${base}/wp-admin/plugins.php`, { waitUntil: 'networkidle' }).catch(() => {});
const rows = await page.$$eval('#the-list tr[data-plugin]', (trs) => trs.map((tr) => tr.dataset.plugin.split('/')[0] + ': ' + [...tr.querySelectorAll('.row-actions a, .plugin-version-author-uri a')].map((a) => a.innerText.trim()).join(', ')));
console.log('plugin links:\n  ' + rows.join('\n  '));
console.log('host deactivate warns:', await page.$eval('tr[data-plugin^="anypress-host/"] .deactivate a', (a) => a.getAttribute('onclick') || 'no').catch(() => 'no row'));
await browser.close();
const log = await cli.playground.run({ code: "<?php echo @file_get_contents('/tmp/debug.log');" });
console.log('debug.log tail:', log.text.split('\n').slice(-30).join('\n'));
await cli[Symbol.asyncDispose]?.();
process.exit(0);
