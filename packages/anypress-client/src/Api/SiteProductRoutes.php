<?php

namespace AnyPressClient\Api;

use AnyPressClient\Shop\ShopSwitch;
use WP_Error;
use WP_REST_Request;

/**
 * SiteController's shop routes: FluentCart products, listed and added (from a reference the store is built from, a
 * Shopify shop or another site). Products are written by FluentCart's own importer, the ProductWriter of the
 * FluentCart Migrator add-on the store template installs (switched off: its classes are loaded for the request
 * only), and their photos are brought in by FluentCart's ImageAttachService. New products are drafts unless asked.
 * A product added before (the same source address) is left as it is.
 */
trait SiteProductRoutes
{
    /** Where a product added from elsewhere came from, so the same product is never added twice. */
    private static $source_meta = '_anypress_source_url';

    private static $max_products = 20;

    /** FluentCart's products, newest first: id, title, status, link, and where each came from. */
    public function list_products()
    {
        $ready = $this->shop_ready();
        if (is_wp_error($ready)) {
            return $ready;
        }
        $posts = get_posts(['post_type' => \FluentCart\App\CPT\FluentProducts::CPT_NAME, 'post_status' => ['publish', 'draft', 'private'], 'numberposts' => 200]);

        return array_map(fn ($post) => $this->product_summary($post), $posts);
    }

    /**
     * { products: [{ title, description?, url?, status?: draft|publish, images?: [https urls], categories?: [names],
     * variants?: [{ title?, sku?, price, compareAtPrice?, available? }] }] } at most 20 at a time.
     */
    public function add_products(WP_REST_Request $request)
    {
        $ready = $this->shop_ready();
        if (is_wp_error($ready)) {
            return $ready;
        }
        $products = $request->get_param('products');
        if (!is_array($products) || !$products || count($products) > self::$max_products) {
            return $this->bad('Give products: a list of 1 to ' . self::$max_products . ' products.');
        }
        $writer = $this->product_writer();
        if (is_wp_error($writer)) {
            return $writer;
        }

        return ['results' => array_map(fn ($product) => $this->add_product(is_array($product) ? $product : [], $writer), array_values($products))];
    }

    private function add_product(array $input, $writer): array
    {
        $title = sanitize_text_field((string) ($input['title'] ?? ''));
        $source = esc_url_raw((string) ($input['url'] ?? ''), ['https', 'http']);
        if ($title === '') {
            return ['url' => $source ?: null, 'status' => 'failed', 'reason' => 'A product needs a title.'];
        }
        $existing = $source ? $this->product_from($source) : null;
        if ($existing) {
            return $this->product_result($source, 'exists', $existing);
        }

        $data = \FluentCartMigrator\Classes\Dto\ProductData::make([
            'postTitle' => $title,
            'postContent' => wp_kses_post((string) ($input['description'] ?? '')),
            'postExcerpt' => sanitize_textarea_field((string) ($input['excerpt'] ?? '')),
            'postStatus' => $this->status($input['status'] ?? null, 'draft'),
            'postName' => sanitize_title($title),
            'categories' => $this->product_categories((array) ($input['categories'] ?? [])),
        ]);
        $data->variations = $this->product_variations((array) ($input['variants'] ?? []), $title);
        $data->isVariable = count($data->variations) > 1;
        $data->stockAvailability = array_filter($data->variations, fn ($v) => $v->stockStatus === 'in-stock') ? 'in-stock' : 'out-of-stock';

        $id = $writer->write($data);
        if (is_wp_error($id)) {
            return ['url' => $source ?: null, 'status' => 'failed', 'reason' => $id->get_error_message()];
        }
        if ($source) {
            update_post_meta($id, self::$source_meta, $source);
        }
        $images = $this->product_images($id, $title, (array) ($input['images'] ?? []));

        return ['images' => $images] + $this->product_result($source ?: null, 'created', get_post($id));
    }

    /** One product's result: whether it was added, and the product as the shop has it (its own status as productStatus). */
    private function product_result(?string $source, string $result, $post): array
    {
        $summary = $this->product_summary($post);
        $summary['productStatus'] = $summary['status'];
        $summary['status'] = $result;

        return ['url' => $source] + $summary;
    }

