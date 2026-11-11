// End-to-end test of the forms switch's Gravity Forms source (`wp anypress forms switch` and the check-forms /
// switch-forms abilities) on WordPress Playground: a website about to move in, with the AnyPress theme, Fluent Forms at
// the store template's version and AnyPress Client from this checkout (or AnyPress Move, which carries the same code).
// It is not set up as a store: on a store, AnyPress Client keeps only the site type's plugins on, so Gravity Forms
// (needed while its forms are read) is switched off. The forms switch runs on the website before it moves in, as in
// forms.mjs.
// Gravity Forms is a paid plugin, not on wordpress.org, so gravity/gravityforms.php stands in for the part of its PHP
// API that Fluent Forms' importer reads (GFAPI, GFFormsModel, GF_Field), with Gravity Forms' own form and entry
// shapes; gravity/seed.php writes a form with every common field, a conditional and a default confirmation, two
// notifications (one for another event), an entry limit, a Mailchimp feed, three entries and three pages.
// Checks: the dry run lists the form, its dropped fields and losses and changes nothing; the switch converts it with
// the importer's mistakes put right (hidden name and address parts, dates, limits, confirmations, notifications) and
// brings the entries in with their dates, IP, browser and page; pages, blocks and a draft are swapped, a conditional
// content shortcode is left alone; the page shows the Fluent Forms form; running again changes nothing; undo puts
// every page back.
//
//   cd scripts/playground && npm ci
//   npm run gravity-forms               # exit 1 on a failure
//   npm run gravity-forms -- --serve    # leave the site running at http://127.0.0.1:9411 (admin / password)
//
// Needs packages/anypress-client/vendor (composer install in packages/anypress-client) and network access to
// downloads.wordpress.org.

import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';
import { wpCLI } from '@wp-playground/blueprints';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.4';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9411);
const SERVE = process.argv.includes('--serve');
// The version the store template installs (templates/anypress-store/build.sh).
const FLUENT_FORMS = process.env.FLUENT_FORMS || '6.2.17';
const USER_TOKEN = randomBytes(24).toString('hex');
const REPORTS = process.env.GRAVITY_REPORT_DIR;
const keep = (name, text) => REPORTS && (mkdirSync(REPORTS, { recursive: true }), writeFileSync(resolve(REPORTS, name), typeof text === 'string' ? text : JSON.stringify(text, null, 2)));

if (!existsSync(resolve(CLIENT, 'vendor/autoload.php'))) {
    console.error('packages/anypress-client/vendor is missing: run composer install in packages/anypress-client first.');
    process.exit(1);
}

