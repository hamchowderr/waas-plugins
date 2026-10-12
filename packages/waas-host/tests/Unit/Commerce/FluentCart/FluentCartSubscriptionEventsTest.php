<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Commerce\FluentCart\FluentCartSubscriptionEvents;
use WaaSHost\Core\Tenant;

class FluentCartSubscriptionEventsTest extends TestCase
{
    private $store;
    private $order;
    private array $logs = [];
    private array $state = [];
    private FluentCartSubscriptionEvents $events;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_waas_state')->andReturnUsing(function () {
            return $this->state;
        });
        $this->order = Mockery::mock();
        $this->order->allows('addLog')->andReturnUsing(function (...$args) {
            $this->logs[] = $args;
        });
        $this->events = new FluentCartSubscriptionEvents($this->store);
    }

    private function data(): array
    {
        return ['subscription' => (object) ['id' => 900], 'order' => $this->order];
    }

    private function changes_state(string $state): \Closure
    {
        return function () use ($state) {
            $this->state = [Tenant::TENANT_STATE => $state];
        };
    }

    public function test_hooks_fluentcart_pause_resume_and_renewal(): void
    {
        $this->events->register();

        $this->assertNotFalse(has_action('fluent_cart/subscription_paused', [$this->events, 'paused']));
        $this->assertNotFalse(has_action('fluent_cart/subscription_resumed', [$this->events, 'resumed']));
        $this->assertNotFalse(has_action('fluent_cart/subscription_renewed', [$this->events, 'renewed']));
    }

    public function test_a_paused_subscription_takes_the_store_offline_as_on_hold(): void
    {
        Actions\expectDone('waas_subscription_suspended')->once()->with(900)->whenHappen($this->changes_state(Tenant::SUSPENDED));

        $this->events->paused($this->data());

        $this->assertSame([['Website taken offline', 'Subscription #900 (subscription_paused)', 'info', 'WaaS']], $this->logs);
    }

    public function test_paused_then_active_again_resumes_the_store(): void
    {
        // FluentCart fires only "resumed" for paused -> active, not "reactivated".
        Actions\expectDone('waas_subscription_reactivated')->once()->with(900)->whenHappen($this->changes_state(Tenant::READY));

        $this->events->resumed($this->data());

        $this->assertSame('Website resumed', $this->logs[0][0]);
    }

    public function test_a_renewal_asks_waas_host_to_resume_a_store_it_took_offline(): void
    {
        Actions\expectDone('waas_subscription_renewed')->once()->with(900)->whenHappen($this->changes_state(Tenant::READY));

        $this->events->renewed($this->data());

        $this->assertSame('Subscription #900 (subscription_renewed)', $this->logs[0][1]);
    }

    public function test_an_ordinary_renewal_leaves_no_note(): void
    {
        // The store was running: waas-host did nothing.
        Actions\expectDone('waas_subscription_renewed')->once()->with(900);

        $this->events->renewed($this->data());

        $this->assertSame([], $this->logs);
    }

    public function test_an_event_without_a_subscription_is_ignored(): void
    {
        Actions\expectDone('waas_subscription_suspended')->never();

        $this->events->paused(['subscription' => null]);
        $this->events->paused('not an array');

        $this->assertSame([], $this->logs);
    }

    public function test_an_event_without_an_order_still_acts(): void
    {
        Actions\expectDone('waas_subscription_suspended')->once()->with(900)->whenHappen($this->changes_state(Tenant::SUSPENDED));

        $this->events->paused(['subscription' => (object) ['id' => 900], 'order' => null]);

        $this->assertSame([], $this->logs);
    }
}
