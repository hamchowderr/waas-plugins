// AnyPress Client's HighLevel link (src/Crm/HighLevelLink.php) on WordPress Playground with WP Fusion Lite: on a
// HighLevel site, the store asks the AnyPress API (signed with its site key) for its sub-account and token, connects
// WP Fusion to HighLevel by itself, loads the sub-account's tags and fields, and a new WordPress user is sent to the
// sub-account as a contact tagged "website user". The API can tell the store to check now (a signed POST to
// /site/crm-link), for a site tied or untied after it was made. The API and HighLevel are played here; every request
// is recorded.
//
//   cd scripts/playground && npm ci
//   npm run highlevel        # run the checks, exit 1 on a failure
//
// Needs Node 24.18+ and packages/anypress-client/vendor (composer install there).

import { randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.4';
const WPF = process.env.WPF_VERSION || '3.48.0';
const SITE_KEY = randomBytes(24).toString('hex');

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${WP}.zip`,
    php: '8.3',
    port: Number(process.env.PLAYGROUND_PORT || 9431),
    blueprint: {
        steps: [
            { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
            { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/wp-fusion-lite.${WPF}.zip` } },
            // A plugin the owner adds (Hello Dolly), and one the template ships for other site types (Classic Editor).
            { step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'hello-dolly' } },
            { step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'classic-editor' } },
            { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
            { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
            {
                // The template's site types: HighLevel sites let the owner add plugins and say what each one is for.
                step: 'writeFile',
                path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json',
                data: JSON.stringify({
                    website: { title: 'Website', plugins: ['wp-fusion-lite/wp-fusion-lite.php', 'classic-editor/classic-editor.php'] },
                    highlevel: {
                        title: 'HighLevel site',
                        ownPlugins: true,
                        plugins: ['wp-fusion-lite/wp-fusion-lite.php'],
                        about: { 'wp-fusion-lite/wp-fusion-lite.php': 'Sends new users and sign-ups to your HighLevel sub-account as contacts. Connected for you.' },
                    },
                }),
            },
            {
                // A HighLevel store: its site key and site type. The API's answer to the signed crm-link request, and
                // HighLevel's answers to WP Fusion, are played here; every request is kept in anypress_test_requests.
                step: 'writeFile',
                path: '/wordpress/wp-content/mu-plugins/anypress-test-highlevel.php',
                data: `<?php
putenv('ANYPRESS_IS_TENANT=true');
putenv('ANYPRESS_SITE_KEY=${SITE_KEY}');
define('ANYPRESS_API_URL', 'https://api.anypress.test');
define('ANYPRESS_TENANT_ROLES', 'highlevel');
// A reseller's site: WP Fusion and HighLevel go by its brand.
if (!get_option('anypress_brand_name')) update_option('anypress_brand_name', 'KnowBS');
add_filter('pre_http_request', function ($answer, $args, $url) {
    $json = function ($code, $body) {
        return ['response' => ['code' => $code, 'message' => ''], 'body' => wp_json_encode($body), 'headers' => [], 'cookies' => []];
    };
    $log = function ($entry) {
        $all = get_option('anypress_test_requests', []);
        $all[] = $entry;
        update_option('anypress_test_requests', $all, false);
    };
    if ($url === 'https://api.anypress.test/apps/site/crm-link') {
        $ok = \\AnyPressClient\\Core\\SignedRequest::verify(getenv('ANYPRESS_SITE_KEY'), $args['headers']['X-AnyPress-Signature'] ?? '', 'POST', '/apps/site/crm-link', $args['body']);
        $log(['api' => true, 'signed' => $ok, 'body' => json_decode($args['body'], true)]);
        if (!$ok) return $json(403, ['message' => 'not signed']);
        if (get_option('anypress_test_untied')) return $json(200, ['crm' => null]);
        return $json(200, ['crm' => 'highlevel', 'locationId' => 'loc-test', 'accessToken' => 'token-test', 'expiresAt' => gmdate('c', time() + 86000)]);
    }
    if (strpos($url, 'https://services.leadconnectorhq.com/') !== 0) return $answer;
    $method = strtoupper($args['method'] ?? 'GET');
    $path = substr($url, strlen('https://services.leadconnectorhq.com'));
    $log(['method' => $method, 'path' => $path, 'auth' => $args['headers']['Authorization'] ?? '', 'body' => isset($args['body']) && is_string($args['body']) ? json_decode($args['body'], true) : null]);
    if (strpos($path, '/locations/loc-test/tags') === 0) return $json(200, ['tags' => [['id' => 't1', 'name' => 'vip']]]);
    if (strpos($path, '/locations/loc-test/customFields') === 0) return $json(200, ['customFields' => [['id' => 'f1', 'name' => 'Plan', 'fieldKey' => 'contact.plan', 'dataType' => 'TEXT'], ['id' => 'f2', 'name' => 'UTM Campaign', 'fieldKey' => 'contact.utm_campaign', 'dataType' => 'TEXT']]]);
    if (strpos($path, '/contacts/?locationId') === 0 || strpos($path, '/contacts/lookup') === 0) return $json(200, ['contacts' => []]);
    if ($method === 'POST' && $path === '/contacts/') return $json(201, ['contact' => ['id' => 'contact-1']]);
    if (strpos($path, '/contacts/contact-1') === 0) return $json(200, ['contact' => ['id' => 'contact-1', 'tags' => []]]);
    return $json(200, []);
}, 10, 3);
`,
            },
        ],
    },
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/anypress-client' }],
    verbosity: 'quiet',
});

