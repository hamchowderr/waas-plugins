<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;

class WPCSTenantStatusService
{
    private WPCSService $wpcsService;

    public function __construct(WPCSService $wpcsService)
    {
        $this->wpcsService = $wpcsService;

        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        if (!wp_next_scheduled('wpcs_cron_poll_tenant_status')) {
            wp_schedule_event(time(), 'five_seconds', 'wpcs_cron_poll_tenant_status');
        }

        add_action('wpcs_tenant_linked_to_subscription', [$this, 'start_poll_tenant_status']);
        add_action('wpcs_subscription_removed', [$this, 'set_tenant_deleted']);
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
        (new WPCSTenant($subscription_id))->update_status(WPCSTenant::PROVISIONING);
    }

    public function set_tenant_deleted($subscription_id)
    {
        (new WPCSTenant($subscription_id))->update_status(WPCSTenant::DELETED);
    }

    public function poll_tenant_status()
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return;
        }

        $results = $commerce->find_subscriptions_by_meta(WPCSTenant::WPCS_TENANT_STATE, [WPCSTenant::READY, WPCSTenant::DELETED]);

        foreach ($results as $subscription_id => $current_status) {
            try {
                $tenant = new WPCSTenant($subscription_id);

                $external_id = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META);
                $target_domain_name = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META);

                switch ($current_status) {
                    case WPCSTenant::PROVISIONING:
                        $tenant_data = $this->wpcsService->get_tenant_safe($external_id);
                        if (!isset($tenant_data)) {
                            error_log('Tenant not set');
                            break;
                        }

                        $tenant->update_status(WPCSTenant::LINKING_DOMAIN);
                    case WPCSTenant::LINKING_DOMAIN:
                        $tenant_data = $this->wpcsService->get_tenant_safe($external_id);

                        if (!isset($tenant_data) || $tenant_data->statusCode === 0 || $target_domain_name !== $tenant_data->domainName) {
                            break;
                        }

                        $tenant->update_status(WPCSTenant::REQUESTING_SSL);
                    case WPCSTenant::REQUESTING_SSL:
                        try {
                            $response = wp_remote_head("https://" . $target_domain_name, [
                                "redirection" => 0
                            ]);

                            $response_code = wp_remote_retrieve_response_code($response);

                            if ($response_code === 200) {
                                $tenant->update_status(WPCSTenant::READY);
                                do_action('wpcs_tenant_ready', $subscription_id, $external_id, $target_domain_name);
                            }
                        } catch (\Error $err) {
                            // Check error?
                        }
                        break;
                    default:
                        error_log("Subscription with Id " . $subscription_id . " has a weird " . WPCSTenant::WPCS_TENANT_STATE . " of " . $current_status);
                        break;
                }
            } catch (\Exception $e) {
            }
        }
    }
}
