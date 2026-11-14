<?php

namespace AnyPressClient\Shop;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\ProductVariation;

/**
 * FluentCart > Offers: where a buyer goes after paying, per product. A store owner can send the buyers of one product
 * to an offer for another (an upsell), from there to a cheaper offer when they say no (a downsell), or to the product's
 * own thank-you page. Each page keeps FluentCart's receipt for the order they just paid, so the buyer always sees it.
 *
 * Everything is FluentCart's own: the buyer lands there through its fluent_cart/payment/success_url filter, an offer's
 * "Yes" is its checkout button (instant checkout, so the offer is a new order of its own), and the receipt is its
 * [fluent_cart_receipt] shortcode. The offer product's own rule says where the buyer goes after paying for the offer,
 * so offers chain. "No Thanks" is an ordinary button to the next page; on these pages, button links to the site's
 * other pages keep the order's receipt link (trx_hash) so the next page can still show it.
 *
 * Nothing here charges a card by itself: a buyer always confirms the offer in FluentCart's checkout, where a saved
 * card (FluentCart's Saved Payment Methods) makes that one step.
 */
class Offers
{
    public const OPTION = 'anypress_offers';
    public const PAGE = 'anypress-offers';
    private const FORM = 'anypress_offers';
    private const NEW_OFFER = 'anypress_offers_new';
    private const PRODUCTS = 'fluent-products';
    private const HASH = 'trx_hash';

    public function __construct()
    {
        add_filter('fluent_cart/payment/success_url', [$this, 'success_url'], 20, 2);
        add_filter('render_block_core/button', [$this, 'carry_receipt'], 10, 1);
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_post_' . self::FORM, [$this, 'save_form']);
        add_action('admin_post_' . self::NEW_OFFER, [$this, 'make_offer']);
    }

    // ---- Rules ----

    /**
     * The saved rules, in order: the first rule for a product in the order decides where its buyer goes.
     *
     * @return array<int, array{product: int, page: int}>
     */
    public static function rules(): array
    {
        return self::clean_rules(get_option(self::OPTION, []));
    }

    /**
     * Rules with a product and a page, each product once (its first rule kept).
     *
     * @param mixed $raw
     * @return array<int, array{product: int, page: int}>
     */
    public static function clean_rules($raw): array
    {
        $rules = [];
        $seen = [];
        foreach (is_array($raw) ? $raw : [] as $rule) {
            $product = (int) ($rule['product'] ?? 0);
            $page = (int) ($rule['page'] ?? 0);
            if ($product <= 0 || $page <= 0 || isset($seen[$product])) {
                continue;
            }
            $seen[$product] = true;
            $rules[] = ['product' => $product, 'page' => $page];
        }

        return $rules;
    }

    /**
     * The page for an order: the rule of the first product in the order that has one. Rules are matched in the
     * order's own item order, so the main product bought decides, not an order bump.
     *
     * @param array<int, array{product: int, page: int}> $rules
     * @param int[] $products The order's product ids, in item order.
     */
    public static function page_for(array $rules, array $products): ?int
    {
        $pages = [];
        foreach ($rules as $rule) {
            $pages[$rule['product']] = $rule['page'];
        }
        foreach ($products as $product) {
            if (isset($pages[(int) $product])) {
                return $pages[(int) $product];
            }
        }

        return null;
    }

    /** @return int[] the pages rules send buyers to. */
    public static function pages(array $rules): array
    {
        return array_values(array_unique(array_map(static fn ($rule) => $rule['page'], $rules)));
    }

    // ---- After payment ----

    /**
     * fluent_cart/payment/success_url: FluentCart's receipt URL for the transaction, with its query (trx_hash and the
     * rest) moved to the page the order's rule names. Anything else is left as FluentCart made it.
     *
     * @param mixed $url
     * @param mixed $context transaction_hash, transaction, order
     * @return mixed
     */
    public function success_url($url, $context = [])
    {
        if (!is_string($url) || !is_array($context)) {
            return $url;
        }
        $order = $context['order'] ?? null;
        if (!is_object($order)) {
            return $url;
        }
        $page = self::page_for(self::rules(), self::order_products($order));
        if ($page === null || get_post_status($page) !== 'publish') {
            return $url;
        }
        $target = get_permalink($page);

        return is_string($target) && $target !== '' ? self::move_query($url, $target) : $url;
    }

    /**
     * @param object $order A FluentCart order.
     * @return int[]
     */
    private static function order_products($order): array
    {
        $items = $order->order_items ?? [];
        $products = [];
        foreach ($items as $item) {
            $products[] = (int) ($item->post_id ?? 0);
        }

        return $products;
    }

