<?php

namespace WaaSHost\Core;

use WaaSHost\Core\Exceptions\InvalidApiKeyException;
use WaaSHost\Core\Exceptions\InvalidDomainException;


class ProvisioningApi
{
    const CAN_REACH_API_OPTION_NAME = 'waas_can_reach_api';
    const GROUP_NAMES_TRANSIENT = 'waas_available_group_names';

    private HttpService $httpService;

    public function __construct(HttpService $httpService)
    {
        $this->httpService = $httpService;
    }

    public function is_reachable()
    {
        $can_reach = get_option(self::CAN_REACH_API_OPTION_NAME, false);
        if($can_reach)
        {
            return true;
        }

        // Don't assume that if somebody uses the API constants, they are correct.
        if (ConfigService::has_constant('API_KEY') && ConfigService::has_constant('API_SECRET') && ConfigService::has_constant('API_BASE_URL'))
        {
            try {
                $can_reach = $this->test_reachability();
                $this->update_is_reachable($can_reach);
            } catch (\Throwable $th) {
                $this->update_is_reachable(false);
            }
        }

        return $can_reach;
    }

    public function update_is_reachable($is_reachable)
    {
        update_option(self::CAN_REACH_API_OPTION_NAME, $is_reachable);
    }

    public function test_reachability() {
        $response = $this->httpService->getraw('/v1/versions');
        if (is_wp_error($response)) {
            throw new \Exception("Something went wrong connecting to the API");
        }

        if (wp_remote_retrieve_response_code($response) == 200) {
            return true;
        }

        if (wp_remote_retrieve_response_code($response) == 403) {
            throw new InvalidApiKeyException("API Key/Secret are not valid");
        }

        return false;
    }

    /**
     * @throws \Exception
     */
    public function get_production_version()
    {
        $versions = $this->httpService->get('/v1/versions');

        foreach ($versions as $version) {
            if ($version->isProduction === true) {
                return $version;
            }
        }

        throw new \Exception('Failed to find any version');
    }

    /**
     * @throws \Exception
     */
    /**
     * get_available_groupnames() for admin screens: cached for 10 minutes, and an empty list when the API can't be
     * reached, so a product editor still opens.
     */
    public function get_available_groupnames_cached(): array
    {
        $cached = get_transient(self::GROUP_NAMES_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $group_names = $this->get_available_groupnames();
        } catch (\Throwable $e) {
            error_log('WaaS Host: could not load snapshot groups: ' . $e->getMessage());
            return [];
        }

        set_transient(self::GROUP_NAMES_TRANSIENT, $group_names, 10 * MINUTE_IN_SECONDS);

        return $group_names;
    }

    public function get_available_groupnames()
    {
        $snapshots = $this->httpService->get('/v1/snapshots?onlyProductionVersion=true');
        $a = array();
        foreach ($snapshots as $snapshot) {
            if (isset($snapshot->groupName) && !empty($snapshot->groupName) && !in_array($snapshot->groupName, $a, true)) {
                array_push($a, $snapshot->groupName);
            }
        }

        return $a;
    }

    public function create_tenant($args)
    {
        $payload = [
            'name' => $args['name'],
            'wordPressUserName' => $args['wordpress_username'],
            'wordPressUserEmail' => $args['wordpress_email'],
            'wordPressUserRole' => $args['wordpress_user_role'],
        ];

        if (isset($args['external_id'])) {
            $payload['externalId'] = $args['external_id'];
        }

        if (isset($args['wordpress_password'])) {
            $payload['wordPressUserPassword'] = $args['wordpress_password'];
        }

        if (isset($args['custom_domain_name'])) {
            $payload['customDomainName'] = $args['custom_domain_name'];
        }

        if (isset($args['group_name'])) {
            $payload['groupName'] = $args['group_name'];
        }

        if (isset($args['size'])) {
            $payload['size'] = $args['size'];
        }

        if (isset($args['php_constants'])) {
            $payload['phpConstants'] = Tenant::with_legacy_constants($args['php_constants']);
        }

        if (!empty($args['wp_options'])) {
            $payload['wpOptions'] = $args['wp_options'];
        }

        $filtered_payload = apply_filters('waas_tenant_create_payload', $payload);

        return $this->httpService->post('/v1/tenants', $filtered_payload);
    }

    public function update_tenant($external_id, $args)
    {
        $payload = [];

        if (isset($args['php_constants'])) {
            $payload['phpConstants'] = Tenant::with_legacy_constants($args['php_constants']);
        }

        if (isset($args['wp_options'])) {
            $payload['wpOptions'] = $args['wp_options'];
        }

        $filtered_payload = apply_filters('waas_tenant_update_payload', $payload, $external_id);

        return $this->httpService->put('/v1/tenants?externalId=' . $external_id, $filtered_payload);
    }

    public function delete_tenant($args)
    {
        return $this->httpService->delete('/v1/tenants?externalId=' . $args['external_id']);
    }

    /**
     * Take the store offline, keeping its data. With $take_backup the API backs it up first.
     */
    public function suspend_tenant($external_id, bool $take_backup = true)
    {
        return $this->httpService->post('/v1/tenants/suspend?externalId=' . $external_id, [
            'takeBackup' => $take_backup,
        ]);
    }

    public function resume_tenant($external_id)
    {
        return $this->httpService->post('/v1/tenants/resume?externalId=' . $external_id, []);
    }

    public function create_tenant_backup($external_id)
    {
        return $this->httpService->post('/v1/tenants/backups?externalId=' . $external_id, []);
    }

