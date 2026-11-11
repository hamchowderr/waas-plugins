<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Core\Tenant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Care starts with a call. Customers book a short call from the care offers (the website card, a plan's product page),
 * and nothing is bought by itself: after the call the team makes a pay link on AnyPress > Care for one website and one
 * package or service, and sends it. The link carries the website, the end of its validity and a signature over both and
 * the product; checkout turns away care and one-time help without such a link (FluentCartCarePortal::validate_checkout).
 * The extra hour at the care price stays one click away for websites that already have care.
 */
class FluentCartCareCall
{
    /** Option: where customers book the call (a FluentBooking page, or any booking link). */
    public const URL_OPTION = 'anypress_care_call_url';
    /** Query args on a pay link: its signature and the time it ends. */
    public const PAY_ARG = 'anypress_care_pay';
    public const UNTIL_ARG = 'anypress_care_until';
    /** Cart checkout_data key: the pay link's signature and end. */
    public const CART_KEY = 'anypress_care_pay';
    /** Days a pay link works. */
    public const PAY_DAYS = 14;
    public const SAVE_ACTION = 'anypress_care_call_url';
    public const LINK_ACTION = 'anypress_care_pay_link';

    private FluentCartStore $store;
    private FluentCartCare $care;

    public function __construct(FluentCartStore $store, FluentCartCare $care)
    {
        $this->store = $store;
        $this->care = $care;
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'remember_link']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);
    }

    /** Where to book the call: the shop's booking link, else an email to support. */
    public static function booking_url(): string
    {
        $url = esc_url_raw((string) get_option(self::URL_OPTION, ''));

        return $url !== '' ? $url : 'mailto:support@anypress.io?subject=' . rawurlencode(__('Book a care call', 'anypress-host'));
    }

    /** A pay link for one website and one product: its instant checkout URL plus the website, end and signature. */
    public static function pay_link(string $checkout_url, int $website_id, int $product_id, int $until): string
    {
        return add_query_arg([
            FluentCartEmailPacks::QUERY_ARG => $website_id,
            self::UNTIL_ARG => $until,
            self::PAY_ARG => self::signature($website_id, $product_id, $until),
        ], $checkout_url);
    }

    private static function signature(int $website_id, int $product_id, int $until): string
    {
        return substr(hash_hmac('sha256', 'care-pay|' . $website_id . '|' . $product_id . '|' . $until, wp_salt('auth')), 0, 32);
    }

    /**
     * On the checkout page FluentCart's instant checkout redirects to (it carries fct_cart_hash): keeps the pay link's
     * signature and end in the cart, or clears one left from an earlier link.
     */
    public function remember_link(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a link to the cart; paid_by_link() checks the signature.
        $cart_hash = isset($_GET['fct_cart_hash']) ? sanitize_text_field(wp_unslash($_GET['fct_cart_hash'])) : '';
        $cart = $cart_hash !== '' ? $this->store->find_cart_by_hash($cart_hash) : null;
        if (!$cart) {
            return;
        }
        $signature = isset($_GET[self::PAY_ARG]) ? sanitize_key(wp_unslash($_GET[self::PAY_ARG])) : '';
        $until = isset($_GET[self::UNTIL_ARG]) ? absint(wp_unslash($_GET[self::UNTIL_ARG])) : 0;
        // phpcs:enable
        $this->store->set_cart_checkout_value($cart, self::CART_KEY, $signature !== '' ? ['signature' => $signature, 'until' => $until] : null);
    }

    /**
     * Whether the cart came from a pay link that is still good for its website and every one of these products.
     *
     * @param int[] $product_ids
     */
    public function paid_by_link($cart, array $product_ids, ?int $now = null): bool
    {
        $link = $this->store->get_cart_checkout_value($cart, self::CART_KEY);
        $website_id = (int) $this->store->get_cart_checkout_value($cart, FluentCartEmailPacks::CART_KEY);
        if (!is_array($link) || $website_id <= 0 || !$product_ids) {
            return false;
        }
        $until = (int) ($link['until'] ?? 0);
        if ($until < ($now ?? time())) {
            return false;
        }
        foreach ($product_ids as $product_id) {
            if (!hash_equals(self::signature($website_id, (int) $product_id, $until), (string) ($link['signature'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    // ---- AnyPress > Care: the booking link and pay links ----

    /**
     * What a pay link can be for: each care package's monthly and yearly price, and the one-time help anyone can buy
     * (not the extra hour at the care price, which needs no call).
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
     * The pay link asked for on AnyPress > Care (website and offer in the query, with its nonce).
     *
     * @return array{url: string, until: int, website: string, offer: string}|null
     */
    public function requested_link(?int $now = null): ?array
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
        $until = ($now ?? time()) + self::PAY_DAYS * 86400;

        return [
            'url' => self::pay_link($offers[$variation_id]['checkout_url'], $website_id, $offers[$variation_id]['product_id'], $until),
            'until' => $until,
            'website' => $websites[$website_id],
            'offer' => $offers[$variation_id]['label'],
        ];
    }

    /** The "Calls and pay links" card. */
    public function render_card(string $page): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows that the link was saved.
        $saved = isset($_GET['care_call_saved']);
        $link = $this->requested_link();
        $websites = $this->live_websites();
        $offers = $this->offers();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- only keeps the last choice selected.
        $chosen_site = isset($_GET['pay_website']) ? absint(wp_unslash($_GET['pay_website'])) : 0;
        $chosen_offer = isset($_GET['pay_offer']) ? absint(wp_unslash($_GET['pay_offer'])) : 0;
        // phpcs:enable

        echo '<section class="ap-card ap-care-call"><div class="ap-card-head"><div><h2>' . esc_html__('Calls and pay links', 'anypress-host') . '</h2><p class="ap-muted">' . esc_html(sprintf(
            /* translators: %d: days */
            __('Care starts with a call: customers see "Book a call" instead of checkout. After the call, make a pay link for their website and send it to them. It works for %d days.', 'anypress-host'),
            self::PAY_DAYS
        )) . '</p></div></div>';
        if ($saved) {
            echo '<div class="ap-banner is-ok" role="status"><span>' . esc_html__('Booking link saved.', 'anypress-host') . '</span></div>';
        }

        echo '<form class="ap-care-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '">';
        wp_nonce_field(self::SAVE_ACTION);
        echo '<label class="ap-care-note"><span>' . esc_html__('Booking link', 'anypress-host') . '</span><input type="url" name="url" value="' . esc_attr((string) get_option(self::URL_OPTION, '')) . '" placeholder="https://"></label>'
            . '<button type="submit" class="ap-btn">' . esc_html__('Save', 'anypress-host') . '</button></form>'
            . '<p class="ap-muted">' . esc_html__('Where the "Book a call" buttons go, such as a FluentBooking page. Left empty, they open an email to support@anypress.io.', 'anypress-host') . '</p>';

        if (!$websites || !$offers) {
            echo '<p class="ap-empty">' . esc_html__('Pay links can be made once the shop has a live website and published care products.', 'anypress-host') . '</p></section>';
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
        echo '</select></label><button type="submit" class="ap-btn is-ink">' . esc_html__('Make a pay link', 'anypress-host') . '</button></form>';

        if ($link) {
            echo '<div class="ap-banner is-ok" role="status"><span>' . esc_html(sprintf(
                /* translators: 1: package, 2: website, 3: date */
                __('Pay link for %1$s on %2$s, good until %3$s. Copy it into your email to the customer:', 'anypress-host'),
                $link['offer'],
                $link['website'],
                wp_date((string) get_option('date_format'), $link['until'])
            )) . '</span></div><input class="ap-care-paylink" type="text" readonly value="' . esc_attr($link['url']) . '" onfocus="this.select()" aria-label="' . esc_attr__('Pay link', 'anypress-host') . '">';
        }
        echo '</section>';
    }

    /** "Save": the booking link. */
    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'anypress-host'), 403);
        }
        check_admin_referer(self::SAVE_ACTION);
        $url = isset($_POST['url']) ? esc_url_raw(trim(sanitize_text_field(wp_unslash($_POST['url'])))) : '';
        update_option(self::URL_OPTION, $url, false);

        wp_safe_redirect(add_query_arg('care_call_saved', 1, admin_url('admin.php?page=' . FluentCartCareDesk::PAGE)));
        exit;
    }
}