    /** $to with $from's query added (a key already in $to keeps $to's value). The fragment is dropped. */
    public static function move_query(string $from, string $to): string
    {
        $query = [];
        parse_str((string) wp_parse_url($from, PHP_URL_QUERY), $query);
        if ($query === []) {
            return $to;
        }
        $hash = strpos($to, '#');
        $fragment = $hash === false ? '' : substr($to, $hash);
        $base = $hash === false ? $to : substr($to, 0, $hash);
        $existing = [];
        parse_str((string) wp_parse_url($base, PHP_URL_QUERY), $existing);
        $add = array_diff_key($query, $existing);
        if ($add === []) {
            return $to;
        }

        return $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($add, '', '&', PHP_QUERY_RFC3986) . $fragment;
    }

    /**
     * render_block_core/button: on an offer page opened with an order's receipt link, a button to another page of
     * this site keeps that link, so "No Thanks" leads to a page that still shows the order. Checkout links
     * (FluentCart's own, with fluent-cart=) and links to other sites are left alone.
     *
     * @param mixed $html
     * @return mixed
     */
    public function carry_receipt($html)
    {
        if (!is_string($html) || $html === '' || empty($_GET[self::HASH]) || !is_singular('page')) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads the receipt link FluentCart sent the buyer here with.
            return $html;
        }
        if (!in_array((int) get_queried_object_id(), self::pages(self::rules()), true)) {
            return $html;
        }
        $hash = sanitize_text_field(wp_unslash((string) $_GET[self::HASH])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        return self::add_hash($html, $hash, $host);
    }

    /** The button's link with trx_hash added, when it points at this site and isn't a checkout link. */
    public static function add_hash(string $html, string $hash, string $host): string
    {
        if ($hash === '' || !preg_match('/^[A-Za-z0-9-]+$/', $hash)) {
            return $html;
        }

        return (string) preg_replace_callback('/(<a\b[^>]*\bhref=")([^"]*)(")/i', static function ($m) use ($hash, $host) {
            $href = html_entity_decode($m[2], ENT_QUOTES);
            $href_host = wp_parse_url($href, PHP_URL_HOST);
            $internal = $href_host === null ? strpos($href, '/') === 0 : strcasecmp((string) $href_host, $host) === 0;
            if (!$internal || stripos($href, 'fluent-cart=') !== false || stripos($href, self::HASH . '=') !== false) {
                return $m[0];
            }

            return $m[1] . htmlspecialchars(self::move_query('?' . self::HASH . '=' . $hash, $href), ENT_QUOTES) . $m[3];
        }, $html, 1);
    }

    // ---- New offer page ----

    /**
     * Block markup for an offer page: the offer, FluentCart's checkout button for it, a "No Thanks" button, then
     * FluentCart's receipt for the order just paid. Layout only; colours, fonts and spacing come from the theme.
     */
    public static function offer_markup(string $title, int $variation, string $decline_url): string
    {
        $yes = sprintf('[fluent_cart_checkout_button variation_id="%d" instant_checkout="yes" button_text="%s"]', $variation, esc_attr__('Yes, I Want It', 'anypress-client'));
        /* translators: %s: the offered product's name. */
        $heading = esc_html(sprintf(__('Want %s Too?', 'anypress-client'), $title));
        /* translators: %s: the offered product's name. */
        $pitch = esc_html(sprintf(__('Thanks for your order. Get %s now, at a price just for today. It\'s a quick second checkout, and your first order is already placed.', 'anypress-client'), $title));
        $no = esc_html__('No Thanks', 'anypress-client');
        $receipt = esc_html__('Your Order', 'anypress-client');
        $decline = esc_url($decline_url);

        return implode("\n", [
            '<!-- wp:group {"layout":{"type":"constrained"}} -->',
            '<div class="wp-block-group"><!-- wp:heading {"textAlign":"center"} -->',
            '<h2 class="wp-block-heading has-text-align-center">' . $heading . '</h2>',
            '<!-- /wp:heading -->',
            '',
            '<!-- wp:paragraph {"align":"center"} -->',
            '<p class="has-text-align-center">' . $pitch . '</p>',
            '<!-- /wp:paragraph -->',
            '',
            '<!-- wp:shortcode -->',
            $yes,
            '<!-- /wp:shortcode -->',
            '',
            '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->',
            '<div class="wp-block-buttons"><!-- wp:button {"className":"is-style-outline"} -->',
            '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="' . $decline . '">' . $no . '</a></div>',
            '<!-- /wp:button --></div>',
            '<!-- /wp:buttons -->',
            '',
            '<!-- wp:separator -->',
            '<hr class="wp-block-separator has-alpha-channel-opacity"/>',
            '<!-- /wp:separator -->',
            '',
            '<!-- wp:heading {"level":3} -->',
            '<h3 class="wp-block-heading">' . $receipt . '</h3>',
            '<!-- /wp:heading -->',
            '',
            '<!-- wp:shortcode -->',
            '[fluent_cart_receipt]',
            '<!-- /wp:shortcode --></div>',
            '<!-- /wp:group -->',
        ]);
    }

