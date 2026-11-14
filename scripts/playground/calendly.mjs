// End-to-end test of AnyPress Client's Calendly switch (`wp anypress calendly switch` and the check-calendly and
// switch-calendly abilities) on WordPress Playground, as a bookings site: the AnyPress theme, FluentBooking at the
// store template's version, and AnyPress Client from this checkout with ANYPRESS_TENANT_ROLES bookings. Calendly's API
// is played by calendly/fake-calendly.php (Calendly's published v2 response shapes, answered inside WordPress), so no
// Calendly account or token is needed. Checks: the check without a token, with a wrong one and with the right one; an
// editor is refused; the switch brings availability schedules (weekly and dated hours, in their own time zone), event
// types as booking types (length, description, locations, seats, drafts) on the administrator's calendar, and
// upcoming bookings (two pages) with each invitee and meeting link; a booking of a deleted event type is skipped;
// the token is saved nowhere; no email is sent; it runs once; undo takes out only what it added; the command runs it
// again.
//
//   cd scripts/playground && npm ci
//   npm run calendly            # exit 1 on a failure
//   npm run calendly -- --serve # leave the site running at http://127.0.0.1:9414 (admin / password)
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
const PORT = Number(process.env.PLAYGROUND_PORT || 9414);
const SERVE = process.argv.includes('--serve');
const SITE_TYPE = 'bookings';
const FLUENT_BOOKING = process.env.FLUENT_BOOKING || '2.6.0';
const TOKEN = 'calendly-test-token';
const USER_TOKEN = randomBytes(24).toString('hex');

for (const [path, fix] of [[resolve(CLIENT, 'vendor/autoload.php'), 'run composer install --no-dev in packages/anypress-client'], [ROLES, 'set ANYPRESS_API_DIR to a waas-provisioning-api checkout']]) {
    if (!existsSync(path)) {
        console.error(`${path} is missing: ${fix} first.`);
        process.exit(1);
    }
}