    /** The tenant's backups, newest first, also after the tenant was deleted. */
    public function get_tenant_backups($external_id): array
    {
        return (array) $this->httpService->get('/v1/tenants/backups?externalId=' . $external_id);
    }

    /** Re-create a deleted tenant, with the same externalId and domains, from one of its backups. */
    public function restore_tenant($external_id, string $backup_id)
    {
        return $this->httpService->post('/v1/tenants/restore?externalId=' . $external_id, [
            'backupId' => $backup_id,
        ]);
    }

    /**
     * Adds an email pack to the store's allowance: $emails more emails, kept until used. $reference makes the call
     * idempotent (the same reference adds the pack once), so a retry is safe. The answer is
     * {tenantId, externalId, emailPacks}: emailPacks is the store's total bought in packs.
     *
     * @throws \Exception
     */
    public function add_email_packs($external_id, int $emails, string $reference)
    {
        return $this->httpService->post('/v1/tenants/email-packs?externalId=' . rawurlencode((string) $external_id), [
            'emails' => $emails,
            'reference' => $reference,
        ]);
    }

    public function add_tenant_domain($args)
    {
        $this->httpService->post('/v1/tenants/domains?externalId=' . $args['external_id'], [
            'setAsMainDomain' => true,
            'domainName' => $args['domain_name'],
        ]);
    }

    public function delete_tenant_domain($args)
    {
        $url = '/v1/tenants/domains?externalId=' . $args['external_id'] . "&domainName=" . $args['old_domain_name'];
        $this->httpService->delete($url);
    }

    /**
     * The store's own sending domain (status, DNS records), or null when it sends from the platform's address.
     *
     * @throws \Exception
     */
    public function get_mail_domain($external_id)
    {
        $response = $this->httpService->get('/v1/tenants/mail-domain?externalId=' . rawurlencode($external_id));
        return $response->mailDomain ?? null;
    }

    /**
     * Gives the store its own sending domain; it sends from it once the DNS records are verified.
     *
     * @throws \Exception
     */
    public function set_mail_domain($external_id, $domain_name)
    {
        $response = $this->httpService->put('/v1/tenants/mail-domain?externalId=' . rawurlencode($external_id), [
            'domainName' => $domain_name,
        ]);
        return $response->mailDomain ?? null;
    }

    /**
     * Moves the store back to the platform's address.
     *
     * @throws \Exception
     */
    public function remove_mail_domain($external_id)
    {
        $this->httpService->delete('/v1/tenants/mail-domain?externalId=' . rawurlencode($external_id));
    }

    /**
     * A Domain Connect link that adds the records for one of the store's domains at its DNS host
     * ({dnsProvider, supported, url}); url is null when the host can't.
     *
     * @param string $service website (the store's domain) or email (its sending domain)
     * @throws \Exception
     */
    public function domain_connect($external_id, $domain_name, $service)
    {
        return $this->httpService->get('/v1/tenants/domain-connect?externalId=' . rawurlencode($external_id)
            . '&domainName=' . rawurlencode($domain_name) . '&service=' . rawurlencode($service));
    }

    public function domain_available($domain)
    {
        $url = '/v1/tenants/domains/available?domain=' . $domain;
        $response = $this->httpService->getraw($url);
        if (is_wp_error($response)) {
            throw new \Exception("Something went wrong connecting to the API");
        }


        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 400) {
            throw new InvalidDomainException("Domain was not valid!");
        }

        // Anything but a 200 with a boolean answer (401/403 credentials, 5xx, a proxy page) is an error, not "taken".
        $body = json_decode((string) wp_remote_retrieve_body($response));
        if ($code !== 200 || !is_object($body) || !isset($body->available) || !is_bool($body->available)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not printed here; every place that shows the message escapes it.
            throw new \Exception('Unexpected answer from the domain availability check: HTTP ' . $code);
        }

        return $body->available;
    }

    /**
     * Registers a webhook endpoint for this storefront. The answer carries the signing secret (whsec_…), the only
     * time the API shows it. The API refuses (400) a URL that isn't https:// on a public address.
     *
     * @param string[] $event_types
     * @throws \Exception
     */
    public function create_webhook_endpoint(string $url, array $event_types, string $description = '')
    {
        return $this->httpService->post('/v1/webhooks', [
            'url' => $url,
            'eventTypes' => array_values($event_types),
            'description' => $description,
        ]);
    }

    /**
     * The storefront's webhook endpoints, without their secrets.
     *
     * @throws \Exception
     */
    public function get_webhook_endpoints(): array
    {
        $endpoints = $this->httpService->get('/v1/webhooks');
        return is_array($endpoints) ? $endpoints : [];
    }

    /**
     * @throws \Exception
     */
    public function delete_webhook_endpoint(string $id)
    {
        return $this->httpService->delete('/v1/webhooks/' . rawurlencode($id));
    }

    /**
     * Every store of this storefront, as the API has them.
     *
     * @throws \Exception
     */
    public function get_tenants(): array
    {
        $tenants = $this->httpService->get('/v1/tenants');
        return is_array($tenants) ? $tenants : [];
    }

    /**
     * @throws \Exception
     * 
     */
    public function get_tenant_safe($external_id)
    {
        $tenant_array = $this->httpService->get('/v1/tenants?externalId=' . $external_id);
        $tenant = reset($tenant_array);
        return $tenant !== false ? $tenant : null;
    }
}
