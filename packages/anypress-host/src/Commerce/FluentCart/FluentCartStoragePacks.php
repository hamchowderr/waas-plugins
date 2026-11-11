<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Core\Tenant;
use AnyPressHost\Features\StoreJobs;

/**
 * Storage packs: a FluentCart subscription product (an AnyPress feed with "Product type: Storage pack") that adds
 * 25 GB of storage to one of the customer's websites for as long as it is paid.
 *
 * Choosing the website works as for email packs: the Website page links to the pack's instant checkout with
 * ?anypress_website=<subscription id>, and FluentCartEmailPacks keeps that website on the order. When the pack's
 * subscription starts, the website is kept on the pack's own subscription (an order without one goes to the
 * customer's only live website, as for email packs).
 *
 * The website's store is then told how many packs it has, counted from all of its pack subscriptions: active ones
 * (also trialing, and failing while FluentCart retries the payment), and cancelled ones until their paid period
 * ends. Each count replaces the one before (PUT /v1/tenants/storage-packs), so sending it again is always safe.
 * The count is sent again whenever one of its pack subscriptions changes: the feed's triggers (started, reactivated,
 * cancelled, ended; a refund of the first order), a pause or a resume, and the end of a cancelled pack's paid period.
 * A failed call is retried with backoff; after the last try the website is listed in an admin notice with a Retry link.
 */
class FluentCartStoragePacks
{
    /** Fired by the AnyPress feed of a storage-pack product: (order, subscription|null, is a refund or revoke). */
    public const CHANGED_HOOK = 'anypress_storage_pack_changed';
    /** The most packs one website can have (the API's limit). */
    public const MAX_PACKS = 20;
    /** The storage one pack adds, in GB (the API's pack size). */
    public const PACK_GB = 25;
    /** Pack subscription meta: the website's subscription id. */
    public const WEBSITE_META = '_anypress_storage_pack_website';
    /** Pack subscription meta: set when the order that bought it was refunded, so it no longer counts. */
    public const REVOKED_META = '_anypress_storage_pack_revoked';
    /** Website subscription meta: the count the API last confirmed, and failed tries since. */
    public const SENT_META = '_anypress_storage_packs_sent';
    public const ATTEMPTS_META = '_anypress_storage_packs_attempts';
    /** Option: website subscription id => last failure, for websites that ran out of automatic retries. */
    public const FAILED_OPTION = 'anypress_storage_pack_failures';
    public const SYNC_HOOK = 'anypress_sync_storage_packs';
    public const RETRY_ACTION = 'anypress_retry_storage_packs';
    /** Statuses a pack counts in; a cancelled pack also counts until its paid period ends. */
    private const COUNTED_STATUSES = ['active', 'trialing', 'failing'];

    private ProvisioningApi $provisioning_api;
    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    private FluentCartEmailPacks $email_packs;

    public function __construct(ProvisioningApi $provisioning_api, FluentCartAdapter $adapter, FluentCartStore $store, FluentCartEmailPacks $email_packs)
    {
        $this->provisioning_api = $provisioning_api;
        $this->adapter = $adapter;
        $this->store = $store;
        $this->email_packs = $email_packs;
    }

    public function register(): void
    {
        add_action(self::CHANGED_HOOK, [$this, 'changed'], 10, 3);
        add_action('fluent_cart/subscription_paused', [$this, 'paused_or_resumed']);
        add_action('fluent_cart/subscription_resumed', [$this, 'paused_or_resumed']);
        add_action(self::SYNC_HOOK, [$this, 'sync']);
        add_action('admin_notices', [$this, 'show_failed_notice']);
        add_action('admin_post_' . self::RETRY_ACTION, [$this, 'handle_retry_request']);
    }

    /**
     * "Add 25 GB storage" for a website: the pack's checkout for it, or null when the shop sells no storage pack or
     * the website has no store.
     *
     * @return array{label: string, url: string, packs: int}|null
     */
    public function link($subscription_id): ?array
    {
        $product = $this->email_packs->can_buy_emails($subscription_id) ? $this->store->get_storage_pack_product() : null;
        if ($product === null) {
            return null;
        }

        return [
            /* translators: %d: gigabytes */
            'label' => sprintf(__('Add %d GB storage', 'anypress-host'), self::PACK_GB),
            'url' => add_query_arg(FluentCartEmailPacks::QUERY_ARG, (int) $subscription_id, $product['checkout_url']),
            'packs' => $this->count($subscription_id),
        ];
    }

