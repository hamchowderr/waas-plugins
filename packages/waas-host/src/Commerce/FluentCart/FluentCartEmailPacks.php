<?php

namespace WaaSHost\Commerce\FluentCart;

use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\StoreJobs;

/**
 * Email packs: one-time FluentCart products (a WaaS feed with "Product type: Email pack") that add emails to one of the
 * customer's websites. Pack emails never expire; the website uses them once its plan's monthly emails run out.
 *
 * Choosing the website: each website on the customer's Website page links to FluentCart's instant checkout for the pack
 * with ?waas_website=<subscription id>. FluentCart passes unknown query args on to the checkout page, where the id is
 * kept in the cart's checkout data, then copied to the order when it is placed. Any instant-checkout landing without
 * the arg clears it, since FluentCart reuses a draft instant cart for the same item.
 *
 * When the order is paid (fluent_cart/order_paid_done, which FluentCart runs in the background), each pack line adds
 * emails per pack x quantity through the provisioning API, with the reference fluentcart-order-<order>-<line>, so the
 * API adds a line once however often it is sent. The website must be the order's customer's; an order without a
 * website goes to the customer's only live website, and is otherwise left to the shop owner with an order note.
 * A failed call is retried with backoff; after the last try the order is listed in an admin notice with a Retry link.
 */
class FluentCartEmailPacks
{
    /** Query arg on the instant-checkout link: the website's subscription id. */
    public const QUERY_ARG = 'waas_website';
    /** Cart checkout_data key for the chosen website. */
    public const CART_KEY = 'waas_email_pack_website';
    /** Order meta: the chosen website's subscription id. */
    public const ORDER_WEBSITE_META = '_waas_email_pack_website';
    /** Order meta: line id => what was added, for the lines the API has confirmed. */
    public const ORDER_DONE_META = '_waas_email_packs_done';
    /** Order meta: failed tries so far. */
    public const ORDER_ATTEMPTS_META = '_waas_email_packs_attempts';
    /** Option: order id => last failure, for orders that ran out of automatic retries. */
    public const FAILED_OPTION = 'waas_email_pack_failures';
    public const RETRY_HOOK = 'waas_retry_email_packs';
    public const RETRY_ACTION = 'waas_retry_email_packs';

    private ProvisioningApi $provisioning_api;
    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    /** @var array<int, array>|null */
    private ?array $pack_products = null;

