<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Api\SupportDesk;
use AnyPressHost\Core\Tenant;
use AnyPressHost\Features\AccountStyles;

/**
 * Where customers see and buy care (FluentCartCare): a "Want us to look after it?" box under the buttons of each website
 * plan's product page, and a Care section on each live website's card on the account's Website page.
 *
 * Care starts with a call (FluentCartCareCall): both places show the packages and one-time help with their prices and a
 * "Book a call" button, and after the call the team sends a pay link for the package that fits. Checkout turns away
 * care and one-time help bought without one. On the card, a website with care shows its package, this month's time
 * left and an "Ask for a change" form, which files a ticket in the shop's Fluent Support, and the extra hour at the care
 * price stays one click from checkout.
 */
class FluentCartCarePortal
{
    public const REQUEST_ACTION = 'anypress_care_request';
    public const ATTACH_ACTION = 'anypress_care_attach';
    /** Query args after a request or attach: the website, and how it went. */
    public const SITE_ARG = 'anypress_care_site';
    public const RESULT_ARG = 'anypress_care_result';
    /** Characters kept of a request. */
    public const MAX_REQUEST = 5000;

    private FluentCartAdapter $adapter;
    private FluentCartStore $store;
    private FluentCartCare $care;
    private FluentCartCareCall $call;

    public function __construct(FluentCartAdapter $adapter, FluentCartStore $store, FluentCartCare $care, FluentCartCareCall $call)
    {
        $this->adapter = $adapter;
        $this->store = $store;
        $this->care = $care;
        $this->call = $call;
    }

    public function register(): void
    {
        add_action('fluent_cart/product/single/after_actions_block', [$this, 'render_product_box'], 20, 1);
        add_action('admin_post_' . self::REQUEST_ACTION, [$this, 'handle_request']);
        add_action('admin_post_' . self::ATTACH_ACTION, [$this, 'handle_attach']);
        add_filter('fluent_cart/shop_query', [$this, 'hide_from_shop'], 10, 1);
        add_filter('fluent_cart/checkout/validate_data', [$this, 'validate_checkout'], 10, 2);
    }

    /**
     * The published one-time services for anyone (care-only ones left out), or with $care_only the ones only websites
     * with a care package may buy, such as an extra hour at the care price.
     */
    private function services(bool $care_only = false): array
    {
        return array_values(array_filter($this->care->services(), fn ($service) => AnyPressProvisioningIntegration::is_care_only($service['settings'] ?? []) === $care_only));
    }

    /** @return int[] the published care package and service products */
    private function care_product_ids(): array
    {
        return array_map(fn ($product) => (int) $product['product_id'], array_merge($this->care->packages(), $this->care->services()));
    }

    /**
     * fluent_cart/shop_query: care is bought for a website, from its plan's product page or the account, so it stays
     * out of the shop's product lists (the shop page, product blocks and shortcodes).
     *
     * @param mixed $query FluentCart's product query builder
     * @return mixed
     */
    public function hide_from_shop($query)
    {
        $ids = $this->care_product_ids();

        return ($ids && is_object($query)) ? $query->whereNotIn('ID', $ids) : $query;
    }

