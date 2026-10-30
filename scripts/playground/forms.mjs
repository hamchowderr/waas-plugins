// End-to-end test of the forms switch (`wp waas forms switch` and the check-forms / switch-forms abilities) on
// WordPress Playground. Boots forms/blueprint.json (Contact Form 7 with Flamingo, WPForms Lite, Ninja Forms and Fluent
// Forms, at the versions the site-moving research used) with WaaS Client from this checkout, seeds each source plugin
// with a realistic form, entries and pages (forms/seed-*.php), then runs the dry run, the switch, the switch again,
// form submissions through Fluent Forms and the undo, and checks each step.
//
//   cd scripts/playground && npm ci
//   npm run forms             # run the checks, exit 1 on a failure
//   npm run forms -- --serve  # same site, left running at http://127.0.0.1:9401 (admin / password)
//
// Needs packages/waas-client/vendor (composer install in packages/waas-client).
// Playground stores the database in SQLite, not MariaDB: this checks the PHP and WordPress behaviour.

import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';
import { validateBlueprintDeclaration, wpCLI } from '@wp-playground/blueprints';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const CLIENT = resolve(ROOT, 'packages/waas-client');
const WP = process.env.PLAYGROUND_WP || '7.1.3';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9401);
const SERVE = process.argv.includes('--serve');
// Stands in for a signed-in user on the abilities checks: requests carrying it act as that user.
const USER_TOKEN = randomBytes(24).toString('hex');
// Optional: a folder to keep the command's reports in (the dry run's table, the switch and undo as JSON).
const REPORTS = process.env.FORMS_REPORT_DIR;
const keep = (name, text) => REPORTS && (mkdirSync(REPORTS, { recursive: true }), writeFileSync(resolve(REPORTS, name), typeof text === 'string' ? text : JSON.stringify(text, null, 2)));

if (!existsSync(resolve(CLIENT, 'vendor/autoload.php'))) {
    console.error('packages/waas-client/vendor is missing: run composer install in packages/waas-client first.');
    process.exit(1);
}

