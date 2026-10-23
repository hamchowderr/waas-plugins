<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\CommerceAdapter;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;

class TenantStatusService
{
    /** Keeps each live store's address in step with the API's, for addresses changed outside this storefront. */
    public const SYNC_DOMAINS_HOOK = 'waas_cron_sync_tenant_domains';
    /** A single look at one store a few seconds after a webhook, when it wasn't Ready yet. */
    public const RECHECK_HOOK = 'waas_recheck_tenant_status';
    public const RECHECK_DELAY = 5;
    /** The states the poller takes a store through on its way to Ready. */
    public const IN_PROGRESS = [Tenant::PROVISIONING, Tenant::LINKING_DOMAIN, Tenant::REQUESTING_SSL];

    private ProvisioningApi $provisioningApi;

    public function __construct(ProvisioningApi $provisioningApi)
    {
        $this->provisioningApi = $provisioningApi;

        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        if (!wp_next_scheduled('waas_poll_store_status')) {
            wp_schedule_event(time(), 'five_seconds', 'waas_poll_store_status');
        }

        add_action('waas_tenant_linked_to_subscription', [$this, 'start_poll_tenant_status']);
        add_action('waas_subscription_removed', [$this, 'set_tenant_deleted']);
        add_action('waas_poll_store_status', [$this, 'poll_tenant_status']);

        if (!wp_next_scheduled(self::SYNC_DOMAINS_HOOK)) {
            wp_schedule_event(time(), 'every_minute', self::SYNC_DOMAINS_HOOK);
        }
        add_action(self::SYNC_DOMAINS_HOOK, [$this, 'sync_tenant_domains']);
        add_action(self::RECHECK_HOOK, [$this, 'process_subscription']);
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
        $this->keep_poll_interval();

        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return;
        }

        // Only stores on their way to Ready. '' is a store waiting for a new name while its subscription isn't active.
        $results = $commerce->find_subscriptions_by_meta(Tenant::TENANT_STATE, ['', Tenant::QUEUED, Tenant::READY, Tenant::SUSPENDED, Tenant::DELETED, Tenant::FAILED, Tenant::NEEDS_NEW_NAME]);

        foreach ($results as $subscription_id => $current_status) {
            $this->advance($commerce, $subscription_id, $current_status);
        }
    }

    /**
     * The poller's work for one store, run straight away when the API's webhook says the store changed. Does
     * nothing for a store that isn't on its way to Ready. Returns the state the store is left in.
     */
    public function process_subscription($subscription_id): string
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return '';
        }

        $current_status = (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_STATE);
        if (!in_array($current_status, self::IN_PROGRESS, true)) {
            return $current_status;
        }

        $this->advance($commerce, $subscription_id, $current_status);

        return (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_STATE);
    }

    /** One more look at a store in a few seconds, e.g. when its certificate wasn't ready yet. At most one is waiting. */
    public function recheck_soon($subscription_id): void
    {
        if (!wp_next_scheduled(self::RECHECK_HOOK, [$subscription_id])) {
            wp_schedule_single_event(time() + self::RECHECK_DELAY, self::RECHECK_HOOK, [$subscription_id]);
        }
    }

    /** With webhooks registered the poll is only a backstop, so it runs every minute instead of every five seconds. */
    public static function poll_recurrence(): string
    {
        return Webhooks::is_registered() ? 'every_minute' : 'five_seconds';
    }

    /**
     * Run from the poll's cron event: moves the event to the other schedule when webhooks were registered or lost
     * since it was scheduled. The change takes effect from the next run.
     */
    private function keep_poll_interval(): void
    {
        $hook = current_filter();
        if (!is_string($hook) || $hook === '' || !doing_action($hook)) {
            return;
        }

        $recurrence = self::poll_recurrence();
        $scheduled = wp_get_schedule($hook);
        if ($scheduled === false || $scheduled === $recurrence) {
            return;
        }

        wp_clear_scheduled_hook($hook);
        wp_schedule_event(time() + ($recurrence === 'every_minute' ? 60 : 5), $recurrence, $hook);
    }

    private function advance(CommerceAdapter $commerce, $subscription_id, $current_status): void
    {
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
            self::apply_domain($commerce, $subscription_id, $domains[$external_id] ?? '');
        }
    }

    /**
     * The same for one store, when the API's webhook says its domains changed: a live store takes the API's
     * address, and a store still on its way to Ready gets the poller's next step (its domain may now match).
     */
    public function sync_tenant_domain($subscription_id): void
    {
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return;
        }

        $state = (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_STATE);
        if (in_array($state, self::IN_PROGRESS, true)) {
            $this->process_subscription($subscription_id);
            return;
        }
        if ($state !== Tenant::READY && $state !== Tenant::SUSPENDED) {
            return;
        }

        try {
            $tenant = $this->provisioningApi->get_tenant_safe($commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META));
        } catch (\Exception $e) {
            return;
        }

        self::apply_domain($commerce, $subscription_id, isset($tenant->domainName) ? strtolower((string) $tenant->domainName) : '');
    }

    private static function apply_domain(CommerceAdapter $commerce, $subscription_id, string $domain): void
    {
        if ($domain !== '' && $domain !== (string) $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META)) {
            $commerce->update_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META, $domain);
        }
    }
}