const blueprint = {
    landingPage: '/wp-admin/admin.php?page=fluent-booking',
    login: SERVE,
    features: { networking: true },
    steps: [
        { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false, AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } },
        { step: 'writeFile', path: '/tmp/wp-cli.phar', data: { resource: 'url', url: 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-booking.${FLUENT_BOOKING}.zip` }, options: { activate: true } },
        { step: 'mkdir', path: '/wordpress/wp-content/plugins/anypress-client-data' },
        { step: 'writeFile', path: '/wordpress/wp-content/plugins/anypress-client-data/roles.json', data: readFileSync(ROLES, 'utf8') },
        // A store sold as a bookings site: its site type comes from the container's environment.
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/0-anypress-store-env.php', data: `<?php putenv('ANYPRESS_IS_TENANT=true'); putenv('ANYPRESS_SITE_KEY=${randomBytes(24).toString('hex')}'); define('ANYPRESS_TENANT_ROLES', '${SITE_TYPE}');\n` },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/fake-calendly.php', data: readFileSync(resolve(HERE, 'calendly/fake-calendly.php'), 'utf8') },
        {
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/calendly-test.php',
            data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user; an editor checks permissions. Every
// email WordPress tries to send is counted in calendly_test_mail.
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
$count = function () { update_option('calendly_test_mail', (int) get_option('calendly_test_mail', 0) + 1, false); };
add_action('wp_mail_succeeded', $count);
add_action('wp_mail_failed', $count);
`,
        },
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'Calendly move test', permalink_structure: '/%postname%/', timezone_string: 'America/New_York' } },
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
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}, site type ${SITE_TYPE}, FluentBooking ${FLUENT_BOOKING}`);

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

/** What FluentBooking holds now, through its own models. */
const state = () =>
    php(`global $wpdb;
    $schedules = array_map(fn ($s) => ['id' => $s->id, 'title' => $s->key, 'default' => !empty($s->value['default']), 'timezone' => $s->value['timezone'] ?? '', 'days' => array_keys(array_filter($s->value['weekly_schedules'] ?? [], fn ($d) => !empty($d['enabled']))), 'overrides' => array_keys($s->value['date_overrides'] ?? [])], \\FluentBooking\\App\\Models\\Availability::query()->orderBy('id')->get()->all());
    $slots = array_map(fn ($e) => ['id' => $e->id, 'calendar_id' => $e->calendar_id, 'title' => $e->title, 'duration' => (int) $e->duration, 'status' => $e->status, 'event_type' => $e->event_type, 'seats' => (int) $e->max_book_per_slot, 'availability_type' => $e->availability_type, 'availability_id' => (int) $e->availability_id, 'locations' => array_column((array) $e->location_settings, 'type'), 'location' => ((array) $e->location_settings)[0] ?? null, 'description' => $e->description], \\FluentBooking\\App\\Models\\CalendarSlot::query()->orderBy('id')->get()->all());
    $bookings = \\FluentBooking\\App\\Models\\Booking::query()->orderBy('id')->get();
    $first = $bookings->first(fn ($b) => $b->email === 'pat@example.com');
    $workshop = array_values(array_map(fn ($b) => ['email' => $b->email, 'group' => (int) $b->group_id, 'tz' => $b->person_time_zone], array_filter($bookings->all(), fn ($b) => in_array($b->email, ['ann@example.com', 'ben@example.com', 'cy@example.com'], true))));
    $leaks = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE '%${TOKEN}%' AND option_name <> 'calendly_test_tokens'") + (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_value LIKE '%${TOKEN}%'");
    echo json_encode([
        'calendars' => array_map(fn ($c) => ['id' => $c->id, 'user_id' => (int) $c->user_id, 'type' => $c->type], \\FluentBooking\\App\\Models\\Calendar::query()->get()->all()),
        'schedules' => $schedules,
        'slots' => $slots,
        'bookings' => $bookings->count(),
        'first' => $first ? ['name' => $first->first_name . ' ' . $first->last_name, 'tz' => $first->person_time_zone, 'start' => $first->start_time, 'end' => $first->end_time, 'minutes' => (int) $first->slot_minutes, 'status' => $first->status, 'phone' => $first->phone, 'message' => $first->message, 'location' => $first->location_details, 'person' => (int) $first->person_user_id, 'hosts' => array_map('intval', $first->hosts->pluck('ID')->all()), 'event' => $first->slot ? $first->slot->title : null, 'source' => $first->source] : null,
        'workshop' => $workshop,
        'leaks' => $leaks,
        'mail' => (int) get_option('calendly_test_mail', 0), 'calls' => (int) get_option('calendly_test_calls', 0),
        'editor_ok' => (bool) get_user_by('login', 'editor'),
        'fluent_booking' => is_plugin_active('fluent-booking/fluent-booking.php'),
        'pat_start' => calendly_test_data()['events'][0]['start_time'],
    ]);`);

await check('the site is a bookings site with FluentBooking on and empty', async () => {
    const now = await state();
    expect(now.fluent_booking && now.editor_ok && now.bookings === 0 && now.slots.length === 0, short(now));
});

await check('without a token the check asks for one and changes nothing', async () => {
    const res = await ability('check-calendly', {}, { method: 'GET' });
    expect(res.status === 200 && res.data.preflight.ready === false, short(res));
    expect(res.data.preflight.blockers.some((b) => b.includes('personal access token')), short(res.data.preflight.blockers));
});

await check('a wrong token is refused with Calendly\'s own answer', async () => {
    const res = await ability('check-calendly', { token: 'wrong' }, { method: 'GET' });
    expect(res.status === 200 && !res.data.preflight.ready && res.data.preflight.blockers.some((b) => b.includes('did not accept the token')), short(res));
    expect(!JSON.stringify(res.data).includes('wrong'), 'the wrong token was shown back');
});

await check('with the token the check counts the Calendly account and changes nothing', async () => {
    const before = (await state()).calls;
    const res = await ability('check-calendly', { token: TOKEN }, { method: 'GET' });
    const p = res.data.preflight;
    expect(res.status === 200 && p.ready, short(res));
    expect(JSON.stringify(p.sources.calendly.counts) === JSON.stringify({ schedules: 2, event_types: 3, bookings: 105 }), short(p.sources));
    expect(p.warnings.some((w) => w.includes('own FluentBooking calendar')) && p.warnings.some((w) => w.includes('API calls')), short(p.warnings));
    expect(res.data.lost.some((l) => l.startsWith('Past and cancelled bookings')), short(res.data.lost));
    const after = await state();
    expect(after.slots.length === 0 && after.schedules.length === 0 && after.calls - before <= 6, short(after));
});

await check('an editor can\'t run it', async () => {
    const res = await ability('switch-calendly', { token: TOKEN }, { as: 'editor' });
    expect(res.status === 403, short(res));
});

let report;
await check('the switch brings the Calendly account across', async () => {
    const res = await ability('switch-calendly', { token: TOKEN });
    report = res.data;
    expect(res.status === 200 && report.dry_run === false, short(res));
    expect(row(report, 'schedules').added === 2 && row(report, 'event_types').added === 3, short(report.compare));
    expect(row(report, 'bookings').added === 104 && row(report, 'bookings').missing === 1 && report.skipped === 1, short([report.compare, report.skipped]));
});

let after;
await check('schedules keep their name, time zone, days and dated hours; the day off and FluentBooking\'s own "Weekly Hours" stay out', async () => {
    after = await state();
    const [working, evenings] = after.schedules;
    expect(after.schedules.length === 2, short(after.schedules));
    expect(working.title === 'Working hours' && working.default && working.timezone === 'America/New_York' && working.days.join() === 'mon,tue,wed,thu,fri', short(working));
    expect(working.overrides.length === 1, short(working.overrides));
    expect(evenings.title === 'Evenings' && !evenings.default && evenings.timezone === 'Europe/London' && evenings.days.join() === 'tue,thu', short(evenings));
});

await check('event types are booking types on the administrator\'s calendar, each on its own schedule', async () => {
    const [intro, workshop, old] = after.slots;
    const [working, evenings] = after.schedules;
    expect(after.calendars.length === 1 && after.calendars[0].user_id === 1 && after.calendars[0].type === 'simple' && after.slots.length === 3, short([after.calendars, after.slots]));
    expect(intro.title === 'Intro call' && intro.duration === 30 && intro.status === 'active' && intro.event_type === 'single' && intro.seats === 1, short(intro));
    expect(intro.availability_type === 'existing_schedule' && intro.availability_id === working.id && intro.locations.join() === 'online_meeting' && intro.location.title === 'Zoom', short(intro));
    expect(intro.description.includes('<strong>Intro call</strong>') && !intro.description.includes('<script'), intro.description);
    expect(workshop.event_type === 'group' && workshop.seats === 8 && workshop.duration === 90 && workshop.availability_id === evenings.id, short(workshop));
    expect(workshop.locations.join() === 'in_person_organizer' && workshop.location.description === '12 Main St, Springfield', short(workshop.location));
    expect(old.title === 'Old consult' && old.status === 'draft' && old.locations.join() === 'custom', short(old));
});

await check('upcoming bookings keep the invitee, time, time zone, answers and meeting link; nobody else is linked to them', async () => {
    const { first } = after;
    expect(after.bookings === 104 && first, short(after.bookings));
    expect(first.name === 'Pat Morgan' && first.tz === 'Europe/Berlin' && first.status === 'scheduled' && first.minutes === 30 && first.event === 'Intro call', short(first));
    expect(first.start === after.pat_start.slice(0, 19).replace('T', ' '), short([first.start, after.pat_start]));
    expect(first.phone === '+49 30 1234567' && first.message === 'What should we talk about?: Moving my shop', short(first));
    expect(first.location.type === 'online_meeting' && first.location.online_platform_link === 'https://zoom.example/j/123', short(first.location));
    expect(first.person === 0 && first.hosts.join() === '1' && first.source === 'calendly', short(first));
});

await check('a group event\'s invitees share one FluentBooking group, and a bad time zone falls back to the host\'s', async () => {
    const w = after.workshop;
    expect(w.length === 3 && w.every((b) => b.group === w[0].group && b.group > 0), short(w));
    expect(w[0].tz === 'Europe/London' && w[1].tz === 'America/New_York' && w[2].tz === 'America/New_York', short(w));
});

await check('FluentBooking\'s own Bookings screen lists them as upcoming, a workshop as one group', async () => {
    const res = await fetch(`${base}/wp-json/fluent-booking/v2/schedules?period=upcoming&author=all&per_page=200`, { headers: { 'x-test-user': `${USER_TOKEN}:admin` } });
    const data = await res.json();
    expect(res.status === 200 && Number(data.schedules?.total) === 102, short({ status: res.status, total: data.schedules?.total, data: String(JSON.stringify(data)).slice(0, 300) }));
});

await check('nothing in debug.log from the switch', async () => {
    const log = await cli.playground.readFileAsText('/wordpress/wp-content/debug.log').catch(() => '');
    const lines = log.split('\n').filter((l) => /anypress-client|Calendly/i.test(l));
    expect(lines.length === 0, lines.slice(0, 5).join('\n'));
});

await check('the token is saved nowhere and no email was sent', async () => {
    expect(after.leaks === 0 && after.mail === 0, short({ leaks: after.leaks, mail: after.mail }));
});

await check('it runs once', async () => {
    const res = await ability('switch-calendly', { token: TOKEN });
    expect(res.status === 409, short(res));
});

await check('undo takes out only what the switch added, the calendar it made too', async () => {
    const dry = await wp('wp anypress calendly switch --user=admin --undo --dry-run --format=json');
    expect(dry.removed.bookings === 104 && dry.removed.event_types === 3 && dry.removed.schedules === 2 && dry.removed.calendar === 1, short(dry));
    const res = await ability('switch-calendly', { undo: true });
    expect(res.status === 200 && res.data.undo, short(res));
    const now = await state();
    expect(now.bookings === 0 && now.slots.length === 0 && now.schedules.length === 0 && now.calendars.length === 0 && now.editor_ok, short(now));
});

await check('the command runs it again with --token', async () => {
    const res = await wp(`wp anypress calendly switch --user=admin --token=${TOKEN} --format=json`);
    expect(row(res, 'bookings').added === 104 && row(res, 'event_types').added === 3, short(res.compare));
    const now = await state();
    expect(now.leaks === 0 && now.mail === 0, short(now));
});

const failed = results.filter((ok) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed (site type ${SITE_TYPE})`);
if (!SERVE) {
    process.exit(failed ? 1 : 0);
}
