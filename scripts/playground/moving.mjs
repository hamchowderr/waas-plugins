// End-to-end test of the support, affiliate and community switches (`wp waas support|affiliate|community switch`
// and their check-* / switch-* abilities) on WordPress Playground. Boots moving/blueprint.json (Fluent Support,
// FluentAffiliate and FluentCommunity next to Awesome Support, SliceWP and BuddyPress, with background updates and
// WP-Cron off) with WaaS Client from this checkout, seeds the source plugins (moving/seed.php), then for each switch
// runs the check, the dry run, the switch, the switch again, the undo and the switch again through the ability.
//
//   cd scripts/playground && npm ci
//   npm run moving              # exit 1 on a failure
//   npm run moving -- --serve   # leave the site running at http://127.0.0.1:9404 (admin / password)
//
// Needs packages/waas-client/vendor (composer install in packages/waas-client) and network access to
// downloads.wordpress.org. Playground stores the database in SQLite, not MariaDB: this checks the PHP and WordPress
// behaviour.

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
const PORT = Number(process.env.PLAYGROUND_PORT || 9404);
const SERVE = process.argv.includes('--serve');
const USER_TOKEN = randomBytes(24).toString('hex');
// Optional: a folder to keep the command's reports in.
const REPORTS = process.env.MOVING_REPORT_DIR;
const keep = (name, text) => REPORTS && (mkdirSync(REPORTS, { recursive: true }), writeFileSync(resolve(REPORTS, name), typeof text === 'string' ? text : JSON.stringify(text, null, 2)));

if (!existsSync(resolve(CLIENT, 'vendor/autoload.php'))) {
    console.error('packages/waas-client/vendor is missing: run composer install in packages/waas-client first.');
    process.exit(1);
}

