<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Core\Tenant;
use AnyPressHost\Features\StoreJobs;

/**
 * Domains sold at this shop's own checkout, in its own brand and at its own price (off until "Sell domains" is on).
 *
 * On the customer's Website page each live website gets a "Get your own domain" section: a search (through the AnyPress
 * API, which prices each name for the storefront), then the name to register it to. Continuing makes a quote (an option,
 * one per pick) and sends the customer to FluentCart's instant checkout for a custom item with that quote's number:
 * fluent_cart/cart/validate_custom_item answers it as a yearly subscription "Domain <name>", priced at the API's price
 * plus the shop's own amount. The first year costs what the API's first year costs, as a signup fee on top of the
 * renewal price when it is higher.
 *
 * When the order is paid the quote is kept on the order and the API registers the domain with the reference
 * fluentcart-order-<order>-domain, so a repeat registers nothing more; the domain then becomes the website's address.
 * Each time the subscription renews (fluent_cart/subscription_renewed), the API renews the domain with the renewal
 * order's reference. Failed calls are retried with backoff, then listed in an admin notice with a Retry link.
 */
class FluentCartDomains
{
    public const ENABLED_OPTION = 'anypress_domains_enabled';
    /** Whole dollars added to each year's price. */
    public const EXTRA_OPTION = 'anypress_domains_extra_dollars';
    /** Quote numbers start here, far above any real FluentCart variation id. */
    private const QUOTE_BASE = 9000000000;
    private const QUOTE_SEQ_OPTION = 'anypress_domain_quote_seq';
    private const QUOTE_PREFIX = 'anypress_domain_quote_';
    /** A quote can be paid for this long after it was made. */
    private const QUOTE_TTL = 86400;
    public const CHECKOUT_ACTION = 'anypress_domain_checkout';
    /** Order meta on the first order: the quote it paid for. */
    public const ORDER_QUOTE_META = '_anypress_domain_quote';
    /** Order meta: set once the API confirmed the registration or renewal of this order. */
    public const ORDER_DONE_META = '_anypress_domain_done';
    public const ORDER_ATTEMPTS_META = '_anypress_domain_attempts';
    public const FAILED_OPTION = 'anypress_domain_failures';
    public const RETRY_HOOK = 'anypress_retry_domain';
    public const RETRY_ACTION = 'anypress_retry_domain';
    /** Query args on the Website page. */
    public const SEARCH_ARG = 'anypress_domain_q';
    public const SITE_ARG = 'anypress_domain_site';
    public const RESULT_ARG = 'anypress_domain_result';

    private ProvisioningApi $provisioning_api;
    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    private FluentCartDomainSearch $search;

    public function __construct(ProvisioningApi $provisioning_api, FluentCartAdapter $adapter, FluentCartStore $store)
    {
        $this->provisioning_api = $provisioning_api;
        $this->adapter = $adapter;
        $this->store = $store;
        $this->search = new FluentCartDomainSearch($provisioning_api, $adapter, $store, $this);
    }

    public static function enabled(): bool
    {
        return get_option(self::ENABLED_OPTION, '') === 'on';
    }

    public function register(): void
    {
        add_filter('fluent_cart/cart/validate_custom_item', [$this, 'checkout_item'], 10, 2);
        add_action('fluent_cart/checkout/prepare_other_data', [$this, 'save_quote_on_order'], 10, 1);
        add_action('fluent_cart/order_paid_done', [$this, 'order_paid'], 20, 1);
        add_action('fluent_cart/subscription_renewed', [$this, 'renewed'], 20, 1);
        add_action(self::RETRY_HOOK, [$this, 'retry'], 10, 1);
        $this->search->register();
        add_action('admin_notices', [$this, 'show_failed_notice']);
        add_action('admin_post_' . self::RETRY_ACTION, [$this, 'handle_retry_request']);
    }

    /** What the customer pays for a year, in cents: the storefront's price plus the shop's own amount. */
    public static function shop_price(int $api_cents): int
    {
        $extra = max(0, (int) get_option(self::EXTRA_OPTION, 0)) * 100;

        return (int) apply_filters('anypress_domain_price', $api_cents + $extra, $api_cents);
    }

    /** The "Get your own domain" section of a live website's card (FluentCartDomainSearch). */
    public function render_section($subscription_id): void
    {
        $this->search->render_section($subscription_id);
    }

    /** Keeps a pick until checkout; its number is the custom item FluentCart's checkout asks about. */
    public function make_quote(array $quote): int
    {
        $seq = (int) get_option(self::QUOTE_SEQ_OPTION, 0) + 1;
        update_option(self::QUOTE_SEQ_OPTION, $seq, false);
        $id = self::QUOTE_BASE + $seq;
        add_option(self::QUOTE_PREFIX . $id, $quote, '', false);

        return $id;
    }

    /** The quote with this number, or null. */
    public function find_quote($quote_id): ?array
    {
        if (!is_numeric($quote_id) || (int) $quote_id <= self::QUOTE_BASE) {
            return null;
        }
        $quote = get_option(self::QUOTE_PREFIX . (int) $quote_id);

        return is_array($quote) ? $quote : null;
    }

