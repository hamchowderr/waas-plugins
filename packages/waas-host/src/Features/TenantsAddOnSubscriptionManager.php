<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\Tenant;
use WaaSHost\Core\ProvisioningApi;

/**
 * Add-ons bought or removed: the store's roles change. The API call runs as a store job, after any job queued
 * before it (e.g. the store's creation).
 */
class TenantsAddOnSubscriptionManager
{
    private ProvisioningApi $provisioningApi;
    private StoreJobs $jobs;

    public function __construct(ProvisioningApi $provisioningApi, StoreJobs $jobs)
    {
        $this->provisioningApi = $provisioningApi;
        $this->jobs = $jobs;
        add_action('waas_tenant_roles_changed', [$this, 'update_tenant_roles'], 10, 2);
        $jobs->register('update_roles', [$this, 'job_update_roles']);
    }

    public function update_tenant_roles($subscription_id, $subscription_roles)
    {
        $roles = array_values(array_unique(array_filter(array_map('strval', (array) $subscription_roles))));
        $this->jobs->enqueue($subscription_id, 'update_roles', ['roles' => $roles]);
    }

    public function job_update_roles($subscription_id, array $args, int $attempt): ?array
    {
        $commerce = Commerce::require_adapter();
        $tenant_external_id = (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
        if ($tenant_external_id === '') {
            // No store yet. One waiting for a new name gets the roles when it is created.
            NameConflicts::set_roles($subscription_id, $args['roles']);
            return null;
        }

        $this->provisioningApi->update_tenant($tenant_external_id, [
            'php_constants' => ['WAAS_TENANT_ROLES' => ['value' => implode(",", $args['roles']), 'isPrivate' => false]]
        ]);
        $commerce->update_subscription_meta($subscription_id, Tenant::SUBSCRIPTION_USER_ROLES, $args['roles']);

        return null;
    }
}
