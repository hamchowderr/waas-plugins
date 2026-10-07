<?php

namespace WaaSHost\Commerce\FluentCart;

use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use WaaSHost\Api\RolesController;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\PluginBootstrap;

/**
 * Product integration that makes a FluentCart product a website plan. Its feed stores
 * the role and snapshot group; its triggers create the store (subscription activated)
 * and suspend it (canceled, end of term, or access revoked; deleted after the grace period). FluentCart runs product
 * feeds in the background through Action Scheduler, outside the payment request.
 */
class WaasProvisioningIntegration extends BaseIntegrationManager
{
    public const KEY = 'waas_provisioning';

    /** Triggers that create the store, or resume it if it is suspended. Creation is idempotent per subscription. */
    public const CREATE_TRIGGERS = ['subscription_activated', 'subscription_reactivated', 'order_paid_done'];

    /** Triggers that suspend the store, besides any revoke hook FluentCart flags. */
    public const REMOVE_TRIGGERS = ['subscription_canceled', 'subscription_eot', 'subscription_expired_validity'];

    /**
     * A new feed's triggers. subscription_reactivated resumes a store that was suspended when the subscription
     * expired, once the customer pays late; FluentCartFeedTriggers adds it to feeds made before it was a default.
     */
    public const DEFAULT_TRIGGERS = ['subscription_activated', 'subscription_reactivated', 'subscription_canceled', 'subscription_eot'];

    public $scopes = ['product'];

    private WPCSService $wpcs_service;
    private FluentCartStore $store;

    public function __construct(WPCSService $wpcs_service, FluentCartStore $store)
    {
        parent::__construct(__('WaaS Provisioning', WPCS_WAAS_HOST_TEXTDOMAIN), self::KEY, 20);

        $this->wpcs_service = $wpcs_service;
        $this->store = $store;
        $this->description = __('Create a website for the customer when they subscribe to this product. When the subscription ends, the website is backed up and taken offline, then deleted after the grace period set in the WaaS settings.', WPCS_WAAS_HOST_TEXTDOMAIN);
        $this->disableGlobalSettings = true;
    }

    /** Always on: the feed needs no global settings (the default checks an API key option). */
    public function isConfigured()
    {
        return true;
    }

    public function getApiSettings()
    {
        return ['status' => true, 'apiKey' => ''];
    }

    public function getIntegrationDefaults($settings)
    {
        return [
            'enabled' => 'yes',
            'name' => __('Website plan', WPCS_WAAS_HOST_TEXTDOMAIN),
            'role' => '',
            'group_name' => '',
            'event_trigger' => self::DEFAULT_TRIGGERS,
            'watch_on_access_revoke' => 'yes',
        ];
    }

    public function getSettingsFields($settings, $args = [])
    {
        $roles = [];
        foreach ((array) get_option(PluginBootstrap::ROLES_WP_OPTION, []) as $slug => $role) {
            $roles[(string) $slug] = is_object($role) && isset($role->title) ? $role->title : (string) $slug;
        }

        $groups = [];
        try {
            foreach ($this->wpcs_service->get_available_groupnames() as $group_name) {
                $groups[(string) $group_name] = (string) $group_name;
            }
        } catch (\Exception $e) {
            // API unreachable: the field stays empty and the store uses the default snapshot.
        }

        $fields = [
            [
                'key' => 'name',
                'label' => __('Feed Title', WPCS_WAAS_HOST_TEXTDOMAIN),
                'required' => true,
                'component' => 'text',
                'placeholder' => __('Website plan', WPCS_WAAS_HOST_TEXTDOMAIN),
            ],
            [
                'key' => 'role',
                'label' => __('Role', WPCS_WAAS_HOST_TEXTDOMAIN),
                'component' => 'select',
                'is_multiple' => false,
                'required' => false,
                'options' => $roles,
                'inline_tip' => sprintf(
                    /* translators: %s: link that reloads the roles from the provisioning API */
                    __('Decides which plugins the website gets. Missing a role? %s', WPCS_WAAS_HOST_TEXTDOMAIN),
                    '<a href="' . esc_url(RolesController::get_refresh_url()) . '">' . __('Refresh roles', WPCS_WAAS_HOST_TEXTDOMAIN) . '</a>'
                ),
            ],
            [
                'key' => 'group_name',
                'label' => __('Snapshot group', WPCS_WAAS_HOST_TEXTDOMAIN),
                'component' => 'select',
                'is_multiple' => false,
                'required' => false,
                'options' => $groups,
                'inline_tip' => __('The website starts as a copy of this snapshot group. Leave empty for the default.', WPCS_WAAS_HOST_TEXTDOMAIN),
            ],
            [
                'key' => 'watch_on_access_revoke',
                'component' => 'yes-no-checkbox',
                'checkbox_label' => __('Take the website offline on refund or when subscription access expires', WPCS_WAAS_HOST_TEXTDOMAIN),
            ],
            $this->actionFields(),
        ];

        return [
            'fields' => $fields,
            'button_require_list' => false,
            'integration_title' => __('WaaS Provisioning', WPCS_WAAS_HOST_TEXTDOMAIN),
        ];
    }

