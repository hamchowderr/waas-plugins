<?php

namespace WaaSHost\Core;

use WaaSHost\Commerce\Commerce;

class Tenant
{
    public const WEBSITE_NAME_META = 'waas_website_name';
    public const DOMAIN_NAME_META = 'waas_domain_name';
    public const BASE_DOMAIN_NAME_META = 'waas_base_domain_name';
    public const SUBSCRIPTION_USER_ROLES = 'waas_subscription_user_roles';
    public const TENANT_EXTERNAL_ID_META = 'waas_tenant_external_id';
    public const TENANT_PUBLIC_KEY_META = 'waas_tenant_public_key';
    /** Encrypted (see PrivateKeyStore). The leading underscore keeps it out of WooCommerce's Custom Fields box and REST meta_data. */
    public const TENANT_PRIVATE_KEY_META = '_waas_tenant_private_key';
    /** Where the key was kept before 2.3.0; PrivateKeyStore moves it on first read, and on update. */
    public const LEGACY_TENANT_PRIVATE_KEY_META = 'WPCS_TENANT_PRIVATE_KEY_META';
    public const DEFAULT_USER_ROLE = 'waas_default_user_role';
    /** Unix time at which a suspended store is deleted. */
    public const TENANT_DELETE_AT_META = 'waas_tenant_delete_at';
    /** Subscription meta: when a cancelled subscription's paid period ends and the store is suspended (unix time). */
    public const TENANT_SUSPEND_AT_META = 'waas_tenant_suspend_at';
    /**
     * Subscription meta: why waas-host took the store offline. A billing event: ON_HOLD (failed renewal, paused) or
     * ENDED (cancelled, expired, refunded). Empty for a store suspended some other way, which waas-host leaves alone.
     */
    public const TENANT_SUSPEND_REASON_META = 'waas_tenant_suspend_reason';
    public const SUSPENDED_ON_HOLD = 'on_hold';
    public const SUSPENDED_ENDED = 'ended';

    /** Order meta: 'yes' when checkout accepted the website name without the API's availability check. */
    public const WEBSITE_NAME_UNVERIFIED_META = 'waas_website_name_unverified';
    /** Subscription meta while the store waits for a new name (see NameConflicts). */
    public const NAME_CONFLICT_META = 'waas_name_conflict';

    /**
     * wp-config constants that stores built before WaaS Client 2.4.0 read under the old platform's prefix. The shop sends
     * each under both names until every store runs a newer client.
     */
    private const LEGACY_CONSTANTS = [
        'WAAS_TENANT_ROLES' => 'WPCS_TENANT_ROLES',
        'WAAS_PUBLIC_KEY' => 'WPCS_PUBLIC_KEY',
        'WAAS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS' => 'WPCS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS',
    ];

    /** The store's wp-config constants as sent to the API: each WAAS_ one also under its old name, when it has one. */
    public static function with_legacy_constants(array $constants): array
    {
        foreach (self::LEGACY_CONSTANTS as $name => $legacy) {
            if (isset($constants[$name]) && !isset($constants[$legacy])) {
                $constants[$legacy] = $constants[$name];
            }
        }
        return $constants;
    }

    public const TENANT_STATE = 'waas_tenant_state';
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
                return __('Setting up your website', WAAS_HOST_TEXTDOMAIN);
            case self::FAILED:
                return __('Setup delayed — we\'re on it', WAAS_HOST_TEXTDOMAIN);
            case self::NEEDS_NEW_NAME:
                return __('Choose a new website name', WAAS_HOST_TEXTDOMAIN);
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
