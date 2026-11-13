<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Core\Tenant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tells the API a website's care (PUT /v1/tenants/care), so the dashboard shows the package and this month's time:
 * when care starts, when the website's store is made (care bought before it), time is logged, extra time is bought,
 * and when the care subscription changes status. Each send
 * replaces the one before, so sending again is always safe. A failed send is tried once more a quarter of an hour
 * later; the shop and the store keep working either way.
 */
class FluentCartCareSync
{
    public const RETRY_HOOK = 'anypress_care_sync_retry';

    private ProvisioningApi $provisioning_api;
    private FluentCartAdapter $adapter;
    private FluentCartCare $care;

    public function __construct(ProvisioningApi $provisioning_api, FluentCartAdapter $adapter, FluentCartCare $care)
    {
        $this->provisioning_api = $provisioning_api;
        $this->adapter = $adapter;
        $this->care = $care;
    }

    public function register(): void
    {
        add_action('anypress_care_added', [$this, 'care_added'], 20, 2);
        add_action('anypress_tenant_linked_to_subscription', [$this, 'sync'], 20, 1);
        add_action('anypress_care_time_logged', [$this, 'sync'], 20, 1);
        add_action('anypress_care_extra_added', [$this, 'sync'], 20, 1);
        add_action(self::RETRY_HOOK, [$this, 'retry'], 10, 1);
        foreach (['payments/subscription_status_changed', 'subscription_activated', 'subscription_paused', 'subscription_resumed', 'subscription_canceled'] as $event) {
            add_action('fluent_cart/' . $event, [$this, 'subscription_changed'], 30, 1);
        }
    }

    /** anypress_care_added: a care package now belongs to a website. */
    public function care_added($care_id, $website_id): void
    {
        $this->sync($website_id);
    }

    /** A FluentCart subscription changed status: if it is a website's care, send that website's care again. */
    public function subscription_changed($data): void
    {
        $subscription = is_array($data) ? ($data['subscription'] ?? null) : null;
        $website_id = $subscription && !empty($subscription->id)
            ? (int) $this->adapter->get_subscription_meta((int) $subscription->id, FluentCartCare::WEBSITE_META)
            : 0;
        if ($website_id > 0) {
            $this->sync($website_id);
        }
    }

    /** The retry a quarter of an hour after a failed send. */
    public function retry($website_id): void
    {
        $this->sync($website_id, false);
    }

    /** Sends the website's care now. */
    public function sync($website_id, bool $retry_on_failure = true): void
    {
        $website_id = (int) $website_id;
        $external_id = (string) $this->adapter->get_subscription_meta($website_id, Tenant::TENANT_EXTERNAL_ID_META);
        if ($external_id === '' || $this->adapter->get_subscription_meta($website_id, Tenant::TENANT_STATE) === Tenant::DELETED) {
            return; // no store (yet, or any more): nothing to tell
        }

        $summary = $this->care->summary($website_id);
        try {
            if ($summary === null || (int) $summary['subscription_id'] === 0) {
                $this->provisioning_api->set_care($external_id, null);
            } else {
                $this->provisioning_api->set_care($external_id, $summary['package'], [
                    'minutes' => (int) $summary['minutes'],
                    'used' => (int) $summary['used'],
                    'extra' => (int) $summary['extra'],
                    'answer' => (string) $summary['answer'],
                    'month' => FluentCartCare::month(),
                ]);
            }
        } catch (\Throwable $e) {
            if ($retry_on_failure && !wp_next_scheduled(self::RETRY_HOOK, [$website_id])) {
                wp_schedule_single_event(time() + 900, self::RETRY_HOOK, [$website_id]);
            }
        }
    }
}
