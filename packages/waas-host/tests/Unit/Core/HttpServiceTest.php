<?php

namespace Tests\Unit\Core;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\HttpService;

class HttpServiceTest extends TestCase
{
    private HttpService $http;

    protected function setUp(): void
    {
        parent::setUp();

        $config = Mockery::mock(ConfigService::class);
        $config->allows('get_api_base_url')->andReturn('https://api.eu1.wpcs.io');
        $config->allows('get_api_key')->andReturn('key');
        $config->allows('get_api_secret')->andReturn('secret');
        $this->http = new HttpService($config);

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn ($response) => $response['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn ($response) => $response['body']);
    }

    public function test_delete_sends_a_delete_request_with_basic_auth_to_the_region_api(): void
    {
        Functions\expect('wp_remote_get')
            ->once()
            ->with('https://api.eu1.wpcs.io/v1/tenants?externalId=ext-123', Mockery::on(function ($args) {
                return $args['method'] === 'DELETE'
                    && $args['headers']['Authorization'] === 'Basic ' . base64_encode('key:secret');
            }))
            ->andReturn(['response' => ['code' => 200], 'body' => '{"deleted":true}']);

        $result = $this->http->delete('/v1/tenants?externalId=ext-123');

        $this->assertTrue($result->deleted);
    }

    public function test_post_sends_json_body(): void
    {
        Functions\expect('wp_remote_post')
            ->once()
            ->with('https://api.eu1.wpcs.io/v1/tenants', Mockery::on(function ($args) {
                return $args['method'] === 'POST' && $args['body'] === '{"name":"Shop"}';
            }))
            ->andReturn(['response' => ['code' => 201], 'body' => '{"externalId":"ext-1"}']);

        $this->assertSame('ext-1', $this->http->post('/v1/tenants', ['name' => 'Shop'])->externalId);
    }

    public function test_non_2xx_response_throws_with_status_and_body(): void
    {
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 404], 'body' => 'not found']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed: 404 - not found');
        $this->http->get('/v1/tenants?externalId=missing');
    }

    public function test_transport_error_throws(): void
    {
        $error = Mockery::mock();
        $error->allows('get_error_message')->andReturn('timed out');
        Functions\when('wp_remote_get')->justReturn($error);
        Functions\when('is_wp_error')->justReturn(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed: timed out');
        $this->http->get('/v1/versions');
    }
}
