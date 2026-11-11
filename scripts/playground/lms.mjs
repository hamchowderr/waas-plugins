// End-to-end test of the course switch from Tutor LMS and LifterLMS (`wp anypress course switch --from=tutor|lifterlms`
// and the check-course / switch-course abilities): their courses into FluentCommunity courses, on WordPress Playground
// as a store of the community site type (the AnyPress theme, FluentCommunity at the store template's version, AnyPress
// Client from this checkout with ANYPRESS_TENANT_ROLES community). Tutor LMS and LifterLMS come from wordpress.org and
// make their own courses, enrolments and progress through their own functions; then both are switched off (they
// register the same post type, so they never run together), as a moved-in community store would have them. Checks:
// with both found, the switch asks which; the check and dry run count right and change nothing; the switch makes the
// courses, sections, lessons, students and progress, holds back email and leaves Tutor LMS's and LifterLMS's data
// alone; running again is refused; undo removes only what it added; the other source then switches cleanly.
//
//   cd scripts/playground && npm ci
//   npm run lms                           # exit 1 on a failure
//   npm run lms -- --serve                # leave the site running at http://127.0.0.1:9406 (admin / password)
//   TUTOR=4.2.1 LIFTERLMS=11.0.1 FLUENT_COMMUNITY=2.13.0 npm run lms   # other releases
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
const PORT = Number(process.env.PLAYGROUND_PORT || 9406);
const SERVE = process.argv.includes('--serve');
const SITE_TYPE = 'community';
// The version the store template installs (templates/anypress-store/build.sh).
const FLUENT_COMMUNITY = process.env.FLUENT_COMMUNITY || '2.13.0';
// Newest releases on wordpress.org unless set (checked 2026-11-11: Tutor LMS 4.2.1, LifterLMS 11.0.1).
const TUTOR = process.env.TUTOR || 'latest-stable';
const LIFTERLMS = process.env.LIFTERLMS || 'latest-stable';
const SITE_KEY = randomBytes(24).toString('hex');
const USER_TOKEN = randomBytes(24).toString('hex');
const REPORTS = process.env.LMS_REPORT_DIR;
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
        // Installed, not active: each is switched on in turn to make its courses.
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/tutor.${TUTOR}.zip` }, options: { activate: false } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/lifterlms.${LIFTERLMS}.zip` }, options: { activate: false } },
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(resolve(API, 'templates/anypress-store/roles.json'), 'utf8') },
        // A store sold as a community site: its site key and site type come from the container's environment.
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${SITE_KEY}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}');\n` },
        {
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/lms-test.php',
            data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user; an editor exists to check permissions.
// Every email WordPress actually tries to send (sent or failed) is counted in the lms_test_mail option.
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
$count = function () { update_option('lms_test_mail', (int) get_option('lms_test_mail', 0) + 1, false); };
add_action('wp_mail_succeeded', $count);
add_action('wp_mail_failed', $count);
// Stands in for a plugin that emails each new student, to show the switch holds such email back.
add_action('fluent_community/course/enrolled', function ($course, $user_id) { wp_mail(get_userdata($user_id)->user_email, 'Welcome to ' . $course->title, 'Hello'); }, 10, 2);
`,
        },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'Playground Art School', permalink_structure: '/%postname%/' } },
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
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}, site type ${SITE_TYPE}, FluentCommunity ${FLUENT_COMMUNITY}, Tutor LMS ${TUTOR}, LifterLMS ${LIFTERLMS}`);

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

