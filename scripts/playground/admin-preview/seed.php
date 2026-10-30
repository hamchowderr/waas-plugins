<?php
// Test only: a storefront that sells websites, for previewing the AnyPress admin pages. Connected (the API calls are
// answered here, so nothing leaves Playground), with sample customers and websites in every state.
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'https://api.anypress.io/') !== 0) {
        return $pre;
    }

    return ['headers' => [], 'body' => '{"status":"ok","data":[]}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

add_action('init', function () {
    if (get_option('waas_admin_preview_seeded') || !class_exists('\FluentCart\App\Models\Customer')) {
        return;
    }
    update_option('waas_admin_preview_seeded', 1);

    update_option('blogname', 'Summit Sites');
    update_option('waas_api_base_url', 'https://api.anypress.io');
    update_option('waas_api_key', 'ak_live_7f3c9a21');
    update_option('waas_api_secret', 'preview-secret');
    update_option('waas_root_domain', 'anypress.site');
    update_option('waas_dns_a_records', ['203.0.113.10']);
    update_option('waas_dns_cname_target', 'stores.anypress.site');
    update_option(\WaaSHost\Core\ProvisioningApi::CAN_REACH_API_OPTION_NAME, true);
    update_option(\WaaSHost\Features\PluginBootstrap::ROLES_WP_OPTION, ['website' => ['name' => 'Website'], 'store' => ['name' => 'Store']]);
    update_option(\WaaSHost\Features\Webhooks::OPTION, ['id' => 'wh_preview', 'secret' => 'preview', 'url' => home_url('/wp-json/waas-host/v1/webhook'), 'last_event_at' => time() - 240, 'error' => '']);
    update_option('waas_volume_discount_enabled', 'on');

    $now = time();
    $sites = [
        ['Maya Patel', 'Juniper Street Bakery', 'juniperstreetbakery', 'Ready', 'Website Small', null],
        ['Luis Ortega', 'Fern and Clay', 'fernandclay', 'Provisioning', 'Store Small', null],
        ['Grace Kim', 'Northside Yoga', 'northsideyoga', 'Failed', 'Bookings Small', ['error' => 'The website could not be created: the server is busy. It will be tried again.', 'attempts' => 6, 'at' => $now - 3600, 'job' => 'create']],
        ['Sam Rivers', 'Rivers Plumbing', 'riversplumbing', 'Ready', 'Website Medium', null],
        ['Ada Brooks', 'Brooks Law Office', 'brookslaw', 'Ready', 'Everything Small', null],
        ['Theo Nash', 'Nash Fitness', 'nashfitness', 'Suspended', 'Community & courses Small', null],
        ['Ivy Chen', 'Chen Family Dental', 'chendental', 'Ready', 'Website Small', null],
    ];
    foreach ($sites as $i => [$person, $name, $slug, $state, $plan, $failure]) {
        [$first, $last] = explode(' ', $person);
        $customer = \FluentCart\App\Models\Customer::create(['email' => $slug . '@example.com', 'first_name' => $first, 'last_name' => $last, 'status' => 'active']);
        $sub = \FluentCart\App\Models\Subscription::create([
            'customer_id' => $customer->id, 'parent_order_id' => 0, 'product_id' => 0, 'variation_id' => 0, 'item_name' => $plan,
            'currency' => 'USD', 'billing_interval' => 'monthly', 'recurring_amount' => 1200, 'recurring_total' => 1200,
            'status' => $state === 'Suspended' ? 'canceled' : 'active', 'next_billing_date' => gmdate('Y-m-d H:i:s', $now + 20 * 86400),
        ]);
        $sub->updateMeta('waas_website_name', $name);
        $sub->updateMeta('waas_domain_name', $slug . '.anypress.site');
        $sub->updateMeta('waas_tenant_state', $state);
        $sub->updateMeta('waas_tenant_external_id', 'preview-' . $i);
        if ($failure) {
            $sub->updateMeta('waas_store_job_failure', $failure);
        }
    }
}, 99);
