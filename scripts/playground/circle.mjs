// End-to-end test of AnyPress Client's Circle switch (`wp anypress circle switch` and the check-circle and
// switch-circle abilities) on WordPress Playground, as a community site: the AnyPress theme, FluentCommunity at the
// store template's version, and AnyPress Client from this checkout with ANYPRESS_TENANT_ROLES community. Circle's Admin
// API is played by circle/fake-circle.php (Circle's published v2 response shapes, answered inside WordPress), so no
// Circle account or token is needed. Checks: the check without a token, with a wrong one and with the right one; an
// editor is refused; the switch brings spaces, groups, members, moderators, topics, posts (two pages) and comments with
// their replies; the token is saved nowhere; no email is sent; it runs once; undo takes out only what it added; the
// command runs it again.
//
//   cd scripts/playground && npm ci
//   npm run circle            # exit 1 on a failure
//   npm run circle -- --serve # leave the site running at http://127.0.0.1:9405 (admin / password)
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
const WP = process.env.PLAYGROUND_WP || '7.1.3';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9405);
const SERVE = process.argv.includes('--serve');
const SITE_TYPE = 'community';
const FLUENT_COMMUNITY = process.env.FLUENT_COMMUNITY || '2.12.0';
const TOKEN = 'circle-test-token';
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
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(ROLES, 'utf8') },
        // A store sold as a community site: its site type comes from the container's environment.
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${randomBytes(24).toString('hex')}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}');\n` },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/fake-circle.php', data: readFileSync(resolve(HERE, 'circle/fake-circle.php'), 'utf8') },
        {
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/circle-test.php',
            data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user. ada@example.com is already a user, as a
// Circle member who also bought from the site would be; an editor checks permissions. Every email WordPress tries to
// send is counted in circle_test_mail.
add_action('init', function () {
    if (!get_user_by('login', 'editor')) {
        wp_insert_user(['user_login' => 'editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
        wp_insert_user(['user_login' => 'ada', 'user_email' => 'ada@example.com', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'description' => 'Already here']);
    }
});
add_filter('determine_current_user', function ($id) {
    [$token, $login] = array_pad(explode(':', $_SERVER['HTTP_X_TEST_USER'] ?? '', 2), 2, '');
    $user = hash_equals('${USER_TOKEN}', $token) ? get_user_by('login', $login) : false;
    return $user ? $user->ID : $id;
}, 30);
$count = function () { update_option('circle_test_mail', (int) get_option('circle_test_mail', 0) + 1, false); };
add_action('wp_mail_succeeded', $count);
add_action('wp_mail_failed', $count);
`,
        },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'Circle move test', permalink_structure: '/%postname%/' } },
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
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}, site type ${SITE_TYPE}, FluentCommunity ${FLUENT_COMMUNITY}`);

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

/** What FluentCommunity and WordPress hold now. */
const state = () =>
    php(`global $wpdb; $p = $wpdb->prefix;
    $spaces = $wpdb->get_results("SELECT s.id, s.title, s.slug, s.privacy, s.settings, g.title AS grp FROM {$p}fcom_spaces s LEFT JOIN {$p}fcom_spaces g ON g.id = s.parent_id WHERE s.type = 'community' ORDER BY s.id", ARRAY_A);
    foreach ($spaces as &$s) { $s['settings'] = maybe_unserialize($s['settings']); $s['members'] = $wpdb->get_results($wpdb->prepare("SELECT u.user_email AS email, su.role FROM {$p}fcom_space_user su JOIN {$wpdb->users} u ON u.ID = su.user_id WHERE su.space_id = %d ORDER BY u.user_email", $s['id']), ARRAY_A); }
    $hello = $wpdb->get_row("SELECT id, user_id, title, message_rendered, created_at, comments_count FROM {$p}fcom_posts WHERE title = 'Hello everyone'", ARRAY_A);
    $old = $wpdb->get_var("SELECT user_id FROM {$p}fcom_posts WHERE title = 'An old post'");
    $comments = $hello ? $wpdb->get_results($wpdb->prepare("SELECT c.id, c.parent_id, c.message_rendered AS body, c.user_id FROM {$p}fcom_post_comments c WHERE c.post_id = %d ORDER BY c.id", $hello['id']), ARRAY_A) : [];
    $topic = fn ($title) => $wpdb->get_col($wpdb->prepare("SELECT t.title FROM {$p}fcom_term_feed tf JOIN {$p}fcom_terms t ON t.id = tf.term_id JOIN {$p}fcom_posts f ON f.id = tf.post_id WHERE f.title = %s ORDER BY t.title", $title));
    $users = [];
    foreach (['ada@example.com', 'grace@example.com', 'alan@example.com', 'katherine@example.com'] as $email) { $u = get_user_by('email', $email); $users[$email] = $u ? ['id' => $u->ID, 'roles' => $u->roles, 'name' => $u->display_name, 'circle' => get_user_meta($u->ID, '_anypress_circle_member_id', true), 'profile' => (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}fcom_xprofile WHERE user_id = %d", $u->ID))] : null; }
    $leaks = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE '%${TOKEN}%' AND option_name <> 'circle_test_tokens'") + (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_value LIKE '%${TOKEN}%'") + (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE '%${TOKEN}%'");
    echo json_encode([
        'spaces' => $spaces,
        'groups' => $wpdb->get_col("SELECT title FROM {$p}fcom_spaces WHERE type = 'space_group' ORDER BY id"),
        'posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fcom_posts"),
        'comments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fcom_post_comments"),
        'topics' => $wpdb->get_col("SELECT title FROM {$p}fcom_terms WHERE taxonomy_name = 'post_topic' ORDER BY title"),
        'hello' => $hello, 'hello_topics' => $topic('Hello everyone'), 'rules_topics' => $topic('House rules'), 'old_by' => (int) $old,
        'comment_rows' => $comments, 'users' => $users, 'leaks' => $leaks,
        'mail' => (int) get_option('circle_test_mail', 0), 'calls' => (int) get_option('circle_test_calls', 0),
        'editor_ok' => (bool) get_user_by('login', 'editor'),
        'fluent_community' => is_plugin_active('fluent-community/fluent-community.php'),
    ]);`);

await check('the site is a community site with FluentCommunity on', async () => {
    const now = await state();
    expect(now.fluent_community && now.editor_ok, short(now));
});

await check('without a token the check asks for one and changes nothing', async () => {
    const res = await ability('check-circle', {}, { method: 'GET' });
    expect(res.status === 200 && res.data.preflight.ready === false, short(res));
    expect(res.data.preflight.blockers.some((b) => b.includes('Admin V2 token')), short(res.data.preflight.blockers));
});

await check('a wrong token is refused with Circle\'s own answer', async () => {
    const res = await ability('check-circle', { token: 'wrong' }, { method: 'GET' });
    expect(res.status === 200 && !res.data.preflight.ready && res.data.preflight.blockers.some((b) => b.includes('did not accept the token')), short(res));
    expect(!JSON.stringify(res.data).includes('wrong'), 'the wrong token was shown back');
});

let calls_before;
await check('with the token the check counts the Circle community and warns about API calls and the chat space', async () => {
    calls_before = (await state()).calls;
    const res = await ability('check-circle', { token: TOKEN }, { method: 'GET' });
    const p = res.data.preflight;
    expect(res.status === 200 && p.ready, short(res));
    expect(JSON.stringify(p.sources.circle.counts) === JSON.stringify({ spaces: 2, members: 4, posts: 123, comments: 2 }), short(p.sources));
    expect(p.warnings.some((w) => w.includes('Admin API calls')) && p.warnings.some((w) => w.includes('only discussion spaces')), short(p.warnings));
    expect(res.data.lost.some((l) => l.startsWith('Likes')), short(res.data.lost));
    const after = await state();
    expect(after.spaces.length === 0 && after.posts === 0 && after.calls - calls_before <= 5, short(after));
});

await check('an editor can\'t run it', async () => {
    const res = await ability('switch-circle', { token: TOKEN }, { as: 'editor' });
    expect(res.status === 403, short(res));
});

let report;
await check('the switch brings the Circle community across', async () => {
    const res = await ability('switch-circle', { token: TOKEN });
    report = res.data;
    expect(res.status === 200 && report.dry_run === false, short(res));
    expect(row(report, 'spaces').added === 2 && row(report, 'posts').added === 123 && row(report, 'posts').missing === 0, short(report.compare));
    expect(row(report, 'comments').added === 4 && row(report, 'members').added >= 3, short(report.compare));
    expect(report.fixed.some((f) => f.includes('same email')) && report.fixed.some((f) => f.includes('left Circle')) && report.fixed.some((f) => f.includes('Admin API calls')), short(report.fixed));
});

let after;
await check('spaces keep their group, privacy, posting rule, members and moderators; the chat space stays out', async () => {
    after = await state();
    const [welcome, insiders] = after.spaces;
    expect(after.spaces.length === 2 && after.groups.join() === 'Start Here,Members Only', short(after.spaces));
    expect(welcome.title === 'Welcome' && welcome.privacy === 'public' && welcome.grp === 'Start Here' && welcome.settings.restricted_post_only === 'no', short(welcome));
    expect(insiders.privacy === 'secret' && insiders.grp === 'Members Only' && insiders.settings.restricted_post_only === 'yes', short(insiders));
    expect(JSON.stringify(welcome.members) === JSON.stringify([{ email: 'ada@example.com', role: 'member' }, { email: 'alan@example.com', role: 'member' }, { email: 'grace@example.com', role: 'moderator' }]), short(welcome.members));
    expect(JSON.stringify(insiders.members) === JSON.stringify([{ email: 'ada@example.com', role: 'moderator' }, { email: 'katherine@example.com', role: 'member' }]), short(insiders.members));
});

await check('members are WordPress subscribers with community profiles; Ada is matched to her existing user', async () => {
    const { users } = after;
    expect(['grace@example.com', 'alan@example.com', 'katherine@example.com'].every((e) => users[e] && users[e].roles.join() === 'subscriber' && users[e].profile && users[e].circle), short(users));
    expect(users['grace@example.com'].name === 'Grace Hopper' && users['ada@example.com'].circle === '11', short(users));
});

await check('posts keep their author, date, topics and safe HTML; comments keep replies under their comment', async () => {
    const { hello, comment_rows: c } = after;
    expect(after.posts === 123 && hello.user_id == after.users['ada@example.com'].id && hello.created_at.startsWith('2025-01-02'), short(hello));
    expect(hello.message_rendered.includes('<strong>community</strong>') && !hello.message_rendered.includes('<script'), hello.message_rendered);
    expect(after.old_by === 1, `the post by a member who left belongs to user ${after.old_by}`);
    expect(after.topics.join() === 'Announcements,Tips' && after.hello_topics.join() === 'Announcements' && after.rules_topics.join() === 'Announcements,Tips', short([after.topics, after.hello_topics, after.rules_topics]));
    expect(c.length === 4 && Number(hello.comments_count) === 4, short(c));
    expect(c[0].parent_id === null && c[1].parent_id == c[0].id && c[2].parent_id == c[0].id && c[3].parent_id === null && c[3].user_id == 1, short(c));
});

await check('the token is saved nowhere and no email was sent', async () => {
    expect(after.leaks === 0 && after.mail === 0, short({ leaks: after.leaks, mail: after.mail }));
});

await check('it runs once', async () => {
    const res = await ability('switch-circle', { token: TOKEN });
    expect(res.status === 409, short(res));
});

await check('undo takes out only what the switch added', async () => {
    const dry = await wp('wp anypress circle switch --user=admin --undo --dry-run --format=json');
    expect(dry.removed.spaces === 2 && dry.removed.posts === 123 && dry.removed.users === 3, short(dry));
    const res = await ability('switch-circle', { undo: true });
    expect(res.status === 200 && res.data.undo, short(res));
    const now = await state();
    expect(now.spaces.length === 0 && now.groups.length === 0 && now.posts === 0 && now.comments === 0 && now.topics.length === 0, short(now));
    expect(now.users['grace@example.com'] === null && now.users['ada@example.com'] && now.users['ada@example.com'].circle === '' && now.editor_ok, short(now.users));
});

await check('the command runs it again with --token', async () => {
    const res = await wp(`wp anypress circle switch --user=admin --token=${TOKEN} --format=json`);
    expect(row(res, 'posts').added === 123 && row(res, 'spaces').added === 2, short(res.compare));
    const now = await state();
    expect(now.leaks === 0 && now.mail === 0, short(now));
});

const failed = results.filter((ok) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed (site type ${SITE_TYPE})`);
if (!SERVE) {
    process.exit(failed ? 1 : 0);
}
