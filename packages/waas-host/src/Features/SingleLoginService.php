<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;

class SingleLoginService
{
    public static function get_formatted_username($subscription_id)
    {
        $username = Commerce::require_adapter()->get_customer_name($subscription_id);
        return str_replace('-', '_', sanitize_title_with_dashes(remove_accents($username)));
    }

    public static function get_login_link($subscription_id)
    {
        return get_rest_url(null, PluginBootstrap::API_V1_NAMESPACE) . '/tenant/single_login?subscription_id=' . $subscription_id . "&_wpnonce=" . wp_create_nonce('wp_rest');
    }
}
