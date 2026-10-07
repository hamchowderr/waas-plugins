<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Features\StoreJobs;

/**
 * A fake cart, options and an in-memory Action Scheduler, for tests of the store jobs and the code that queues them.
 */
abstract class StoreJobsTestCase extends TestCase
{
    protected FakeCommerceAdapter $cart;
    protected StoreJobs $jobs;
    protected array $options = [];
    /** Pending Action Scheduler actions: [timestamp, hook, args, group] */
    protected array $actions = [];
    /** WP-Cron single events: [timestamp, hook, args] */
    protected array $scheduled = [];

    protected function setUp(): void
    {
        parent::setUp();
        Commerce::reset();
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }

        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('add_option')->alias(function ($name, $value = '') {
            if (array_key_exists($name, $this->options)) {
                return false;
            }
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($name) {
            unset($this->options[$name]);
            return true;
        });
        Functions\when('absint')->alias(fn ($value) => abs((int) $value));
        Functions\when('wp_schedule_single_event')->alias(function ($timestamp, $hook, $args = []) {
            $this->scheduled[] = [$timestamp, $hook, $args];
            return true;
        });
        Functions\when('wp_clear_scheduled_hook')->alias(function ($hook, $args = []) {
            $this->scheduled = array_values(array_filter($this->scheduled, fn ($e) => !($e[1] === $hook && $e[2] === $args)));
            return 1;
        });
        Functions\when('as_schedule_single_action')->alias(function ($timestamp, $hook, $args = [], $group = '') {
            $this->actions[] = [$timestamp, $hook, $args, $group];
            return count($this->actions);
        });
        Functions\when('as_next_scheduled_action')->alias(function ($hook, $args = null, $group = '') {
            foreach ($this->actions as [$timestamp, $action_hook, $action_args, $action_group]) {
                if ($action_hook === $hook && $action_args === $args && $action_group === $group) {
                    return $timestamp;
                }
            }
            return false;
        });

        $this->cart = new FakeCommerceAdapter();
        Commerce::register($this->cart);
        $this->jobs = new StoreJobs();
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    /** Action Scheduler runs the pending runners for the subscription that are due (or all of them, with $all). */
    protected function run_due($subscription_id, bool $all = false): void
    {
        $due = [];
        foreach ($this->actions as $i => [$timestamp, $hook, $args]) {
            if ($hook === StoreJobs::RUN_HOOK && $args === [$subscription_id] && ($all || $timestamp <= time())) {
                $due[] = $i;
            }
        }
        foreach ($due as $i) {
            unset($this->actions[$i]);
        }
        $this->actions = array_values($this->actions);

        if ($due) {
            $this->jobs->run($subscription_id);
        }
    }

    protected function queue($subscription_id): array
    {
        $queue = $this->cart->meta[$subscription_id][StoreJobs::QUEUE_META] ?? '';

        return is_array($queue) ? $queue : [];
    }
}
