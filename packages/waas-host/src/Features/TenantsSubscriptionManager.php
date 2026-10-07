<?php

namespace WaaSHost\Features;

use Exception;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\EncryptionService;
use WaaSHost\Core\PrivateKeyStore;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;

class TenantsSubscriptionManager
{
    /** Days a suspended store is kept before it is deleted. */
    public const DELETION_GRACE_DAYS_OPTION = 'wpcs_host_settings_deletion_grace_days';
    public const DEFAULT_DELETION_GRACE_DAYS = 30;
    public const DELETE_SUSPENDED_TENANT_HOOK = 'wpcs_delete_suspended_tenant';

    private WPCSService $wpcsService;

    public function __construct(WPCSService $wpcsService)
    {
        $this->wpcsService = $wpcsService;

        add_action('wpcs_subscription_created', [$this, 'create_tenant_when_subscription_created'], 10, 2);
        add_action('wpcs_subscription_suspended', [$this, 'suspend_tenant_on_hold']);
        add_action('wpcs_subscription_reactivated', [$this, 'resume_suspended_tenant']);
        add_action('wpcs_subscription_expired', [$this, 'suspend_tenant_when_subscription_expired']);
        add_action(self::DELETE_SUSPENDED_TENANT_HOOK, [$this, 'delete_suspended_tenant']);
    }

    public static function get_deletion_grace_days(): int
    {
        return max(1, absint(get_option(self::DELETION_GRACE_DAYS_OPTION, self::DEFAULT_DELETION_GRACE_DAYS)));
    }

    /**
     * @param mixed $purchase_context passed by the cart adapter; only the adapter reads it
     * @throws Exception
     */
    public function create_tenant_when_subscription_created($subscription_id, $purchase_context)
    {
        $commerce = Commerce::require_adapter();

        // The subscription already has a store: bring it back if it is suspended or was deleted, never create a
        // second one. A deleted store is restored from its newest backup; only without one does it get a new store.
        if(!empty($commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META)))
        {
            $status = (new WPCSTenant($subscription_id))->get_status();
            if ($status === WPCSTenant::SUSPENDED) {
                $this->resume_suspended_tenant($subscription_id);
                return;
            }
            if ($status !== WPCSTenant::DELETED || $this->restore_deleted_tenant($subscription_id)) {
                return;
            }
            $this->forget_deleted_tenant($subscription_id);
        }

        $purchase = $commerce->get_plan_purchase($subscription_id, $purchase_context);

        if (empty($purchase->plans)) {
            return;
        }

        $group_name = "";
        $subscription_roles = [];
        $product_role = '';

        foreach ($purchase->plans as $plan) {
            $product_role = $plan->role;
            if (!empty($product_role)) {
                $subscription_roles[] = $product_role;
            }
            $group_name = empty($group_name) ? $plan->group_name : $group_name;
        }

        $website_name = sanitize_text_field($purchase->website_name);

        $keys = EncryptionService::generate_key_pair();

        $args = [
            'name' => $website_name,
            'wordpress_username' => SingleLoginService::get_formatted_username($subscription_id),
            'wordpress_email' => $purchase->customer_email,
            'wordpress_user_role' => get_option(WPCSTenant::WPCS_DEFAULT_USER_ROLE),
            'group_name' => ($group_name === false || empty($group_name)) ? null : $group_name,
            'php_constants' => [
                'WPCS_TENANT_ROLES' => ['value' => implode(",", $subscription_roles), 'isPrivate' => false],
                'WPCS_PUBLIC_KEY' => ['value' => base64_encode($keys['public_key']), 'isPrivate' => false],
                'WPCS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS' => [
                    'value' => apply_filters('wpcs_remove_tenant_administrator_plugin_capabilities', true) ? "true" : "false",
                    'isPrivate' => false,
                ]
            ]
        ];

        $tenant_root_domain = get_option('wpcs_host_settings_root_domain', '');
        if ($tenant_root_domain !== '') {
            $subdomain = sanitize_title_with_dashes(remove_accents($website_name));
            $args['custom_domain_name'] = $subdomain . '.' . $tenant_root_domain;
        }

        $new_tenant = $this->wpcsService->create_tenant($args);

