<?php
require '/wordpress/wp-load.php';
wp_set_current_user(1);
// Seed a Gravity Forms form as Gravity Forms stores it (form object, fields, confirmations, notifications, limits),
// a Mailchimp add-on feed, three entries (one with an empty address line), and pages that show the form.
$choices = fn (...$l) => array_map(fn ($x) => ['text' => $x, 'value' => $x, 'isSelected' => false], $l);
$base = ['isRequired' => false, 'cssClass' => '', 'labelPlacement' => '', 'description' => '', 'placeholder' => '', 'defaultValue' => '', 'adminLabel' => '', 'maxLength' => '', 'layoutGroupId' => ''];
$field = fn ($id, $type, $label, $extra = []) => array_merge($base, ['id' => $id, 'type' => $type, 'label' => $label, 'layoutGroupId' => 'g' . $id], $extra);
$form = [
    'id' => 1,
    'title' => 'GF Kitchen Sink',
    'button' => ['type' => 'text', 'text' => 'Send it', 'imageUrl' => ''],
    'fields' => [
        $field(1, 'name', 'Name', ['isRequired' => true, 'nameFormat' => 'advanced', 'inputs' => [
            ['id' => '1.2', 'label' => 'Prefix', 'isHidden' => true],
            ['id' => '1.3', 'label' => 'First'],
            ['id' => '1.4', 'label' => 'Middle', 'isHidden' => true],
            ['id' => '1.6', 'label' => 'Last'],
            ['id' => '1.8', 'label' => 'Suffix', 'isHidden' => true],
        ]]),
        $field(2, 'email', 'Email', ['isRequired' => true]),
        $field(3, 'phone', 'Phone', ['phoneFormat' => 'standard']),
        $field(4, 'text', 'Company', ['maxLength' => '80']),
        $field(5, 'textarea', 'Message', ['maxLength' => '']),
        $field(6, 'select', 'Topic', ['choices' => $choices('Sales', 'Support', 'Billing')]),
        $field(7, 'checkbox', 'Interests', ['choices' => $choices('Anime', 'Gaming', 'Music'), 'inputs' => [['id' => '7.1', 'label' => 'Anime'], ['id' => '7.2', 'label' => 'Gaming'], ['id' => '7.3', 'label' => 'Music']]]),
        $field(8, 'radio', 'Contact method', ['choices' => $choices('Email', 'Phone')]),
        $field(9, 'date', 'Event date', ['dateFormat' => 'dmy', 'dateType' => 'datepicker']),
        $field(10, 'time', 'Start time', ['timeFormat' => '24', 'inputs' => [['id' => '10.1', 'label' => 'HH'], ['id' => '10.2', 'label' => 'MM']]]),
        $field(11, 'number', 'Guests', ['rangeMin' => '1', 'rangeMax' => '10']),
        $field(12, 'address', 'Address', ['isRequired' => true, 'inputs' => [
            ['id' => '12.1', 'label' => 'Street Address'], ['id' => '12.2', 'label' => 'Address Line 2'], ['id' => '12.3', 'label' => 'City'],
            ['id' => '12.4', 'label' => 'State / Province'], ['id' => '12.5', 'label' => 'ZIP / Postal Code'], ['id' => '12.6', 'label' => 'Country', 'isHidden' => true],
        ]]),
        $field(13, 'website', 'Website'),
        $field(14, 'hidden', 'Source', ['defaultValue' => 'gf-landing']),
        $field(15, 'html', 'Intro', ['content' => '<p>GF HTML block</p>']),
        $field(16, 'section', 'More about you', ['description' => 'Optional']),
        $field(17, 'text', 'Referral code', ['conditionalLogic' => ['actionType' => 'show', 'logicType' => 'all', 'rules' => [['fieldId' => '6', 'operator' => 'is', 'value' => 'Sales']]]]),
        $field(18, 'consent', 'I agree to the privacy policy', ['isRequired' => true, 'checkboxLabel' => 'I agree', 'inputs' => [['id' => '18.1', 'label' => 'Consent'], ['id' => '18.2', 'label' => 'Text', 'isHidden' => true], ['id' => '18.3', 'label' => 'Description', 'isHidden' => true]]]),
        $field(19, 'fileupload', 'Brief', ['allowedExtensions' => 'pdf', 'maxFileSize' => '2']),
        $field(20, 'list', 'Team', ['choices' => null]),
    ],
    'confirmations' => [
        'c1' => ['id' => 'c1', 'name' => 'Sales redirect', 'isDefault' => false, 'type' => 'redirect', 'url' => 'https://anypress.example/sales', 'queryString' => '',
            'conditionalLogic' => ['actionType' => 'show', 'logicType' => 'all', 'rules' => [['fieldId' => '6', 'operator' => 'is', 'value' => 'Sales']]], 'confirmation_conditional_logic' => true],
        'c2' => ['id' => 'c2', 'name' => 'Default Confirmation', 'isDefault' => true, 'type' => 'message', 'message' => 'CUSTOM-GF-CONFIRM: thanks {Name (First):1.3}!'],
    ],
    'notifications' => [
        'n1' => ['id' => 'n1', 'name' => 'Admin Notification', 'isActive' => true, 'event' => 'form_submission', 'toType' => 'email', 'to' => '{admin_email}',
            'subject' => 'GF lead {Name (First):1.3}', 'message' => '{all_fields}', 'replyTo' => '{Email:2}', 'from' => '{admin_email}', 'fromName' => 'Site', 'bcc' => ''],
        'n2' => ['id' => 'n2', 'name' => 'Saved for later', 'isActive' => true, 'event' => 'form_saved', 'toType' => 'email', 'to' => '{admin_email}', 'subject' => 'Saved', 'message' => 'Saved'],
    ],
    'limitEntries' => true, 'limitEntriesCount' => '100', 'limitEntriesPeriod' => 'month', 'limitEntriesMessage' => 'GF-LIMIT: full this month.',
    'requireLogin' => false, 'scheduleForm' => false, 'enableHoneypot' => true,
];
update_option('gf_test_forms', [$form], false);
update_option('gf_test_feeds', [['id' => 1, 'form_id' => 1, 'addon_slug' => 'gravityformsmailchimp', 'is_active' => 1, 'meta' => ['feedName' => 'Newsletter']]], false);

