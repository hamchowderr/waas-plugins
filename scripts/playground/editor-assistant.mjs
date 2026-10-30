// The website assistant beside the page editor (WaaS Client's EditorAssistant) on WordPress Playground, in a real
// browser at desktop and phone width. A small local page stands in for the assistant's chat (its /editor page), so
// this checks WaaS Client's side: the Assistant panel opens from the admin bar's link, the chat signs in through the
// store's own handoff (signed with the site key), it receives what the editor has open (the selected block), and a
// change it sends lands in the editor, unsaved, with Undo.
//
//   cd scripts/playground && npm ci
//   npm run editor            # run the checks, exit 1 on a failure; screenshots in shots/editor/
//
// Needs Node 24.18+, Playwright with Chromium, and packages/waas-client/vendor (composer install there).

import { randomBytes } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/waas-client');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9430);
const SITE_KEY = randomBytes(24).toString('hex');
const OUT = resolve(HERE, 'shots/editor');
mkdirSync(OUT, { recursive: true });

const WARMER = '<!-- wp:heading --><h2 class="wp-block-heading">Bread baked at dawn</h2><!-- /wp:heading -->';

// The stand-in chat. Signed out it asks the editor to sign it in; /from-shop (the handoff) signs it in.
const chat = createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/from-shop') {
        const ok = url.searchParams.get('handoff') === 'test-handoff' && url.searchParams.get('back') === '/editor';
        response.writeHead(303, { Location: ok ? '/editor?signed=1' : '/editor?error=bad-handoff' }).end();
        return;
    }
    response.writeHead(200, { 'Content-Type': 'text/html' }).end(`<!doctype html><body style="font-family:sans-serif;margin:8px">
<p id="state">${url.searchParams.get('signed') ? 'Signed in' : 'Signing you in…'}</p>
<script>
window.views = [];
window.applied = [];
const signed = ${JSON.stringify(Boolean(url.searchParams.get('signed')))};
let editorOrigin = null;
if (!signed) parent.postMessage({ source: 'anypress-assistant', type: 'sign-in' }, '*');
addEventListener('message', (event) => {
  if (event.source !== parent || event.data?.source !== 'anypress-editor') return;
  if (event.data.type === 'view') window.views.push(event.data.view);
  if (event.data.type === 'applied') window.applied.push(event.data);
});
window.applyChange = (change) => parent.postMessage({ source: 'anypress-assistant', type: 'apply', id: 'call-' + Date.now(), change }, '*');
if (signed) parent.postMessage({ source: 'anypress-assistant', type: 'ready' }, '*');
</script>`);
}).listen(0);
await new Promise((r) => chat.once('listening', r));
const CHAT = `http://localhost:${chat.address().port}`;

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${WP}.zip`,
    php: '8.3',
    port: PORT,
    blueprint: {
        login: true,
        steps: [
            { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
            { step: 'activatePlugin', pluginPath: 'waas-client/index.php' },
            {
                // A store's site key and the assistant's address; the API's answer to the store's signed handoff
                // request is played here, after checking its signature with the same key.
                step: 'writeFile',
                path: '/wordpress/wp-content/mu-plugins/waas-test-assistant.php',
                data: `<?php
putenv('WAAS_SITE_KEY=${SITE_KEY}');
define('WAAS_API_URL', 'https://api.anypress.test');
define('WAAS_ASSISTANT_URL', '${CHAT}');
add_filter('pre_http_request', function ($answer, $args, $url) {
    if ($url !== 'https://api.anypress.test/oauth/site/store-handoff') return $answer;
    $ok = \\WaaSClient\\Core\\SignedRequest::verify(getenv('WAAS_SITE_KEY'), $args['headers']['X-Waas-Signature'] ?? '', 'POST', '/oauth/site/store-handoff', $args['body']);
    $body = json_decode($args['body'], true);
    update_option('waas_test_handoff_asked', ['signed' => $ok, 'body' => $body]);
    if (!$ok) return ['response' => ['code' => 403], 'body' => '{}', 'headers' => [], 'cookies' => []];
    return ['response' => ['code' => 200], 'body' => wp_json_encode(['url' => $body['assistantUrl'] . '/from-shop?handoff=test-handoff']), 'headers' => [], 'cookies' => []];
}, 10, 3);
`,
            },
            // No welcome guide over the editor.
            { step: 'updateUserMeta', userId: 1, meta: { wp_persisted_preferences: { 'core/edit-post': { welcomeGuide: false }, core: { welcomeGuide: false } } } },
        ],
    },
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/waas-client' }],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
const made = await cli.playground.run({
    code: `<?php require '/wordpress/wp-load.php'; echo wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Fresh bread</h2><!-- /wp:heading -->\n\n<!-- wp:paragraph --><p>Baked every morning.</p><!-- /wp:paragraph -->']);`,
});
const pageId = Number(made.text.trim());

