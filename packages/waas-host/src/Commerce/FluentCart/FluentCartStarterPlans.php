<?php

namespace WaaSHost\Commerce\FluentCart;

use FluentCart\App\Models\Meta;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Modules\Integrations\IntegrationHelper;
use FluentCart\App\Services\BulkProductInsertService;
use WaaSHost\Api\RolesController;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Features\AdminHome;
use WaaSHost\Features\PluginBootstrap;

/**
 * Makes AnyPress's website plans as FluentCart products, so a storefront can sell on day one: one product per site
 * type and size, each with monthly and yearly prices and the WaaS Provisioning feed set to that role and size.
 *
 * A seller gets them from the "Create my plans" setup step. A ready-made storefront (keys in wp-config) gets them
 * once, the first time its owner opens wp-admin, so its setup steps start with the plans already made.
 */
class FluentCartStarterPlans
{
    public const ACTION = 'waas_create_plans';
    /** Set once a ready-made storefront has had its plans made (or already had some), so it never runs again. */
    public const AUTO_DONE_OPTION = 'waas_starter_plans_done';
    /** Waits between tries while a ready-made storefront can't load its roles yet. */
    private const RETRY_TRANSIENT = 'waas_starter_plans_retry';

    /** Price multiplier per size, as the AnyPress shop sells them. A yearly plan costs ten months. */
    public const SIZES = ['small' => 1, 'medium' => 2, 'large' => 4];

    private ProvisioningApi $provisioning_api;
    private FluentCartStore $store;

    public function __construct(ProvisioningApi $provisioning_api, FluentCartStore $store)
    {
        $this->provisioning_api = $provisioning_api;
        $this->store = $store;
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle_create']);
        add_action('admin_init', [$this, 'maybe_create_for_ready_made'], 20);
        add_filter('waas_getting_started_checklist', [$this, 'checklist'], 25);
        add_filter('waas_getting_started_checklist', [$this, 'payment_step'], 26);
        add_filter('waas_plan_button', [$this, 'plan_button']);
    }

    /**
     * The plans made for each role, by role slug: name, monthly price of the Small size in cents, and a line for the
     * product page. Filter: waas_starter_plans. Only roles the storefront has loaded get products.
     */
    public static function catalogue(): array
    {
        return (array) apply_filters('waas_starter_plans', [
            'website' => [__('Website', 'waas-host'), 1200, __('A website or blog with SEO, forms, email marketing and an affiliate program.', 'waas-host')],
            'bookings' => [__('Bookings', 'waas-host'), 1900, __('A website that takes appointments and bookings, with a support desk.', 'waas-host')],
            'online-store' => [__('Online Store', 'waas-host'), 2900, __('An online store with checkout, subscriptions, email marketing and affiliates.', 'waas-host')],
            'community' => [__('Community & Courses', 'waas-host'), 3900, __('A members community with courses, plus a store and support desk.', 'waas-host')],
            'everything' => [__('Everything', 'waas-host'), 7900, __('Every feature: store, bookings, community, courses, support, email marketing and affiliates.', 'waas-host')],
        ]);
    }

    /** The catalogue's plans whose role this storefront has loaded, in catalogue order. */
    public static function available(): array
    {
        $roles = (array) get_option(PluginBootstrap::ROLES_WP_OPTION, []);

        return array_intersect_key(self::catalogue(), $roles);
    }

