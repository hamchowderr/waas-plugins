<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;

class TenantStatusService
{
    /** Keeps each live store's address in step with the API's, for addresses changed outside this storefront. */
    public const SYNC_DOMAINS_HOOK = 'waas_cron_sync_tenant_domains';

    private ProvisioningApi $provisioningApi;

    public function __construct(ProvisioningApi $provisioningApi)
    {
        $this->provisioningApi = $provisioningApi;

        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        if (!wp_next_scheduled('wpcs_cron_poll_tenant_status')) {
            wp_schedule_event(time(), 'five_seconds', 'wpcs_cron_poll_tenant_status');
        }

        add_action('waas_tenant_linked_to_subscription', [$this, 'start_poll_tenant_status']);
        add_action('waas_subscription_removed', [$this, 'set_tenant_deleted']);
        add_action('wpcs_cron_poll_tenant_status', [$this, 'poll_tenant_status']);

        if (!wp_next_scheduled(self::SYNC_DOMAINS_HOOK)) {
            wp_schedule_event(time(), 'every_minute', self::SYNC_DOMAINS_HOOK);
        }
        add_action(self::SYNC_DOMAINS_HOOK, [$this, 'sync_tenant_domains']);
    }

    public function add_cron_schedules($schedules)
    {
        $schedules['five_seconds'] = [
            'interval' => 5,
            'display'  => esc_html__( 'Every Five Seconds' ),
        ];

        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => esc_html__( 'Every Minute' ),
        ];

        return $schedules;
    }

    public function start_poll_tenant_status($subscription_id)
    {
        (new Tenant($subscription_id))->update_status(Tenant::PROVISIONING);
    }

    public function set_tenant_deleted($subscription_id)
    {
        (new Tenant($subscription_id))->update_status(Tenant::DELETED);
    }

    public function poll_tenant_status()
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return;
        }

        // Only stores on their way to Ready. '' is a store waiting for a new name while its subscription isn't active.
        $results = $commerce->find_subscriptions_by_meta(Tenant::TENANT_STATE, ['', Tenant::QUEUED, Tenant::READY, Tenant::SUSPENDED, Tenant::DELETED, Tenant::FAILED, Tenant::NEEDS_NEW_NAME]);

        foreach ($results as $subscription_id => $current_status) {
            try {
                $tenant = new Tenant($subscription_id);

                $external_id = $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
                $target_domain_name = $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META);

                switch ($current_status) {
                    case Tenant::PROVISIONING:
                        $tenant_data = $this->provisioningApi->get_tenant_safe($external_id);
                        if (!isset($tenant_data)) {
                            error_log('Tenant not set');
                            break;
                        }

                        $tenant->update_status(Tenant::LINKING_DOMAIN);
                    case Tenant::LINKING_DOMAIN:
                        $tenant_data = $this->provisioningApi->get_tenant_safe($external_id);

                        if (!isset($tenant_data) || $tenant_data->statusCode === 0 || $target_domain_name !== $tenant_data->domainName) {
                            break;
                        }

                        $tenant->update_status(Tenant::REQUESTING_SSL);
                    case Tenant::REQUESTING_SSL:
                        try {
                            $response = wp_remote_head("https://" . $target_domain_name, [
                                "redirection" => 0
                            ]);

                            $response_code = wp_remote_retrieve_response_code($response);

                            if ($response_code === 200) {
                                $tenant->update_status(Tenant::READY);
                                do_action('waas_tenant_ready', $subscription_id, $external_id, $target_domain_name);
                            }
                        } catch (\Error $err) {
                            // Check error?
                        }
                        break;
                    default:
                        error_log("Subscription with Id " . $subscription_id . " has a weird " . Tenant::TENANT_STATE . " of " . $current_status);
                        break;
                }
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * Copies each live store's main address from the API to its subscription. An admin can change a
     * store's address outside this storefront (the dashboard's Change address); without this the account
     * page would keep showing, and signing in to, the old address. The API is the source of truth, so a
     * write racing a customer's own change here is put right on the next run. One request per run.
     */
    public function sync_tenant_domains()
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return;
        }

        $live = [];
        foreach ($commerce->find_subscriptions_by_meta(Tenant::TENANT_STATE, []) as $subscription_id => $state) {
            if ($state === Tenant::READY || $state === Tenant::SUSPENDED) {
                $live[] = $subscription_id;
            }
        }
        if (!$live) {
            return;
        }

        try {
            $tenants = $this->provisioningApi->get_tenants();
        } catch (\Exception $e) {
            return;
        }

        $domains = [];
        foreach ($tenants as $tenant) {
            $tenant = (object) $tenant;
            if (!empty($tenant->externalId) && !empty($tenant->domainName)) {
                $domains[(string) $tenant->externalId] = strtolower((string) $tenant->domainName);
            }
        }

        foreach ($live as $subscription_id) {
            $external_id = (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
            $domain = $domains[$external_id] ?? '';
            if ($domain !== '' && $domain !== (string) $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META)) {
                $commerce->update_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META, $domain);
            }
        }
    }
}
