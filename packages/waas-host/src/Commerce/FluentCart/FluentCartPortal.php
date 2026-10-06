<?php

namespace WaaSHost\Commerce\FluentCart;

use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\SingleLoginService;
use WaaSHost\Features\UserAccountSubscriptionsSettings;

/**
 * The customer's websites in FluentCart's customer portal: a "Website" tab with status,
 * one-click login and the domain form, plus links on each subscription. The form lives
 * on its own tab because the subscription panel strips form inputs.
 */
class FluentCartPortal
{
    public const ENDPOINT = 'website';

    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    private UserAccountSubscriptionsSettings $domain_settings;

    public function __construct(FluentCartAdapter $adapter, FluentCartStore $store, UserAccountSubscriptionsSettings $domain_settings)
    {
        $this->adapter = $adapter;
        $this->store = $store;
        $this->domain_settings = $domain_settings;

        add_action('fluent_cart/init', [$this, 'register_endpoint']);
        add_filter('fluent_cart/customer_portal/subscription_data', [$this, 'add_subscription_actions'], 10, 2);
    }

    public function register_endpoint()
    {
        if (!function_exists('fluent_cart_api')) {
            return;
        }

        fluent_cart_api()->addCustomerDashboardEndpoint(self::ENDPOINT, [
            'title' => __('Website', WPCS_WAAS_HOST_TEXTDOMAIN),
            'render_callback' => [$this, 'render'],
        ]);
    }

    /** Subscriptions of the current user that have a website. @return int[] */
    public function current_user_websites(): array
    {
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            return [];
        }

        $user = wp_get_current_user();
        $ids = $this->store->find_user_subscription_ids($user_id, (string) $user->user_email);

        return array_values(array_filter($ids, function ($subscription_id) use ($user_id) {
            return $this->adapter->get_customer_id($subscription_id) === $user_id
                && !empty($this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META));
        }));
    }

    public function render()
    {
        $websites = $this->current_user_websites();
        if (!$websites) {
            echo '<p>' . esc_html__('You have no websites yet. They appear here a few minutes after you subscribe to a website plan.', WPCS_WAAS_HOST_TEXTDOMAIN) . '</p>';
            return;
        }

        foreach ($websites as $subscription_id) {
            $domain = $this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META)
                ?: $this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_BASE_DOMAIN_NAME_META);
            $status = $this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_STATE) ?: WPCSTenant::PROVISIONING;

            echo '<section class="wpcs-website">';
            echo '<h3>' . esc_html((string) $domain) . '</h3>';
            echo '<p>' . esc_html__('Status:', WPCS_WAAS_HOST_TEXTDOMAIN) . ' ' . esc_html((string) $status) . '</p>';
            if ($status === WPCSTenant::READY) {
                echo '<p><a class="fct-btn fct-btn-primary" target="_blank" rel="noopener" href="' . esc_url(SingleLoginService::get_login_link($subscription_id)) . '">'
                    . esc_html__('Log in to your website', WPCS_WAAS_HOST_TEXTDOMAIN) . '</a></p>';
            }
            $this->domain_settings->render_edit_domain($subscription_id);
            echo '</section>';
        }
    }

    public function add_subscription_actions($data, $args)
    {
        $subscription = $args['subscription'] ?? null;
        if (!$subscription || empty($this->adapter->get_subscription_meta($subscription->id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META))) {
            return $data;
        }

        $actions = $data['custom_actions'] ?? [];
        if ($this->adapter->get_subscription_meta($subscription->id, WPCSTenant::WPCS_TENANT_STATE) === WPCSTenant::READY) {
            $actions[] = [
                'key' => 'wpcs_login',
                'label' => __('Log in to website', WPCS_WAAS_HOST_TEXTDOMAIN),
                'url' => SingleLoginService::get_login_link($subscription->id),
            ];
        }
        $actions[] = [
            'key' => 'wpcs_manage',
            'label' => __('Manage website', WPCS_WAAS_HOST_TEXTDOMAIN),
            'url' => $this->store->get_portal_url(self::ENDPOINT),
        ];
        $data['custom_actions'] = $actions;

        return $data;
    }
}
