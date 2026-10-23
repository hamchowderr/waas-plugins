<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\StoreJobs;

/**
 * The per-store job queue: order, retries with backoff, Failed after the last retry, admin retry, locking, and the
 * sweep that restarts a store whose runner was lost.
 */
class StoreJobsTest extends StoreJobsTestCase
{
    /** @var string[] */
    private array $ran = [];
    /** op => number of times it should still fail */
    private array $failures = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['a', 'b', 'c'] as $op) {
            $this->jobs->register($op, function ($subscription_id, array $args, int $attempt) use ($op) {
                if (($this->failures[$op] ?? 0) > 0) {
                    $this->failures[$op]--;
                    throw new \Exception("$op failed");
                }
                $this->ran[] = $op . ($args ? json_encode($args) : '') . "@$attempt";
                return null;
            });
        }
        $this->cart->meta[7] = [Tenant::TENANT_STATE => Tenant::READY];
    }

    public function test_enqueue_only_schedules_a_runner(): void
    {
        $this->jobs->enqueue(7, 'a');
        $this->jobs->enqueue(7, 'b');

        $this->assertSame([], $this->ran);
        $this->assertSame([[time(), StoreJobs::RUN_HOOK, [7], StoreJobs::GROUP]], $this->actions);
    }

    public function test_runs_jobs_in_the_order_they_were_queued(): void
    {
        $this->jobs->enqueue(7, 'a', ['n' => 1]);
        $this->jobs->enqueue(7, 'b');
        $this->jobs->enqueue(7, 'a', ['n' => 2]);

        $this->run_due(7);

        $this->assertSame(['a{"n":1}@0', 'b@0', 'a{"n":2}@0'], $this->ran);
        $this->assertSame('', $this->cart->meta[7][StoreJobs::QUEUE_META]);
    }

    public function test_a_failing_job_is_retried_with_backoff_and_holds_back_the_ones_behind_it(): void
    {
        $this->failures['a'] = 2;
        $this->jobs->enqueue(7, 'a');
        $this->jobs->enqueue(7, 'b');

        $this->run_due(7);
        $this->assertSame([], $this->ran);
        $this->assertEqualsWithDelta(time() + 60, $this->actions[0][0], 2);
        $this->assertSame('a failed', $this->queue(7)[0]['error']);

        $this->run_due(7, true);
        $this->assertEqualsWithDelta(time() + 300, $this->actions[0][0], 2);

        $this->run_due(7, true);
        $this->assertSame(['a@2', 'b@0'], $this->ran);
        $this->assertSame([], $this->actions);
    }

    public function test_marks_the_store_failed_after_the_last_retry_and_keeps_the_queue(): void
    {
        $this->failures['a'] = 99;
        $this->jobs->enqueue(7, 'a');
        $this->jobs->enqueue(7, 'b');
        Actions\expectDone('waas_store_job_failed')->once()->with(7, 'a', 'a failed');

        for ($i = 0; $i <= count(StoreJobs::RETRY_DELAYS); $i++) {
            $this->run_due(7, true);
        }

        $this->assertSame(Tenant::FAILED, $this->cart->meta[7][Tenant::TENANT_STATE]);
        $failure = $this->cart->meta[7][StoreJobs::FAILURE_META];
        $this->assertSame('a', $failure['op']);
        $this->assertSame(count(StoreJobs::RETRY_DELAYS) + 1, $failure['attempts']);
        $this->assertSame(Tenant::READY, $failure['state']);
        $this->assertSame(['a', 'b'], array_column($this->queue(7), 'op'));
        $this->assertSame([], $this->actions, 'no more runs until an admin retries');
        $this->assertSame([7 => $failure], $this->jobs->failed_stores());
    }

    public function test_retries_take_about_two_hours_before_the_store_is_marked_failed(): void
    {
        $this->assertSame([60, 300, 900, 1800, 3600], StoreJobs::RETRY_DELAYS);
        $this->assertLessThanOrEqual(2 * 3600, array_sum(StoreJobs::RETRY_DELAYS));
    }

    public function test_the_admin_gets_one_email_per_failure_not_one_per_retry(): void
    {
        $this->failures['a'] = 99;
        $this->jobs->enqueue(7, 'a');

        for ($i = 0; $i <= count(StoreJobs::RETRY_DELAYS) + 3; $i++) {
            $this->run_due(7, true);
            $this->jobs->run(7); // even a stray runner
        }
        $this->assertCount(1, $this->mails);
        [$to, $subject, $message] = $this->mails[0];
        $this->assertSame('ops@storefront.example', $to);
        $this->assertStringContainsString('Peptide Storefront', $subject);
        $this->assertStringContainsString('subscription #7', $message);
        $this->assertStringContainsString('Job: a', $message);
        $this->assertStringContainsString('Tries: 6', $message);
        $this->assertStringContainsString('Last error: a failed', $message);
        $this->assertStringContainsString('https://storefront.example/wp-admin/', $message);

        // Retried by the admin and failing again is a new failure: one more email.
        $this->jobs->retry(7);
        for ($i = 0; $i <= count(StoreJobs::RETRY_DELAYS); $i++) {
            $this->run_due(7, true);
        }
        $this->assertCount(2, $this->mails);
    }

    public function test_a_failed_store_keeps_new_work_and_its_state_for_the_retry(): void
    {
        $this->failures['a'] = 99;
        $this->jobs->enqueue(7, 'a');
        for ($i = 0; $i <= count(StoreJobs::RETRY_DELAYS); $i++) {
            $this->run_due(7, true);
        }

        $this->jobs->enqueue(7, 'c');
        $this->jobs->set_status(7, Tenant::SUSPENDED);

        $this->assertSame([], $this->actions);
        $this->assertSame(Tenant::FAILED, $this->cart->meta[7][Tenant::TENANT_STATE]);
        $this->assertSame(Tenant::SUSPENDED, $this->jobs->status(7));

        $this->failures['a'] = 0;
        $this->jobs->retry(7);
        $this->assertSame(Tenant::SUSPENDED, $this->cart->meta[7][Tenant::TENANT_STATE]);
        $this->assertSame('', $this->cart->meta[7][StoreJobs::FAILURE_META]);

        $this->run_due(7);
        $this->assertSame(['a@0', 'c@0'], $this->ran);
    }

    public function test_a_job_can_hand_over_to_other_jobs(): void
    {
        $this->jobs->register('pick', fn () => ['replace' => [['b', ['x' => 1]], ['c', []]]]);
        $this->jobs->enqueue(7, 'pick');
        $this->jobs->enqueue(7, 'a');

        $this->run_due(7);

        $this->assertSame(['b{"x":1}@0', 'c@0', 'a@0'], $this->ran);
    }

    public function test_work_queued_while_a_job_runs_is_kept(): void
    {
        $this->jobs->register('slow', function () {
            $this->jobs->enqueue(7, 'c');
            return null;
        });
        $this->jobs->enqueue(7, 'slow');

        $this->run_due(7);

        $this->assertSame(['c@0'], $this->ran);
    }

    public function test_a_second_runner_waits_for_the_first(): void
    {
        $this->jobs->enqueue(7, 'a');
        $this->options['waas_store_jobs_lock_' . md5('7')] = time();

        $this->run_due(7);

        $this->assertSame([], $this->ran);
        $this->assertEqualsWithDelta(time() + 30, $this->actions[0][0], 2);
    }

    public function test_a_runner_that_died_holding_the_lock_does_not_block_the_store(): void
    {
        $this->jobs->enqueue(7, 'a');
        $this->options['waas_store_jobs_lock_' . md5('7')] = time() - StoreJobs::LOCK_TTL - 1;

        $this->run_due(7);

        $this->assertSame(['a@0'], $this->ran);
        $this->assertArrayNotHasKey('waas_store_jobs_lock_' . md5('7'), $this->options);
    }

    public function test_the_sweep_restarts_a_store_whose_runner_was_lost(): void
    {
        $this->jobs->enqueue(7, 'a');
        $this->cart->meta[8] = [StoreJobs::QUEUE_META => [['op' => 'b', 'args' => [], 'attempts' => 0]]];
        $this->actions = []; // e.g. the runner died and Action Scheduler marked it failed

        $this->jobs->sweep();

        $this->assertSame([[7], [8]], array_column($this->actions, 2));
    }

    public function test_the_sweep_leaves_failed_stores_to_the_admin(): void
    {
        $this->cart->meta[8] = [
            StoreJobs::QUEUE_META => [['op' => 'b', 'args' => [], 'attempts' => 7]],
            StoreJobs::FAILURE_META => ['op' => 'b', 'attempts' => 7, 'error' => 'x', 'at' => time(), 'state' => Tenant::READY],
        ];

        $this->jobs->sweep();

        $this->assertSame([], $this->actions);
    }

    public function test_admins_see_failed_stores_with_a_retry_link(): void
    {
        $this->cart->meta[8] = [
            StoreJobs::FAILURE_META => ['op' => 'create', 'attempts' => 7, 'error' => 'Failed: 503', 'at' => 1791302400, 'state' => Tenant::QUEUED],
        ];
        Functions\when('current_user_can')->justReturn(true);
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('admin_url')->alias(fn ($path = '') => 'https://storefront.example/wp-admin/' . $path);
        Functions\when('wp_nonce_url')->alias(fn ($url, $action) => $url . '&_wpnonce=nonce-for-' . $action);
        Functions\when('wp_date')->alias(fn ($format, $timestamp) => gmdate($format, $timestamp));
        if (!defined('WAAS_HOST_TEXTDOMAIN')) {
            define('WAAS_HOST_TEXTDOMAIN', 'waas-host');
        }

        ob_start();
        $this->jobs->show_failed_notice();
        $html = ob_get_clean();

        $this->assertStringContainsString('1 website could not be updated', $html);
        $this->assertStringContainsString('Subscription #8: &quot;create&quot; failed 7 times', $html);
        $this->assertStringContainsString('Failed: 503', $html);
        $this->assertStringContainsString('admin-post.php?action=waas_retry_store_jobs&subscription_id=8&_wpnonce=nonce-for-waas_retry_store_jobs_8', $html);
    }

    public function test_no_notice_without_failed_stores(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        ob_start();
        $this->jobs->show_failed_notice();

        $this->assertSame('', ob_get_clean());
    }
}