const TARGET = ['fcom_spaces', 'fcom_posts', 'fcom_space_user', 'fcom_post_reactions', 'fcom_user_activities', 'fcom_xprofile'];
/** A fingerprint of FluentCommunity's tables, of Tutor LMS's and LifterLMS's data, and the mail counter. */
async function snapshot() {
    return php(`global $wpdb; $p = $wpdb->prefix; $out = [];
    foreach (${JSON.stringify(TARGET)} as $t) {
        $rows = $wpdb->get_results("SELECT * FROM {$p}{$t}", ARRAY_A);
        $out[$t] = ['rows' => count($rows), 'hash' => md5(json_encode($rows))];
    }
    $types = "('courses', 'topics', 'lesson', 'tutor_quiz', 'tutor_enrolled', 'course', 'section', 'llms_quiz', 'llms_access_plan')";
    $source = [
        $wpdb->get_results("SELECT ID, post_type, post_status, post_title, post_content, post_parent, menu_order, post_author FROM {$p}posts WHERE post_type IN $types ORDER BY ID", ARRAY_A),
        $wpdb->get_results("SELECT m.post_id, m.meta_key, m.meta_value FROM {$p}postmeta m JOIN {$p}posts p ON p.ID = m.post_id WHERE p.post_type IN $types ORDER BY m.meta_id", ARRAY_A),
        $wpdb->get_results("SELECT user_id, meta_key, meta_value FROM {$p}usermeta WHERE meta_key LIKE '\\\\_tutor%' ORDER BY umeta_id", ARRAY_A),
        $wpdb->get_results("SELECT comment_post_ID, user_id, comment_type FROM {$p}comments WHERE comment_type = 'course_completed' ORDER BY comment_ID", ARRAY_A),
        $wpdb->get_results("SELECT * FROM {$p}lifterlms_user_postmeta ORDER BY meta_id", ARRAY_A),
    ];
    echo json_encode(['tables' => $out, 'source' => md5(json_encode($source)), 'mail' => (int) get_option('lms_test_mail', 0)]);`);
}
const rows = (snap) => Object.fromEntries(Object.entries(snap.tables).map(([t, v]) => [t, v.rows]));
const users = `$user = fn ($login) => ($u = get_user_by('login', $login)) ? $u->ID : wp_insert_user(['user_login' => $login, 'user_email' => "$login@example.com", 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$ana = $user('ana'); $ben = $user('ben'); $cal = $user('cal'); $dee = $user('dee'); $eve = $user('eve');
$para = fn ($text) => "<!-- wp:paragraph -->\\n<p>$text</p>\\n<!-- /wp:paragraph -->";`;

