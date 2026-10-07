<?php

namespace WaaSHost\Core;

use WaaSHost\Commerce\Commerce;

class WPCSTenant
{
    public const WPCS_WEBSITE_NAME_META = 'WPCS_WEBSITE_NAME_META';
    public const WPCS_DOMAIN_NAME_META = 'WPCS_DOMAIN_NAME_META';
    public const WPCS_BASE_DOMAIN_NAME_META = 'WPCS_BASE_DOMAIN_NAME_META';
    public const WPCS_SUBSCRIPTION_USER_ROLES = 'WPCS_SUBSCRIPTION_USER_ROLES';
    public const WPCS_TENANT_EXTERNAL_ID_META = 'WPCS_TENANT_EXTERNAL_ID_META';
    public const WPCS_TENANT_PUBLIC_KEY_META = 'WPCS_TENANT_PUBLIC_KEY_META';
    public const WPCS_TENANT_PRIVATE_KEY_META = 'WPCS_TENANT_PRIVATE_KEY_META';
    public const WPCS_DEFAULT_USER_ROLE = 'wpcs_host_settings_default_user_role';
    /** Unix time at which a suspended store is deleted. */
    public const WPCS_TENANT_DELETE_AT_META = 'WPCS_TENANT_DELETE_AT_META';

    public const WPCS_TENANT_STATE = 'WPCS_TENANT_STATE';
    /** Asked for; the background job that creates or restores the store has not finished yet. */
    public const QUEUED = 'Queued';
    public const PROVISIONING = 'Provisioning';
    public const LINKING_DOMAIN = 'Linking Domain';
    public const REQUESTING_SSL = 'Requesting SSL';
    public const READY = 'Ready';
    public const SUSPENDED = 'Suspended';
    public const DELETED = 'Deleted';
    /** A background job kept failing; an admin has to retry it (see StoreJobs). */
    public const FAILED = 'Failed';

    private $post_id;

    public function __construct($post_id)
    {
        $this->post_id = $post_id;
    }

    public function get_status()
    {
        return Commerce::require_adapter()->get_subscription_meta($this->post_id, self::WPCS_TENANT_STATE);
    }

    public function update_status($status)
    {
        Commerce::require_adapter()->update_subscription_meta($this->post_id, self::WPCS_TENANT_STATE, $status);
    }
}