    public static function url(): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::ACTION);
    }

    /** The products list in FluentCart, where the seller changes a plan's price or picture. */
    public static function products_url(): string
    {
        return admin_url('admin.php?page=fluent-cart#/products');
    }

    /**
     * Makes every available plan and size that has no product yet. Safe to run again: a role and size that already
     * has a plan feed is skipped.
     *
     * @return int how many products were made
     */
    public function create(): int
    {
        $existing = [];
        $feeds = ProductMeta::query()->where('object_type', 'product_integration')->where('meta_key', WaasProvisioningIntegration::KEY)->get();
        foreach ($feeds as $feed) {
            $settings = $feed->meta_value;
            if (is_array($settings) && !WaasProvisioningIntegration::is_email_pack($settings)) {
                $existing[($settings['role'] ?? '') . '/' . (($settings['size'] ?? '') ?: 'small')] = true;
            }
        }

        $made = 0;
        foreach (self::available() as $role => [$name, $price, $pitch]) {
            foreach (self::SIZES as $size => $times) {
                if (isset($existing["$role/$size"]) || !$this->create_plan((string) $role, (string) $name, (int) $price * $times, (string) $pitch, $size)) {
                    continue;
                }
                $made++;
            }
        }

        if ($made) {
            do_action('fluent_cart/reindex_integration_feeds', []);
        }

        return $made;
    }

    private function create_plan(string $role, string $name, int $monthly, string $pitch, string $size): bool
    {
        $subscription = fn (string $interval) => ['payment_type' => 'subscription', 'repeat_interval' => $interval, 'times' => '', 'trial_days' => 0, 'manage_setup_fee' => 'no'];
        $sizes = ['small' => __('Small', 'waas-host'), 'medium' => __('Medium', 'waas-host'), 'large' => __('Large', 'waas-host')];
        $result = (new BulkProductInsertService())->insertChunk([[
            '_cid' => "$role-$size",
            /* translators: 1: plan name, such as Website, 2: size, such as Small */
            'post_title' => sprintf(__('%1$s (%2$s)', 'waas-host'), $name, $sizes[$size]),
            'post_status' => 'published',
            'post_content' => $pitch,
            'detail' => ['fulfillment_type' => 'digital', 'variation_type' => 'simple_variations'],
            'variants' => [
                ['variation_title' => __('Monthly', 'waas-host'), 'item_price' => $monthly, 'other_info' => $subscription('monthly')],
                ['variation_title' => __('Yearly', 'waas-host'), 'item_price' => $monthly * 10, 'other_info' => $subscription('yearly')],
            ],
        ]]);
        $id = (int) ($result['created'][0]['id'] ?? 0);
        if (!$id) {
            return false;
        }
        wp_update_post(['ID' => $id, 'post_name' => sanitize_title("$role-$size")]);

        $feed = [
            'enabled' => 'yes',
            'name' => __('Website plan', 'waas-host'),
            'product_type' => WaasProvisioningIntegration::PRODUCT_TYPE_PLAN,
            'emails_per_pack' => '',
            'role' => $role,
            'group_name' => '',
            'size' => $size,
            'event_trigger' => WaasProvisioningIntegration::DEFAULT_TRIGGERS,
            'watch_on_access_revoke' => 'yes',
        ];
        $data = IntegrationHelper::validateAndFormatIntegrationFeedSettings($feed, ['provider' => WaasProvisioningIntegration::KEY, 'scope' => 'product', 'product_id' => $id, 'integration_id' => null]);
        if (is_wp_error($data)) {
            // A product that would sell without making a website must not stay on sale.
            wp_update_post(['ID' => $id, 'post_status' => 'draft']);
            return false;
        }
        ProductMeta::create(['object_id' => $id, 'object_type' => 'product_integration', 'meta_key' => WaasProvisioningIntegration::KEY, 'meta_value' => $data]);

        return true;
    }

    public function handle_create(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'waas-host'), 403);
        }
        check_admin_referer(self::ACTION);

        if (!self::available()) {
            try {
                RolesController::load($this->provisioning_api);
            } catch (\Exception $e) {
                // Shown below as "no plans made".
            }
        }

        wp_safe_redirect(add_query_arg('plans_made', $this->create(), admin_url('admin.php?page=' . AdminHome::SLUG)));
        exit;
    }

    /**
     * A ready-made storefront makes its plans once, on its owner's first visit to wp-admin. A storefront that already
     * has a plan is left as it is. Until the roles can be loaded it tries again every ten minutes.
     */
    public function maybe_create_for_ready_made(): void
    {
        if (get_option(self::AUTO_DONE_OPTION) || wp_doing_ajax() || !current_user_can('manage_options')
            || !ConfigService::has_constant('API_KEY') || !ConfigService::has_constant('API_SECRET') || get_transient(self::RETRY_TRANSIENT)) {
            return;
        }

        if ($this->store->has_plan_feed()) {
            update_option(self::AUTO_DONE_OPTION, 'existing', false);
            return;
        }

        if (!self::available()) {
            try {
                RolesController::load($this->provisioning_api);
            } catch (\Exception $e) {
                set_transient(self::RETRY_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS);
                return;
            }
        }
        if (!self::available()) {
            set_transient(self::RETRY_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS);
            return;
        }

        update_option(self::AUTO_DONE_OPTION, (string) $this->create(), false);
    }

    /** The plan step offers to make the plans, once the plans' roles are loaded. */
    public function checklist($items)
    {
        if (!isset($items['create_woo_waas_product']) || !empty($items['create_woo_waas_product']['is_done'])) {
            return $items;
        }

        $count = count(self::available()) * count(self::SIZES);
        if ($count) {
            $items['create_woo_waas_product']['label'] = __('Create your website plans', 'waas-host');
            $items['create_woo_waas_product']['description'] = sprintf(
                /* translators: %d: number of products, such as 15 */
                __('Makes %d ready-to-sell plans in FluentCart: each site type in Small, Medium and Large, monthly and yearly, at AnyPress\'s prices. Change any price or picture afterwards in FluentCart.', 'waas-host'),
                $count
            );
            $items['create_woo_waas_product']['action_label'] = __('Create my plans', 'waas-host');
            $items['create_woo_waas_product']['action_url'] = self::url();
        }

        return $items;
    }

    /** Customers can't pay until a card payment method is on, so it is a setup step too. */
    public function payment_step($items)
    {
        $items['payment_method'] = [
            'label' => __('Turn on card payments', 'waas-host'),
            'description' => __('Connect Stripe or PayPal in FluentCart so customers can pay. Payments go straight to your own account.', 'waas-host'),
            'action_label' => __('Connect payments', 'waas-host'),
            'action_url' => admin_url('admin.php?page=fluent-cart#/settings/payments'),
            'is_done' => self::has_payment_method(),
        ];

        return $items;
    }

    /** The header's plan button: make the plans, or open them once they exist. */
    public function plan_button($button)
    {
        if ($this->store->has_plan_feed()) {
            return [__('Website plans', 'waas-host'), self::products_url()];
        }

        return self::available() ? [__('Create my plans', 'waas-host'), self::url()] : $button;
    }

    /**
     * A card payment method is turned on in FluentCart. Cash on delivery and the offline method don't count: they
     * can't take a subscription's renewals.
     */
    public static function has_payment_method(): bool
    {
        $settings = Meta::query()->where('meta_key', 'LIKE', 'fluent\\_cart\\_payment\\_settings\\_%')->get();
        foreach ($settings as $setting) {
            if (in_array($setting->meta_key, ['fluent_cart_payment_settings_offline_payment', 'fluent_cart_payment_settings_promo_gateway'], true)) {
                continue;
            }
            $value = $setting->meta_value;
            if (is_array($value) && ($value['is_active'] ?? '') === 'yes') {
                return true;
            }
        }

        return false;
    }
}
