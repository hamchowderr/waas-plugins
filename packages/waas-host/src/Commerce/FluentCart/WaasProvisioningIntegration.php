<?php

namespace WaaSHost\Commerce\FluentCart;

use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use WaaSHost\Api\RolesController;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\PluginBootstrap;

/**
 * Product integration that makes a FluentCart product a website plan. Its feed stores
 * the role, snapshot group and size; its triggers create the store (subscription activated)
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

    private ProvisioningApi $provisioning_api;
    private FluentCartStore $store;

    public function __construct(ProvisioningApi $provisioning_api, FluentCartStore $store)
    {
        parent::__construct(__('WaaS Provisioning', WAAS_HOST_TEXTDOMAIN), self::KEY, 20);

        $this->provisioning_api = $provisioning_api;
        $this->store = $store;
        $this->description = __('Create a website for the customer when they subscribe to this product. When the subscription ends, the website is backed up and taken offline, then deleted after the grace period set in the WaaS settings.', WAAS_HOST_TEXTDOMAIN);
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
            'name' => __('Website plan', WAAS_HOST_TEXTDOMAIN),
            'role' => '',
            'group_name' => '',
            'size' => '',
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
            foreach ($this->provisioning_api->get_available_groupnames() as $group_name) {
                $groups[(string) $group_name] = (string) $group_name;
            }
        } catch (\Exception $e) {
            // API unreachable: the field stays empty and the store uses the default snapshot.
        }

        $fields = [
            [
                'key' => 'name',
                'label' => __('Feed Title', WAAS_HOST_TEXTDOMAIN),
                'required' => true,
                'component' => 'text',
                'placeholder' => __('Website plan', WAAS_HOST_TEXTDOMAIN),
            ],
            [
                'key' => 'role',
                'label' => __('Role', WAAS_HOST_TEXTDOMAIN),
                'component' => 'select',
                'is_multiple' => false,
                'required' => false,
                'options' => $roles,
                'inline_tip' => sprintf(
                    /* translators: %s: link that reloads the roles from the provisioning API */
                    __('Decides which plugins the website gets. Missing a role? %s', WAAS_HOST_TEXTDOMAIN),
                    '<a href="' . esc_url(RolesController::get_refresh_url()) . '">' . __('Refresh roles', WAAS_HOST_TEXTDOMAIN) . '</a>'
                ),
            ],
            [
                'key' => 'group_name',
                'label' => __('Snapshot group', WAAS_HOST_TEXTDOMAIN),
                'component' => 'select',
                'is_multiple' => false,
                'required' => false,
                'options' => $groups,
                'inline_tip' => __('The website starts as a copy of this snapshot group. Leave empty for the default.', WAAS_HOST_TEXTDOMAIN),
            ],
            [
                'key' => 'size',
                'label' => __('Size', WAAS_HOST_TEXTDOMAIN),
                'component' => 'select',
                'is_multiple' => false,
                'required' => false,
                'options' => [
                    'small' => __('Small: 1 CPU, 512 MB memory, 5 GB storage, 5,000 emails a month', WAAS_HOST_TEXTDOMAIN),
                    'medium' => __('Medium: 2 CPUs, 512 MB memory, 10 GB storage, 20,000 emails a month', WAAS_HOST_TEXTDOMAIN),
                    'large' => __('Large: 4 CPUs, 768 MB memory, 25 GB storage, 50,000 emails a month', WAAS_HOST_TEXTDOMAIN),
                ],
                'inline_tip' => __('How much computing power, storage and email the website gets. Leave empty for Small.', WAAS_HOST_TEXTDOMAIN),
            ],
            [
                'key' => 'watch_on_access_revoke',
                'component' => 'yes-no-checkbox',
                'checkbox_label' => __('Take the website offline on refund or when subscription access expires', WAAS_HOST_TEXTDOMAIN),
            ],
            $this->actionFields(),
        ];

        return [
            'fields' => $fields,
            'button_require_list' => false,
            'integration_title' => __('WaaS Provisioning', WAAS_HOST_TEXTDOMAIN),
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
            if (empty($this->store->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META))
                && !is_array($this->store->get_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META))) {
                return; // never created, and not waiting for a new name
            }
            $is_active = in_array($this->store->get_subscription_status($subscription_id), FluentCartStore::ACTIVE_STATUSES, true);
            // Feeds run in the background, so a cancel can be handled after the reactivation that followed it.
            if (!$is_revoke && $is_active) {
                $order->addLog(__('Website kept', WAAS_HOST_TEXTDOMAIN), sprintf('Subscription #%d (%s): the subscription is active again', $subscription_id, $trigger), 'info', 'WaaS');
                return;
            }
            // A refund (revoke) leaves the subscription active, so refunding the order that bought it counts. A
            // renewal order refunded or cancelled while the subscription carries on doesn't: the customer keeps the
            // plan, as on WooCommerce, where only the parent order's refund ends the subscription.
            if ($is_revoke && $is_active && ($order->type ?? '') === 'renewal') {
                $order->addLog(__('Website kept', WAAS_HOST_TEXTDOMAIN), sprintf('Subscription #%d (%s): a renewal order, and the subscription is still active', $subscription_id, $trigger), 'info', 'WaaS');
                return;
            }
            // A customer's or admin's cancel keeps the website until the end of the period they paid for. A refund or
            // revoked access, the end of term, or a cancel with nothing left of the period takes it offline now.
            $paid_through = (!$is_revoke && $trigger === 'subscription_canceled') ? $this->store->get_paid_through($subscription_id) : 0;
            if ($paid_through > time()) {
                WaasEventNotes::fire($this->store, $order, $subscription_id, 'waas_subscription_canceled_until', [$paid_through],
                    __('Website stays online until the paid period ends', WAAS_HOST_TEXTDOMAIN),
                    sprintf('Subscription #%d (%s): until %s UTC', $subscription_id, $trigger, gmdate('Y-m-d H:i', $paid_through))
                );
                return;
            }
            WaasEventNotes::fire($this->store, $order, $subscription_id, 'waas_subscription_expired', [],
                __('Website suspended', WAAS_HOST_TEXTDOMAIN), sprintf('Subscription #%d (%s)', $subscription_id, $trigger));
            return;
        }

        if (in_array($trigger, self::CREATE_TRIGGERS, true)) {
            WaasEventNotes::fire($this->store, $order, $subscription_id, 'waas_subscription_created', [$order],
                __('Website requested', WAAS_HOST_TEXTDOMAIN), sprintf('Subscription #%d (%s)', $subscription_id, $trigger));
        }
    }
}
