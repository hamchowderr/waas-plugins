<?php

namespace WaaSHost\Commerce\FluentCart;

use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\NameConflicts;
use WaaSHost\Features\SingleLoginService;
use WaaSHost\Features\UserAccountSubscriptionsSettings;

/**
 * The customer's websites in FluentCart's customer portal: a "My website" tab (right after
 * Dashboard) with status, one-click login and the domain form, a notice above every other
 * portal page while a website is ready, and links on each subscription. The form lives on
 * its own tab because the subscription panel strips form inputs.
 */
class FluentCartPortal
{
    public const ENDPOINT = 'website';

    /** Lucide "globe", in the 20px outline style of FluentCart's own menu icons. */
    private const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>';

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
        add_filter('wpcs_choose_website_name_url', [$this, 'website_page_url']);
        add_filter('wpcs_website_page_url', [$this, 'website_page_url']);
        // FluentCart puts custom tabs just before Profile; this runs after that and moves ours up.
        add_filter('fluent_cart/global_customer_menu_items', [$this, 'move_menu_item_after_dashboard'], 20);
        // Before FluentCart's own content (priority 10), so the notice sits above the page.
        add_action('fluent_cart/customer_app', [$this, 'render_ready_notice'], 5);
    }

    /**
     * The Website page, for links in emails (website ready, choose a new name): a customer who is logged out logs in
     * through the portal, which then goes to its redirect_to (by default the portal's first page).
     */
    public function website_page_url(): string
    {
        $url = $this->store->get_portal_url(self::ENDPOINT);

        return add_query_arg('redirect_to', rawurlencode($url), $url);
    }

    public function register_endpoint()
    {
        if (!function_exists('fluent_cart_api')) {
            return;
        }

        fluent_cart_api()->addCustomerDashboardEndpoint(self::ENDPOINT, [
            'title' => __('My website', WPCS_WAAS_HOST_TEXTDOMAIN),
            'icon_svg' => self::ICON_SVG,
            'render_callback' => [$this, 'render'],
        ]);
    }

    /** @param array<string, array> $items FluentCart's customer menu, keyed by slug. */
    public function move_menu_item_after_dashboard($items)
    {
        if (!is_array($items) || !isset($items[self::ENDPOINT], $items['dashboard'])) {
            return $items;
        }

        $website = $items[self::ENDPOINT];
        unset($items[self::ENDPOINT]);

        $moved = [];
        foreach ($items as $slug => $item) {
            $moved[$slug] = $item;
            if ($slug === 'dashboard') {
                $moved[self::ENDPOINT] = $website;
            }
        }

        return $moved;
    }

    /** A short notice above the other portal pages for each ready website, with its login and the Website tab. */
    public function render_ready_notice()
    {
        if (apply_filters('fluent_cart/customer_portal/active_tab', '') === self::ENDPOINT) {
            return; // the Website tab shows it all already
        }

        foreach ($this->current_user_websites() as $subscription_id) {
            if ($this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_STATE) !== WPCSTenant::READY) {
                continue;
            }
            $domain = $this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META)
                ?: $this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_BASE_DOMAIN_NAME_META);

            echo '<div class="wpcs-website-ready-notice fct-alert fct-alert-success" style="margin-bottom:16px">';
            echo '<p>' . sprintf(
                /* translators: %s: the website's domain */
                esc_html__('Your website %s is ready.', WPCS_WAAS_HOST_TEXTDOMAIN),
                '<strong>' . esc_html((string) ($domain ?: __('Your website', WPCS_WAAS_HOST_TEXTDOMAIN))) . '</strong>'
            ) . ' ';
            echo '<a class="fct-btn fct-btn-primary" target="_blank" rel="noopener" href="' . esc_url(SingleLoginService::get_login_link($subscription_id)) . '">'
                . esc_html__('Log in to your website', WPCS_WAAS_HOST_TEXTDOMAIN) . '</a> ';
            echo '<a href="' . esc_url($this->store->get_portal_url(self::ENDPOINT)) . '">' . esc_html__('Manage website', WPCS_WAAS_HOST_TEXTDOMAIN) . '</a></p>';
            echo '</div>';
        }
    }

    /** Subscriptions of the current user. @return int[] */
    private function current_user_subscription_ids(): array
    {
        $user_id = get_current_user_id();
        if ($user_id === 0) {
            return [];
        }

        $user = wp_get_current_user();
        $ids = $this->store->find_user_subscription_ids($user_id, (string) $user->user_email);

        return array_values(array_filter($ids, fn ($subscription_id) => $this->adapter->get_customer_id($subscription_id) === $user_id));
    }

    /** Subscriptions of the current user that have a website. @return int[] */
    public function current_user_websites(): array
    {
        return array_values(array_filter($this->current_user_subscription_ids(), function ($subscription_id) {
            return WPCSTenant::shown_to_customer(
                $this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META),
                $this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_STATE)
            );
        }));
    }

    public function render()
    {
        $websites = $this->current_user_websites();

        // A new name submitted for a website that is no longer listed (its subscription stopped meanwhile).
        $result_for = NameConflicts::result_subscription_id();
        if ($result_for !== null && !in_array((int) $result_for, $websites, true) && in_array((int) $result_for, $this->current_user_subscription_ids(), true)) {
            NameConflicts::render_result($result_for);
        }

        if (!$websites) {
            echo '<p>' . esc_html__('You have no websites yet. They appear here a few minutes after you subscribe to a website plan.', WPCS_WAAS_HOST_TEXTDOMAIN) . '</p>';
            return;
        }

        foreach ($websites as $subscription_id) {
            $domain = $this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META)
                ?: $this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_BASE_DOMAIN_NAME_META);
            $status = $this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_STATE) ?: WPCSTenant::PROVISIONING;

            echo '<section class="wpcs-website">';
            echo '<h3>' . esc_html((string) ($domain ?: __('Your website', WPCS_WAAS_HOST_TEXTDOMAIN))) . '</h3>';
            NameConflicts::render_result($subscription_id);
            echo '<p>' . esc_html__('Status:', WPCS_WAAS_HOST_TEXTDOMAIN) . ' ' . esc_html(WPCSTenant::customer_status_label($status)) . '</p>';
            if ($status === WPCSTenant::READY) {
                echo '<p><a class="fct-btn fct-btn-primary" target="_blank" rel="noopener" href="' . esc_url(SingleLoginService::get_login_link($subscription_id)) . '">'
                    . esc_html__('Log in to your website', WPCS_WAAS_HOST_TEXTDOMAIN) . '</a></p>';
            }
            if ($status === WPCSTenant::NEEDS_NEW_NAME) {
                NameConflicts::render_form($subscription_id);
            }
            if (!empty($this->adapter->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META))) {
                $this->domain_settings->render_edit_domain($subscription_id);
            }
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
