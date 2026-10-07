<?php

namespace Tests\Unit\Core;

use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Core\HttpService;
use WaaSHost\Core\WPCSService;

class WPCSServiceTest extends TestCase
{
    private $http;
    private WPCSService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = Mockery::mock(HttpService::class);
        $this->service = new WPCSService($this->http);
    }

    public function test_delete_tenant_targets_the_tenant_by_external_id(): void
    {
        // Regression for waas-91g.8: this used to send ?tenantId={external id}.
        $this->http->expects('delete')
            ->with('/v1/tenants?externalId=ext-123')
            ->andReturn(null);

        $this->service->delete_tenant(['external_id' => 'ext-123']);
    }

    public function test_suspend_tenant_asks_for_a_backup_by_default(): void
    {
        $this->http->expects('post')
            ->with('/v1/tenants/suspend?externalId=ext-123', ['takeBackup' => true])
            ->andReturn((object) ['externalId' => 'ext-123', 'backupId' => 'bk-1']);

        $result = $this->service->suspend_tenant('ext-123');

        $this->assertSame('bk-1', $result->backupId);
    }

    public function test_resume_tenant_posts_an_empty_body_by_external_id(): void
    {
        $this->http->expects('post')
            ->with('/v1/tenants/resume?externalId=ext-123', [])
            ->andReturn((object) ['externalId' => 'ext-123']);

        $this->service->resume_tenant('ext-123');
    }

    public function test_update_tenant_puts_filtered_payload_by_external_id(): void
    {
        $this->http->expects('put')
            ->with('/v1/tenants?externalId=ext-123', [
                'phpConstants' => ['FOO' => 'bar'],
                'wpOptions' => ['blogname' => 'Shop'],
            ])
            ->andReturn((object) ['ok' => true]);

        $result = $this->service->update_tenant('ext-123', [
            'php_constants' => ['FOO' => 'bar'],
            'wp_options' => ['blogname' => 'Shop'],
        ]);

        $this->assertEquals((object) ['ok' => true], $result);
    }

    public function test_create_tenant_maps_required_fields_and_skips_unset_optional_ones(): void
    {
        $this->http->expects('post')
            ->with('/v1/tenants', [
                'name' => 'Shop',
                'wordPressUserName' => 'owner',
                'wordPressUserEmail' => 'owner@example.com',
                'wordPressUserRole' => 'administrator',
                'groupName' => 'peptides',
            ])
            ->andReturn((object) ['externalId' => 'ext-1']);

        $tenant = $this->service->create_tenant([
            'name' => 'Shop',
            'wordpress_username' => 'owner',
            'wordpress_email' => 'owner@example.com',
            'wordpress_user_role' => 'administrator',
            'group_name' => 'peptides',
        ]);

        $this->assertSame('ext-1', $tenant->externalId);
    }

    public function test_add_tenant_domain_sets_it_as_main_domain(): void
    {
        $this->http->expects('post')
            ->with('/v1/tenants/domains?externalId=ext-123', [
                'setAsMainDomain' => true,
                'domainName' => 'shop.example.com',
            ]);

        $this->service->add_tenant_domain([
            'external_id' => 'ext-123',
            'domain_name' => 'shop.example.com',
        ]);
    }

    public function test_delete_tenant_domain_targets_external_id_and_domain(): void
    {
        $this->http->expects('delete')
            ->with('/v1/tenants/domains?externalId=ext-123&domainName=old.example.com');

        $this->service->delete_tenant_domain([
            'external_id' => 'ext-123',
            'old_domain_name' => 'old.example.com',
        ]);
    }

    public function test_get_tenant_safe_returns_first_match(): void
    {
        $tenant = (object) ['externalId' => 'ext-123'];
        $this->http->expects('get')
            ->with('/v1/tenants?externalId=ext-123')
            ->andReturn([$tenant]);

        $this->assertSame($tenant, $this->service->get_tenant_safe('ext-123'));
    }

    public function test_get_tenant_safe_returns_null_when_no_tenant_matches(): void
    {
        $this->http->expects('get')->andReturn([]);

        $this->assertNull($this->service->get_tenant_safe('missing'));
    }

    public function test_get_production_version_returns_the_production_version(): void
    {
        $production = (object) ['id' => 'v2', 'isProduction' => true];
        $this->http->expects('get')
            ->with('/v1/versions')
            ->andReturn([(object) ['id' => 'v1', 'isProduction' => false], $production]);

        $this->assertSame($production, $this->service->get_production_version());
    }

    public function test_get_production_version_throws_when_none_is_production(): void
    {
        $this->http->expects('get')->andReturn([(object) ['isProduction' => false]]);

        $this->expectException(\Exception::class);
        $this->service->get_production_version();
    }

    public function test_get_available_groupnames_dedupes_and_skips_empty_names(): void
    {
        $this->http->expects('get')
            ->with('/v1/snapshots?onlyProductionVersion=true')
            ->andReturn([
                (object) ['groupName' => 'peptides'],
                (object) ['groupName' => ''],
                (object) [],
                (object) ['groupName' => 'peptides'],
                (object) ['groupName' => 'supplements'],
            ]);

        $this->assertSame(['peptides', 'supplements'], $this->service->get_available_groupnames());
    }
}
