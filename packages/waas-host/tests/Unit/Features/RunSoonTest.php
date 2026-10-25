<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSHost\Features\RunSoon;
use WaaSHost\Features\StoreJobs;
use WaaSHost\Features\TenantStatusService;
use WP_REST_Request;

class RunSoonTest extends TestCase
{
    private RunSoon $runSoon;
    /** wp_remote_post calls: [url, args] */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        RunSoon::reset();
        Functions\when('wp_salt')->justReturn('salt');
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('rest_url')->alias(fn ($path) => 'https://shop.example/wp-json/' . $path);
        Functions\when('wp_remote_post')->alias(function ($url, $args) {
            $this->posts[] = [$url, $args];
            return [];
        });
        $this->runSoon = new RunSoon();
    }

    public function test_sends_one_signed_request_per_piece_of_work_when_the_request_ends(): void
    {
        RunSoon::request(StoreJobs::RUN_HOOK, 7);
        RunSoon::request(StoreJobs::RUN_HOOK, 7);
        RunSoon::request(TenantStatusService::RECHECK_HOOK, 7, 5);

        $this->runSoon->dispatch();

        $this->assertCount(2, $this->posts);
        [$url, $args] = $this->posts[0];
        $this->assertSame('https://shop.example/wp-json/waas-host/v1/run-soon', $url);
        $this->assertFalse($args['blocking']);
        $this->assertSame(StoreJobs::RUN_HOOK, $args['body']['hook']);
        $this->assertSame(RunSoon::sign(StoreJobs::RUN_HOOK, '7', 0), $args['body']['sig']);
        $this->assertSame(5, $this->posts[1][1]['body']['delay']);
        $this->assertSame([], RunSoon::pending());
    }

    public function test_cron_runs_its_own_work(): void
    {
        Functions\when('wp_doing_cron')->justReturn(true);
        RunSoon::request(StoreJobs::RUN_HOOK, 7);

        $this->runSoon->dispatch();

        $this->assertSame([], $this->posts);
    }

    public function test_runs_the_hook_for_a_signed_request(): void
    {
        Actions\expectDone(StoreJobs::RUN_HOOK)->once()->with(7);

        $response = $this->runSoon->handle($this->request(StoreJobs::RUN_HOOK, '7', 0, RunSoon::sign(StoreJobs::RUN_HOOK, '7', 0)));

        $this->assertSame(200, $response->get_status());
    }

    public function test_refuses_unsigned_requests_and_other_hooks(): void
    {
        Actions\expectDone(StoreJobs::RUN_HOOK)->never();
        Actions\expectDone('shutdown')->never();

        $this->assertSame(401, $this->runSoon->handle($this->request(StoreJobs::RUN_HOOK, '7', 0, 'nope'))->get_status());
        $this->assertSame(401, $this->runSoon->handle($this->request('shutdown', '7', 0, RunSoon::sign('shutdown', '7', 0)))->get_status());
        $this->assertSame(401, $this->runSoon->handle($this->request(StoreJobs::RUN_HOOK, '7', 60, RunSoon::sign(StoreJobs::RUN_HOOK, '7', 60)))->get_status());
    }

    private function request(string $hook, string $arg, int $delay, string $sig): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/waas-host/v1/run-soon');
        foreach (['hook' => $hook, 'arg' => $arg, 'delay' => $delay, 'sig' => $sig] as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }
}
