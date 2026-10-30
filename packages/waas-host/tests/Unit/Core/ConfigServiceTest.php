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
        $this->options['waas_api_base_url'] = 'https://api.example.com/';

        $this->assertSame('https://api.example.com', (new ConfigService())->get_api_base_url());
    }

    /** A leftover region setting from older installs is ignored: the API URL setting decides where calls go. */
    public function test_a_leftover_region_setting_is_ignored(): void
    {
        $this->options['waas_api_base_url'] = '';
        $this->options['waas_region'] = 'eu1';

        $this->assertFalse((new ConfigService())->get_api_base_url());
    }

    public function test_waas_constants_win_and_wpcs_constants_still_work(): void
    {
        define('WPCS_CONFIG_TEST_OLD_ONLY', 'old');
        define('WPCS_CONFIG_TEST_BOTH', 'old');
        define('WAAS_CONFIG_TEST_BOTH', 'new');

        $this->assertSame('old', ConfigService::constant('CONFIG_TEST_OLD_ONLY'));
        $this->assertSame('new', ConfigService::constant('CONFIG_TEST_BOTH'));
        $this->assertTrue(ConfigService::has_constant('CONFIG_TEST_OLD_ONLY'));
        $this->assertFalse(ConfigService::has_constant('CONFIG_TEST_NEITHER'));
        $this->assertNull(ConfigService::constant('CONFIG_TEST_NEITHER'));
    }

    public function test_false_without_a_base_url(): void
    {
        $this->assertFalse((new ConfigService())->get_api_base_url());
    }

    public function test_filter_can_change_base_url(): void
    {
        $this->options['waas_api_base_url'] = 'https://api.example.com';
        Filters\expectApplied('waas_settings_api_base_url')->once()->andReturn('http://localhost:4111/');

        $this->assertSame('http://localhost:4111', (new ConfigService())->get_api_base_url());
    }

    public function test_credentials_incomplete_without_base_url(): void
    {
        $this->options['waas_api_key'] = 'key';
        $this->options['waas_api_secret'] = 'secret';

        $this->assertFalse((new ConfigService())->check_credentials());
    }

    public function test_credentials_complete_with_base_url_only(): void
    {
        $this->options['waas_api_base_url'] = 'https://api.example.com';
        $this->options['waas_api_key'] = 'key';
        $this->options['waas_api_secret'] = 'secret';

        $this->assertTrue((new ConfigService())->check_credentials());
    }

    public function test_credentials_incomplete_when_saved_empty(): void
    {
        $this->options['waas_api_base_url'] = 'https://api.example.com';
        $this->options['waas_api_key'] = '';
        $this->options['waas_api_secret'] = '';

        $this->assertFalse((new ConfigService())->check_credentials());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_constant_wins_over_setting(): void
    {
        define('WPCS_API_BASE_URL', 'https://api.from-constant.example');
        $this->options['waas_api_base_url'] = 'https://api.example.com';

        $this->assertSame('https://api.from-constant.example', (new ConfigService())->get_api_base_url());
    }

    public function test_http_service_sends_requests_to_base_url(): void
    {
        $this->options['waas_api_base_url'] = 'https://api.example.com/';
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\expect('wp_remote_get')
            ->once()
            ->with('https://api.example.com/v1/versions', Mockery::type('array'))
            ->andReturn(['response' => ['code' => 200], 'body' => '[]']);

        $this->assertSame([], (new HttpService(new ConfigService()))->get('/v1/versions'));
    }

    public function test_dns_a_records_from_setting_drop_invalid_and_duplicate_ips(): void
    {
        $this->options['waas_dns_a_records'] = "203.0.113.10, 203.0.113.11\n203.0.113.10 not-an-ip 2001:db8::1";

        $this->assertSame(['203.0.113.10', '203.0.113.11', '2001:db8::1'], (new ConfigService())->get_dns_a_records());
    }

    public function test_no_dns_records_by_default(): void
    {
        $this->options['waas_region'] = 'us1';
        $config = new ConfigService();

        $this->assertSame([], $config->get_dns_a_records());
        $this->assertSame('', $config->get_dns_cname_target());
    }

    public function test_dns_cname_target_is_normalized_and_validated(): void
    {
        $this->options['waas_dns_cname_target'] = ' Sites.Example.NET. ';
        $this->assertSame('sites.example.net', (new ConfigService())->get_dns_cname_target());

        $this->options['waas_dns_cname_target'] = 'https://sites.example.net';
        $this->assertSame('', (new ConfigService())->get_dns_cname_target());
    }

    public function test_sanitize_ip_list_stores_a_clean_comma_list(): void
    {
        $this->assertSame('203.0.113.10, 203.0.113.11', ConfigService::sanitize_ip_list("203.0.113.10\n bad, 203.0.113.11"));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_dns_constants_win_over_settings(): void
    {
        define('WPCS_DNS_A_RECORDS', ['198.51.100.1', '198.51.100.2']);
        define('WPCS_DNS_CNAME_TARGET', 'edge.from-constant.example');
        $this->options['waas_dns_a_records'] = '203.0.113.10';
        $this->options['waas_dns_cname_target'] = 'sites.example.net';
        $config = new ConfigService();

        $this->assertSame(['198.51.100.1', '198.51.100.2'], $config->get_dns_a_records());
        $this->assertSame('edge.from-constant.example', $config->get_dns_cname_target());
    }

    private function assistant(): string
    {
        Functions\when('wp_parse_url')->alias(fn ($url, $part) => parse_url($url, $part));
        Functions\when('esc_url_raw')->returnArg();
        return (new ConfigService())->get_assistant_url();
    }

    public function test_a_storefront_on_the_anypress_api_offers_its_assistant_without_a_setting(): void
    {
        $this->options['waas_api_base_url'] = 'https://api.anypress.io';

        $this->assertSame(ConfigService::ANYPRESS_ASSISTANT_URL, $this->assistant());
    }

    public function test_clearing_the_setting_hides_the_assistant(): void
    {
        $this->options['waas_api_base_url'] = 'https://api.anypress.io';
        $this->options['waas_assistant_url'] = '';

        $this->assertSame('', $this->assistant());
    }

    public function test_another_api_gets_no_assistant_unless_one_is_set(): void
    {
        $this->options['waas_api_base_url'] = 'https://api.example.com';
        $this->assertSame('', $this->assistant());

        $this->options['waas_assistant_url'] = 'https://assistant.example.com/';
        $this->assertSame('https://assistant.example.com', $this->assistant());
    }
}