const blueprint = JSON.parse(readFileSync(resolve(HERE, 'forms/blueprint.json'), 'utf8'));
const validation = await validateBlueprintDeclaration(blueprint);
if (!validation.valid) {
    console.error('forms/blueprint.json is not a valid Blueprint:', JSON.stringify(validation.errors, null, 2));
    process.exit(1);
}
blueprint.login = SERVE;
// The release zip from wordpress.org, as the store template's build uses ("latest" resolves to a mirror that isn't
// reachable from every network).
const WP_ZIP = `https://wordpress.org/wordpress-${WP}.zip`;
blueprint.preferredVersions = { php: PHP, wp: WP_ZIP };
// PHP warnings and notices go to wp-content/debug.log, checked at the end.
blueprint.steps.unshift({ step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } });
blueprint.steps.push({
    step: 'writeFile',
    path: '/wordpress/wp-content/mu-plugins/forms-test-users.php',
    data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user; an editor exists to check permissions.
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
`,
});

const cli = await runCLI({
    command: 'server',
    wp: WP_ZIP,
    php: PHP,
    port: PORT,
    blueprint,
    mount: [{ hostPath: CLIENT, vfsPath: '/wordpress/wp-content/plugins/waas-client' }],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}`);

/** Runs PHP in the site (WordPress loaded, as the admin) and returns what it printed, parsed as JSON when it is. */
async function php(code) {
    const out = await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; wp_set_current_user(1); ${code}` });
    const text = out.text.trim();
    const start = text.search(/[[{]/);
    if (start < 0) return text;
    try {
        return JSON.parse(text.slice(start));
    } catch {
        return text;
    }
}
async function seed(file) {
    const out = await cli.playground.run({ code: readFileSync(resolve(HERE, 'forms', file), 'utf8') });
    const text = out.text.trim();
    return JSON.parse(text.slice(text.lastIndexOf('\n{') + 1));
}
/** Runs a WP-CLI command in the site and returns its output (parsed JSON with --format=json). */
async function wp(command) {
    let text;
    try {
        text = (await wpCLI(cli.playground, { command })).text.trim();
    } catch (error) {
        // A command that ends in WP_CLI::error() exits 1: give back what it said.
        text = String(error.message ?? error);
    }
    if (command.includes('--format=json')) {
        const start = text.indexOf('{');
        try {
            return JSON.parse(text.slice(start));
        } catch {
            throw new Error(`not JSON: ${text.slice(0, 500)}`);
        }
    }
    return text;
}
async function ability(name, input, { as = 'admin', method = 'POST' } = {}) {
    let url = `${base}/wp-json/wp-abilities/v1/abilities/${name}/run`;
    const headers = { 'content-type': 'application/json' };
    if (as) headers['x-test-user'] = `${USER_TOKEN}:${as}`;
    let body;
    if (method === 'GET') {
        if (input) url += `?${new URLSearchParams(Object.entries(input).map(([k, v]) => [`input[${k}]`, String(v)]))}`;
    } else {
        body = JSON.stringify(input === undefined ? {} : { input });
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
const short = (value) => JSON.stringify(value).slice(0, 600);

// ---- Seed the three source plugins, as a site moved in would have them.
const cf7 = await seed('seed-cf7.php');
// Three real submissions through CF7's own REST endpoint (Flamingo stores them), with a file upload.
const quiz = await php(`echo wp_hash('paris', 'wpcf7_quiz');`);
for (const i of [1, 2, 3]) {
    const form = new FormData();
    const fields = {
        _wpcf7: cf7.cf7_id, _wpcf7_version: '6.2.4.1', _wpcf7_locale: 'en_US', _wpcf7_unit_tag: `wpcf7-f${cf7.cf7_id}-p${cf7.page}-o1`,
        _wpcf7_container_post: cf7.page, 'your-name': `Tester ${i}`, 'your-email': `tester${i}@example.com`, 'your-phone': `555-010${i}`,
        'your-site': `https://site${i}.example.com`, guests: i, budget: `4${i}`, 'event-date': `2026-12-0${i}`, topic: 'Support',
        'contact-method': 'Phone', 'source-id': 'cf7-landing', 'your-message': `Hello number ${i}`, gdpr: '1', 'capital-quiz': 'Paris',
        '_wpcf7_quiz_answer_capital-quiz': quiz,
    };
    for (const [k, v] of Object.entries(fields)) form.append(k, String(v));
    for (const v of ['A', 'C']) form.append('multi-topic[]', v);
    for (const v of ['Anime', 'Music']) form.append('interests[]', v);
    form.append('attachment', new Blob(['%PDF-1.4 test'], { type: 'application/pdf' }), 'brief.pdf');
    const res = await fetch(`${base}/wp-json/contact-form-7/v1/contact-forms/${cf7.cf7_id}/feedback`, { method: 'POST', body: form, headers: { 'x-forwarded-for': `203.0.113.${i}` } });
    const answer = await res.json();
    if (answer.status !== 'mail_sent') console.log(`  (CF7 submission ${i}: ${answer.status} ${JSON.stringify(answer.invalid_fields ?? answer.message ?? '')})`);
}
const wpf = await seed('seed-wpforms.php');
const ninja = await seed('seed-ninja.php');
// Give each source entry its own day, so the dates and the order can be checked after the move.
await php(`
foreach (get_posts(['post_type' => 'flamingo_inbound', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'post_status' => 'any']) as $i => $p) {
    $d = sprintf('2026-01-%02d 09:0%d:00', $i + 1, $i);
    wp_update_post(['ID' => $p->ID, 'post_date' => $d, 'post_date_gmt' => get_gmt_from_date($d)]);
}
foreach (get_posts(['post_type' => 'nf_sub', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'post_status' => 'any']) as $i => $p) {
    $d = sprintf('2026-02-%02d 10:0%d:00', $i + 1, $i);
    wp_update_post(['ID' => $p->ID, 'post_date' => $d, 'post_date_gmt' => get_gmt_from_date($d)]);
}
echo json_encode(['ok' => true]);`);
await php(`update_option('forms_test_mail', [], false); echo '{}';`);