    /**
     * A storage pack's feed ran: keeps the website on the pack's subscription, marks a refunded pack, then sends the
     * website's count.
     *
     * @param \FluentCart\App\Models\Order $order
     * @param object|null $subscription
     */
    public function changed($order, $subscription = null, $revoked = false): void
    {
        if (!$subscription || empty($subscription->id)) {
            $subscription = $this->store->find_subscription_for_order($order);
        }
        if (!$subscription) {
            return; // a storage pack sold without a subscription adds nothing
        }
        $pack_id = (int) $subscription->id;

        // A refund of a renewal leaves the pack running, as for plans; a refund of the order that bought it ends it.
        if ($revoked && ($order->type ?? '') !== 'renewal') {
            $this->store->update_subscription_meta($pack_id, self::REVOKED_META, (string) time());
        }

        $website_id = $this->website_of($pack_id, $order);
        if ($website_id === 0) {
            return;
        }

        $paid_through = $this->store->get_subscription_status($pack_id) === 'canceled' ? $this->store->get_paid_through($pack_id) : 0;
        if ($paid_through > time()) {
            $this->schedule_sync($website_id, $paid_through + 60);
        }

        $this->sync($website_id);
    }

    /** fluent_cart/subscription_paused and _resumed: a paused pack stops counting, a resumed one counts again. */
    public function paused_or_resumed($data): void
    {
        $subscription = is_array($data) ? ($data['subscription'] ?? null) : null;
        $website_id = $subscription && !empty($subscription->id) ? (int) $this->store->get_subscription_meta((int) $subscription->id, self::WEBSITE_META) : 0;
        if ($website_id > 0) {
            $this->sync($website_id);
        }
    }

    /** The website a pack is for, kept on the pack's subscription the first time; 0 (with an order note) when unclear. */
    private function website_of(int $pack_id, $order): int
    {
        $website_id = (int) $this->store->get_subscription_meta($pack_id, self::WEBSITE_META);
        if ($website_id > 0) {
            return $website_id;
        }

        $website = $this->email_packs->resolve_website($order);
        if (is_string($website)) {
            $order->addLog(__('Storage not added', 'anypress-host'), $website . ' Choose the website by hand: set the subscription meta ' . self::WEBSITE_META . ' to its subscription id.', 'error', 'AnyPress');
            return 0;
        }

        $this->store->update_subscription_meta($pack_id, self::WEBSITE_META, (string) $website[0]);

        return $website[0];
    }

    /** How many packs a website has now, at most MAX_PACKS. */
    public function count($website_id): int
    {
        $packs = 0;
        foreach ($this->store->find_subscription_ids_with_meta(self::WEBSITE_META, (string) (int) $website_id) as $pack_id) {
            if ($this->counts($pack_id)) {
                $packs += $this->store->get_subscription_quantity($pack_id);
            }
        }

        return min($packs, self::MAX_PACKS);
    }

    private function counts(int $pack_id): bool
    {
        if ($this->store->get_subscription_meta($pack_id, self::REVOKED_META) !== '') {
            return false;
        }
        $status = $this->store->get_subscription_status($pack_id);

        return in_array($status, self::COUNTED_STATUSES, true)
            || ($status === 'canceled' && $this->store->get_paid_through($pack_id) > time());
    }

