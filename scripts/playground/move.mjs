// End-to-end test of AnyPress Move, the moving switches as their own plugin for any WordPress site (not an AnyPress
// store, so no site type: a plain WordPress with the AnyPress theme, FluentCommunity and Tutor LMS from wordpress.org).
// Tutor LMS makes a course, an enrolment and progress with its own functions and is then switched off. Checks: every
// switch's abilities and WP-CLI commands are there; the Tools, Move to Fluent page lists every switch; its Check
// changes nothing; its Switch makes the FluentCommunity course with the student and progress; its Undo takes it back
// out; the Tutor LMS data never changes; and with AnyPress Client active, AnyPress Move does nothing.
//
//   cd scripts/playground && npm ci
//   npm run move                          # exit 1 on a failure
//   npm run move -- --serve               # leave the site running at http://127.0.0.1:9408 (admin / password)
//
// Needs packages/anypress-move built (scripts/build-move.sh, then composer install in packages/anypress-move),
// packages/anypress-client/vendor (composer install there), and network access to downloads.wordpress.org.

import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';
import { wpCLI } from '@wp-playground/blueprints';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const MOVE = resolve(ROOT, 'packages/anypress-move');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const THEME = resolve(HERE, 'preview-zips/anypress.zip');
const WP = process.env.PLAYGROUND_WP || '7.1.3';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9408);
const SERVE = process.argv.includes('--serve');
const FLUENT_COMMUNITY = process.env.FLUENT_COMMUNITY || '2.12.0';
const TUTOR = process.env.TUTOR || 'latest-stable';
const USER_TOKEN = randomBytes(24).toString('hex');
const SWITCHES = ['forms', 'shop', 'crm', 'support', 'affiliate', 'community', 'course', 'circle', 'memberpress'];

for (const [path, fix] of [
    [resolve(MOVE, 'lib/Moving/CourseImport.php'), 'run scripts/build-move.sh'],
    [resolve(MOVE, 'vendor/autoload.php'), 'run composer install in packages/anypress-move'],
    [resolve(CLIENT, 'vendor/autoload.php'), 'run composer install in packages/anypress-client'],
]) {
    if (!existsSync(path)) {
        console.error(`${path} is missing: ${fix} first.`);
        process.exit(1);
    }
}

