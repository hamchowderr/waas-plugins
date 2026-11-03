// The website assistant beside the page editor (AnyPress Client's EditorAssistant) on WordPress Playground, in a real
// browser at desktop and phone width. A small local page stands in for the assistant's chat (its /editor page), so
// this checks AnyPress Client's side: the Assistant panel opens from the admin bar's link, the chat signs in through the
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

const ORIGINAL = '<!-- wp:heading --><h2 class="wp-block-heading">Fresh bread</h2><!-- /wp:heading -->\n\n<!-- wp:paragraph --><p>Baked every morning.</p><!-- /wp:paragraph -->';
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
if (!signed) parent.postMessage({ source: 'site-assistant', type: 'sign-in' }, '*');
addEventListener('message', (event) => {
  if (event.source !== parent || event.data?.source !== 'site-editor') return;
  if (event.data.type === 'view') window.views.push(event.data.view);
  if (event.data.type === 'applied') window.applied.push(event.data);
});
window.applyChange = (change) => parent.postMessage({ source: 'site-assistant', type: 'apply', id: 'call-' + Date.now(), change }, '*');
if (signed) parent.postMessage({ source: 'site-assistant', type: 'ready' }, '*');
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
    code: `<?php require '/wordpress/wp-load.php'; echo wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => ${JSON.stringify(ORIGINAL)}]);`,
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
    const button = page.locator('#wp-admin-bar-site-assistant a');
    if (label === 'desktop') check(await button.isVisible(), `${label}: Assistant button in the admin bar`);
    const href = await button.getAttribute('href');
    check(Boolean(href && href.includes(`post=${pageId}`) && href.includes('site-assistant=1')), `${label}: it opens this page's editor with the panel`);

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
        // On a phone the panel covers the page: close it to pick the block, then open it again from Options, where
        // WordPress lists plugin panels at that width.
        await page.locator('.interface-complementary-area-header button[aria-label^="Close"]').first().click();
    }
    await canvas.locator('h2', { hasText: 'Fresh bread' }).click();
    if (label === 'phone') {
        await page.getByRole('region', { name: 'Editor top bar' }).getByRole('button', { name: 'Options' }).click();
        await page.locator('.components-dropdown-menu__menu [role^=menuitem]', { hasText: /^Assistant$/ }).click();
        await page.locator('iframe.waas-editor-assistant__frame').waitFor({ timeout: 10_000 });
    }
    const chatFrame = page.frames().find((f) => f.url().startsWith(CHAT)) ?? null;
    let selected = null;
    for (let i = 0; i < 120 && !selected; i++) {
        const views = (await page.frames().find((f) => f.url().startsWith(CHAT))?.evaluate(() => window.views)) ?? [];
        selected = views.map((v) => v.selected).filter(Boolean).find((b) => b.name === 'core/heading') ?? null;
        if (!selected) await page.waitForTimeout(250);
    }
    check(Boolean(selected && selected.markup.includes('Fresh bread')), `${label}: the chat got the selected heading with its markup`);

    // The assistant's change lands in the editor, unsaved, with Undo.
    let live = page.frames().find((f) => f.url().startsWith(CHAT)) ?? chatFrame;
    await live.evaluate(([clientId, content]) => window.applyChange({ action: 'replace', clientId, content }), [selected?.clientId, WARMER]);
    await canvas.locator('h2', { hasText: 'Bread baked at dawn' }).waitFor({ timeout: 10_000 }).catch(() => {});
    check(await canvas.locator('h2', { hasText: 'Bread baked at dawn' }).isVisible(), `${label}: the change shows in the editor`);
    // A card at the top of the panel says what changed, with Undo and Save; WordPress's dark pop-up is not used.
    const card = page.locator('.waas-editor-assistant__changes');
    await card.waitFor({ timeout: 5_000 }).catch(() => {});
    check(await card.isVisible() && (await card.innerText()).includes('Heading changed') && (await card.innerText()).includes('Not saved'),
        `${label}: a quiet line says what changed and that it isn't saved`);
    check(await card.getByRole('button', { name: 'Undo' }).isVisible() && await card.getByRole('button', { name: 'Save' }).isVisible(), `${label}: the card has Undo and Save`);
    check(!(await page.locator('.components-snackbar').isVisible().catch(() => false)), `${label}: no dark pop-up over the page`);
    {
        // Inside the panel, under its header and above the chat, so it covers nothing.
        const box = await card.boundingBox();
        const header = await page.locator('.interface-complementary-area-header').first().boundingBox();
        const chatBox = await page.locator('iframe.waas-editor-assistant__frame').boundingBox();
        check(Boolean(box && header && chatBox && box.y >= header.y + header.height && box.y + box.height <= chatBox.y + 1),
            `${label}: the card sits between the panel's header and the chat`);
        const save = await card.getByRole('button', { name: 'Save' }).boundingBox();
        check(Boolean(save && box && save.x + save.width <= box.x + box.width + 1 && box.height <= 56), `${label}: the line is one row and its buttons fit`);
    }
    const saved = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; echo get_post(${pageId})->post_content;` });
    check(saved.text.includes('Fresh bread') && !saved.text.includes('dawn'), `${label}: nothing is saved until the owner saves`);
    const wide = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    check(!wide, `${label}: no sideways scroll`);
    const panel = await page.locator('.interface-complementary-area:has(.waas-editor-assistant)').evaluate((el) => ({
        width: el.getBoundingClientRect().width,
        scrolls: el.scrollHeight > el.clientHeight + 1,
    }));
    check(label === 'phone' ? panel.width >= width - 1 : panel.width >= 370, `${label}: the panel is ${Math.round(panel.width)}px wide`);
    check(!panel.scrolls, `${label}: only the chat scrolls, not the panel around it`);
    await page.screenshot({ path: `${OUT}/editor-${label}.png` });

    await card.getByRole('button', { name: 'Undo' }).click();
    await canvas.locator('h2', { hasText: 'Fresh bread' }).waitFor({ timeout: 10_000 }).catch(() => {});
    check(await canvas.locator('h2', { hasText: 'Fresh bread' }).isVisible(), `${label}: Undo puts it back`);
    await page.waitForTimeout(500);
    check(!(await card.isVisible().catch(() => false)), `${label}: with nothing left unsaved, the card goes away`);

    // Two changes, then Save from the card: the page is saved and the card says so.
    const firstHeading = await page.evaluate(() => wp.data.select('core/block-editor').getBlocks().find((b) => b.name === 'core/heading')?.clientId);
    await live.evaluate(([clientId, content]) => window.applyChange({ action: 'replace', clientId, content }), [firstHeading, WARMER]);
    await canvas.locator('h2', { hasText: 'Bread baked at dawn' }).waitFor({ timeout: 10_000 }).catch(() => {});
    await live.evaluate((content) => window.applyChange({ action: 'insert', content }), '<!-- wp:paragraph --><p>Open every day from 7.</p><!-- /wp:paragraph -->');
    await page.waitForTimeout(800);
    check((await card.innerText().catch(() => '')).includes('2 changes') && await card.getByRole('button', { name: 'Undo all' }).isVisible().catch(() => false),
        `${label}: several changes are counted, with Undo all`);
    await page.screenshot({ path: `${OUT}/editor-two-changes-${label}.png` });
    await card.getByRole('button', { name: 'Save' }).click();
    await page.locator('.waas-editor-assistant__changes.is-saved').waitFor({ timeout: 15_000 }).catch(() => {});
    check(await page.locator('.waas-editor-assistant__changes.is-saved').isVisible(), `${label}: after Save the card says the page is saved`);
    await page.screenshot({ path: `${OUT}/editor-saved-${label}.png` });
    const kept = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; echo get_post(${pageId})->post_content;` });
    check(kept.text.includes('dawn') && kept.text.includes('Open every day') && kept.text.includes('Baked every morning'), `${label}: Save from the card keeps both changes and the rest of the page`);
    if (process.env.DEBUG_EDITOR) console.log(kept.text);
    // Put the page back for the next run.
    await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; wp_update_post(['ID' => ${pageId}, 'post_content' => ${JSON.stringify(ORIGINAL)}]);` });

    // Undo all takes back every change since the last save.
    const headingNow = await page.evaluate(() => wp.data.select('core/block-editor').getBlocks().find((b) => b.name === 'core/heading')?.clientId);
    await live.evaluate(([clientId, content]) => window.applyChange({ action: 'replace', clientId, content }), [headingNow, '<!-- wp:heading --><h2 class="wp-block-heading">One</h2><!-- /wp:heading -->']);
    await page.waitForTimeout(400);
    await live.evaluate((content) => window.applyChange({ action: 'insert', content }), '<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->');
    await page.waitForTimeout(600);
    await card.getByRole('button', { name: 'Undo all' }).click().catch(() => {});
    await page.waitForTimeout(600);
    if (process.env.DEBUG_EDITOR) console.log('after undo all:', JSON.stringify(await page.evaluate(() => wp.data.select('core/block-editor').getBlocks().map((b) => b.name + ':' + (b.attributes.content?.text ?? b.attributes.content ?? '')))), 'card:', await card.innerText().catch(() => '-'));
    check(!(await canvas.locator('h2', { hasText: /^One$/ }).count()) && !(await canvas.locator('p', { hasText: /^Two$/ }).count()), `${label}: Undo all takes back every change`);

    // The chat can clear the selection and ask for a wider panel.
    await page.evaluate(() => wp.data.dispatch('core/block-editor').selectBlock(wp.data.select('core/block-editor').getBlocks()[0].clientId));
    await live.evaluate(() => parent.postMessage({ source: 'site-assistant', type: 'clear-selection' }, '*'));
    await page.waitForTimeout(300);
    check(!(await page.evaluate(() => wp.data.select('core/block-editor').getSelectedBlockClientId())), `${label}: the chat can clear the selected block`);
    await live.evaluate(() => parent.postMessage({ source: 'site-assistant', type: 'width', wide: true }, '*'));
    await page.waitForTimeout(300);
    check(await page.evaluate(() => document.body.classList.contains('waas-editor-assistant-wide')), `${label}: the chat can ask for a wider panel`);
    await live.evaluate(() => parent.postMessage({ source: 'site-assistant', type: 'width', wide: false }, '*'));
    check(!(await page.locator('.components-guide, .edit-post-welcome-guide').isVisible().catch(() => false)), `${label}: no welcome guide over the page`);

    // Ctrl + . closes and opens the panel; on a phone a round button opens it while it is closed.
    // Focus on the editor itself; inside the chat the chat handles the shortcut.
    await page.evaluate(() => document.activeElement?.blur());
    await page.keyboard.press('Control+.');
    await page.waitForTimeout(500);
    if (process.env.DEBUG_EDITOR) console.log('area after shortcut:', await page.evaluate(() => [wp.data.select('core/interface').getActiveComplementaryArea('core'), document.activeElement?.tagName, document.activeElement?.className]));
    check(!(await page.locator('iframe.waas-editor-assistant__frame').isVisible().catch(() => false)), `${label}: Ctrl + . closes the panel`);
    const fab = page.locator('.waas-editor-assistant__fab');
    if (label === 'phone') {
        check(await fab.isVisible().catch(() => false), `${label}: a round Assistant button shows while the panel is closed`);
        await page.screenshot({ path: `${OUT}/editor-fab-${label}.png` });
        await fab.click().catch(() => {});
    } else {
        check(!(await fab.isVisible().catch(() => false)), `${label}: no round button on a wide screen`);
        await page.keyboard.press('Control+.');
    }
    await page.locator('iframe.waas-editor-assistant__frame').waitFor({ timeout: 5_000 }).catch(() => {});
    check(await page.locator('iframe.waas-editor-assistant__frame').isVisible().catch(() => false), `${label}: and it opens again`);
    await page.frameLocator('iframe.waas-editor-assistant__frame').locator('#state', { hasText: 'Signed in' }).waitFor({ timeout: 15_000 }).catch(() => {});
    live = page.frames().find((f) => f.url().startsWith(CHAT) && f.url().includes('signed=1')) ?? live;

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
