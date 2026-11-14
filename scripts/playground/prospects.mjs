// AnyPress Host's prospects route (src/Api/ProspectsReceiver.php) on WordPress Playground with FluentCRM and the shop's
// CRM files (scripts/test-storefront/remote: anypress-crm.php, crm.php, crm-emails.json): the API's signed call adds a
// researched business named after its person, with Customer Group, Job Title and Outreach Route in their fields and the
// business as a FluentCRM company; a shared inbox keeps the business name; a lifecycle tag takes "Prospect" off; a plan
// going Active takes "Beta: Interested" off; and crm.php's Title Case renames and new drafts.
//
//   npm run prospects                      # FluentCRM as the shop runs it (3.2.6)
//   FLUENT_CRM=3.3.1 npm run prospects     # another release

import { createHmac, randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const HERE = dirname(fileURLToPath(import.meta.url));
const HOST = resolve(HERE, '../../packages/anypress-host');
const REMOTE = resolve(HERE, '../test-storefront/remote');
const CRM_VERSION = process.env.FLUENT_CRM || '3.2.6';
// A test secret only, made up here.
const SECRET = `whsec_${Buffer.alloc(24, 7).toString('base64')}`;

const cli = await runCLI({
    command: 'server',
    wp: `https://wordpress.org/wordpress-${process.env.PLAYGROUND_WP || '7.1.4'}.zip`,
    php: '8.3',
    port: Number(process.env.PLAYGROUND_PORT || 9442),
    blueprint: {
        steps: [
            { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_LOG: '/tmp/debug.log', WP_DEBUG_DISPLAY: false } },
            { step: 'installTheme', themeData: { resource: 'literal', name: 'anypress.zip', contents: readFileSync(resolve(HERE, 'preview-zips/anypress.zip')) }, options: { activate: true } },
            { step: 'installPlugin', pluginData: { resource: 'url', url: `https://downloads.wordpress.org/plugin/fluent-crm.${CRM_VERSION}.zip` }, options: { activate: true } },
            { step: 'activatePlugin', pluginPath: 'anypress-host/anypress-host.php' },
            { step: 'mkdir', path: '/wordpress/wp-content/mu-plugins' },
            { step: 'cp', fromPath: '/wordpress/crm-remote/anypress-crm.php', toPath: '/wordpress/wp-content/mu-plugins/anypress-crm.php' },
        ],
    },
    mount: [
        { hostPath: HOST, vfsPath: '/wordpress/wp-content/plugins/anypress-host' },
        { hostPath: REMOTE, vfsPath: '/wordpress/crm-remote' },
    ],
    verbosity: 'quiet',
});
const base = cli.serverUrl.replace(/\/$/, '');
const php = async (code) => (await cli.playground.run({ code: `<?php require '/wordpress/wp-load.php'; ${code}` })).text;

let failed = false;
const check = (ok, what, detail = '') => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${what}${ok || !detail ? '' : `: ${detail}`}`);
    if (!ok) failed = true;
};

// An automation named the old way, as the shop has it, so the rename is checked too.
const setup = await php(`
    update_option(\\AnyPressHost\\Features\\Webhooks::OPTION, ['id' => 'ep-1', 'secret' => \\AnyPressHost\\Core\\SecretBox::seal('${SECRET}', 'anypress-host-webhook-secret')], false);
    \\FluentCrm\\App\\Models\\Funnel::create(['title' => 'Email: Nurture shops', 'trigger_name' => 'user_register', 'status' => 'published', 'settings' => [], 'conditions' => []]);
    wp_set_current_user(1);
    ob_start(); include '/wordpress/crm-remote/crm.php'; $out = ob_get_clean();
    echo 'FluentCRM ' . FLUENTCRM_PLUGIN_VERSION . ', AnyPress Host ' . ANYPRESS_HOST_VERSION . "\\n" . $out;
