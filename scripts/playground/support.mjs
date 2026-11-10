// AnyPress Host's support desk (src/Api/SupportDesk.php) on WordPress Playground with Fluent Support: the API's signed
// calls open, list, read, reply to and close a customer's tickets, another customer can't see them, a used or badly
// signed request is refused, and the storefront gets its Support page once.
//
//   npm run support                          # Fluent Support as the shop runs it (2.4.7)
//   FLUENT_SUPPORT=2.5.0 npm run support     # another release

import { createHmac, randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const HOST = resolve(HERE, '../../packages/anypress-host');
const FS_VERSION = process.env.FLUENT_SUPPORT || '2.4.7';
// A test secret only, made up here.
const SECRET = `whsec_${Buffer.alloc(24, 9).toString('base64')}`;

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${process.env.PLAYGROUND_WP || '7.1.3'}.zip`,
    php: '8.3',
    port: Number(process.env.PLAYGROUND_PORT || 9441),
    blueprint: {
        steps: [
            { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: '/tmp/debug.log', WP_DEBUG_DISPLAY: false } },
            { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(resolve(HERE, 'preview-zips/anypress.zip')) }, options: { activate: true } },
            { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-support.${FS_VERSION}.zip` }, options: { activate: true } },
            { step: 'activatePlugin', pluginPath: 'anypress-host/anypress-host.php' },
        ],
    },
    mount: [{ hostPath: HOST, vfsPath: '/wordpress/wp-content/plugins/anypress-host' }],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
const php = async (code) => (await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` })).text;

let failed = false;
const check = (ok, what, detail = '') => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${what}${ok || !detail ? '' : `: ${detail}`}`);
    if (!ok) failed = true;
};

// What connecting to the API leaves behind: the webhook endpoint's secret, sealed. And Fluent Support's first mailbox.
console.log(
    await php(`
        update_option(\\AnyPressHost\\Features\\Webhooks::OPTION, ['id' => 'ep-1', 'secret' => \\AnyPressHost\\Core\\SecretBox::seal('${SECRET}', 'anypress-host-webhook-secret')], false);
        if (!\\FluentSupport\\App\\Models\\MailBox::count()) {
            \\FluentSupport\\App\\Models\\MailBox::create(['name' => 'AnyPress', 'slug' => 'anypress', 'box_type' => 'web', 'email' => 'support@shop.example', 'is_default' => 'yes', 'created_by' => 1]);
        }
        echo 'Fluent Support ' . (defined('FLUENT_SUPPORT_VERSION') ? FLUENT_SUPPORT_VERSION : '?') . ', AnyPress Host ' . ANYPRESS_HOST_VERSION;
    `),
);

const sign = (id, timestamp, body) => `v1,${createHmac('sha256', Buffer.from(SECRET.slice(6), 'base64')).update(`${id}.${timestamp}.${body}`).digest('base64')}`;
/** One call the way the API makes it (src/support.ts callDesk). */
async function desk(body, { id = `sup_${randomUUID()}`, signature } = {}) {
    const text = JSON.stringify(body);
    const timestamp = Math.floor(Date.now() / 1000);
    const res = await fetch(`${base}/wp-json/anypress-host/v1/support`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'webhook-id': id, 'webhook-timestamp': String(timestamp), 'webhook-signature': signature ?? sign(id, timestamp, text) },
        body: text,
    });
    return { status: res.status, body: await res.json().catch(() => null), id, text, timestamp };
}

const dana = { email: 'dana@example.com', name: 'Dana Florist' };
const opened = await desk({ action: 'open', customer: dana, title: 'Booking page error', content: 'Since this morning it says "Something went wrong".\n\n---\nWebsite: Rose Florist' });
check(opened.status === 201 && opened.body?.ticket?.id > 0, 'open files a ticket', JSON.stringify(opened.body));
const id = opened.body?.ticket?.id;
check(opened.body?.ticket?.messages?.[0]?.content?.includes('Something went wrong'), 'the ticket keeps the message as text', JSON.stringify(opened.body?.ticket));

const stored = await php(`$t = \\FluentSupport\\App\\Models\\Ticket::find(${Number(id) || 0}); echo json_encode(['title' => $t->title, 'email' => $t->customer->email, 'mailbox' => (int) $t->mailbox_id, 'status' => $t->status]);`);
const row = JSON.parse(stored || '{}');
check(row.title === 'Booking page error' && row.email === dana.email && row.mailbox > 0, 'it is in Fluent Support under the customer, in the mailbox', stored);
check(opened.body?.ticket?.status === row.status && row.status === 'new', 'open answers the stored status', `${opened.body?.ticket?.status} / ${row.status}`);

