// End-to-end test of the CRM switch (`wp waas crm switch` and the check-crm / switch-crm abilities) on WordPress
// Playground. Boots crm-switch/blueprint.json (WooCommerce, FluentCRM, MailPoet and the Newsletter plugin, with
// background updates and WP-Cron off) with WaaS Client from this checkout, seeds buyers and subscribers
// (crm-switch/seed.php), then runs the checks, the dry run, the switch, the switch again, the abilities and the undo.
// It runs twice, on a fresh site each time: WooCommerce orders in posts (HPOS off), then in its order tables (HPOS on).
//
//   cd scripts/playground && npm ci
//   npm run crm-switch                      # both runs, exit 1 on a failure
//   npm run crm-switch -- --hpos=yes        # one run only (yes or no)
//   npm run crm-switch -- --hpos=no --serve # leave the site running at http://127.0.0.1:9403 (admin / password)
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
const PORT = Number(process.env.PLAYGROUND_PORT || 9403);
const SERVE = process.argv.includes('--serve');
const ONLY = (process.argv.find((arg) => arg.startsWith('--hpos=')) || '').slice(7);
const MODES = ONLY === 'yes' || ONLY === 'no' ? [ONLY] : ['no', 'yes'];
const USER_TOKEN = randomBytes(24).toString('hex');
// Optional: a folder to keep the command's reports in.
const REPORTS = process.env.CRM_REPORT_DIR;
const keep = (name, text) => REPORTS && (mkdirSync(REPORTS, { recursive: true }), writeFileSync(resolve(REPORTS, name), typeof text === 'string' ? text : JSON.stringify(text, null, 2)));

if (!existsSync(resolve(CLIENT, 'vendor/autoload.php'))) {
    console.error('packages/waas-client/vendor is missing: run composer install in packages/waas-client first.');
    process.exit(1);
}

const blueprint = JSON.parse(readFileSync(resolve(HERE, 'crm-switch/blueprint.json'), 'utf8'));
const validation = await validateBlueprintDeclaration(blueprint);
if (!validation.valid) {
    console.error('crm-switch/blueprint.json is not a valid Blueprint:', JSON.stringify(validation.errors, null, 2));
    process.exit(1);
}
const consts = blueprint.steps.find((s) => s.step === 'defineWpConfigConsts')?.consts || {};
if (consts.AUTOMATIC_UPDATER_DISABLED !== true || consts.DISABLE_WP_CRON !== true) {
    // A core update mid-test puts the site in maintenance mode, and cron could send email.
    console.error('crm-switch/blueprint.json must turn off background updates and WP-Cron.');
    process.exit(1);
}
const WP_ZIP = `https://wordpress.org/wordpress-${WP}.zip`;
blueprint.preferredVersions = { php: PHP, wp: WP_ZIP };
blueprint.login = SERVE;
blueprint.steps.push({
    step: 'writeFile',
    path: '/wordpress/wp-content/mu-plugins/crm-test.php',
    data: `<?php
// Test only: X-Test-User: <token>:<login> signs the request in as that user; an editor exists to check permissions.
// Every email WordPress actually tries to send (sent or failed) is counted in the crm_test_mail option.
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
$count = function () { update_option('crm_test_mail', (int) get_option('crm_test_mail', 0) + 1, false); };
add_action('wp_mail_succeeded', $count);
add_action('wp_mail_failed', $count);
// Stands in for another plugin that emails each new contact, to show the switch holds such email back.
add_action('fluent_crm/contact_created', function ($contact) {
    wp_mail($contact->email, 'Welcome', 'Hello');
});
`,
});

const results = [];
let failedTotal = 0;
for (const hpos of MODES) {
    await runMode(hpos);
}
console.log(`\n${results.length - failedTotal} passed, ${failedTotal} failed`);
if (!SERVE) {
    process.exit(failedTotal ? 1 : 0);
}

