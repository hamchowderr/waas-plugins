<?php

namespace WaaSHost\Core;

class StoreProduct
{
    public const IS_STORE_PRODUCT_META = 'is_wpcs_product';
    public const PRODUCT_TYPE_META = 'wpcs_product_type';
    public const PRODUCT_TYPE_BASE_PRODUCT = 'base_product';
    public const PRODUCT_TYPE_ADDON = 'addon';

    public const PRODUCT_ROLE_META = 'WPCS_PRODUCT_ROLE_META';
    public const PRODUCT_GROUPNAME_META = 'WPCS_PRODUCT_GROUPNAME_META';

    private $post_id;

    public function __construct($post_id)
    {
        $this->post_id = $post_id;
    }

    public function store_is_waas_product($true_or_false)
    {
        update_post_meta(
            $this->post_id,
            '_' . self::IS_STORE_PRODUCT_META,
            $true_or_false ? 'yes' : 'no',
        );
    }

    public function is_store_product()
    {
        $meta_value = get_post_meta(
            $this->post_id,
            '_' . self::IS_STORE_PRODUCT_META,
            true,
        );

        return $meta_value === 'yes';
    }

    public static function get_waas_product_meta_query()
    {
        return [
            'key' => '_' . self::IS_STORE_PRODUCT_META,
            'value' => 'yes',
            'compare' => '=',
        ];
    }

    public function store_type($type)
    {
        update_post_meta($this->post_id, self::PRODUCT_TYPE_META, $type);
    }

    public function get_type()
    {
        return get_post_meta($this->post_id, self::PRODUCT_TYPE_META, true);
    }

    public function get_role()
    {
        return get_post_meta($this->post_id, self::PRODUCT_ROLE_META, true);
    }

    public function update_role($role)
    {
        return update_post_meta($this->post_id, self::PRODUCT_ROLE_META, $role);
    }

    public function get_tenant_snapshot_group_name()
    {
        return get_post_meta($this->post_id, StoreProduct::PRODUCT_GROUPNAME_META, true);
    }

    public function update_tenant_snapshot_group_name($group_name)
    {
        return update_post_meta($this->post_id, self::PRODUCT_GROUPNAME_META, $group_name);
    }
}
