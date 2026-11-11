// End-to-end test of the MemberPress switch (`wp anypress memberpress switch` and the check-memberpress /
// switch-memberpress abilities): everyone with an active MemberPress membership into the FluentCommunity space or
// course for it, with a FluentCRM tag and end date, on WordPress Playground as a store of the community site type (the
// AnyPress theme, FluentCommunity and FluentCRM at the store template's versions, AnyPress Client from this checkout
// with ANYPRESS_TENANT_ROLES community). MemberPress is a paid plugin, not on wordpress.org, so it isn't installed:
// the seed writes its memberships (memberpressproduct posts) and its transactions and subscriptions tables with
// MemberPress's own column names, as a moved-in website with MemberPress switched off has them. Checks: the check counts
// only active memberships and changes nothing; an unknown map target is refused; the switch makes a private space for
// an unmapped membership, enrols a mapped course's students, tags contacts (a new one as transactional, an existing
// one keeping its tags), holds back email and leaves MemberPress's data alone; running again is refused; undo puts
// everything back.
//
//   cd scripts/playground && npm ci
//   npm run memberpress                   # exit 1 on a failure
//   npm run memberpress -- --serve        # leave the site running at http://127.0.0.1:9407 (admin / password)
//
// Needs packages/anypress-client/vendor (composer install in packages/anypress-client), a waas-provisioning-api
// checkout next to this repository (its store template's roles.json; or ANYPRESS_API_DIR), and network access to
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
const API = process.env.ANYPRESS_API_DIR || resolve(ROOT, '../waas-provisioning-api');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.3';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9407);
const SERVE = process.argv.includes('--serve');
const SITE_TYPE = 'community';
// The version the store template installs (templates/anypress-store/build.sh).
const FLUENT_COMMUNITY = process.env.FLUENT_COMMUNITY || '2.12.0';
// The version the store template installs (templates/anypress-store/build.sh).
const FLUENT_CRM = process.env.FLUENT_CRM || '3.3.1';
const SITE_KEY = randomBytes(24).toString('hex');
const USER_TOKEN = randomBytes(24).toString('hex');
const REPORTS = process.env.MEMBERPRESS_REPORT_DIR;
const keep = (name, text) => REPORTS && (mkdirSync(REPORTS, { recursive: true }), writeFileSync(resolve(REPORTS, name), typeof text === 'string' ? text : JSON.stringify(text, null, 2)));

for (const [path, fix] of [[resolve(CLIENT, 'vendor/autoload.php'), 'run composer install in packages/anypress-client'], [resolve(API, 'templates/anypress-store/roles.json'), 'set ANYPRESS_API_DIR to a waas-provisioning-api checkout']]) {
    if (!existsSync(path)) {
        console.error(`${path} is missing: ${fix} first.`);
        process.exit(1);
    }
}

