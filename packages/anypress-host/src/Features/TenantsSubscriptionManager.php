<?php

namespace AnyPressHost\Features;

use AnyPressHost\Commerce\Commerce;
use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Core\Tenant;

/**
 * The store's lifecycle, driven by the cart's subscription events.
 *
 * The event handlers never call the provisioning API: they decide from the subscription's own data what has to
 * happen, record the store's new state and queue the API work in StoreJobs. The job handlers (StoreLifecycleJobs) make
 * the calls in the background, in order, with retries. They are written to run more than once: a create reuses the
 * externalId and keys chosen the first time, and checks whether an earlier try already created the store.
 */
class TenantsSubscriptionManager
{
    /** Days a suspended store is kept before it is deleted. */
    public const DELETION_GRACE_DAYS_OPTION = 'anypress_deletion_grace_days';
    public const DEFAULT_DELETION_GRACE_DAYS = 30;
    public const DELETE_SUSPENDED_TENANT_HOOK = 'anypress_delete_suspended_store';
    /** Fires when a cancelled subscription's paid period ends. */
    public const SUSPEND_AT_PERIOD_END_HOOK = 'anypress_suspend_at_period_end';

    /** Jobs that bring a store into existence; until they have run the subscription has no externalId yet. */
    private const CREATING_JOBS = ['create', 'restore_or_create'];

    private StoreJobs $jobs;
    private NameConflicts $name_conflicts;

    public function __construct(ProvisioningApi $provisioningApi, StoreJobs $jobs, ?NameConflicts $name_conflicts = null)
    {
        $this->jobs = $jobs;
        $this->name_conflicts = $name_conflicts ?? new NameConflicts($provisioningApi, $jobs);

        add_action('anypress_subscription_created', [$this, 'create_tenant_when_subscription_created'], 10, 2);
        add_action('anypress_subscription_suspended', [$this, 'suspend_tenant_on_hold']);
        add_action('anypress_subscription_reactivated', [$this, 'resume_suspended_tenant']);
        add_action('anypress_subscription_renewed', [$this, 'resume_after_renewal']);
        add_action('anypress_subscription_expired', [$this, 'suspend_tenant_when_subscription_expired']);
        add_action('anypress_subscription_canceled_until', [$this, 'suspend_at_end_of_paid_period'], 10, 2);
        add_action(self::SUSPEND_AT_PERIOD_END_HOOK, [$this, 'paid_period_ended']);
        add_action(self::DELETE_SUSPENDED_TENANT_HOOK, [$this, 'delete_suspended_tenant']);
        // Safety net for a lost WP-cron event: the store jobs' recurring sweep also runs suspensions that are due.
        add_action(StoreJobs::SWEEP_HOOK, [$this, 'run_due_suspensions']);

        new StoreLifecycleJobs($provisioningApi, $jobs, $this->name_conflicts);
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
            // Active again before the paid period of a cancellation ended: the store simply stays.
            $this->cancel_scheduled_suspend($subscription_id);
            $status = $this->jobs->status($subscription_id);
            if ($status === Tenant::SUSPENDED) {
                $this->resume_suspended_tenant($subscription_id);
            } elseif ($status === Tenant::DELETED && !$this->jobs->has_pending($subscription_id, self::CREATING_JOBS)) {
                $this->jobs->enqueue($subscription_id, 'restore_or_create', ['store' => $this->new_store_args($subscription_id, $purchase_context)]);
                $this->jobs->set_status($subscription_id, Tenant::QUEUED);
            }
            return;
        }

        $store = $this->new_store_args($subscription_id, $purchase_context);
        if ($store === null) {
            return;
        }