const original = await php(`echo json_encode(array_map(fn ($id) => get_post_field('post_content', $id), [
    'cf7' => ${cf7.page}, 'part' => ${cf7.part}, 'wpf' => ${wpf.page}, 'pattern' => ${wpf.pattern}, 'ninja' => ${ninja.page}, 'draft' => ${ninja.draft},
]) + ['widget' => get_option('widget_block')[2]['content']]);`);
console.log(`Seeded CF7 #${cf7.cf7_id} (hash ${cf7.hash}), WPForms #${wpf.wpforms_id}, Ninja #${ninja.ninja_id} (${ninja.subs} submissions)`);

const counts = () => php(`global $wpdb; echo json_encode([
    'forms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_forms"),
    'entries' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions"),
    'active' => array_values(get_option('active_plugins')),
]);`);
// CF7 may add its own "Contact form 1" when it's activated; the seeded forms are found by name.
const SEEDED = { contactform7: 'CF7 Kitchen Sink', wpforms: 'WPForms Kitchen Sink', ninja_forms: 'Ninja Kitchen Sink' };
const byType = (report) => Object.fromEntries(Object.entries(SEEDED).map(([type, title]) => [type, report.forms.find((f) => f.source_type === type && f.title === title)]));
let switched;

await check('command and abilities are registered', async () => {
    const help = await wp('wp help waas forms switch');
    expect(help.includes('--dry-run') && help.includes('--undo'), `help: ${help.slice(0, 300)}`);
    const list = await fetch(`${base}/wp-json/wp-abilities/v1/abilities?category=anypress&per_page=100`, { headers: { 'x-test-user': `${USER_TOKEN}:admin` } }).then((r) => r.json());
    const names = list.map((a) => a.name);
    expect(names.includes('anypress/check-forms') && names.includes('anypress/switch-forms'), `abilities: ${names.join(', ')}`);
    const check = list.find((a) => a.name === 'anypress/check-forms');
    expect(check.meta.annotations.readonly === true, `check-forms annotations ${short(check.meta.annotations)}`);
});

await check('the command needs an administrator', async () => {
    const out = await wp('wp waas forms switch --dry-run');
    expect(/administrator/i.test(out), `without --user: ${out.slice(0, 300)}`);
    const editor = await wp('wp waas forms switch --dry-run --user=editor');
    expect(/administrator/i.test(editor), `as editor: ${editor.slice(0, 300)}`);
});