`);
console.log(setup);
check(setup.includes('Companies: on'), 'crm.php turns on Companies');
check(setup.includes('Email: Nurture shops renamed Email: Nurture Online Shops and Niche Sellers'), 'crm.php renames an old automation to Title Case');
check(setup.includes('Email: Care Welcome added as a draft') && setup.includes('Email: Nurture WordPress Hosts added as a draft'), 'crm.php adds the new drafts');

const fields = JSON.parse(await php(`echo json_encode(array_map(fn ($f) => [$f['slug'], $f['label'], $f['type'], count($f['options'] ?? [])], fluentcrm_get_option('contact_custom_fields', [])));`));
const label = (slug) => fields.find((f) => f[0] === slug);
check(label('customer_group')?.[1] === 'Customer Group' && label('customer_group')?.[2] === 'select-one' && label('customer_group')?.[3] === 13, 'Customer Group is a Title Case dropdown with 13 groups', JSON.stringify(fields));
check(label('job_title')?.[1] === 'Job Title' && label('outreach_route')?.[3] === 4, 'Job Title and Outreach Route are there', JSON.stringify(fields));

const sign = (id, timestamp, body) => `v1,${createHmac('sha256', Buffer.from(SECRET.slice(6), 'base64')).update(`${id}.${timestamp}.${body}`).digest('base64')}`;
async function send(body) {
    const text = JSON.stringify(body);
    const id = `pro_${randomUUID()}`;
    const timestamp = Math.floor(Date.now() / 1000);
    const res = await fetch(`${base}/wp-json/anypress-host/v1/prospects`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'webhook-id': id, 'webhook-timestamp': String(timestamp), 'webhook-signature': sign(id, timestamp, text) },
        body: text,
    });
    return { status: res.status, body: await res.json().catch(() => null) };
}
const till = { id: 'p1', name: 'Till Co', email: 'dana@till.anypress.example', website: 'https://till.anypress.example/', phone: '555-0100', type: 'Point of sale software', person: 'Dana Rivera', jobTitle: 'Head of Partnerships', customerGroup: 'Point of Sale Companies', route: 'Affiliate', tags: ['Prospect'] };
const inbox = { id: 'p2', name: 'Book Studio', email: 'hello@book.anypress.example', customerGroup: 'Not A Group', route: 'Affiliate', tags: ['Prospect'] };
const first = await send({ contacts: [till, inbox] });
check(first.status === 200 && first.body?.added === 2, 'the signed call adds both businesses', JSON.stringify(first.body));

const read = async (email) =>
    JSON.parse(
        await php(`$c = \\FluentCrm\\App\\Models\\Subscriber::where('email', '${email}')->first(); $f = (array) $c->custom_fields();
            echo json_encode(['first' => $c->first_name, 'last' => $c->last_name, 'status' => $c->status, 'group' => $f['customer_group'] ?? '', 'job' => $f['job_title'] ?? '', 'route' => $f['outreach_route'] ?? '',
                'tags' => $c->tags->pluck('title')->all(), 'company' => $c->company ? $c->company->name : '', 'site' => $c->company ? $c->company->website : '']);`),
    );
const dana = await read(till.email);
check(dana.first === 'Dana' && dana.last === 'Rivera', 'a known person names the contact', JSON.stringify(dana));
check(dana.group === 'Point of Sale Companies' && dana.job === 'Head of Partnerships' && dana.route === 'Affiliate', 'group, job title and route are in their fields', JSON.stringify(dana));
check(dana.company === 'Till Co' && dana.site === till.website, 'the business is a company the contact is linked to', JSON.stringify(dana));
check(dana.status === 'transactional' && JSON.stringify(dana.tags) === '["Prospect"]', 'a new prospect is transactional with only the Prospect tag', JSON.stringify(dana));
const studio = await read(inbox.email);
check(studio.first === 'Book Studio' && studio.group === '', 'a shared inbox keeps the business name, and an unknown group is left out', JSON.stringify(studio));

// The 2026-11-14 contacts were named after the business: sending again with the person renames them, once.
const again = await send({ contacts: [{ ...inbox, person: 'Sam Lee', customerGroup: 'Booking Software Companies' }] });
const sam = await read(inbox.email);
check(again.body?.updated === 1 && sam.first === 'Sam' && sam.last === 'Lee' && sam.group === 'Booking Software Companies', 'sending again names the person and fills the empty group', JSON.stringify(sam));

const tagged = await php(`
    $c = \\FluentCrm\\App\\Models\\Subscriber::where('email', '${till.email}')->first();
    $c->attachTags([AnyPress_CRM::tag_id('Beta', 'Interested')]);
    $c->attachTags([AnyPress_CRM::tag_id('Website', 'Active')]);
    echo json_encode(\\FluentCrm\\App\\Models\\Subscriber::find($c->id)->tags->pluck('title')->all());
`);
check(tagged === '["Website: Active"]', 'a plan going Active takes off Prospect and Beta: Interested', tagged);

const mail = await php(`echo json_encode([
    \\AnyPressHost\\Api\\ProspectsReceiver::skip_prospect_mail(false, ['to' => ['email' => '${inbox.email}']]),
    \\AnyPressHost\\Api\\ProspectsReceiver::skip_prospect_mail(false, ['to' => ['email' => '${till.email}']]),
]);`);
check(mail === '[true,false]', 'FluentCRM still skips the prospect, and now emails the customer', mail);

const log = await php(`echo @file_get_contents('/tmp/debug.log') ?: '';`);
const errors = log.split('\n').filter((l) => /PHP (Fatal|Warning|Parse)/.test(l) && /anypress/i.test(l));
check(!errors.length, 'no PHP warnings from AnyPress code', errors.join('\n'));

await cli[Symbol.asyncDispose]?.();
console.log(failed ? '\nFAILED' : '\nAll prospect checks passed (FluentCRM ' + CRM_VERSION + ').');
process.exit(failed ? 1 : 0);
