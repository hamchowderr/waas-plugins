<?php

namespace AnyPressClient\Shop;

use AnyPressClient\Forms\FormPlacements;

/**
 * The pages part of the shop switch from Easy Digital Downloads: EDD's shortcodes and blocks become FluentCart's
 * (EddShortcodes) in posts, pages, templates, template parts and synced patterns, the same way the forms switch
 * swaps forms. Each changed post keeps a revision, and its old content is kept so undo puts it back.
 */
final class EddPages
{
    public static function switch_posts(array &$state, array &$report, bool $dry_run): void
    {
        $products = self::products();
        FormPlacements::rewrite_posts(
            fn (string $content) => EddShortcodes::rewrite($content, $products),
            EddShortcodes::NEEDLES,
            __('That download isn\'t in FluentCart yet, so its buy button was left as it is.', 'anypress-client'),
            $state,
            $report,
            $dry_run,
            // EDD's own posts: the switch never changes EDD.
            ['download', 'edd_payment', 'edd_log', 'edd_discount']
        );
    }

    public static function restore_posts(array &$state, array &$report, bool $dry_run): void
    {
        FormPlacements::restore_posts($state, $report, $dry_run);
    }

    /**
     * EDD download id => the FluentCart product the FluentCart Migrator add-on made of it (it notes the product's id
     * on the download as _fcart_migrated_id).
     *
     * @return array<int, int>
     */
    private static function products(): array
    {
        $map = [];
        $downloads = get_posts(['post_type' => 'download', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1]);
        foreach ($downloads as $id) {
            $product = (int) get_post_meta((int) $id, '_fcart_migrated_id', true);
            if ($product > 0 && get_post($product)) {
                $map[(int) $id] = $product;
            }
        }

        return $map;
    }
}
