<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\SecretBox;
use WaaSHost\Features\Webhooks;

/** One endpoint per storefront: registered once, replaced when the site's address or the API changes. */
class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private array $options = [];
    /** The API connection, as ConfigService's filters give it. */
    private array $connection = [];
    private string $site = 'https://shop.example.com';
    private $api;
    private Webhooks $webhooks;
    private string $log;
    private $previous_log;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        $this->options = [];
        $this->connection = ['base_url' => 'https://api.example.com/', 'key' => 'key', 'secret' => 'secret'];
        foreach ($this->connection as $name => $value) {
            Filters\expectApplied('waas_settings_api_' . $name)->zeroOrMoreTimes()->andReturnUsing(fn () => $this->connection[$name]);
        }
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function ($name, $value) {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('rest_url')->alias(fn ($path) => $this->site . '/wp-json/' . $path);

        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->api->allows('get_webhook_endpoints')->andReturn([])->byDefault();
        $this->webhooks = new Webhooks($this->api, new ConfigService());

        $this->log = tempnam(sys_get_temp_dir(), 'waas-log');
        $this->previous_log = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previous_log);
        @unlink($this->log);
        parent::tearDown();
    }

    private function created(string $id): object
    {
        return (object) ['id' => $id, 'url' => $this->site . '/wp-json/waas-host/v1/webhook', 'secret' => self::SECRET];
    }

    public function test_registers_the_receiver_for_the_events_it_handles_and_seals_the_secret(): void
    {
        $this->api->expects('create_webhook_endpoint')->once()
            ->with('https://shop.example.com/wp-json/waas-host/v1/webhook', ['tenant.ready', 'tenant.restored', 'tenant.deleted', 'tenant.domains_changed', 'tenant.failed'], Mockery::type('string'))
            ->andReturn($this->created('ep-1'));

        $this->assertFalse(Webhooks::is_registered());
        $this->webhooks->ensure_registered();

        $stored = $this->options[Webhooks::OPTION];
        $this->assertSame('ep-1', $stored['id']);
        $this->assertSame('https://api.example.com', $stored['api']);
        $this->assertStringStartsWith(SecretBox::PREFIX, $stored['secret']);
        $this->assertStringNotContainsString('MfKQ9r8G', serialize($stored), 'the secret is not stored in the clear');
        $this->assertSame(self::SECRET, Webhooks::secret());
        $this->assertTrue(Webhooks::is_registered());
    }

    public function test_an_endpoint_for_the_same_url_and_api_is_kept(): void
    {
        $this->api->expects('create_webhook_endpoint')->once()->andReturn($this->created('ep-1'));
        $this->api->expects('delete_webhook_endpoint')->never();
        $this->webhooks->ensure_registered();

        $this->webhooks->ensure_registered();
        $this->webhooks->connection_saved();

        $this->assertSame('ep-1', $this->options[Webhooks::OPTION]['id']);
    }

    public function test_an_endpoint_registered_for_fewer_events_is_replaced(): void
    {
        $this->api->expects('create_webhook_endpoint')->twice()->andReturn($this->created('ep-1'), $this->created('ep-2'));
        $this->api->expects('delete_webhook_endpoint')->once()->with('ep-1');
        $this->api->allows('get_webhook_endpoints')->andReturn([]);
        $this->webhooks->ensure_registered();
        // As an older version stored it: no event list.
        unset($this->options[Webhooks::OPTION]['events']);

        $this->webhooks->ensure_registered();

        $this->assertSame('ep-2', $this->options[Webhooks::OPTION]['id']);
    }

    public function test_a_new_site_address_replaces_the_endpoint(): void
    {
        $this->api->expects('create_webhook_endpoint')->twice()->andReturn($this->created('ep-1'), $this->created('ep-2'));
        $this->webhooks->ensure_registered();

        $this->site = 'https://new-shop.example.com';
        $this->api->expects('delete_webhook_endpoint')->once()->with('ep-1');
        $this->webhooks->ensure_registered();

        $this->assertSame('ep-2', $this->options[Webhooks::OPTION]['id']);
        $this->assertSame('https://new-shop.example.com/wp-json/waas-host/v1/webhook', $this->options[Webhooks::OPTION]['url']);
    }

    public function test_a_new_api_gets_its_own_endpoint_and_the_old_one_is_left_alone(): void
    {
        $this->api->expects('create_webhook_endpoint')->twice()->andReturn($this->created('ep-1'), $this->created('ep-9'));
        $this->webhooks->ensure_registered();

        // These credentials can't reach the old API's endpoint.
        $this->connection['base_url'] = 'https://other-api.example.com';
        $this->api->expects('delete_webhook_endpoint')->never();
        $this->webhooks->ensure_registered();

        $this->assertSame('ep-9', $this->options[Webhooks::OPTION]['id']);
        $this->assertSame('https://other-api.example.com', $this->options[Webhooks::OPTION]['api']);
    }

    public function test_registering_again_replaces_it_and_clears_strays_at_the_same_url(): void
    {
        $this->api->expects('create_webhook_endpoint')->twice()->andReturn($this->created('ep-1'), $this->created('ep-3'));
        $this->webhooks->ensure_registered();

        // ep-2 is a registration whose answer never arrived; ep-4 belongs to some other integration.
        $this->api->allows('get_webhook_endpoints')->andReturn([
            (object) ['id' => 'ep-1', 'url' => $this->site . '/wp-json/waas-host/v1/webhook'],
            (object) ['id' => 'ep-2', 'url' => $this->site . '/wp-json/waas-host/v1/webhook'],
            (object) ['id' => 'ep-4', 'url' => 'https://crm.example.com/hooks'],
        ]);
        $this->api->expects('delete_webhook_endpoint')->once()->with('ep-1');
        $this->api->expects('delete_webhook_endpoint')->once()->with('ep-2');
        $this->webhooks->ensure_registered(true);

        $this->assertSame('ep-3', $this->options[Webhooks::OPTION]['id']);
    }

    public function test_a_url_the_api_refuses_is_recorded_and_polling_stays_fast(): void
    {
        $this->site = 'http://shop.local';
        $this->api->expects('create_webhook_endpoint')->andThrow(new \Exception('Failed: 400 - {"statusCode":400,"message":"url must start with https://"}'));

        $this->webhooks->ensure_registered();

        $stored = $this->options[Webhooks::OPTION];
        $this->assertSame('', $stored['id']);
        $this->assertSame('url must start with https:// (HTTP 400)', $stored['error']);
        $this->assertFalse(Webhooks::is_registered());
        $this->assertSame('five_seconds', \WaaSHost\Features\TenantStatusService::poll_recurrence());

        // Tried again on the next save, and kept once it works.
        $this->api->expects('create_webhook_endpoint')->andReturn($this->created('ep-5'));
        $this->webhooks->ensure_registered();
        $this->assertSame('', $this->options[Webhooks::OPTION]['error']);
        $this->assertSame('every_minute', \WaaSHost\Features\TenantStatusService::poll_recurrence());
    }

    public function test_a_secret_that_can_no_longer_be_opened_is_registered_again(): void
    {
        $this->api->expects('create_webhook_endpoint')->twice()->andReturn($this->created('ep-1'), $this->created('ep-2'));
        $this->webhooks->ensure_registered();

        // The encryption key changed (WAAS_HOST_KEY_ENCRYPTION_KEY or the auth salt).
        Functions\when('wp_salt')->justReturn('another-salt');
        $this->assertSame('', Webhooks::secret());
        $this->api->expects('delete_webhook_endpoint')->once()->with('ep-1');
        $this->webhooks->ensure_registered();

        $this->assertSame(self::SECRET, Webhooks::secret());
        $this->assertSame('ep-2', $this->options[Webhooks::OPTION]['id']);
    }

    public function test_nothing_is_registered_without_api_credentials(): void
    {
        $this->connection['key'] = '';
        $this->api->expects('create_webhook_endpoint')->never();

        $this->webhooks->ensure_registered();

        $this->assertArrayNotHasKey(Webhooks::OPTION, $this->options);
    }
}
