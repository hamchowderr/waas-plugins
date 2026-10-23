<?php

namespace WaaSHost\Core;

/**
 * A WooCommerce product's WaaS settings, read and written through WooCommerce's product object
 * (get_meta / update_meta_data / save), so WooCommerce's caches and hooks stay in step.
 */
class StoreProduct
{
    /** The "WaaS product" checkbox; WooCommerce stores product type options as "_<id>". */
    public const IS_STORE_PRODUCT_META = 'is_waas_product';

    // Leading underscore: WooCommerce keeps these out of the Custom Fields box and the REST meta_data.
    public const PRODUCT_ROLE_META = '_waas_product_role';
    public const PRODUCT_GROUPNAME_META = '_waas_product_group_name';

    // Keys before 2.3.0. Still read until migrate_legacy_meta() has moved them.
    public const LEGACY_PRODUCT_ROLE_META = 'WPCS_PRODUCT_ROLE_META';
    public const LEGACY_PRODUCT_GROUPNAME_META = 'WPCS_PRODUCT_GROUPNAME_META';

    /** @var \WC_Product|null */
    private $product;

    /** @param \WC_Product|int $product a product, or its id */
    public function __construct($product)
    {
        if (is_object($product)) {
            $this->product = $product;
        } else {
            $found = function_exists('wc_get_product') ? wc_get_product($product) : null;
            $this->product = $found ?: null;
        }
    }

    /** Sets the checkbox. Like the setters below, it is saved by save(), or by WooCommerce on the product editor. */
    public function store_is_waas_product($true_or_false)
    {
        $this->product?->update_meta_data('_' . self::IS_STORE_PRODUCT_META, $true_or_false ? 'yes' : 'no');
    }

    public function is_store_product()
    {
        return $this->get('_' . self::IS_STORE_PRODUCT_META) === 'yes';
    }

    public static function get_waas_product_meta_query()
    {
        return [
            'key' => '_' . self::IS_STORE_PRODUCT_META,
            'value' => 'yes',
            'compare' => '=',
        ];
    }

    public function get_role()
    {
        return $this->get(self::PRODUCT_ROLE_META, self::LEGACY_PRODUCT_ROLE_META);
    }

    public function update_role($role)
    {
        $this->set(self::PRODUCT_ROLE_META, self::LEGACY_PRODUCT_ROLE_META, $role);
    }

    public function get_tenant_snapshot_group_name()
    {
        return $this->get(self::PRODUCT_GROUPNAME_META, self::LEGACY_PRODUCT_GROUPNAME_META);
    }

    public function update_tenant_snapshot_group_name($group_name)
    {
        $this->set(self::PRODUCT_GROUPNAME_META, self::LEGACY_PRODUCT_GROUPNAME_META, $group_name);
    }

    public function save(): void
    {
        $this->product?->save();
    }

    /**
     * Moves the pre-2.3.0 role and snapshot group keys to the new ones. A product that had a role but was
     * never marked a WaaS product (stores from before the checkbox existed) is marked one, once.
     *
     * @return int how many products were moved
     */
    public static function migrate_legacy_meta(): int
    {
        if (!function_exists('wc_get_product')) {
            return 0;
        }

        // Products are posts in every WooCommerce storage mode, so the lookup can go through WP_Query.
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => self::LEGACY_PRODUCT_ROLE_META, 'compare' => 'EXISTS'],
                ['key' => self::LEGACY_PRODUCT_GROUPNAME_META, 'compare' => 'EXISTS'],
            ],
        ]);

        $moved = 0;
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }

            $store_product = new self($product);
            if ($product->meta_exists(self::LEGACY_PRODUCT_ROLE_META) && !$product->meta_exists('_' . self::IS_STORE_PRODUCT_META)) {
                $store_product->store_is_waas_product(true);
            }
            // Reading falls back to the legacy key; writing moves the value and drops the legacy key.
            if ($product->meta_exists(self::LEGACY_PRODUCT_ROLE_META)) {
                $store_product->update_role($store_product->get_role());
            }
            if ($product->meta_exists(self::LEGACY_PRODUCT_GROUPNAME_META)) {
                $store_product->update_tenant_snapshot_group_name($store_product->get_tenant_snapshot_group_name());
            }
            $store_product->save();
            $moved++;
        }

        return $moved;
    }

    private function get(string $key, ?string $legacy_key = null)
    {
        if ($this->product === null) {
            return '';
        }

        $value = $this->product->get_meta($key, true);
        if ($value === '' && $legacy_key !== null) {
            $value = $this->product->get_meta($legacy_key, true);
        }

        return $value;
    }

    private function set(string $key, string $legacy_key, $value): void
    {
        if ($this->product === null) {
            return;
        }

        $this->product->update_meta_data($key, $value);
        $this->product->delete_meta_data($legacy_key);
    }
}