    /** Whether a page shows FluentCart's receipt (its shortcode). */
    public static function shows_receipt(string $content): bool
    {
        return strpos($content, '[fluent_cart_receipt') !== false;
    }

    // ---- Admin ----

    public function menu(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }
        add_submenu_page('fluent-cart', __('Offers', 'anypress-client'), __('Offers', 'anypress-client'), 'manage_options', self::PAGE, [$this, 'render']);
    }

    /** @return array<int, string> FluentCart product id => name. */
    private static function products(): array
    {
        $posts = get_posts(['post_type' => self::PRODUCTS, 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'title', 'order' => 'ASC']);
        $products = [];
        foreach ($posts as $post) {
            $products[(int) $post->ID] = $post->post_title !== '' ? $post->post_title : '#' . $post->ID;
        }

        return $products;
    }

    /** @return array<int, string> variation id => "Product: Variation" for the offer form. */
    private static function variations(array $products): array
    {
        if (!class_exists(ProductVariation::class) || $products === []) {
            return [];
        }
        $choices = [];
        foreach (ProductVariation::query()->whereIn('post_id', array_keys($products))->orderBy('post_id')->orderBy('serial_index')->get() as $variation) {
            $name = $products[(int) $variation->post_id] ?? '';
            $title = (string) ($variation->variation_title ?? '');
            $choices[(int) $variation->id] = $title !== '' && $title !== $name ? $name . ': ' . $title : $name;
        }

        return $choices;
    }

    private static function receipt_page_id(): int
    {
        return class_exists(StoreSettings::class) ? (int) (new StoreSettings())->getReceiptPageId() : 0;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $products = self::products();
        $rules = self::rules();
        $pages = get_pages(['post_status' => 'publish,draft', 'sort_column' => 'post_title']);
        $rows = array_merge($rules, [['product' => 0, 'page' => 0]]);
        $notice = isset($_GET['anypress_saved']) ? sanitize_key(wp_unslash($_GET['anypress_saved'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the notice shown after our own redirect.
        echo '<div class="wrap"><h1>' . esc_html__('Offers', 'anypress-client') . '</h1>';
        if ($notice === 'rules') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Saved.', 'anypress-client') . '</p></div>';
        }
        echo '<p>' . esc_html__('Choose where buyers go after they pay. Send the buyers of one product to an offer for another, to a cheaper offer when they say no, or to the product\'s own thank-you page. Products without a page here use FluentCart\'s receipt page.', 'anypress-client') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::FORM);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::FORM) . '">';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__('After Someone Buys', 'anypress-client') . '</th><th>' . esc_html__('Send Them To', 'anypress-client') . '</th><th></th></tr></thead><tbody>';
        foreach ($rows as $i => $rule) {
            echo '<tr><td><select name="rules[' . (int) $i . '][product]"><option value="0">' . esc_html__('Choose a product', 'anypress-client') . '</option>';
            foreach ($products as $id => $name) {
                echo '<option value="' . (int) $id . '"' . selected($rule['product'], $id, false) . '>' . esc_html($name) . '</option>';
            }
            echo '</select></td><td><select name="rules[' . (int) $i . '][page]"><option value="0">' . esc_html__('Choose a page', 'anypress-client') . '</option>';
            foreach ($pages as $page) {
                $label = $page->post_title . ($page->post_status === 'draft' ? ' (' . __('Draft', 'anypress-client') . ')' : '');
                echo '<option value="' . (int) $page->ID . '"' . selected($rule['page'], $page->ID, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></td><td>';
            if ($rule['page']) {
                echo '<a href="' . esc_url((string) get_edit_post_link($rule['page'])) . '">' . esc_html__('Edit Page', 'anypress-client') . '</a>';
                $post = get_post($rule['page']);
                if ($post && $post->post_status !== 'publish') {
                    echo ' &middot; ' . esc_html__('Publish the page so buyers go there.', 'anypress-client');
                }
                if ($post && !self::shows_receipt($post->post_content)) {
                    echo ' &middot; ' . esc_html__('Add FluentCart\'s receipt ([fluent_cart_receipt]) so buyers see their order.', 'anypress-client');
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p class="description">' . esc_html__('Pick "Choose a product" to remove a row. A product\'s first row is the one used.', 'anypress-client') . '</p>';
        submit_button(__('Save Offers', 'anypress-client'));
        echo '</form>';

        $variations = self::variations($products);
        echo '<h2>' . esc_html__('Make an Offer Page', 'anypress-client') . '</h2>';
        if ($variations === []) {
            echo '<p>' . esc_html__('Add a product in FluentCart first.', 'anypress-client') . '</p></div>';

            return;
        }
        echo '<p>' . esc_html__('Makes a draft page offering a product, with FluentCart\'s checkout button, a No Thanks button and the buyer\'s receipt, and sends the buyers you choose to it. Edit the words and pictures, then publish it.', 'anypress-client') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NEW_OFFER);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::NEW_OFFER) . '"><table class="form-table"><tr><th>' . esc_html__('After Someone Buys', 'anypress-client') . '</th><td><select name="after" required><option value="">' . esc_html__('Choose a product', 'anypress-client') . '</option>';
        foreach ($products as $id => $name) {
            echo '<option value="' . (int) $id . '">' . esc_html($name) . '</option>';
        }
        echo '</select></td></tr><tr><th>' . esc_html__('Offer Them', 'anypress-client') . '</th><td><select name="offer" required><option value="">' . esc_html__('Choose a product', 'anypress-client') . '</option>';
        foreach ($variations as $id => $name) {
            echo '<option value="' . (int) $id . '">' . esc_html($name) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__('For a special price, give the offer product a sale price, or make a product just for the offer and hide it from the shop.', 'anypress-client') . '</p></td></tr><tr><th>' . esc_html__('If They Say No', 'anypress-client') . '</th><td><select name="decline"><option value="0">' . esc_html__('FluentCart\'s receipt page', 'anypress-client') . '</option>';
        foreach ($pages as $page) {
            echo '<option value="' . (int) $page->ID . '">' . esc_html($page->post_title) . '</option>';
        }
        echo '</select></td></tr></table>';
        submit_button(__('Make the Page', 'anypress-client'), 'secondary');
        echo '</form></div>';
    }

    public function save_form(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You can\'t change offers.', 'anypress-client'), 403);
        }
        check_admin_referer(self::FORM);
        $raw = isset($_POST['rules']) && is_array($_POST['rules']) ? wp_unslash($_POST['rules']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_rules keeps whole numbers only.
        $rules = array_values(array_filter(self::clean_rules($raw), static fn ($rule) => get_post_type($rule['product']) === self::PRODUCTS && get_post_type($rule['page']) === 'page'));
        update_option(self::OPTION, $rules, false);
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'anypress_saved' => 'rules'], admin_url('admin.php')));
        exit;
    }

    public function make_offer(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You can\'t change offers.', 'anypress-client'), 403);
        }
        check_admin_referer(self::NEW_OFFER);
        $after = isset($_POST['after']) ? absint($_POST['after']) : 0;
        $variation_id = isset($_POST['offer']) ? absint($_POST['offer']) : 0;
        $decline = isset($_POST['decline']) ? absint($_POST['decline']) : 0;
        $variation = class_exists(ProductVariation::class) ? ProductVariation::query()->find($variation_id) : null;
        if (get_post_type($after) !== self::PRODUCTS || !$variation) {
            wp_die(esc_html__('Choose both products.', 'anypress-client'), 400);
        }
        $decline_page = $decline && get_post_type($decline) === 'page' ? $decline : self::receipt_page_id();
        $decline_url = $decline_page ? (string) get_permalink($decline_page) : home_url('/');
        $title = (string) get_the_title((int) $variation->post_id);
        $page = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            /* translators: %s: the offered product's name. */
            'post_title' => sprintf(__('Special Offer: %s', 'anypress-client'), $title),
            'post_content' => self::offer_markup($title, (int) $variation->id, $decline_url),
        ], true);
        if (is_wp_error($page)) {
            wp_die(esc_html($page->get_error_message()), 500);
        }
        $rules = array_values(array_filter(self::rules(), static fn ($rule) => $rule['product'] !== $after));
        $rules[] = ['product' => $after, 'page' => (int) $page];
        update_option(self::OPTION, $rules, false);
        wp_safe_redirect((string) get_edit_post_link((int) $page, 'url'));
        exit;
    }
}