    /**
     * fluent_cart/checkout/validate_data: care and one-time help start with a call, so a cart with them is turned away
     * unless it came from a pay link the team sent after the call (FluentCartCareCall), still good for its website and
     * products. A care-only service (the extra hour at the care price) needs no link, only a live website with a care
     * package (the buyer signed in, or found by their checkout email).
     *
     * @param array $errors
     * @param array $args data, cart
     */
    public function validate_checkout($errors, $args)
    {
        $cart = $args['cart'] ?? null;
        if (!$cart) {
            return $errors;
        }
        $in_cart = array_map('intval', $this->store->get_cart_product_ids($cart));
        $care = array_values(array_intersect($in_cart, $this->care_product_ids()));
        if (!$care) {
            return $errors;
        }
        $care_only = array_map(fn ($product) => (int) $product['product_id'], $this->services(true));
        if (array_intersect($in_cart, $care_only)) {
            $user_id = get_current_user_id();
            if ($user_id <= 0) {
                $email = sanitize_email((string) ($args['data']['billing_email'] ?? ''));
                $user = $email !== '' ? get_user_by('email', $email) : false;
                $user_id = $user ? (int) $user->ID : 0;
            }
            if ($user_id > 0 && $this->care->has_care_package($user_id)) {
                return $errors;
            }
            $errors = is_array($errors) ? $errors : [];
            $errors['anypress_care']['care_only'] = __('This price is for websites with a care package. Please sign in to the account that has care.', 'anypress-host');

            return $errors;
        }
        if ($this->call->paid_by_link($cart, $care)) {
            return $errors;
        }

        $errors = is_array($errors) ? $errors : [];
        $errors['anypress_care']['call_first'] = sprintf(
            /* translators: %s: booking link */
            __('Care starts with a short call, so we can pick what fits your website. Book one at %s and we will send you a link to pay.', 'anypress-host'),
            FluentCartCareCall::booking_url()
        );

        return $errors;
    }

    private static function money(int $cents): string
    {
        return '$' . number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }

    /** "$199 a month", "$1,990 a year", "$299". */
    public static function price_label(array $variation): string
    {
        switch ($variation['interval']) {
            case 'monthly':
                /* translators: %s: price */
                return sprintf(__('%s a month', 'anypress-host'), self::money($variation['price']));
            case 'yearly':
                /* translators: %s: price */
                return sprintf(__('%s a year', 'anypress-host'), self::money($variation['price']));
            default:
                return self::money($variation['price']);
        }
    }

    /** The package's line under its name: its time and answer time. */
    private static function package_line(array $package): string
    {
        $minutes = AnyPressProvisioningIntegration::minutes($package['settings'], 'care_minutes');
        $answer = sanitize_text_field((string) ($package['settings']['care_answer'] ?? ''));
        $parts = [];
        if ($minutes > 0) {
            /* translators: %s: time, e.g. 2 h */
            $parts[] = sprintf(__('%s of changes a month', 'anypress-host'), FluentCartCare::format_minutes($minutes));
        }
        if ($answer !== '') {
            /* translators: %s: e.g. 1 business day */
            $parts[] = sprintf(__('answered within %s', 'anypress-host'), $answer);
        }

        return implode(', ', $parts);
    }

    /** The "Book a call" button, and what happens after the call. */
    private static function call_button(string $class, string $button_class, string $note_class): string
    {
        return '<p class="' . esc_attr($class) . '"><a class="' . esc_attr($button_class) . '" href="' . esc_url(FluentCartCareCall::booking_url()) . '" target="_blank" rel="noopener">'
            . esc_html__('Book a call', 'anypress-host') . '</a></p><p class="' . esc_attr($note_class) . '">'
            . esc_html__('A short call first, so we can pick what fits your website. After it we send you a link to pay; nothing starts before that.', 'anypress-host') . '</p>';
    }

    // ---- Product page ----

