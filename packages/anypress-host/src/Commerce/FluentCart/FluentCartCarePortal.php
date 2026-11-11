<?php

namespace AnyPressHost\Commerce\FluentCart;

use AnyPressHost\Api\SupportDesk;
use AnyPressHost\Core\Tenant;
use AnyPressHost\Features\AccountStyles;

/**
 * Where customers see and buy care (FluentCartCare): a "Want us to look after it?" box under the buttons of each website
 * plan's product page, and a Care section on each live website's card on the account's Website page.
 *
 * FluentCart's checkout holds one subscription at a time, and its order bump (an add-on in the checkout) is a
 * FluentCart Pro feature, so care is its own checkout. On the product page the customer picks a package; the pick is
 * kept in a cookie, and once the website is up its card leads with that package, one click from checkout. On the card,
 * a website with care shows its package, this month's time left and an "Ask for a change" form, which files a ticket in
 * the shop's Fluent Support.
 */
class FluentCartCarePortal
{
    /** Cookie: the care variation picked on a product page. */
    public const PICK_COOKIE = 'anypress_care_pick';
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

    public function __construct(FluentCartAdapter $adapter, FluentCartStore $store, FluentCartCare $care)
    {
        $this->adapter = $adapter;
        $this->store = $store;
        $this->care = $care;
    }