const blueprint = {
    landingPage: '/portal/courses',
    login: SERVE,
    features: { networking: true },
    steps: [
        // Background updates and WP-Cron off: nothing changes mid-test, and cron sends no email.
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } },
        { step: 'writeFile', path: '/tmp/wp-cli.phar', data: { resource: 'url', url: 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-community.${FLUENT_COMMUNITY}.zip` }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-crm.${FLUENT_CRM}.zip` }, options: { activate: true } },
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(resolve(API, 'templates/anypress-store/roles.json'), 'utf8') },
        // A store sold as a community site: its site key and site type come from the container's environment.
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${SITE_KEY}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}');\n` },
        {
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/memberpress-test.php',
            data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user; an editor exists to check permissions.
// Every email WordPress actually tries to send (sent or failed) is counted in the mp_test_mail option.
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
$count = function () { update_option('mp_test_mail', (int) get_option('mp_test_mail', 0) + 1, false); };
add_action('wp_mail_succeeded', $count);
add_action('wp_mail_failed', $count);
// Stands in for a plugin that emails each new student, to show the switch holds such email back.
add_action('fluent_community/course/enrolled', function ($course, $user_id) { wp_mail(get_userdata($user_id)->user_email, 'Welcome to ' . $course->title, 'Hello'); }, 10, 2);
`,
        },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'Playground Members Club', permalink_structure: '/%postname%/' } },
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
        const start = text.indexOf('{\n');
        try {
            return JSON.parse(text.slice(start < 0 ? text.indexOf('{') : start));
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
let failed = 0;
async function check(name, fn) {
    try {
        await fn();
        results.push([true, name]);
        console.log(`  ok    ${name}`);
    } catch (error) {
        results.push([false, name]);
        failed++;
        console.log(`  FAIL  ${name}\n        ${error.message}`);
    }
}
const expect = (condition, message) => {
    if (!condition) throw new Error(message);
};
const short = (value) => String(JSON.stringify(value)).slice(0, 900);
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

const TARGET = ['fcom_spaces', 'fcom_space_user', 'fcom_xprofile', 'fc_subscribers', 'fc_tags', 'fc_subscriber_pivot', 'fc_subscriber_meta'];
/** A fingerprint of FluentCommunity's and FluentCRM's tables, of MemberPress's data, and the mail counter. */
async function snapshot() {
    return php(`global $wpdb; $p = $wpdb->prefix; $out = [];
    foreach (${JSON.stringify(TARGET)} as $t) {
        $rows = $wpdb->get_results("SELECT * FROM {$p}{$t}", ARRAY_A);
        foreach ($rows as &$r) { unset($r['updated_at'], $r['last_activity']); }
        $out[$t] = ['rows' => count($rows), 'hash' => md5(json_encode($rows))];
    }
    $out['crm_fields'] = ['rows' => 0, 'hash' => md5(json_encode(get_option('_fluentcrm_contact_custom_fields') ?: fluentcrm_get_option('contact_custom_fields', [])))];
    $source = [
        $wpdb->get_results("SELECT ID, post_type, post_status, post_title FROM {$p}posts WHERE post_type = 'memberpressproduct' ORDER BY ID", ARRAY_A),
        $wpdb->get_results("SELECT * FROM {$p}mepr_transactions ORDER BY id", ARRAY_A),
        $wpdb->get_results("SELECT * FROM {$p}mepr_subscriptions ORDER BY id", ARRAY_A),
    ];
    echo json_encode(['tables' => $out, 'source' => md5(json_encode($source)), 'mail' => (int) get_option('mp_test_mail', 0)]);`);
}
const rows = (snap) => Object.fromEntries(Object.entries(snap.tables).map(([t, v]) => [t, v.rows]));

// ---- Seed: MemberPress's memberships, transactions and subscriptions; a FluentCommunity course; a FluentCRM contact.
const seed = await php(String.raw`
$user = fn ($login, $first = '') => ($u = get_user_by('login', $login)) ? $u->ID : wp_insert_user(['user_login' => $login, 'user_email' => "$login@example.com", 'first_name' => $first, 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$ana = $user('ana', 'Ana'); $ben = $user('ben', 'Ben'); $cal = $user('cal'); $dee = $user('dee'); $eve = $user('eve'); $fay = $user('fay');
global $wpdb; $p = $wpdb->prefix;
// MemberPress's tables, with the columns it keeps (a subset of them is enough for the switch).
$wpdb->query("CREATE TABLE {$p}mepr_transactions (id bigint(20) NOT NULL AUTO_INCREMENT, amount decimal(16,2) NOT NULL, total decimal(16,2) DEFAULT 0, user_id bigint(20) NOT NULL, product_id bigint(20) NOT NULL, coupon_id bigint(20) DEFAULT 0, trans_num varchar(255) DEFAULT NULL, status varchar(255) DEFAULT 'pending', txn_type varchar(255) DEFAULT 'payment', gateway varchar(255) DEFAULT 'manual', subscription_id bigint(20) DEFAULT NULL, created_at datetime NOT NULL, expires_at datetime DEFAULT '0000-00-00 00:00:00', PRIMARY KEY (id))");
$wpdb->query("CREATE TABLE {$p}mepr_subscriptions (id bigint(20) NOT NULL AUTO_INCREMENT, user_id bigint(20) NOT NULL, product_id bigint(20) NOT NULL, subscr_id varchar(255) DEFAULT '', gateway varchar(255) DEFAULT 'manual', price decimal(16,2) NOT NULL, period int(11) DEFAULT 1, period_type varchar(20) DEFAULT 'months', status varchar(20) DEFAULT 'pending', created_at datetime NOT NULL, PRIMARY KEY (id))");
$mk = fn ($title, $excerpt = '') => wp_insert_post(['post_type' => 'memberpressproduct', 'post_title' => $title, 'post_excerpt' => $excerpt, 'post_status' => 'publish'], true);
$gold = $mk('Gold', 'Everything, every week.');
$silver = $mk('Silver');
$bronze = $mk('Bronze');
$txn = function ($who, $what, $status, $expires, $sub = null) use ($wpdb, $p) {
    $wpdb->insert("{$p}mepr_transactions", ['amount' => 20, 'total' => 20, 'user_id' => $who, 'product_id' => $what, 'trans_num' => wp_generate_password(10, false), 'status' => $status, 'gateway' => 'stripe', 'subscription_id' => $sub, 'created_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 40), 'expires_at' => $expires]);
};
$future = gmdate('Y-m-d H:i:s', time() + 86400 * 60);
$later = gmdate('Y-m-d H:i:s', time() + 86400 * 400);
$past = gmdate('Y-m-d H:i:s', time() - 86400 * 10);
$wpdb->insert("{$p}mepr_subscriptions", ['user_id' => $ben, 'product_id' => $gold, 'subscr_id' => 'sub_test_ben', 'gateway' => 'stripe', 'price' => 20, 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s')]);
$txn($ana, $gold, 'complete', '0000-00-00 00:00:00');     // lifetime
$txn($ben, $gold, 'complete', $past, 1);                  // an old payment that ran out ...
$txn($ben, $gold, 'complete', $future, 1);                // ... and this month's
$txn($cal, $gold, 'complete', $past);                     // ran out: no access
$txn($fay, $gold, 'confirmed', $later);                   // a free trial still running
$txn($dee, $silver, 'complete', $future);
$txn($eve, $silver, 'refunded', $future);                 // refunded: no access
$txn($cal, $bronze, 'failed', $future);                   // failed: no access
// A FluentCommunity course Silver is mapped to, and Ben already in FluentCRM with a tag of his own.
$req = new WP_REST_Request('POST', '/fluent-community/v2/admin/courses');
$req->set_body_params(['title' => 'Members course', 'description' => 'For Silver members.', 'privacy' => 'private', 'course_type' => 'self_paced', 'status' => 'published']);
$made = rest_get_server()->response_to_data(rest_do_request($req), false);
$course = (int) ($made['course']['id'] ?? (is_object($made['course'] ?? null) ? $made['course']->id : 0));
$vip = FluentCrmApi('tags')->importBulk([['title' => 'VIP', 'slug' => 'vip']]);
FluentCrmApi('contacts')->createOrUpdate(['email' => 'ben@example.com', 'first_name' => 'Ben', 'status' => 'subscribed', 'tags' => [(int) $vip[0]->id]], false, false);
delete_option('mp_test_mail');
echo json_encode(['seed' => compact('gold', 'silver', 'bronze', 'course'), 'mepr' => defined('MEPR_VERSION'), 'crm' => function_exists('FluentCrmApi')]);
`);
keep('seed.json', seed);

await check('seed: a community store with FluentCommunity and FluentCRM on, MemberPress data and no MemberPress plugin', async () => {
    expect(seed.seed?.course > 0 && seed.mepr === false && seed.crm === true, short(seed));
});

const { gold, silver, course } = seed.seed;
const MAP = `${silver}:${course}`;
const start = await snapshot();

await check('the check counts only active memberships, warns that payments stay, and changes nothing', async () => {
    const dry = await wp(`wp anypress memberpress switch --map=${MAP} --dry-run --format=json --user=admin`);
    keep('dry-run.json', dry);
    const p = dry.preflight;
    expect(p.ready && p.source === 'memberpress' && !p.blockers.length, short(p));
    expect(same(p.sources.memberpress.counts, { memberships: 3, members: 4, access: 4, mapped: 1, subscriptions: 1 }), short(p.sources.memberpress.counts));
    expect(p.warnings.some((w) => w.includes('Payments don')), short(p.warnings));
    expect(dry.lost.some((l) => l.includes('subscriptions')), short(dry.lost));
    const viaAbility = await ability('anypress/check-memberpress', { from: 'memberpress' }, { method: 'GET' });
    expect(viaAbility.status === 200 && viaAbility.data.preflight.ready, `check-memberpress: HTTP ${viaAbility.status} ${short(viaAbility.data)}`);
    expect(same((await snapshot()).tables, start.tables), 'the dry run changed something');
});

await check('a map to a space that isn\'t there is refused', async () => {
    const dry = await wp(`wp anypress memberpress switch --map=${gold}:999999 --dry-run --format=json --user=admin`);
    expect(!dry.preflight.ready && dry.preflight.blockers.some((b) => b.includes('999999')), short(dry.preflight));
});

let added;
await check('the switch gives each active member their space or course, tags them, holds back email, leaves MemberPress alone', async () => {
    const run = await wp(`wp anypress memberpress switch --map=${MAP} --format=json --user=admin`);
    keep('switch.json', run);
    expect(!run.dry_run && run.compare.every((r) => r.missing === 0 && r.added === r.source), short(run.compare));
    const after = await snapshot();
    added = rows(after);
    expect(after.mail === start.mail, `email sent: ${start.mail} -> ${after.mail} (held back ${run.emails_blocked})`);
    expect(after.source === start.source, 'MemberPress\'s data changed');
});

await check('spaces, students, tags and end dates are right', async () => {
    const d = await php(`global $wpdb; $p = $wpdb->prefix;
        $in = fn ($space) => $wpdb->get_col($wpdb->prepare("SELECT u.user_login FROM {$p}fcom_space_user m JOIN {$p}users u ON u.ID = m.user_id WHERE m.space_id = %d AND m.status = 'active' ORDER BY u.user_login", $space));
        $spaces = $wpdb->get_results("SELECT id, title, privacy, type FROM {$p}fcom_spaces WHERE type = 'community' ORDER BY id", ARRAY_A);
        foreach ($spaces as &$s) { $s['members'] = $in($s['id']); }
        $contacts = [];
        foreach (\\FluentCrm\\App\\Models\\Subscriber::orderBy('email')->get() as $c) {
            $contacts[$c->email] = ['status' => $c->status, 'tags' => $c->tags()->pluck('title')->sort()->values()->toArray(), 'ends' => $c->custom_fields()['memberpress_expires'] ?? null];
        }
        echo json_encode(['spaces' => $spaces, 'course' => $in(${course}), 'contacts' => $contacts]);`);
    keep('detail.json', d);
    expect(d.spaces.length === 2, short(d.spaces));
    const byTitle = Object.fromEntries(d.spaces.map((s) => [s.title, s]));
    expect(byTitle.Gold?.privacy === 'private' && same(byTitle.Gold.members, ['ana', 'ben', 'fay']), short(byTitle.Gold));
    expect(byTitle.Bronze && same(byTitle.Bronze.members, []), `Bronze has no active members, so an empty space: ${short(byTitle.Bronze)}`);
    expect(same(d.course, ['dee']), `the mapped course: ${short(d.course)}`);
    const c = d.contacts;
    expect(same(Object.keys(c), ['ana@example.com', 'ben@example.com', 'dee@example.com', 'fay@example.com']), short(Object.keys(c)));
    expect(c['ana@example.com'].status === 'transactional' && same(c['ana@example.com'].tags, ['MemberPress: Gold']) && c['ana@example.com'].ends === 'never', short(c['ana@example.com']));
    expect(c['ben@example.com'].status === 'subscribed' && same(c['ben@example.com'].tags, ['MemberPress: Gold', 'VIP']) && /^\d{4}-\d{2}-\d{2}$/.test(c['ben@example.com'].ends), short(c['ben@example.com']));
    expect(same(c['dee@example.com'].tags, ['MemberPress: Silver']), short(c['dee@example.com']));
});

await check('running again is refused; undo puts everything back', async () => {
    const again = await ability('anypress/switch-memberpress', {});
    expect(again.status === 409, `switch-memberpress: HTTP ${again.status} ${short(again.data)}`);
    expect(same(rows(await snapshot()), added), 'something changed');
    const preview = await wp('wp anypress memberpress switch --undo --dry-run --format=json --user=admin');
    expect(preview.removed.access === 4 && preview.removed.spaces === 2 && preview.removed.contacts === 3, short(preview.removed));
    const undo = await ability('anypress/switch-memberpress', { undo: true });
    expect(undo.status === 200, `undo: HTTP ${undo.status} ${short(undo.data)}`);
    const undone = await snapshot();
    const diff = Object.keys(start.tables).filter((t) => start.tables[t].hash !== undone.tables[t].hash);
    expect(!diff.length, `left behind in ${diff.join(', ')}: ${short(rows(undone))} (before ${short(rows(start))})`);
    expect(undone.source === start.source, 'MemberPress\'s data changed');
});

await check('no PHP warnings or errors from AnyPress Client', async () => {
    const log = await php(`$f = WP_CONTENT_DIR . '/debug.log'; echo json_encode(['log' => file_exists($f) ? file_get_contents($f) : '']);`);
    keep('debug.log', log.log);
    const ours = log.log.split('\n').filter((line) => /anypress-client|AnyPressClient/.test(line));
    expect(!ours.length, ours.slice(0, 5).join('\n'));
});

console.log(`\n${results.length - failed} passed, ${failed} failed (site type ${SITE_TYPE})`);
if (SERVE) {
    console.log(`Left running at ${base} (Ctrl+C to stop).`);
    await new Promise(() => {});
}
await cli[Symbol.asyncDispose]();
process.exit(failed ? 1 : 0);