async function runMode(hpos) {
    console.log(`\nHPOS ${hpos === 'yes' ? 'on' : 'off'}`);
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
            failedTotal++;
            console.log(`  FAIL  ${name}\n        ${error.message}`);
        }
    }
    const expect = (condition, message) => {
        if (!condition) throw new Error(message);
    };
    const short = (value) => String(JSON.stringify(value)).slice(0, 700);
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

    /** FluentCRM's contacts (email => status, names, tags, lists, custom fields), its tags, lists and fields, and the sources' own state. */
    async function crm() {
        return php(`global $wpdb; $p = $wpdb->prefix;
        $contacts = [];
        foreach ($wpdb->get_results("SELECT * FROM {$p}fc_subscribers ORDER BY email", ARRAY_A) as $r) {
            $tags = $wpdb->get_col($wpdb->prepare("SELECT t.title FROM {$p}fc_subscriber_pivot x JOIN {$p}fc_tags t ON t.id = x.object_id WHERE x.subscriber_id = %d AND x.object_type LIKE %s ORDER BY t.title", $r['id'], '%Tag'));
            $lists = $wpdb->get_col($wpdb->prepare("SELECT t.title FROM {$p}fc_subscriber_pivot x JOIN {$p}fc_lists t ON t.id = x.object_id WHERE x.subscriber_id = %d AND x.object_type LIKE %s ORDER BY t.title", $r['id'], '%Lists'));
            $custom = [];
            foreach ($wpdb->get_results($wpdb->prepare("SELECT \`key\`, value FROM {$p}fc_subscriber_meta WHERE subscriber_id = %d AND object_type = 'custom_field'", $r['id']), ARRAY_A) as $m) { $custom[$m['key']] = $m['value']; }
            $contacts[$r['email']] = ['id' => (int) $r['id'], 'status' => $r['status'], 'first' => $r['first_name'], 'last' => (string) $r['last_name'], 'phone' => (string) $r['phone'], 'city' => (string) $r['city'],
                'type' => $r['contact_type'], 'source' => (string) $r['source'], 'user_id' => (int) $r['user_id'], 'ip' => (string) $r['ip'], 'tags' => $tags, 'lists' => $lists, 'custom' => $custom];
        }
        $fields = array_column((array) fluentcrm_get_option('contact_custom_fields', []), 'slug');
        echo json_encode([
            'contacts' => $contacts,
            'tags' => $wpdb->get_col("SELECT title FROM {$p}fc_tags ORDER BY title"),
            'lists' => $wpdb->get_col("SELECT title FROM {$p}fc_lists ORDER BY title"),
            'fields' => $fields,
            'campaign_emails' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}fc_campaign_emails"),
            'mail' => (int) get_option('crm_test_mail', 0),
            'sources' => md5(json_encode([
                $wpdb->get_results("SELECT * FROM {$p}mailpoet_subscribers ORDER BY id", ARRAY_A),
                $wpdb->get_results("SELECT subscriber_id, segment_id, status FROM {$p}mailpoet_subscriber_segment ORDER BY id", ARRAY_A),
                $wpdb->get_results("SELECT id, name, type FROM {$p}mailpoet_segments ORDER BY id", ARRAY_A),
                $wpdb->get_results("SELECT * FROM {$p}newsletter ORDER BY id", ARRAY_A),
                get_option('newsletter_lists'),
                array_map(fn ($o) => [$o->get_id(), $o->get_status(), $o->get_total(), $o->get_billing_email()], wc_get_orders(['limit' => -1, 'type' => 'shop_order', 'status' => 'any', 'orderby' => 'ID', 'order' => 'ASC'])),
            ])),
            'active' => get_option('active_plugins'),
            'hpos' => \\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled(),
        ]);`);
    }

    // ---- Seed the sources.
    const seed = await (async () => {
        const out = await cli.playground.run({ code: readFileSync(resolve(HERE, 'crm-switch/seed.php'), 'utf8').replace('<?php', `<?php $GLOBALS['crm_test_hpos'] = '${hpos}';`) });
        const text = out.text.trim();
        try {
            return JSON.parse(text.slice(text.lastIndexOf('{"orders"')));
        } catch {
            throw new Error(`seed failed: ${text.slice(-1500)}`);
        }
    })();
    const start = await crm();

    await check('seed: WooCommerce, MailPoet, Newsletter and the existing FluentCRM contacts are in place', async () => {
        expect(!seed.errors.length, short(seed.errors));
        expect(seed.hpos_enabled === (hpos === 'yes'), `HPOS ${seed.hpos_enabled}`);
        expect(Object.keys(seed.orders).length === 8 && seed.mailpoet === 7 && seed.newsletter === 4 && seed.fluentcrm === 2, short(seed));
        expect(same(Object.keys(start.contacts), ['bea@example.com', 'erin@example.com']), short(start.contacts));
        // The seed's own order emails (and the stand-in welcome emails) were counted, so the counter works.
        expect(start.mail > 0, `mail counter ${start.mail}`);
    });

    let dry;
    await check('dry run: reports each source, the statuses and what would be created, and changes nothing', async () => {
        dry = await wp('wp waas crm switch --dry-run --format=json --user=admin');
        keep(`hpos-${hpos}-dry-run.json`, dry);
        const p = dry.preflight;
        expect(p.ready && p.fluentcrm === '3.3.1' && p.sources.woocommerce.active && p.sources.woocommerce.hpos === (hpos === 'yes'), short(p));
        expect(p.sources.mailpoet.found && p.sources.newsletter.found, short(p.sources));
        const w = dry.sources.woocommerce;
        expect(w.orders === 7 && w.buyers === 7 && w.registered === 2 && w.guests === 5 && w.opted_in === 1 && w.refunded_only === 1, `woocommerce ${short(w)}`);
        expect(same(w.products, { 'Cap: Past': 3, 'Hoodie: Past': 1, 'Mug: Past': 3 }), short(w.products));
        const m = dry.sources.mailpoet;
        expect(m.subscribers === 6 && m.trashed === 1 && same(m.by_status, { bounced: 1, inactive: 1, subscribed: 2, unconfirmed: 1, unsubscribed: 1 }), `mailpoet ${short(m)}`);
        expect(m.lists.Weekly === 4 && m.lists.Offers === 1 && m.not_copied.length >= 1, short(m.lists));
        const n = dry.sources.newsletter;
        expect(n.subscribers === 3 && n.skipped === 1 && n.lists.Members === 3, `newsletter ${short(n)}`);
        const c = dry.contacts;
        expect(c.total === 14 && c.created === 12 && c.updated === 2 && c.unchanged === 0, `contacts ${short(c)}`);
        expect(same(c.by_status, { bounced: 1, pending: 1, subscribed: 4, transactional: 4, unsubscribed: 4 }), short(c.by_status));
        expect(dry.consent.without_record === 5 && dry.consent.without_record_status === 'transactional', short(dry.consent));
        expect(same(dry.tags.created.sort(), ['Cap: Past', 'Hoodie: Past', 'Mug: Past']) && same(dry.lists.created.sort(), ['Members', 'Offers']) && same(dry.lists.existing, ['Weekly']), short([dry.tags, dry.lists]));
        expect(same(dry.fields.created, ['woocommerce_orders', 'woocommerce_spent']), short(dry.fields));
        expect(dry.rows.every((r) => r.action.startsWith('will be')), short(dry.rows));
        expect(!dry.rows.some((r) => ['pam@example.com', 'tom@example.com', 'tess@example.com'].includes(r.email)), 'a non-buyer, a trashed or a temporary subscriber is listed');
        const after = await crm();
        expect(same(after.contacts, start.contacts) && same(after.tags, start.tags) && same(after.lists, start.lists) && same(after.fields, start.fields), `changed: ${short(after)}`);
    });

    await check('without FluentCRM: the check says to install it and the switch refuses', async () => {
        await php(`deactivate_plugins('fluent-crm/fluent-crm.php');`);
        const off = await php(`echo json_encode(WaaSClient\\Crm\\CrmSwitch::preflight());`);
        expect(off.ready === false && off.blockers.some((b) => b.includes('FluentCRM isn')), short(off));
        const refused = await php(`$r = (new WaaSClient\\Crm\\CrmSwitch())->run(); echo json_encode(is_wp_error($r) ? ['code' => $r->get_error_code()] : ['report' => true]);`);
        expect(refused.code === 'waas_crm_not_ready', short(refused));
        const activated = await php(`$r = activate_plugin('fluent-crm/fluent-crm.php'); echo json_encode(['error' => is_wp_error($r) ? $r->get_error_message() : null]);`);
        expect(!activated.error, short(activated));
    });

    let now;
    await check('switch: everyone comes in with the right status, tags, lists and fields, and no email is sent', async () => {
        const before = await crm();
        const table = await wp('wp waas crm switch --user=admin');
        keep(`hpos-${hpos}-switch.txt`, table);
        expect(/(\d+) email\(s\) that something tried to send during the import were held back/.exec(table)?.[1] === '12', `held: ${table.slice(-600)}`);
        expect(table.includes('Contacts brought into FluentCRM') && !/database error|Fatal|Error:/.test(table), table.slice(-1500));
        now = await crm();
        const c = now.contacts;
        expect(Object.keys(c).length === 14, `contacts ${short(Object.keys(c))}`);
        const status = Object.fromEntries(Object.entries(c).map(([e, v]) => [e.split('@')[0], v.status]));
        expect(same(status, {
            alice: 'transactional', bea: 'unsubscribed', bob: 'bounced', carol: 'transactional', dave: 'unsubscribed', erin: 'subscribed', gina: 'transactional',
            gus: 'subscribed', ivy: 'unsubscribed', mia: 'subscribed', nell: 'subscribed', rita: 'transactional', sam: 'unsubscribed', uma: 'pending',
        }), short(status));
        const alice = c['alice@example.com'];
        expect(same(alice.tags, ['Cap: Past', 'Mug: Past']) && Number(alice.custom.woocommerce_orders) === 1 && Number(alice.custom.woocommerce_spent) === 25, short(alice));
        expect(alice.first === 'Alice' && alice.last === 'Anders' && alice.phone === '555-0100' && alice.city === 'Chicago' && alice.type === 'customer' && alice.user_id === seed.users.alice, short(alice));
        const carol = c['carol@example.com'];
        expect(carol && !c['carol.work@example.com'] && same(carol.tags, ['Hoodie: Past']) && carol.user_id === seed.users.carol, `Carol: ${short(carol)}`);
        expect(same(c['gus@example.com'].tags, ['Mug: Past']) && c['gina@example.com'].user_id === 0, short(c['gus@example.com']));
        const rita = c['rita@example.com'];
        expect(same(rita.tags, []) && Number(rita.custom.woocommerce_orders) === 0 && Number(rita.custom.woocommerce_spent) === 0, `refunded: ${short(rita)}`);
        expect(!c['pam@example.com'] && !c['tom@example.com'] && !c['tess@example.com'], 'pending buyer, trashed or temporary subscriber came in');
        const dave = c['dave@example.com'];
        expect(same(dave.tags, ['Cap: Past']) && same(dave.lists, []) && dave.first === 'Dave', `Dave: ${short(dave)}`);
        const mia = c['mia@example.com'];
        expect(same(mia.lists, ['Members', 'Offers', 'Weekly']) && mia.last === 'Moss' && mia.type === 'lead' && mia.source === 'MailPoet, Newsletter' && mia.ip === '10.0.0.1', `Mia: ${short(mia)}`);
        expect(same(c['uma@example.com'].lists, ['Weekly']) && same(c['nell@example.com'].lists, ['Members']), short([c['uma@example.com'], c['nell@example.com']]));
        // Existing contacts: empty fields filled, the status kept, Interested swapped for Past.
        const erin = c['erin@example.com'];
        expect(erin.first === 'Erin' && erin.last === 'Ellis' && erin.phone === '555-0199' && erin.type === 'customer' && same(erin.tags, ['Mug: Past', 'VIP']), `Erin: ${short(erin)}`);
        const bea = c['bea@example.com'];
        expect(bea.status === 'unsubscribed' && bea.last === 'Back' && same(bea.lists, ['Weekly']), `Bea: ${short(bea)}`);
        expect(same(now.tags, ['Cap: Past', 'Hoodie: Past', 'Mug: Interested', 'Mug: Past', 'VIP']) && same(now.lists, ['Members', 'Offers', 'Weekly']), short([now.tags, now.lists]));
        expect(now.fields.includes('woocommerce_orders') && now.fields.includes('woocommerce_spent'), short(now.fields));
        expect(now.mail === before.mail && now.campaign_emails === 0, `email: ${before.mail} -> ${now.mail}, queued ${now.campaign_emails}`);
        expect(now.sources === start.sources, 'a source plugin\'s data changed');
        expect(['woocommerce/woocommerce.php', 'mailpoet/mailpoet.php', 'newsletter/plugin.php'].every((f) => now.active.includes(f)), short(now.active));
        expect(now.hpos === (hpos === 'yes'), `HPOS changed: ${now.hpos}`);
    });

    await check('running it again changes nothing', async () => {
        const again = await wp('wp waas crm switch --format=json --user=admin');
        keep(`hpos-${hpos}-again.json`, again);
        expect(again.contacts.created === 0 && again.contacts.updated === 0 && again.contacts.unchanged === 14, short(again.contacts));
        expect(again.tags.created.length === 3 && again.tags.existing.length === 0, 'tags created by the first run are still reported as created by it');
        const after = await crm();
        expect(same(after.contacts, now.contacts) && same(after.tags, now.tags) && same(after.lists, now.lists), 'FluentCRM changed on the second run');
    });

    await check('abilities: check-crm is read-only, switch-crm needs an administrator', async () => {
        const before = await crm();
        const checked = await ability('anypress/check-crm', { without_consent: 'unsubscribed' }, { method: 'GET' });
        expect(checked.status === 200 && checked.data.dry_run === true && checked.data.consent.without_record_status === 'unsubscribed', `check-crm: HTTP ${checked.status} ${short(checked.data)}`);
        expect(checked.data.contacts.unchanged === 14, short(checked.data.contacts));
        const editor = await ability('anypress/switch-crm', { undo: false }, { as: 'editor' });
        expect(editor.status === 403, `editor: HTTP ${editor.status}`);
        const nobody = await ability('anypress/check-crm', undefined, { as: null, method: 'GET' });
        expect(nobody.status === 401, `signed out: HTTP ${nobody.status}`);
        const run = await ability('anypress/switch-crm', { undo: false });
        expect(run.status === 200 && run.data.contacts.created === 0 && run.data.contacts.updated === 0, `switch-crm: HTTP ${run.status} ${short(run.data)}`);
        const after = await crm();
        expect(same(after.contacts, before.contacts), 'FluentCRM changed');
    });

    await check('undo removes only what the switch made and puts the existing contacts back; switching again redoes it', async () => {
        const preview = await wp('wp waas crm switch --undo --dry-run --format=json --user=admin');
        keep(`hpos-${hpos}-undo-preview.json`, preview);
        expect(preview.contacts_removed === 12 && (await crm()).contacts['alice@example.com'], short(preview));
        const undo = await wp('wp waas crm switch --undo --format=json --user=admin');
        keep(`hpos-${hpos}-undo.json`, undo);
        expect(undo.contacts_removed === 12 && undo.contacts_restored === 2 && undo.tags_removed === 3 && undo.lists_removed === 2 && undo.fields_removed === 2, short(undo));
        const undone = await crm();
        expect(same(undone.contacts, start.contacts), `contacts: ${short(undone.contacts)} (before ${short(start.contacts)})`);
        expect(same(undone.tags, start.tags) && same(undone.lists, start.lists) && same(undone.fields, start.fields), short([undone.tags, undone.lists, undone.fields]));
        expect(undone.sources === start.sources, 'a source plugin\'s data changed');
        const redo = await ability('anypress/switch-crm', {});
        expect(redo.status === 200 && redo.data.contacts.created === 12 && redo.data.contacts.updated === 2, short(redo.data.contacts));
        const after = await crm();
        expect(same(after.contacts['erin@example.com'].tags, ['Mug: Past', 'VIP']) && Object.keys(after.contacts).length === 14, short(after.contacts['erin@example.com']));
        expect(after.mail === start.mail && after.campaign_emails === 0, `email: ${start.mail} -> ${after.mail}`);
    });

    await check('no PHP warnings or errors from WaaS Client', async () => {
        const log = await php(`$f = WP_CONTENT_DIR . '/debug.log'; echo json_encode(['log' => file_exists($f) ? file_get_contents($f) : '']);`);
        keep(`hpos-${hpos}-debug.log`, log.log);
        const ours = log.log.split('\n').filter((line) => /waas-client|WaaSClient/.test(line));
        expect(!ours.length, ours.slice(0, 5).join('\n'));
    });

    if (SERVE) {
        console.log(`Left running at ${base} (Ctrl+C to stop).`);
        await new Promise(() => {});
    }
    await cli[Symbol.asyncDispose]();
}