const blueprint = {
    landingPage: '/wp-admin/tools.php?page=anypress-move',
    login: SERVE,
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } },
        { step: 'writeFile', path: '/tmp/wp-cli.phar', data: { resource: 'url', url: 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-community.${FLUENT_COMMUNITY}.zip` }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/tutor.${TUTOR}.zip` }, options: { activate: true } },
        {
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/move-test.php',
            data: `<?php
// Test only: X-Test-User: <token>:<login> signs a REST request in as that user.
add_filter('determine_current_user', function ($id) {
    [$token, $login] = array_pad(explode(':', $_SERVER['HTTP_X_TEST_USER'] ?? '', 2), 2, '');
    $user = hash_equals('${USER_TOKEN}', $token) ? get_user_by('login', $login) : false;
    return $user ? $user->ID : $id;
}, 30);
`,
        },
        { step: 'activatePlugin', pluginPath: 'anypress-move/anypress-move.php' },
        { step: 'setSiteOptions', options: { blogname: 'Playground Bakery', permalink_structure: '/%postname%/' } },
    ],
};

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${WP}.zip`,
    php: PHP,
    port: PORT,
    blueprint,
    mount: [
        { hostPath: MOVE, vfsPath: '/wordpress/wp-content/plugins/anypress-move' },
        { hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/anypress-client' },
    ],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
console.log(`WordPress ${WP} on PHP ${PHP} at ${base} (a plain WordPress, not a store), FluentCommunity ${FLUENT_COMMUNITY}, Tutor LMS ${TUTOR}`);

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
    try {
        return (await wpCLI(cli.playground, { command })).text.trim();
    } catch (error) {
        return String(error.message ?? error);
    }
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

/** The Move to Fluent page as an administrator sees it. */
const page = () => php(`require_once ABSPATH . 'wp-admin/includes/admin.php'; set_current_screen('tools_page_anypress-move'); do_action('admin_menu');
    $hook = get_plugin_page_hook('anypress-move', 'tools.php'); ob_start(); if ($hook) { do_action($hook); } echo json_encode(['hook' => $hook, 'html' => ob_get_clean()]);`);
/** Presses one of the page's buttons, as the form posts it to admin-post.php. */
const press = (key, act) => php(`$_POST = $_REQUEST = ['action' => 'anypress_move', 'switch' => '${key}', 'act' => '${act}', 'from' => 'tutor', '_wpnonce' => wp_create_nonce('anypress_move'), '_wp_http_referer' => '/wp-admin/tools.php?page=anypress-move'];
    add_filter('wp_redirect', function ($to) { echo json_encode(['redirect' => $to, 'result' => get_transient('anypress_move_result_1')]); return false; });
    do_action('admin_post_anypress_move');`);
const course = () => php(`global $wpdb; $p = $wpdb->prefix;
    $c = $wpdb->get_row("SELECT id, title FROM {$p}fcom_spaces WHERE type = 'course'", ARRAY_A);
    $students = $c ? $wpdb->get_col($wpdb->prepare("SELECT u.user_login FROM {$p}fcom_space_user m JOIN {$p}users u ON u.ID = m.user_id WHERE m.space_id = %d AND m.role = 'student'", $c['id'])) : [];
    $lessons = $c ? $wpdb->get_col($wpdb->prepare("SELECT title FROM {$p}fcom_posts WHERE space_id = %d AND type = 'course_lesson' ORDER BY priority, id", $c['id'])) : [];
    $done = $c ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}fcom_post_reactions WHERE parent_id = %d AND object_type = 'lesson_completed'", $c['id'])) : 0;
    echo json_encode(['course' => $c, 'students' => $students, 'lessons' => $lessons, 'done' => $done]);`);
const tutorData = () => php(`global $wpdb; $p = $wpdb->prefix; echo json_encode(['hash' => md5(json_encode([
    $wpdb->get_results("SELECT ID, post_type, post_status, post_title, post_parent FROM {$p}posts WHERE post_type IN ('courses', 'topics', 'lesson', 'tutor_enrolled') ORDER BY ID", ARRAY_A),
    $wpdb->get_results("SELECT user_id, meta_key, meta_value FROM {$p}usermeta WHERE meta_key LIKE '\\\\_tutor%' ORDER BY umeta_id", ARRAY_A),
]))]);`);

// ---- Tutor LMS makes a course with a student and progress, then is switched off, as on a site that's moved on.
const seed = await php(String.raw`$para = fn ($text) => "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
$ana = wp_insert_user(['user_login' => 'ana', 'user_email' => 'ana@example.com', 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$mk = fn ($type, $title, $parent = 0, $content = '', $order = 0) => wp_insert_post(['post_type' => $type, 'post_title' => $title, 'post_parent' => $parent, 'post_content' => $content, 'post_status' => 'publish', 'menu_order' => $order, 'post_author' => 1], true);
$a = $mk('courses', 'Sourdough Basics', 0, $para('A starter and a first loaf.'));
update_post_meta($a, '_tutor_course_price_type', 'free');
$t = $mk('topics', 'Your starter', $a, '', 1);
$feed = $mk('lesson', 'Feeding the starter', $t, $para('Feed it twice a day.'), 1);
$mk('lesson', 'When it is ready', $t, $para('It doubles in four hours.'), 2);
$e = \Tutor\Models\EnrollmentModel::do_enroll($a, 0, $ana);
wp_update_post(['ID' => $e, 'post_status' => 'completed']);
\Tutor\Models\LessonModel::mark_lesson_complete($feed, $ana);
deactivate_plugins('tutor/tutor.php');
echo json_encode(['course' => $a, 'enrolment' => get_post_status($e), 'version' => TUTOR_VERSION]);`);
console.log(`Tutor LMS ${seed.version}: course ${seed.course}, enrolment ${seed.enrolment}`);
const before = await tutorData();

await check('every switch is there as abilities and WP-CLI commands, without AnyPress Client', async () => {
    const state = await php(`$names = array_keys(wp_get_abilities()); echo json_encode(['client' => defined('ANYPRESS_CLIENT_VERSION'), 'move' => class_exists('AnyPressMove\\\\Plugin', false), 'names' => $names]);`);
    expect(!state.client && state.move, short(state));
    const missing = SWITCHES.flatMap((k) => [`anypress/check-${k}`, `anypress/switch-${k}`]).filter((n) => !state.names.includes(n));
    expect(!missing.length, `missing abilities: ${missing.join(', ')}`);
    const help = await wp('wp anypress --help');
    for (const k of SWITCHES) expect(help.includes(k), `no wp anypress ${k}: ${help.slice(0, 600)}`);
});

await check('Tools, Move to Fluent lists every switch with Check, Switch and Undo', async () => {
    const { hook, html } = await page();
    expect(hook && html.includes('Move to Fluent'), short({ hook, html: html?.slice(0, 300) }));
    for (const k of SWITCHES) expect(html.includes(`id="switch-${k}"`), `no ${k} card`);
    expect((html.match(/value="check"/g) || []).length === SWITCHES.length, 'a Check button per switch');
    expect(html.includes('type="password" id="circle-token"'), 'the Circle token is a password field');
    expect(!/ (onclick|style)=/.test(html), 'no inline script or style attributes (they are enqueued)');
});

await check('Check on the page says what would come across and changes nothing', async () => {
    const pressed = await press('course', 'check');
    expect(pressed.redirect?.includes('page=anypress-move#switch-course'), short(pressed));
    const report = pressed.result?.result;
    expect(report?.dry_run === true && report.preflight?.ready === true, short(report));
    expect(report.preflight.sources.tutor.counts.courses === 1, short(report.preflight.sources.tutor));
    const after = await course();
    expect(!after.course, `a course was made: ${short(after)}`);
    const { html } = await page();
    expect(html.includes('Check: nothing was changed') && html.includes('Dry run: nothing was changed.'), 'the result shows on the page');
    expect(!(await page()).html.includes('Check: nothing was changed'), 'the result shows once');
});

await check('Switch on the page makes the FluentCommunity course with the student and progress', async () => {
    const pressed = await press('course', 'switch');
    const report = pressed.result?.result;
    expect(report && report.dry_run === false && !report.errors, short(pressed));
    const made = await course();
    expect(made.course?.title === 'Sourdough Basics', short(made));
    expect(JSON.stringify(made.students) === '["ana"]' && made.lessons.length === 2 && made.done === 1, short(made));
    const again = await press('course', 'check');
    expect(String(JSON.stringify(again.result?.result)).includes('already ran here'), `running again is refused: ${short(again)}`);
});

await check('Undo on the page takes out only what the switch added; Tutor LMS data never changed', async () => {
    const pressed = await press('course', 'undo');
    expect(pressed.result?.result?.undo === true, short(pressed));
    const gone = await course();
    expect(!gone.course, short(gone));
    expect((await tutorData()).hash === before.hash, 'Tutor LMS data changed');
});

await check('an editor cannot open the page or press its buttons', async () => {
    const out = await php(`$id = wp_insert_user(['user_login' => 'editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']); wp_set_current_user($id);
        require_once ABSPATH . 'wp-admin/includes/admin.php'; do_action('admin_menu');
        echo json_encode(['page' => (bool) get_plugin_page_hook('anypress-move', 'tools.php'), 'can' => current_user_can('manage_options')]);`);
    expect(out.page === false && out.can === false, short(out));
    const res = await fetch(`${base}/wp-json/wp-abilities/v1/abilities/anypress/check-course/run`, { headers: { 'x-test-user': `${USER_TOKEN}:editor` } });
    expect(res.status === 403, `check-course as an editor: HTTP ${res.status}`);
});

await check('a switch never turns plugins on: with FluentCommunity off, Check asks for it and it stays off', async () => {
    await php(`deactivate_plugins('fluent-community/fluent-community.php'); echo json_encode([]);`);
    const pressed = await press('course', 'check');
    const text = JSON.stringify(pressed.result?.result ?? pressed);
    const on = await php(`echo json_encode(['on' => is_plugin_active('fluent-community/fluent-community.php')]);`);
    await php(`activate_plugin('fluent-community/fluent-community.php'); echo json_encode([]);`);
    expect(on.on === false, `FluentCommunity was turned on: ${short(pressed)}`);
    expect(text.includes("FluentCommunity isn't active") || text.includes('FluentCommunity isn\\u0027t active'), short(pressed));
});

await check('with AnyPress Client active, AnyPress Move does nothing', async () => {
    await php(`activate_plugin('anypress-client/anypress-client.php'); echo json_encode(['ok' => is_plugin_active('anypress-client/anypress-client.php')]);`);
    const state = await php(`require_once ABSPATH . 'wp-admin/includes/admin.php'; do_action('admin_menu');
        echo json_encode(['client' => defined('ANYPRESS_CLIENT_VERSION'), 'move' => class_exists('AnyPressMove\\\\Plugin', false), 'page' => (bool) get_plugin_page_hook('anypress-move', 'tools.php'), 'course' => (bool) wp_get_ability('anypress/check-course')]);`);
    expect(state.client && !state.move && !state.page && state.course, short(state));
    await php(`deactivate_plugins('anypress-client/anypress-client.php'); echo json_encode([]);`);
});

const log = await cli.playground.readFileAsText('/wordpress/wp-content/debug.log').catch(() => '');
await check('no PHP errors from AnyPress Move', async () => {
    const bad = log.split('\n').filter((line) => /PHP (Fatal|Warning|Parse|Notice|Deprecated)/.test(line) && /anypress-move/.test(line));
    expect(!bad.length, bad.slice(0, 5).join('\n'));
});

console.log(`\n${results.filter(([ok]) => ok).length}/${results.length} checks passed`);
if (SERVE) {
    console.log(`Leaving it running at ${base}/wp-admin/tools.php?page=anypress-move (admin / password). Ctrl+C to stop.`);
} else {
    await cli[Symbol.asyncDispose]?.();
    process.exit(failed ? 1 : 0);
}
