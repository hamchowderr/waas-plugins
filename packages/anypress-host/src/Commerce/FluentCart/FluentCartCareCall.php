<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Core\Tenant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checkout links for care, made on AnyPress > Care: one link buys one package or one-time help for one customer's
 * website, so the team can send it by email. Customers can also buy care themselves, from their website's card and
 * from the thank-you page after a plan (FluentCartCarePortal); a link only saves them picking the website.
 */
class FluentCartCareCall
{
    public const LINK_ACTION = 'anypress_care_pay_link';

    private FluentCartStore $store;
    private FluentCartCare $care;

    public function __construct(FluentCartStore $store, FluentCartCare $care)
    {
        $this->store = $store;
        $this->care = $care;
    }

    /** A checkout link that buys one product for one website: its instant checkout URL plus the website. */
    public static function pay_link(string $checkout_url, int $website_id): string
    {
        return add_query_arg(FluentCartEmailPacks::QUERY_ARG, $website_id, $checkout_url);
    }

    // ---- AnyPress > Care: checkout links ----

    /**
     * What a link can be for: each care package's monthly and yearly price, and the one-time help anyone can buy
     * (not the extra hour at the care price, which only websites with care may buy).
     *
     * @return array<int, array{product_id: int, checkout_url: string, label: string}> variation id => offer
     */
    public function offers(): array
    {
        $offers = [];
        foreach (array_merge($this->care->packages(), $this->care->services()) as $product) {
            if (AnyPressProvisioningIntegration::is_care_only($product['settings'] ?? [])) {
                continue;
            }
            foreach ($product['variations'] as $variation) {
                $offers[(int) $variation['id']] = [
                    'product_id' => (int) $product['product_id'],
                    'checkout_url' => (string) $variation['checkout_url'],
                    'label' => $product['title'] . ' · ' . FluentCartCarePortal::price_label($variation),
                ];
            }
        }

        return $offers;
    }

    /** @return array<int, string> live website => "domain · customer email" */
    public function live_websites(): array
    {
        $websites = [];
        foreach ($this->store->find_subscription_meta(Tenant::TENANT_STATE, []) as $website_id => $state) {
            if ($state !== Tenant::READY || !$this->care->is_live_website($website_id)) {
                continue;
            }
            $customer = $this->store->get_subscription_customer($website_id) ?? [];
            $websites[(int) $website_id] = implode(' · ', array_filter([$this->care->domain((int) $website_id), (string) ($customer['email'] ?? '')]));
        }
        asort($websites);

        return $websites;
    }

    /**
     * The link asked for on AnyPress > Care (website and offer in the query, with its nonce).
     *
     * @return array{url: string, website: string, offer: string}|null
     */
    public function requested_link(): ?array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked right below.
        $asked = isset($_GET['pay_website'], $_GET['pay_offer'], $_GET['_wpnonce']) && current_user_can('manage_options');
        if (!$asked || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::LINK_ACTION)) {
            return null;
        }
        $website_id = absint(wp_unslash($_GET['pay_website']));
        $variation_id = absint(wp_unslash($_GET['pay_offer']));
        // phpcs:enable
        $websites = $this->live_websites();
        $offers = $this->offers();
        if (!isset($websites[$website_id], $offers[$variation_id])) {
            return null;
        }
        return [
            'url' => self::pay_link($offers[$variation_id]['checkout_url'], $website_id),
            'website' => $websites[$website_id],
            'offer' => $offers[$variation_id]['label'],
        ];
    }

    /** The "Checkout links" card. */
    public function render_card(string $page): void
    {
        $link = $this->requested_link();
        $websites = $this->live_websites();
        $offers = $this->offers();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- only keeps the last choice selected.
        $chosen_site = isset($_GET['pay_website']) ? absint(wp_unslash($_GET['pay_website'])) : 0;
        $chosen_offer = isset($_GET['pay_offer']) ? absint(wp_unslash($_GET['pay_offer'])) : 0;
        // phpcs:enable

        echo '<section class="ap-card ap-care-call"><div class="ap-card-head"><div><h2>' . esc_html__('Checkout links', 'anypress-host') . '</h2><p class="ap-muted">'
            . esc_html__('Customers can add care themselves from their account. To send one a link by email, pick their website and the package or help: the link opens checkout with the website already chosen.', 'anypress-host')
            . '</p></div></div>';

        if (!$websites || !$offers) {
            echo '<p class="ap-empty">' . esc_html__('Links can be made once the shop has a live website and published care products.', 'anypress-host') . '</p></section>';
            return;
        }
        echo '<form class="ap-care-form" method="get" action="' . esc_url(admin_url('admin.php')) . '"><input type="hidden" name="page" value="' . esc_attr($page) . '">';
        wp_nonce_field(self::LINK_ACTION, '_wpnonce', false);
        echo '<label><span>' . esc_html__('Website', 'anypress-host') . '</span><select name="pay_website" required>';
        foreach ($websites as $website_id => $label) {
            echo '<option value="' . esc_attr((string) $website_id) . '"' . selected($website_id, $chosen_site, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label><label><span>' . esc_html__('Package or help', 'anypress-host') . '</span><select name="pay_offer" required>';
        foreach ($offers as $variation_id => $offer) {
            echo '<option value="' . esc_attr((string) $variation_id) . '"' . selected($variation_id, $chosen_offer, false) . '>' . esc_html($offer['label']) . '</option>';
        }
        echo '</select></label><button type="submit" class="ap-btn is-ink">' . esc_html__('Make a link', 'anypress-host') . '</button></form>';

        if ($link) {
            echo '<div class="ap-banner is-ok" role="status"><span>' . esc_html(sprintf(
                /* translators: 1: package, 2: website */
                __('Link for %1$s on %2$s. Copy it into your email to the customer:', 'anypress-host'),
                $link['offer'],
                $link['website']
            )) . '</span></div><input class="ap-care-paylink" type="text" readonly value="' . esc_attr($link['url']) . '" onfocus="this.select()" aria-label="' . esc_attr__('Checkout link', 'anypress-host') . '">';
        }
        echo '</section>';
    }
}
