<?php

namespace AnyPressHost\Commerce\FluentCart;

use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use AnyPressHost\Migrations\FluentCartFeedTriggers;

/**
 * FluentCartStore's reads and writes of the AnyPress feeds on products: which products are website plans or email
 * packs, and the feed triggers migration.
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
            if (!AnyPressProvisioningIntegration::is_email_pack($settings)) {
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
     * The published email-pack products, fewest emails first, each with the instant-checkout link of its first
     * variation (FluentCart's "Buy now" link: a one-item cart, straight to checkout).
     *
     * @return array<int, array{product_id: int, emails: int, title: string, checkout_url: string}>
     */
    public function get_email_pack_products(): array
    {
        $feeds = ProductMeta::query()
            ->where('object_type', 'product_integration')
            ->where('meta_key', AnyPressProvisioningIntegration::KEY)
            ->get();

        $packs = [];
        foreach ($feeds as $feed) {
            $settings = $feed->meta_value;
            $product_id = (int) $feed->object_id;
            $emails = is_array($settings) && ($settings['enabled'] ?? '') === 'yes' ? AnyPressProvisioningIntegration::emails_per_pack($settings) : 0;
            if ($emails === 0 || isset($packs[$product_id]) || get_post_status($product_id) !== 'publish') {
                continue;
            }

            $variation = ProductVariation::query()->where('post_id', $product_id)->orderBy('serial_index', 'ASC')->orderBy('id', 'ASC')->first();
            if (!$variation) {
                continue;
            }

            $packs[$product_id] = [
                'product_id' => $product_id,
                'emails' => $emails,
                'title' => (string) get_the_title($product_id),
                'checkout_url' => site_url('?fluent-cart=instant_checkout&item_id=' . (int) $variation->id . '&quantity=1'),
            ];
        }

        $packs = array_values($packs);
        usort($packs, fn ($a, $b) => $a['emails'] <=> $b['emails'] ?: $a['product_id'] <=> $b['product_id']);

        return $packs;
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
            if (!AnyPressProvisioningIntegration::is_email_pack($feed->meta_value)) {
                return true;
            }
        }

        return false;
    }
}
