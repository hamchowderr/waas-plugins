<?php

namespace Tests\Contract;

use Tests\Unit\TestCase;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\Exceptions\InvalidApiKeyException;
use WaaSHost\Core\Exceptions\InvalidDomainException;
use WaaSHost\Core\HttpService;
use WaaSHost\Core\ProvisioningApi;

/**
 * Runs the plugin's real API client against the provisioning API (see MockApi).
 *
 * @group mock
 */
class ProvisioningApiContractTest extends TestCase
{
    use MockApi;

    private ProvisioningApi $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->use_mock_api();
        $this->service = new ProvisioningApi(new HttpService(new ConfigService()));
    }

    public function test_connection_test_succeeds(): void
    {
        $this->mock_example('List versions: Production v1.0.0 and a release candidate');

        $this->assertTrue($this->service->test_reachability());
    }

    public function test_connection_test_reports_bad_credentials_on_403(): void
    {
        $this->mock_example('List versions: 403 Invalid API key or secret');

        $this->expectException(InvalidApiKeyException::class);
        $this->service->test_reachability();
    }

    public function test_finds_the_production_version(): void
    {
        $this->mock_example('List versions: Production v1.0.0 and a release candidate');

        $version = $this->service->get_production_version();

        $this->assertSame('v1-0-0.versions.waas.example', $version->domain);
    }

    public function test_lists_template_groups_from_production_snapshots(): void
    {
        $this->mock_example('List snapshots: Two store templates on production');

        $this->assertSame(['peptide-starter', 'peptide-pro'], $this->service->get_available_groupnames());
        $this->assertStringEndsWith('/v1/snapshots?onlyProductionVersion=true', $this->requests[0][1]);
    }

    public function test_create_tenant_returns_generated_external_id_and_domains(): void
    {
        $this->mock_example('Create tenant: Scheduled (API generated the externalId)');

        $tenant = $this->service->create_tenant([
            'name' => 'Apex Research',
            'wordpress_username' => 'jordan_reyes',
            'wordpress_email' => 'owner@apexresearch.example',
            'wordpress_user_role' => 'administrator',
            'custom_domain_name' => 'apex-research.stores.waas.example',
            'group_name' => 'peptide-starter',
            'php_constants' => [
                'WAAS_TENANT_ROLES' => ['value' => 'peptide-store', 'isPrivate' => false],
            ],
        ]);

        $this->assertSame('store-1042', $tenant->externalId);
        // TenantsSubscriptionManager waits for customDomain ?? baseDomain.
        $this->assertSame('apex-research.stores.waas.example', $tenant->customDomain ?? $tenant->baseDomain);
        $this->assertArrayNotHasKey('externalId', json_decode($this->requests[0][3], true));
    }

    public function test_tenant_status_while_provisioning_and_when_ready(): void
    {
        $this->mock_example('List tenants: Still provisioning (statusCode 0)');
        $this->assertSame(0, $this->service->get_tenant_safe('store-1042')->statusCode);

        $this->mock_example('List tenants: Ready (statusCode 1, domain linked)');
        $tenant = $this->service->get_tenant_safe('store-1042');
        $this->assertSame(1, $tenant->statusCode);
        $this->assertSame('apex-research.stores.waas.example', $tenant->domainName);
    }

    public function test_unknown_tenant_is_null(): void
    {
        $this->mock_example('List tenants: No such tenant (empty list)');

        $this->assertNull($this->service->get_tenant_safe('nope'));
    }

    public function test_update_tenant_roles(): void
    {
        $this->mock_example('Update tenant options and constants: Scheduled (roles updated)');

        $result = $this->service->update_tenant('store-1042', [
            'php_constants' => ['WAAS_TENANT_ROLES' => ['value' => 'peptide-store,peptide-coa-addon', 'isPrivate' => false]],
        ]);

        $this->assertSame('store-1042', $result->externalId);
    }

    public function test_domain_availability(): void
    {
        $this->mock_example('Check domain availability: Available');
        $this->assertTrue($this->service->domain_available('apex-research.stores.waas.example'));

        $this->mock_example('Check domain availability: Taken by another store');
        $this->assertFalse($this->service->domain_available('apex-research.stores.waas.example'));
    }

    public function test_invalid_domain_is_reported(): void
    {
        $this->mock_example('Check domain availability: 400 Invalid domain');

        $this->expectException(InvalidDomainException::class);
        $this->service->domain_available('bad_domain!');
    }

    public function test_custom_domain_add_and_old_domain_removal(): void
    {
        $this->mock_example("Add tenant domain: Scheduled (customer's own domain)");
        $this->service->add_tenant_domain(['external_id' => 'store-1042', 'domain_name' => 'apexresearchlabs.example']);

        $this->mock_example('Remove tenant domain: Scheduled (old subdomain removed)');
        $this->service->delete_tenant_domain(['external_id' => 'store-1042', 'old_domain_name' => 'apex-research.stores.waas.example']);

        $this->assertSame(['POST', 'DELETE'], array_column($this->requests, 0));
    }

    public function test_delete_tenant_by_external_id(): void
    {
        $this->mock_example('Delete tenant: Scheduled');

        $this->service->delete_tenant(['external_id' => 'store-1042']);

        $this->assertSame('DELETE', $this->requests[0][0]);
        $this->assertStringEndsWith('/v1/tenants?externalId=store-1042', $this->requests[0][1]);
    }
}
