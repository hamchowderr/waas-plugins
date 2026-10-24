<?php
/**
 * AnyPress's own CRM on the shop (Beads waas-api-gtb.1): the lists and contact fields the lifecycle tags rely on, and
 * the email automations from crm-emails.json, imported as drafts. Safe to run again: lists, fields and automations
 * that already exist (by slug or title) are left as they are, so edits made in FluentCRM survive.
 *   wp eval-file crm.php --user=admin
 *
 * Tags (Offering: Interested / Active / Past) are created when first used, by the anypress-crm.php mu-plugin, which
 * also starts the automations whose trigger is "manual" (free FluentCRM can't start an automation from a tag).
 * Nothing sends until an automation is published in FluentCRM.
 */
if (!defined('FLUENTCRM')) {
    echo "FluentCRM isn't active\n";
    return;
}
use FluentCrm\App\Models\Funnel;
use FluentCrm\App\Models\FunnelCampaign;
use FluentCrm\App\Models\Lists;

// Lists are consent: who asked for which kind of email.
$lists = [
    'newsletter' => ['Newsletter', 'Tips and news, about once a month. Sign-ups from anypress.io join this list.'],
    'product-updates' => ['Product updates', 'New features and changes to AnyPress.'],
];
foreach ($lists as $slug => [$title, $description]) {
    $list = Lists::where('slug', $slug)->first();
    if (!$list) {
        $list = Lists::create(['title' => $title, 'slug' => $slug, 'description' => $description]);
    }
    echo "List: $title is #{$list->id}\n";
}

// Contact fields: where they came from, which customer group they're in, and whether a full refund ended a plan.
$fields = fluentcrm_get_option('contact_custom_fields', []);
$have = array_column($fields, 'slug');
foreach (['source' => 'Source', 'customer_group' => 'Customer group', 'refunded' => 'Refunded'] as $slug => $label) {
    if (!in_array($slug, $have, true)) {
        $fields[] = ['field_key' => 'text', 'type' => 'text', 'label' => $label, 'slug' => $slug, 'group' => 'AnyPress'];
    }
}
(new \FluentCrm\App\Models\CustomContactField())->saveGlobalFields($fields);
echo 'Fields: ' . implode(', ', array_column($fields, 'slug')) . "\n";

// The automations, each a straight line: wait, email, wait, email.
$automations = json_decode(file_get_contents(__DIR__ . '/crm-emails.json'), true);
if (!is_array($automations)) {
    echo "crm-emails.json didn't parse\n";
    return;
}
$sender = [
    'from_name' => '', 'from_email' => '',
    'reply_to_name' => 'AnyPress', 'reply_to_email' => 'support@anypress.io', 'is_custom' => 'yes',
];
$made = 0;
foreach ($automations as $a) {
    if (Funnel::where('title', $a['title'])->exists()) {
        echo "Automation: {$a['title']} already there\n";
        continue;
    }
    $manual = $a['trigger'] === 'manual';
    $funnel = [
        'title' => $a['title'],
        // A manual automation sits on the user-registration trigger, limited to a role nobody has, and the mu-plugin
        // also refuses it there; anypress-crm.php starts it directly.
        'trigger_name' => $manual ? 'user_register' : $a['trigger'],
        'settings' => $manual ? ['subscription_status' => 'subscribed'] : $a['settings'],
        'conditions' => $manual ? ['update_type' => 'update', 'user_roles' => ['anypress_started_by_code']] : $a['conditions'],
        'sticky_note' => $a['note'],
    ];
    $sequences = [];
    $id = 1;
    $day = 0;
    foreach ($a['emails'] as $n => $email) {
        if ($email['day'] > $day) {
            $sequences[] = [
                'id' => $id++,
                'type' => 'action',
                'action_name' => 'fluentcrm_wait_times',
                'title' => 'Wait ' . ($email['day'] - $day) . ' days',
                'description' => '',
                'settings' => ['wait_type' => 'unit_wait', 'wait_time_amount' => $email['day'] - $day, 'wait_time_unit' => 'days'],
            ];
            $day = $email['day'];
        }
        $campaign = FunnelCampaign::getMock();
        $campaign['title'] = $email['subject'];
        $campaign['email_subject'] = $email['subject'];
        $campaign['email_pre_header'] = $email['preheader'];
        $campaign['email_body'] = $email['body'];
        $campaign['settings'] = (array) $campaign['settings'];
        $campaign['settings']['mailer_settings'] = $sender;
        $sequences[] = [
            'id' => $id++,
            'type' => 'action',
            'action_name' => 'send_custom_email',
            'title' => 'Email ' . ($n + 1) . ': ' . $email['subject'],
            'description' => '',
            'settings' => [
                'send_email_to_type' => 'contact',
                'send_email_custom' => '',
                'campaign' => $campaign,
                'is_scheduled' => 'no',
                'scheduled_at' => '',
                'skip_if_overdue' => 'no',
                'mailer_settings' => $sender,
            ],
        ];
    }
    $request = new WP_REST_Request('POST', '/fluent-crm/v2/funnels/import');
    $request->set_body_params(['funnel' => $funnel, 'sequences' => wp_json_encode($sequences)]);
    $response = rest_do_request($request);
    $data = $response->get_data();
    if ($response->is_error() || empty($data['funnel'])) {
        echo "Automation: {$a['title']} FAILED: " . (is_array($data) ? ($data['message'] ?? wp_json_encode($data)) : $data) . "\n";
        continue;
    }
    $made++;
    $emails = count($a['emails']);
    echo "Automation: {$a['title']} added as a draft ($emails emails)\n";
}
echo "Done: $made automations added. Publish each in FluentCRM > Automations once its emails are approved.\n";
