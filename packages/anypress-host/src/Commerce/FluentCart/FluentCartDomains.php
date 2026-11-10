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

    public function __construct(ProvisioningApi $provisioning_api, FluentCartAdapter $adapter, FluentCartStore $store)
    {
        $this->provisioning_api = $provisioning_api;
        $this->adapter = $adapter;
        $this->store = $store;
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
        add_action('admin_post_' . self::CHECKOUT_ACTION, [$this, 'handle_checkout_request']);
        add_action('admin_post_nopriv_' . self::CHECKOUT_ACTION, [$this, 'handle_logged_out_request']);
        add_action('admin_notices', [$this, 'show_failed_notice']);
        add_action('admin_post_' . self::RETRY_ACTION, [$this, 'handle_retry_request']);
    }

    /** What the customer pays for a year, in cents: the storefront's price plus the shop's own amount. */
    public static function shop_price(int $api_cents): int
    {
        $extra = max(0, (int) get_option(self::EXTRA_OPTION, 0)) * 100;

        return (int) apply_filters('anypress_domain_price', $api_cents + $extra, $api_cents);
    }

    private static function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }

    // ---- The Website page ----

    /** The "Get your own domain" section of a live website's card. */
    public function render_section($subscription_id): void
    {
        if (!self::enabled() || !$this->is_live($subscription_id)) {
            return;
        }
        $subscription_id = (int) $subscription_id;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a search on the customer's own page; buying posts with a nonce.
        $for_this = isset($_GET[self::SITE_ARG]) && absint(wp_unslash($_GET[self::SITE_ARG])) === $subscription_id;
        $query = $for_this && isset($_GET[self::SEARCH_ARG]) ? sanitize_text_field(wp_unslash($_GET[self::SEARCH_ARG])) : '';
        $result = $for_this && isset($_GET[self::RESULT_ARG]) ? sanitize_key(wp_unslash($_GET[self::RESULT_ARG])) : '';
        // phpcs:enable

        echo '<details class="anypress-acct-panel anypress-acct-domains"' . ($for_this ? ' open' : '') . '><summary>' . esc_html__('Get a new domain', 'anypress-host')
            . ' <small>' . esc_html__('Registered in your name and set up for you', 'anypress-host') . '</small></summary><div class="anypress-acct-panel-body">';
        if ($result !== '') {
            $messages = [
                'expired' => __('The page was open too long. Search again and pick your domain.', 'anypress-host'),
                'gone' => __('That domain can no longer be bought. Pick another one.', 'anypress-host'),
                'missing' => __('Fill in every field to register the domain.', 'anypress-host'),
                'error' => __('We could not check that domain just now. Try again in a minute.', 'anypress-host'),
            ];
            if (isset($messages[$result])) {
                echo '<p class="anypress-acct-error" role="alert">' . esc_html($messages[$result]) . '</p>';
            }
        }

        echo '<form method="get" action="' . esc_url($this->store->get_portal_url(FluentCartPortal::ENDPOINT)) . '" class="anypress-acct-form anypress-acct-domain-search">';
        echo '<input type="hidden" name="' . esc_attr(self::SITE_ARG) . '" value="' . esc_attr((string) $subscription_id) . '" />';
        echo '<label for="anypress-domain-q-' . esc_attr((string) $subscription_id) . '">' . esc_html__('Search for a name', 'anypress-host')
            . '<input type="search" id="anypress-domain-q-' . esc_attr((string) $subscription_id) . '" name="' . esc_attr(self::SEARCH_ARG) . '" value="' . esc_attr($query) . '" placeholder="mybakery" maxlength="63" required /></label>';
        echo '<button type="submit" class="anypress-acct-btn' . ($query === '' ? ' is-primary fct-btn-primary' : ' fct-btn-secondary') . ' fct-btn">' . esc_html__('Search', 'anypress-host') . '</button>';
        echo '</form>';

        if ($query !== '') {
            $this->render_results($subscription_id, $query);
        }
        echo '</div></details>';
    }

    private function render_results(int $subscription_id, string $query): void
    {
        try {
            $offers = $this->provisioning_api->search_domains($query);
        } catch (\Throwable $e) {
            error_log('AnyPress Host: domain search failed: ' . $e->getMessage());
            echo '<p class="anypress-acct-error" role="alert">' . esc_html__('We could not search for domains just now. Try again in a minute.', 'anypress-host') . '</p>';
            return;
        }

        $available = array_values(array_filter($offers, fn ($o) => !empty($o->available)));
        if (!$available) {
            echo '<p class="anypress-acct-note">' . esc_html__('Nothing available for that name. Try another one.', 'anypress-host') . '</p>';
            return;
        }

        $user = wp_get_current_user();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="anypress-acct-domain-buy">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::CHECKOUT_ACTION) . '" />';
        echo '<input type="hidden" name="subscription_id" value="' . esc_attr((string) $subscription_id) . '" />';
        echo '<input type="hidden" name="query" value="' . esc_attr($query) . '" />';
        wp_nonce_field(self::CHECKOUT_ACTION . '_' . $subscription_id);
        echo '<p class="anypress-acct-step"><b>1.</b> ' . esc_html__('Pick a domain', 'anypress-host') . '</p><ul class="anypress-acct-domain-list">';
        foreach ($available as $i => $offer) {
            $first = self::shop_price((int) $offer->priceCents);
            $yearly = self::shop_price((int) $offer->renewalPriceCents);
            echo '<li><label><input type="radio" name="domain" value="' . esc_attr((string) $offer->domainName) . '"' . ($i === 0 ? ' checked' : '') . ' required />'
                . '<strong>' . esc_html((string) $offer->domainName) . '</strong><span>'
                // A first year cheaper than the rest is charged at the yearly price: the subscription bills one price.
                . esc_html($first <= $yearly
                    /* translators: %s: price */
                    ? sprintf(__('%s a year', 'anypress-host'), self::money($yearly))
                    /* translators: 1: first year's price, 2: each later year's price */
                    : sprintf(__('%1$s the first year, then %2$s a year', 'anypress-host'), self::money($first), self::money($yearly)))
                . '</span></label></li>';
        }
        echo '</ul>';

        echo '<p class="anypress-acct-step"><b>2.</b> ' . esc_html__('Who the domain belongs to', 'anypress-host') . '</p>';
        echo '<p class="anypress-acct-note">' . esc_html__('It is registered in this name and kept private in public lookups. The registry may email this address once to confirm it.', 'anypress-host') . '</p>';
        echo '<div class="anypress-acct-contact">';
        foreach (self::contact_fields() as $key => [$label, $required]) {
            $value = $key === 'firstName' ? $user->first_name : ($key === 'lastName' ? $user->last_name : ($key === 'email' ? $user->user_email : ($key === 'country' ? 'US' : '')));
            $id = 'anypress-domain-' . $key . '-' . $subscription_id;
            $wide = in_array($key, ['companyName', 'email', 'address1', 'address2'], true);
            echo '<label for="' . esc_attr($id) . '"' . ($wide ? ' class="is-wide"' : '') . '><span>' . esc_html($label) . ($required ? '' : ' <small>' . esc_html__('(optional)', 'anypress-host') . '</small>') . '</span>'
                . '<input type="' . ($key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text')) . '" id="' . esc_attr($id) . '" name="contact[' . esc_attr($key) . ']" value="' . esc_attr((string) $value) . '"'
                . ($required ? ' required' : '') . ($key === 'phone' ? ' placeholder="+1.5555555555"' : '') . ($key === 'country' ? ' maxlength="2"' : '') . ' /></label>';
        }
        echo '</div>';
        echo '<button type="submit" class="anypress-acct-btn is-primary fct-btn fct-btn-primary">' . esc_html__('Continue to checkout', 'anypress-host') . '</button>';
        echo '</form>';
    }

    /** @return array<string, array{0: string, 1: bool}> key => [label, required] */
    private static function contact_fields(): array
    {
        return [
            'firstName' => [__('First name', 'anypress-host'), true],
            'lastName' => [__('Last name', 'anypress-host'), true],
            'companyName' => [__('Business name', 'anypress-host'), false],
            'email' => [__('Email', 'anypress-host'), true],
            'phone' => [__('Phone', 'anypress-host'), true],
            'address1' => [__('Address', 'anypress-host'), true],
            'address2' => [__('Address line 2', 'anypress-host'), false],
            'city' => [__('City', 'anypress-host'), true],
            'state' => [__('State or region', 'anypress-host'), true],
            'zip' => [__('ZIP or postal code', 'anypress-host'), true],
            'country' => [__('Country code', 'anypress-host'), true],
        ];
    }

    /** Back to the website's domain section on the Website page, with a message. */
    /** @return never */
    private function back(int $subscription_id, string $query, string $result): void
    {
        wp_safe_redirect(add_query_arg([
            self::SITE_ARG => $subscription_id,
            self::SEARCH_ARG => rawurlencode($query),
            self::RESULT_ARG => $result,
        ], $this->store->get_portal_url(FluentCartPortal::ENDPOINT)));
        exit;
    }

    /** "Continue to checkout": checks the pick again with the API, makes a quote, and goes to FluentCart's checkout. */
    public function handle_checkout_request(): void
    {
        $subscription_id = isset($_POST['subscription_id']) ? absint(wp_unslash($_POST['subscription_id'])) : 0;
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::CHECKOUT_ACTION . '_' . $subscription_id)) {
            $this->back($subscription_id, $query, 'expired');
        }
        $user_id = get_current_user_id();
        if (!self::enabled() || $user_id === 0 || $this->adapter->get_customer_id($subscription_id) !== $user_id || !$this->is_live($subscription_id)) {
            wp_die(esc_html__('You are not allowed to do this.', 'anypress-host'), 403);
        }

        $domain = isset($_POST['domain']) ? strtolower(sanitize_text_field(wp_unslash($_POST['domain']))) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
        $posted = isset($_POST['contact']) && is_array($_POST['contact']) ? wp_unslash($_POST['contact']) : [];
        $contact = [];
        foreach (self::contact_fields() as $key => [, $required]) {
            $value = isset($posted[$key]) ? trim(sanitize_text_field((string) $posted[$key])) : '';
            if ($value === '' && $required) {
                $this->back($subscription_id, $query, 'missing');
            }
            if ($value !== '') {
                $contact[$key] = $key === 'country' ? strtoupper($value) : $value;
            }
        }
        if ($domain === '' || !is_email($contact['email'] ?? '')) {
            $this->back($subscription_id, $query, 'missing');
        }

        try {
            $offers = $this->provisioning_api->search_domains($domain);
        } catch (\Throwable $e) {
            error_log('AnyPress Host: domain check failed: ' . $e->getMessage());
            $this->back($subscription_id, $query, 'error');
        }
        $offer = null;
        foreach ($offers as $candidate) {
            if (isset($candidate->domainName) && strtolower((string) $candidate->domainName) === $domain && !empty($candidate->available)) {
                $offer = $candidate;
            }
        }
        if (!$offer) {
            $this->back($subscription_id, $query, 'gone');
        }

        $quote_id = $this->make_quote([
            'user_id' => $user_id,
            'subscription_id' => $subscription_id,
            'external_id' => (string) $this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META),
            'domain' => $domain,
            'contact' => $contact,
            'first_cents' => self::shop_price((int) $offer->priceCents),
            'yearly_cents' => self::shop_price((int) $offer->renewalPriceCents),
            'made_at' => time(),
        ]);

        wp_safe_redirect(site_url('?fluent-cart=instant_checkout&is_custom=1&quantity=1&item_id=' . $quote_id));
        exit;
    }

    /** Logged out since the page opened: back to the Website page, which asks them to log in first. */
    public function handle_logged_out_request(): void
    {
        wp_safe_redirect($this->store->get_portal_url(FluentCartPortal::ENDPOINT));
        exit;
    }

    private function make_quote(array $quote): int
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
    private function is_live($subscription_id): bool
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
