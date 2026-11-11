// End-to-end test of AnyPress Client's Brevo and MailerLite (accounts since 2022) importers in FluentCRM's own Import
// screen ("Migrate from another CRM") on WordPress Playground, as a website store: the AnyPress theme, FluentCRM at
// the store template's version and AnyPress Client from this checkout with ANYPRESS_TENANT_ROLES website. Brevo's and
// MailerLite's APIs are played by crm-import/fake-apis.php (their published response shapes, answered inside
// WordPress), and the importers call them through the official Brevo and MailerLite PHP SDKs. Each step goes through
// FluentCRM's own REST routes, as its Import screen does: the drivers list, the key check (a wrong key, then the
// right one), the lists or groups and fields, the summary, and the import, page by page. Checks: every contact comes
// across with its name, phone, sign-up date, tag and status (Brevo's blocklisted and MailerLite's unsubscribed and
// bounced included); a contact FluentCRM already holds as unsubscribed stays so; importing again makes no duplicates;
// the key is saved nowhere.
//
//   cd scripts/playground && npm ci
//   npm run crm-import            # exit 1 on a failure
//   npm run crm-import -- --serve # leave the site running at http://127.0.0.1:9413 (admin / password)
//
// Needs packages/anypress-client/vendor (composer install --no-dev in packages/anypress-client), a
// waas-provisioning-api checkout next to this repository (its store template's roles.json; or ANYPRESS_API_DIR), and
// network access to downloads.wordpress.org. Playground stores the database in SQLite, not MariaDB.

import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const API = process.env.ANYPRESS_API_DIR || resolve(ROOT, '../waas-provisioning-api');
const ROLES = resolve(API, 'templates/anypress-store/roles.json');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.4';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9413);
const SERVE = process.argv.includes('--serve');
const SITE_TYPE = 'website';
// The version the store template installs (templates/anypress-store/build.sh).
const FLUENT_CRM = process.env.FLUENT_CRM || '3.3.1';

for (const [path, fix] of [[resolve(CLIENT, 'vendor/autoload.php'), 'run composer install --no-dev in packages/anypress-client'], [ROLES, 'set ANYPRESS_API_DIR to a waas-provisioning-api checkout']]) {
    if (!existsSync(path)) {
        console.error(`${path} is missing: ${fix} first.`);
        process.exit(1);
    }
}