    /**
     * fluent_cart/product/single/after_actions_block: on a website plan's page, the care packages and one-time
     * services with their prices, and a "Book a call" button.
     *
     * @param array $context product, variant, scope
     */
    public function render_product_box($context): void
    {
        $product = is_array($context) ? ($context['product'] ?? null) : null;
        $product_id = is_object($product) ? (int) ($product->ID ?? 0) : 0;
        if ($product_id <= 0 || $this->store->get_plan_feed($product_id) === null) {
            return;
        }
        $packages = $this->care->packages();
        if (!$packages) {
            return;
        }

        echo '<div class="anypress-care-box"><p class="anypress-care-title">' . esc_html__('Want us to look after it?', 'anypress-host') . '</p>';
        echo '<p class="anypress-care-intro">' . esc_html__('A care package is time each month for the changes you need: we make them for you.', 'anypress-host') . '</p><ul class="anypress-care-options">';
        foreach ($packages as $package) {
            $monthly = self::first_variation($package, 'monthly');
            echo '<li class="anypress-care-option"><span><strong>' . esc_html($package['title']) . '</strong> <small>' . esc_html(self::package_line($package)) . '</small></span> <b>' . esc_html(self::price_label($monthly)) . '</b></li>';
        }
        echo '</ul>';

        $services = $this->services();
        if ($services) {
            echo '<p class="anypress-care-sub">' . esc_html__('One-time help', 'anypress-host') . '</p><ul class="anypress-care-services">';
            foreach ($services as $service) {
                echo '<li><span>' . esc_html($service['title']) . '</span> <b>' . esc_html(self::price_label($service['variations'][0])) . '</b></li>';
            }
            echo '</ul>';
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- call_button() escapes.
        echo self::call_button('anypress-care-call', 'anypress-care-btn', 'anypress-care-note');
        echo '<p class="anypress-care-note">' . esc_html__('Care is its own small subscription, separate from the plan, so either can be cancelled alone.', 'anypress-host') . '</p>';
        echo '</div>';
        $this->print_product_assets();
    }

    /** A package's first variation with this interval, else its first. */
    private static function first_variation(array $package, string $interval): array
    {
        foreach ($package['variations'] as $variation) {
            if ($variation['interval'] === $interval) {
                return $variation;
            }
        }

        return $package['variations'][0];
    }

    private function print_product_assets(): void
    {
        $version = defined('ANYPRESS_HOST_VERSION') ? ANYPRESS_HOST_VERSION : null;
        wp_register_style('anypress-care-box', false, [], $version);
        wp_add_inline_style('anypress-care-box', '.anypress-care-box{grid-column:1/-1;flex:1 0 100%;width:100%;box-sizing:border-box;border:1px solid color-mix(in srgb,currentColor 18%,transparent);border-radius:14px;padding:18px;margin:20px 0 0;display:grid;gap:12px;min-width:0}'
            . '.anypress-care-box *{box-sizing:border-box}.anypress-care-box p{margin:0}'
            . '.anypress-care-title{font-weight:700;font-size:1.1em}'
            . '.anypress-care-intro{font-size:.92em;opacity:.75}'
            . '.anypress-care-options{list-style:none;margin:0;padding:0;display:grid;gap:8px}'
            . '.anypress-care-option{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 12px;align-items:center;padding:12px 14px;margin:0;border:1px solid color-mix(in srgb,currentColor 16%,transparent);border-radius:10px}'
            . '.anypress-care-option span{display:grid;gap:1px;min-width:0}.anypress-care-option small{font-size:.86em;opacity:.7}.anypress-care-option b{white-space:nowrap;font-variant-numeric:tabular-nums}'
            . '.anypress-care-sub{margin:6px 0 0;font-size:.78em;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.6}'
            . '.anypress-care-services{list-style:none;margin:0;padding:0;display:grid}'
            . '.anypress-care-services li{display:flex;justify-content:space-between;gap:12px;padding:7px 0;margin:0;border-top:1px solid color-mix(in srgb,currentColor 10%,transparent);font-size:.94em}'
            . '.anypress-care-services li:first-child{border-top:0}.anypress-care-services b{font-weight:600;font-variant-numeric:tabular-nums;white-space:nowrap}'
            . '.anypress-care-btn{display:inline-block;padding:10px 18px;border-radius:999px;font-weight:600;text-decoration:none;background:var(--wp--preset--color--contrast,#1d2327);color:var(--wp--preset--color--base,#fff)}.anypress-care-btn:hover,.anypress-care-btn:focus{opacity:.88;color:var(--wp--preset--color--base,#fff)}'
            . '.anypress-care-note{font-size:.86em;opacity:.65}'
            . '@media (max-width:480px){.anypress-care-box{padding:14px}.anypress-care-option{grid-template-columns:minmax(0,1fr)}}');
        wp_print_styles('anypress-care-box');
    }

    // ---- Account: a website's Care section ----

    /** The Care section of a live website's card. */
    public function render_section($website_id): void
    {
        $website_id = (int) $website_id;
        if ($this->adapter->get_subscription_meta($website_id, Tenant::TENANT_STATE) !== Tenant::READY || !$this->care->is_live_website($website_id)) {
            return;
        }
        $summary = $this->care->summary($website_id);
        $packages = $summary && $summary['subscription_id'] > 0 ? [] : $this->care->packages();
        if (!$summary && !$packages) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- only shows how the customer's own request went.
        $for_this = isset($_GET[self::SITE_ARG]) && absint(wp_unslash($_GET[self::SITE_ARG])) === $website_id;
        $result = $for_this && isset($_GET[self::RESULT_ARG]) ? sanitize_key(wp_unslash($_GET[self::RESULT_ARG])) : '';
        // phpcs:enable

        $title = $summary && $summary['package'] !== '' ? $summary['package'] : __('Care', 'anypress-host');
        echo '<details class="anypress-acct-panel anypress-acct-care"' . ($for_this || ($summary && $summary['subscription_id'] > 0) ? ' open' : '') . '><summary>' . esc_html($title)
            . ' <small>' . esc_html($summary && $summary['subscription_id'] > 0 ? self::time_line($summary) : __('We make the changes, set things up and check in each month', 'anypress-host')) . '</small></summary><div class="anypress-acct-panel-body">';
        self::render_result($result, $summary);
        if ($summary) {
            $this->render_care($website_id, $summary);
        }
        if ($packages) {
            $this->render_offer($website_id);
        }
        echo '</div></details>';
    }

    private static function time_line(array $summary): string
    {
        $line = sprintf(
            /* translators: 1: time left, 2: time a month */
            __('%1$s of %2$s left this month', 'anypress-host'),
            FluentCartCare::format_minutes($summary['left']),
            FluentCartCare::format_minutes($summary['minutes'])
        );
        if ($summary['answer'] !== '') {
            /* translators: %s: e.g. 1 business day */
            $line .= ', ' . sprintf(__('answered within %s', 'anypress-host'), $summary['answer']);
        }

        return $line;
    }

    private static function render_result(string $result, ?array $summary): void
    {
        $messages = [
            'sent' => $summary && $summary['answer'] !== ''
                /* translators: %s: e.g. 1 business day */
                ? sprintf(__('Thanks, your request is in. We answer by email within %s; you can also follow it on the Support page.', 'anypress-host'), $summary['answer'])
                : __('Thanks, your request is in. We answer by email; you can also follow it on the Support page.', 'anypress-host'),
            'added' => __('Care is now on this website.', 'anypress-host'),
            'empty' => __('Tell us what you need changed, then send it again.', 'anypress-host'),
            'error' => __('We could not take that just now. Try again in a minute, or write to support@anypress.io.', 'anypress-host'),
        ];
        if (isset($messages[$result])) {
            $ok = $result === 'sent' || $result === 'added';
            echo '<p class="anypress-acct-care-result ' . ($ok ? 'is-ok' : 'is-bad') . '" role="status">' . esc_html($messages[$result]) . '</p>';
        }
    }

    /** A website with care or extra time: what's left this month, the request form, the call and the subscription. */
    private function render_care(int $website_id, array $summary): void
    {
        $has_package = $summary['subscription_id'] > 0 && $summary['minutes'] > 0;
        if ($has_package || $summary['extra'] > 0) {
            $share = $has_package ? $summary['left'] / $summary['minutes'] : 1.0;
            $state = $has_package && $summary['left'] <= 0 ? ' is-out' : ($has_package && $share <= FluentCartCareEmails::LOW_SHARE ? ' is-low' : '');
            echo '<div class="anypress-acct-care-time' . esc_attr($state) . '">';
            if ($has_package) {
                echo '<p class="anypress-acct-care-left"><b>' . esc_html(FluentCartCare::format_minutes($summary['left'])) . '</b> <span>' . esc_html(sprintf(
                    /* translators: %s: time a month, e.g. 2 h */
                    __('left of %s this month', 'anypress-host'),
                    FluentCartCare::format_minutes($summary['minutes'])
                )) . '</span></p>';
                echo '<div class="anypress-acct-care-meter" role="img" aria-label="' . esc_attr(self::time_line($summary)) . '"><i style="width:' . esc_attr((string) (int) round(100 * $share)) . '%"></i></div>';
                echo '<p class="anypress-acct-care-facts"><span>' . esc_html__('Starts again on the 1st', 'anypress-host') . '</span>'
                    . ($summary['answer'] !== '' ? '<span>' . esc_html(sprintf(
                        /* translators: %s: e.g. 1 business day */
                        __('We answer within %s', 'anypress-host'),
                        $summary['answer']
                    )) . '</span>' : '') . '</p>';
            }
            if ($summary['extra'] > 0) {
                echo '<p class="anypress-acct-care-extra">' . esc_html(sprintf(
                    /* translators: %s: time */
                    __('Plus %s of extra time, used once this month\'s time runs out. It never expires.', 'anypress-host'),
                    FluentCartCare::format_minutes($summary['extra'])
                )) . '</p>';
            }
            echo '</div>';
        }

        if (SupportDesk::available()) {
            echo '<form class="anypress-acct-care-request" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::REQUEST_ACTION) . '"><input type="hidden" name="website" value="' . esc_attr((string) $website_id) . '">';
            wp_nonce_field(self::REQUEST_ACTION . '_' . $website_id);
            echo '<label><span>' . esc_html__('Ask for a change', 'anypress-host') . '</span><textarea name="request" rows="4" maxlength="' . esc_attr((string) self::MAX_REQUEST) . '" required placeholder="'
                . esc_attr__('For example: add a "Spring workshop" page with a booking button, and put it in the menu.', 'anypress-host') . '"></textarea></label>';
            echo '<button type="submit" class="anypress-acct-btn is-primary">' . esc_html__('Send request', 'anypress-host') . '</button></form>';
        }

        $links = [];
        if ($has_package) {
            foreach ($this->services(true) as $service) {
                $variation = $service['variations'][0];
                $links[] = '<a class="anypress-acct-btn" href="' . esc_url(add_query_arg(FluentCartEmailPacks::QUERY_ARG, $website_id, $variation['checkout_url'])) . '">' . esc_html(sprintf(
                    /* translators: 1: e.g. Extra hour for care customers, 2: price */
                    __('%1$s · %2$s', 'anypress-host'),
                    $service['title'],
                    self::price_label($variation)
                )) . '</a>';
            }
        }
        if ($summary['call_url'] !== '') {
            $links[] = '<a class="anypress-acct-btn" href="' . esc_url($summary['call_url']) . '" target="_blank" rel="noopener">' . esc_html__('Book this month\'s call', 'anypress-host') . '</a>';
        }
        if ($summary['subscription_id'] > 0) {
            $links[] = '<a class="anypress-acct-care-manage" href="' . esc_url($this->adapter->get_subscription_url($summary['subscription_id'])) . '">' . esc_html__('Manage or cancel care', 'anypress-host') . '</a>';
        }
        if ($links) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link is escaped above.
            echo '<p class="anypress-acct-care-links">' . implode('', $links) . '</p>';
        }
    }

