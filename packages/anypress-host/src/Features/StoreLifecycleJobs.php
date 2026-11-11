<?php

namespace AnyPressHost\Features;

use AnyPressHost\Commerce\Commerce;
use AnyPressHost\Core\EncryptionService;
use AnyPressHost\Core\PrivateKeyStore;
use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Core\Tenant;

/**
 * The store jobs TenantsSubscriptionManager queues (create, restore_or_create, suspend, resume, delete): they make the
 * provisioning API calls in the background, in order, with retries. They are written to run more than once: a create
 * reuses the externalId and keys chosen the first time, and checks whether an earlier try already created the store.
 */
class StoreLifecycleJobs
{
    private ProvisioningApi $provisioningApi;
    private StoreJobs $jobs;
    private NameConflicts $name_conflicts;

    public function __construct(ProvisioningApi $provisioningApi, StoreJobs $jobs, NameConflicts $name_conflicts)
    {
        $this->provisioningApi = $provisioningApi;
        $this->jobs = $jobs;
        $this->name_conflicts = $name_conflicts;

        $jobs->register('create', [$this, 'job_create']);
        $jobs->register('restore_or_create', [$this, 'job_restore_or_create']);
        $jobs->register('suspend', [$this, 'job_suspend']);
        $jobs->register('resume', [$this, 'job_resume']);
        $jobs->register('delete', [$this, 'job_delete']);
    }

