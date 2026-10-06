<?php

namespace Tests\Unit\Core;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\HttpService;

class ConfigServiceTest extends TestCase
{
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];
        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('untrailingslashit')->alias(fn ($value) => rtrim($value, '/\\'));
    }

    public function test_base_url_setting_is_used_without_trailing_slash(): void
    {
        $this->options['wpcs_credentials_api_base_url_setting'] = 'https://api.example.com/';

        $this->assertSame('https://api.example.com', (new ConfigService())->get_api_base_url());
    }

    public function test_base_url_setting_overrides_region(): void
    {
        $this->options['wpcs_credentials_api_base_url_setting'] = 'https://api.example.com';
        $this->options['wpcs_credentials_region_setting'] = 'eu1';

        $this->assertSame('https://api.example.com', (new ConfigService())->get_api_base_url());
    }

    public function test_falls_back_to_region_host_when_no_base_url(): void
    {
        $this->options['wpcs_credentials_region_setting'] = 'us1';

        $this->assertSame('https://api.us1.wpcs.io', (new ConfigService())->get_api_base_url());
    }

    public function test_empty_base_url_setting_falls_back_to_region(): void
    {
        $this->options['wpcs_credentials_api_base_url_setting'] = '';
        $this->options['wpcs_credentials_region_setting'] = 'eu1';

        $this->assertSame('https://api.eu1.wpcs.io', (new ConfigService())->get_api_base_url());
    }

    public function test_false_when_neither_base_url_nor_region(): void
    {
        $this->assertFalse((new ConfigService())->get_api_base_url());
    }

    public function test_filter_can_change_base_url(): void
    {
        $this->options['wpcs_credentials_api_base_url_setting'] = 'https://api.example.com';
        Filters\expectApplied('wpcs_settings_api_base_url')->once()->andReturn('http://localhost:4111/');

        $this->assertSame('http://localhost:4111', (new ConfigService())->get_api_base_url());
    }

    public function test_credentials_incomplete_without_base_url_or_region(): void
    {
        $this->options['wpcs_credentials_api_key_setting'] = 'key';
        $this->options['wpcs_credentials_api_secret_setting'] = 'secret';

        $this->assertFalse((new ConfigService())->check_credentials());
    }

    public function test_credentials_complete_with_base_url_only(): void
    {
        $this->options['wpcs_credentials_api_base_url_setting'] = 'https://api.example.com';
        $this->options['wpcs_credentials_api_key_setting'] = 'key';
        $this->options['wpcs_credentials_api_secret_setting'] = 'secret';

        $this->assertTrue((new ConfigService())->check_credentials());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_constant_wins_over_setting(): void
    {
        define('WPCS_API_BASE_URL', 'https://api.from-constant.example');
        $this->options['wpcs_credentials_api_base_url_setting'] = 'https://api.example.com';

        $this->assertSame('https://api.from-constant.example', (new ConfigService())->get_api_base_url());
    }

    public function test_http_service_sends_requests_to_base_url(): void
    {
        $this->options['wpcs_credentials_api_base_url_setting'] = 'https://api.example.com/';
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\expect('wp_remote_get')
            ->once()
            ->with('https://api.example.com/v1/versions', Mockery::type('array'))
            ->andReturn(['response' => ['code' => 200], 'body' => '[]']);

        $this->assertSame([], (new HttpService(new ConfigService()))->get('/v1/versions'));
    }
}