$entries = [];
foreach ([1, 2, 3] as $i) {
    $entries[] = [
        'id' => (string) (100 + $i), 'form_id' => '1', 'status' => 'active', 'is_starred' => $i === 2 ? '1' : '0', 'is_read' => '1',
        'date_created' => sprintf('2026-03-0%d 09:0%d:00', $i, $i), 'date_updated' => sprintf('2026-03-0%d 09:0%d:00', $i, $i),
        'ip' => "203.0.113.$i", 'user_agent' => "TestBrowser/$i", 'source_url' => "https://anypress.example/contact?from=$i",
        '1.3' => "Tester$i", '1.6' => "Last$i", '2' => "tester$i@anypress.example", '3' => "(555) 010-000$i", '4' => "Company $i",
        '5' => "Hello number $i", '6' => 'Support', '7.1' => 'Anime', '7.3' => $i === 1 ? 'Music' : '', '8' => 'Phone',
        '9' => "0$i/04/2026", '10' => "1$i:30", '11' => (string) $i,
        '12.1' => "$i Main St", '12.2' => $i === 2 ? 'Suite 5' : '', '12.3' => 'Atlanta', '12.4' => 'GA', '12.5' => "3030$i", '12.6' => 'United States',
        '13' => "https://site$i.anypress.example", '14' => 'gf-landing', '17' => '', '18.1' => '1',
    ];
}
update_option('gf_test_entries', $entries, false);

$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'GF Contact',
    'post_content' => "<!-- wp:paragraph --><p>Talk to us.</p><!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[gravityform id=\"1\" title=\"false\" description=\"false\" ajax=\"true\"]\n<!-- /wp:shortcode -->"]);
$block = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'GF Block',
    'post_content' => "<!-- wp:gravityforms/form {\"formId\":\"1\",\"title\":false,\"description\":false} /-->\n\n<!-- wp:shortcode -->\n[gravityforms action=\"conditional\" merge_tag=\"{user:user_email}\" condition=\"isnot\" value=\"\"]Members only[/gravityforms]\n<!-- /wp:shortcode -->"]);
$draft = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'GF Draft', 'post_content' => '[gravityforms id=1]']);

echo "\n" . json_encode(['page' => $page, 'block' => $block, 'draft' => $draft]);
