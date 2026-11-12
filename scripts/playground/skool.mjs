// End-to-end test of AnyPress Client's Skool switch (`wp anypress skool switch --file=members.csv` and the check-skool
// and switch-skool abilities) on WordPress Playground, as a community site: the AnyPress theme, FluentCommunity and
// FluentCRM from wordpress.org, and AnyPress Client from this checkout with ANYPRESS_TENANT_ROLES community. The members
// CSV is skool/members.csv, laid out as Skool's Members tab export (name, email, the membership questions and answers,
// and columns the switch leaves out), with anypress.example emails, a member without an email, one with a bad email,
// one listed twice and an answer over two lines. Checks: the check without a file and with a file that has no email
// column; an editor is refused; the switch makes users (no email sent), matches one by email, puts everyone in a new
// private space, tags them in FluentCRM with their answers in custom fields; the file is saved nowhere; it runs once;
// undo takes out only what it added; the command runs it again with --file and --space.
//
//   cd scripts/playground && npm ci
//   npm run skool            # exit 1 on a failure
//   npm run skool -- --serve # leave the site running at http://127.0.0.1:9406 (admin / password)
//
// Needs packages/anypress-client/vendor (composer install --no-dev in packages/anypress-client), a
// waas-provisioning-api checkout next to this repository (its store template's roles.json; or ANYPRESS_API_DIR), and
// network access to downloads.wordpress.org. Playground stores the database in SQLite, not MariaDB.

import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';
import { wpCLI } from '@wp-playground/blueprints';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const API = process.env.ANYPRESS_API_DIR || resolve(ROOT, '../waas-provisioning-api');
const ROLES = resolve(API, 'templates/anypress-store/roles.json');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.4';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9406);
const SERVE = process.argv.includes('--serve');
const SITE_TYPE = 'community';
const FLUENT_COMMUNITY = process.env.FLUENT_COMMUNITY || '2.13.0';
const FLUENT_CRM = process.env.FLUENT_CRM || '3.3.1';
const CSV = readFileSync(resolve(HERE, 'skool/members.csv'), 'utf8');
const USER_TOKEN = randomBytes(24).toString('hex');

for (const [path, fix] of [[resolve(CLIENT, 'vendor/autoload.php'), 'run composer install --no-dev in packages/anypress-client'], [ROLES, 'set ANYPRESS_API_DIR to a waas-provisioning-api checkout']]) {
    if (!existsSync(path)) {
        console.error(`${path} is missing: ${fix} first.`);
        process.exit(1);
    }
}