const listed = await desk({ action: 'list', customer: dana });
check(listed.status === 200 && listed.body?.tickets?.length === 1 && listed.body.tickets[0].id === id, 'list shows their ticket', JSON.stringify(listed.body));

// An agent answers in wp-admin.
await php(`
    wp_set_current_user(1);
    $agent = \\FluentSupport\\App\\Models\\Agent::where('user_id', 1)->first() ?: \\FluentSupport\\App\\Models\\Agent::create(['email' => get_userdata(1)->user_email, 'first_name' => 'Sam', 'user_id' => 1]);
    (new \\FluentSupport\\App\\Services\\Tickets\\ResponseService())->createResponse(['content' => '<p>We are on it.</p>', 'conversation_type' => 'response'], $agent, \\FluentSupport\\App\\Models\\Ticket::find(${Number(id) || 0}));
`);
const replied = await desk({ action: 'reply', customer: dana, ticketId: id, content: 'Thanks! It also happens on my phone.' });
check(replied.status === 200, 'reply adds their reply', JSON.stringify(replied.body));
const read = await desk({ action: 'get', customer: dana, ticketId: id });
const froms = read.body?.ticket?.messages?.map((m) => `${m.from}: ${m.content}`) ?? [];
check(
    read.status === 200 && froms.length === 3 && froms[1] === 'support: We are on it.' && froms[2] === 'you: Thanks! It also happens on my phone.',
    'get shows the thread: the agent as support, the customer as you',
    JSON.stringify(froms),
);

const other = await desk({ action: 'get', customer: { email: 'eve@example.com' }, ticketId: id });
check(other.status === 404, "another customer can't read it", String(other.status));
const otherReply = await desk({ action: 'reply', customer: { email: 'eve@example.com' }, ticketId: id, content: 'hi' });
check(otherReply.status === 404, "or reply to it", String(otherReply.status));

const closed = await desk({ action: 'close', customer: dana, ticketId: id });
check(closed.status === 200 && closed.body?.ticket?.status === 'closed', 'close closes it', JSON.stringify(closed.body));
const reopened = await desk({ action: 'reply', customer: dana, ticketId: id, content: 'Back again, sorry.' });
check(reopened.status === 200 && reopened.body?.ticket?.status !== 'closed', 'a reply opens it again', JSON.stringify(reopened.body?.ticket?.status));

const bad = await desk({ action: 'list', customer: dana }, { signature: 'v1,bm9wZQ==' });
check(bad.status === 401, 'a bad signature is refused', String(bad.status));
const first = await desk({ action: 'list', customer: dana }, { id: 'sup_replay' });
const again = await fetch(`${base}/wp-json/anypress-host/v1/support`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'webhook-id': first.id, 'webhook-timestamp': String(first.timestamp), 'webhook-signature': sign(first.id, first.timestamp, first.text) },
    body: first.text,
});
check(first.status === 200 && again.status === 409, 'the same signed request works once', `${first.status} then ${again.status}`);

// The Support page: made once, as Fluent Support's portal page.
const page = JSON.parse(
    await php(`
        wp_set_current_user(1);
        \\AnyPressHost\\Api\\SupportDesk::maybe_create_page();
        \\AnyPressHost\\Api\\SupportDesk::maybe_create_page();
        $settings = \\FluentSupport\\App\\Services\\Helper::getOption('global_business_settings', []);
        $pages = get_posts(['post_type' => 'page', 'post_status' => 'any', 's' => 'fluent_support_portal', 'numberposts' => 10]);
        echo json_encode(['option' => (int) get_option('anypress_support_page'), 'portal' => (int) ($settings['portal_page_id'] ?? 0), 'pages' => count($pages), 'url' => get_permalink((int) get_option('anypress_support_page'))]);
    `),
);
check(page.option > 0 && page.portal === page.option && page.pages === 1, 'one Support page, set as the portal page', JSON.stringify(page));
const portal = await fetch(page.url);
check(portal.status === 200, 'the Support page opens', String(portal.status));

const log = await php("echo @file_get_contents('/tmp/debug.log');");
const errors = log.split('\n').filter((l) => /PHP (Fatal|Warning)|support desk/i.test(l));
check(errors.length === 0, 'no PHP warnings or desk errors', errors.join('\n').slice(0, 1500));

await cli[Symbol.asyncDispose]?.();
process.exit(failed ? 1 : 0);