// ---- Tutor LMS makes its courses, enrolments and progress with its own functions.
await php(`activate_plugin('tutor/tutor.php'); echo json_encode(['ok' => is_plugin_active('tutor/tutor.php')]);`);
const tutor = await php(String.raw`${users}
$mk = fn ($type, $title, $parent = 0, $content = '', $status = 'publish', $order = 0) => wp_insert_post(['post_type' => $type, 'post_title' => $title, 'post_parent' => $parent, 'post_content' => $content, 'post_status' => $status, 'menu_order' => $order, 'post_author' => 1], true);
// A paid course: two topics with lessons (one with a YouTube video), a quiz, a topic holding only a quiz, a lesson in the bin.
$a = $mk('courses', 'Sourdough Basics', 0, $para('Everything about a sourdough starter and a first loaf.'));
update_post_meta($a, '_tutor_course_price_type', 'paid');
$t1 = $mk('topics', 'Your starter', $a, '', 'publish', 1);
$feed = $mk('lesson', 'Feeding the starter', $t1, $para('Feed it twice a day.'), 'publish', 1);
update_post_meta($feed, '_video', ['source' => 'youtube', 'source_youtube' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
$ready = $mk('lesson', 'When it is ready', $t1, $para('It doubles in four hours.'), 'publish', 2);
$mk('tutor_quiz', 'Starter quiz', $t1, '', 'publish', 3);
$mk('lesson', 'Old lesson', $t1, $para('Gone.'), 'trash', 4);
$t2 = $mk('topics', 'Your first loaf', $a, '', 'publish', 2);
$mix = $mk('lesson', 'Mix and bake', $t2, $para('Mix, rest, fold, shape, bake.'), 'publish', 1);
$t3 = $mk('topics', 'Final test', $a, '', 'publish', 3);
$mk('tutor_quiz', 'Final quiz', $t3, '', 'publish', 1);
// A free course, and a draft with nothing in it.
$b = $mk('courses', 'Bread Club', 0, '', 'publish', 1);
wp_update_post(['ID' => $b, 'post_excerpt' => 'A free weekly bake-along.']);
update_post_meta($b, '_tutor_course_price_type', 'free');
$bt = $mk('topics', 'Week one', $b, '', 'publish', 1);
$flour = $mk('lesson', 'Flour', $bt, $para('Strong white flour.'), 'publish', 1);
$c = $mk('courses', 'Pastry', 0, '', 'draft', 2);
// Ana and Ben paid (their enrolments made active, as a paid order does), Cal's was cancelled; Dee took the free course.
$enrol = function ($course, $user, $status) {
    $id = \Tutor\Models\EnrollmentModel::do_enroll($course, 0, $user);
    wp_update_post(['ID' => $id, 'post_status' => $status]);
    return $id;
};
$enrolments = [$enrol($a, $ana, 'completed'), $enrol($a, $ben, 'completed'), $enrol($a, $cal, 'cancel'), $enrol($b, $dee, 'completed')];
foreach ([[$feed, $ana], [$mix, $ana], [$feed, $ben], [$ready, $ben], [$mix, $ben], [$feed, $cal], [$flour, $dee]] as [$lesson, $who]) {
    \Tutor\Models\LessonModel::mark_lesson_complete($lesson, $who);
}
\Tutor\Models\CourseModel::mark_course_as_completed($a, $ben);
echo json_encode(['seed' => compact('a', 'b', 'c', 'enrolments'), 'version' => TUTOR_VERSION, 'statuses' => array_map('get_post_status', $enrolments)]);
`);
await php(`deactivate_plugins('tutor/tutor.php'); activate_plugin('lifterlms/lifterlms.php'); echo json_encode(['ok' => is_plugin_active('lifterlms/lifterlms.php')]);`);

