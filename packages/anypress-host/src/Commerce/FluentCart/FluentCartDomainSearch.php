<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Core\Tenant;

/**
 * The "Get a new domain" section on the customer's Website page (FluentCartDomains): the search, the pick and its owner,
 * and "Continue to checkout", which makes a quote and opens FluentCart's instant checkout for it.
 */
class FluentCartDomainSearch
{
    private ProvisioningApi $provisioning_api;
    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    private FluentCartDomains $domains;

    public function __construct(ProvisioningApi $provisioning_api, FluentCartAdapter $adapter, FluentCartStore $store, FluentCartDomains $domains)
    {
        $this->provisioning_api = $provisioning_api;
        $this->adapter = $adapter;
        $this->store = $store;
        $this->domains = $domains;
    }

    public function register(): void
    {
        add_action('admin_post_' . FluentCartDomains::CHECKOUT_ACTION, [$this, 'handle_checkout_request']);
        add_action('admin_post_nopriv_' . FluentCartDomains::CHECKOUT_ACTION, [$this, 'handle_logged_out_request']);
    }

    private static function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }

    /** The "Get your own domain" section of a live website's card. */
    public function render_section($subscription_id): void
    {
        if (!FluentCartDomains::enabled() || !$this->domains->is_live($subscription_id)) {
            return;
        }
        $subscription_id = (int) $subscription_id;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a search on the customer's own page; buying posts with a nonce.
        $for_this = isset($_GET[FluentCartDomains::SITE_ARG]) && absint(wp_unslash($_GET[FluentCartDomains::SITE_ARG])) === $subscription_id;
        $query = $for_this && isset($_GET[FluentCartDomains::SEARCH_ARG]) ? sanitize_text_field(wp_unslash($_GET[FluentCartDomains::SEARCH_ARG])) : '';
        $result = $for_this && isset($_GET[FluentCartDomains::RESULT_ARG]) ? sanitize_key(wp_unslash($_GET[FluentCartDomains::RESULT_ARG])) : '';
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
        echo '<input type="hidden" name="' . esc_attr(FluentCartDomains::SITE_ARG) . '" value="' . esc_attr((string) $subscription_id) . '" />';
        echo '<label for="anypress-domain-q-' . esc_attr((string) $subscription_id) . '">' . esc_html__('Search for a name', 'anypress-host')
            . '<input type="search" id="anypress-domain-q-' . esc_attr((string) $subscription_id) . '" name="' . esc_attr(FluentCartDomains::SEARCH_ARG) . '" value="' . esc_attr($query) . '" placeholder="mybakery" maxlength="63" required /></label>';
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
        echo '<input type="hidden" name="action" value="' . esc_attr(FluentCartDomains::CHECKOUT_ACTION) . '" />';
        echo '<input type="hidden" name="subscription_id" value="' . esc_attr((string) $subscription_id) . '" />';
        echo '<input type="hidden" name="query" value="' . esc_attr($query) . '" />';
        wp_nonce_field(FluentCartDomains::CHECKOUT_ACTION . '_' . $subscription_id);
        echo '<p class="anypress-acct-step"><b>1.</b> ' . esc_html__('Pick a domain', 'anypress-host') . '</p><ul class="anypress-acct-domain-list">';
        foreach ($available as $i => $offer) {
            $first = FluentCartDomains::shop_price((int) $offer->priceCents);
            $yearly = FluentCartDomains::shop_price((int) $offer->renewalPriceCents);
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
            FluentCartDomains::SITE_ARG => $subscription_id,
            FluentCartDomains::SEARCH_ARG => rawurlencode($query),
            FluentCartDomains::RESULT_ARG => $result,
        ], $this->store->get_portal_url(FluentCartPortal::ENDPOINT)));
        exit;
    }

    /** "Continue to checkout": checks the pick again with the API, makes a quote, and goes to FluentCart's checkout. */
    public function handle_checkout_request(): void
    {
        $subscription_id = isset($_POST['subscription_id']) ? absint(wp_unslash($_POST['subscription_id'])) : 0;
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, FluentCartDomains::CHECKOUT_ACTION . '_' . $subscription_id)) {
            $this->back($subscription_id, $query, 'expired');
        }
        $user_id = get_current_user_id();
        if (!FluentCartDomains::enabled() || $user_id === 0 || $this->adapter->get_customer_id($subscription_id) !== $user_id || !$this->domains->is_live($subscription_id)) {
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

        $quote_id = $this->domains->make_quote([
            'user_id' => $user_id,
            'subscription_id' => $subscription_id,
            'external_id' => (string) $this->adapter->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META),
            'domain' => $domain,
            'contact' => $contact,
            'first_cents' => FluentCartDomains::shop_price((int) $offer->priceCents),
            'yearly_cents' => FluentCartDomains::shop_price((int) $offer->renewalPriceCents),
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
}
