<?php

namespace AnyPressHost\Features;

use AnyPressHost\Commerce\Commerce;
use AnyPressHost\Core\Tenant;

/** The websites this storefront sold, as the Overview shows them: their states, and what needs the seller. */
class AdminWebsites
{
    /**
     * Websites this storefront sold, newest first: subscription id => [name, domain, state, failed].
     *
     * @return array<int|string, array{name: string, domain: string, state: string}>
     */
    public static function websites(): array
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return [];
        }

        $websites = [];
        foreach ($commerce->find_subscriptions_by_meta(Tenant::WEBSITE_NAME_META, ['']) as $subscription_id => $name) {
            $failed = $commerce->get_subscription_meta($subscription_id, StoreJobs::FAILURE_META);
            $websites[$subscription_id] = [
                'name' => (string) $name,
                'domain' => (string) $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META),
                'state' => is_array($failed) && $failed ? Tenant::FAILED : (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_STATE),
            ];
        }
        uksort($websites, fn ($a, $b) => is_numeric($a) && is_numeric($b) ? (int) $b <=> (int) $a : strcmp((string) $b, (string) $a));

        return $websites;
    }

    /** Which count a website's state goes into on the Overview: live, building, failed or offline. */
    public static function state_group(string $state): string
    {
        switch ($state) {
            case Tenant::READY:
                return 'live';
            case Tenant::FAILED:
            case Tenant::NEEDS_NEW_NAME:
                return 'failed';
            case Tenant::SUSPENDED:
            case Tenant::DELETED:
                return 'offline';
            default:
                return 'building';
        }
    }

    /** A website's state as a pill: what the seller should read, not the API's internal name. */
    public static function state_pill(string $state): string
    {
        $labels = [
            'live' => __('Live', 'anypress-host'),
            'building' => __('Building', 'anypress-host'),
            'failed' => $state === Tenant::NEEDS_NEW_NAME ? __('Needs a new name', 'anypress-host') : __('Failed', 'anypress-host'),
            'offline' => $state === Tenant::DELETED ? __('Deleted', 'anypress-host') : __('Offline', 'anypress-host'),
        ];
        $group = self::state_group($state);
        $class = ['live' => 'is-ok', 'building' => 'is-warn', 'failed' => 'is-bad', 'offline' => ''][$group];

        return '<span class="ap-pill ' . esc_attr($class) . '">' . esc_html($labels[$group]) . '</span>';
    }

    /**
     * What needs the seller: store jobs that kept failing (Try again) and changes the API reports it could not
     * finish (Dismiss once checked).
     *
     * @return array<int, array{subscription_id: int|string, title: string, detail: string, actions: array<string, string>}>
     */
    public static function needs_attention(): array
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return [];
        }

        $items = [];
        foreach ($commerce->find_subscriptions_by_meta(StoreJobs::FAILURE_META, ['']) as $subscription_id => $failure) {
            if (!is_array($failure) || !$failure) {
                continue;
            }
            $items[] = [
                'subscription_id' => $subscription_id,
                'title' => self::website_label($subscription_id),
                'detail' => sprintf(
                    /* translators: 1: date and time, 2: number of tries, 3: the API's error message */
                    __('Couldn\'t be set up or updated. Last try %1$s, after %2$d tries: %3$s', 'anypress-host'),
                    wp_date('j M H:i', (int) ($failure['at'] ?? 0)),
                    (int) ($failure['attempts'] ?? 0),
                    (string) ($failure['error'] ?? '')
                ),
                'actions' => [
                    __('Try again', 'anypress-host') => wp_nonce_url(
                        admin_url('admin-post.php?action=' . StoreJobs::RETRY_ACTION . '&subscription_id=' . rawurlencode((string) $subscription_id)),
                        StoreJobs::RETRY_ACTION . '_' . $subscription_id
                    ),
                ],
            ];
        }
        foreach ($commerce->find_subscriptions_by_meta(Webhooks::TENANT_FAILURE_META, ['']) as $subscription_id => $failure) {
            if (!is_array($failure) || !$failure) {
                continue;
            }
            $items[] = [
                'subscription_id' => $subscription_id,
                'title' => self::website_label($subscription_id),
                'detail' => sprintf(
                    /* translators: 1: what the API was doing, such as "restore", 2: date and time */
                    __('The API couldn\'t finish "%1$s" (%2$s). Check the website in the AnyPress dashboard, then dismiss this.', 'anypress-host'),
                    (string) ($failure['action'] ?? ''),
                    wp_date('j M H:i', (int) ($failure['at'] ?? 0))
                ),
                'actions' => [
                    __('Dismiss', 'anypress-host') => wp_nonce_url(
                        admin_url('admin-post.php?action=' . Webhooks::DISMISS_FAILURE_ACTION . '&subscription_id=' . rawurlencode((string) $subscription_id)),
                        Webhooks::DISMISS_FAILURE_ACTION . '_' . $subscription_id
                    ),
                ],
            ];
        }

        return $items;
    }

    /** "Order #12 · shop.example.com", or the website name while it has no address yet. */
    private static function website_label($subscription_id): string
    {
        $commerce = Commerce::require_adapter();
        $domain = (string) $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META);
        $name = $domain !== '' ? $domain : (string) $commerce->get_subscription_meta($subscription_id, Tenant::WEBSITE_NAME_META);

        /* translators: 1: subscription number, 2: the website's address */
        return sprintf(__('Subscription #%1$s · %2$s', 'anypress-host'), $subscription_id, $name);
    }

    /** The subscription in the cart's own admin, where it has one. */
    public static function admin_subscription_url($subscription_id): string
    {
        $commerce = Commerce::adapter();
        if ($commerce !== null && method_exists($commerce, 'get_admin_subscription_url')) {
            return (string) $commerce->get_admin_subscription_url($subscription_id);
        }

        return '';
    }
}
