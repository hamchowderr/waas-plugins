<?php

namespace AnyPressHost\Core;

use AnyPressHost\Commerce\Commerce;

class Tenant
{
    public const WEBSITE_NAME_META = 'anypress_website_name';
    public const DOMAIN_NAME_META = 'anypress_domain_name';
    public const BASE_DOMAIN_NAME_META = 'anypress_base_domain_name';
    public const SUBSCRIPTION_USER_ROLES = 'anypress_subscription_user_roles';
    public const TENANT_EXTERNAL_ID_META = 'anypress_tenant_external_id';
    public const TENANT_PUBLIC_KEY_META = 'anypress_tenant_public_key';
    /** Encrypted (see PrivateKeyStore). The leading underscore keeps it out of WooCommerce's Custom Fields box and REST meta_data. */
    public const TENANT_PRIVATE_KEY_META = '_anypress_tenant_private_key';
    /** Where the key was kept before 2.3.0; PrivateKeyStore moves it on first read, and on update. */
    public const DEFAULT_USER_ROLE = 'anypress_default_user_role';
    /** Unix time at which a suspended store is deleted. */
    public const TENANT_DELETE_AT_META = 'anypress_tenant_delete_at';
    /** Subscription meta: when a cancelled subscription's paid period ends and the store is suspended (unix time). */
    public const TENANT_SUSPEND_AT_META = 'anypress_tenant_suspend_at';
    /**
     * Subscription meta: why anypress-host took the store offline. A billing event: ON_HOLD (failed renewal, paused) or
     * ENDED (cancelled, expired, refunded). Empty for a store suspended some other way, which anypress-host leaves alone.
     */
    public const TENANT_SUSPEND_REASON_META = 'anypress_tenant_suspend_reason';
    public const SUSPENDED_ON_HOLD = 'on_hold';
    public const SUSPENDED_ENDED = 'ended';

    /** Order meta: 'yes' when checkout accepted the website name without the API's availability check. */
    public const WEBSITE_NAME_UNVERIFIED_META = 'anypress_website_name_unverified';
    /** The checkout's optional "What does your business do, and who is it for?" answer; the store's assistant starts from it. */
    public const BUSINESS_ABOUT_META = 'anypress_business_about';
    /** Subscription meta while the store waits for a new name (see NameConflicts). */
    public const NAME_CONFLICT_META = 'anypress_name_conflict';


    public const TENANT_STATE = 'anypress_tenant_state';
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
                return __('Setting up your website', 'anypress-host');
            case self::FAILED:
                return __('Setup delayed — we\'re on it', 'anypress-host');
            case self::NEEDS_NEW_NAME:
                return __('Choose a new website name', 'anypress-host');
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
        return Commerce::require_adapter()->get_subscription_meta($this->post_id, self::TENANT_STATE);
    }

    public function update_status($status)
    {
        Commerce::require_adapter()->update_subscription_meta($this->post_id, self::TENANT_STATE, $status);
    }
}