const blueprint = {
    landingPage: '/portal/',
    login: SERVE,
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } },
        { step: 'writeFile', path: '/tmp/wp-cli.phar', data: { resource: 'url', url: 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-community.${FLUENT_COMMUNITY}.zip` }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-crm.${FLUENT_CRM}.zip` }, options: { activate: true } },
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(ROLES, 'utf8') },
        // A store sold as a community site: its site type comes from the container's environment.
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${randomBytes(24).toString('hex')}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}');\n` },
        { step: 'writeFile', path: '/tmp/members.csv', data: CSV },
        {
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/skool-test.php',
            data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user. ada@anypress.example is already a user,
// as a Skool member who also bought from the site would be; an editor checks permissions. Every email WordPress tries
// to send is counted in skool_test_mail.
add_action('init', function () {
    if (!get_user_by('login', 'editor')) {
        wp_insert_user(['user_login' => 'editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
        wp_insert_user(['user_login' => 'ada', 'user_email' => 'ada@anypress.example', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'display_name' => 'Ada L']);
    }
});
add_filter('determine_current_user', function ($id) {
    [$token, $login] = array_pad(explode(':', $_SERVER['HTTP_X_TEST_USER'] ?? '', 2), 2, '');
    $user = hash_equals('${USER_TOKEN}', $token) ? get_user_by('login', $login) : false;
    return $user ? $user->ID : $id;
}, 30);
$count = function () { update_option('skool_test_mail', (int) get_option('skool_test_mail', 0) + 1, false); };
add_action('wp_mail_succeeded', $count);
add_action('wp_mail_failed', $count);
`,
        },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'Skool move test', permalink_structure: '/%postname%/' } },
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
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}, site type ${SITE_TYPE}, FluentCommunity ${FLUENT_COMMUNITY}, FluentCRM ${FLUENT_CRM}`);

async function php(code) {
    let out;
    try {
        out = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; wp_set_current_user(1); ${code}` });
    } catch (error) {
        const log = await cli.playground.readFileAsText('/wordpress/wp-content/debug.log').catch(() => '');
        throw new Error(`PHP failed (${String(error.message).split('\n')[0]}); debug.log ends: ${log.slice(-1500)}`);
    }
    const text = out.text.trim();
    return JSON.parse(text.slice(text.search(/[[{]/)));
}
async function wp(command) {
    const text = (await wpCLI(cli.playground, { command })).text.trim();
    const start = text.indexOf('{\n');
    return JSON.parse(text.slice(start < 0 ? text.indexOf('{') : start));
}
async function ability(name, input, { as = 'admin', method = 'POST' } = {}) {
    let url = `${base}/wp-json/wp-abilities/v1/abilities/anypress/${name}/run`;
    const headers = { 'content-type': 'application/json', 'x-test-user': `${USER_TOKEN}:${as}` };
    let body;
    if (method === 'GET') {
        if (input) url += `?${new URLSearchParams(Object.entries(input).map(([k, v]) => [`input[${k}]`, String(v)]))}`;
    } else {
        body = JSON.stringify({ input: input || {} });
    }
    const res = await fetch(url, { method, headers, body });
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
        results.push(true);
        console.log(`  ok    ${name}`);
    } catch (error) {
        results.push(false);
        console.log(`  FAIL  ${name}\n        ${error.message}`);
    }
}
const expect = (condition, message) => {
    if (!condition) throw new Error(message);
};
const short = (value) => String(JSON.stringify(value)).slice(0, 900);
const row = (report, item) => report.compare.find((r) => r.item === item) || {};
const EMAILS = ['ada@anypress.example', 'grace@anypress.example', 'alan@anypress.example', 'katherine@anypress.example'];

/** What FluentCommunity, FluentCRM and WordPress hold now. */
const state = () =>
    php(`global $wpdb; $p = $wpdb->prefix;
    $spaces = $wpdb->get_results("SELECT id, title, slug, privacy FROM {$p}fcom_spaces WHERE type = 'community' ORDER BY id", ARRAY_A);
    foreach ($spaces as &$s) { $s['members'] = $wpdb->get_col($wpdb->prepare("SELECT u.user_email FROM {$p}fcom_space_user su JOIN {$wpdb->users} u ON u.ID = su.user_id WHERE su.space_id = %d AND su.status = 'active' ORDER BY u.user_email", $s['id'])); }
    $fields = array_values(array_filter((array) fluentcrm_get_option('contact_custom_fields', []), fn ($f) => str_starts_with($f['slug'] ?? '', 'skool_')));
    $by_label = array_column($fields, 'slug', 'label');
    $users = [];
    foreach (${JSON.stringify(EMAILS)} as $email) {
        $u = get_user_by('email', $email);
        $c = \\FluentCrm\\App\\Models\\Subscriber::where('email', $email)->first();
        $answers = [];
        if ($c) { foreach ($c->custom_fields() as $slug => $value) { $label = array_search($slug, $by_label, true); if ($label !== false) { $answers[$label] = $value; } } }
        $users[$email] = $u ? ['id' => $u->ID, 'roles' => $u->roles, 'name' => $u->display_name, 'skool' => get_user_meta($u->ID, '_anypress_skool_member', true), 'profile' => (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}fcom_xprofile WHERE user_id = %d", $u->ID)), 'contact' => $c ? ['status' => $c->status, 'tags' => $c->tags->pluck('title')->toArray(), 'answers' => $answers] : null] : null;
    }
    $leaks = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE '%ships and code%' OR option_value LIKE '%Learning to build things%'") + (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_value LIKE '%Learning to build things%'");
    echo json_encode([
        'spaces' => $spaces,
        'users' => $users,
        'fields' => array_column($fields, 'label'),
        'tag' => (bool) \\FluentCrm\\App\\Models\\Tag::where('title', 'Skool member')->first(),
        'contacts' => (int) \\FluentCrm\\App\\Models\\Subscriber::count(),
        'leaks' => $leaks,
        'mail' => (int) get_option('skool_test_mail', 0),
        'editor_ok' => (bool) get_user_by('login', 'editor'),
        'ok' => is_plugin_active('fluent-community/fluent-community.php') && is_plugin_active('fluent-crm/fluent-crm.php'),
    ]);`);

await check('the site is a community site with FluentCommunity and FluentCRM on', async () => {
    const now = await state();
    expect(now.ok && now.editor_ok && now.spaces.length === 0, short(now));
});

await check('without a file the check asks for the Members tab CSV and changes nothing', async () => {
    const res = await ability('check-skool', {}, { method: 'GET' });
    expect(res.status === 200 && res.data.preflight.ready === false, short(res));
    expect(res.data.preflight.blockers.some((b) => b.includes('Members tab')), short(res.data.preflight.blockers));
});

await check('a file without an email column is refused', async () => {
    const res = await ability('check-skool', { csv: 'FirstName,LastName\nAda,Lovelace\n' }, { method: 'GET' });
    expect(res.status === 200 && !res.data.preflight.ready && res.data.preflight.blockers.some((b) => b.includes('no email column')), short(res));
});

await check('with the file the check counts members, rows without an email, repeats and left-out columns', async () => {
    const res = await ability('check-skool', { csv: CSV }, { method: 'GET' });
    const p = res.data.preflight;
    expect(res.status === 200 && p.ready, short(res));
    expect(JSON.stringify(p.sources.skool.counts) === JSON.stringify({ members: 4, without_email: 2, questions: 3, answers: 8 }), short(p.sources));
    expect(p.warnings.some((w) => w.includes('2 members have no email') && w.includes('rows 5, 7')), short(p.warnings));
    expect(p.warnings.some((w) => w.includes('1 email is in the file more than once')), short(p.warnings));
    expect(p.warnings.some((w) => w.includes('Invited By, JoinedDate, Tier, LTV')), short(p.warnings));
    expect(res.data.lost.some((l) => l.includes('classroom') && l.includes('rebuilt') && l.includes('anypress.io/care')), short(res.data.lost));
    const now = await state();
    expect(now.spaces.length === 0 && now.users['grace@anypress.example'] === null, short(now));
});

await check('an editor can\'t run it', async () => {
    const res = await ability('switch-skool', { csv: CSV }, { as: 'editor' });
    expect(res.status === 403, short(res));
});

let report;
await check('the switch brings every member with an email across', async () => {
    const res = await ability('switch-skool', { csv: CSV });
    report = res.data;
    expect(res.status === 200 && report.dry_run === false, short(res));
    expect(row(report, 'members').added === 4 && row(report, 'members').missing === 0, short(report.compare));
    expect(report.skipped === 2 && report.fixed.some((f) => f.includes('same email')) && report.fixed.some((f) => f.includes('tagged "Skool member"')), short(report));
});

let after;
await check('members are subscribers in a new private "Skool members" space; Ada is matched to her user', async () => {
    after = await state();
    expect(after.spaces.length === 1 && after.spaces[0].title === 'Skool members' && after.spaces[0].privacy === 'private', short(after.spaces));
    expect(after.spaces[0].members.join() === [...EMAILS].sort().join(), short(after.spaces[0].members));
    const { users } = after;
    expect(EMAILS.every((e) => users[e] && users[e].roles.join() === 'subscriber' && users[e].profile && users[e].skool), short(users));
    expect(users['grace@anypress.example'].name === 'Grace Hopper' && users['ada@anypress.example'].name === 'Ada L', short(users));
});

await check('FluentCRM has each member tagged, with their answers in one custom field per question', async () => {
    const { users, fields } = after;
    expect(after.tag && fields.join('|') === 'What brings you here?|Where did you hear about us?|What is your website?', short(fields));
    expect(EMAILS.every((e) => users[e].contact && users[e].contact.status === 'transactional' && users[e].contact.tags.includes('Skool member')), short(users));
    const grace = users['grace@anypress.example'].contact.answers;
    expect(grace['What brings you here?'] === 'Two lines:\nships and code' && grace['Where did you hear about us?'] === 'YouTube' && !('What is your website?' in grace), short(grace));
    expect(users['katherine@anypress.example'].contact.answers['What brings you here?'] === 'Maths, "orbits" and more', short(users['katherine@anypress.example']));
    expect(users['ada@anypress.example'].contact.answers['What is your website?'] === 'ada.anypress.example', short(users['ada@anypress.example']));
});

await check('the file is saved nowhere and no email was sent', async () => {
    expect(after.leaks === 0 && after.mail === 0, short({ leaks: after.leaks, mail: after.mail }));
});

await check('it runs once', async () => {
    const res = await ability('switch-skool', { csv: CSV });
    expect(res.status === 409, short(res));
});

await check('undo takes out only what the switch added', async () => {
    const dry = await wp('wp anypress skool switch --user=admin --undo --dry-run --format=json');
    expect(dry.removed.members === 4 && dry.removed.spaces === 1 && dry.removed.users === 3 && dry.removed.contacts === 4 && dry.removed.tags === 1 && dry.removed.fields === 3, short(dry));
    const res = await ability('switch-skool', { undo: true });
    expect(res.status === 200 && res.data.undo, short(res));
    const now = await state();
    expect(now.spaces.length === 0 && now.fields.length === 0 && !now.tag && now.contacts === 0, short(now));
    expect(now.users['grace@anypress.example'] === null && now.users['ada@anypress.example'] && now.users['ada@anypress.example'].skool === '' && now.editor_ok, short(now.users));
});

await check('the command runs it again with --file and --space into a space that is already there', async () => {
    const space = await php(`$s = \\FluentCommunity\\App\\Models\\Space::create(['title' => 'Members', 'slug' => 'members', 'privacy' => 'private', 'description' => '', 'settings' => []]); echo json_encode(['id' => (int) $s->id]);`);
    const res = await wp(`wp anypress skool switch --user=admin --file=/tmp/members.csv --space=${space.id} --format=json`);
    expect(row(res, 'members').added === 4, short(res.compare));
    const now = await state();
    expect(now.spaces.length === 1 && now.spaces[0].title === 'Members' && now.spaces[0].members.length === 4 && now.mail === 0, short(now));
    const missing = await wp('wp anypress skool switch --user=admin --undo --format=json');
    expect(missing.removed.spaces === 0 && missing.removed.members === 4, short(missing));
});

const failed = results.filter((ok) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed (site type ${SITE_TYPE})`);
if (!SERVE) {
    process.exit(failed ? 1 : 0);
}
