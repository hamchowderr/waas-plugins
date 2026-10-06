<?php

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\HttpService;
use WpStub;

class ConfigServiceTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
    }

    public function test_base_url_setting_is_used_without_trailing_slash()
    {
        WpStub::$options['wpcs_credentials_api_base_url_setting'] = 'https://api.example.com/';

        $this->assertSame('https://api.example.com', (new ConfigService())->get_api_base_url());
    }

    public function test_base_url_setting_overrides_region()
    {
        WpStub::$options['wpcs_credentials_api_base_url_setting'] = 'https://api.example.com';
        WpStub::$options['wpcs_credentials_region_setting'] = 'eu1';

        $this->assertSame('https://api.example.com', (new ConfigService())->get_api_base_url());
    }

    public function test_falls_back_to_region_host_when_no_base_url()
    {
        WpStub::$options['wpcs_credentials_region_setting'] = 'us1';

        $this->assertSame('https://api.us1.wpcs.io', (new ConfigService())->get_api_base_url());
    }

    public function test_empty_base_url_setting_falls_back_to_region()
    {
        WpStub::$options['wpcs_credentials_api_base_url_setting'] = '';
        WpStub::$options['wpcs_credentials_region_setting'] = 'eu1';

        $this->assertSame('https://api.eu1.wpcs.io', (new ConfigService())->get_api_base_url());
    }

    public function test_false_when_neither_base_url_nor_region()
    {
        $this->assertFalse((new ConfigService())->get_api_base_url());
    }

    public function test_filter_can_change_base_url()
    {
        WpStub::$options['wpcs_credentials_api_base_url_setting'] = 'https://api.example.com';
        WpStub::$filters['wpcs_settings_api_base_url'][] = fn ($url) => 'http://localhost:4111/';

        $this->assertSame('http://localhost:4111', (new ConfigService())->get_api_base_url());
    }

    public function test_credentials_incomplete_without_base_url_or_region()
    {
        WpStub::$options['wpcs_credentials_api_key_setting'] = 'key';
        WpStub::$options['wpcs_credentials_api_secret_setting'] = 'secret';

        $this->assertFalse((new ConfigService())->check_credentials());
    }

    public function test_credentials_complete_with_base_url_only()
    {
        WpStub::$options['wpcs_credentials_api_base_url_setting'] = 'https://api.example.com';
        WpStub::$options['wpcs_credentials_api_key_setting'] = 'key';
        WpStub::$options['wpcs_credentials_api_secret_setting'] = 'secret';

        $this->assertTrue((new ConfigService())->check_credentials());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_constant_wins_over_setting()
    {
        define('WPCS_API_BASE_URL', 'https://api.from-constant.example');
        WpStub::$options['wpcs_credentials_api_base_url_setting'] = 'https://api.example.com';

        $this->assertSame('https://api.from-constant.example', (new ConfigService())->get_api_base_url());
    }

    public function test_http_service_sends_requests_to_base_url()
    {
        WpStub::$options['wpcs_credentials_api_base_url_setting'] = 'http://127.0.0.1:9/';

        try {
            (new HttpService(new ConfigService()))->get('/v1/versions');
        } catch (\Exception $e) {
            // Nothing listens on port 9; only the URL matters here.
        }

        $this->assertSame('http://127.0.0.1:9/v1/versions', WpStub::$requests[0][1]);
    }
}
