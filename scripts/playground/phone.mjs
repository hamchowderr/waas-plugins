// End-to-end test of AnyPress Client's phone receptionist routes (/site/phone/*) on WordPress Playground: FluentBooking
// and FluentCRM from wordpress.org, one booking type open every day, then the calls the phone receptionist makes:
// list services, read open times, book one, refuse the same time twice, and save the caller in FluentCRM with tags.
//
//   cd scripts/playground && npm ci
//   npm run phone             # exit 1 on a failure
//   npm run phone -- --serve  # leave the site running at http://127.0.0.1:9404 (admin / password)
//
// Needs packages/anypress-client/vendor (composer install in packages/anypress-client) and network access to
// downloads.wordpress.org. Playground keeps the database in SQLite, not MariaDB.

import { createHash, createHmac, randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const CLIENT = resolve(ROOT, 'packages/anypress-client');
const WP = process.env.PLAYGROUND_WP || '7.1.3';
const PHP = process.env.PLAYGROUND_PHP || '8.3';
const PORT = Number(process.env.PLAYGROUND_PORT || 9404);
const SERVE = process.argv.includes('--serve');
const THEME = resolve(ROOT, 'scripts/playground/preview-zips/anypress.zip');
const SITE_KEY = randomBytes(24).toString('hex');
const USER_TOKEN = randomBytes(24).toString('hex');
const TIMEZONE = 'America/Los_Angeles';

if (!existsSync(resolve(CLIENT, 'vendor/autoload.php'))) {
    console.error('packages/anypress-client/vendor is missing: run composer install in packages/anypress-client first.');
    process.exit(1);
}

const plugin = (slug) => ({ step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug }, options: { activate: true } });
const blueprint = {
    landingPage: '/wp-admin/',
    login: SERVE,
    steps: [
        { step: 'defineWpConfigConsts', consts: { AUTOMATIC_UPDATER_DISABLED: true, DISABLE_WP_CRON: true } },
        { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(THEME) }, options: { activate: true } },
        plugin('fluent-booking'),
        plugin('fluent-crm'),
        { step: 'activatePlugin', pluginPath: 'anypress-client/anypress-client.php' },
        { step: 'setSiteOptions', options: { blogname: 'Rosa Plumbing', permalink_structure: '/%postname%/', timezone_string: TIMEZONE } },
        { step: 'writeFile', path: '/wordpress/wp-content/mu-plugins/anypress-test-site-key.php', data: `<?php putenv('ANYPRESS_SITE_KEY=${SITE_KEY}');\n` },
        {
            // Test only: X-Test-User: <token>:<login> signs the request in as that user (to set FluentBooking up).
            // No email leaves the site.
            step: 'writeFile',
            path: '/wordpress/wp-content/mu-plugins/anypress-test-users.php',
            data: `<?php
add_filter('determine_current_user', function ($id) {
    [$token, $login] = array_pad(explode(':', $_SERVER['HTTP_X_TEST_USER'] ?? '', 2), 2, '');
    $user = hash_equals('${USER_TOKEN}', $token) ? get_user_by('login', $login) : false;
    return $user ? $user->ID : $id;
}, 30);
add_filter('pre_wp_mail', '__return_true');
`,
        },
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
console.log(`WordPress ${WP} on PHP ${PHP} at ${base}`);

const parse = async (res) => {
    const text = await res.text();
    try {
        return { status: res.status, data: JSON.parse(text) };
    } catch {
        return { status: res.status, data: text };
    }
};

/** PHP's rawurlencode: like encodeURIComponent, but also encodes ! ' ( ) *. */
const rawurlencode = (value) => encodeURIComponent(value).replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`);

/** Calls a phone route as the AnyPress API does: X-AnyPress-Signature over time, nonce, method, route and body hash. */
async function phone(method, path, body, { sign = true } = {}) {
    const route = `/anypress-client/v1/site/phone${path}`;
    const json = body === undefined ? '' : JSON.stringify(body);
    const headers = { 'content-type': 'application/json' };
    if (sign) {
        const t = String(Math.floor(Date.now() / 1000));
        const n = randomBytes(16).toString('hex');
        const [pathname, query = ''] = route.split('?');
        const sorted = [...new URLSearchParams(query)]
            .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
            .map(([k, v]) => `${rawurlencode(k)}=${rawurlencode(v)}`)
            .join('&');
        const signed = sorted ? `${pathname}?${sorted}` : pathname;
        const payload = [t, n, method, signed, createHash('sha256').update(json).digest('hex')].join('\n');
        headers['x-anypress-signature'] = `t=${t},n=${n},s=${createHmac('sha256', SITE_KEY).update(payload).digest('hex')}`;
    }
    return parse(await fetch(`${base}/wp-json${route}`, { method, headers, body: json || undefined }));
}

/** A request as the site's administrator, for setting the site up. */
const asAdmin = async (method, path, body) =>
    parse(await fetch(`${base}/wp-json${path}`, { method, headers: { 'content-type': 'application/json', 'x-test-user': `${USER_TOKEN}:admin` }, body: body && JSON.stringify(body) }));

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
const expectStatus = (res, status) => expect(res.status === status, `HTTP ${res.status} (wanted ${status}): ${JSON.stringify(res.data).slice(0, 400)}`);

// One booking type, open 09:00 to 17:00 every day, so the test always finds open times.
const day = { enabled: true, slots: [{ start: '09:00', end: '17:00' }] };
const setup = await asAdmin('POST', '/fluent-booking/v2/calendars', {
    calendar: {
        author_timezone: TIMEZONE,
        type: 'simple',
        slot: {
            title: 'Leak repair visit',
            duration: 60,
            event_type: 'single',
            availability_type: 'custom',
            schedule_type: 'weekly_schedules',
            weekly_schedules: Object.fromEntries(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'].map((d) => [d, day])),
            status: 'active',
            location_settings: [{ type: 'in_person_organizer', title: 'At your home', description: '' }],
        },
    },
});
if (setup.status !== 200 || !setup.data?.slot?.id) {
    console.error('Could not set FluentBooking up:', setup.status, JSON.stringify(setup.data).slice(0, 500));
    await cli[Symbol.asyncDispose]?.();
    process.exit(1);
}

let service;
let firstTime;

await check('unsigned requests are refused', async () => {
    expectStatus(await phone('GET', '/services', undefined, { sign: false }), 401);
});

await check('services: the open booking type, with its minutes', async () => {
    const res = await phone('GET', '/services');
    expectStatus(res, 200);
    service = res.data.services.find((s) => s.title === 'Leak repair visit');
    expect(service && service.minutes === 60, JSON.stringify(res.data));
    expect(res.data.timezone === TIMEZONE, `timezone ${res.data.timezone}`);
});

await check('times: open start times for the next days, at most 8 a day', async () => {
    const res = await phone('GET', `/services/${service.id}/times?timezone=${encodeURIComponent(TIMEZONE)}`);
    expectStatus(res, 200);
    expect(res.data.days.length > 0 && res.data.days.length <= 7, `days: ${JSON.stringify(res.data).slice(0, 300)}`);
    expect(res.data.days.every((d) => d.times.length > 0 && d.times.length <= 8), 'a day with no times or too many');
    // Tomorrow's first time is safely past FluentBooking's minimum notice.
    firstTime = (res.data.days[1] || res.data.days[0]).times[0];
    expect(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(firstTime), `time ${firstTime}`);
});

await check('book: a caller with no email gets the time', async () => {
    const res = await phone('POST', '/bookings', { service: service.id, start: firstTime, timezone: TIMEZONE, name: 'Sam Lee', phone: '+1 (555) 010-2000', note: 'Kitchen sink leaking' });
    expectStatus(res, 200);
    expect(res.data.booked === true && res.data.id > 0, JSON.stringify(res.data));
});

await check('book: the same time again is refused as taken', async () => {
    const res = await phone('POST', '/bookings', { service: service.id, start: firstTime, timezone: TIMEZONE, name: 'Ann Cho', phone: '5550103000' });
    expectStatus(res, 409);
});

await check('times: the booked time is no longer offered', async () => {
    const res = await phone('GET', `/services/${service.id}/times?timezone=${encodeURIComponent(TIMEZONE)}`);
    expectStatus(res, 200);
    expect(!res.data.days.some((d) => d.times.includes(firstTime)), `${firstTime} still offered`);
});

await check('book: missing name or phone is a bad request', async () => {
    expectStatus(await phone('POST', '/bookings', { service: service.id, start: firstTime, name: 'Sam' }), 400);
});

await check('callers: saved in FluentCRM with the call tags, never subscribed', async () => {
    const res = await phone('POST', '/callers', { name: 'Sam Lee', phone: '+1 (555) 010-2000', interested: 'Leak repair', urgent: true, summary: 'Sink leaking, booked tomorrow.' });
    expectStatus(res, 200);
    expect(res.data.saved && res.data.new, JSON.stringify(res.data));
    expect(['Phone call', 'Leak repair: Interested', 'Urgent call'].every((t) => res.data.tags.includes(t)), JSON.stringify(res.data.tags));
    const contact = await asAdmin('GET', `/fluent-crm/v2/subscribers/${res.data.contact}`);
    expectStatus(contact, 200);
    const s = contact.data.subscriber;
    expect(s.email === 'caller-15550102000@phone.invalid', `email ${s.email}`);
    expect(s.status === 'transactional', `status ${s.status}`);
    expect(s.phone === '+15550102000', `phone ${s.phone}`);
});

await check('callers: the same number again updates the same contact', async () => {
    const res = await phone('POST', '/callers', { phone: '+15550102000' });
    expectStatus(res, 200);
    expect(res.data.new === false, JSON.stringify(res.data));
});

const failed = results.filter((ok) => !ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
if (SERVE) {
    console.log(`Serving at ${base} (admin / password). Ctrl+C to stop.`);
} else {
    await cli[Symbol.asyncDispose]?.();
    process.exit(failed ? 1 : 0);
}
