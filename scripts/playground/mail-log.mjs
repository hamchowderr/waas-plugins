// Test of AnyPress Client's community mail log rule on WordPress Playground, as a community store: the AnyPress theme,
// FluentCommunity and FluentSMTP from wordpress.org at the store template's versions, and AnyPress Client from this
// checkout with ANYPRESS_TENANT_ROLES=community. FluentSMTP simulates sending (FLUENTMAIL_SIMULATE_EMAILS) with its
// email log on, as stores have it. Checks: an email FluentCommunity's own Mailer sends (its notifications quote
// members' posts and private messages) is not in FluentSMTP's log; an ordinary wp_mail email still is.
//
//   cd scripts/playground && npm ci
//   npm run mail-log          # exit 1 on a failure
//
// Needs packages/anypress-client/vendor (composer install), a waas-provisioning-api checkout next to this repository
// (its store template's roles.json; or ANYPRESS_API_DIR), and network access to downloads.wordpress.org.

import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const API = process.env.ANYPRESS_API_DIR || resolve(ROOT, '../waas-provisioning-api');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.4';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9411);
// The store template's versions (waas-provisioning-api templates/anypress-store/build.sh).
const PLUGINS = ['fluent-community.2.14.0.zip', 'fluent-smtp.2.4.5.zip'];

for (const [path, fix] of [[resolve(CLIENT, 'vendor/autoload.php'), 'run composer install in packages/anypress-client'], [resolve(API, 'templates/anypress-store/roles.json'), 'set ANYPRESS_API_DIR to a waas-provisioning-api checkout']]) {
    if (!existsSync(path)) {
        console.error(`${path} is missing: ${fix} first.`);
        process.exit(1);
    }
}

const blueprint = {
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true, FLUENTMAIL_SIMULATE_EMAILS: true } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        ...PLUGINS.map((zip) => ({ step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/${zip}` }, options: { activate: true } })),
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(resolve(API, 'templates/anypress-store/roles.json'), 'utf8') },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: "<?php putenv('ANYPRESS_IS_TENANT=true'); define('ANYPRESS_TENANT_ROLES', 'community');\n" },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
    ],
};

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${WP}.zip`,
    php: PHP,
    port: PORT,
    blueprint,
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/anypress-client' }],
    verbosity: 'quiet',
});
console.log(`WordPress ${WP} on PHP ${PHP}, site type community`);

async function php(code) {
    const out = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` });
    const text = out.text.trim();
    return JSON.parse(text.slice(text.search(/[[{]/)));
}

let failed = 0;
const check = (ok, what) => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${what}`);
    if (!ok) failed++;
};

const result = await php(`
    (new \\FluentCommunity\\App\\Services\\Libs\\Mailer('member@example.com', 'New private message', '<p>The private text</p>'))->setIsHtml(true)->send();
    wp_mail('owner@example.com', 'Your order', 'Order details');
    // FluentSMTP's own log model, as its Email Logs screen reads it.
    $logs = (new \\FluentMail\\App\\Models\\Logger())->get(['per_page' => 50]);
    $subjects = array_map(function ($row) { return is_array($row) ? $row['subject'] : $row->subject; }, $logs['data'] ?? []);
    echo wp_json_encode([
        'subjects' => array_values($subjects),
        'logging' => (fluentMailGetSettings()['misc']['log_emails'] ?? null),
        'client' => class_exists('AnyPressClient\\\\Features\\\\CommunityMailLog'),
    ]);
`).catch((error) => ({ error: String(error) }));

if (result.error) {
    check(false, `sending the two emails: ${result.error}`);
} else {
    console.log(`FluentSMTP log: ${JSON.stringify(result.subjects)} (log_emails ${result.logging})`);
    check(result.client, 'AnyPress Client is loaded');
    check(result.subjects.includes('Your order'), 'an ordinary email is in the log');
    check(!result.subjects.includes('New private message'), "FluentCommunity's notification email is not in the log");
}

await cli[Symbol.asyncDispose]?.();
process.exit(failed ? 1 : 0);