    /** One variation a price, or one per variant; prices come as amounts ("19.99") and are stored in cents. */
    private function product_variations(array $variants, string $title): array
    {
        $variants = array_values(array_filter($variants, 'is_array')) ?: [[]];
        $out = [];
        foreach (array_slice($variants, 0, 100) as $i => $variant) {
            $price = \FluentCartMigrator\Classes\Support\Money::toCents($variant['price'] ?? 0);
            $compare = \FluentCartMigrator\Classes\Support\Money::toCents($variant['compareAtPrice'] ?? 0);
            $out[] = \FluentCartMigrator\Classes\Dto\ProductVariationData::make([
                'serialIndex' => $i + 1,
                'variationTitle' => sanitize_text_field((string) ($variant['title'] ?? '')) ?: (count($variants) > 1 ? 'Option ' . ($i + 1) : $title),
                'variationIdentifier' => (string) $i,
                'sku' => sanitize_text_field((string) ($variant['sku'] ?? '')),
                'stockStatus' => ($variant['available'] ?? true) === false ? 'out-of-stock' : 'in-stock',
                'itemPrice' => $price,
                'comparePrice' => $compare > $price ? $compare : 0,
                'createdAt' => current_time('mysql'),
                'updatedAt' => current_time('mysql'),
            ]);
        }

        return $out;
    }

    /** Category names as FluentCart product categories, made when missing. */
    private function product_categories(array $names): array
    {
        $taxonomy = \FluentCartMigrator\Classes\Load\CategoryWriter::TAXONOMY;
        $ids = [];
        foreach (array_slice($names, 0, 20) as $name) {
            $name = sanitize_text_field((string) $name);
            if ($name === '' || !taxonomy_exists($taxonomy)) {
                continue;
            }
            $term = term_exists($name, $taxonomy) ?: wp_insert_term($name, $taxonomy);
            if (!is_wp_error($term)) {
                $ids[] = (int) $term['term_id'];
            }
        }

        return $ids;
    }

    /** The photos into the media library and the product's gallery (FluentCart's own code); the first is its image. */
    private function product_images(int $id, string $title, array $urls): int
    {
        $urls = array_values(array_filter(array_map(fn ($url) => esc_url_raw((string) $url, ['https']), array_slice($urls, 0, 10))));
        if (!$urls) {
            return 0;
        }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        \FluentCart\App\Services\Async\ImageAttachService::attachImageToProduct(['ID' => $id, 'post_title' => $title], $urls);
        $gallery = (array) get_post_meta($id, \FluentCart\App\CPT\FluentProducts::CPT_NAME . '-gallery-image', true);
        $first = (int) ($gallery[0]['id'] ?? 0);
        if ($first) {
            set_post_thumbnail($id, $first);
        }

        return count(array_filter($gallery, fn ($image) => !empty($image['id'])));
    }

    private function product_from(string $source)
    {
        $found = get_posts(['post_type' => \FluentCart\App\CPT\FluentProducts::CPT_NAME, 'post_status' => 'any', 'numberposts' => 1, 'meta_key' => self::$source_meta, 'meta_value' => $source]);

        return $found[0] ?? null;
    }

    private function product_summary($post): array
    {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'link' => $post->post_status === 'publish' ? get_permalink($post) : get_preview_post_link($post),
            'sourceUrl' => get_post_meta($post->ID, self::$source_meta, true) ?: null,
        ];
    }

    private function shop_ready()
    {
        if (!class_exists('FluentCart\App\CPT\FluentProducts')) {
            return new WP_Error('anypress_site_no_shop', 'This site has no shop: FluentCart is not on.', ['status' => 409]);
        }

        return true;
    }

    /** FluentCart's importer, from the Migrator add-on: its classes for this request, without switching it on. */
    private function product_writer()
    {
        if (!class_exists('FluentCartMigrator\Classes\Load\ProductWriter')) {
            $file = WP_PLUGIN_DIR . '/' . ShopSwitch::ADDON_FILE;
            if (!defined('FLUENTCART_MIGRATOR_VERSION') && is_readable($file)) {
                require_once $file;
            }
        }
        if (!class_exists('FluentCartMigrator\Classes\Load\ProductWriter')) {
            return new WP_Error('anypress_site_no_importer', 'FluentCart\'s importer (the FluentCart Migrator add-on) is not installed on this site.', ['status' => 409]);
        }

        return new \FluentCartMigrator\Classes\Load\ProductWriter();
    }
}
