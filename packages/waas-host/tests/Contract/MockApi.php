<?php

namespace Tests\Contract;

use Brain\Monkey\Functions;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Features\StoreJobs;

/**
 * Routes WordPress's HTTP functions to cURL so the plugin's real API client talks to the provisioning API (by
 * default the public Postman mock, see phpunit.xml.dist). The mock picks a response by the x-mock-response-name
 * header, named "<request>: <example>".
 */
trait MockApi
{
    /** Extra headers added to every outgoing request (e.g. x-mock-response-name). */
    private array $extra_headers = [];
    /** Every outgoing request: [method, url, headers, body]. */
    private array $requests = [];
    private array $options = [];

    private function use_mock_api(array $options = []): void
    {
        if (!class_exists('WP_Error')) {
            eval('class WP_Error { private $message; public function __construct($code = "", $message = "") { $this->message = $message; } public function get_error_message() { return $this->message; } }');
        }

        $this->extra_headers = [];
        $this->requests = [];
        $this->options = array_merge([
            'waas_api_base_url' => getenv('WAAS_TEST_API_BASE_URL'),
            'waas_api_key' => 'test-key',
            'waas_api_secret' => 'test-secret',
        ], $options);

        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('untrailingslashit')->alias(fn ($value) => rtrim($value, '/\\'));
        Functions\when('is_wp_error')->alias(fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn ($r) => $r instanceof \WP_Error ? '' : $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn ($r) => $r instanceof \WP_Error ? '' : $r['body']);
        Functions\when('wp_remote_get')->alias(fn ($url, $args = []) => $this->request($args['method'] ?? 'GET', $url, $args));
        Functions\when('wp_remote_post')->alias(fn ($url, $args = []) => $this->request($args['method'] ?? 'POST', $url, $args));
        Functions\when('wp_remote_head')->alias(fn ($url, $args = []) => $this->request('HEAD', $url, $args));
    }

    /**
     * Store jobs run here, in the test, with run(): Action Scheduler and the job lock are stubbed in memory.
     */
    private function use_store_jobs(): StoreJobs
    {
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
        Functions\when('wp_generate_uuid4')->alias(fn () => sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff)));
        Functions\when('as_schedule_single_action')->justReturn(1);
        Functions\when('as_next_scheduled_action')->justReturn(false);

        return new StoreJobs();
    }

    /**
     * Run the store's jobs as Action Scheduler would. A job that fails keeps its error in the queue (to retry
     * later); report that error here instead of letting a later assertion trip over the missing store.
     */
    private function run_store_jobs(StoreJobs $jobs, $subscription_id): void
    {
        $jobs->run($subscription_id);

        $queue = Commerce::require_adapter()->get_subscription_meta($subscription_id, StoreJobs::QUEUE_META);
        if (is_array($queue) && isset($queue[0]['error'])) {
            $this->fail(sprintf('Store job "%s" failed against the mock: %s', $queue[0]['op'], $queue[0]['error']));
        }
    }

    /** Seconds to wait before resending a request the mock failed: 4 attempts, about 12 s at worst. */
    private static function mock_retry_delays(): array
    {
        return [1, 3, 8];
    }

    private function pause(int $seconds): void
    {
        sleep($seconds);
    }

    private function mock_example(string $name): void
    {
        $this->extra_headers['x-mock-response-name'] = $name;
    }

    private function request(string $method, string $url, array $args)
    {
        $headers = array_merge($args['headers'] ?? [], $this->extra_headers);
        $this->requests[] = [$method, $url, $headers, $args['body'] ?? null];

        $header_lines = array_map(fn ($key, $value) => $key . ': ' . $value, array_keys($headers), $headers);

        // The public mock sometimes hangs, or answers with an error of its own instead of the example. That says
        // nothing about the plugin, so such a request is sent again after a pause; its errors come in short bursts,
        // so an immediate resend tends to hit the same burst. Any other error is returned as is.
        for ($attempt = 1; ; $attempt++) {
            [$body, $code, $errno, $error] = $this->send($method, $url, $header_lines, $args);
            $mock_failed = $errno === CURLE_OPERATION_TIMEDOUT || self::is_mock_error($body);
            if (!$mock_failed || $attempt > count(self::mock_retry_delays())) {
                break;
            }
            $this->pause(self::mock_retry_delays()[$attempt - 1]);
        }

        if ($body === false) {
            return new \WP_Error('http_request_failed', $error);
        }

        return ['body' => $body, 'response' => ['code' => $code]];
    }

    /**
     * The mock's own failure, not an example: {"error": {"name": "serverError", "header": "Unable to mock request"}}
     * (500), or "syncError" / "Unable to load environments" (400). Examples answer with the API's own bodies, which
     * never have an "error" object.
     *
     * @param string|false $body
     */
    private static function is_mock_error($body): bool
    {
        $decoded = is_string($body) ? json_decode($body, true) : null;

        return is_array($decoded) && isset($decoded['error']['name'], $decoded['error']['header']);
    }

    /** @return array{0: string|false, 1: int, 2: int, 3: string} body, status code, curl error number, curl error */
    private function send(string $method, string $url, array $header_lines, array $args): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            // These tests check request and response shapes, not timeouts: give the public mock room for a
            // cold start instead of WordPress's 5 s default (the plugin passes 20 s for writes).
            CURLOPT_TIMEOUT => max($args['timeout'] ?? 5, 30),
        ]);
        if (isset($args['body'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $args['body']);
        }

        $body = curl_exec($curl);
        $result = [$body, (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), curl_errno($curl), curl_error($curl)];
        curl_close($curl);

        return $result;
    }
}