        $this->jobs->enqueue($subscription_id, 'create', $store);
        $this->jobs->set_status($subscription_id, Tenant::QUEUED);
        // Recorded now, so checkout refuses the same name for someone else even before the store exists.
        Commerce::require_adapter()->update_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META, (string) ($store['request']['custom_domain_name'] ?? ''));
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
        if (
            !$this->has_store($subscription_id)
            || $status === Tenant::DELETED
            || !empty($commerce->get_subscription_meta($subscription_id, Tenant::TENANT_DELETE_AT_META))
        ) {
            return;
        }

        $delete_at = time() + self::get_deletion_grace_days() * DAY_IN_SECONDS;
        // Already offline since it went on hold, without a backup: the job only takes the backup.
        $this->jobs->enqueue($subscription_id, 'suspend', ['backup' => true, 'already_suspended' => $status === Tenant::SUSPENDED]);
        $this->jobs->set_status($subscription_id, Tenant::SUSPENDED);
        $commerce->update_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_REASON_META, Tenant::SUSPENDED_ENDED);
        $commerce->update_subscription_meta($subscription_id, Tenant::TENANT_DELETE_AT_META, $delete_at);
        wp_schedule_single_event($delete_at, self::DELETE_SUSPENDED_TENANT_HOOK, [$subscription_id]);
    }

    /**
     * Cancelled, but paid until $paid_through: the store stays online until then, and the suspension (with backup and
     * the deletion grace period) starts at that time. A store that is already offline, or has no store yet, takes the
     * usual path at once.
     */
    public function suspend_at_end_of_paid_period($subscription_id, $paid_through)
    {
        $paid_through = (int) $paid_through;
        $commerce = Commerce::require_adapter();

        if (
            $paid_through <= time()
            || NameConflicts::is_waiting($subscription_id)
            || !$this->has_store($subscription_id)
            || in_array($this->jobs->status($subscription_id), [Tenant::SUSPENDED, Tenant::DELETED], true)
            || !empty($commerce->get_subscription_meta($subscription_id, Tenant::TENANT_DELETE_AT_META))
        ) {
            $this->suspend_tenant_when_subscription_expired($subscription_id);
            return;
        }

        // Carts can report one cancellation more than once: the first date stands.
        if (!empty($commerce->get_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_AT_META))) {
            return;
        }

        $commerce->update_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_AT_META, $paid_through);
        wp_schedule_single_event($paid_through, self::SUSPEND_AT_PERIOD_END_HOOK, [$subscription_id]);
        do_action('anypress_tenant_suspend_scheduled', $subscription_id, $paid_through);
    }

    /** The paid period of a cancelled subscription is over: suspend now, unless the subscription came back. */
    public function paid_period_ended($subscription_id)
    {
        $commerce = Commerce::require_adapter();
        if (empty($commerce->get_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_AT_META))) {
            return; // reactivated meanwhile, or already handled
        }

        $commerce->update_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_AT_META, '');
        if ($commerce->is_subscription_active($subscription_id)) {
            return;
        }

        $this->suspend_tenant_when_subscription_expired($subscription_id);
    }

    /** Runs suspensions whose time has passed but whose WP-cron event never fired. */
    public function run_due_suspensions()
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return;
        }

        foreach ($commerce->find_subscriptions_by_meta(Tenant::TENANT_SUSPEND_AT_META, ['']) as $subscription_id => $suspend_at) {
            if ((int) $suspend_at > 0 && (int) $suspend_at <= time()) {
                $this->paid_period_ended($subscription_id);
            }
        }
    }

    /** Forgets a suspension planned for the end of a paid period. True if there was one. */
    private function cancel_scheduled_suspend($subscription_id): bool
    {
        $commerce = Commerce::require_adapter();
        if (empty($commerce->get_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_AT_META))) {
            return false;
        }

        $commerce->update_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_AT_META, '');
        wp_clear_scheduled_hook(self::SUSPEND_AT_PERIOD_END_HOOK, [$subscription_id]);
        do_action('anypress_tenant_suspend_canceled', $subscription_id);

        return true;
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
        if (
            !$this->has_store($subscription_id)
            || in_array($this->jobs->status($subscription_id), [Tenant::SUSPENDED, Tenant::DELETED], true)
        ) {
            return;
        }

        $this->jobs->enqueue($subscription_id, 'suspend', ['backup' => false, 'already_suspended' => false]);
        $this->jobs->set_status($subscription_id, Tenant::SUSPENDED);
        Commerce::require_adapter()->update_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_REASON_META, Tenant::SUSPENDED_ON_HOLD);
    }

    public function resume_suspended_tenant($subscription_id)
    {
        if (NameConflicts::is_waiting($subscription_id)) {
            $this->name_conflicts->resume($subscription_id);
            return;
        }
        $this->cancel_scheduled_suspend($subscription_id);
        if ($this->jobs->status($subscription_id) !== Tenant::SUSPENDED) {
            return;
        }

        $this->jobs->enqueue($subscription_id, 'resume');
        wp_clear_scheduled_hook(self::DELETE_SUSPENDED_TENANT_HOOK, [$subscription_id]);
        Commerce::require_adapter()->update_subscription_meta($subscription_id, Tenant::TENANT_DELETE_AT_META, '');
        Commerce::require_adapter()->update_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_REASON_META, '');
        $this->jobs->set_status($subscription_id, Tenant::READY);
    }

    /**
     * A renewal was paid. Brings back a store that anypress-host took offline for a billing event, when the subscription
     * is active again: e.g. the order that bought it was refunded in the payment provider's dashboard while billing
     * went on, or a renewal came in after the subscription went on hold. A store suspended some other way (no
     * reason recorded) and a deleted store are left as they are.
     */
    public function resume_after_renewal($subscription_id)
    {
        $commerce = Commerce::require_adapter();
        if (
            $this->jobs->status($subscription_id) !== Tenant::SUSPENDED
            || !in_array($commerce->get_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_REASON_META), [Tenant::SUSPENDED_ON_HOLD, Tenant::SUSPENDED_ENDED], true)
            || !$commerce->is_subscription_active($subscription_id)
        ) {
            return;
        }

        $this->resume_suspended_tenant($subscription_id);
    }

    /** End of the grace period. Does nothing if the store was resumed in the meantime. */
    public function delete_suspended_tenant($subscription_id)
    {
        if ($this->jobs->status($subscription_id) !== Tenant::SUSPENDED) {
            return;
        }

        $this->jobs->enqueue($subscription_id, 'delete');
        Commerce::require_adapter()->update_subscription_meta($subscription_id, Tenant::TENANT_DELETE_AT_META, '');
        $this->jobs->set_status($subscription_id, Tenant::DELETED);
    }

    /** True when the subscription has a store, or one is queued to be created. */
    private function has_store($subscription_id): bool
    {
        return !empty(Commerce::require_adapter()->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META))
            || $this->jobs->has_pending($subscription_id, self::CREATING_JOBS);
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
        $size = '';
        $subscription_roles = [];
        $product_role = '';
        foreach ($purchase->plans as $plan) {
            $product_role = $plan->role;
            if (!empty($product_role)) {
                $subscription_roles[] = $product_role;
            }
            $group_name = empty($group_name) ? $plan->group_name : $group_name;
            $size = $size === '' ? $plan->size : $size;
        }

        $website_name = sanitize_text_field($purchase->website_name);
        $request = [
            'name' => $website_name,
            'wordpress_username' => SingleLoginService::get_formatted_username($subscription_id),
            'wordpress_email' => $purchase->customer_email,
            'group_name' => empty($group_name) ? null : $group_name,
            'size' => $size === '' ? null : $size,
            'php_constants' => [
                'ANYPRESS_TENANT_ROLES' => ['value' => implode(",", $subscription_roles), 'isPrivate' => false],
            ],
            // Not sent as is: job_create turns it into the store's settings.
            'owner' => ['country' => strtoupper((string) preg_replace('/[^A-Za-z]/', '', $purchase->country))] + $this->owner_billing($purchase->billing),
        ];

        $demo = DemoDrafts::valid($purchase->demo_draft_id);
        if ($demo !== '') {
            $request['demo_draft_id'] = $demo;
        }

        $tenant_root_domain = get_option('anypress_root_domain', '');
        if ($tenant_root_domain !== '') {
            $request['custom_domain_name'] = sanitize_title_with_dashes(remove_accents($website_name)) . '.' . $tenant_root_domain;
        }

        return [
            'external_id' => wp_generate_uuid4(),
            'request' => $request,
            'product_role' => $product_role,
        ];
    }

    /** The buyer's billing details the store fills in as its business details (name, address, phone), cleaned. */
    private function owner_billing(array $billing): array
    {
        $owner = [];
        foreach (['name', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode'] as $key) {
            $value = trim(sanitize_text_field((string) ($billing[$key] ?? '')));
            if ($value !== '') {
                $owner[$key] = $value;
            }
        }
        // The checkout's optional "What does your business do?": the store's assistant starts from it.
        $about = isset($billing['about']) ? trim((string) preg_replace('/\s+/', ' ', sanitize_textarea_field((string) $billing['about']))) : '';
        if ($about !== '') {
            $owner['about'] = function_exists('mb_substr') ? mb_substr($about, 0, 500) : substr($about, 0, 500);
        }

        return $owner;
    }
}