    /** A website without a package: a waiting package to use here, or the packages and services with a "Book a call" button. */
    private function render_offer(int $website_id): void
    {
        $waiting = $this->care->waiting_for_user(get_current_user_id());
        if ($waiting) {
            $care_id = (int) $waiting[0];
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::ATTACH_ACTION) . '">'
                . '<input type="hidden" name="website" value="' . esc_attr((string) $website_id) . '"><input type="hidden" name="care" value="' . esc_attr((string) $care_id) . '">';
            wp_nonce_field(self::ATTACH_ACTION . '_' . $website_id);
            echo '<p>' . esc_html__('You have a care package that isn\'t on a website yet.', 'anypress-host') . '</p><button type="submit" class="anypress-acct-btn is-primary">'
                . esc_html__('Use it for this website', 'anypress-host') . '</button></form>';
            return;
        }

        $packages = $this->care->packages();
        $has_yearly = false;
        foreach ($packages as $package) {
            foreach ($package['variations'] as $variation) {
                $has_yearly = $has_yearly || $variation['interval'] === 'yearly';
            }
        }

        // Monthly or yearly: two radios the tiers' prices follow, in CSS alone.
        if ($has_yearly) {
            $name = 'anypress_care_period_' . $website_id;
            $free = self::months_free($packages);
            echo '<div class="anypress-acct-care-period" role="radiogroup" aria-label="' . esc_attr__('Billing', 'anypress-host') . '">'
                . '<label><input type="radio" name="' . esc_attr($name) . '" value="monthly" checked><span>' . esc_html__('Monthly', 'anypress-host') . '</span></label>'
                . '<label><input type="radio" name="' . esc_attr($name) . '" value="yearly"><span>' . esc_html__('Yearly', 'anypress-host') . ($free > 0 ? ' <small>' . esc_html(sprintf(
                    /* translators: %d: months */
                    _n('%d month free', '%d months free', $free, 'anypress-host'),
                    $free
                )) . '</small>' : '') . '</span></label></div>';
        }