    // ---- Checkout ----

    /**
     * fluent_cart/cart/validate_custom_item for instant checkout: our quote as a yearly subscription item, for the
     * customer who made it while it is fresh. Anything else is left as it was.
     *
     * @param mixed $item
     * @param array $args item_id, quantity, is_custom, action
     */
    public function checkout_item($item, $args)
    {
        $quote_id = $args['item_id'] ?? null;
        $quote = $this->find_quote($quote_id);
        if ($quote === null || ($args['action'] ?? '') !== 'instant_checkout') {
            return $item;
        }
        if (!self::enabled() || (int) $quote['user_id'] !== get_current_user_id() || time() - (int) $quote['made_at'] > self::QUOTE_TTL) {
            return null;
        }

        $first = (int) $quote['first_cents'];
        $yearly = (int) $quote['yearly_cents'];

        return (object) [
            'id' => (int) $quote_id,
            'item_id' => (int) $quote_id,
            'is_custom' => true,
            'post_id' => 0,
            'post_title' => sprintf(
                /* translators: %s: domain name */
                __('Domain %s', 'anypress-host'),
                $quote['domain']
            ),
            'title' => __('Yearly', 'anypress-host'),
            'item_price' => $yearly,
            'sold_individually' => true,
            'fulfillment_type' => 'digital',
            'payment_type' => 'subscription',
            'other_info' => [
                'payment_type' => 'subscription',
                'repeat_interval' => 'yearly',
                'times' => 0,
                'trial_days' => 0,
                // The first year, when it costs more than later ones.
                'manage_setup_fee' => $first > $yearly ? 'yes' : 'no',
                'signup_fee' => max(0, $first - $yearly),
                'signup_fee_name' => __('First year', 'anypress-host'),
            ],
        ];
    }

    /** @param array $data cart, order, request_data, ... */
    public function save_quote_on_order($data): void
    {
        $cart = $data['cart'] ?? null;
        $order = $data['order'] ?? null;
        if (!$cart || !$order || !is_array($cart->cart_data ?? null)) {
            return;
        }
        foreach ($cart->cart_data as $line) {
            $quote_id = is_array($line) ? ($line['object_id'] ?? $line['id'] ?? null) : null;
            $quote = $this->find_quote($quote_id);
            if ($quote !== null) {
                $quote['quote_id'] = (int) $quote_id;
                $this->store->update_order_meta($order, self::ORDER_QUOTE_META, $quote);
                return;
            }
        }
    }

    // ---- After payment ----

    /** @param array $data order, transaction, customer[, subscription] */
    public function order_paid($data): void
    {
        $order = is_array($data) ? ($data['order'] ?? null) : null;
        if ($order && $this->quote_of($order) !== null) {
            $this->process($order, $order);
        }
    }

    /** @param array $data subscription, order (the renewal), main_order, customer */
    public function renewed($data): void
    {
        $renewal = is_array($data) ? ($data['order'] ?? null) : null;
        $main = is_array($data) ? ($data['main_order'] ?? null) : null;
        if ($renewal && $main && $this->quote_of($main) !== null) {
            $this->store->update_order_meta($renewal, '_anypress_domain_main_order', (int) $main->id);
            $this->process($renewal, $main);
        }
    }

    /** Action Scheduler / WP-Cron retry, and the admin's Retry. */
    public function retry($order_id): void
    {
        $order = $this->store->find_order($order_id);
        if (!$order) {
            return;
        }
        $main_id = (int) $this->store->get_order_meta($order, '_anypress_domain_main_order');
        $main = $main_id > 0 ? $this->store->find_order($main_id) : $order;
        if ($main && $this->quote_of($main) !== null) {
            $this->process($order, $main);
        }
    }

    private function quote_of($order): ?array
    {
        $quote = $this->store->get_order_meta_value($order, self::ORDER_QUOTE_META);

        return is_array($quote) && !empty($quote['domain']) ? $quote : null;
    }

    /**
     * Registers the domain ($order is the first order) or renews it ($order is a renewal of $main). Safe to run again:
     * a confirmed order is skipped, and the API does a reference once.
     */
    public function process($order, $main): void
    {
        if ($this->store->get_order_meta_value($order, self::ORDER_DONE_META)) {
            return;
        }
        $quote = $this->quote_of($main);
        $renewal = (int) $order->id !== (int) $main->id;
        $reference = $renewal ? sprintf('fluentcart-renewal-%d', (int) $order->id) : sprintf('fluentcart-order-%d-domain', (int) $order->id);

        try {
            $result = $renewal
                ? $this->provisioning_api->renew_paid_domain($quote['external_id'], $quote['domain'], $reference)
                : $this->provisioning_api->buy_paid_domain($quote['external_id'], $quote['domain'], $quote['contact'], $reference);
        } catch (\Throwable $e) {
            $this->failed($order, $quote['domain'], $e->getMessage());
            return;
        }

        $this->store->update_order_meta($order, self::ORDER_DONE_META, ['at' => time(), 'status' => is_object($result) ? (string) ($result->status ?? '') : '']);
        $this->store->update_order_meta($order, self::ORDER_ATTEMPTS_META, 0);
        $this->clear_failure((int) $order->id);
        if (!$renewal && !empty($quote['quote_id'])) {
            delete_option(self::QUOTE_PREFIX . (int) $quote['quote_id']);
        }
        $this->note($order, $renewal ? __('Domain renewed', 'anypress-host') : __('Domain ordered', 'anypress-host'), $renewal
            /* translators: 1: domain, 2: reference */
            ? sprintf('%1$s renewed for one more year. Reference %2$s', $quote['domain'], $reference)
            /* translators: 1: domain, 2: reference */
            : sprintf('%1$s is being registered and becomes the website\'s address in a few minutes. Reference %2$s', $quote['domain'], $reference));
    }

