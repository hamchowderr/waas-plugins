<?php

namespace AnyPressHost\Core;

/**
 * A WooCommerce product's AnyPress settings, read and written through WooCommerce's product object
 * (get_meta / update_meta_data / save), so WooCommerce's caches and hooks stay in step.
 */
class StoreProduct
{
    /** The "AnyPress product" checkbox; WooCommerce stores product type options as "_<id>". */
    public const IS_STORE_PRODUCT_META = 'is_anypress_product';

    // Leading underscore: WooCommerce keeps these out of the Custom Fields box and the REST meta_data.
    public const PRODUCT_ROLE_META = '_anypress_product_role';
    public const PRODUCT_GROUPNAME_META = '_anypress_product_group_name';

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
    public function store_is_anypress_product($true_or_false)
    {
        $this->product?->update_meta_data('_' . self::IS_STORE_PRODUCT_META, $true_or_false ? 'yes' : 'no');
    }

    public function is_store_product()
    {
        return $this->get('_' . self::IS_STORE_PRODUCT_META) === 'yes';
    }

    public static function get_anypress_product_meta_query()
    {
        return [
            'key' => '_' . self::IS_STORE_PRODUCT_META,
            'value' => 'yes',
            'compare' => '=',
        ];
    }

    public function get_role()
    {
        return $this->get(self::PRODUCT_ROLE_META);
    }

    public function update_role($role)
    {
        $this->set(self::PRODUCT_ROLE_META, $role);
    }

    public function get_tenant_snapshot_group_name()
    {
        return $this->get(self::PRODUCT_GROUPNAME_META);
    }

    public function update_tenant_snapshot_group_name($group_name)
    {
        $this->set(self::PRODUCT_GROUPNAME_META, $group_name);
    }

    public function save(): void
    {
        $this->product?->save();
    }

    private function get(string $key)
    {
        return $this->product === null ? '' : $this->product->get_meta($key, true);
    }

    private function set(string $key, $value): void
    {
        if ($this->product !== null) {
            $this->product->update_meta_data($key, $value);
        }
    }
}
