<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\EncryptionService;
use WaaSHost\Core\PrivateKeyStore;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;

/**
 * The store's lifecycle, driven by the cart's subscription events.
 *
 * The event handlers never call the provisioning API: they decide from the subscription's own data what has to
 * happen, record the store's new state and queue the API work in StoreJobs. The job handlers below (job_*) make the
 * calls in the background, in order, with retries. They are written to run more than once: a create reuses the
 * externalId and keys chosen the first time, and checks whether an earlier try already created the store.
 */
class TenantsSubscriptionManager
{
    /** Days a suspended store is kept before it is deleted. */
    public const DELETION_GRACE_DAYS_OPTION = 'wpcs_host_settings_deletion_grace_days';
    public const DEFAULT_DELETION_GRACE_DAYS = 30;
    public const DELETE_SUSPENDED_TENANT_HOOK = 'wpcs_delete_suspended_tenant';

    /** Jobs that bring a store into existence; until they have run the subscription has no externalId yet. */
    private const CREATING_JOBS = ['create', 'restore_or_create'];

    private WPCSService $wpcsService;
    private StoreJobs $jobs;
    private NameConflicts $name_conflicts;

    public function __construct(WPCSService $wpcsService, StoreJobs $jobs, ?NameConflicts $name_conflicts = null)
    {
        $this->wpcsService = $wpcsService;
        $this->jobs = $jobs;
        $this->name_conflicts = $name_conflicts ?? new NameConflicts($wpcsService, $jobs);

        add_action('wpcs_subscription_created', [$this, 'create_tenant_when_subscription_created'], 10, 2);
        add_action('wpcs_subscription_suspended', [$this, 'suspend_tenant_on_hold']);
        add_action('wpcs_subscription_reactivated', [$this, 'resume_suspended_tenant']);
        add_action('wpcs_subscription_expired', [$this, 'suspend_tenant_when_subscription_expired']);
        add_action(self::DELETE_SUSPENDED_TENANT_HOOK, [$this, 'delete_suspended_tenant']);

        $jobs->register('create', [$this, 'job_create']);
        $jobs->register('restore_or_create', [$this, 'job_restore_or_create']);
        $jobs->register('suspend', [$this, 'job_suspend']);
        $jobs->register('resume', [$this, 'job_resume']);
        $jobs->register('delete', [$this, 'job_delete']);
    }

    public static function get_deletion_grace_days(): int
    {
        return max(1, absint(get_option(self::DELETION_GRACE_DAYS_OPTION, self::DEFAULT_DELETION_GRACE_DAYS)));
    }

