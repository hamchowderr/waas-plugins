<?php

namespace Tests\Unit\Core;

use Brain\Monkey\Functions;
use Mockery;
use WaaSHost\Core\Exceptions\InvalidDomainException;
use Tests\Unit\TestCase;
use WaaSHost\Core\HttpService;
use WaaSHost\Core\ProvisioningApi;

class ProvisioningApiTest extends TestCase
{
    private $http;
    private ProvisioningApi $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = Mockery::mock(HttpService::class);
        $this->service = new ProvisioningApi($this->http);
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

    public function test_restore_tenant_posts_the_backup_id_by_external_id(): void
    {
        $this->http->expects('post')
            ->with('/v1/tenants/restore?externalId=ext-123', ['backupId' => 'bk-1'])
            ->andReturn((object) ['externalId' => 'ext-123', 'backupId' => 'bk-1']);

        $this->service->restore_tenant('ext-123', 'bk-1');
    }

    public function test_tenant_backups_are_listed_and_created_by_external_id(): void
    {
        $this->http->expects('get')->with('/v1/tenants/backups?externalId=ext-123')->andReturn([(object) ['id' => 'bk-1']]);
        $this->http->expects('post')->with('/v1/tenants/backups?externalId=ext-123', [])->andReturn((object) ['backupId' => 'bk-2']);

        $this->assertSame('bk-1', $this->service->get_tenant_backups('ext-123')[0]->id);
        $this->assertSame('bk-2', $this->service->create_tenant_backup('ext-123')->backupId);
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

    public function test_create_tenant_sends_the_store_settings_as_wp_options(): void
    {
        $this->http->expects('post')
            ->with('/v1/tenants', Mockery::on(fn ($payload) => $payload['wpOptions'] === ['blogname' => 'Shop', 'admin_email' => 'owner@example.com']))
            ->andReturn((object) ['externalId' => 'ext-1']);

        $this->service->create_tenant([
            'name' => 'Shop',
            'wordpress_username' => 'owner',
            'wordpress_email' => 'owner@example.com',
            'wordpress_user_role' => 'administrator',
            'wp_options' => ['blogname' => 'Shop', 'admin_email' => 'owner@example.com'],
        ]);
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

    private function availability_response(int $code, string $body): void
    {
        $this->http->expects('getraw')->with('/v1/tenants/domains/available?domain=apex.stores.example')
            ->andReturn(['response' => ['code' => $code], 'body' => $body]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn ($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn ($r) => $r['body']);
    }

    public function test_domain_available_reads_a_200_answer(): void
    {
        $this->availability_response(200, '{"available":false}');

        $this->assertFalse($this->service->domain_available('apex.stores.example'));
    }

    public function test_domain_available_turns_a_400_into_an_invalid_domain(): void
    {
        $this->availability_response(400, '{"statusCode":400,"message":"bad"}');
        $this->expectException(InvalidDomainException::class);

        $this->service->domain_available('apex.stores.example');
    }

    /** @dataProvider broken_answers */
    public function test_domain_available_treats_any_other_answer_as_an_error_not_as_taken(int $code, string $body): void
    {
        // Regression for M6: these used to read ->available off the error body (null: "taken", plus a warning).
        $this->availability_response($code, $body);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('HTTP ' . $code);

        $this->service->domain_available('apex.stores.example');
    }

    public function broken_answers(): array
    {
        return [
            'bad credentials' => [403, ''],
            'unauthorised' => [401, '{"message":"no"}'],
            'server error' => [500, '<html>Bad gateway</html>'],
            '200 without an answer' => [200, '{"statusCode":1}'],
            '200 with a string' => [200, '{"available":"yes"}'],
        ];
    }

    public function test_group_names_for_admin_screens_are_cached(): void
    {
        Functions\when('get_transient')->justReturn(['peptide-starter']);
        $this->http->expects('get')->never();

        $this->assertSame(['peptide-starter'], $this->service->get_available_groupnames_cached());
    }

    public function test_group_names_for_admin_screens_are_loaded_once_then_cached_for_ten_minutes(): void
    {
        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }
        Functions\when('get_transient')->justReturn(false);
        $this->http->expects('get')->andReturn([(object) ['groupName' => 'peptide-starter']]);
        Functions\expect('set_transient')->once()->with(ProvisioningApi::GROUP_NAMES_TRANSIENT, ['peptide-starter'], 600);

        $this->assertSame(['peptide-starter'], $this->service->get_available_groupnames_cached());
    }

    public function test_group_names_for_admin_screens_are_empty_when_the_api_is_down(): void
    {
        // Regression for H11: the product editor called the API directly and the exception went uncaught.
        Functions\when('get_transient')->justReturn(false);
        $this->http->expects('get')->andThrow(new \Exception('Failed: cURL error 7'));
        Functions\expect('set_transient')->never();

        $this->assertSame([], $this->service->get_available_groupnames_cached());
    }
}
