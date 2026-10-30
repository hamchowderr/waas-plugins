<?php

namespace Tests\Unit\Api;

use Brain\Monkey\Functions;
use Exception;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Api\AssistantSignIn;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;

class AssistantSignInTest extends TestCase
{
    private const ASSISTANT = 'https://assistant.example.com';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private FakeCommerceAdapter $cart;
    /** @var ConfigService&\Mockery\MockInterface */
    private $config;
    /** @var ProvisioningApi&\Mockery\MockInterface */
    private $api;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('add_action')->justReturn(true);
        Functions\when('__')->returnArg();
        Functions\when('wp_create_nonce')->justReturn('nonce1');
        Functions\when('get_rest_url')->justReturn('https://shop.example.com/wp-json/waas/v1');
        Functions\when('add_query_arg')->alias(fn (array $args, string $url) => $url . '?' . http_build_query($args));
        Commerce::reset();
        Commerce::register($this->cart = new FakeCommerceAdapter());
        $this->cart->meta[42][Tenant::TENANT_EXTERNAL_ID_META] = 'store-42';
        $this->cart->meta[42][Tenant::TENANT_STATE] = Tenant::READY;
        $this->config = Mockery::mock(ConfigService::class);
        $this->config->allows('get_assistant_url')->andReturn(self::ASSISTANT);
        $this->api = Mockery::mock(ProvisioningApi::class);
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    private function step(string $client_id = '', string $redirect_uri = '', string $state = ''): array
    {
        return (new AssistantSignIn($this->config, $this->api))->next_step(42, $client_id, $redirect_uri, self::CHALLENGE, $state);
    }

    public function test_the_button_first_sends_the_owner_to_the_assistant_with_the_way_back(): void
    {
        $step = $this->step();

        $this->assertStringStartsWith(self::ASSISTANT . '/from-shop?shop=', $step['redirect']);
        $back = rawurldecode(substr($step['redirect'], strlen(self::ASSISTANT . '/from-shop?shop=')));
        $this->assertSame('https://shop.example.com/wp-json/waas/v1/tenant/assistant?subscription_id=42&_wpnonce=nonce1', $back);
    }

    public function test_the_api_code_goes_back_to_the_assistant(): void
    {
        $next = self::ASSISTANT . '/callback?code=abc&state=s1&iss=https%3A%2F%2Fapi.example.com';
        $this->api->expects('site_sign_in')
            ->with('store-42', ['clientId' => 'client-1', 'redirectUri' => self::ASSISTANT . '/callback', 'codeChallenge' => self::CHALLENGE, 'state' => 's1'])
            ->andReturn($next);

        $this->assertSame(['redirect' => $next], $this->step('client-1', self::ASSISTANT . '/callback', 's1'));
    }

    public function test_never_signs_in_for_another_address(): void
    {
        $this->api->shouldNotReceive('site_sign_in');

        $step = $this->step('client-1', 'https://evil.example.com/callback');

        $this->assertSame(400, $step['status']);
    }

    public function test_an_api_answer_for_another_address_is_not_followed(): void
    {
        $this->api->allows('site_sign_in')->andReturn('https://evil.example.com/callback?code=abc');

        $this->assertSame(502, $this->step('client-1', self::ASSISTANT . '/callback')['status']);
    }

    public function test_an_unreachable_api_shows_a_plain_message(): void
    {
        $this->api->allows('site_sign_in')->andThrow(new Exception('Failed: timeout'));

        $this->assertSame(502, $this->step('client-1', self::ASSISTANT . '/callback')['status']);
    }

    public function test_not_offered_for_a_website_that_is_not_ready_or_without_the_setting(): void
    {
        $this->cart->meta[42][Tenant::TENANT_STATE] = Tenant::PROVISIONING;
        $this->assertSame(404, $this->step()['status']);

        $this->cart->meta[42][Tenant::TENANT_STATE] = Tenant::READY;
        $this->config = Mockery::mock(ConfigService::class);
        $this->config->allows('get_assistant_url')->andReturn('');
        $this->assertSame(404, $this->step()['status']);
    }
}
