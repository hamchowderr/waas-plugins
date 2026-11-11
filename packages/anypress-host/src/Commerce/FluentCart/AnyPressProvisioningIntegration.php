<?php

namespace AnyPressHost\Commerce\FluentCart;

use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use AnyPressHost\Api\RolesController;
use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Core\Tenant;
use AnyPressHost\Features\PluginBootstrap;

/**
 * Product integration that makes a FluentCart product a website plan. Its feed stores
 * the role, snapshot group and size; its triggers create the store (subscription activated)
 * and suspend it (canceled, end of term, or access revoked; deleted after the grace period). FluentCart runs product
 * feeds in the background through Action Scheduler, outside the payment request.
 *
 * The same feed with "Product type: Email pack" makes the product an email pack instead: buying it adds
 * "Emails per pack" emails (times the quantity) to one of the customer's websites (FluentCartEmailPacks). A pack is
 * never a website plan, and its feed's triggers do nothing. Feeds saved before the product type existed are plans.
 *
 * "Product type: Care package" makes a subscription product a monthly care package for one of the customer's websites,
 * and "One-time service" a one-off job such as setting a site up (FluentCartCare). Neither creates a website either.
 */
class AnyPressProvisioningIntegration extends BaseIntegrationManager
{
    public const KEY = 'anypress_provisioning';

    /** Triggers that create the store, or resume it if it is suspended. Creation is idempotent per subscription. */
    public const CREATE_TRIGGERS = ['subscription_activated', 'subscription_reactivated', 'order_paid_done'];

    /** Triggers that suspend the store, besides any revoke hook FluentCart flags. */
    public const REMOVE_TRIGGERS = ['subscription_canceled', 'subscription_eot', 'subscription_expired_validity'];

    /**
     * A new feed's triggers. subscription_reactivated resumes a store that was suspended when the subscription
     * expired, once the customer pays late; FluentCartFeedTriggers adds it to feeds made before it was a default.
     */
    public const DEFAULT_TRIGGERS = ['subscription_activated', 'subscription_reactivated', 'subscription_canceled', 'subscription_eot'];

    /** product_type values. A feed without one is a website plan. */
    public const PRODUCT_TYPE_PLAN = 'plan';
    public const PRODUCT_TYPE_EMAIL_PACK = 'email_pack';
    public const PRODUCT_TYPE_CARE = 'care';
    public const PRODUCT_TYPE_SERVICE = 'service';

    /** The most emails one pack line may add (the API's limit per call). */
    public const MAX_EMAILS_PER_PACK = 1000000;

    public $scopes = ['product'];

    private ProvisioningApi $provisioning_api;
    private FluentCartStore $store;