// ---- LifterLMS makes its courses, enrolments and progress with its own functions.
const lifter = await php(String.raw`${users}
$course = function ($title, $status = 'publish') { $c = new LLMS_Course('new', $title); wp_update_post(['ID' => $c->get('id'), 'post_status' => $status]); return $c->get('id'); };
$section = function ($course, $title, $order) { $s = new LLMS_Section('new', $title); $s->set('parent_course', $course); $s->set('order', $order); return $s->get('id'); };
$lesson = function ($course, $section, $title, $text, $order, $video = '') use ($para) {
    $l = new LLMS_Lesson('new', ['post_title' => $title, 'post_content' => $para($text)]);
    $l->set('parent_course', $course); $l->set('parent_section', $section); $l->set('order', $order);
    if ($video) { $l->set('video_embed', $video); }
    return $l->get('id');
};
$plan = function ($course, $title, $free) { $p = new LLMS_Access_Plan('new', $title); $p->set('product_id', $course); $p->set('is_free', $free ? 'yes' : 'no'); $p->set('price', $free ? 0 : 49); return $p->get('id'); };
// A paid course with two sections, given in reverse order to check LifterLMS's own order wins.
$a = $course('Watercolour Basics');
$plan($a, 'One payment', false);
$w2 = $section($a, 'Week two', 2);
$w1 = $section($a, 'Week one', 1);
$l2 = $lesson($a, $w1, 'Mixing colours', 'Two primaries at a time.', 2);
$l1 = $lesson($a, $w1, 'Your brushes', 'Round and flat.', 1, 'https://vimeo.com/76979871');
$l3 = $lesson($a, $w2, 'Washes', 'Wet on wet.', 1);
// A free course and an empty draft.
$b = $course('Sketch Club');
$plan($b, 'Free', true);
$s = $section($b, 'Start here', 1);
$l4 = $lesson($b, $s, 'Lines', 'Loose lines first.', 1);
$c = $course('Oils', 'draft');
foreach ([[$l3, 'Wash quiz'], [$l4, 'Line quiz']] as [$on, $title]) { $q = new LLMS_Quiz('new', $title); $q->set('lesson_id', $on); }
// Ana and Ben enrolled; Cal enrolled, finished a lesson, then left; Dee took the free course and finished it.
foreach ([[$ana, $a], [$ben, $a], [$cal, $a], [$dee, $b]] as [$who, $what]) { llms_enroll_student($who, $what); }
foreach ([[$ana, $l1], [$ben, $l1], [$ben, $l2], [$ben, $l3], [$cal, $l1], [$dee, $l4]] as [$who, $what]) { llms_mark_complete($who, $what, 'lesson'); }
llms_unenroll_student($cal, $a);
$s1 = new LLMS_Student($ben);
$s2 = new LLMS_Student($dee);
echo json_encode(['seed' => compact('a', 'b', 'c'), 'version' => LLMS_VERSION, 'ben_done' => $s1->is_complete($a, 'course'), 'dee_done' => $s2->is_complete($b, 'course'), 'cal' => (new LLMS_Student($cal))->get_enrollment_status($a)]);
`);
// Both off, as a moved-in community store has them; the existing FluentCommunity course is made last.
const seed = await php(String.raw`${users}
deactivate_plugins('lifterlms/lifterlms.php');
$req = new WP_REST_Request('POST', '/fluent-community/v2/admin/courses');
$req->set_body_params(['title' => 'Existing course', 'description' => 'Made before the switch.', 'privacy' => 'public', 'course_type' => 'self_paced', 'status' => 'published']);
$made = rest_get_server()->response_to_data(rest_do_request($req), false);
$existing = (int) ($made['course']['id'] ?? (is_object($made['course'] ?? null) ? $made['course']->id : 0));
$enrol = new WP_REST_Request('POST', "/fluent-community/v2/admin/courses/{$existing}/students");
$enrol->set_body_params(['user_id' => $eve]);
rest_do_request($enrol);
delete_option('lms_test_mail');
echo json_encode(['existing' => $existing, 'active' => array_values(array_filter(['tutor/tutor.php', 'lifterlms/lifterlms.php'], 'is_plugin_active')), 'roles' => get_option('anypress_tenant_roles')]);
`);
keep('seed.json', { tutor, lifter, seed });

await check('seed: Tutor LMS and LifterLMS made their courses, then were switched off, on a community store', async () => {
    expect(tutor.seed?.a > 0 && same(tutor.statuses, ['completed', 'completed', 'cancel', 'completed']), short(tutor));
    expect(lifter.seed?.a > 0 && lifter.ben_done && lifter.dee_done && lifter.cal !== 'enrolled', short(lifter));
    expect(seed.existing > 0 && same(seed.active, []), short(seed));
});

const start = await snapshot();
const EXPECT = {
    tutor: { courses: 3, sections: 3, lessons: 4, students: 3, completed_lessons: 6, completed_courses: 1 },
    lifterlms: { courses: 3, sections: 3, lessons: 4, students: 3, completed_lessons: 5, completed_courses: 2 },
};

await check('with both found, the check asks which one, and LearnDash isn\'t found', async () => {
    const dry = await wp('wp anypress course switch --dry-run --format=json --user=admin');
    const p = dry.preflight;
    expect(!p.ready && p.blockers.some((b) => b.includes('More than one source')), short(p));
    expect(p.sources.tutor.found && p.sources.lifterlms.found && !p.sources.learndash.found, short(p.sources));
    expect(p.sources.tutor.name.includes('switched off') && p.sources.lifterlms.name.includes('switched off'), short(p.sources));
});