    private function failed($order, string $domain, string $error): void
    {
        $order_id = (int) $order->id;
        $attempts = (int) $this->store->get_order_meta($order, self::ORDER_ATTEMPTS_META) + 1;
        $this->store->update_order_meta($order, self::ORDER_ATTEMPTS_META, $attempts);
        error_log(sprintf('AnyPress Host: domain %s for order %d failed (try %d): %s', $domain, $order_id, $attempts, $error));

        // The name was taken or not sold any more: trying again won't help, the order needs a refund.
        $final = (bool) preg_match('/^Failed: (400|403|409) /', $error);
        if (!$final && $attempts <= count(StoreJobs::RETRY_DELAYS)) {
            $delay = StoreJobs::RETRY_DELAYS[$attempts - 1];
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + $delay, self::RETRY_HOOK, [$order_id], StoreJobs::GROUP);
            } else {
                wp_schedule_single_event(time() + $delay, self::RETRY_HOOK, [$order_id]);
            }
            $this->note($order, __('Domain not done yet', 'anypress-host'), sprintf('The AnyPress API failed: %s. Trying again in %d minutes.', $error, (int) ceil($delay / 60)), 'error');
            return;
        }

        $failures = get_option(self::FAILED_OPTION, []);
        $failures = is_array($failures) ? $failures : [];
        $failures[$order_id] = ['domain' => $domain, 'error' => $error, 'attempts' => $attempts, 'at' => time()];
        update_option(self::FAILED_OPTION, $failures, false);
        $this->note($order, __('Domain not done', 'anypress-host'), $final
            ? sprintf('The AnyPress API refused %s: %s. Refund this order, or pick another domain for the customer.', $domain, $error)
            : sprintf('The AnyPress API failed %d times: %s. Use Retry in the WordPress admin notice once the API works again.', $attempts, $error), 'error');
        do_action('anypress_domain_failed', $order_id, $domain, $error);
    }

    private function clear_failure(int $order_id): void
    {
        $failures = get_option(self::FAILED_OPTION, []);
        if (is_array($failures) && isset($failures[$order_id])) {
            unset($failures[$order_id]);
            update_option(self::FAILED_OPTION, $failures, false);
        }
    }

    private function note($order, string $title, string $detail, string $type = 'info'): void
    {
        $order->addLog($title, $detail, $type, 'AnyPress');
    }

    /** A website that is up: it has a store that isn't deleted, and its subscription is active. */
    public function is_live($subscription_id): bool
    {
        return !empty($this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META))
            && $this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_STATE) === Tenant::READY
            && $this->adapter->is_subscription_active($subscription_id);
    }

    public function show_failed_notice(): void
    {
        $failures = get_option(self::FAILED_OPTION, []);
        if (!current_user_can('manage_options') || !is_array($failures) || !$failures) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>' . esc_html(sprintf(
            /* translators: %d: number of orders */
            _n('A domain bought in %d order was not registered or renewed.', 'Domains bought in %d orders were not registered or renewed.', count($failures), 'anypress-host'),
            count($failures)
        )) . '</strong></p><ul>';
        foreach ($failures as $order_id => $failure) {
            $retry_url = wp_nonce_url(admin_url('admin-post.php?action=' . self::RETRY_ACTION . '&order_id=' . (int) $order_id), self::RETRY_ACTION . '_' . (int) $order_id);
            echo '<li>' . esc_html(sprintf(
                'Order #%d (%s): failed %d times, last at %s: %s',
                $order_id,
                $failure['domain'] ?? '',
                $failure['attempts'] ?? 0,
                wp_date('Y-m-d H:i', (int) ($failure['at'] ?? 0)),
                $failure['error'] ?? ''
            )) . ' <a href="' . esc_url($retry_url) . '">' . esc_html__('Retry', 'anypress-host') . '</a></li>';
        }
        echo '</ul></div>';
    }

    public function handle_retry_request(): void
    {
        $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'anypress-host'), 403);
        }
        check_admin_referer(self::RETRY_ACTION . '_' . $order_id);

        $this->clear_failure($order_id);
        $order = $this->store->find_order($order_id);
        if ($order) {
            $this->store->update_order_meta($order, self::ORDER_ATTEMPTS_META, 0);
            $this->retry($order_id);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
