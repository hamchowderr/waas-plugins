<?php

namespace AnyPressHost\Commerce\FluentCart;

use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use AnyPressHost\Migrations\FluentCartFeedTriggers;

/**
 * FluentCartStore's reads and writes of the AnyPress feeds on products: which products are website plans, email
 * packs, care packages or one-time services, and the feed triggers migration.
 */
trait FluentCartFeedQueries
{
    /**
     * Settings of the enabled AnyPress feed on a product, or null when the product is not a website plan. An email-pack
     * feed is not a plan.
     *
     * @return array<string, mixed>|null
     */
    public function get_plan_feed($product_id): ?array
    {
        foreach ($this->get_enabled_feeds($product_id) as $settings) {
            if (AnyPressProvisioningIntegration::is_plan($settings)) {
                return $settings;
            }
        }

        return null;
    }

    /**
     * Settings of the enabled email-pack feed on a product with a valid number of emails, or null when the product is
     * not an email pack.
     *
     * @return array<string, mixed>|null
     */
    public function get_email_pack_feed($product_id): ?array
    {
        foreach ($this->get_enabled_feeds($product_id) as $settings) {
            if (AnyPressProvisioningIntegration::emails_per_pack($settings) > 0) {
                return $settings;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> settings of the product's enabled AnyPress feeds */
    private function get_enabled_feeds($product_id): array
    {
        $feeds = ProductMeta::query()
            ->where('object_id', (int) $product_id)
            ->where('object_type', 'product_integration')
            ->where('meta_key', AnyPressProvisioningIntegration::KEY)
            ->get();

        $enabled = [];
        foreach ($feeds as $feed) {
            $settings = $feed->meta_value;
            if (is_array($settings) && ($settings['enabled'] ?? '') === 'yes') {
                $enabled[] = $settings;
            }
        }

        return $enabled;
    }

    /**
     * Settings of the product's enabled AnyPress feed of this product type (AnyPressProvisioningIntegration::PRODUCT_TYPE_),
     * or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    public function get_feed_of_type($product_id, string $type): ?array
    {
        foreach ($this->get_enabled_feeds($product_id) as $settings) {
            if (AnyPressProvisioningIntegration::product_type($settings) === $type) {
                return $settings;
            }
        }

        return null;
    }

    /** True when the product is bought for one of the customer's websites: an email pack, care package or service. */
    public function is_website_addon($product_id): bool
    {
        foreach ($this->get_enabled_feeds($product_id) as $settings) {
            if (AnyPressProvisioningIntegration::is_email_pack($settings) ? AnyPressProvisioningIntegration::emails_per_pack($settings) > 0 : !AnyPressProvisioningIntegration::is_plan($settings)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The published email-pack products, fewest emails first, each with the instant-checkout link of its first
     * variation (FluentCart's "Buy now" link: a one-item cart, straight to checkout).
     *
     * @return array<int, array{product_id: int, emails: int, title: string, checkout_url: string}>
     */
    public function get_email_pack_products(): array
    {
        $packs = [];
        foreach ($this->get_typed_products(AnyPressProvisioningIntegration::PRODUCT_TYPE_EMAIL_PACK) as $product) {
            $emails = AnyPressProvisioningIntegration::emails_per_pack($product['settings']);
            if ($emails > 0) {
                $packs[] = [
                    'product_id' => $product['product_id'],
                    'emails' => $emails,
                    'title' => $product['title'],
                    'checkout_url' => $product['variations'][0]['checkout_url'],
                ];
            }
        }

        usort($packs, fn ($a, $b) => $a['emails'] <=> $b['emails'] ?: $a['product_id'] <=> $b['product_id']);

        return $packs;
    }

    /**
     * The published products whose enabled AnyPress feed is of this product type, in menu order, each with its feed
     * settings and its variations (prices in cents, and FluentCart's instant-checkout link for each).
     *
     * @return array<int, array{product_id: int, title: string, excerpt: string, settings: array, variations: array<int, array{id: int, title: string, price: int, interval: string, checkout_url: string}>}>
     */
    public function get_typed_products(string $type): array
    {
        $feeds = ProductMeta::query()
            ->where('object_type', 'product_integration')
            ->where('meta_key', AnyPressProvisioningIntegration::KEY)
            ->get();

        $products = [];
        foreach ($feeds as $feed) {
            $settings = $feed->meta_value;
            $product_id = (int) $feed->object_id;
            if (
                !is_array($settings) || ($settings['enabled'] ?? '') !== 'yes'
                || AnyPressProvisioningIntegration::product_type($settings) !== $type
                || isset($products[$product_id]) || get_post_status($product_id) !== 'publish'
            ) {
                continue;
            }

            $variations = [];
            foreach (ProductVariation::query()->where('post_id', $product_id)->orderBy('serial_index', 'ASC')->orderBy('id', 'ASC')->get() as $variation) {
                $info = is_array($variation->other_info ?? null) ? $variation->other_info : [];
                $variations[] = [
                    'id' => (int) $variation->id,
                    'title' => (string) ($variation->variation_title ?? ''),
                    'price' => (int) round((float) ($variation->item_price ?? 0)),
                    'interval' => ($variation->payment_type ?? '') === 'subscription' ? (string) ($info['repeat_interval'] ?? '') : '',
                    'checkout_url' => site_url('?fluent-cart=instant_checkout&item_id=' . (int) $variation->id . '&quantity=1'),
                ];
            }
            if (!$variations) {
                continue;
            }

            $products[$product_id] = [
                'product_id' => $product_id,
                'title' => (string) get_the_title($product_id),
                'excerpt' => (string) get_the_excerpt($product_id),
                'settings' => $settings,
                'variations' => $variations,
                'order' => (int) get_post_field('menu_order', $product_id),
            ];
        }

        $products = array_values($products);
        usort($products, fn ($a, $b) => $a['order'] <=> $b['order'] ?: $a['product_id'] <=> $b['product_id']);

        return array_map(static function ($product) {
            unset($product['order']);
            return $product;
        }, $products);
    }

    /**
     * Gives every AnyPress feed whose triggers are exactly $old_triggers (in any order) the current defaults, which add
     * $trigger (FluentCartFeedTriggers).
     *
     * @return int feeds updated
     */
    public function add_feed_trigger(array $old_triggers, string $trigger): int
    {
        $feeds = ProductMeta::query()
            ->where('object_type', 'product_integration')
            ->where('meta_key', AnyPressProvisioningIntegration::KEY)
            ->get();

        $updated = 0;
        foreach ($feeds as $feed) {
            $settings = $feed->meta_value;
            if (!is_array($settings)) {
                continue;
            }

            $triggers = FluentCartFeedTriggers::updated_triggers((array) ($settings['event_trigger'] ?? []));
            if ($triggers === null || !in_array($trigger, $triggers, true)) {
                continue;
            }

            $settings['event_trigger'] = $triggers;
            $feed->meta_value = $settings;
            $feed->save();
            $updated++;
        }

        return $updated;
    }

    public function has_plan_feed(): bool
    {
        $feeds = ProductMeta::query()
            ->where('object_type', 'product_integration')
            ->where('meta_key', AnyPressProvisioningIntegration::KEY)
            ->get();

        foreach ($feeds as $feed) {
            if (AnyPressProvisioningIntegration::is_plan($feed->meta_value)) {
                return true;
            }
        }

        return false;
    }
}