        echo '<div class="anypress-acct-care-tiers">';
        foreach ($packages as $package) {
            echo '<div class="anypress-acct-care-tier"><p class="anypress-acct-care-name"><strong>' . esc_html($package['title']) . '</strong></p>';
            foreach (['monthly', 'yearly'] as $interval) {
                foreach ($package['variations'] as $variation) {
                    if ($variation['interval'] === $interval) {
                        echo '<div class="anypress-acct-care-price is-' . esc_attr($interval) . '"><p><b>' . esc_html(self::money($variation['price'])) . '</b> <span>'
                            . esc_html($interval === 'yearly' ? __('a year', 'anypress-host') : __('a month', 'anypress-host')) . '</span></p></div>';
                    }
                }
            }
            echo '<ul>';
            foreach (self::package_points($package) as $point) {
                echo '<li>' . esc_html($point) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '</div>';

        $services = $this->services();
        if ($services) {
            echo '<p class="anypress-acct-care-sub">' . esc_html__('One-time help', 'anypress-host') . '</p><ul class="anypress-acct-care-services">';
            foreach ($services as $service) {
                echo '<li><div><strong>' . esc_html($service['title']) . '</strong><small>' . esc_html(wp_strip_all_tags($service['excerpt'])) . '</small></div>'
                    . '<b>' . esc_html(self::price_label($service['variations'][0])) . '</b></li>';
            }
            echo '</ul>';
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- call_button() escapes.
        echo self::call_button('anypress-acct-care-links', 'anypress-acct-btn is-primary', 'anypress-acct-note');
        echo '<p class="anypress-acct-note">' . esc_html__('Unused time doesn\'t carry over. Care renews on its own and can be cancelled any time; it ends with the website\'s plan.', 'anypress-host') . '</p>';
    }

    /** The months a year of every package saves over paying monthly (2 when yearly is 10 months' price), else 0. */
    private static function months_free(array $packages): int
    {
        $free = null;
        foreach ($packages as $package) {
            $monthly = self::first_variation($package, 'monthly');
            $yearly = self::first_variation($package, 'yearly');
            if ($monthly['interval'] !== 'monthly' || $yearly['interval'] !== 'yearly' || $monthly['price'] <= 0) {
                return 0;
            }
            $months = (int) round(12 - $yearly['price'] / $monthly['price']);
            if ($free !== null && $months !== $free) {
                return 0;
            }
            $free = $months;
        }
        return max(0, (int) $free);
    }

    /**
     * A package's points: its time, its answer time, the monthly call when it has one, and the report.
     *
     * @return string[]
     */
    private static function package_points(array $package): array
    {
        $minutes = AnyPressProvisioningIntegration::minutes($package['settings'], 'care_minutes');
        $answer = sanitize_text_field((string) ($package['settings']['care_answer'] ?? ''));
        $points = [];
        if ($minutes > 0) {
            /* translators: %s: time, e.g. 2 h */
            $points[] = sprintf(__('%s of changes a month', 'anypress-host'), FluentCartCare::format_minutes($minutes));
        }
        if ($answer !== '') {
            /* translators: %s: e.g. 1 business day */
            $points[] = sprintf(__('Answer within %s', 'anypress-host'), $answer);
        }
        if (!empty($package['settings']['care_call_url'])) {
            $points[] = __('A call each month', 'anypress-host');
        }
        $points[] = __('Monthly report by email', 'anypress-host');

        return $points;
    }

    // ---- Account: form posts ----

    /** "Send request": a ticket in Fluent Support for one of the customer's live websites. */
    public function handle_request(): void
    {
        $website_id = $this->own_website('website', self::REQUEST_ACTION);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- own_website() checked the nonce.
        $text = isset($_POST['request']) ? trim(sanitize_textarea_field(wp_unslash($_POST['request']))) : '';
        if ($text === '') {
            $this->back($website_id, 'empty');
        }
        $text = function_exists('mb_substr') ? mb_substr($text, 0, self::MAX_REQUEST) : substr($text, 0, self::MAX_REQUEST);

        $user = wp_get_current_user();
        $summary = $this->care->summary($website_id);
        $domain = $this->care->domain($website_id);
        $title = $summary && $summary['package'] !== ''
            /* translators: 1: care package, 2: website address */
            ? sprintf(__('%1$s: change for %2$s', 'anypress-host'), $summary['package'], $domain)
            /* translators: %s: website address */
            : sprintf(__('Change for %s', 'anypress-host'), $domain);
        $ticket = SupportDesk::file_ticket(
            (string) $user->user_email,
            (string) $user->display_name,
            $title,
            SupportDesk::message($text),
            $summary && $summary['package'] !== '' ? ['priority' => $summary['priority'], 'tag' => $summary['package']] : []
        );
        $this->back($website_id, $ticket ? 'sent' : 'error');
    }

    /** "Use it for this website": a care package bought before the website, given to it. */
    public function handle_attach(): void
    {
        $website_id = $this->own_website('website', self::ATTACH_ACTION);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- own_website() checked the nonce.
        $care_id = isset($_POST['care']) ? absint(wp_unslash($_POST['care'])) : 0;
        $this->back($website_id, $this->care->attach_for_user($care_id, $website_id, get_current_user_id()) ? 'added' : 'error');
    }

    /** The posted website, after checking the nonce and that it is a live website of the logged-in customer. */
    private function own_website(string $field, string $action): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked right below, with the website in the action.
        $website_id = isset($_POST[$field]) ? absint(wp_unslash($_POST[$field])) : 0;
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Log in to your account first.', 'anypress-host'), 403);
        }
        check_admin_referer($action . '_' . $website_id);
        $own = array_map('intval', $this->adapter->get_customer_subscription_ids(get_current_user_id()));
        if ($website_id <= 0 || !in_array($website_id, $own, true) || !$this->care->is_live_website($website_id)) {
            wp_die(esc_html__('That website is not one of yours.', 'anypress-host'), 403);
        }

        return $website_id;
    }

    private function back(int $website_id, string $result): void
    {
        $url = add_query_arg([self::SITE_ARG => $website_id, self::RESULT_ARG => $result], $this->store->get_portal_url(FluentCartPortal::ENDPOINT));
        wp_safe_redirect($url);
        exit;
    }
}