let failed = 0;
const check = (ok, label) => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${label}`);
    if (!ok) failed++;
};

const { chromium } = createRequire(import.meta.url)('playwright');
const browser = await chromium.launch();
for (const [label, width, height] of [['desktop', 1440, 900], ['phone', 390, 844]]) {
    const context = await browser.newContext({ viewport: { width, height } });
    const page = await context.newPage();
    // Playground signs the browser in on its first page.
    await page.goto(`${base}/wp-admin/`, { waitUntil: 'networkidle' });

    // The admin bar's button on the page itself opens its editor with the panel.
    await page.goto(`${base}/?page_id=${pageId}`, { waitUntil: 'networkidle' });
    const button = page.locator('#wp-admin-bar-anypress-assistant a');
    if (label === 'desktop') check(await button.isVisible(), `${label}: Assistant button in the admin bar`);
    const href = await button.getAttribute('href');
    check(Boolean(href && href.includes(`post=${pageId}`) && href.includes('anypress-assistant=1')), `${label}: it opens this page's editor with the panel`);

    await page.goto(href, { waitUntil: 'networkidle' });
    const frame = page.frameLocator('iframe.waas-editor-assistant__frame');
    await frame.locator('#state', { hasText: 'Signed in' }).waitFor({ timeout: 30_000 }).catch(() => {});
    check(await frame.locator('#state', { hasText: 'Signed in' }).isVisible(), `${label}: the panel is open and the chat signed in through the website`);
    const asked = await cli.playground.run({ code: "<?php require '/wordpress/wp-load.php'; echo wp_json_encode(get_option('waas_test_handoff_asked'));" });
    const handoff = JSON.parse(asked.text || 'null');
    check(handoff?.signed === true && handoff?.body?.assistantUrl === CHAT && handoff?.body?.site === new URL(base).hostname, `${label}: the handoff request was signed with the site key`);

    // Select the heading in the page, then the chat hears it as the selected block.
    const canvas = page.frameLocator('iframe[name="editor-canvas"]');
    if (label === 'phone') {
        // On a phone the panel covers the page: close it to pick the block, then open it again from the toolbar.
        await page.getByRole('button', { name: 'Close Settings' }).or(page.locator('.interface-complementary-area-header button[aria-label^="Close"]')).first().click().catch(() => {});
    }
    await canvas.locator('h2', { hasText: 'Fresh bread' }).click();
    if (label === 'phone') await page.getByRole('button', { name: 'Assistant' }).first().click();
    const chatFrame = page.frames().find((f) => f.url().startsWith(CHAT)) ?? null;
    let selected = null;
    for (let i = 0; i < 40 && !selected; i++) {
        const views = (await page.frames().find((f) => f.url().startsWith(CHAT))?.evaluate(() => window.views)) ?? [];
        selected = views.map((v) => v.selected).filter(Boolean).find((b) => b.name === 'core/heading') ?? null;
        if (!selected) await page.waitForTimeout(250);
    }
    check(Boolean(selected && selected.markup.includes('Fresh bread')), `${label}: the chat got the selected heading with its markup`);

    // The assistant's change lands in the editor, unsaved, with Undo.
    const live = page.frames().find((f) => f.url().startsWith(CHAT)) ?? chatFrame;
    await live.evaluate(([clientId, content]) => window.applyChange({ action: 'replace', clientId, content }), [selected?.clientId, WARMER]);
    await canvas.locator('h2', { hasText: 'Bread baked at dawn' }).waitFor({ timeout: 10_000 }).catch(() => {});
    check(await canvas.locator('h2', { hasText: 'Bread baked at dawn' }).isVisible(), `${label}: the change shows in the editor`);
    const notice = page.locator('.components-snackbar', { hasText: 'The assistant changed the page. Save to keep it.' });
    check(await notice.isVisible(), `${label}: a notice says to save, with Undo`);
    const saved = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; echo get_post(${pageId})->post_content;` });
    check(saved.text.includes('Fresh bread') && !saved.text.includes('dawn'), `${label}: nothing is saved until the owner saves`);
    const wide = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    check(!wide, `${label}: no sideways scroll`);
    await page.screenshot({ path: `${OUT}/editor-${label}.png` });

    await notice.getByRole('button', { name: 'Undo' }).click();
    await canvas.locator('h2', { hasText: 'Fresh bread' }).waitFor({ timeout: 10_000 }).catch(() => {});
    check(await canvas.locator('h2', { hasText: 'Fresh bread' }).isVisible(), `${label}: Undo puts it back`);

    // A block that isn't there is refused with a notice, not applied.
    await live.evaluate(() => window.applyChange({ action: 'remove', clientId: 'not-a-block' }));
    await page.waitForTimeout(500);
    const answers = await live.evaluate(() => window.applied);
    check(answers.at(-1)?.ok === false, `${label}: a change to a missing block is refused`);
    await context.close();
}

await browser.close();
chat.close();
await cli[Symbol.asyncDispose]?.();
console.log(failed ? `${failed} check(s) failed` : 'All checks passed');
process.exit(failed ? 1 : 0);
