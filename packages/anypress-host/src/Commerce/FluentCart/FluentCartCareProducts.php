<?php

namespace AnyPressHost\Commerce\FluentCart;

use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Modules\Integrations\IntegrationHelper;
use FluentCart\App\Services\BulkProductInsertService;

/**
 * "Create the care products" on AnyPress > Care: the three care packages (monthly and yearly, yearly at ten months'
 * price) and the three one-time services, made as FluentCart drafts with their AnyPress feed. Nothing is on sale until
 * the shop owner publishes the products; prices and words can be changed in FluentCart first.
 */
class FluentCartCareProducts
{
    public const ACTION = 'anypress_create_care_products';

    /**
     * The products: title, monthly (or one-time) price in cents, short description, feed fields.
     * A filter lets a seller start from other prices.
     *
     * @return array<string, array{0: string, 1: int, 2: string, 3: array<string, string>}>
     */
    public static function catalogue(): array
    {
        return (array) apply_filters('anypress_care_products', [
            'care' => [__('Care', 'anypress-host'), 7900, __('For owners who mostly run the site themselves and want someone on call: 30 minutes of changes each month, a monthly check-in report, requests answered within 2 business days.', 'anypress-host'), ['product_type' => 'care', 'care_minutes' => '30', 'care_answer' => __('2 business days', 'anypress-host'), 'care_priority' => 'normal']],
            'care-plus' => [__('Care Plus', 'anypress-host'), 19900, __('For a business adding pages, products or courses most months: 2 hours of changes each month, set-up jobs included, requests answered within 1 business day.', 'anypress-host'), ['product_type' => 'care', 'care_minutes' => '120', 'care_answer' => __('1 business day', 'anypress-host'), 'care_priority' => 'medium']],
            'care-pro' => [__('Care Pro', 'anypress-host'), 44900, __('For a busy community, shop or course business: 5 hours of changes each month, a 30-minute planning call, requests answered the same business day.', 'anypress-host'), ['product_type' => 'care', 'care_minutes' => '300', 'care_answer' => __('the same business day', 'anypress-host'), 'care_priority' => 'critical']],
            'set-up-my-site' => [__('Set up my site for me', 'anypress-host'), 29900, __('We fill in your pages, logo, colours, menus and first products, courses or spaces from what you send us.', 'anypress-host'), ['product_type' => 'service', 'service_minutes' => '']],
            'move-my-site-in' => [__('Move my site in for me', 'anypress-host'), 19900, __('We run the move from WordPress, Circle, LearnDash, Tutor LMS, LifterLMS, MemberPress or WooCommerce, then check it.', 'anypress-host'), ['product_type' => 'service', 'service_minutes' => '']],
            'one-hour' => [__('One hour of changes', 'anypress-host'), 12500, __('For a one-off change, with no package. The hour is added to your website and used as you ask.', 'anypress-host'), ['product_type' => 'service', 'service_minutes' => '60']],
        ]);
    }

    public static function url(): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::ACTION);
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle_create']);
    }

    /** True once any care package or service product exists, published or not. */
    public static function exist(): bool
    {
        foreach (ProductMeta::query()->where('object_type', 'product_integration')->where('meta_key', AnyPressProvisioningIntegration::KEY)->get() as $feed) {
            if (in_array(AnyPressProvisioningIntegration::product_type($feed->meta_value), [AnyPressProvisioningIntegration::PRODUCT_TYPE_CARE, AnyPressProvisioningIntegration::PRODUCT_TYPE_SERVICE], true)) {
                return true;
            }
        }

        return false;
    }

    /** Makes every product of the catalogue as a draft. @return int how many were made */
    public function create(): int
    {
        $made = 0;
        $order = 0;
        foreach (self::catalogue() as $slug => [$title, $price, $excerpt, $fields]) {
            $order++;
            $made += $this->create_product((string) $slug, (string) $title, (int) $price, (string) $excerpt, (array) $fields, $order) ? 1 : 0;
        }

        return $made;
    }

    private function create_product(string $slug, string $title, int $price, string $excerpt, array $fields, int $order): bool
    {
        $care = ($fields['product_type'] ?? '') === AnyPressProvisioningIntegration::PRODUCT_TYPE_CARE;
        $subscription = fn (string $interval) => ['payment_type' => 'subscription', 'repeat_interval' => $interval, 'times' => '', 'trial_days' => 0, 'manage_setup_fee' => 'no'];
        $variants = $care
            ? [
                ['variation_title' => __('Monthly', 'anypress-host'), 'item_price' => $price, 'other_info' => $subscription('monthly')],
                ['variation_title' => __('Yearly', 'anypress-host'), 'item_price' => $price * 10, 'other_info' => $subscription('yearly')],
            ]
            : [['variation_title' => $title, 'item_price' => $price, 'other_info' => ['payment_type' => 'onetime']]];

        $result = (new BulkProductInsertService())->insertChunk([[
            '_cid' => $slug,
            'post_title' => $title,
            'post_status' => 'draft',
            'post_excerpt' => $excerpt,
            'post_content' => $excerpt,
            'detail' => ['fulfillment_type' => 'digital', 'variation_type' => 'simple_variations'],
            'variants' => $variants,
        ]]);
        $id = (int) ($result['created'][0]['id'] ?? 0);
        if (!$id) {
            return false;
        }
        wp_update_post(['ID' => $id, 'post_name' => sanitize_title($slug), 'menu_order' => $order]);

        $feed = array_merge([
            'enabled' => 'yes',
            'name' => $care ? __('Care package', 'anypress-host') : __('One-time service', 'anypress-host'),
            'emails_per_pack' => '',
            'care_minutes' => '',
            'care_answer' => '',
            'care_priority' => AnyPressProvisioningIntegration::PRIORITY_NORMAL,
            'care_call_url' => '',
            'service_minutes' => '',
            'role' => '',
            'group_name' => '',
            'size' => '',
            'event_trigger' => AnyPressProvisioningIntegration::DEFAULT_TRIGGERS,
            'watch_on_access_revoke' => 'no',
        ], $fields);
        $data = IntegrationHelper::validateAndFormatIntegrationFeedSettings($feed, ['provider' => AnyPressProvisioningIntegration::KEY, 'scope' => 'product', 'product_id' => $id, 'integration_id' => null]);
        if (is_wp_error($data)) {
            return false; // stays a draft without a feed; the seller can add it in FluentCart
        }
        ProductMeta::create(['object_id' => $id, 'object_type' => 'product_integration', 'meta_key' => AnyPressProvisioningIntegration::KEY, 'meta_value' => $data]); // phpcs:ignore WordPress.DB.SlowDBQuery -- a FluentCart model write, not a query.

        return true;
    }

    public function handle_create(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'anypress-host'), 403);
        }
        check_admin_referer(self::ACTION);

        $made = self::exist() ? 0 : $this->create();
        wp_safe_redirect(add_query_arg('care_products_made', $made, admin_url('admin.php?page=' . FluentCartCareDesk::PAGE)));
        exit;
    }
}