    public function __construct(ProvisioningApi $provisioning_api, FluentCartAdapter $adapter, FluentCartStore $store)
    {
        $this->provisioning_api = $provisioning_api;
        $this->adapter = $adapter;
        $this->store = $store;
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'remember_website']);
        add_action('fluent_cart/checkout/prepare_other_data', [$this, 'save_website_on_order'], 10, 1);
        add_action('fluent_cart/order_paid_done', [$this, 'order_paid'], 20, 1);
        add_action(self::RETRY_HOOK, [$this, 'retry']);
        add_filter('waas_buy_emails_url', [$this, 'buy_emails_url'], 10, 2);
        add_action('admin_notices', [$this, 'show_failed_notice']);
        add_action('admin_post_' . self::RETRY_ACTION, [$this, 'handle_retry_request']);
    }

    /** @return array<int, array{product_id: int, emails: int, title: string, checkout_url: string}> */
    private function pack_products(): array
    {
        if ($this->pack_products === null) {
            $this->pack_products = $this->store->get_email_pack_products();
        }

        return $this->pack_products;
    }

    /** True when the website has a store emails can be added to. */
    public function can_buy_emails($subscription_id): bool
    {
        return !empty($this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META))
            && $this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_STATE) !== Tenant::DELETED;
    }

    /**
     * "Buy more emails" links for a website, one per pack product (none when there is no pack product or the website
     * has no store).
     *
     * @return array<int, array{label: string, url: string, emails: int}>
     */
    public function links($subscription_id): array
    {
        if (!$this->can_buy_emails($subscription_id)) {
            return [];
        }

        $packs = $this->pack_products();
        $links = [];
        foreach ($packs as $pack) {
            $links[] = [
                'label' => count($packs) === 1
                    ? __('Buy more emails', 'waas-host')
                    /* translators: %s: number of emails */
                    : sprintf(__('Buy %s more emails', 'waas-host'), number_format_i18n($pack['emails'])),
                'url' => add_query_arg(self::QUERY_ARG, (int) $subscription_id, $pack['checkout_url']),
                'emails' => $pack['emails'],
            ];
        }

        return $links;
    }

    /** waas_buy_emails_url: the checkout link of the smallest pack for a website, '' when there is none. */
    public function buy_emails_url($url, $subscription_id)
    {
        $links = $this->links($subscription_id);

        return $links ? $links[0]['url'] : $url;
    }

    /**
     * On the checkout page FluentCart's instant checkout redirects to (it carries fct_cart_hash): keeps the website
     * from the link in the cart, or clears one left from an earlier link.
     */
    public function remember_website(): void
    {
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link to the cart; resolve_website() only accepts a website that belongs to the paying customer.
        $cart_hash = isset($_GET['fct_cart_hash']) ? sanitize_text_field(wp_unslash($_GET['fct_cart_hash'])) : '';
        if ($cart_hash === '') {
            return;
        }

        $cart = $this->store->find_cart_by_hash($cart_hash);
        if (!$cart) {
            return;
        }

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $subscription_id = isset($_GET[self::QUERY_ARG]) ? absint(wp_unslash($_GET[self::QUERY_ARG])) : 0;
        $this->store->set_cart_checkout_value($cart, self::CART_KEY, $subscription_id > 0 ? $subscription_id : null);
    }

    /** @param array $data cart, order, request_data, ... */
    public function save_website_on_order($data): void
    {
        $cart = $data['cart'] ?? null;
        $order = $data['order'] ?? null;
        if (!$cart || !$order) {
            return;
        }

        $subscription_id = (int) $this->store->get_cart_checkout_value($cart, self::CART_KEY);
        if ($subscription_id <= 0) {
            return;
        }

        foreach ($this->store->get_cart_product_ids($cart) as $product_id) {
            if ($this->store->get_email_pack_feed($product_id) !== null) {
                $this->store->update_order_meta($order, self::ORDER_WEBSITE_META, $subscription_id);
                return;
            }
        }
    }

    /** @param array $data order, transaction, customer[, subscription] */
    public function order_paid($data): void
    {
        $order = is_array($data) ? ($data['order'] ?? null) : null;
        if ($order) {
            $this->process($order);
        }
    }

    /** Action Scheduler / WP-Cron retry, and the admin's Retry. */
    public function retry($order_id): void
    {
        $order = $this->store->find_order($order_id);
        if ($order) {
            $this->process($order);
        }
    }

    /**
     * The order's email-pack lines that the API has not confirmed yet.
     *
     * @return array<int, array{id: int, product_id: int, quantity: int, emails: int}>
     */
    public function pending_lines($order): array
    {
        $done = $this->store->get_order_meta_value($order, self::ORDER_DONE_META);
        $done = is_array($done) ? $done : [];

        $lines = [];
        foreach ($this->store->get_order_lines($order) as $line) {
            $feed = $this->store->get_email_pack_feed($line['product_id']);
            if ($feed === null || isset($done[$line['id']])) {
                continue;
            }
            $line['emails'] = WaasProvisioningIntegration::emails_per_pack($feed) * $line['quantity'];
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Adds the emails of each pack line not added yet. Safe to run again: lines the API confirmed are skipped, and a
     * line sent again with its reference is added once.
     */
    public function process($order): void
    {
        $lines = $this->pending_lines($order);
        if (!$lines) {
            return;
        }

        $order_id = (int) $order->id;
        $website = $this->resolve_website($order);
        if (is_string($website)) {
            $this->note($order, __('Emails not added', 'waas-host'), $website . ' No emails were added; add them to the right website by hand.', 'error');
            return;
        }
        [$subscription_id, $external_id] = $website;
        $domain = $this->domain($subscription_id);

        $done = $this->store->get_order_meta_value($order, self::ORDER_DONE_META);
        $done = is_array($done) ? $done : [];
        $error = null;
        foreach ($lines as $line) {
            $reference = sprintf('fluentcart-order-%d-%d', $order_id, $line['id']);
            try {
                $result = $this->provisioning_api->add_email_packs($external_id, $line['emails'], $reference);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                break;
            }

            $total = is_object($result) && isset($result->emailPacks) ? (int) $result->emailPacks : null;
            $done[$line['id']] = ['subscription_id' => $subscription_id, 'emails' => $line['emails'], 'at' => time()];
            $this->store->update_order_meta($order, self::ORDER_DONE_META, $done);
            $this->note($order, __('Emails added', 'waas-host'), sprintf(
                '%s emails added to %s (subscription #%d)%s. Reference %s',
                number_format($line['emails']),
                $domain,
                $subscription_id,
                $total !== null ? sprintf('; %s bought in packs in all', number_format($total)) : '',
                $reference
            ));
        }

        if ($error === null) {
            $this->store->update_order_meta($order, self::ORDER_ATTEMPTS_META, 0);
            $this->clear_failure($order_id);
            return;
        }

        $this->failed($order, $subscription_id, $error);
    }

    /**
     * The website to add the emails to: [subscription id, external id], or why there is none.
     *
     * @return array{0: int, 1: string}|string
     */
    public function resolve_website($order)
    {
        $customer_websites = $this->customer_subscription_ids($order);
        $chosen = (int) $this->store->get_order_meta($order, self::ORDER_WEBSITE_META);

        if ($chosen > 0) {
            if (!in_array($chosen, $customer_websites, true)) {
                return sprintf('The website chosen for this order (subscription #%d) is not this customer\'s.', $chosen);
            }
            if (!$this->can_buy_emails($chosen)) {
                return sprintf('The website chosen for this order (subscription #%d) has no live website.', $chosen);
            }

            return [$chosen, (string) $this->adapter->get_subscription_meta($chosen, Tenant::TENANT_EXTERNAL_ID_META)];
        }

        $live = array_values(array_filter($customer_websites, fn ($subscription_id) => $this->is_live($subscription_id)));
        if (count($live) !== 1) {
            return $live
                ? sprintf('No website was chosen for this order and the customer has %d live websites.', count($live))
                : 'No website was chosen for this order and the customer has no live website.';
        }

        return [$live[0], (string) $this->adapter->get_subscription_meta($live[0], Tenant::TENANT_EXTERNAL_ID_META)];
    }

    /** A website that is up: it has a store that isn't deleted, and its subscription is active. */
    private function is_live($subscription_id): bool
    {
        return $this->can_buy_emails($subscription_id) && $this->adapter->is_subscription_active($subscription_id);
    }

    /**
     * Subscriptions of the order's customer: their account's (also customers FluentCart left unlinked under its email)
     * and the FluentCart customer's own.
     *
     * @return int[]
     */
    private function customer_subscription_ids($order): array
    {
        $customer = $this->store->get_order_customer($order);
        if ($customer === null) {
            return [];
        }

        $user_id = $customer['user_id'];
        if ($user_id <= 0 && $customer['email'] !== '') {
            $user = get_user_by('email', $customer['email']);
            $user_id = $user ? (int) $user->ID : 0;
        }

        $ids = array_merge(
            $user_id > 0 ? $this->adapter->get_customer_subscription_ids($user_id) : [],
            $this->store->find_customer_subscription_ids($customer['id'])
        );

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function domain(int $subscription_id): string
    {
        return (string) ($this->adapter->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META)
            ?: $this->adapter->get_subscription_meta($subscription_id, Tenant::BASE_DOMAIN_NAME_META)
            ?: 'the website');
    }

    private function failed($order, int $subscription_id, string $error): void
    {
        $order_id = (int) $order->id;
        $attempts = (int) $this->store->get_order_meta($order, self::ORDER_ATTEMPTS_META) + 1;
        $this->store->update_order_meta($order, self::ORDER_ATTEMPTS_META, $attempts);
        error_log(sprintf('WaaS Host: adding email packs for order %d (subscription %d) failed (try %d): %s', $order_id, $subscription_id, $attempts, $error));

        if ($attempts <= count(StoreJobs::RETRY_DELAYS)) {
            $delay = StoreJobs::RETRY_DELAYS[$attempts - 1];
            $this->schedule_retry($order_id, time() + $delay);
            $this->note($order, __('Emails not added yet', 'waas-host'), sprintf('The provisioning API failed: %s. Trying again in %d minutes.', $error, (int) ceil($delay / 60)), 'error');
            return;
        }

        $failures = get_option(self::FAILED_OPTION, []);
        $failures = is_array($failures) ? $failures : [];
        $failures[$order_id] = ['error' => $error, 'attempts' => $attempts, 'at' => time()];
        update_option(self::FAILED_OPTION, $failures, false);
        $this->note($order, __('Emails not added', 'waas-host'), sprintf('The provisioning API failed %d times: %s. Use Retry in the WordPress admin notice once the API works again.', $attempts, $error), 'error');
        do_action('waas_email_packs_failed', $order_id, $subscription_id, $error);
    }

    private function schedule_retry(int $order_id, int $timestamp): void
    {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, self::RETRY_HOOK, [$order_id], StoreJobs::GROUP);
        } else {
            wp_schedule_single_event($timestamp, self::RETRY_HOOK, [$order_id]);
        }
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
        $order->addLog($title, $detail, $type, 'WaaS');
    }

    public function show_failed_notice(): void
    {
        $failures = get_option(self::FAILED_OPTION, []);
        if (!current_user_can('manage_options') || !is_array($failures) || !$failures) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>' . esc_html(sprintf(
            /* translators: %d: number of orders */
            _n('Emails bought in %d order could not be added to the website.', 'Emails bought in %d orders could not be added to the websites.', count($failures), 'waas-host'),
            count($failures)
        )) . '</strong></p><ul>';
        foreach ($failures as $order_id => $failure) {
            $retry_url = wp_nonce_url(
                admin_url('admin-post.php?action=' . self::RETRY_ACTION . '&order_id=' . (int) $order_id),
                self::RETRY_ACTION . '_' . (int) $order_id
            );
            echo '<li>' . esc_html(sprintf(
                'Order #%d: failed %d times, last at %s: %s',
                $order_id,
                $failure['attempts'] ?? 0,
                wp_date('Y-m-d H:i', (int) ($failure['at'] ?? 0)),
                $failure['error'] ?? ''
            )) . ' <a href="' . esc_url($retry_url) . '">' . esc_html__('Retry', 'waas-host') . '</a></li>';
        }
        echo '</ul></div>';
    }

    public function handle_retry_request(): void
    {
        $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'waas-host'), 403);
        }
        check_admin_referer(self::RETRY_ACTION . '_' . $order_id);

        $this->clear_failure($order_id);
        $order = $this->store->find_order($order_id);
        if ($order) {
            $this->store->update_order_meta($order, self::ORDER_ATTEMPTS_META, 0);
            $this->process($order);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
