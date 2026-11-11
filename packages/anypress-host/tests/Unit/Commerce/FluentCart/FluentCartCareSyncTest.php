<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use AnyPressHost\Commerce\FluentCart\FluentCartAdapter;
use AnyPressHost\Commerce\FluentCart\FluentCartCare;
use AnyPressHost\Commerce\FluentCart\FluentCartCareSync;
use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Core\Tenant;

/** The API hears each website's care, for the dashboard. */
class FluentCartCareSyncTest extends TestCase
{
    private const WEBSITE = 900;
    private const CARE = 950;

    private $adapter;
    private $care;
    private $api;
    private array $meta;
    private array $scheduled = [];
    private FluentCartCareSync $sync;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_date')->justReturn('2026-11');
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(function ($at, $hook, $args) {
            $this->scheduled[] = [$hook, $args];
            return true;
        });

        $this->meta = [
            self::WEBSITE => [Tenant::TENANT_EXTERNAL_ID_META => 'store-900', Tenant::TENANT_STATE => Tenant::READY],
            self::CARE => [FluentCartCare::WEBSITE_META => (string) self::WEBSITE],
        ];
        $this->adapter = Mockery::mock(FluentCartAdapter::class);
        $this->adapter->allows('get_subscription_meta')->andReturnUsing(fn ($id, $key) => $this->meta[$id][$key] ?? '');
        $this->care = Mockery::mock(FluentCartCare::class);
        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->sync = new FluentCartCareSync($this->api, $this->adapter, $this->care);
    }

    private function with_package(): void
    {
        $this->care->allows('summary')->with(self::WEBSITE)->andReturn([
            'subscription_id' => self::CARE, 'package' => 'Care Plus', 'answer' => '1 business day', 'priority' => 'medium',
            'call_url' => '', 'minutes' => 120, 'used' => 40, 'left' => 80, 'extra' => 60,
        ]);
    }

    public function test_care_added_sends_the_package_and_this_months_time(): void
    {
        $this->with_package();
        $this->api->expects('set_care')->with('store-900', 'Care Plus', ['minutes' => 120, 'used' => 40, 'extra' => 60, 'answer' => '1 business day', 'month' => '2026-11']);

        $this->sync->care_added(self::CARE, self::WEBSITE);
    }

    public function test_a_care_subscription_changing_status_sends_its_websites_care(): void
    {
        $this->care->allows('summary')->andReturn(null);
        $this->api->expects('set_care')->with('store-900', null);

        $this->sync->subscription_changed(['subscription' => (object) ['id' => self::CARE]]);
        $this->sync->subscription_changed(['subscription' => (object) ['id' => 123]]); // not care: nothing sent
    }

    public function test_extra_time_without_a_package_ends_the_package_on_the_dashboard(): void
    {
        $this->care->allows('summary')->andReturn(['subscription_id' => 0, 'package' => '', 'answer' => '', 'priority' => 'normal', 'call_url' => '', 'minutes' => 0, 'used' => 0, 'left' => 0, 'extra' => 60]);
        $this->api->expects('set_care')->with('store-900', null);

        $this->sync->sync(self::WEBSITE);
    }

    public function test_a_website_without_a_store_sends_nothing(): void
    {
        $this->meta[self::WEBSITE][Tenant::TENANT_STATE] = Tenant::DELETED;
        $this->api->expects('set_care')->never();

        $this->sync->sync(self::WEBSITE);
    }

    public function test_a_failed_send_is_tried_once_more_later(): void
    {
        $this->with_package();
        $this->api->expects('set_care')->twice()->andThrow(new \Exception('API down'));

        $this->sync->sync(self::WEBSITE);
        $this->assertSame([[FluentCartCareSync::RETRY_HOOK, [self::WEBSITE]]], $this->scheduled);

        $this->sync->retry(self::WEBSITE);
        $this->assertCount(1, $this->scheduled, 'the retry does not schedule another');
    }
}
