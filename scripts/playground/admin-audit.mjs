// Audit: every template plugin on Playground, every wp-admin page visited, and what a site owner would see that isn't
// theirs to deal with: notices, upgrade and review asks, pop-ups, setup wizards and upsell menu items.
//   SHOTS=<dir> node admin-audit.mjs [--aged] [--client]
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const API = process.env.API_REPO || resolve(ROOT, '../waas-provisioning-api');
const OUT = process.env.SHOTS || '/tmp/admin-audit';
mkdirSync(OUT, { recursive: true });
const AGED = process.argv.includes('--aged');
const CLIENT = process.argv.includes('--client');
// A store is always one of the template's site types (CLAUDE.md): SITE_TYPE=highlevel, website, bookings...
if (CLIENT && !process.env.SITE_TYPE) {
    console.error('Set SITE_TYPE to one of the site types in roles.json (e.g. SITE_TYPE=highlevel).');
    process.exit(2);
}
const build = readFileSync(`${API}/templates/anypress-store/build.sh`, 'utf8');
const list = (name) => build.match(new RegExp(`^${name}="\\n([\\s\\S]*?)\\n"`, 'm'))[1].trim().split('\n').map((l) => l.trim().split(/\s+/));
const org = [...list('PLUGINS'), ...list('OFF_PLUGINS').filter(([s]) => s === 'leadconnector')];
const steps = [
    { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(resolve(HERE, 'preview-zips/anypress.zip')) }, options: { activate: true } },
    ...org.map(([slug, version]) => ({ step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/${slug}.${version}.zip` } })),
    { step: 'installPlugin', pluginData: { resource: 'url', url: `https://github.com/hamchowderr/anypress-blocks/releases/download/v${build.match(/BLOCKS_VERSION=\$\{BLOCKS_VERSION:-([0-9.]+)\}/)[1]}/anypress-blocks.zip` } },
    // The vendored plugins the template ships (VENDORED in build.sh), from its plugins/ folder.
    ...list('VENDORED').map(([slug, version]) => ({ step: 'installPlugin', pluginData: { resource: 'literal', name: `${slug}.zip`, contents: readFileSync(`${API}/templates/anypress-store/plugins/${slug}-${version}.zip`) } })),
];
// The template's own mu-plugin (telemetry and upgrade nags off, install dates set on the first run), as on a store.
steps.push({ step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/anypress-store.php', data: readFileSync(`${API}/templates/anypress-store/mu-plugins/anypress-store.php`, 'utf8') });
for (const extra of (process.env.MU_PLUGINS || '').split(',').filter(Boolean)) steps.push({ step: 'writeFile', path: `/wordpress/wp-content/mu-plugins/${extra.split('/').pop()}`, data: readFileSync(extra, 'utf8') });
// What the template build does once (build.sh): presets.php, the settings every store shares, stands in for each
// plugin's setup wizard.
if (!process.argv.includes('--raw')) {
    steps.push({ step: 'writeFile', path: '/wordpress/presets.php', data: readFileSync(`${API}/templates/anypress-store/presets.php`, 'utf8') });
    steps.push({ step: 'runPHP', code: "<?php require '/wordpress/wp-load.php'; wp_set_current_user(1); require '/wordpress/presets.php';" });
}
if (CLIENT) {
    // A store (AnyPress Client's clean wp-admin) with the template's site types, but no site type of its own, so
    // every plugin stays on and every screen can be checked.
    steps.push({ step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' });
    steps.push({ step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(`${API}/templates/anypress-store/roles.json`, 'utf8') });
    // SITE_TYPE=highlevel (or website...): a store sold as that site type, so only its plugins are on.
    const define = process.env.SITE_TYPE ? ` define('ANYPRESS_TENANT_ROLES', ${JSON.stringify(process.env.SITE_TYPE)});` : '';
    steps.push({ step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true');${define}` });
    steps.push({ step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' });
}
const cli = await runCLI({
    command: 'server', wp: `https://wordpress.org/wordpress-${build.match(/WP_VERSION=\$\{WP_VERSION:-([0-9.]+)\}/)[1]}.zip`, php: '8.3', port: Number(process.env.PLAYGROUND_PORT || 9441),
    blueprint: { steps },
    mount: CLIENT ? [{ hostPath: resolve(ROOT, 'packages/anypress-client'), vfsPath: '/wordpress/wp-content/plugins/anypress-client' }] : [],
    verbosity: 'quiet',
});
const php = async (body) => (await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${body}` })).text;
const report = { aged: AGED, client: CLIENT, pages: [] };
try {
    if (AGED) {
        // 60 days on: every install date and "first seen" option moved back, as on a store that has been live a while.
        console.log(await php(`global $wpdb; $old = time() - 60 * DAY_IN_SECONDS; $n = 0;
            foreach ($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name REGEXP 'install|activat|first|_time|_date|review|rating'") as $o) {
                $v = $o->option_value;
                if (preg_match('/^1[6-9]\\d{8}$/', $v)) { update_option($o->option_name, $old); $n++; }
                elseif (preg_match('/^20\\d\\d-\\d\\d-\\d\\d/', $v)) { update_option($o->option_name, gmdate('Y-m-d H:i:s', $old)); $n++; }
            } echo "aged $n options";`));
    }
    report.options = JSON.parse(await php(`global $wpdb; echo wp_json_encode($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name REGEXP 'install|activat|notice|review|rating|wizard|onboard|setup|dismiss|nag|redirect' ORDER BY option_name"));`));
    // BRAND=<name>: a reseller's store, where AnyPress Client goes by the reseller's name and shows no AnyPress look.
    if (process.env.BRAND) await php(`update_option('anypress_brand_name', ${JSON.stringify(process.env.BRAND)});`);
    const { chromium } = createRequire(import.meta.url)('playwright');
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
    await page.goto(`${cli.serverUrl}/wp-login.php`);
    await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'password'); await page.click('#wp-submit');
    await page.waitForLoadState('networkidle');
    await page.goto(`${cli.serverUrl}/wp-admin/index.php`);
    report.firstLanding = page.url();
    const menu = await page.$$eval('#adminmenu a', (as) => as.map((a) => ({ text: a.innerText.trim().replace(/\s+/g, ' '), href: a.href, top: !a.closest('.wp-submenu') })));
    report.menu = menu;
    if (CLIENT && process.env.SITE_TYPE) {
        // The site type's plugins, and only those, are on (AnyPress Client and the owner's own aside).
        const roles = JSON.parse(readFileSync(`${API}/templates/anypress-store/roles.json`, 'utf8'));
        const want = [...new Set(process.env.SITE_TYPE.split(',').flatMap((t) => roles[t].plugins))].sort();
        const active = JSON.parse(await php("echo wp_json_encode(array_values(get_option('active_plugins')));")).filter((f) => f !== 'anypress-client/anypress-client.php').sort();
        report.sitePlugins = { want, active, ok: JSON.stringify(want) === JSON.stringify(active) };
        console.log(report.sitePlugins.ok ? 'ok   only the site type\'s plugins are on' : `FAIL plugins on: ${active.join(', ')}; want ${want.join(', ')}`);
        if (!report.sitePlugins.ok) process.exitCode = 1;
    }
    if (CLIENT) {
        // The Dashboard home: none of WordPress's own boxes, the store's home in their place (CleanDashboard).
        const dash = await page.evaluate(() => {
            const shown = (sel) => { const el = document.querySelector(sel); return !!el && el.getBoundingClientRect().height > 0; };
            const core = ['#welcome-panel', '#dashboard_right_now', '#dashboard_activity', '#dashboard_quick_press', '#dashboard_primary', '#dashboard_site_health'];
            return {
                core: core.filter(shown),
                boxes: [...document.querySelectorAll('#dashboard-widgets .postbox')].filter((el) => el.getBoundingClientRect().height > 0).map((el) => el.id),
                home: shown('#anypress_home'), site: shown('#anypress_site'), plan: shown('#anypress_plan'),
                links: [...document.querySelectorAll('#anypress_site a')].map((a) => ({ text: a.innerText.trim(), href: a.href })),
            };
        });
        report.dashboard = dash;
        // The admin bar: the site's name, New, Edit and the account; no WordPress logo, comments or included plugins' items (CleanAdminBar).
        const bar = await page.evaluate(() => [...document.querySelectorAll('#wp-admin-bar-root-default > li, #wp-admin-bar-top-secondary > li')].map((li) => li.id.replace('wp-admin-bar-', '')));
        report.adminBar = bar;
        const barOk = !bar.some((id) => ['wp-logo', 'comments', 'customize', 'updates'].includes(id) || /fluent|rank-math|automator|wp-fusion|fluentform/i.test(id));
        console.log(barOk ? `ok   the admin bar is short (${bar.join(', ')})` : `FAIL the admin bar: ${bar.join(', ')}`);
        if (!barOk) process.exitCode = 1;
        const ok = dash.core.length === 0 && dash.home && dash.site && dash.plan;
        console.log(ok ? `ok   the Dashboard shows the store's home (${dash.boxes.join(', ')})` : `FAIL the Dashboard: ${JSON.stringify(dash)}`);
        if (!ok) process.exitCode = 1;
        for (const [name, size] of [['desktop', { width: 1366, height: 900 }], ['phone', { width: 390, height: 844 }]]) {
            await page.setViewportSize(size);
            await page.screenshot({ path: `${OUT}/dashboard-${process.env.SITE_TYPE}${process.env.BRAND ? '-reseller' : ''}-${name}.png`, fullPage: true });
        }
        await page.setViewportSize({ width: 1366, height: 900 });
    }
    if (!menu.length) writeFileSync(`${OUT}/landing.html`, await page.content());
    const only = (process.env.ONLY || '').split(',').filter(Boolean);
    const urls = [...new Set(menu.map((m) => m.href.split('#')[0]))].filter((u) => u.includes('/wp-admin/') && (!only.length || only.some((o) => u.includes(o))));
    for (const [i, url] of urls.entries()) {
        const entry = { url };
        try {
            await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
        } catch (e) { entry.error = String(e).slice(0, 200); }
        await page.waitForTimeout(1500);
        entry.landed = page.url();
        Object.assign(entry, await page.evaluate(() => {
            const vis = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && s.opacity !== '0'; };
            const txt = (el) => el.innerText.trim().replace(/\s+/g, ' ').slice(0, 220);
            const notices = [...document.querySelectorAll('#wpbody-content .notice, #wpbody-content .update-nag, #wpbody-content .error, #wpbody-content .updated, #wpbody-content [class*="notice"], #wpbody-content [class*="nag"], #wpbody-content [class*="banner"], #wpbody-content [class*="upgrade"], #wpbody-content [class*="promo"]')]
                .filter((el) => vis(el) && !el.parentElement.closest('.notice, .update-nag, [class*="notice"], [class*="banner"], [class*="promo"]')).map((el) => ({ cls: String(el.className).slice(0, 120), text: txt(el) })).filter((n) => n.text);
            const popups = [...document.querySelectorAll('body *')].filter((el) => {
                if (el.closest('#adminmenuwrap, #wpadminbar')) return false;
                const s = getComputedStyle(el); if (s.position !== 'fixed') return false;
                const r = el.getBoundingClientRect(); return vis(el) && r.width > 250 && r.height > 120;
            }).map((el) => ({ cls: String(el.className).slice(0, 120), id: el.id, text: txt(el) }));
            const upsell = [...document.querySelectorAll('#wpbody-content a, #wpbody-content button')].filter((el) => vis(el) && /\b(pro|premium|upgrade|unlock|buy|pricing|go pro|get pro|license|rate us|review|★)\b/i.test(el.innerText)).map(txt).slice(0, 25);
            const broken = /There has been a critical error|Fatal error:|Parse error:/i.test(document.body.innerText);
            return { title: document.title, notices, popups, upsell, broken };
        }));
        await page.screenshot({ path: `${OUT}/${String(i).padStart(3, '0')}.png` });
        entry.shot = `${String(i).padStart(3, '0')}.png`;
        if (entry.broken) {
            console.log(`FAIL ${url.replace(cli.serverUrl, '')} shows a PHP error`);
            process.exitCode = 1;
        }
        report.pages.push(entry);
        writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));
        console.log(i, url.replace(cli.serverUrl, ''), entry.notices.length, entry.popups.length, entry.upsell.length);
    }
    // The site itself, as a visitor sees it.
    const front = await page.goto(`${cli.serverUrl}/`, { waitUntil: 'networkidle', timeout: 45000 }).catch(() => null);
    report.front = { status: front?.status() ?? 0, broken: /There has been a critical error|Fatal error:|Parse error:/i.test(await page.innerText('body').catch(() => '')) };
    console.log(report.front.status === 200 && !report.front.broken ? 'ok   the home page loads' : `FAIL the home page: ${report.front.status}${report.front.broken ? ', PHP error' : ''}`);
    if (report.front.status !== 200 || report.front.broken) process.exitCode = 1;
    await browser.close();
} finally {
    writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));
    await cli[Symbol.asyncDispose]?.();
    await cli.server?.close?.();
}
