<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;

class TenantStatusService
{
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
}
