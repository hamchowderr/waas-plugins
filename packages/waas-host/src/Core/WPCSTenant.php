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
    /** Subscription meta: when a cancelled subscription's paid period ends and the store is suspended (unix time). */
    public const WPCS_TENANT_SUSPEND_AT_META = 'WPCS_TENANT_SUSPEND_AT_META';

    /** Order meta: 'yes' when checkout accepted the website name without the API's availability check. */
    public const WPCS_WEBSITE_NAME_UNVERIFIED_META = 'WPCS_WEBSITE_NAME_UNVERIFIED_META';
    /** Subscription meta while the store waits for a new name (see NameConflicts). */
    public const WPCS_NAME_CONFLICT_META = 'WPCS_NAME_CONFLICT_META';

    public const WPCS_TENANT_STATE = 'WPCS_TENANT_STATE';
    /** Asked for; the background job that creates or restores the store has not finished yet. */
    public const QUEUED = 'Queued';
    public const PROVISIONING = 'Provisioning';
    public const LINKING_DOMAIN = 'Linking Domain';
    public const REQUESTING_SSL = 'Requesting SSL';
    public const READY = 'Ready';
    public const SUSPENDED = 'Suspended';
    public const DELETED = 'Deleted';
    /** The website name turned out to be taken when the store was created; the customer picks a new one. */
    public const NEEDS_NEW_NAME = 'Needs New Name';
    /** A background job kept failing; an admin has to retry it (see StoreJobs). */
    public const FAILED = 'Failed';

    private $post_id;

    public function __construct($post_id)
    {
        $this->post_id = $post_id;
    }

    /** What the customer sees for a state; internal names stay as they are (the admin notice uses them too). */
    public static function customer_status_label($state): string
    {
        switch ((string) $state) {
            case self::QUEUED:
                return __('Setting up your website', WPCS_WAAS_HOST_TEXTDOMAIN);
            case self::FAILED:
                return __('Setup delayed — we\'re on it', WPCS_WAAS_HOST_TEXTDOMAIN);
            case self::NEEDS_NEW_NAME:
                return __('Choose a new website name', WPCS_WAAS_HOST_TEXTDOMAIN);
            default:
                return (string) $state;
        }
    }

    /** True when the customer should see a website: the subscription has one, or one is being set up. */
    public static function shown_to_customer($external_id, $state): bool
    {
        return !empty($external_id) || in_array($state, [self::QUEUED, self::FAILED, self::NEEDS_NEW_NAME], true);
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