async function detail() {
    return php(`global $wpdb; $p = $wpdb->prefix;
        $courses = $wpdb->get_results("SELECT id, title, privacy, status FROM {$p}fcom_spaces WHERE type = 'course' ORDER BY id", ARRAY_A);
        $out = [];
        foreach ($courses as $c) {
            $sections = [];
            foreach ($wpdb->get_results($wpdb->prepare("SELECT id, title FROM {$p}fcom_posts WHERE space_id = %d AND type = 'course_section' ORDER BY priority, id", $c['id'])) as $s) {
                $sections[$s->title] = $wpdb->get_col($wpdb->prepare("SELECT CONCAT(title, ' ', status) FROM {$p}fcom_posts WHERE parent_id = %d AND type = 'course_lesson' ORDER BY priority, id", $s->id));
            }
            $students = $wpdb->get_col($wpdb->prepare("SELECT u.user_login FROM {$p}fcom_space_user m JOIN {$p}users u ON u.ID = m.user_id WHERE m.space_id = %d AND m.role = 'student' AND m.status = 'active' ORDER BY u.user_login", $c['id']));
            $done = $wpdb->get_col($wpdb->prepare("SELECT CONCAT(u.user_login, ': ', l.title) FROM {$p}fcom_post_reactions r JOIN {$p}users u ON u.ID = r.user_id JOIN {$p}fcom_posts l ON l.id = r.object_id WHERE r.parent_id = %d AND r.object_type = 'lesson_completed' AND r.type = 'completed' ORDER BY u.user_login, l.title", $c['id']));
            $finished = $wpdb->get_col($wpdb->prepare("SELECT u.user_login FROM {$p}fcom_user_activities a JOIN {$p}users u ON u.ID = a.user_id WHERE a.feed_id = %d AND a.action_name = 'course_completed' ORDER BY u.user_login", $c['id']));
            $out[$c['title']] = $c + compact('sections', 'students', 'done', 'finished');
        }
        $video = fn ($title) => (string) $wpdb->get_var($wpdb->prepare("SELECT message FROM {$p}fcom_posts WHERE type = 'course_lesson' AND title = %s", $title));
        echo json_encode(['courses' => $out, 'feeding' => $video('Feeding the starter'), 'brushes' => $video('Your brushes')]);`);
}

async function switchFrom(from, expected, verify) {
    await check(`${from}: the check counts what will come across and changes nothing`, async () => {
        const dry = await wp(`wp anypress course switch --from=${from} --dry-run --format=json --user=admin`);
        keep(`${from}-dry-run.json`, dry);
        const p = dry.preflight;
        expect(p.ready && p.source === from && !p.blockers.length, short(p));
        expect(p.sources[from].counts.quizzes === 2, short(p.sources[from].counts));
        const counts = Object.fromEntries(dry.compare.map((r) => [r.item, r.source]));
        expect(same(counts, expected), `counts ${short(counts)}`);
        expect(dry.lost.length >= 3 && dry.lost.some((l) => l.includes('Quizzes')), short(dry.lost));
        const viaAbility = await ability('anypress/check-course', { from }, { method: 'GET' });
        expect(viaAbility.status === 200 && viaAbility.data.preflight.ready, `check-course: HTTP ${viaAbility.status} ${short(viaAbility.data)}`);
        expect(same((await snapshot()).tables, start.tables), 'the dry run changed FluentCommunity');
    });

    let added;
    await check(`${from}: the switch brings every course across, holds back email and leaves the source's data alone`, async () => {
        const run = await wp(`wp anypress course switch --from=${from} --format=json --user=admin`);
        keep(`${from}-switch.json`, run);
        expect(!run.dry_run && run.compare.every((r) => r.missing === 0 && r.added === r.source), short(run.compare));
        const after = await snapshot();
        added = rows(after);
        expect(after.mail === start.mail, `email sent: ${start.mail} -> ${after.mail} (held back ${run.emails_blocked})`);
        expect(run.emails_blocked === 3, `held back ${run.emails_blocked}`);
        expect(after.source === start.source, 'the source\'s data changed');
    });

    await check(`${from}: the courses, outline, students and progress are right`, async () => {
        const d = await detail();
        keep(`${from}-detail.json`, d);
        verify(d);
        expect(same(d.courses['Existing course'].students, ['eve']), short(d.courses['Existing course']));
    });

    await check(`${from}: running again is refused; undo removes only what it added`, async () => {
        const again = await ability('anypress/switch-course', { from });
        expect(again.status === 409, `switch-course: HTTP ${again.status} ${short(again.data)}`);
        expect(same(rows(await snapshot()), added), 'FluentCommunity changed');
        const undo = await ability('anypress/switch-course', { undo: true });
        expect(undo.status === 200 && undo.data.removed.courses === 3, `undo: HTTP ${undo.status} ${short(undo.data)}`);
        const undone = await snapshot();
        expect(same(undone.tables, start.tables), `left behind: ${short(rows(undone))} (before ${short(rows(start))})`);
        expect(undone.source === start.source, 'the source\'s data changed');
    });
}