const blueprint = {
    landingPage: '/wp-admin/admin.php?page=fluent_forms',
    login: SERVE,
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } },
        { step: 'writeFile', path: '/tmp/wp-cli.phar', data: { resource: 'url', url: 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluentform.${FLUENT_FORMS}.zip` }, options: { activate: true } },
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/gravityforms' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/gravityforms/gravityforms.php', data: readFileSync(resolve(HERE, 'gravity/gravityforms.php'), 'utf8') },
        { step: 'activatePlugin', pluginPath: 'gravityforms/gravityforms.php' },
        {
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/gravity-test.php',
            data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user; an editor exists to check permissions.
// Email is logged to the gf_test_mail option instead of sent.
add_action('init', function () {
    if (!get_user_by('login', 'editor')) {
        wp_insert_user(['user_login' => 'editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
    }
});
add_filter('determine_current_user', function ($id) {
    [$token, $login] = array_pad(explode(':', $_SERVER['HTTP_X_TEST_USER'] ?? '', 2), 2, '');
    $user = hash_equals('${USER_TOKEN}', $token) ? get_user_by('login', $login) : false;
    return $user ? $user->ID : $id;
}, 30);
add_filter('pre_wp_mail', function ($sent, $atts) {
    $log = (array) get_option('gf_test_mail', []);
    $log[] = ['to' => $atts['to'], 'subject' => $atts['subject']];
    update_option('gf_test_mail', $log, false);
    return true;
}, 10, 2);
`,
        },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'Playground Gravity Move', permalink_structure: '/%postname%/' } },
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
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}, Fluent Forms ${FLUENT_FORMS}, not a store (the site before it moves in)`);

async function php(code) {
    let out;
    try {
        out = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; wp_set_current_user(1); ${code}` });
    } catch (error) {
        const log = await cli.playground.readFileAsText('/wordpress/wp-content/debug.log').catch(() => '');
        throw new Error(`PHP failed (${String(error.message).split('\n')[0]}); debug.log ends: ${log.slice(-1500)}`);
    }
    const text = out.text.trim();
    const start = text.search(/[[{]/);
    if (start < 0) return text;
    try {
        return JSON.parse(text.slice(start));
    } catch {
        return text;
    }
}
async function wp(command) {
    let text;
    try {
        text = (await wpCLI(cli.playground, { command })).text.trim();
    } catch (error) {
        text = String(error.message ?? error);
    }
    if (command.includes('--format=json')) {
        const start = text.indexOf('{');
        try {
            return JSON.parse(text.slice(start));
        } catch (error) {
            keep('last-not-json.txt', text);
            throw new Error(`not JSON (${error.message}): ${text.slice(0, 300)} ... ${text.slice(-500)}`);
        }
    }
    return text;
}
async function ability(name, input, { as = 'admin', method = 'POST' } = {}) {
    const url = `${base}/wp-json/wp-abilities/v1/abilities/${name}/run`;
    const headers = { 'content-type': 'application/json' };
    if (as) headers['x-test-user'] = `${USER_TOKEN}:${as}`;
    const query = method === 'GET' && input ? '?' + new URLSearchParams(Object.entries(input).map(([k, v]) => [`input[${k}]`, String(v)])) : '';
    const res = await fetch(url + query, { method, headers, body: method === 'GET' ? undefined : JSON.stringify(input === undefined ? {} : { input }) });
    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        data = text;
    }
    return { status: res.status, data };
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
function expect(condition, message) {
    if (!condition) throw new Error(message);
}
const short = (value) => JSON.stringify(value).slice(0, 700);

const seedOut = await cli.playground.run({ code: readFileSync(resolve(HERE, 'gravity/seed.php'), 'utf8') });
const seeded = JSON.parse(seedOut.text.trim().slice(seedOut.text.trim().lastIndexOf('\n{') + 1));
const contents = () => php(`echo json_encode(['page' => get_post_field('post_content', ${seeded.page}), 'block' => get_post_field('post_content', ${seeded.block}), 'draft' => get_post_field('post_content', ${seeded.draft})]);`);
const original = await contents();
const counts = () => php(`global $wpdb; echo json_encode([
    'forms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_forms"),
    'entries' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions"),
    'active' => array_values(get_option('active_plugins')),
]);`);
const gravity = (report) => report.forms.find((f) => f.source_type === 'gravityform' && f.title === 'GF Kitchen Sink');
let fluentId;

await check('Fluent Forms and Gravity Forms are on', async () => {
    const now = await counts();
    expect(now.active.includes('fluentform/fluentform.php') && now.active.includes('gravityforms/gravityforms.php'), `active: ${now.active.join(', ')}`);
});

await check('dry run: lists the Gravity Forms form, its dropped fields and losses, changes nothing', async () => {
    const before = await counts();
    const report = await wp('wp anypress forms switch --dry-run --format=json --user=admin');
    keep('dry-run.json', report);
    const form = gravity(report);
    expect(form && form.status === 'will be converted' && form.source === 'Gravity Forms', short(report.forms));
    const dropped = form.dropped_fields.map((f) => `${f.type}:${f.reason}`);
    for (const type of ['phone', 'fileupload', 'list']) {
        expect(dropped.some((d) => d.startsWith(`${type}:Needs Fluent Forms Pro`)), `dropped ${dropped.join(', ')}`);
    }
    expect(form.lost.some((l) => l.includes('Referral code')), `lost (show/hide) ${short(form.lost)}`);
    expect(form.lost.some((l) => l.includes('gravityformsmailchimp')), `lost (feeds) ${short(form.lost)}`);
    expect(form.entries.found === 3, `entries ${short(form.entries)}`);
    expect(report.pages.length === 3, `pages ${short(report.pages)}`);
    const after = await counts();
    expect(after.forms === before.forms && after.entries === before.entries, `changed: ${short([before, after])}`);
    const now = await contents();
    expect(now.page === original.page, `the page changed in a dry run: ${now.page}`);
});

await check('switch: converted, with the importer\'s mistakes put right', async () => {
    const report = await wp('wp anypress forms switch --format=json --user=admin');
    keep('switch.json', report);
    const form = gravity(report);
    expect(form && form.status === 'converted' && form.fluent_id > 0, short(report.forms));
    fluentId = form.fluent_id;
    const saved = await php(`$f = \\FluentForm\\App\\Models\\Form::find(${fluentId}); $fields = json_decode($f->form_fields, true);
        $meta = fn ($k) => array_map(fn ($r) => json_decode($r->value, true), \\FluentForm\\App\\Models\\FormMeta::where('form_id', ${fluentId})->where('meta_key', $k)->get()->all());
        $find = function ($node, $el) use (&$find) { if (is_array($node)) { if (($node['element'] ?? '') === $el) return $node; foreach ($node as $c) { $r = $find($c, $el); if ($r) return $r; } } return null; };
        echo json_encode(['name' => $find($fields, 'input_name'), 'address' => $find($fields, 'address'), 'dates' => array_map(fn ($n) => $n['settings']['date_format'] ?? null, array_values(array_filter($fields['fields'], fn ($n) => ($n['element'] ?? '') === 'input_date'))),
            'settings' => $meta('formSettings'), 'notifications' => $meta('notifications'), 'confirmations' => $meta('confirmations')]);`);
    keep('fluent-form.json', saved);
    const name = saved.name.fields;
    expect(name.first_name.settings.visible === true && name.middle_name.settings.visible === false && name.last_name.settings.visible === true, `name parts ${short(name)}`);
    const address = saved.address.fields;
    expect(address.address_line_2.settings.validation_rules.required.value === false && address.address_line_1.settings.validation_rules.required.value === true, `address required ${short(address.address_line_2)}`);
    expect(address.country.settings.visible === false && address.city.settings.visible === true, `address visible ${short(address.country)}`);
    expect(saved.dates[0] === 'd/m/Y' && saved.dates[1] === 'H:i', `dates ${short(saved.dates)}`);
    const settings = saved.settings[0];
    const limit = settings.restrictions.limitNumberOfEntries;
    expect(limit.numberOfEntries === 100 && limit.period === 'month' && limit.limitReachedMsg === 'GF-LIMIT: full this month.', `limit ${short(limit)}`);
    expect(settings.confirmation.messageToShow.includes('CUSTOM-GF-CONFIRM') && settings.confirmation.redirectTo === 'samePage', `main confirmation ${short(settings.confirmation)}`);
    expect(saved.notifications.length === 1 && saved.notifications[0].name === 'Admin Notification', `notifications ${short(saved.notifications)}`);
});

await check('entries came across with their dates, order, IP, browser, page and address parts', async () => {
    const rows = await php(`echo json_encode(array_map(fn ($s) => ['ip' => $s->ip, 'browser' => $s->browser, 'url' => $s->source_url, 'created' => (string) $s->created_at, 'response' => json_decode($s->response, true)],
        \\FluentForm\\App\\Models\\Submission::where('form_id', ${fluentId})->orderBy('id', 'ASC')->get()->all()));`);
    keep('entries.json', rows);
    expect(rows.length === 3, `entries ${rows.length}`);
    expect(rows.map((r) => r.ip).join() === '203.0.113.1,203.0.113.2,203.0.113.3', `ips ${short(rows.map((r) => r.ip))}`);
    expect(rows[0].browser === 'TestBrowser/1' && rows[2].url === 'https://anypress.example/contact?from=3', `details ${short(rows[0])}`);
    expect(rows[0].created.startsWith('2026-03-01'), `dates ${short(rows.map((r) => r.created))}`);
    expect(rows[0].response.start_time_10 === '11:30' && rows[0].response.event_date_9 === '01/04/2026', `date and time ${short(rows[0].response)}`);
    const address = Object.values(rows[0].response).find((v) => v && typeof v === 'object' && 'address_line_1' in v);
    expect(address && address.city === 'Atlanta' && !('address_line_2' in address), `address entry ${short(rows[0].response)}`);
    const second = Object.values(rows[1].response).find((v) => v && typeof v === 'object' && 'address_line_1' in v);
    expect(second && second.address_line_2 === 'Suite 5', `address line 2 ${short(second)}`);
});

await check('pages, blocks and a draft are swapped; a conditional shortcode is left alone', async () => {
    const now = await contents();
    expect(now.page.includes(`[fluentform id="${fluentId}"]`) && !now.page.includes('[gravityform '), `page ${now.page}`);
    expect(now.block.includes(`<!-- wp:fluentfom/guten-block {"formId":"${fluentId}"} /-->`) && now.block.includes('[gravityforms action="conditional"'), `block page ${now.block}`);
    expect(now.draft === `[fluentform id="${fluentId}"]`, `draft ${now.draft}`);
    const html = await fetch(`${base}/?page_id=${seeded.page}`).then((r) => r.text());
    expect(html.includes(`fluentform_wrapper_${fluentId}`) && !html.includes('gform_wrapper'), `the page doesn't show the Fluent Forms form: ${html.slice(0, 300)}`);
});

await check('running it again changes nothing and leaves Gravity Forms on', async () => {
    const before = await counts();
    const again = await wp('wp anypress forms switch --format=json --user=admin');
    const after = await counts();
    expect(gravity(again).status === 'already converted' && again.pages.length === 0, short(again));
    expect(after.forms === before.forms && after.entries === before.entries, `counts ${short([before, after])}`);
    expect(after.active.includes('gravityforms/gravityforms.php'), `Gravity Forms turned off: ${after.active.join(', ')}`);
});

await check('abilities: check-forms lists it, switch-forms needs an administrator', async () => {
    const report = await ability('anypress/check-forms', undefined, { method: 'GET' });
    expect(report.status === 200 && gravity(report.data)?.status === 'already converted', `check-forms: HTTP ${report.status} ${short(report.data)}`);
    const editor = await ability('anypress/switch-forms', { undo: false }, { as: 'editor' });
    expect(editor.status === 403, `editor: HTTP ${editor.status}`);
});

await check('undo puts every page back exactly, keeps the Fluent Forms form', async () => {
    const undo = await wp('wp anypress forms switch --undo --format=json --user=admin');
    keep('undo.json', undo);
    const now = await contents();
    for (const key of Object.keys(original)) {
        expect(now[key] === original[key], `${key}: ${now[key]} (was ${original[key]})`);
    }
    const exists = await php(`echo json_encode(['ok' => (bool) \\FluentForm\\App\\Models\\Form::find(${fluentId})]);`);
    expect(exists.ok, 'the Fluent Forms form is gone');
});

await check('no PHP warnings or errors from AnyPress Client', async () => {
    const log = await php(`$f = WP_CONTENT_DIR . '/debug.log'; echo json_encode(['log' => file_exists($f) ? file_get_contents($f) : '']);`);
    keep('debug.log', log.log);
    const ours = log.log.split('\n').filter((line) => /anypress-client|AnyPressClient/.test(line));
    expect(!ours.length, ours.slice(0, 5).join('\n'));
});

const failed = results.filter(([ok]) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);

if (SERVE) {
    console.log(`Left running at ${base} (Ctrl+C to stop).`);
} else {
    await cli[Symbol.asyncDispose]();
    process.exit(failed ? 1 : 0);
}
