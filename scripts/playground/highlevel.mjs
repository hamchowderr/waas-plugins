// AnyPress Client's HighLevel link (src/Crm/HighLevelLink.php) on WordPress Playground with WP Fusion Lite: on a
// HighLevel site, the store asks the AnyPress API (signed with its site key) for its sub-account and token, connects
// WP Fusion to HighLevel by itself, loads the sub-account's tags and fields, and a new WordPress user is sent to the
// sub-account as a contact tagged "website user". The API and HighLevel are played here; every request is recorded.
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
const WP = process.env.PLAYGROUND_WP || '7.1.3';
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
            { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
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
        return $json(200, ['crm' => 'highlevel', 'locationId' => 'loc-test', 'accessToken' => 'token-test', 'expiresAt' => gmdate('c', time() + 86000)]);
    }
    if (strpos($url, 'https://services.leadconnectorhq.com/') !== 0) return $answer;
    $method = strtoupper($args['method'] ?? 'GET');
    $path = substr($url, strlen('https://services.leadconnectorhq.com'));
    $log(['method' => $method, 'path' => $path, 'auth' => $args['headers']['Authorization'] ?? '', 'body' => isset($args['body']) && is_string($args['body']) ? json_decode($args['body'], true) : null]);
    if (strpos($path, '/locations/loc-test/tags') === 0) return $json(200, ['tags' => [['id' => 't1', 'name' => 'vip']]]);
    if (strpos($path, '/locations/loc-test/customFields') === 0) return $json(200, ['customFields' => [['id' => 'f1', 'name' => 'Plan', 'fieldKey' => 'contact.plan', 'dataType' => 'TEXT']]]);
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

    // A new WordPress user goes to the sub-account as a contact, with the tag.
    await php("wp_insert_user(['user_login' => 'sam', 'user_email' => 'sam@example.com', 'user_pass' => wp_generate_password(), 'first_name' => 'Sam', 'last_name' => 'Rivera', 'role' => 'subscriber']); echo 'null';");
    const sent = (await requests()).filter((r) => !r.api);
    const created = sent.find((r) => r.method === 'POST' && r.path === '/contacts/');
    check(Boolean(created), 'a new user is sent to HighLevel as a contact', sent);
    check(created?.auth === 'Bearer token-test' && created?.body?.locationId === 'loc-test', "with the sub-account's token, in that sub-account", created);
    check(created?.body?.email === 'sam@example.com' && created?.body?.firstName === 'Sam', 'with their email and name', created?.body);
    const tagged = sent.some((r) => r.path.startsWith('/contacts/contact-1') && JSON.stringify(r.body ?? {}).includes('website user')) || JSON.stringify(created?.body?.tags ?? []).includes('website user');
    check(tagged, 'the contact is tagged "website user"', sent);
    check(sent.every((r) => r.auth === 'Bearer token-test'), 'every HighLevel call carries the token', sent.map((r) => r.auth));
    const user = await php("$u = get_user_by('email', 'sam@example.com'); echo wp_json_encode(get_user_meta($u->ID, 'highlevel_contact_id', true));");
    check(user === 'contact-1', 'the user is linked to the contact', user);

    // A token about to end is renewed from the API before WP Fusion uses it.
    const renewed = await php("$l = get_option('anypress_highlevel_link'); $l['expires'] = time() + 60; update_option('anypress_highlevel_link', $l); wpf_get_option('highlevel_token'); echo wp_json_encode(get_option('anypress_highlevel_link')['expires'] - time());");
    check(renewed > 3 * 3600, 'a token about to end is renewed from the API', renewed);
} finally {
    await cli[Symbol.asyncDispose]?.();
    await cli.server?.close?.();
}

console.log(failed ? `\n${failed} check(s) failed` : '\nall checks passed');
process.exit(failed ? 1 : 0);
