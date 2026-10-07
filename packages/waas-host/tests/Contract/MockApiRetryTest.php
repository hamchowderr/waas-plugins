<?php

namespace Tests\Contract;

use Tests\Unit\TestCase;

/**
 * The mock client retries the public mock's own failures (it once left the status poller in "Linking Domain" on CI,
 * because the poller swallows API errors) but returns the API's error examples as they are. No network: send() is
 * replaced with a scripted one.
 */
class MockApiRetryTest extends TestCase
{
    use MockApi;

    /** @var array<int, array{0: string|false, 1: int, 2: int, 3: string}> responses send() returns, in order */
    private array $script = [];
    private int $sent = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->use_mock_api();
    }

    private function send(string $method, string $url, array $header_lines, array $args): array
    {
        return $this->script[$this->sent++];
    }

    private function example(): array
    {
        return ['[{"externalId":"store-1042","statusCode":1}]', 200, 0, ''];
    }

    public function test_retries_the_mocks_500_unable_to_mock_request(): void
    {
        $this->script = [
            ['{"error":{"name":"serverError","message":"Try sending it again.","header":"Unable to mock request"}}', 500, 0, ''],
            $this->example(),
        ];

        $response = $this->request('GET', 'https://mock.example/v1/tenants?externalId=store-1042', []);

        $this->assertSame(200, $response['response']['code']);
        $this->assertSame(2, $this->sent);
    }

    public function test_retries_the_mocks_400_unable_to_load_environments(): void
    {
        $this->script = [
            ['{"error":{"name":"syncError","message":"Please try again.","header":"Unable to load environments"}}', 400, 0, ''],
            $this->example(),
        ];

        $response = $this->request('GET', 'https://mock.example/v1/snapshots', []);

        $this->assertSame(200, $response['response']['code']);
    }

    public function test_retries_a_timeout(): void
    {
        $this->script = [[false, 0, CURLE_OPERATION_TIMEDOUT, 'timed out'], $this->example()];

        $this->assertSame(200, $this->request('GET', 'https://mock.example/v1/tenants', [])['response']['code']);
    }

    public function test_returns_an_api_error_example_as_is(): void
    {
        $this->script = [['{"statusCode":400,"message":"\'bad_domain!\' is not a valid domain name."}', 400, 0, '']];

        $response = $this->request('GET', 'https://mock.example/v1/tenants/domains/available?domain=bad_domain!', []);

        $this->assertSame(400, $response['response']['code']);
        $this->assertSame(1, $this->sent);
    }

    public function test_gives_up_after_three_attempts(): void
    {
        $failure = ['{"error":{"name":"serverError","header":"Unable to mock request"}}', 500, 0, ''];
        $this->script = [$failure, $failure, $failure, $this->example()];

        $this->assertSame(500, $this->request('GET', 'https://mock.example/v1/tenants', [])['response']['code']);
        $this->assertSame(3, $this->sent);
    }
}