    /** Sends a website's pack count to its store. The cron/Action Scheduler sync, and the admin's Retry. */
    public function sync($website_id): void
    {
        $website_id = (int) $website_id;
        $external_id = (string) $this->adapter->get_subscription_meta($website_id, Tenant::TENANT_EXTERNAL_ID_META);
        if ($external_id === '' || $this->adapter->get_subscription_meta($website_id, Tenant::TENANT_STATE) === Tenant::DELETED) {
            return; // no store (yet, or any more): nothing to tell
        }

        $packs = $this->count($website_id);
        try {
            $this->provisioning_api->set_storage_packs($external_id, $packs);
        } catch (\Throwable $e) {
            $this->failed($website_id, $e->getMessage());
            return;
        }

        $before = $this->store->get_subscription_meta($website_id, self::SENT_META);
        $this->store->update_subscription_meta($website_id, self::SENT_META, (string) $packs);
        $this->store->update_subscription_meta($website_id, self::ATTEMPTS_META, '0');
        $this->clear_failure($website_id);
        if ($before !== (string) $packs) {
            $this->store->add_subscription_note($website_id, __('Storage packs', 'anypress-host'), sprintf('The website now has %d storage pack%s (%d GB more storage).', $packs, $packs === 1 ? '' : 's', $packs * self::PACK_GB));
        }
    }

    private function failed(int $website_id, string $error): void
    {
        $attempts = (int) $this->store->get_subscription_meta($website_id, self::ATTEMPTS_META) + 1;
        $this->store->update_subscription_meta($website_id, self::ATTEMPTS_META, (string) $attempts);
        error_log(sprintf('AnyPress Host: sending the storage packs of subscription %d failed (try %d): %s', $website_id, $attempts, $error));

        if ($attempts <= count(StoreJobs::RETRY_DELAYS)) {
            $this->schedule_sync($website_id, time() + StoreJobs::RETRY_DELAYS[$attempts - 1]);
            return;
        }

        $failures = get_option(self::FAILED_OPTION, []);
        $failures = is_array($failures) ? $failures : [];
        $failures[$website_id] = ['error' => $error, 'attempts' => $attempts, 'at' => time()];
        update_option(self::FAILED_OPTION, $failures, false);
        $this->store->add_subscription_note($website_id, __('Storage packs not sent', 'anypress-host'), sprintf('The provisioning API failed %d times: %s. Use Retry in the WordPress admin notice once the API works again.', $attempts, $error), 'error');
    }

    private function schedule_sync(int $website_id, int $timestamp): void
    {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, self::SYNC_HOOK, [$website_id], StoreJobs::GROUP);
        } else {
            wp_schedule_single_event($timestamp, self::SYNC_HOOK, [$website_id]);
        }
    }

    private function clear_failure(int $website_id): void
    {
        $failures = get_option(self::FAILED_OPTION, []);
        if (is_array($failures) && isset($failures[$website_id])) {
            unset($failures[$website_id]);
            update_option(self::FAILED_OPTION, $failures, false);
        }
    }

    public function show_failed_notice(): void
    {
        $failures = get_option(self::FAILED_OPTION, []);
        if (!current_user_can('manage_options') || !is_array($failures) || !$failures) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>' . esc_html(sprintf(
            /* translators: %d: number of websites */
            _n('The storage packs of %d website could not be sent to its store.', 'The storage packs of %d websites could not be sent to their stores.', count($failures), 'anypress-host'),
            count($failures)
        )) . '</strong></p><ul>';
        foreach ($failures as $website_id => $failure) {
            $retry_url = wp_nonce_url(
                admin_url('admin-post.php?action=' . self::RETRY_ACTION . '&subscription_id=' . (int) $website_id),
                self::RETRY_ACTION . '_' . (int) $website_id
            );
            echo '<li>' . esc_html(sprintf(
                'Subscription #%d: failed %d times, last at %s: %s',
                $website_id,
                $failure['attempts'] ?? 0,
                wp_date('Y-m-d H:i', (int) ($failure['at'] ?? 0)),
                $failure['error'] ?? ''
            )) . ' <a href="' . esc_url($retry_url) . '">' . esc_html__('Retry', 'anypress-host') . '</a></li>';
        }
        echo '</ul></div>';
    }

    public function handle_retry_request(): void
    {
        $website_id = isset($_GET['subscription_id']) ? absint(wp_unslash($_GET['subscription_id'])) : 0;
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'anypress-host'), 403);
        }
        check_admin_referer(self::RETRY_ACTION . '_' . $website_id);

        $this->clear_failure($website_id);
        $this->store->update_subscription_meta($website_id, self::ATTEMPTS_META, '0');
        $this->sync($website_id);

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
