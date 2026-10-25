<?php

namespace WaaSHost\Features;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Starts background work as soon as the request that asked for it ends, instead of on the next cron run.
 *
 * Storefronts usually run cron from a real cron job (DISABLE_WP_CRON), often only every few minutes, so a website
 * bought at checkout waited for the next run before the API was even asked to build it, and a store that wasn't
 * answering over HTTPS yet waited for another before it turned Ready. At the end of the request this sends a
 * signed, non-blocking request to the storefront itself (POST waas-host/v1/run-soon), which runs the hook there.
 * The cron event or action scheduled for the same work stays as the fallback, and the hooks it runs are safe to run
 * twice (StoreJobs takes a lock; the status check reads the API again).
 */
class RunSoon
{
    public const ROUTE = '/run-soon';
    /** The hooks a request may run. */
    public const HOOKS = [StoreJobs::RUN_HOOK, TenantStatusService::RECHECK_HOOK];
    /** The longest wait a request may ask for, in seconds. */
    public const MAX_DELAY = 10;

    /** @var array<string, array{0: string, 1: int|string, 2: int}> */
    private static array $pending = [];

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('shutdown', [$this, 'dispatch']);
    }

    /** Runs $hook($arg) in a request of its own once this one ends, after $delay seconds. */
    public static function request(string $hook, $arg, int $delay = 0): void
    {
        self::$pending[$hook . '|' . $arg] = [$hook, $arg, max(0, min($delay, self::MAX_DELAY))];
    }

    /** @return array<int, array{0: string, 1: int|string, 2: int}> */
    public static function pending(): array
    {
        return array_values(self::$pending);
    }

    public static function reset(): void
    {
        self::$pending = [];
    }

    public function dispatch(): void
    {
        $pending = self::$pending;
        self::$pending = [];
        // Cron and WP-CLI are already a runner: what they scheduled for now runs in the same pass.
        if (!$pending || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        foreach ($pending as [$hook, $arg, $delay]) {
            wp_remote_post(rest_url(PluginBootstrap::API_V1_NAMESPACE . self::ROUTE), [
                // Waits at most a second; the request goes on without us (see handle()).
                'blocking' => false,
                'timeout' => 1,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
                'body' => ['hook' => $hook, 'arg' => (string) $arg, 'delay' => $delay, 'sig' => self::sign($hook, (string) $arg, $delay)],
            ]);
        }
    }

    public function register_routes(): void
    {
        register_rest_route(PluginBootstrap::API_V1_NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            // The signature is the credential, checked in the callback.
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $hook = (string) $request->get_param('hook');
        $arg = (string) $request->get_param('arg');
        $delay = (int) $request->get_param('delay');
        if (!in_array($hook, self::HOOKS, true) || $delay < 0 || $delay > self::MAX_DELAY
            || !hash_equals(self::sign($hook, $arg, $delay), (string) $request->get_param('sig'))) {
            return new WP_REST_Response(['code' => 'invalid_signature', 'message' => 'Not a request this storefront sent.'], 401);
        }

        // The caller hangs up after a second; the work goes on.
        ignore_user_abort(true);
        if ($delay) {
            sleep($delay);
        }
        // Carts use integer ids; keep the arguments identical to the ones the scheduled events carry.
        do_action($hook, ctype_digit($arg) ? (int) $arg : $arg);

        return new WP_REST_Response(['ran' => true], 200);
    }

    public static function sign(string $hook, string $arg, int $delay): string
    {
        return hash_hmac('sha256', $hook . '|' . $arg . '|' . $delay, wp_salt('auth'));
    }
}