const blueprint = {
    landingPage: '/wp-admin/admin.php?page=fluentcrm-admin#/import',
    login: SERVE,
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-crm.${FLUENT_CRM}.zip` }, options: { activate: true } },
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(ROLES, 'utf8') },
        // A store sold as a website: its site type comes from the container's environment.
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${randomBytes(24).toString('hex')}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}');\n` },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/crm-import-fake-apis.php', data: readFileSync(resolve(HERE, 'crm-import/fake-apis.php'), 'utf8') },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'CRM import test', permalink_structure: '/%postname%/' } },
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
const base = cli.serverUrl.replace(/\/$/, '');
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}, site type ${SITE_TYPE}, FluentCRM ${FLUENT_CRM}`);

async function php(code) {
    let out;
    try {
        out = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; wp_set_current_user(1); ${code}` });
    } catch (error) {
        const log = await cli.playground.readFileAsText('/wordpress/wp-content/debug.log').catch(() => '');
        throw new Error(`PHP failed (${String(error.message).split('\n')[0]}); debug.log ends: ${log.slice(-1500)}`);
    }
    const text = out.text.trim();
    try {
        return JSON.parse(text.slice(text.search(/[[{]/)));
    } catch {
        throw new Error(`not JSON: ${text.slice(0, 800)}`);
    }
}
/** One of FluentCRM's migrator routes, as its Import screen calls it (signed in as the administrator). */
async function route(method, path, params = {}) {
    return php(`$r = new WP_REST_Request('${method}', '/fluent-crm/v2/migrators${path}');
        $p = json_decode(base64_decode('${Buffer.from(JSON.stringify(params)).toString('base64')}'), true);
        ${method === 'GET' ? '$r->set_query_params($p);' : '$r->set_body_params($p);'}
        $res = rest_do_request($r);
        echo wp_json_encode(['status' => $res->get_status(), 'data' => $res->get_data()]);`);
}
async function contacts(source) {
    return php(`$out = [];
        foreach (FluentCrm\\App\\Models\\Subscriber::where('source', '${source}')->with('tags')->get() as $s) {
            $out[$s->email] = ['first' => $s->first_name, 'last' => $s->last_name, 'phone' => $s->phone, 'city' => $s->city, 'status' => $s->status, 'ip' => $s->ip, 'created' => (string) $s->created_at, 'tags' => $s->tags->pluck('title')->all()];
        }
        echo wp_json_encode(['contacts' => (object) $out, 'all' => FluentCrm\\App\\Models\\Subscriber::count()]);`);
}

const results = [];
async function check(name, fn) {
    try {
        await fn();
        results.push([true, name]);
        console.log(`  ok    ${name}`);
    } catch (error) {
        results.push([false, name]);
        console.log(`  FAIL  ${name}\n        ${error.message}`);
    }
}
const expect = (condition, message) => {
    if (!condition) throw new Error(message);
};
const short = (value) => JSON.stringify(value).slice(0, 700);

/** The whole import, as the Import screen runs it: every list or group as a new tag, then page after page. */
async function importAll(driver, key) {
    const credential = { api_key: key };
    const maps = await route('GET', '/list-tag-mappings', { driver, credential });
    expect(maps.status === 200, `mappings: ${short(maps)}`);
    const settings = { driver, credential, tags: maps.data.options.tags.map((t) => ({ ...t, will_create: 'yes' })), contact_fields: maps.data.options.contact_fields, map_settings: {} };
    const summary = await route('POST', '/summary', settings);
    expect(summary.status === 200, `summary: ${short(summary)}`);
    let tracker = {};
    let rounds = 0;
    for (;;) {
        const step = await route('POST', '/import', { ...settings, import_tracker: tracker });
        expect(step.status === 200, `import: ${short(step)}`);
        tracker = step.data.import_info.import_tracker;
        rounds++;
        if (!step.data.import_info.has_more || rounds > 20) break;
    }
    return { maps: maps.data.options, summary: summary.data.import_summary, rounds };
}

await check('FluentCRM\'s Import screen lists Brevo and MailerLite (accounts since 2022) next to its own importers', async () => {
    const drivers = await route('GET', '');
    const keys = Object.keys(drivers.data.drivers || {});
    expect(drivers.status === 200 && ['anypress_brevo', 'anypress_mailerlite', 'mailchimp', 'MailerLite', 'ConvertKit'].every((k) => keys.includes(k)), short(keys));
    expect(drivers.data.drivers.anypress_brevo.title === 'Brevo' && drivers.data.drivers.anypress_brevo.credential_fields.api_key.data_type === 'password', short(drivers.data.drivers.anypress_brevo));
});

await check('Brevo: a wrong key is refused, the right one accepted', async () => {
    const wrong = await route('POST', '/verify-cred', { driver: 'anypress_brevo', credential: { api_key: 'xkeysib-wrong' } });
    expect(wrong.status === 422, `wrong key: ${short(wrong)}`);
    const right = await route('POST', '/verify-cred', { driver: 'anypress_brevo', credential: { api_key: 'xkeysib-test' } });
    expect(right.status === 200, `right key: ${short(right)}`);
});

let brevo;
await check('Brevo: lists, attributes and the summary, then every contact of both lists', async () => {
    // Cara unsubscribed on this site before the move: Brevo still lists her, but she stays unsubscribed.
    await php(`FluentCrmApi('contacts')->createOrUpdate(['email' => 'cara@anypress.example', 'status' => 'unsubscribed', 'source' => 'Brevo']); echo '{}';`);
    brevo = await importAll('anypress_brevo', 'xkeysib-test');
    expect(brevo.maps.tags.map((t) => t.remote_name).join() === 'Newsletter,VIP', short(brevo.maps.tags));
    const fields = Object.fromEntries(brevo.maps.contact_fields.map((f) => [f.remote_tag, f.fluentcrm_field]));
    expect(fields.FIRSTNAME === 'first_name' && fields.LASTNAME === 'last_name' && fields.SMS === 'phone' && 'COMPANY' in fields && !('ORDER_ID' in fields), short(fields));
    expect(brevo.summary.all_count === 253, short(brevo.summary));
    expect(brevo.rounds === 3, `rounds ${brevo.rounds}`);
    const { contacts: c } = await contacts('Brevo');
    expect(Object.keys(c).length === 252, `contacts ${Object.keys(c).length}`);
    expect(c['alice@anypress.example'].first === 'Alice' && c['alice@anypress.example'].last === 'Ng' && c['alice@anypress.example'].phone === '+15550100', short(c['alice@anypress.example']));
    expect(c['alice@anypress.example'].tags.sort().join() === 'Newsletter,VIP' && c['alice@anypress.example'].status === 'subscribed', short(c['alice@anypress.example']));
    expect(c['bob@anypress.example'].status === 'unsubscribed', `blocklisted Bob: ${short(c['bob@anypress.example'])}`);
    expect(c['cara@anypress.example'].status === 'unsubscribed', `Cara: ${short(c['cara@anypress.example'])}`);
    expect(c['vip249@anypress.example']?.tags.join() === 'VIP', short(c['vip249@anypress.example']));
    expect(c['alice@anypress.example'].created.startsWith('2024-03-0'), `sign-up date ${c['alice@anypress.example'].created}`);
});

await check('MailerLite: a Classic-style wrong token is refused, the right one accepted', async () => {
    const wrong = await route('POST', '/verify-cred', { driver: 'anypress_mailerlite', credential: { api_key: 'classic-key' } });
    expect(wrong.status === 422, `wrong token: ${short(wrong)}`);
    const right = await route('POST', '/verify-cred', { driver: 'anypress_mailerlite', credential: { api_key: 'ml-test' } });
    expect(right.status === 200, `right token: ${short(right)}`);
});

await check('MailerLite: groups, fields and the summary, then every subscriber with their status', async () => {
    const ml = await importAll('anypress_mailerlite', 'ml-test');
    expect(ml.maps.tags.map((t) => t.remote_name).join() === 'Customers,Leads', short(ml.maps.tags));
    const fields = Object.fromEntries(ml.maps.contact_fields.map((f) => [f.remote_tag, f.fluentcrm_field]));
    expect(fields.name === 'first_name' && fields.city === 'city' && !('email' in fields) && 'company' in fields, short(fields));
    expect(ml.summary.all_count === 233, short(ml.summary));
    expect(ml.rounds === 3, `rounds ${ml.rounds}`);
    const { contacts: c } = await contacts('MailerLite');
    expect(Object.keys(c).length === 233, `contacts ${Object.keys(c).length}`);
    expect(c['dina@anypress.example'].status === 'subscribed' && c['dina@anypress.example'].city === 'Oakland' && c['dina@anypress.example'].tags.join() === 'Customers' && c['dina@anypress.example'].ip, short(c['dina@anypress.example']));
    expect(c['evan@anypress.example'].status === 'unsubscribed' && c['fay@anypress.example'].status === 'bounced', short([c['evan@anypress.example'], c['fay@anypress.example']]));
    expect(c['lead230@anypress.example']?.tags.join() === 'Leads', short(c['lead230@anypress.example']));
});

await check('importing again makes no duplicates and changes no status', async () => {
    const before = await contacts('Brevo');
    await importAll('anypress_brevo', 'xkeysib-test');
    const after = await contacts('Brevo');
    expect(after.all === before.all, `contacts ${before.all} -> ${after.all}`);
    expect(after.contacts['bob@anypress.example'].status === 'unsubscribed' && after.contacts['cara@anypress.example'].status === 'unsubscribed', short([after.contacts['bob@anypress.example'], after.contacts['cara@anypress.example']]));
});

await check('the keys are saved nowhere on the site', async () => {
    const found = await php(`global $wpdb; $hits = [];
        foreach (['xkeysib-test', 'ml-test'] as $k) {
            $like = '%' . $wpdb->esc_like($k) . '%';
            $hits[$k] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT LIKE 'crm_import_test_%'", $like))
                + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s", $like))
                + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fc_meta WHERE value LIKE %s", $like));
        }
        echo wp_json_encode($hits);`);
    expect(found['xkeysib-test'] === 0 && found['ml-test'] === 0, short(found));
});

await check('no PHP warnings or errors from AnyPress Client', async () => {
    const log = await cli.playground.readFileAsText('/wordpress/wp-content/debug.log').catch(() => '');
    const ours = log.split(/\n(?=\[)/).filter((entry) => /anypress-client|AnyPressClient/.test(entry));
    expect(!ours.length, ours.slice(0, 3).join('\n').slice(0, 1500));
});

const failed = results.filter(([ok]) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
if (SERVE) {
    console.log(`Left running at ${base} (Ctrl+C to stop).`);
    await new Promise(() => {});
}
await cli[Symbol.asyncDispose]();
process.exit(failed ? 1 : 0);