// Someone already tried Fluent Forms' own importer on the CF7 form (Fluent Forms → Tools → Migrator): it made a
// Fluent Forms copy with about ten malformed notification rows. The switch must reuse that copy and clean it up.
let earlierCopy;
await check('setup: an earlier import with Fluent Forms\' own tool', async () => {
    await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; wp_set_current_user(1);
add_filter('wp_doing_ajax', '__return_true'); add_filter('wp_die_ajax_handler', fn () => fn () => null);
(new \\FluentForm\\App\\Services\\Migrator\\Classes\\ContactForm7Migrator())->import_forms(['${cf7.cf7_id}']);` });
    earlierCopy = await php(`foreach ((array) get_option('__ff_imorted_forms_map') as $id => $v) if ($v['imported_form_id'] == ${cf7.cf7_id}) { global $wpdb;
        echo json_encode(['id' => (int) $id, 'rows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = $id AND meta_key = 'notifications'")]); }`);
    expect(earlierCopy.id > 0 && earlierCopy.rows > 2, `earlier copy ${short(earlierCopy)}`);
});

await check('dry run: lists every form and what it would drop, changes nothing', async () => {
    const before = await counts();
    const report = await wp('wp waas forms switch --dry-run --format=json --user=admin');
    keep('dry-run.json', report);
    keep('dry-run.txt', await wp('wp waas forms switch --dry-run --user=admin'));
    const forms = byType(report);
    expect(report.dry_run === true && report.forms.length >= 3 && Object.values(forms).every(Boolean), short(report));
    expect(Object.values(forms).every((f) => f.status === 'will be converted'), short(report.forms.map((f) => f.status)));
    const cf7Dropped = forms.contactform7.dropped_fields.map((f) => `${f.name}:${f.type}`);
    for (const want of ['your-phone:tel', 'budget:range', 'attachment:file', 'source-id:hidden', 'capital-quiz:quiz']) {
        expect(cf7Dropped.includes(want), `CF7 dropped ${cf7Dropped.join(', ')}`);
    }
    expect(forms.contactform7.entries.found === 3, `CF7 entries ${short(forms.contactform7.entries)}`);
    expect(report.pages.length === 6 && report.widgets.length === 1, `pages ${short(report.pages)} widgets ${short(report.widgets)}`);
    const after = await counts();
    expect(after.forms === before.forms && after.entries === before.entries, `changed: ${short([before, after])}`);
    const page = await php(`echo json_encode(['content' => get_post_field('post_content', ${cf7.page})]);`);
    expect(page.content === original.cf7, `the CF7 page changed in a dry run: ${page.content}`);
});

await check('switch: three forms converted, entries brought in', async () => {
    switched = await wp('wp waas forms switch --format=json --user=admin');
    keep('switch.json', switched);
    const forms = byType(switched);
    expect(switched.forms.every((f) => f.status === 'converted' && f.fluent_id > 0), short(switched.forms.map((f) => [f.source, f.status, f.lost])));
    expect(forms.contactform7.fluent_id === earlierCopy.id, `CF7 copy #${forms.contactform7.fluent_id}, the earlier one was #${earlierCopy.id}`);
    expect(forms.contactform7.entries.imported === 3, `CF7 entries ${short(forms.contactform7.entries)}`);
    expect(forms.ninja_forms.entries.imported === 3, `Ninja entries ${short(forms.ninja_forms.entries)}`);
    expect(forms.wpforms.entries.imported === 0 && /WPForms Pro/.test(forms.wpforms.entries.note), `WPForms entries ${short(forms.wpforms.entries)}`);
    const wpfDropped = forms.wpforms.dropped_fields.map((f) => `${f.label}:${f.type}`);
    for (const want of ['Phone:phone', 'Budget:number-slider', 'Attachment:file-upload', 'GDPR Agreement:gdpr-checkbox', 'Next:pagebreak']) {
        expect(wpfDropped.includes(want), `WPForms dropped ${wpfDropped.join(', ')}`);
    }
    const ninjaDropped = forms.ninja_forms.dropped_fields.map((f) => `${f.name}:${f.type}`);
    for (const want of ['phone:phone', 'confirm_email:confirm', 'spam_q:spam']) {
        expect(ninjaDropped.includes(want), `Ninja dropped ${ninjaDropped.join(', ')}`);
    }
    expect(forms.wpforms.lost.some((l) => l.includes('Phone time')), `WPForms field rules not reported: ${short(forms.wpforms.lost)}`);
    expect(forms.contactform7.lost.some((l) => l.includes('[your-phone]') || l.includes('[attachment]')), `CF7 mail losses: ${short(forms.contactform7.lost)}`);
});

const ff = () => byType(switched);
const meta = (id, key) => php(`global $wpdb; echo json_encode(array_map(fn ($v) => json_decode($v, true), $wpdb->get_col($wpdb->prepare("SELECT value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = %d AND meta_key = %s ORDER BY id", ${id}, '${key}'))));`);

await check('CF7: notifications rebuilt from Mail and Mail (2), no junk rows, confirmation kept', async () => {
    const notifications = await meta(ff().contactform7.fluent_id, 'notifications');
    expect(notifications.length === 2 && notifications.every((n) => n && n.sendTo), `notification rows: ${short(notifications)}`);
    const [admin, visitor] = notifications;
    expect(admin.sendTo.email === 'sales@example.com' && admin.enabled === true, `Mail: ${short(admin)}`);
    expect(admin.subject === 'CF7 lead: {inputs.your-name} about {inputs.topic}', `subject ${admin.subject}`);
    expect(admin.replyTo === '{inputs.your-email}' && admin.fromEmail === 'wordpress@example.com', `Mail headers ${short(admin)}`);
    expect(visitor.sendTo.type === 'field' && visitor.sendTo.field === 'your-email' && visitor.subject === 'Thanks {inputs.your-name}', `Mail (2): ${short(visitor)}`);
    const settings = (await meta(ff().contactform7.fluent_id, 'formSettings'))[0];
    expect(settings.confirmation.messageToShow.includes('CUSTOM-CF7-CONFIRM'), `confirmation ${short(settings.confirmation)}`);
});

await check('CF7: placeholder stays a placeholder, date has no time', async () => {
    const fields = await php(`global $wpdb; echo $wpdb->get_var("SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id = ${ff().contactform7.fluent_id}");`);
    const all = JSON.stringify(fields);
    const list = Object.values(fields.fields);
    const name = list.find((f) => f.attributes?.name === 'your-name');
    expect(name.attributes.placeholder === 'Jane Doe' && name.attributes.value === '', `your-name ${short(name.attributes)}`);
    const date = list.find((f) => f.attributes?.name === 'event-date');
    expect(date.settings.date_format === 'Y-m-d', `event-date ${short(date.settings)} ${all.length}`);
});

const entries = (id) => php(`global $wpdb; echo json_encode($wpdb->get_results($wpdb->prepare("SELECT serial_number, created_at, ip, source_url, response FROM {$wpdb->prefix}fluentform_submissions WHERE form_id = %d ORDER BY serial_number", ${id}), ARRAY_A));`);

await check('CF7 entries: original dates, oldest first, IP and page kept', async () => {
    const rows = await entries(ff().contactform7.fluent_id);
    expect(rows.map((r) => r.created_at).join() === '2026-01-01 09:00:00,2026-01-02 09:01:00,2026-01-03 09:02:00', `dates ${short(rows.map((r) => r.created_at))}`);
    expect(JSON.parse(rows[0].response)['your-name'] === 'Tester 1', `first entry ${rows[0].response.slice(0, 200)}`);
    expect(rows.every((r) => r.ip !== ''), `ips ${short(rows.map((r) => r.ip))}`);
});

await check('WPForms: the default confirmation is the main one, the redirect stays conditional', async () => {
    const settings = (await meta(ff().wpforms.fluent_id, 'formSettings'))[0];
    expect(settings.confirmation.redirectTo === 'samePage' && settings.confirmation.messageToShow.includes('CUSTOM-WPF-CONFIRM'), `main ${short(settings.confirmation)}`);
    const others = await meta(ff().wpforms.fluent_id, 'confirmations');
    expect(others.length === 1 && others[0].redirectTo === 'customUrl' && others[0].conditionals.status === true, `others ${short(others)}`);
});

await check('WPForms: submissions get the right confirmation (conditional redirect only for Support)', async () => {
    const id = ff().wpforms.fluent_id;
    const result = await php(`
$form = wpFluent()->table('fluentform_forms')->find(${id});
$settings = json_decode(wpFluent()->table('fluentform_form_meta')->where('form_id', ${id})->where('meta_key', 'formSettings')->value('value'), true);
$out = [];
foreach (['Support', 'Billing'] as $topic) {
    $c = apply_filters('fluentform/form_submission_confirmation', $settings['confirmation'], ['select_6' => $topic], $form);
    $out[$topic] = [$c['redirectTo'], $c['customUrl'] ?? ''];
}
echo json_encode($out);`);
    expect(result.Support[0] === 'customUrl' && result.Support[1] === 'https://example.com/support-thanks', `Support ${short(result)}`);
    expect(result.Billing[0] === 'samePage', `Billing ${short(result)}`);
});

await check('Ninja: Reply-To from the email action, date format m/d/Y, entries oldest first with their dates', async () => {
    const notifications = await meta(ff().ninja_forms.fluent_id, 'notifications');
    const admin = notifications.find((n) => n.name === 'Admin Email');
    expect(admin && admin.replyTo === '{inputs.email}' && admin.bcc === 'audit@example.com', `admin email ${short(admin)}`);
    const fields = await php(`global $wpdb; echo $wpdb->get_var("SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id = ${ff().ninja_forms.fluent_id}");`);
    const date = Object.values(fields.fields).find((f) => f.attributes?.name === 'event_date');
    expect(date.settings.date_format === 'm/d/Y', `date ${short(date.settings)}`);
    const rows = await entries(ff().ninja_forms.fluent_id);
    expect(JSON.parse(rows[0].response).first_name === 'Ninja1' && rows[0].created_at === '2026-02-01 10:00:00', `first ${short(rows[0])}`);
    expect(rows.map((r) => r.serial_number).join() === '1,2,3', `serials ${short(rows.map((r) => r.serial_number))}`);
});

await check('pages, template part, synced pattern, draft and widget switched, with revisions', async () => {
    const now = await php(`echo json_encode(array_map(fn ($id) => get_post_field('post_content', $id), [
        'cf7' => ${cf7.page}, 'part' => ${cf7.part}, 'wpf' => ${wpf.page}, 'pattern' => ${wpf.pattern}, 'ninja' => ${ninja.page}, 'draft' => ${ninja.draft},
    ]) + ['widget' => get_option('widget_block')[2]['content'], 'revisions' => count(wp_get_post_revisions(${cf7.page}))]);`);
    const old = /contact-form-7|wpforms|ninja_form|ninja-forms/;
    for (const key of ['cf7', 'part', 'wpf', 'pattern', 'ninja', 'draft', 'widget']) {
        expect(!old.test(now[key]) && /fluentform|fluentfom\/guten-block/.test(now[key]), `${key}: ${now[key]}`);
    }
    const id = ff().contactform7.fluent_id;
    expect(now.cf7.split(`[fluentform id="${id}"]`).length === 3 && now.cf7.includes(`{"formId":"${id}"}`), `cf7 page: ${now.cf7}`);
    expect(now.revisions >= 1, `revisions ${now.revisions}`);
    const html = await fetch(`${base}/cf7-contact/`).then((r) => r.text());
    expect(html.includes('fluentform') && !html.includes('[contact-form-7'), 'the CF7 page doesn\'t show the Fluent Forms form');
});

await check('CF7 copy: a submission sends both rebuilt emails', async () => {
    const id = ff().contactform7.fluent_id;
    await php(`update_option('forms_test_mail', [], false); echo '{}';`);
    const result = await php(`
$form = wpFluent()->table('fluentform_forms')->find(${id});
$data = ['your-name' => 'Mail Test', 'your-email' => 'visitor@example.com', 'your-site' => 'https://x.example.com', 'guests' => 2,
    'event-date' => '2026-12-24', 'topic' => 'Sales', 'multi-topic' => ['A'], 'interests' => ['Anime'], 'contact-method' => 'Email',
    'your-message' => 'Hi <there>', 'gdpr' => 'on'];
try {
    $result = (new \\FluentForm\\App\\Services\\Form\\SubmissionHandlerService())->handleSubmission($data, ${id});
} catch (\\Throwable $e) {
    $result = ['error' => get_class($e), 'message' => $e->getMessage(), 'errors' => method_exists($e, 'errors') ? $e->errors() : (method_exists($e, 'getErrors') ? $e->getErrors() : null)];
}
do_action('fluentform_do_scheduled_tasks');
echo json_encode(['result' => $result, 'mail' => get_option('forms_test_mail')]);`);
    const mail = result.mail ?? [];
    const admin = mail.find((m) => String(m.to).includes('sales@example.com'));
    const visitor = mail.find((m) => String(m.to).includes('visitor@example.com'));
    expect(admin && admin.subject === 'CF7 lead: Mail Test about Sales', `admin mail ${short(result)}`);
    expect(JSON.stringify(admin.headers).includes('visitor@example.com'), `admin reply-to ${short(admin.headers)}`);
    expect(visitor && visitor.subject === 'Thanks Mail Test', `visitor mail ${short(mail)}`);
});

await check('running it again changes nothing and makes no duplicates', async () => {
    const before = await counts();
    const again = await wp('wp waas forms switch --format=json --user=admin');
    const after = await counts();
    expect(again.forms.every((f) => f.status === 'already converted'), short(again.forms.map((f) => f.status)));
    expect(again.pages.length === 0 && again.widgets.length === 0, `switched again: ${short(again.pages)}`);
    expect(after.forms === before.forms && after.entries === before.entries, `counts ${short([before, after])}`);
    expect(['contact-form-7/wp-contact-form-7.php', 'wpforms-lite/wpforms.php', 'ninja-forms/ninja-forms.php', 'flamingo/flamingo.php'].every((p) => after.active.includes(p)), `old plugins: ${after.active.join(', ')}`);
});

await check('abilities: check-forms is read-only, switch-forms needs an administrator', async () => {
    const report = await ability('anypress/check-forms', undefined, { method: 'GET' });
    expect(report.status === 200 && report.data.dry_run === true && report.data.forms.length >= 3, `check-forms: HTTP ${report.status} ${short(report.data)}`);
    const editor = await ability('anypress/switch-forms', { undo: false }, { as: 'editor' });
    expect(editor.status === 403, `editor: HTTP ${editor.status}`);
    const nobody = await ability('anypress/check-forms', undefined, { as: null, method: 'GET' });
    expect(nobody.status === 401, `signed out: HTTP ${nobody.status}`);
    const run = await ability('anypress/switch-forms', { undo: false });
    expect(run.status === 200 && run.data.forms.every((f) => f.status === 'already converted'), `switch-forms: HTTP ${run.status} ${short(run.data)}`);
});

await check('undo puts the old shortcodes and blocks back exactly, keeps the Fluent Forms forms', async () => {
    const before = await counts();
    const undo = await wp('wp waas forms switch --undo --format=json --user=admin');
    keep('undo.json', undo);
    expect(undo.pages.length === 6 && undo.widgets.length === 1, `undo ${short(undo)}`);
    const now = await php(`echo json_encode(array_map(fn ($id) => get_post_field('post_content', $id), [
        'cf7' => ${cf7.page}, 'part' => ${cf7.part}, 'wpf' => ${wpf.page}, 'pattern' => ${wpf.pattern}, 'ninja' => ${ninja.page}, 'draft' => ${ninja.draft},
    ]) + ['widget' => get_option('widget_block')[2]['content']]);`);
    for (const key of Object.keys(original)) {
        expect(now[key] === original[key], `${key}: ${now[key]} (was ${original[key]})`);
    }
    const after = await counts();
    expect(after.forms === before.forms && after.entries === before.entries, `counts ${short([before, after])}`);
});

await check('switching again after undo reuses the same forms', async () => {
    const before = await counts();
    const again = await ability('anypress/switch-forms', { undo: false });
    expect(again.status === 200 && again.data.pages.length === 6, `HTTP ${again.status} ${short(again.data.pages)}`);
    const after = await counts();
    expect(after.forms === before.forms, `forms ${before.forms} -> ${after.forms}`);
    const undo = await ability('anypress/switch-forms', { undo: true });
    expect(undo.status === 200 && undo.data.undo === true, `undo ability: HTTP ${undo.status} ${short(undo.data)}`);
});

await check('no PHP warnings or errors from WaaS Client', async () => {
    const log = await php(`$f = WP_CONTENT_DIR . '/debug.log'; echo json_encode(['log' => file_exists($f) ? file_get_contents($f) : '']);`);
    keep('debug.log', log.log);
    const ours = log.log.split('\n').filter((line) => /waas-client|WaaSClient/.test(line));
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
