<?php
/**
 * The shop's share of the AnyPress store template's presets (waas-provisioning-api
 * templates/anypress-store/presets.php): renewal reminders and the cart icon, a newsletter box at checkout, users
 * synced into FluentCRM, and FluentAffiliate tracking FluentCart orders. Safe to run again.
 *   wp eval-file defaults.php --user=admin
 */
if (class_exists('\FluentCart\Api\StoreSettings')) {
    // Settings → Reminders (nothing is sent while the master switch is off) and the cart icon in the menu.
    (new \FluentCart\Api\StoreSettings())->save([
        'show_cart_icon_in_nav' => 'yes',
        'reminders_enabled' => 'yes',
        'monthly_renewal_reminders_enabled' => 'yes',
    ]);
    echo "FluentCart: reminders and cart icon on\n";
}

if (defined('FLUENTCRM')) {
    $newsletter = \FluentCrm\App\Models\Lists::firstOrCreate(['slug' => 'newsletter'], ['title' => 'Newsletter']);
    \FluentCrm\App\Models\Lists::firstOrCreate(['slug' => 'customers'], ['title' => 'Customers']);
    foreach (['Customer', 'Lead', 'Subscriber'] as $title) {
        \FluentCrm\App\Models\Tag::firstOrCreate(['slug' => sanitize_title($title)], ['title' => $title]);
    }
    fluentcrm_update_option('fluent_cart_checkout_form_subscribe_settings', array_merge(
        (new \FluentCrm\App\Services\AutoSubscribe())->getFluentCartCheckoutSettings(),
        ['status' => 'yes', 'auto_checked' => 'no', 'double_optin' => 'yes', 'show_only_new' => 'yes', 'target_list' => (string) $newsletter->id]
    ));
    fluentcrm_update_option('user_syncing_settings', ['status' => 'yes', 'delete_contact_on_user_delete' => 'no']);
    echo "FluentCRM: checkout newsletter box and user sync on\n";
}

if (class_exists('\FluentAffiliate\App\Helper\Utility')) {
    $utility = '\FluentAffiliate\App\Helper\Utility';
    $utility::updateOption('_fluent_cart_connector_config', array_merge((array) $utility::getOption('_fluent_cart_connector_config', []), ['is_enabled' => 'yes']));
    $referral = $utility::getReferralSettings(false);
    $utility::updateReferralSettings([
        'enabled_integrations' => array_values(array_unique(array_merge((array) ($referral['enabled_integrations'] ?? []), ['fluent_cart']))),
    ]);
    echo "FluentAffiliate: FluentCart integration on\n";
}