        $domain_name = $new_tenant->customDomain ?? $new_tenant->baseDomain;

        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META, $new_tenant->externalId);
        PrivateKeyStore::save($subscription_id, $keys['private_key']);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META, $domain_name);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_BASE_DOMAIN_NAME_META, $new_tenant->baseDomain);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_SUBSCRIPTION_USER_ROLES, [$product_role]);

        do_action('wpcs_tenant_linked_to_subscription', $subscription_id);
    }

    /**
     * Cancelled or expired: back the store up and take it offline. It is deleted after the grace
     * period unless the subscription becomes active again first.
     */
    public function suspend_tenant_when_subscription_expired($subscription_id)
    {
        $commerce = Commerce::require_adapter();
        $tenant_external_id = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META);
        $tenant = new WPCSTenant($subscription_id);

        // Carts can report one cancellation several times (cancelled, then end of term).
        if (empty($tenant_external_id)
            || $tenant->get_status() === WPCSTenant::DELETED
            || !empty($commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_DELETE_AT_META))) {
            return;
        }

        if ($tenant->get_status() === WPCSTenant::SUSPENDED) {
            // Already offline since it went on hold, without a backup: take one before the grace period starts.
            $this->wpcsService->create_tenant_backup($tenant_external_id);
        } else {
            $this->wpcsService->suspend_tenant($tenant_external_id, true);
        }

        $delete_at = time() + self::get_deletion_grace_days() * DAY_IN_SECONDS;
        $tenant->update_status(WPCSTenant::SUSPENDED);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_DELETE_AT_META, $delete_at);
        wp_schedule_single_event($delete_at, self::DELETE_SUSPENDED_TENANT_HOOK, [$subscription_id]);

        do_action('wpcs_tenant_suspended', $subscription_id, $delete_at);
    }

    /**
     * On hold (failed renewal payment, or put on hold by an admin): take the store offline. No backup and no
     * deletion: both start only if the subscription is then cancelled or expires.
     */
    public function suspend_tenant_on_hold($subscription_id)
    {
        $commerce = Commerce::require_adapter();
        $tenant_external_id = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META);
        $tenant = new WPCSTenant($subscription_id);
        if (empty($tenant_external_id) || in_array($tenant->get_status(), [WPCSTenant::SUSPENDED, WPCSTenant::DELETED], true)) {
            return;
        }

        $this->wpcsService->suspend_tenant($tenant_external_id, false);
        $tenant->update_status(WPCSTenant::SUSPENDED);

        do_action('wpcs_tenant_suspended', $subscription_id, 0);
    }

    public function resume_suspended_tenant($subscription_id)
    {
        $commerce = Commerce::require_adapter();
        $tenant = new WPCSTenant($subscription_id);
        if ($tenant->get_status() !== WPCSTenant::SUSPENDED) {
            return;
        }

        $this->wpcsService->resume_tenant($commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META));

        wp_clear_scheduled_hook(self::DELETE_SUSPENDED_TENANT_HOOK, [$subscription_id]);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_DELETE_AT_META, '');
        $tenant->update_status(WPCSTenant::READY);

        do_action('wpcs_tenant_resumed', $subscription_id);
    }

    /** End of the grace period. Does nothing if the store was resumed in the meantime. */
    public function delete_suspended_tenant($subscription_id)
    {
        $commerce = Commerce::require_adapter();
        $tenant = new WPCSTenant($subscription_id);
        if ($tenant->get_status() !== WPCSTenant::SUSPENDED) {
            return;
        }

        $this->wpcsService->delete_tenant([
            'external_id' => $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META),
        ]);

        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_DELETE_AT_META, '');
        $tenant->update_status(WPCSTenant::DELETED);

        do_action('wpcs_tenant_deleted', $subscription_id);
    }

    /**
     * Restore a deleted store from its newest finished backup. False when it has none left (backups are kept
     * 90 days after deletion); API errors are thrown, so a failure never leads to a second store.
     */
    private function restore_deleted_tenant($subscription_id): bool
    {
        $external_id = Commerce::require_adapter()->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META);

        $backup = null;
        foreach ($this->wpcsService->get_tenant_backups($external_id) as $candidate) {
            if (isset($candidate->id, $candidate->statusCode) && $candidate->statusCode === 1) {
                $backup = $candidate;
                break;
            }
        }
        if ($backup === null) {
            return false;
        }

        $this->wpcsService->restore_tenant($external_id, $backup->id);

        // Same externalId and domains: the status poller takes it from Provisioning to Ready as for a new store.
        do_action('wpcs_tenant_linked_to_subscription', $subscription_id);
        do_action('wpcs_tenant_restored', $subscription_id, $backup->id);

        return true;
    }

    /**
     * No backup left: unlink the deleted store so the subscription gets a new one. The state stays Deleted (the
     * poller skips it) until the new store is linked.
     */
    private function forget_deleted_tenant($subscription_id): void
    {
        $commerce = Commerce::require_adapter();
        foreach ([
            WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META,
            WPCSTenant::WPCS_DOMAIN_NAME_META,
            WPCSTenant::WPCS_BASE_DOMAIN_NAME_META,
            WPCSTenant::WPCS_TENANT_DELETE_AT_META,
        ] as $key) {
            $commerce->update_subscription_meta($subscription_id, $key, '');
        }
    }
}