    public function register(): void
    {
        add_action('fluent_cart/product/single/after_actions_block', [$this, 'render_product_box'], 20, 1);
        add_action('admin_post_' . self::REQUEST_ACTION, [$this, 'handle_request']);
        add_action('admin_post_' . self::ATTACH_ACTION, [$this, 'handle_attach']);
        add_filter('fluent_cart/shop_query', [$this, 'hide_from_shop'], 10, 1);
        add_filter('fluent_cart/checkout/validate_data', [$this, 'validate_checkout'], 10, 2);
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
     * fluent_cart/checkout/validate_data: care and services need a website. A cart with one is turned away unless it
     * also holds a website plan or the buyer (signed in, or by their checkout email) has a live website.
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
        if (!array_intersect($in_cart, $this->care_product_ids())) {
            return $errors;
        }
        foreach ($in_cart as $product_id) {
            if ($this->adapter->is_plan_product($product_id)) {
                return $errors;
            }
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            $email = sanitize_email((string) ($args['data']['billing_email'] ?? ''));
            $user = $email !== '' ? get_user_by('email', $email) : false;
            $user_id = $user ? (int) $user->ID : 0;
        }
        if ($user_id > 0 && $this->care->has_live_website($user_id)) {
            return $errors;
        }

        $errors = is_array($errors) ? $errors : [];
        $errors['anypress_care']['no_website'] = __('Care is added to one of your websites. Please sign in to the account that has your website, or get a website plan first.', 'anypress-host');

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

    /** The variation picked on a product page, 0 when none. */
    private static function picked(): int
    {
        return isset($_COOKIE[self::PICK_COOKIE]) ? absint(wp_unslash($_COOKIE[self::PICK_COOKIE])) : 0;
    }

    // ---- Product page ----

    /**
     * fluent_cart/product/single/after_actions_block: on a website plan's page, the care packages and one-time
     * services, to pick now and add once the website is up.
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

        $picked = self::picked();
        echo '<fieldset class="anypress-care-box"><legend>' . esc_html__('Want us to look after it?', 'anypress-host') . '</legend>';
        echo '<label class="anypress-care-option"><input type="radio" name="anypress_care_pick" value="0"' . checked($picked, 0, false) . '> <span><strong>'
            . esc_html__('No thanks', 'anypress-host') . '</strong> <small>' . esc_html__('You can add care later from your account.', 'anypress-host') . '</small></span></label>';
        foreach ($packages as $package) {
            $monthly = self::first_variation($package, 'monthly');
            echo '<label class="anypress-care-option"><input type="radio" name="anypress_care_pick" value="' . esc_attr((string) $monthly['id']) . '"' . checked($picked, $monthly['id'], false) . '> <span><strong>'
                . esc_html($package['title']) . '</strong> <small>' . esc_html(self::package_line($package)) . '</small></span> <b>' . esc_html(self::price_label($monthly)) . '</b></label>';
        }
        echo '<p class="anypress-care-note">' . esc_html__('Care is its own small subscription for this website. Once your website is ready, your account\'s My website page has your pick ready to add, and either can be cancelled alone.', 'anypress-host') . '</p>';

        $services = $this->care->services();
        if ($services) {
            echo '<p class="anypress-care-note"><strong>' . esc_html__('Get started faster (one-time):', 'anypress-host') . '</strong> ';
            $links = [];
            foreach ($services as $service) {
                $links[] = esc_html($service['title']) . ' &middot; ' . esc_html(self::price_label($service['variations'][0]));
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part is escaped above.
            echo implode('; ', $links) . '. ' . esc_html__('Also on your account once your website is ready.', 'anypress-host') . '</p>';
        }
        echo '</fieldset>';
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
        wp_add_inline_style('anypress-care-box', '.anypress-care-box{grid-column:1/-1;flex:1 0 100%;width:100%;box-sizing:border-box;border:1px solid currentColor;border-color:color-mix(in srgb,currentColor 25%,transparent);border-radius:12px;padding:14px 16px;margin:18px 0 0;display:grid;gap:8px;min-width:0}'
            . '.anypress-care-box legend{font-weight:700;padding:0 6px}'
            . '.anypress-care-option{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid color-mix(in srgb,currentColor 18%,transparent);border-radius:10px;cursor:pointer}'
            . '.anypress-care-option:has(input:checked){border-color:currentColor}'
            . '.anypress-care-option span{display:grid;gap:2px;flex:1;min-width:0}.anypress-care-option small{opacity:.75}.anypress-care-option b{white-space:nowrap}'
            . '.anypress-care-option input{margin-top:5px}.anypress-care-note{margin:4px 0 0;font-size:.92em;opacity:.85}');
        wp_print_styles('anypress-care-box');

        wp_register_script('anypress-care-box', false, [], $version, true);
        wp_add_inline_script('anypress-care-box', 'document.addEventListener("change",function(e){var t=e.target;if(t&&t.name==="anypress_care_pick"){document.cookie="' . self::PICK_COOKIE . '="+encodeURIComponent(t.value)+";path=/;max-age=2592000;SameSite=Lax"+(location.protocol==="https:"?";Secure":"");}});');
        wp_enqueue_script('anypress-care-box');
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
            echo '<p class="' . ($result === 'sent' || $result === 'added' ? 'anypress-acct-step' : 'anypress-acct-error') . '" role="status">' . esc_html($messages[$result]) . '</p>';
        }
    }

    /** A website with care or extra time: what's left, and the request form. */
    private function render_care(int $website_id, array $summary): void
    {
        if ($summary['subscription_id'] > 0 && $summary['minutes'] > 0) {
            $percent = (int) round(100 * $summary['left'] / $summary['minutes']);
            echo '<div class="anypress-acct-care-meter" role="img" aria-label="' . esc_attr(self::time_line($summary)) . '"><i style="width:' . esc_attr((string) $percent) . '%"></i></div>';
        }
        if ($summary['extra'] > 0) {
            echo '<p class="anypress-acct-note">' . esc_html(sprintf(
                /* translators: %s: time */
                __('Plus %s of extra time, used once this month\'s time runs out. It never expires.', 'anypress-host'),
                FluentCartCare::format_minutes($summary['extra'])
            )) . '</p>';
        }

        if (SupportDesk::available()) {
            echo '<form class="anypress-acct-care-request" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::REQUEST_ACTION) . '"><input type="hidden" name="website" value="' . esc_attr((string) $website_id) . '">';
            wp_nonce_field(self::REQUEST_ACTION . '_' . $website_id);
            echo '<label><span>' . esc_html__('Ask for a change', 'anypress-host') . '</span><textarea name="request" rows="4" maxlength="' . esc_attr((string) self::MAX_REQUEST) . '" required placeholder="'
                . esc_attr__('For example: add a "Spring workshop" page with a booking button, and put it in the menu.', 'anypress-host') . '"></textarea></label>';
            echo '<button type="submit" class="anypress-acct-btn is-primary">' . esc_html__('Send request', 'anypress-host') . '</button></form>';
        }
        if ($summary['call_url'] !== '') {
            echo '<p><a class="anypress-acct-btn" href="' . esc_url($summary['call_url']) . '" target="_blank" rel="noopener">' . esc_html__('Book this month\'s call', 'anypress-host') . '</a></p>';
        }
        if ($summary['subscription_id'] > 0) {
            echo '<p class="anypress-acct-note"><a href="' . esc_url($this->adapter->get_subscription_url($summary['subscription_id'])) . '">' . esc_html__('Manage or cancel care', 'anypress-host') . '</a></p>';
        }
    }

    /** A website without a package: a waiting package to use here, or the packages and services to buy for it. */
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

        $picked = self::picked();
        echo '<ul class="anypress-acct-domain-list anypress-acct-care-list">';
        foreach ($this->offers() as $offer) {
            [$item, $variation] = $offer;
            $url = add_query_arg(FluentCartEmailPacks::QUERY_ARG, $website_id, $variation['checkout_url']);
            echo '<li><a class="anypress-acct-care-offer' . ($variation['id'] === $picked ? ' is-picked' : '') . '" href="' . esc_url($url) . '"><strong>' . esc_html($item['title'])
                . ($variation['interval'] === 'yearly' ? ' <small>' . esc_html__('yearly', 'anypress-host') . '</small>' : '') . '</strong>'
                . ($variation['id'] === $picked ? ' <em>' . esc_html__('Your pick', 'anypress-host') . '</em>' : '')
                . ' <span>' . esc_html(self::price_label($variation)) . '</span>'
                . ($item['line'] !== '' ? '<small>' . esc_html($item['line']) . '</small>' : '') . '</a></li>';
        }
        echo '</ul><p class="anypress-acct-note">' . esc_html__('Unused time doesn\'t carry over. Care renews on its own and can be cancelled any time; it ends with the website\'s plan.', 'anypress-host') . '</p>';
    }

    /**
     * What a website without a package can buy: each package monthly then yearly, then each service. The pick from
     * the product page comes first.
     *
     * @return array<int, array{0: array{title: string, line: string}, 1: array}>
     */
    private function offers(): array
    {
        $offers = [];
        foreach ($this->care->packages() as $package) {
            foreach (['monthly', 'yearly'] as $interval) {
                foreach ($package['variations'] as $variation) {
                    if ($variation['interval'] === $interval) {
                        $offers[] = [['title' => $package['title'], 'line' => $interval === 'monthly' ? self::package_line($package) : ''], $variation];
                    }
                }
            }
        }
        foreach ($this->care->services() as $service) {
            $offers[] = [['title' => $service['title'], 'line' => wp_strip_all_tags($service['excerpt'])], $service['variations'][0]];
        }

        $picked = self::picked();
        usort($offers, fn ($a, $b) => (int) ($b[1]['id'] === $picked) <=> (int) ($a[1]['id'] === $picked));

        return $offers;
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