let failed = 0;
const check = (ok, label, detail) => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${label}${ok || detail === undefined ? '' : `\n     ${JSON.stringify(detail)}`}`);
    if (!ok) failed++;
};
const php = async (body) => {
    const result = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${body}` });
    if (result.errors) console.error(result.errors);
    return JSON.parse(result.text.trim() || 'null');
};
const requests = () => php("echo wp_json_encode(get_option('anypress_test_requests', []));");

try {
    const before = await php("echo wp_json_encode(['wpf' => function_exists('wp_fusion'), 'crm' => wpf_get_option('crm'), 'scheduled' => (bool) wp_next_scheduled('anypress_highlevel_link_check')]);");
    check(before.wpf && !before.crm, 'WP Fusion Lite is active and not connected', before);
    check(before.scheduled, 'the hourly link check is scheduled', before);

    // Plugins: the template's follow the site type, the owner's own stay as the owner set them.
    const plugins = await php("echo wp_json_encode(['owners' => is_plugin_active('hello-dolly/hello.php'), 'template' => is_plugin_active('classic-editor/classic-editor.php'), 'wpf' => is_plugin_active('wp-fusion-lite/wp-fusion-lite.php')]);");
    check(plugins.owners && plugins.wpf, "a plugin the owner added stays on, and the site type's plugins are on", plugins);
    check(!plugins.template, "a template plugin outside the HighLevel site type is switched off", plugins);

    // Your plan, before the site is tied to its sub-account: what each plugin is for, and what is connected.
    const planPage = () => php("wp_set_current_user(1); ob_start(); (new \\AnyPressClient\\Features\\CustomerPlanPage())->render(); echo wp_json_encode(ob_get_clean());");
    const unlinked = await planPage();
    check(unlinked.includes('plugins of your own') && unlinked.includes('What it is for') && unlinked.includes('Connected for you.'), 'Your plan says owners may add plugins and what each included one is for', unlinked.slice(0, 300));
    check(unlinked.includes('Connected to HighLevel') && unlinked.includes('KnowBS connects it') && unlinked.includes('Open LeadConnector'), 'Your plan shows WP Fusion not connected yet and how to connect LeadConnector', unlinked.slice(0, 300));
    check(!unlinked.includes('change your plan'), 'no "change your plan" link on a HighLevel site');

    // The hourly check: the store asks the API and connects WP Fusion.
    await php("do_action('anypress_highlevel_link_check'); echo 'null';");
    const asked = (await requests()).filter((r) => r.api);
    check(asked.length === 1 && asked[0].signed && asked[0].body?.site, 'the store asked the API once, signed with its site key', asked);

    const settings = await php("echo wp_json_encode(['crm' => wpf_get_option('crm'), 'configured' => wpf_get_option('connection_configured'), 'location' => wpf_get_option('highlevel_location_id'), 'token' => wpf_get_option('highlevel_token'), 'create_users' => wpf_get_option('create_users'), 'assign_tags' => wpf_get_option('assign_tags'), 'first' => wpf_get_option('contact_fields')['first_name'] ?? null, 'v2' => wp_fusion()->crm->is_v2()]);");
    check(settings.crm === 'highlevel' && settings.configured, 'WP Fusion is connected to HighLevel', settings);
    check(settings.location === 'loc-test' && settings.token === 'token-test' && settings.v2, "it uses the sub-account and the API's token", settings);
    check(Boolean(settings.create_users) && JSON.stringify(settings.assign_tags) === '["website user"]' && settings.first?.crm_field === 'firstName', 'new users become contacts tagged "website user", names mapped', settings);

    // The tags and fields are loaded on the next request, as WP Fusion's Refresh button does.
    await php("do_action('anypress_highlevel_link_sync'); echo 'null';");
    const synced = await php("echo wp_json_encode(['tags' => wpf_get_option('available_tags'), 'fields' => wpf_get_option('crm_fields')]);");
    check(JSON.stringify(synced.tags ?? {}).includes('vip'), "the sub-account's tags are loaded", synced);
    check(JSON.stringify(synced.fields ?? {}).includes('Plan'), "the sub-account's custom fields are loaded", synced);
    const lead = await php("$f = wpf_get_option('contact_fields'); echo wp_json_encode(['source' => $f['utm_source'] ?? null, 'campaign' => $f['utm_campaign'] ?? null]);");
    check(lead.source?.crm_field === 'source' && lead.source?.active && lead.campaign?.crm_field === 'f2' && lead.campaign?.active, "lead source goes to Source, and utm_campaign to the sub-account's UTM Campaign field", lead);
    const brandedRun = await cli.playground.run({ code: "<?php define('WP_ADMIN', true); require '/wordpress/wp-load.php'; echo wp_json_encode(['crm' => wp_fusion()->crm->name, 'menu' => __('WP Fusion', 'wp-fusion-lite')]);" });
    const branded = JSON.parse(brandedRun.text.trim() || 'null');
    check(branded.crm === 'KnowBS CRM' && branded.menu === 'KnowBS Contact Sync', "WP Fusion and HighLevel go by the reseller's brand in wp-admin", branded);

    // A new WordPress user goes to the sub-account as a contact, with the tag.
    // The visitor came from an ad: WP Fusion's lead source cookie, as its tracking sets on the first visit.
    await php("$_COOKIE['wpf_leadsource'] = wp_json_encode(['utm_source' => 'google', 'utm_campaign' => 'spring']); wp_insert_user(['user_login' => 'sam', 'user_email' => 'sam@example.com', 'user_pass' => wp_generate_password(), 'first_name' => 'Sam', 'last_name' => 'Rivera', 'role' => 'subscriber']); echo 'null';");
    const sent = (await requests()).filter((r) => !r.api);
    const created = sent.find((r) => r.method === 'POST' && r.path === '/contacts/');
    check(Boolean(created), 'a new user is sent to HighLevel as a contact', sent);
    check(created?.auth === 'Bearer token-test' && created?.body?.locationId === 'loc-test', "with the sub-account's token, in that sub-account", created);
    check(created?.body?.email === 'sam@example.com' && created?.body?.firstName === 'Sam', 'with their email and name', created?.body);
    check(created?.body?.source === 'google' && JSON.stringify(created?.body?.customFields ?? []).includes('spring'), 'with where they came from (Source google, UTM Campaign spring)', created?.body);
    const tagged = sent.some((r) => r.path.startsWith('/contacts/contact-1') && JSON.stringify(r.body ?? {}).includes('website user')) || JSON.stringify(created?.body?.tags ?? []).includes('website user');
    check(tagged, 'the contact is tagged "website user"', sent);
    check(sent.every((r) => r.auth === 'Bearer token-test'), 'every HighLevel call carries the token', sent.map((r) => r.auth));
    const user = await php("$u = get_user_by('email', 'sam@example.com'); echo wp_json_encode(get_user_meta($u->ID, 'highlevel_contact_id', true));");
    check(user === 'contact-1', 'the user is linked to the contact', user);

    // A token about to end is renewed from the API before WP Fusion uses it.
    const renewed = await php("$l = get_option('anypress_highlevel_link'); $l['expires'] = time() + 60; update_option('anypress_highlevel_link', $l); wpf_get_option('highlevel_token'); echo wp_json_encode(get_option('anypress_highlevel_link')['expires'] - time());");
    check(renewed > 3 * 3600, 'a token about to end is renewed from the API', renewed);

    // The API's check-now: a signed POST to the store's /site/crm-link asks the API straight away, whatever was kept.
    const checkNow = (sign) => php(`$body = ''; $route = '/anypress-client/v1/site/crm-link'; $r = new WP_REST_Request('POST', $route);${sign ? " $r->set_header('X-AnyPress-Signature', \\AnyPressClient\\Core\\SignedRequest::sign(getenv('ANYPRESS_SITE_KEY'), 'POST', $route, $body));" : ''} $res = rest_do_request($r); echo wp_json_encode(['status' => $res->get_status(), 'data' => $res->get_data()]);`);
    const unsigned = await checkNow(false);
    check(unsigned.status === 401, 'check-now refuses a request not signed by the API', unsigned);
    const askedBefore = (await requests()).filter((r) => r.api).length;
    const now = await checkNow(true);
    check(now.status === 200 && now.data?.linked && now.data?.connected && now.data?.locationId === 'loc-test', 'check-now answers the link and that WP Fusion uses it', now);
    check((await requests()).filter((r) => r.api).length === askedBefore + 1, 'check-now asked the API even with a fresh token kept', askedBefore);
    // Untied from the sub-account, then a wait set by the empty answer: check-now still asks.
    await php("update_option('anypress_test_untied', 1); echo 'null';");
    const untied = await checkNow(true);
    check(untied.data?.linked === false && untied.data?.connected === false, 'untied: the link is dropped', untied);
    await php("delete_option('anypress_test_untied'); echo 'null';");
    const retied = await checkNow(true);
    check(retied.data?.linked && retied.data?.connected, 'tied again: connected at once, without the hour wait', retied);

    const linked = await planPage();
    check(linked.includes('Connected. New users and sign-ups go to your HighLevel sub-account (loc-test)'), 'Your plan shows WP Fusion connected to the sub-account', linked.slice(0, 300));
    if (process.env.SHOTS) {
        // Playwright from the machine (not a dependency here): require() honours NODE_PATH.
        const { chromium } = (await import('node:module')).createRequire(import.meta.url)('playwright');
        const browser = await chromium.launch();
        for (const [name, viewport] of [['desktop', { width: 1280, height: 900 }], ['phone', { width: 390, height: 844 }]]) {
            const page = await browser.newPage({ viewport, deviceScaleFactor: 2 });
            await page.goto(`${cli.serverUrl}/wp-login.php`);
            await page.fill('#user_login', 'admin');
            await page.fill('#user_pass', 'password');
            await page.click('#wp-submit');
            await page.waitForLoadState('networkidle');
            await page.goto(`${cli.serverUrl}/wp-admin/index.php?page=anypress-your-plan`);
            await page.screenshot({ path: `${process.env.SHOTS}/your-plan-${name}.png`, fullPage: true });
        }
        await browser.close();
    }

    // A store made before HighLevel sites had their own plugins: the template connected WP Fusion to FluentCRM.
    // The link takes over from that, and check-now says which CRM WP Fusion is on.
    await php("wp_fusion()->settings->set_multiple(['crm' => 'fluentcrm', 'connection_configured' => true]); echo 'null';");
    const before2 = await checkNow(true);
    check(before2.data?.crm === 'highlevel' && before2.data?.connected, 'a store on its own FluentCRM is moved to HighLevel, and check-now says so', before2);
} finally {
    await cli[Symbol.asyncDispose]?.();
    await cli.server?.close?.();
}

console.log(failed ? `\n${failed} check(s) failed` : '\nall checks passed');
process.exit(failed ? 1 : 0);