    public function __construct(ProvisioningApi $provisioning_api, FluentCartStore $store)
    {
        parent::__construct(__('AnyPress websites', 'anypress-host'), self::KEY, 20);

        $this->provisioning_api = $provisioning_api;
        $this->store = $store;
        $this->description = __('Create a website for the customer when they subscribe to this product. When the subscription ends, the website is backed up and taken offline, then deleted after the grace period set in the AnyPress settings. Or set Product type to Email pack to sell extra emails for a website.', 'anypress-host');
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
            'name' => __('Website plan', 'anypress-host'),
            'product_type' => self::PRODUCT_TYPE_PLAN,
            'emails_per_pack' => '',
            'care_minutes' => '',
            'care_answer' => '',
            'service_minutes' => '',
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
                'label' => __('Feed Title', 'anypress-host'),
                'required' => true,
                'component' => 'text',
                'placeholder' => __('Website plan', 'anypress-host'),
            ],
            [
                'key' => 'product_type',
                'label' => __('Product type', 'anypress-host'),
                'component' => 'select',
                'is_multiple' => false,
                'required' => false,
                'options' => [
                    self::PRODUCT_TYPE_PLAN => __('Website plan', 'anypress-host'),
                    self::PRODUCT_TYPE_EMAIL_PACK => __('Email pack', 'anypress-host'),
                    self::PRODUCT_TYPE_CARE => __('Care package', 'anypress-host'),
                    self::PRODUCT_TYPE_SERVICE => __('One-time service', 'anypress-host'),
                ],
                'inline_tip' => __('A website plan is a subscription that creates a website. An email pack is a one-time product that adds emails to one of the customer\'s websites; the emails never expire and are used once the plan\'s monthly emails run out. For an email pack, only "Emails per pack" below applies. A care package is a monthly or yearly subscription for changes to one of the customer\'s websites; a one-time service is a single job for one website, such as setting it up. For those, only the care fields below apply.', 'anypress-host'),
            ],
            [
                'key' => 'emails_per_pack',
                'label' => __('Emails per pack', 'anypress-host'),
                'component' => 'number',
                'required' => false,
                'dependency' => ['depends_on' => 'product_type', 'operator' => '=', 'value' => self::PRODUCT_TYPE_EMAIL_PACK],
                'inline_tip' => __('Email packs only: how many emails one pack adds. Buying several adds this many times the quantity.', 'anypress-host'),
            ],
            [
                'key' => 'care_minutes',
                'label' => __('Minutes of changes a month', 'anypress-host'),
                'component' => 'number',
                'required' => false,
                'dependency' => ['depends_on' => 'product_type', 'operator' => '=', 'value' => self::PRODUCT_TYPE_CARE],
                'inline_tip' => __('Care packages only: the time for changes the package includes each month, also on a yearly price. Unused time does not carry over.', 'anypress-host'),
            ],
            [
                'key' => 'care_answer',
                'label' => __('Answered within', 'anypress-host'),
                'component' => 'text',
                'required' => false,
                'dependency' => ['depends_on' => 'product_type', 'operator' => '=', 'value' => self::PRODUCT_TYPE_CARE],
                'inline_tip' => __('Care packages only: how soon requests are answered, as the customer and your team see it, e.g. "1 business day".', 'anypress-host'),
            ],
            [
                'key' => 'service_minutes',
                'label' => __('Minutes of extra time', 'anypress-host'),
                'component' => 'number',
                'required' => false,
                'dependency' => ['depends_on' => 'product_type', 'operator' => '=', 'value' => self::PRODUCT_TYPE_SERVICE],
                'inline_tip' => __('One-time services only: time this adds to the website, used once its care package\'s monthly time runs out (e.g. 60 for a single hour). Leave empty for a job such as setting a site up.', 'anypress-host'),
            ],
            [
                'key' => 'role',
                'label' => __('Role', 'anypress-host'),
                'component' => 'select',
                'is_multiple' => false,
                'required' => false,
                'options' => $roles,
                'inline_tip' => sprintf(
                    /* translators: %s: link that reloads the roles from the provisioning API */
                    __('Decides which plugins the website gets. Missing a role? %s', 'anypress-host'),
                    '<a href="' . esc_url(RolesController::get_refresh_url()) . '">' . __('Refresh roles', 'anypress-host') . '</a>'
                ),
            ],
            [
                'key' => 'group_name',
                'label' => __('Snapshot group', 'anypress-host'),
                'component' => 'select',
                'is_multiple' => false,
                'required' => false,
                'options' => $groups,
                'inline_tip' => __('The website starts as a copy of this snapshot group. Leave empty for the default.', 'anypress-host'),
            ],
            [
                'key' => 'size',
                'label' => __('Size', 'anypress-host'),
                'component' => 'select',
                'is_multiple' => false,
                'required' => false,
                'options' => [
                    'small' => __('Small: 1 CPU, 768 MB memory, 10 GB storage, 5,000 emails a month', 'anypress-host'),
                    'medium' => __('Medium: 2 CPUs, 1.25 GB memory, 20 GB storage, 20,000 emails a month', 'anypress-host'),
                    'large' => __('Large: 4 CPUs, 2 GB memory, 30 GB storage, 50,000 emails a month', 'anypress-host'),
                ],
                'inline_tip' => __('How much computing power, storage and email the website gets. Leave empty for Small.', 'anypress-host'),
            ],
            [
                'key' => 'watch_on_access_revoke',
                'component' => 'yes-no-checkbox',
                'checkbox_label' => __('Take the website offline on refund or when subscription access expires', 'anypress-host'),
            ],
            $this->actionFields(),
        ];

        return [
            'fields' => $fields,
            'button_require_list' => false,
            'integration_title' => __('AnyPress websites', 'anypress-host'),
        ];
    }

    /** What a feed's settings make the product: one of the PRODUCT_TYPE_ values. Feeds without one are plans. */
    public static function product_type($settings): string
    {
        $type = is_array($settings) ? (string) ($settings['product_type'] ?? '') : '';

        return in_array($type, [self::PRODUCT_TYPE_EMAIL_PACK, self::PRODUCT_TYPE_CARE, self::PRODUCT_TYPE_SERVICE], true) ? $type : self::PRODUCT_TYPE_PLAN;
    }

    /** True when a feed's settings make the product a website plan (not an email pack, care package or service). */
    public static function is_plan($settings): bool
    {
        return self::product_type($settings) === self::PRODUCT_TYPE_PLAN;
    }

    /** True when a feed's settings make the product an email pack, not a website plan. */
    public static function is_email_pack($settings): bool
    {
        return self::product_type($settings) === self::PRODUCT_TYPE_EMAIL_PACK;
    }

    /** A whole number of minutes from a feed field, 0 when it is empty or not one (at most a month of minutes). */
    public static function minutes($settings, string $key): int
    {
        $minutes = is_array($settings) ? ($settings[$key] ?? '') : '';
        if (!is_numeric($minutes) || (float) $minutes != (int) $minutes) {
            return 0;
        }

        return max(0, min((int) $minutes, 31 * 24 * 60));
    }

    /** The emails one pack of an email-pack feed adds; 0 when the feed is not a pack or has no valid number. */
    public static function emails_per_pack($settings): int
    {
        if (!self::is_email_pack($settings)) {
            return 0;
        }

        $emails = $settings['emails_per_pack'] ?? '';
        if (!is_numeric($emails) || (float) $emails != (int) $emails) {
            return 0;
        }
        $emails = (int) $emails;

        return $emails >= 1 && $emails <= self::MAX_EMAILS_PER_PACK ? $emails : 0;
    }

    /**
     * @param \FluentCart\App\Models\Order $order
     * @param array $eventData the feed run: trigger, is_revoke_hook, feed, event_data
     */
    public function processAction($order, $eventData)
    {
        if (!self::is_plan($eventData['feed'] ?? null)) {
            // Email packs (FluentCartEmailPacks), care packages and services (FluentCartCare) are handled when the order
            // is paid; they never create or remove a website.
            return;
        }

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
            if (
                empty($this->store->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META))
                && !is_array($this->store->get_subscription_meta($subscription_id, Tenant::NAME_CONFLICT_META))
            ) {
                return; // never created, and not waiting for a new name
            }
            $is_active = in_array($this->store->get_subscription_status($subscription_id), FluentCartStore::ACTIVE_STATUSES, true);
            // Feeds run in the background, so a cancel can be handled after the reactivation that followed it.
            if (!$is_revoke && $is_active) {
                $order->addLog(__('Website kept', 'anypress-host'), sprintf('Subscription #%d (%s): the subscription is active again', $subscription_id, $trigger), 'info', 'AnyPress');
                return;
            }
            // A refund (revoke) leaves the subscription active, so refunding the order that bought it counts. A
            // renewal order refunded or cancelled while the subscription carries on doesn't: the customer keeps the
            // plan, as on WooCommerce, where only the parent order's refund ends the subscription.
            if ($is_revoke && $is_active && ($order->type ?? '') === 'renewal') {
                $order->addLog(__('Website kept', 'anypress-host'), sprintf('Subscription #%d (%s): a renewal order, and the subscription is still active', $subscription_id, $trigger), 'info', 'AnyPress');
                return;
            }
            // A customer's or admin's cancel keeps the website until the end of the period they paid for. A refund or
            // revoked access, the end of term, or a cancel with nothing left of the period takes it offline now.
            $paid_through = (!$is_revoke && $trigger === 'subscription_canceled') ? $this->store->get_paid_through($subscription_id) : 0;
            if ($paid_through > time()) {
                AnyPressEventNotes::fire(
                    $this->store,
                    $order,
                    $subscription_id,
                    'anypress_subscription_canceled_until',
                    [$paid_through],
                    __('Website stays online until the paid period ends', 'anypress-host'),
                    sprintf('Subscription #%d (%s): until %s UTC', $subscription_id, $trigger, gmdate('Y-m-d H:i', $paid_through))
                );
                return;
            }
            AnyPressEventNotes::fire(
                $this->store,
                $order,
                $subscription_id,
                'anypress_subscription_expired',
                [],
                __('Website suspended', 'anypress-host'),
                sprintf('Subscription #%d (%s)', $subscription_id, $trigger)
            );
            return;
        }

        if (in_array($trigger, self::CREATE_TRIGGERS, true)) {
            AnyPressEventNotes::fire(
                $this->store,
                $order,
                $subscription_id,
                'anypress_subscription_created',
                [$order],
                __('Website requested', 'anypress-host'),
                sprintf('Subscription #%d (%s)', $subscription_id, $trigger)
            );
        }
    }
}
