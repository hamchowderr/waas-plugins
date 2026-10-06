<?php

namespace WaaSHost\Features;

use Exception;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\EncryptionService;
use WaaSHost\Core\PrivateKeyStore;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;

class TenantsSubscriptionManager
{
    private WPCSService $wpcsService;

    public function __construct(WPCSService $wpcsService)
    {
        $this->wpcsService = $wpcsService;

        add_action('wpcs_subscription_created', [$this, 'create_tenant_when_subscription_created'], 10, 2);
        add_action('wpcs_subscription_expired', [$this, 'remove_tenant_when_subscription_expired']);
    }

    /**
     * @param mixed $purchase_context passed by the cart adapter; only the adapter reads it
     * @throws Exception
     */
    public function create_tenant_when_subscription_created($subscription_id, $purchase_context)
    {
        $commerce = Commerce::require_adapter();

        // abort early if the subscription already has a tenant external ID
        if(!empty($commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META)))
        {
            return;
        }

        $purchase = $commerce->get_plan_purchase($subscription_id, $purchase_context);

        if (empty($purchase->plans)) {
            return;
        }

        $group_name = "";
        $subscription_roles = [];
        $product_role = '';

        foreach ($purchase->plans as $plan) {
            $product_role = $plan->role;
            if (!empty($product_role)) {
                $subscription_roles[] = $product_role;
            }
            $group_name = empty($group_name) ? $plan->group_name : $group_name;
        }

        $website_name = sanitize_text_field($purchase->website_name);

        $keys = EncryptionService::generate_key_pair();

        $args = [
            'name' => $website_name,
            'wordpress_username' => SingleLoginService::get_formatted_username($subscription_id),
            'wordpress_email' => $purchase->customer_email,
            'wordpress_user_role' => get_option(WPCSTenant::WPCS_DEFAULT_USER_ROLE),
            'group_name' => ($group_name === false || empty($group_name)) ? null : $group_name,
            'php_constants' => [
                'WPCS_TENANT_ROLES' => ['value' => implode(",", $subscription_roles), 'isPrivate' => false],
                'WPCS_PUBLIC_KEY' => ['value' => base64_encode($keys['public_key']), 'isPrivate' => false],
                'WPCS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS' => [
                    'value' => apply_filters('wpcs_remove_tenant_administrator_plugin_capabilities', true) ? "true" : "false",
                    'isPrivate' => false,
                ]
            ]
        ];

        $tenant_root_domain = get_option('wpcs_host_settings_root_domain', '');
        if ($tenant_root_domain !== '') {
            $subdomain = sanitize_title_with_dashes(remove_accents($website_name));
            $args['custom_domain_name'] = $subdomain . '.' . $tenant_root_domain;
        }

        $new_tenant = $this->wpcsService->create_tenant($args);

        $domain_name = $new_tenant->customDomain ?? $new_tenant->baseDomain;

        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META, $new_tenant->externalId);
        PrivateKeyStore::save($subscription_id, $keys['private_key']);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META, $domain_name);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_BASE_DOMAIN_NAME_META, $new_tenant->baseDomain);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_SUBSCRIPTION_USER_ROLES, [$product_role]);

        do_action('wpcs_tenant_linked_to_subscription', $subscription_id);
    }

    public function remove_tenant_when_subscription_expired($subscription_id)
    {
        $tenant_external_id = Commerce::require_adapter()->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META);
        $this->wpcsService->delete_tenant([
            'external_id' => $tenant_external_id
        ]);
    }
}
