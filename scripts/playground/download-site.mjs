// AnyPress Client's Tools > Download my site on WordPress Playground, on a website store (SITE_TYPE=<site type> for
// another), in a real browser at desktop and phone width. The AnyPress API is played by a pre_http_request filter that
// checks each request's signature with the store's site key: no copy yet, then one being made, then one ready.
//
//   cd scripts/playground && npm ci
//   npm run download-site     # run the checks, exit 1 on a failure; screenshots in shots/download-site/
//
// Needs Node 24.18+, Playwright with Chromium, packages/anypress-client/vendor (composer install there) and a
// waas-provisioning-api checkout next to this repository (its store template's roles.json; or ANYPRESS_API_DIR).

import { randomBytes } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.4';
const PORT = Number(process.env.PLAYGROUND_PORT || 9431);
const SITE_TYPE = process.env.SITE_TYPE || 'website';
const SITE_KEY = randomBytes(24).toString('hex');
const API = resolve(process.env.ANYPRESS_API_DIR || resolve(ROOT, '../waas-provisioning-api'));
const ROLES = resolve(API, 'templates/anypress-store/roles.json');
const OUT = resolve(HERE, 'shots/download-site');
mkdirSync(OUT, { recursive: true });

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${WP}.zip`,
    php: '8.3',
    port: PORT,
    blueprint: {
        login: true,
        steps: [
            { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
            { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
            { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(ROLES, 'utf8') },
            { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${SITE_KEY}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}'); define('ANYPRESS_API_URL', 'https://api.anypress.test');\n` },
            { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
            {
                // The API: answers only requests signed with the site key, for this site's own address. The copy is
                // Pending once asked for, and Done the second time the page looks.
                step: 'writeFile',
                path: '/wordpress/wp-content/mu-plugins/anypress-test-api.php',
                data: `<?php
add_filter('pre_http_request', function ($answer, $args, $url) {
    if ($url !== 'https://api.anypress.test/sites/download') return $answer;
    $ok = \\AnyPressClient\\Core\\SignedRequest::verify(getenv('ANYPRESS_SITE_KEY'), $args['headers']['X-AnyPress-Signature'] ?? '', 'POST', '/sites/download', $args['body']);
    $body = json_decode($args['body'], true);
    $asked = (array) get_option('anypress_test_download_asked', []);
    $asked[] = ['signed' => $ok, 'body' => $body];
    update_option('anypress_test_download_asked', $asked);
    if (!$ok || ($body['site'] ?? '') !== wp_parse_url(home_url(), PHP_URL_HOST)) return ['response' => ['code' => 403], 'body' => '{}', 'headers' => [], 'cookies' => []];
    $state = get_option('anypress_test_download', 'none');
    if (!empty($body['start'])) {
        $state = 'pending';
        update_option('anypress_test_download_started', time());
    } elseif ($state === 'pending' && time() - (int) get_option('anypress_test_download_started') >= 5) {
        $state = 'done';
    }
    update_option('anypress_test_download', $state);
    $now = time();
    $download = $state === 'none' ? null : [
        'id' => '3f6d2c1e-8a4b-4f0e-9c7d-5b2a1e0f9d84', 'statusCode' => $state === 'done' ? 1 : 0, 'statusName' => $state === 'done' ? 'Done' : 'Pending',
        'sizeBytes' => $state === 'done' ? 48300000 : null, 'dateCreated' => gmdate('c', $now - 200), 'dateDone' => $state === 'done' ? gmdate('c', $now - 60) : null,
        'expiresAt' => gmdate('c', $now + 7 * 86400), 'fileName' => 'bakery.example-' . gmdate('Y-m-d') . '.zip',
        'url' => $state === 'done' ? 'https://downloads.anypress.test/site.zip' : null,
    ];
    return ['response' => ['code' => 200], 'body' => wp_json_encode(['download' => $download]), 'headers' => [], 'cookies' => []];
}, 10, 3);
`,
            },
        ],
    },
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/anypress-client' }],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');

let failed = 0;
const check = (ok, label) => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${label}`);
    if (!ok) failed++;
};
const reset = () => cli.playground.run({ code: "<?php require '/wordpress/wp-load.php'; delete_option('anypress_test_download'); delete_option('anypress_test_download_asked');" });

const { chromium } = createRequire(import.meta.url)('playwright');
const browser = await chromium.launch();
console.log(`site type: ${SITE_TYPE}`);
for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
    await reset();
    const context = await browser.newContext({ viewport: { width, height } });
    const page = await context.newPage();
    await page.goto(`${base}/wp-admin/`, { waitUntil: 'networkidle' });

    await page.goto(`${base}/wp-admin/tools.php`, { waitUntil: 'networkidle' });
    check((await page.locator('#adminmenu a[href*="page=anypress-download-site"]').count()) > 0, `${label}: Download my site is under Tools`);

    await page.goto(`${base}/wp-admin/tools.php?page=anypress-download-site`, { waitUntil: 'networkidle' });
    check(await page.getByText('There is no copy from the last week yet.').isVisible(), `${label}: no copy yet`);
    if (label === 'desktop') await page.screenshot({ path: `${OUT}/${label}-1-none.png`, fullPage: true });

    await page.getByRole('button', { name: 'Make a copy' }).click();
    await page.waitForLoadState('networkidle');
    check(await page.getByText('Making the copy').isVisible(), `${label}: making the copy after the press`);
    await page.screenshot({ path: `${OUT}/${label}-2-making.png`, fullPage: true });

    // The page looks again on its own after 10 seconds.
    await page.getByRole('link', { name: 'Download zip' }).waitFor({ timeout: 30_000 }).catch(() => {});
    const link = page.getByRole('link', { name: 'Download zip' });
    check((await link.count()) > 0 && (await link.getAttribute('href')) === 'https://downloads.anypress.test/site.zip', `${label}: the page updated itself to the download link`);
    check(await page.getByRole('button', { name: 'Make a new copy' }).isVisible(), `${label}: a new copy can be made`);
    await page.screenshot({ path: `${OUT}/${label}-3-ready.png`, fullPage: true });

    const asked = JSON.parse((await cli.playground.run({ code: "<?php require '/wordpress/wp-load.php'; echo wp_json_encode(get_option('anypress_test_download_asked'));" })).text || '[]');
    check(asked.length >= 3 && asked.every((a) => a.signed === true), `${label}: every request was signed with the site key`);
    check(asked.filter((a) => a.body.start === true).length === 1, `${label}: one press asked for one copy`);
    await context.close();
}

// Someone who is not an administrator doesn't get the page.
const editor = await cli.playground.run({ code: "<?php require '/wordpress/wp-load.php'; $u = wp_create_user('ed', 'pw-ed-123', 'ed@example.com'); (new WP_User($u))->set_role('editor'); wp_set_current_user($u); do_action('admin_menu'); global $submenu; echo wp_json_encode(array_column($submenu['tools.php'] ?? [], 2));" });
check(!editor.text.includes('anypress-download-site'), 'editors: no Download my site page');

await browser.close();
await cli[Symbol.asyncDispose]?.();
console.log(failed ? `${failed} check(s) failed` : 'all checks passed');
process.exit(failed ? 1 : 0);