await switchFrom('tutor', EXPECT.tutor, (d) => {
    const c = d.courses;
    expect(same(Object.keys(c), ['Existing course', 'Sourdough Basics', 'Bread Club', 'Pastry']), short(Object.keys(c)));
    const sd = c['Sourdough Basics'];
    // Topics as sections, in order; the quiz-only topic and the binned lesson stay out.
    expect(sd.privacy === 'private' && sd.status === 'published', short(sd));
    expect(same(sd.sections, { 'Your starter': ['Feeding the starter published', 'When it is ready published'], 'Your first loaf': ['Mix and bake published'] }), short(sd.sections));
    expect(same(sd.students, ['ana', 'ben']), `Cal's enrolment was cancelled: ${short(sd.students)}`);
    expect(same(sd.done, ['ana: Feeding the starter', 'ana: Mix and bake', 'ben: Feeding the starter', 'ben: Mix and bake', 'ben: When it is ready']), short(sd.done));
    expect(same(sd.finished, ['ben']), short(sd.finished));
    const bc = c['Bread Club'];
    expect(bc.privacy === 'public' && same(bc.sections, { 'Week one': ['Flour published'] }) && same(bc.students, ['dee']) && same(bc.done, ['dee: Flour']), short(bc));
    expect(c.Pastry.status === 'draft' && same(c.Pastry.sections, []), short(c.Pastry));
    expect(d.feeding.includes('<!-- wp:embed') && d.feeding.includes('youtube.com/watch?v=dQw4w9WgXcQ') && d.feeding.includes('twice a day'), short(d.feeding));
});

await switchFrom('lifterlms', EXPECT.lifterlms, (d) => {
    const c = d.courses;
    expect(same(Object.keys(c), ['Existing course', 'Watercolour Basics', 'Sketch Club', 'Oils']), short(Object.keys(c)));
    const wc = c['Watercolour Basics'];
    expect(wc.privacy === 'private' && wc.status === 'published', short(wc));
    expect(same(wc.sections, { 'Week one': ['Your brushes published', 'Mixing colours published'], 'Week two': ['Washes published'] }), `LifterLMS's own order: ${short(wc.sections)}`);
    expect(same(wc.students, ['ana', 'ben']), `Cal left the course: ${short(wc.students)}`);
    expect(same(wc.done, ['ana: Your brushes', 'ben: Mixing colours', 'ben: Washes', 'ben: Your brushes']), short(wc.done));
    expect(same(wc.finished, ['ben']), short(wc.finished));
    const sc = c['Sketch Club'];
    expect(sc.privacy === 'public' && same(sc.students, ['dee']) && same(sc.done, ['dee: Lines']) && same(sc.finished, ['dee']), short(sc));
    expect(c.Oils.status === 'draft' && same(c.Oils.sections, []), short(c.Oils));
    expect(d.brushes.includes('<!-- wp:embed') && d.brushes.includes('vimeo.com/76979871'), short(d.brushes));
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