    /**
     * Active: queue the creation of a store, or bring back the one the subscription had (resume a suspended
     * store; restore a deleted one from its newest backup, or create a fresh one when no backup is left).
     *
     * @param mixed $purchase_context passed by the cart adapter; only the adapter reads it
     */
    public function create_tenant_when_subscription_created($subscription_id, $purchase_context)
    {
        if (NameConflicts::is_waiting($subscription_id)) {
            // The store waits for the customer's new name (and is never created twice).
            $this->name_conflicts->resume($subscription_id);
            return;
        }
        if ($this->has_store($subscription_id)) {
            $status = $this->jobs->status($subscription_id);
            if ($status === WPCSTenant::SUSPENDED) {
                $this->resume_suspended_tenant($subscription_id);
            } elseif ($status === WPCSTenant::DELETED && !$this->jobs->has_pending($subscription_id, self::CREATING_JOBS)) {
                $this->jobs->enqueue($subscription_id, 'restore_or_create', ['store' => $this->new_store_args($subscription_id, $purchase_context)]);
                $this->jobs->set_status($subscription_id, WPCSTenant::QUEUED);
            }
            return;
        }

        $store = $this->new_store_args($subscription_id, $purchase_context);
        if ($store === null) {
            return;
        }

        $this->jobs->enqueue($subscription_id, 'create', $store);
        $this->jobs->set_status($subscription_id, WPCSTenant::QUEUED);
        // Recorded now, so checkout refuses the same name for someone else even before the store exists.
        Commerce::require_adapter()->update_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META, (string) ($store['request']['custom_domain_name'] ?? ''));
    }

    /**
     * Cancelled or expired: back the store up and take it offline. It is deleted after the grace
     * period unless the subscription becomes active again first.
     */
    public function suspend_tenant_when_subscription_expired($subscription_id)
    {
        if (NameConflicts::is_waiting($subscription_id)) {
            $this->name_conflicts->pause($subscription_id);
            return;
        }
        $commerce = Commerce::require_adapter();
        $status = $this->jobs->status($subscription_id);

        // Carts can report one cancellation several times (cancelled, then end of term).
        if (!$this->has_store($subscription_id)
            || $status === WPCSTenant::DELETED
            || !empty($commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_DELETE_AT_META))) {
            return;
        }

        $delete_at = time() + self::get_deletion_grace_days() * DAY_IN_SECONDS;
        // Already offline since it went on hold, without a backup: the job only takes the backup.
        $this->jobs->enqueue($subscription_id, 'suspend', ['backup' => true, 'already_suspended' => $status === WPCSTenant::SUSPENDED]);
        $this->jobs->set_status($subscription_id, WPCSTenant::SUSPENDED);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_DELETE_AT_META, $delete_at);
        wp_schedule_single_event($delete_at, self::DELETE_SUSPENDED_TENANT_HOOK, [$subscription_id]);
    }

    /**
     * On hold (failed renewal payment, or put on hold by an admin): take the store offline. No backup and no
     * deletion: both start only if the subscription is then cancelled or expires.
     */
    public function suspend_tenant_on_hold($subscription_id)
    {
        if (NameConflicts::is_waiting($subscription_id)) {
            $this->name_conflicts->pause($subscription_id);
            return;
        }
        if (!$this->has_store($subscription_id)
            || in_array($this->jobs->status($subscription_id), [WPCSTenant::SUSPENDED, WPCSTenant::DELETED], true)) {
            return;
        }

        $this->jobs->enqueue($subscription_id, 'suspend', ['backup' => false, 'already_suspended' => false]);
        $this->jobs->set_status($subscription_id, WPCSTenant::SUSPENDED);
    }

    public function resume_suspended_tenant($subscription_id)
    {
        if (NameConflicts::is_waiting($subscription_id)) {
            $this->name_conflicts->resume($subscription_id);
            return;
        }
        if ($this->jobs->status($subscription_id) !== WPCSTenant::SUSPENDED) {
            return;
        }

        $this->jobs->enqueue($subscription_id, 'resume');
        wp_clear_scheduled_hook(self::DELETE_SUSPENDED_TENANT_HOOK, [$subscription_id]);
        Commerce::require_adapter()->update_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_DELETE_AT_META, '');
        $this->jobs->set_status($subscription_id, WPCSTenant::READY);
    }

    /** End of the grace period. Does nothing if the store was resumed in the meantime. */
    public function delete_suspended_tenant($subscription_id)
    {
        if ($this->jobs->status($subscription_id) !== WPCSTenant::SUSPENDED) {
            return;
        }

        $this->jobs->enqueue($subscription_id, 'delete');
        Commerce::require_adapter()->update_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_DELETE_AT_META, '');
        $this->jobs->set_status($subscription_id, WPCSTenant::DELETED);
    }

    /**
     * Create the store. The externalId was chosen when the job was queued, so a retry after a create that reached
     * the API (but whose answer was lost) finds that store instead of making a second one.
     */
    public function job_create($subscription_id, array $store, int $attempt): ?array
    {
        $external_id = $store['external_id'];
        $public_key = $this->key_pair($subscription_id);

        $tenant = $attempt > 0 ? $this->wpcsService->get_tenant_safe($external_id) : null;
        if ($tenant !== null) {
            $domain_name = $tenant->domainName;
            $base_domain = $tenant->baseDomain ?? $tenant->domainName;
        } else {
            $args = $store['request'];
            $args['external_id'] = $external_id;
            $args['wordpress_user_role'] = get_option(WPCSTenant::WPCS_DEFAULT_USER_ROLE);
            $args['php_constants']['WPCS_PUBLIC_KEY'] = ['value' => base64_encode($public_key), 'isPrivate' => false];
            $args['php_constants']['WPCS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS'] = [
                'value' => apply_filters('wpcs_remove_tenant_administrator_plugin_capabilities', true) ? "true" : "false",
                'isPrivate' => false,
            ];

            try {
                $new_tenant = $this->wpcsService->create_tenant($args);
            } catch (\Exception $error) {
                if ($this->name_taken($args, $error)) {
                    // Retrying can't help: park the store until the customer picks another name.
                    $this->name_conflicts->park($subscription_id, $store, $error->getMessage());
                    return ['replace' => []];
                }
                throw $error;
            }
            $external_id = $new_tenant->externalId ?? $external_id;
            $domain_name = $new_tenant->customDomain ?? $new_tenant->baseDomain;
            $base_domain = $new_tenant->baseDomain;
        }

        $commerce = Commerce::require_adapter();
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META, $external_id);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META, $domain_name);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_BASE_DOMAIN_NAME_META, $base_domain);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_SUBSCRIPTION_USER_ROLES, [$store['product_role']]);

        $this->store_linked($subscription_id);

        return null;
    }

    /**
     * Bring back a deleted store from its newest finished backup (kept 90 days after deletion), or replace this
     * job with the creation of a fresh store when there is none.
     */
    public function job_restore_or_create($subscription_id, array $args, int $attempt): ?array
    {
        $commerce = Commerce::require_adapter();
        $external_id = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META);

        if ($attempt > 0) {
            // An earlier try may have scheduled the restore before failing.
            $tenant = $this->wpcsService->get_tenant_safe($external_id);
            if ($tenant !== null && ($tenant->statusCode ?? null) !== 3) {
                $this->store_linked($subscription_id);
                return null;
            }
        }

        foreach ($this->wpcsService->get_tenant_backups($external_id) as $backup) {
            if (isset($backup->id, $backup->statusCode) && $backup->statusCode === 1) {
                $this->wpcsService->restore_tenant($external_id, $backup->id);
                // Same externalId and domains: the status poller takes it from Provisioning to Ready.
                $this->store_linked($subscription_id);
                do_action('wpcs_tenant_restored', $subscription_id, $backup->id);
                return null;
            }
        }

        if (empty($args['store'])) {
            throw new \RuntimeException('The deleted store has no backup left, and the purchase has no website plan to create a new one from.');
        }

        // No backup left: unlink the deleted store; the subscription gets a new one.
        foreach ([
            WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META,
            WPCSTenant::WPCS_DOMAIN_NAME_META,
            WPCSTenant::WPCS_BASE_DOMAIN_NAME_META,
            WPCSTenant::WPCS_TENANT_PUBLIC_KEY_META,
            WPCSTenant::WPCS_TENANT_PRIVATE_KEY_META,
        ] as $key) {
            $commerce->update_subscription_meta($subscription_id, $key, '');
        }

        return ['replace' => [['create', $args['store']]]];
    }

    public function job_suspend($subscription_id, array $args, int $attempt): ?array
    {
        $external_id = $this->external_id($subscription_id);
        if ($external_id === '') {
            return null; // never created (its create was parked for a new name)
        }
        if (!empty($args['already_suspended'])) {
            $this->wpcsService->create_tenant_backup($external_id);
        } else {
            $this->wpcsService->suspend_tenant($external_id, (bool) $args['backup']);
        }

        do_action('wpcs_tenant_suspended', $subscription_id, (int) Commerce::require_adapter()->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_DELETE_AT_META));

        return null;
    }

    public function job_resume($subscription_id, array $args, int $attempt): ?array
    {
        $external_id = $this->external_id($subscription_id);
        if ($external_id === '') {
            return null; // never created (its create was parked for a new name)
        }
        $this->wpcsService->resume_tenant($external_id);
        do_action('wpcs_tenant_resumed', $subscription_id);

        return null;
    }

    public function job_delete($subscription_id, array $args, int $attempt): ?array
    {
        $external_id = $this->external_id($subscription_id);
        if ($external_id === '') {
            return null; // never created (its create was parked for a new name)
        }

        // An earlier try may have scheduled the deletion before failing.
        $tenant = $attempt > 0 ? $this->wpcsService->get_tenant_safe($external_id) : false;
        if ($tenant === false || ($tenant !== null && ($tenant->statusCode ?? null) !== 3)) {
            $this->wpcsService->delete_tenant(['external_id' => $external_id]);
        }

        do_action('wpcs_tenant_deleted', $subscription_id);

        return null;
    }

    /**
     * The create was refused because the custom domain belongs to another store: the API's 400 "Domain ... is
     * already in use", or any 400 when the availability check then says the domain is taken.
     */
    private function name_taken(array $args, \Exception $error): bool
    {
        $domain = (string) ($args['custom_domain_name'] ?? '');
        $message = $error->getMessage();
        if ($domain === '' || strpos($message, 'Failed: 400') !== 0) {
            return false;
        }
        if (stripos($message, 'already in use') !== false) {
            return true;
        }

        try {
            return !$this->wpcsService->domain_available($domain);
        } catch (\Throwable $e) {
            return false; // can't tell: retry as usual
        }
    }

    /** True when the subscription has a store, or one is queued to be created. */
    private function has_store($subscription_id): bool
    {
        return !empty(Commerce::require_adapter()->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META))
            || $this->jobs->has_pending($subscription_id, self::CREATING_JOBS);
    }

    private function external_id($subscription_id): string
    {
        return (string) Commerce::require_adapter()->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META);
    }

    /**
     * What the create job needs from the purchase, read now while the cart has it: the job may run much later.
     * Null when the purchase has no website plan.
     */
    private function new_store_args($subscription_id, $purchase_context): ?array
    {
        $purchase = Commerce::require_adapter()->get_plan_purchase($subscription_id, $purchase_context);
        if (empty($purchase->plans)) {
            return null;
        }

        $group_name = '';
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
        $request = [
            'name' => $website_name,
            'wordpress_username' => SingleLoginService::get_formatted_username($subscription_id),
            'wordpress_email' => $purchase->customer_email,
            'group_name' => empty($group_name) ? null : $group_name,
            'php_constants' => [
                'WPCS_TENANT_ROLES' => ['value' => implode(",", $subscription_roles), 'isPrivate' => false],
            ],
        ];

        $tenant_root_domain = get_option('wpcs_host_settings_root_domain', '');
        if ($tenant_root_domain !== '') {
            $request['custom_domain_name'] = sanitize_title_with_dashes(remove_accents($website_name)) . '.' . $tenant_root_domain;
        }

        return [
            'external_id' => wp_generate_uuid4(),
            'request' => $request,
            'product_role' => $product_role,
        ];
    }

    /** The one-click-login key pair: made once per store and kept, so a retried create sends the same public key. */
    private function key_pair($subscription_id): string
    {
        $commerce = Commerce::require_adapter();
        $public_key = (string) $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_PUBLIC_KEY_META);
        if ($public_key !== '' && PrivateKeyStore::load($subscription_id) !== '') {
            return $public_key;
        }

        $keys = EncryptionService::generate_key_pair();
        PrivateKeyStore::save($subscription_id, $keys['private_key']);
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_PUBLIC_KEY_META, $keys['public_key']);

        return $keys['public_key'];
    }

    /** The store exists in the API: the status poller takes it to Ready, unless it was suspended in the meantime. */
    private function store_linked($subscription_id): void
    {
        if ($this->jobs->status($subscription_id) === WPCSTenant::QUEUED) {
            do_action('wpcs_tenant_linked_to_subscription', $subscription_id);
        }
    }
}
