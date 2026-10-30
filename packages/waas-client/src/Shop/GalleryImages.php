<?php

namespace WaaSClient\Shop;

/**
 * Copies each WooCommerce product's gallery images to its FluentCart copy. The FluentCart Migrator add-on links
 * the copy to the original (`_fct_migrated_id` on the WooCommerce product) but puts only the featured image in
 * FluentCart's gallery. The images themselves are already in the media library; only the list is written.
 */
class GalleryImages
{
    /** FluentCart's gallery: a list of ['id', 'url', 'name'], the featured image first. */
    public const META = 'fluent-products-gallery-image';
    /** On the WooCommerce product: its FluentCart copy's post id (written by the add-on). */
    public const LINK = '_fct_migrated_id';

    /**
     * The gallery a FluentCart copy should have: its current one, then any WooCommerce image it is missing, in
     * WooCommerce's order (featured image first). Images it already has keep their place and details.
     *
     * @param array $current FluentCart's gallery now.
     * @param int[] $woo_ids The featured image id, then the gallery ids.
     * @param callable $describe Attachment id => ['id', 'url', 'name'], or null when the image is gone.
     * @return array{gallery: array, added: int, missing: int[]}
     */
    public static function merge(array $current, array $woo_ids, callable $describe): array
    {
        $have = [];
        $gallery = [];
        foreach ($current as $image) {
            $id = (int) ($image['id'] ?? 0);
            if ($id && !isset($have[$id])) {
                $have[$id] = true;
                $gallery[] = $image;
            }
        }
        $added = 0;
        $missing = [];
        foreach ($woo_ids as $id) {
            $id = (int) $id;
            if (!$id || isset($have[$id])) {
                continue;
            }
            $image = $describe($id);
            if (!$image) {
                $missing[] = $id;
                continue;
            }
            $have[$id] = true;
            $gallery[] = $image;
            $added++;
        }

        return ['gallery' => $gallery, 'added' => $added, 'missing' => $missing];
    }

    public static function describe(int $id): ?array
    {
        $url = wp_get_attachment_url($id);
        if (!$url) {
            return null;
        }

        return ['id' => $id, 'url' => $url, 'name' => (string) get_the_title($id)];
    }

    /**
     * @param array $state The switch's state; 'gallery' keeps each changed copy's gallery from before, for undo.
     * @return array{products: int, images: int, missing: array}
     */
    public static function copy(array &$state, bool $dry_run): array
    {
        $report = ['products' => 0, 'images' => 0, 'missing' => []];
        foreach (self::linked_products() as $woo_id => $fc_id) {
            $product = wc_get_product($woo_id);
            if (!$product) {
                continue;
            }
            $woo_ids = array_merge([(int) $product->get_image_id()], array_map('intval', $product->get_gallery_image_ids()));
            $current = get_post_meta($fc_id, self::META, true);
            $current = is_array($current) ? $current : [];
            $merged = self::merge($current, $woo_ids, [self::class, 'describe']);
            foreach ($merged['missing'] as $missing) {
                $report['missing'][] = sprintf('%s: image #%d is no longer in the media library.', $product->get_name(), $missing);
            }
            if (!$merged['added']) {
                continue;
            }
            $report['products']++;
            $report['images'] += $merged['added'];
            if ($dry_run) {
                continue;
            }
            if (!array_key_exists($fc_id, $state['gallery'])) {
                $state['gallery'][$fc_id] = $current;
            }
            update_post_meta($fc_id, self::META, $merged['gallery']);
        }

        return $report;
    }

    public static function undo(array &$state, bool $dry_run): int
    {
        $count = count($state['gallery']);
        if (!$dry_run) {
            foreach ($state['gallery'] as $fc_id => $gallery) {
                update_post_meta((int) $fc_id, self::META, $gallery);
            }
            $state['gallery'] = [];
        }

        return $count;
    }

    /** WooCommerce product id => its FluentCart copy's id, for products the add-on has copied. */
    private static function linked_products(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = %s",
            self::LINK,
            'product'
        ));
        $map = [];
        foreach ((array) $rows as $row) {
            if ((int) $row->meta_value && get_post_type((int) $row->meta_value) === 'fluent-products') {
                $map[(int) $row->post_id] = (int) $row->meta_value;
            }
        }

        return $map;
    }
}