const blueprint = JSON.parse(readFileSync(resolve(HERE, 'moving/blueprint.json'), 'utf8'));
const validation = await validateBlueprintDeclaration(blueprint);
if (!validation.valid) {
    console.error('moving/blueprint.json is not a valid Blueprint:', JSON.stringify(validation.errors, null, 2));
    process.exit(1);
}
const consts = blueprint.steps.find((s) => s.step === 'defineWpConfigConsts')?.consts || {};
if (consts.AUTOMATIC_UPDATER_DISABLED !== true || consts.DISABLE_WP_CRON !== true) {
    // A core update mid-test puts the site in maintenance mode, and cron could send email.
    console.error('moving/blueprint.json must turn off background updates and WP-Cron.');
    process.exit(1);
}
const WP_ZIP = `https://wordpress.org/wordpress-${WP}.zip`;
blueprint.preferredVersions = { php: PHP, wp: WP_ZIP };
blueprint.login = SERVE;
blueprint.steps.push({
    step: 'writeFile',
    path: '/wordpress/wp-content/mu-plugins/moving-test.php',
    data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user; an editor exists to check permissions.
// Every email WordPress actually tries to send (sent or failed) is counted in the moving_test_mail option.
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
$count = function () { update_option('moving_test_mail', (int) get_option('moving_test_mail', 0) + 1, false); };
add_action('wp_mail_succeeded', $count);
add_action('wp_mail_failed', $count);
// Stands in for a plugin that emails each new help desk customer, to show the switch holds such email back.
add_action('fluent_support/customer_created', function ($customer) { wp_mail($customer->email, 'Welcome', 'Hello'); });
`,
});

/** Each switch: the source tables that must never change, and what the seed makes, as the dry run should count it. */
const SWITCHES = {
    support: {
        target: 'Fluent Support',
        source: 'awesome-support',
        sourceTables: ['posts', 'postmeta', 'comments'],
        counts: { tickets: 3, replies: 4, customers: 2 },
        targetTables: ['fs_tickets', 'fs_conversations', 'fs_persons', 'fs_attachments', 'fs_mail_boxes'],
    },
    affiliate: {
        target: 'FluentAffiliate',
        source: 'slicewp',
        sourceTables: ['slicewp_affiliates', 'slicewp_commissions', 'slicewp_visits', 'slicewp_customers', 'slicewp_payouts', 'slicewp_payments'],
        counts: { affiliate_groups: 0, affiliates: 3, referrals: 3, customers: 2, payouts: 1, visits: 4 },
        targetTables: ['fa_affiliates', 'fa_referrals', 'fa_visits', 'fa_customers', 'fa_payouts', 'fa_payout_transactions'],
    },
    community: {
        target: 'FluentCommunity',
        source: 'buddypress',
        sourceTables: ['bp_groups', 'bp_groups_members', 'bp_activity', 'bp_xprofile_data'],
        counts: { groups: 2, group_members: 6, posts: 3, comments: 2 },
        targetTables: ['fcom_spaces', 'fcom_space_user', 'fcom_posts', 'fcom_post_comments', 'fcom_post_reactions', 'fcom_xprofile'],
    },
};

const results = [];
let failed = 0;
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
const short = (value) => String(JSON.stringify(value)).slice(0, 700);
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/** A fingerprint of some tables (prefix added; missing tables count as empty) and the mail counter. */
async function tables(names) {
    return php(`global $wpdb; $out = [];
    foreach (${JSON.stringify(names)} as $t) {
        $name = $wpdb->prefix . $t;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name;
        $rows = $exists ? $wpdb->get_results("SELECT * FROM {$name}", ARRAY_A) : [];
        $out[$t] = ['rows' => count($rows), 'hash' => md5(json_encode($rows))];
    }
    echo json_encode(['tables' => $out, 'mail' => (int) get_option('moving_test_mail', 0)]);`);
}
const rows = (snapshot) => Object.fromEntries(Object.entries(snapshot.tables).map(([t, v]) => [t, v.rows]));

// ---- Seed the sources (BuddyPress needs one request to turn its groups on, then the seed).
const seed = await (async () => {
    let out;
    for (let i = 0; i < 2; i++) {
        out = (await cli.playground.run({ code: readFileSync(resolve(HERE, 'moving/seed.php'), 'utf8') })).text.trim();
    }
    try {
        return JSON.parse(out.slice(out.lastIndexOf('{"seed"')));
    } catch {
        throw new Error(`seed failed: ${out.slice(-1500)}`);
    }
})();

await check('seed: Awesome Support, SliceWP and BuddyPress hold their test data', async () => {
    expect(!seed.errors.length, short(seed.errors));
    expect(same(seed.seed.support, { tickets: 3, replies: 4 }), short(seed.seed.support));
    expect(same(seed.seed.affiliate, { affiliates: 3, visits: 4, customers: 2, commissions: 3, payouts: 1, payments: 1 }), short(seed.seed.affiliate));
    expect(seed.seed.community.groups === 2 && seed.seed.community.moderators === 1 && seed.seed.community.banned === 1 && seed.seed.community.requests === 1 && seed.seed.community.posts === 3 && seed.seed.community.comments === 2, short(seed.seed.community));
});

for (const [key, s] of Object.entries(SWITCHES)) {
    console.log(`\n${key}: ${s.source} into ${s.target}`);
    const sourceStart = await tables(s.sourceTables);
    const targetStart = await tables(s.targetTables);

    await check(`${key}: the check reports the source, its counts and nothing in the way, and changes nothing`, async () => {
        const dry = await wp(`wp waas ${key} switch --dry-run --format=json --user=admin`);
        keep(`${key}-dry-run.json`, dry);
        const p = dry.preflight;
        expect(p.ready && p.target === s.target && p.source === s.source && !p.blockers.length, short(p));
        const counts = Object.fromEntries(dry.compare.map((r) => [r.item, r.source]));
        expect(same(counts, s.counts), `counts ${short(counts)}`);
        expect(dry.lost.length >= 2, short(dry.lost));
        if (key === 'support') {
            expect(p.warnings.some((w) => w.includes('no business inbox')), short(p.warnings));
        }
        const viaAbility = await ability(`anypress/check-${key}`, undefined, { method: 'GET' });
        expect(viaAbility.status === 200 && viaAbility.data.preflight.ready, `check-${key}: HTTP ${viaAbility.status} ${short(viaAbility.data)}`);
        const after = await tables(s.targetTables);
        expect(same(after.tables, targetStart.tables), 'the dry run changed the target');
    });

    await check(`${key}: only an administrator can check or switch`, async () => {
        const editor = await ability(`anypress/check-${key}`, undefined, { as: 'editor', method: 'GET' });
        expect(editor.status === 403, `editor: HTTP ${editor.status}`);
        const nobody = await ability(`anypress/switch-${key}`, {}, { as: null });
        expect(nobody.status === 401, `signed out: HTTP ${nobody.status}`);
        const cliEditor = await wp(`wp waas ${key} switch --dry-run --user=editor`);
        expect(cliEditor.includes('Run this as an administrator'), cliEditor.slice(-300));
    });

    let added;
    await check(`${key}: the switch brings everything across, holds back email and leaves the source alone`, async () => {
        const run = await wp(`wp waas ${key} switch --format=json --user=admin`);
        keep(`${key}-switch.json`, run);
        expect(!run.dry_run && run.compare.every((r) => r.missing === 0 && r.added === r.source), short(run.compare));
        expect(run.notes[0].includes('Everything the source holds'), short(run.notes));
        if (key === 'community') {
            expect(run.fixed.length === 2 && run.fixed[0].includes('1 banned member') && run.fixed[1].includes('is pending'), short(run.fixed));
        }
        const after = await tables(s.targetTables);
        added = rows(after);
        expect(after.mail === targetStart.mail, `email sent: ${targetStart.mail} -> ${after.mail} (held back ${run.emails_blocked})`);
        if (key === 'support') {
            // Each new customer set off the stand-in welcome email, and the switch held it back.
            expect(run.emails_blocked === 2, `held back ${run.emails_blocked}`);
        }
        const source = await tables(s.sourceTables);
        expect(same(source.tables, sourceStart.tables), `the source changed: ${short(source.tables)}`);
    });

    await check(`${key}: what came across is right`, async () => {
        const detail = await php({
            support: `global $wpdb; $p = $wpdb->prefix;
                echo json_encode([
                    'titles' => $wpdb->get_col("SELECT title FROM {$p}fs_tickets ORDER BY title"),
                    'replies' => $wpdb->get_col("SELECT c.content FROM {$p}fs_conversations c ORDER BY c.id"),
                    'customers' => $wpdb->get_col("SELECT email FROM {$p}fs_persons WHERE person_type = 'customer' ORDER BY email"),
                    'mailbox' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fs_mail_boxes"),
                    'unassigned' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fs_tickets WHERE mailbox_id IS NULL OR mailbox_id = 0"),
                ]);`,
            affiliate: `global $wpdb; $p = $wpdb->prefix;
                echo json_encode([
                    'affiliates' => $wpdb->get_col("SELECT u.user_login FROM {$p}fa_affiliates a JOIN {$p}users u ON u.ID = a.user_id ORDER BY u.user_login"),
                    'amounts' => array_map('floatval', $wpdb->get_col("SELECT amount FROM {$p}fa_referrals ORDER BY amount")),
                    'paid' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fa_referrals WHERE status = 'paid'"),
                ]);`,
            community: `global $wpdb; $p = $wpdb->prefix;
                echo json_encode([
                    'spaces' => $wpdb->get_col("SELECT title FROM {$p}fcom_spaces WHERE type = 'community' ORDER BY title"),
                    'moderators' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fcom_space_user WHERE role = 'moderator'"),
                    'members' => $wpdb->get_col("SELECT CONCAT(s.title, ' ', u.user_login, ' ', m.status) FROM {$p}fcom_space_user m JOIN {$p}fcom_spaces s ON s.id = m.space_id JOIN {$p}users u ON u.ID = m.user_id ORDER BY s.title, u.user_login"),
                    'in_spaces' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fcom_posts WHERE space_id IS NOT NULL AND space_id > 0"),
                    'comments' => $wpdb->get_col("SELECT message FROM {$p}fcom_post_comments ORDER BY id"),
                ]);`,
        }[key]);
        keep(`${key}-detail.json`, detail);
        if (key === 'support') {
            expect(same(detail.titles, ['Invoice please', 'Order never arrived', 'Wrong size']), short(detail.titles));
            expect(detail.replies.length === 4 && detail.replies.some((r) => r.includes('ships today')), short(detail.replies));
            expect(same(detail.customers, ['ana@example.com', 'ben@example.com']) && detail.mailbox === 1 && detail.unassigned === 0, short(detail));
        } else if (key === 'affiliate') {
            expect(same(detail.affiliates, ['cara', 'dev', 'eli']) && same(detail.amounts, [7.25, 12.5, 20]) && detail.paid === 1, short(detail));
        } else {
            expect(same(detail.spaces, ['Bakers', 'Gardeners']) && detail.moderators === 1 && detail.in_spaces === 2, short(detail));
            // The banned member (jo) is out of Bakers and the open request (kim) is pending.
            expect(same(detail.members, ['Bakers admin active', 'Bakers hana active', 'Bakers kim pending', 'Gardeners admin active', 'Gardeners hana active', 'Gardeners ivo active']), short(detail.members));
            expect(detail.comments.length === 2 && detail.comments.some((c) => c.includes('twice a day')), short(detail.comments));
        }
    });

    await check(`${key}: running it again is refused and changes nothing`, async () => {
        const again = await wp(`wp waas ${key} switch --user=admin`);
        expect(again.includes('already ran here'), again.slice(-400));
        const viaAbility = await ability(`anypress/switch-${key}`, {});
        expect(viaAbility.status === 409, `switch-${key}: HTTP ${viaAbility.status} ${short(viaAbility.data)}`);
        const now = rows(await tables(s.targetTables));
        expect(same(now, added), `the target changed: ${short(now)} (was ${short(added)})`);
    });

    await check(`${key}: undo removes only what the switch added; switching again through the ability redoes it`, async () => {
        const preview = await wp(`wp waas ${key} switch --undo --dry-run --format=json --user=admin`);
        expect(preview.dry_run && same(rows(await tables(s.targetTables)), added), short(preview));
        const undo = await wp(`wp waas ${key} switch --undo --format=json --user=admin`);
        keep(`${key}-undo.json`, undo);
        expect(same(undo.removed, preview.removed), `undo ${short(undo.removed)} vs preview ${short(preview.removed)}`);
        const undone = await tables(s.targetTables);
        expect(same(undone.tables, targetStart.tables), `left behind: ${short(rows(undone))} (before ${short(rows(targetStart))})`);
        const source = await tables(s.sourceTables);
        expect(same(source.tables, sourceStart.tables), 'the source changed');
        const redo = await ability(`anypress/switch-${key}`, {});
        expect(redo.status === 200 && redo.data.compare.every((r) => r.missing === 0), `switch-${key}: HTTP ${redo.status} ${short(redo.data)}`);
        expect(same(rows(await tables(s.targetTables)), added), 'the redo differs from the first switch');
    });
}

await check('community: a FluentCommunity that already has a space is refused', async () => {
    await wp('wp waas community switch --undo --user=admin');
    await php(`global $wpdb; $wpdb->insert($wpdb->prefix . 'fcom_spaces', ['title' => 'Existing', 'slug' => 'existing', 'type' => 'community', 'privacy' => 'public', 'status' => 'published', 'created_by' => 1]); echo json_encode(['id' => $wpdb->insert_id]);`);
    const dry = await wp('wp waas community switch --dry-run --format=json --user=admin');
    expect(!dry.preflight.ready && dry.preflight.blockers.some((b) => b.includes('already has spaces or posts')), short(dry.preflight));
    const run = await wp('wp waas community switch --user=admin');
    expect(run.includes('already has spaces or posts'), run.slice(-400));
});

await check('affiliate: a FluentAffiliate that already has affiliates is refused', async () => {
    await wp('wp waas affiliate switch --undo --user=admin');
    await php(`global $wpdb; $wpdb->insert($wpdb->prefix . 'fa_affiliates', ['user_id' => 1, 'status' => 'active']); echo json_encode(['id' => $wpdb->insert_id]);`);
    const dry = await wp('wp waas affiliate switch --dry-run --format=json --user=admin');
    expect(!dry.preflight.ready && dry.preflight.blockers.some((b) => b.includes('already holds affiliates')), short(dry.preflight));
});

await check('without the Fluent plugin: the check says to turn it on', async () => {
    await php(`deactivate_plugins('fluent-support/fluent-support.php');`);
    const off = await wp('wp waas support switch --dry-run --format=json --user=admin');
    expect(!off.preflight.ready && off.preflight.blockers.some((b) => b.includes('Fluent Support isn')), short(off.preflight));
    await php(`activate_plugin('fluent-support/fluent-support.php');`);
});

await check('no PHP warnings or errors from WaaS Client', async () => {
    const log = await php(`$f = WP_CONTENT_DIR . '/debug.log'; echo json_encode(['log' => file_exists($f) ? file_get_contents($f) : '']);`);
    keep('debug.log', log.log);
    const ours = log.log.split('\n').filter((line) => /waas-client|WaaSClient/.test(line));
    expect(!ours.length, ours.slice(0, 5).join('\n'));
});

console.log(`\n${results.length - failed} passed, ${failed} failed`);
if (SERVE) {
    console.log(`Left running at ${base} (Ctrl+C to stop).`);
    await new Promise(() => {});
}
await cli[Symbol.asyncDispose]();
process.exit(failed ? 1 : 0);