    /**
     * @param \FluentCart\App\Models\Order $order
     * @param array $eventData the feed run: trigger, is_revoke_hook, feed, event_data
     */
    public function processAction($order, $eventData)
    {
        $trigger = (string) ($eventData['trigger'] ?? '');
        $is_revoke = ($eventData['is_revoke_hook'] ?? '') === 'yes';

        $subscription = $eventData['event_data']['subscription'] ?? null;
        if (!$subscription) {
            $subscription = $this->store->find_subscription_for_order($order);
        }
        if (!$subscription) {
            return; // one-off purchases don't get a website
        }
        $subscription_id = (int) $subscription->id;

        if ($is_revoke || in_array($trigger, self::REMOVE_TRIGGERS, true)) {
            if (empty($this->store->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META))
                && !is_array($this->store->get_subscription_meta($subscription_id, WPCSTenant::WPCS_NAME_CONFLICT_META))) {
                return; // never created, and not waiting for a new name
            }
            $is_active = in_array($this->store->get_subscription_status($subscription_id), FluentCartStore::ACTIVE_STATUSES, true);
            // Feeds run in the background, so a cancel can be handled after the reactivation that followed it.
            if (!$is_revoke && $is_active) {
                $order->addLog(__('Website kept', WPCS_WAAS_HOST_TEXTDOMAIN), sprintf('Subscription #%d (%s): the subscription is active again', $subscription_id, $trigger), 'info', 'WaaS');
                return;
            }
            // A refund (revoke) leaves the subscription active, so refunding the order that bought it counts. A
            // renewal order refunded or cancelled while the subscription carries on doesn't: the customer keeps the
            // plan, as on WooCommerce, where only the parent order's refund ends the subscription.
            if ($is_revoke && $is_active && ($order->type ?? '') === 'renewal') {
                $order->addLog(__('Website kept', WPCS_WAAS_HOST_TEXTDOMAIN), sprintf('Subscription #%d (%s): a renewal order, and the subscription is still active', $subscription_id, $trigger), 'info', 'WaaS');
                return;
            }
            // A customer's or admin's cancel keeps the website until the end of the period they paid for. A refund or
            // revoked access, the end of term, or a cancel with nothing left of the period takes it offline now.
            $paid_through = (!$is_revoke && $trigger === 'subscription_canceled') ? $this->store->get_paid_through($subscription_id) : 0;
            if ($paid_through > time()) {
                do_action('wpcs_subscription_canceled_until', $subscription_id, $paid_through);
                $order->addLog(
                    __('Website stays online until the paid period ends', WPCS_WAAS_HOST_TEXTDOMAIN),
                    sprintf('Subscription #%d (%s): until %s UTC', $subscription_id, $trigger, gmdate('Y-m-d H:i', $paid_through)),
                    'info',
                    'WaaS'
                );
                return;
            }
            do_action('wpcs_subscription_expired', $subscription_id);
            $order->addLog(__('Website suspended', WPCS_WAAS_HOST_TEXTDOMAIN), sprintf('Subscription #%d (%s)', $subscription_id, $trigger), 'info', 'WaaS');
            return;
        }

        if (in_array($trigger, self::CREATE_TRIGGERS, true)) {
            do_action('wpcs_subscription_created', $subscription_id, $order);
            $order->addLog(__('Website requested', WPCS_WAAS_HOST_TEXTDOMAIN), sprintf('Subscription #%d (%s)', $subscription_id, $trigger), 'info', 'WaaS');
        }
    }
}
