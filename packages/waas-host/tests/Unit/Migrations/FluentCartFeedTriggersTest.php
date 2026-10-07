<?php

namespace Tests\Unit\Migrations;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Commerce\FluentCart\WaasProvisioningIntegration;
use WaaSHost\Migrations\FluentCartFeedTriggers;

class FluentCartFeedTriggersTest extends TestCase
{
    public function test_it_adds_the_reactivation_trigger_once_after_the_update(): void
    {
        $store = Mockery::mock(FluentCartStore::class);
        $store->expects('add_feed_trigger')
            ->with(['subscription_activated', 'subscription_canceled', 'subscription_eot'], 'subscription_reactivated')
            ->once()
            ->andReturn(2);
        Functions\expect('get_option')->with(FluentCartFeedTriggers::OPTION, 0)->andReturn(0, 1);
        Functions\expect('update_option')->once()->with(FluentCartFeedTriggers::OPTION, 1);

        $migration = new FluentCartFeedTriggers($store);

        $this->assertSame(2, $migration->maybe_run());
        $this->assertNull($migration->maybe_run(), 'already ran');
    }

    public function test_feeds_on_the_old_default_triggers_get_the_new_defaults(): void
    {
        $this->assertSame(WaasProvisioningIntegration::DEFAULT_TRIGGERS, FluentCartFeedTriggers::updated_triggers(['subscription_activated', 'subscription_canceled', 'subscription_eot']));
        $this->assertSame(WaasProvisioningIntegration::DEFAULT_TRIGGERS, FluentCartFeedTriggers::updated_triggers(['subscription_eot', 'subscription_activated', 'subscription_canceled']), 'any order');
    }

    public function test_feeds_with_triggers_changed_by_hand_are_left_alone(): void
    {
        $this->assertNull(FluentCartFeedTriggers::updated_triggers(['subscription_activated', 'subscription_canceled']));
        $this->assertNull(FluentCartFeedTriggers::updated_triggers(['subscription_activated', 'subscription_canceled', 'subscription_eot', 'order_paid_done']));
        $this->assertNull(FluentCartFeedTriggers::updated_triggers(WaasProvisioningIntegration::DEFAULT_TRIGGERS), 'already has it');
    }
}
