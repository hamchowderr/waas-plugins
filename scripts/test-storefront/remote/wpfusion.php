<?php
/**
 * Connects WP Fusion to FluentCRM on the same site, which is all its setup screen's Connect button does, plus the
 * default field map and the tag and list cache it loads right after. Same as the AnyPress store template's
 * per-store step (waas-provisioning-api templates/anypress-store/mu-plugins/anypress-store.php).
 *   wp eval-file wpfusion.php
 */
if (!function_exists('wp_fusion') || !class_exists('WPF_FluentCRM') || !defined('FLUENTCRM')) {
    echo "WP Fusion or FluentCRM not active\n";
    return;
}
if (wpf_get_option('connection_configured')) {
    echo "WP Fusion already connected to " . wpf_get_option('crm') . "\n";
    return;
}
$fields = (array) wpf_get_option('contact_fields', []);
foreach ([
    'first_name' => ['first_name', 'First Name', true],
    'last_name' => ['last_name', 'Last Name', true],
    'user_email' => ['email', 'Email', true],
    'phone_number' => ['phone', 'Phone', false],
    'billing_address_1' => ['address_line_1', 'Address 1', false],
    'billing_address_2' => ['address_line_2', 'Address 2', false],
    'billing_city' => ['city', 'City', false],
    'billing_state' => ['state', 'State', false],
    'billing_postcode' => ['postal_code', 'Zip', false],
    'billing_country' => ['country', 'Country', false],
] as $meta => [$crmField, $label, $active]) {
    $fields[$meta] = array_merge((array) ($fields[$meta] ?? []), ['crm_field' => $crmField, 'crm_label' => $label, 'active' => $active]);
}
wp_fusion()->settings->set_multiple([
    'crm' => 'fluentcrm',
    'connection_configured' => true,
    // New users become FluentCRM contacts, and profile changes follow them.
    'create_users' => true,
    'push' => true,
    'contact_fields' => $fields,
]);
(new WPF_FluentCRM())->sync();
echo "WP Fusion connected to FluentCRM\n";