    /**
     * Create the store. The externalId was chosen when the job was queued, so a retry after a create that reached
     * the API (but whose answer was lost) finds that store instead of making a second one.
     */
    public function job_create($subscription_id, array $store, int $attempt): ?array
    {
        $external_id = $store['external_id'];
        $public_key = $this->key_pair($subscription_id);

        $tenant = $attempt > 0 ? $this->provisioningApi->get_tenant_safe($external_id) : null;
        if ($tenant === null) {
            $args = $store['request'];
            $args['external_id'] = $external_id;
            // The setting's 'administrator' default is registered for wp-admin only; jobs run from cron, so give it here too.
            $args['wordpress_user_role'] = (string) get_option(Tenant::DEFAULT_USER_ROLE, 'administrator') ?: 'administrator';
            $args['php_constants']['ANYPRESS_PUBLIC_KEY'] = ['value' => base64_encode($public_key), 'isPrivate' => false];
            $args['php_constants']['ANYPRESS_TENANT_NO_ADMINISTRATOR_PLUGIN_CAPS'] = [
                'value' => apply_filters('anypress_remove_tenant_administrator_plugin_capabilities', true) ? "true" : "false",
                'isPrivate' => false,
            ];
            $args['wp_options'] = $this->store_options($args);

            try {
                $new_tenant = $this->create_tenant($args);
            } catch (\Exception $error) {
                // The externalId is this subscription's own, so a store already holding it is ours: an earlier try
                // got through (an admin's retry starts again at attempt 0, skipping the lookup above). Adopt it.
                $tenant = $this->external_id_taken($error) ? $this->provisioningApi->get_tenant_safe($external_id) : null;
                if ($tenant === null && $this->name_taken($args, $error)) {
                    // Retrying can't help: park the store until the customer picks another name.
                    $this->name_conflicts->park($subscription_id, $store, $error->getMessage());
                    return ['replace' => []];
                }
                if ($tenant === null) {
                    throw $error;
                }
            }
        }

        if ($tenant !== null) {
            $domain_name = $tenant->domainName;
            $base_domain = $tenant->baseDomain ?? $tenant->domainName;
        } else {
            $external_id = $new_tenant->externalId ?? $external_id;
            $domain_name = $new_tenant->customDomain ?? $new_tenant->baseDomain;
            $base_domain = $new_tenant->baseDomain;
        }

        $commerce = Commerce::require_adapter();
        $commerce->update_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META, $external_id);
        $commerce->update_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META, $domain_name);
        $commerce->update_subscription_meta($subscription_id, Tenant::BASE_DOMAIN_NAME_META, $base_domain);
        // 'roles' is set when the plan changed while the store waited for a new name.
        $commerce->update_subscription_meta($subscription_id, Tenant::SUBSCRIPTION_USER_ROLES, $store['roles'] ?? [$store['product_role']]);

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
        $external_id = $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);

        if ($attempt > 0) {
            // An earlier try may have scheduled the restore before failing.
            $tenant = $this->provisioningApi->get_tenant_safe($external_id);
            if ($tenant !== null && ($tenant->statusCode ?? null) !== 3) {
                $this->store_linked($subscription_id);
                return null;
            }
        }

        foreach ($this->provisioningApi->get_tenant_backups($external_id) as $backup) {
            if (isset($backup->id, $backup->statusCode) && $backup->statusCode === 1) {
                $this->provisioningApi->restore_tenant($external_id, $backup->id);
                // Same externalId and domains: the status poller takes it from Provisioning to Ready.
                $this->store_linked($subscription_id);
                do_action('anypress_tenant_restored', $subscription_id, $backup->id);
                return null;
            }
        }

        if (empty($args['store'])) {
            throw new \RuntimeException('The deleted store has no backup left, and the purchase has no website plan to create a new one from.');
        }

        // No backup left: unlink the deleted store; the subscription gets a new one.
        foreach (
            [
            Tenant::TENANT_EXTERNAL_ID_META,
            Tenant::DOMAIN_NAME_META,
            Tenant::BASE_DOMAIN_NAME_META,
            Tenant::TENANT_PUBLIC_KEY_META,
            Tenant::TENANT_PRIVATE_KEY_META,
            ] as $key
        ) {
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
            $this->provisioningApi->create_tenant_backup($external_id);
        } else {
            $this->provisioningApi->suspend_tenant($external_id, (bool) $args['backup']);
        }

        do_action('anypress_tenant_suspended', $subscription_id, (int) Commerce::require_adapter()->get_subscription_meta($subscription_id, Tenant::TENANT_DELETE_AT_META));

        return null;
    }

    public function job_resume($subscription_id, array $args, int $attempt): ?array
    {
        $external_id = $this->external_id($subscription_id);
        if ($external_id === '') {
            return null; // never created (its create was parked for a new name)
        }
        $this->provisioningApi->resume_tenant($external_id);
        do_action('anypress_tenant_resumed', $subscription_id);

        return null;
    }

    public function job_delete($subscription_id, array $args, int $attempt): ?array
    {
        $external_id = $this->external_id($subscription_id);
        if ($external_id === '') {
            return null; // never created (its create was parked for a new name)
        }

        // An earlier try may have scheduled the deletion before failing.
        $tenant = $attempt > 0 ? $this->provisioningApi->get_tenant_safe($external_id) : false;
        if ($tenant === false || ($tenant !== null && ($tenant->statusCode ?? null) !== 3)) {
            $this->provisioningApi->delete_tenant(['external_id' => $external_id]);
        }

        do_action('anypress_tenant_deleted', $subscription_id);

        return null;
    }

    /**
     * Create the store from its demo draft, if it has one. When the API refuses the draft (expired, already used, or
     * made for another site type or storefront), the store is made from the template instead: the buyer has paid.
     */
    private function create_tenant(array $args)
    {
        try {
            return $this->provisioningApi->create_tenant($args);
        } catch (\Exception $error) {
            $message = $error->getMessage();
            if (empty($args['demo_draft_id']) || strpos($message, 'Failed: 400') !== 0 || stripos($message, 'demo draft') === false) {
                throw $error;
            }
            error_log('AnyPress Host: the store is made without its demo, which the API refused: ' . $message);
            unset($args['demo_draft_id']);

            return $this->provisioningApi->create_tenant($args);
        }
    }

    /** The API refused the create because a store already has this externalId (409; older APIs answer 400). */
    private function external_id_taken(\Exception $error): bool
    {
        $message = $error->getMessage();
        return (strpos($message, 'Failed: 409') === 0 || strpos($message, 'Failed: 400') === 0)
            && preg_match('/externalId \S+ (is already in use|belongs to)/', $message) === 1;
    }

    /**
     * The create was refused because the custom domain belongs to another store: the API's 400 "Domain ... is
     * already in use", or any 400 when the availability check then says the domain is taken.
     */
    private function name_taken(array $args, \Exception $error): bool
    {
        $domain = (string) ($args['custom_domain_name'] ?? '');
        $message = $error->getMessage();
        if ($domain === '' || strpos($message, 'Failed: 400') !== 0 || $this->external_id_taken($error)) {
            return false;
        }
        if (stripos($message, 'already in use') !== false) {
            return true;
        }

        try {
            return !$this->provisioningApi->domain_available($domain);
        } catch (\Throwable $e) {
            return false; // can't tell: retry as usual
        }
    }

    private function external_id($subscription_id): string
    {
        return (string) Commerce::require_adapter()->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
    }

    /**
     * WordPress settings the new store starts with: its title is the website name, and its admin email (where
     * WordPress and the store's plugins send owner notices) is the buyer's, not the template's placeholder.
     * `anypress_store_owner` (JSON) carries the rest: the country and the buyer's billing name, phone and address, which
     * the store fills in as its business details, and their answer to what the business does, if they gave one. Read at job time, so a name changed after a conflict is the one used.
     */
    private function store_options(array $args): array
    {
        $options = ['blogname' => (string) $args['name']];
        if (filter_var($args['wordpress_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $options['admin_email'] = (string) $args['wordpress_email'];
        }
        $owner = array_filter((array) ($args['owner'] ?? []), static fn ($value) => (string) $value !== '');
        if ($owner) {
            $options['anypress_store_owner'] = (string) wp_json_encode($owner);
        }

        return (array) apply_filters('anypress_tenant_wp_options', $options, $args);
    }

    /** The one-click-login key pair: made once per store and kept, so a retried create sends the same public key. */
    private function key_pair($subscription_id): string
    {
        $commerce = Commerce::require_adapter();
        $public_key = (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_PUBLIC_KEY_META);
        if ($public_key !== '' && PrivateKeyStore::load($subscription_id) !== '') {
            return $public_key;
        }

        $keys = EncryptionService::generate_key_pair();
        PrivateKeyStore::save($subscription_id, $keys['private_key']);
        $commerce->update_subscription_meta($subscription_id, Tenant::TENANT_PUBLIC_KEY_META, $keys['public_key']);

        return $keys['public_key'];
    }

    /** The store exists in the API: the status poller takes it to Ready, unless it was suspended in the meantime. */
    private function store_linked($subscription_id): void
    {
        if ($this->jobs->status($subscription_id) === Tenant::QUEUED) {
            do_action('anypress_tenant_linked_to_subscription', $subscription_id);
        }
    }
}
