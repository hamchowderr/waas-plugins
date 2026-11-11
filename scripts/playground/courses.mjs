// End-to-end test of the course switch (`wp anypress course switch` and the check-course / switch-course abilities):
// LearnDash courses into FluentCommunity courses, on WordPress Playground as a store of the community site type (the
// AnyPress theme, FluentCommunity at the store template's version, AnyPress Client from this checkout with
// ANYPRESS_TENANT_ROLES community). LearnDash itself isn't installed: a community store would turn it off anyway, and
// the switch reads what LearnDash leaves in WordPress (its course, lesson, topic and quiz posts, the course builder's
// steps and section headings, enrolments, group access and progress in user meta). The seed writes those as
// LearnDash 4 stores them. Checks: the check and dry run count right and change nothing; only an administrator can
// run it; the switch makes the courses, sections, lessons, students and progress, holds back email and leaves the
// LearnDash data and an existing FluentCommunity course alone; running again is refused; undo removes only what it
// added; the ability redoes it; turned-off courses are refused.
//
//   cd scripts/playground && npm ci
//   npm run courses                       # exit 1 on a failure
//   npm run courses -- --serve            # leave the site running at http://127.0.0.1:9405 (admin / password)
//   FLUENT_COMMUNITY=2.13.0 npm run courses   # another FluentCommunity release
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
const WP = process.env.PLAYGROUND_WP || '7.1.4';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9405);
const SERVE = process.argv.includes('--serve');
const SITE_TYPE = 'community';
// The version the store template installs (templates/anypress-store/build.sh).
const FLUENT_COMMUNITY = process.env.FLUENT_COMMUNITY || '2.13.0';
const SITE_KEY = randomBytes(24).toString('hex');
const USER_TOKEN = randomBytes(24).toString('hex');
const REPORTS = process.env.COURSES_REPORT_DIR;
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
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(resolve(API, 'templates/anypress-store/roles.json'), 'utf8') },
        // A store sold as a community site: its site key and site type come from the container's environment.
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${SITE_KEY}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}');\n` },
        {
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/course-test.php',
            data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user; an editor exists to check permissions.
// Every email WordPress actually tries to send (sent or failed) is counted in the course_test_mail option.
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
$count = function () { update_option('course_test_mail', (int) get_option('course_test_mail', 0) + 1, false); };
add_action('wp_mail_succeeded', $count);
add_action('wp_mail_failed', $count);
// Stands in for a plugin that emails each new student, to show the switch holds such email back.
add_action('fluent_community/course/enrolled', function ($course, $user_id) { wp_mail(get_userdata($user_id)->user_email, 'Welcome to ' . $course->title, 'Hello'); }, 10, 2);
`,
        },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'Playground Bakery School', permalink_structure: '/%postname%/' } },
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
    const res = await fetch(url, { method, headers, body: method === 'GET' ? undefined : JSON.stringify(input === undefined ? {} : { input }) });
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

const TARGET = ['fcom_spaces', 'fcom_posts', 'fcom_space_user', 'fcom_post_reactions', 'fcom_user_activities', 'fcom_xprofile'];
/** A fingerprint of FluentCommunity's tables, of LearnDash's data, and the mail counter. */
async function snapshot() {
    return php(`global $wpdb; $p = $wpdb->prefix; $out = [];
    foreach (${JSON.stringify(TARGET)} as $t) {
        $rows = $wpdb->get_results("SELECT * FROM {$p}{$t}", ARRAY_A);
        $out[$t] = ['rows' => count($rows), 'hash' => md5(json_encode($rows))];
    }
    $ld = [
        $wpdb->get_results("SELECT ID, post_type, post_status, post_title, post_content, menu_order FROM {$p}posts WHERE post_type LIKE 'sfwd-%' OR post_type = 'groups' ORDER BY ID", ARRAY_A),
        $wpdb->get_results("SELECT m.post_id, m.meta_key, m.meta_value FROM {$p}postmeta m JOIN {$p}posts p ON p.ID = m.post_id WHERE p.post_type LIKE 'sfwd-%' OR p.post_type = 'groups' ORDER BY m.meta_id", ARRAY_A),
        $wpdb->get_results("SELECT user_id, meta_key, meta_value FROM {$p}usermeta WHERE meta_key LIKE '%sfwd%' OR meta_key LIKE 'course\\\\_%' OR meta_key LIKE 'learndash%' ORDER BY umeta_id", ARRAY_A),
    ];
    echo json_encode(['tables' => $out, 'learndash' => md5(json_encode($ld)), 'mail' => (int) get_option('course_test_mail', 0)]);`);
}
const rows = (snap) => Object.fromEntries(Object.entries(snap.tables).map(([t, v]) => [t, v.rows]));

// ---- Seed: LearnDash data as LearnDash 4 stores it, and one course already in FluentCommunity.
const seed = await php(String.raw`
$user = fn ($login) => ($u = get_user_by('login', $login)) ? $u->ID : wp_insert_user(['user_login' => $login, 'user_email' => "$login@example.com", 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$ana = $user('ana'); $ben = $user('ben'); $cal = $user('cal'); $dee = $user('dee'); $eve = $user('eve');
$mk = function ($type, $title, $content = '', $status = 'publish', $order = 0) {
    return wp_insert_post(['post_type' => $type, 'post_title' => $title, 'post_content' => $content, 'post_status' => $status, 'menu_order' => $order], true);
};
$para = fn ($text) => "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";

// Course A, sold (paynow), built with the course builder: lessons holding topics, and a quiz.
$a = $mk('sfwd-courses', 'Sourdough Basics', $para('Everything about a sourdough starter and a first loaf.'));
update_post_meta($a, '_ld_price_type', 'paynow');
update_post_meta($a, '_sfwd-courses', ['sfwd-courses_course_price_type' => 'paynow', 'sfwd-courses_course_price' => '49']);
$l1 = $mk('sfwd-lessons', 'Your starter', $para('A starter is flour, water and time.'));
$t1 = $mk('sfwd-topic', 'Feeding the starter', $para('Feed it twice a day.'));
$t2 = $mk('sfwd-topic', 'When it is ready', $para('It doubles in four hours.'));
$l2 = $mk('sfwd-lessons', 'Your first loaf', $para('Mix, rest, fold, shape, bake.'));
$l3 = $mk('sfwd-lessons', 'Shaping', '');
$t3 = $mk('sfwd-topic', 'The boule', $para('Round and tight.'));
$q1 = $mk('sfwd-quiz', 'Starter quiz');
foreach ([$l1, $l2, $l3, $t1, $t2, $t3, $q1] as $step) { update_post_meta($step, 'course_id', $a); }
foreach ([$t1, $t2] as $step) { update_post_meta($step, 'lesson_id', $l1); }
update_post_meta($t3, 'lesson_id', $l3);
update_post_meta($t1, '_sfwd-topic', ['sfwd-topic_course' => $a, 'sfwd-topic_lesson' => $l1, 'sfwd-topic_lesson_video_enabled' => 'on', 'sfwd-topic_lesson_video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
update_post_meta($a, 'ld_course_steps', ['steps' => ['h' => [
    'sfwd-lessons' => [
        $l1 => ['sfwd-topic' => [$t1 => ['sfwd-quiz' => []], $t2 => ['sfwd-quiz' => []]], 'sfwd-quiz' => [$q1 => []]],
        $l2 => ['sfwd-topic' => [], 'sfwd-quiz' => []],
        $l3 => ['sfwd-topic' => [$t3 => ['sfwd-quiz' => []]], 'sfwd-quiz' => []],
    ],
    'sfwd-quiz' => [],
]], 'course_id' => $a, 'version' => '4.20.0', 'empty' => false, 'course_builder_enabled' => true]);
// Ana bought it; Ben has it through a LearnDash group; Cal started it once but has no access now.
update_user_meta($ana, 'course_' . $a . '_access_from', time() - 86400);
$g = $mk('groups', 'Bakery staff');
update_post_meta($a, 'learndash_group_enrolled_' . $g, time());
update_user_meta($ben, 'learndash_group_users_' . $g, $g);
update_user_meta($ana, '_sfwd-course_progress', [$a => ['lessons' => [$l1 => 1, $l2 => 1], 'topics' => [$l1 => [$t1 => 1, $t2 => 0]], 'completed' => 3, 'total' => 6]]);
update_user_meta($ben, '_sfwd-course_progress', [$a => ['lessons' => [], 'topics' => [$l3 => [$t3 => 1]], 'completed' => 1, 'total' => 6]]);
update_user_meta($ben, 'course_completed_' . $a, time());
update_user_meta($cal, '_sfwd-course_progress', [$a => ['lessons' => [$l1 => 1], 'topics' => [], 'completed' => 1, 'total' => 6]]);

// Course B, open to anyone, without the course builder (lessons point at the course), with section headings.
$b = $mk('sfwd-courses', 'Bread Club', '', 'publish', 1);
wp_update_post(['ID' => $b, 'post_excerpt' => 'A free weekly bake-along.']);
update_post_meta($b, '_ld_price_type', 'open');
$b1 = $mk('sfwd-lessons', 'Flour', $para('Strong white flour.'), 'publish', 1);
$b2 = $mk('sfwd-lessons', 'Water', $para('Warm, not hot.'), 'publish', 2);
$bt = $mk('sfwd-topic', 'Hard and soft water', $para('Either works.'), 'publish', 1);
$b3 = $mk('sfwd-lessons', 'Bake day', $para('Preheat for an hour.'), 'publish', 3);
foreach ([$b1, $b2, $b3, $bt] as $step) { update_post_meta($step, 'course_id', $b); }
update_post_meta($bt, 'lesson_id', $b2);
update_post_meta($b, 'course_sections', wp_json_encode([
    ['order' => 0, 'ID' => 900001, 'post_title' => 'Week one', 'type' => 'section-heading'],
    ['order' => 3, 'ID' => 900002, 'post_title' => 'Week two', 'type' => 'section-heading'],
]));
update_user_meta($dee, '_sfwd-course_progress', [$b => ['lessons' => [$b1 => 1], 'topics' => [], 'completed' => 1, 'total' => 4]]);

// Course C: a draft with no lessons yet. And a lesson in the bin, which must not come across.
$c = $mk('sfwd-courses', 'Pastry', '', 'draft', 2);
$gone = $mk('sfwd-lessons', 'Old lesson', $para('Gone.'), 'trash', 4);
update_post_meta($gone, 'course_id', $b);

// A course already in FluentCommunity, with a student, made through FluentCommunity's own routes.
$req = new WP_REST_Request('POST', '/fluent-community/v2/admin/courses');
$req->set_body_params(['title' => 'Existing course', 'description' => 'Made before the switch.', 'privacy' => 'public', 'course_type' => 'self_paced', 'status' => 'published']);
$made = rest_get_server()->response_to_data(rest_do_request($req), false);
$existing = (int) ($made['course']['id'] ?? (is_object($made['course'] ?? null) ? $made['course']->id : 0));
$enrol = new WP_REST_Request('POST', "/fluent-community/v2/admin/courses/{$existing}/students");
$enrol->set_body_params(['user_id' => $eve]);
rest_do_request($enrol);
delete_option('course_test_mail');
echo json_encode(['seed' => compact('a', 'b', 'c', 'existing'), 'learndash_active' => defined('LEARNDASH_VERSION'), 'roles' => get_option('anypress_tenant_roles'), 'fcom' => is_plugin_active('fluent-community/fluent-community.php')]);
`);

await check('seed: a community store with FluentCommunity on, LearnDash data and no LearnDash plugin', async () => {
    expect(seed.seed && seed.seed.existing > 0, short(seed));
    expect(seed.fcom === true && seed.learndash_active === false, short(seed));
});

const start = await snapshot();
const COUNTS = { courses: 3, sections: 5, lessons: 9, students: 3, completed_lessons: 5, completed_courses: 1 };

await check('the check finds LearnDash, counts what will come across, and changes nothing', async () => {
    const dry = await wp('wp anypress course switch --dry-run --format=json --user=admin');
    keep('course-dry-run.json', dry);
    const p = dry.preflight;
    expect(p.ready && p.target === 'FluentCommunity' && p.source === 'learndash' && !p.blockers.length, short(p));
    expect(p.sources.learndash.counts.quizzes === 1, short(p.sources.learndash.counts));
    expect(p.warnings.some((w) => w.includes('automation')), short(p.warnings));
    const counts = Object.fromEntries(dry.compare.map((r) => [r.item, r.source]));
    expect(same(counts, COUNTS), `counts ${short(counts)}`);
    expect(dry.lost.length >= 4 && dry.lost.some((l) => l.includes('Quizzes')), short(dry.lost));
    const viaAbility = await ability('anypress/check-course', undefined, { method: 'GET' });
    expect(viaAbility.status === 200 && viaAbility.data.preflight.ready, `check-course: HTTP ${viaAbility.status} ${short(viaAbility.data)}`);
    expect(same((await snapshot()).tables, start.tables), 'the dry run changed FluentCommunity');
});

await check('only an administrator can check or switch', async () => {
    const editor = await ability('anypress/check-course', undefined, { as: 'editor', method: 'GET' });
    expect(editor.status === 403, `editor: HTTP ${editor.status}`);
    const nobody = await ability('anypress/switch-course', {}, { as: null });
    expect(nobody.status === 401, `signed out: HTTP ${nobody.status}`);
    const cliEditor = await wp('wp anypress course switch --dry-run --user=editor');
    expect(cliEditor.includes('Run this as an administrator'), cliEditor.slice(-300));
});

let added;
await check('the switch brings every course across, holds back email and leaves LearnDash\'s data alone', async () => {
    const run = await wp('wp anypress course switch --format=json --user=admin');
    keep('course-switch.json', run);
    expect(!run.dry_run && run.compare.every((r) => r.missing === 0 && r.added === r.source), short(run.compare));
    expect(run.notes[0].includes('Everything the source holds'), short(run.notes));
    const after = await snapshot();
    added = rows(after);
    expect(after.mail === start.mail, `email sent: ${start.mail} -> ${after.mail} (held back ${run.emails_blocked})`);
    // The stand-in welcome email for each of the three students was held back.
    expect(run.emails_blocked === 3, `held back ${run.emails_blocked}`);
    expect(after.learndash === start.learndash, 'LearnDash\'s data changed');
});

await check('the courses, outline, students and progress are right', async () => {
    const d = await php(`global $wpdb; $p = $wpdb->prefix;
        $courses = $wpdb->get_results("SELECT id, title, privacy, status FROM {$p}fcom_spaces WHERE type = 'course' ORDER BY id", ARRAY_A);
        $out = [];
        foreach ($courses as $c) {
            $sections = [];
            foreach ($wpdb->get_results($wpdb->prepare("SELECT id, title FROM {$p}fcom_posts WHERE space_id = %d AND type = 'course_section' ORDER BY priority, id", $c['id'])) as $s) {
                $sections[$s->title] = $wpdb->get_col($wpdb->prepare("SELECT CONCAT(title, ' ', status) FROM {$p}fcom_posts WHERE parent_id = %d AND type = 'course_lesson' ORDER BY priority, id", $s->id));
            }
            $students = $wpdb->get_col($wpdb->prepare("SELECT u.user_login FROM {$p}fcom_space_user m JOIN {$p}users u ON u.ID = m.user_id WHERE m.space_id = %d AND m.role = 'student' AND m.status = 'active' ORDER BY u.user_login", $c['id']));
            $done = $wpdb->get_col($wpdb->prepare("SELECT CONCAT(u.user_login, ': ', l.title) FROM {$p}fcom_post_reactions r JOIN {$p}users u ON u.ID = r.user_id JOIN {$p}fcom_posts l ON l.id = r.object_id WHERE r.parent_id = %d AND r.object_type = 'lesson_completed' AND r.type = 'completed' ORDER BY u.user_login, l.title", $c['id']));
            $finished = $wpdb->get_col($wpdb->prepare("SELECT u.user_login FROM {$p}fcom_user_activities a JOIN {$p}users u ON u.ID = a.user_id WHERE a.feed_id = %d AND a.action_name = 'course_completed'", $c['id']));
            $out[$c['title']] = $c + compact('sections', 'students', 'done', 'finished');
        }
        $feeding = $wpdb->get_var("SELECT message FROM {$p}fcom_posts WHERE type = 'course_lesson' AND title = 'Feeding the starter'");
        $ana = get_user_by('login', 'ana')->ID;
        $a = (int) $out['Sourdough Basics']['id'];
        echo json_encode(['courses' => $out, 'feeding' => $feeding,
            'ana_done' => count(\\FluentCommunity\\Modules\\Course\\Services\\CourseHelper::getCompletedLessonIds($a, $ana)),
            'ana_enrolled' => (bool) \\FluentCommunity\\Modules\\Course\\Services\\CourseHelper::isEnrolled($a, $ana)]);`);
    keep('course-detail.json', d);
    const c = d.courses;
    expect(same(Object.keys(c), ['Existing course', 'Sourdough Basics', 'Bread Club', 'Pastry']), short(Object.keys(c)));
    const sd = c['Sourdough Basics'];
    expect(sd.privacy === 'private' && sd.status === 'published', short(sd));
    // Lessons holding topics: each lesson a section, its own text first; Shaping has no text, so only its topic.
    expect(same(sd.sections, {
        'Your starter': ['Your starter published', 'Feeding the starter published', 'When it is ready published'],
        'Your first loaf': ['Your first loaf published'],
        Shaping: ['The boule published'],
    }), short(sd.sections));
    expect(same(sd.students, ['ana', 'ben']), `Cal has no access, so isn't a student: ${short(sd.students)}`);
    expect(same(sd.done, ['ana: Feeding the starter', 'ana: Your first loaf', 'ana: Your starter', 'ben: The boule']), short(sd.done));
    expect(same(sd.finished, ['ben']), short(sd.finished));
    const bc = c['Bread Club'];
    expect(bc.privacy === 'public' && same(bc.sections, { 'Week one': ['Flour published', 'Water published', 'Hard and soft water published'], 'Week two': ['Bake day published'] }), short(bc));
    expect(same(bc.students, ['dee']) && same(bc.done, ['dee: Flour']), `an open course: whoever started it ${short(bc)}`);
    expect(c.Pastry.status === 'draft' && same(c.Pastry.sections, []), short(c.Pastry));
    expect(same(c['Existing course'].students, ['eve']), short(c['Existing course']));
    expect(d.feeding.includes('<!-- wp:embed') && d.feeding.includes('youtube.com/watch?v=dQw4w9WgXcQ') && d.feeding.includes('twice a day'), short(d.feeding));
    expect(d.ana_enrolled && d.ana_done === 3, `FluentCommunity's own helpers: ${short(d)}`);
});

await check('running it again is refused and changes nothing', async () => {
    const again = await wp('wp anypress course switch --user=admin');
    expect(again.includes('already ran here'), again.slice(-400));
    const viaAbility = await ability('anypress/switch-course', {});
    expect(viaAbility.status === 409, `switch-course: HTTP ${viaAbility.status} ${short(viaAbility.data)}`);
    expect(same(rows(await snapshot()), added), 'FluentCommunity changed');
});

await check('undo removes only what the switch added; switching again through the ability redoes it', async () => {
    const preview = await wp('wp anypress course switch --undo --dry-run --format=json --user=admin');
    expect(preview.dry_run && preview.removed.courses === 3 && same(rows(await snapshot()), added), short(preview));
    const undo = await wp('wp anypress course switch --undo --format=json --user=admin');
    keep('course-undo.json', undo);
    expect(same(undo.removed, preview.removed), `undo ${short(undo.removed)} vs preview ${short(preview.removed)}`);
    const undone = await snapshot();
    expect(same(undone.tables, start.tables), `left behind: ${short(rows(undone))} (before ${short(rows(start))})`);
    expect(undone.learndash === start.learndash, 'LearnDash\'s data changed');
    const redo = await ability('anypress/switch-course', {});
    expect(redo.status === 200 && redo.data.compare.every((r) => r.missing === 0), `switch-course: HTTP ${redo.status} ${short(redo.data)}`);
    expect(same(rows(await snapshot()), added), 'the redo differs from the first switch');
});

await check('with courses turned off in FluentCommunity, the switch is refused', async () => {
    await wp('wp anypress course switch --undo --user=admin');
    await php(`$f = \\FluentCommunity\\App\\Functions\\Utility::getFeaturesConfig(); $f['course_module'] = 'no'; \\FluentCommunity\\App\\Functions\\Utility::updateOption('fluent_community_features', $f); echo json_encode(['ok' => true]);`);
    const dry = await wp('wp anypress course switch --dry-run --format=json --user=admin');
    expect(!dry.preflight.ready && dry.preflight.blockers.some((b) => b.includes('Courses are turned off')), short(dry.preflight));
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
