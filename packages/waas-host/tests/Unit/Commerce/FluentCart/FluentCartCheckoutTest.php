<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartAdapter;
use WaaSHost\Commerce\FluentCart\FluentCartCheckout;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Core\Exceptions\InvalidDomainException;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;

class FluentCartCheckoutTest extends TestCase
{
    private const FIELD = Tenant::WEBSITE_NAME_META;

    private $api;
    private $store;
    private FluentCartCheckout $checkout;
    private object $plan_cart;
    private object $other_cart;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('sanitize_text_field')->alias('trim');
        Functions\when('remove_accents')->returnArg();
        Functions\when('sanitize_title_with_dashes')->alias(fn ($s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'));
        Functions\when('get_option')->alias(fn ($key, $default = '') => $key === 'wpcs_host_settings_root_domain' ? 'sites.example' : $default);

        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->store = Mockery::mock(FluentCartStore::class);
        $this->plan_cart = (object) ['cart_data' => [['post_id' => 101]]];
        $this->other_cart = (object) ['cart_data' => [['post_id' => 102]]];
        $this->store->allows('get_cart_product_ids')->with($this->plan_cart)->andReturn([101]);
        $this->store->allows('get_cart_product_ids')->with($this->other_cart)->andReturn([102]);
        $this->store->allows('get_plan_feed')->with(101)->andReturn(['enabled' => 'yes']);
        $this->store->allows('get_plan_feed')->with(102)->andReturnNull();
        $this->store->allows('find_subscription_ids_with_meta')->andReturn([])->byDefault();

        $this->checkout = new FluentCartCheckout($this->api, new FluentCartAdapter($this->store), $this->store);
    }

    private function validate(string $website_name, $cart = null): array
    {
        return $this->checkout->validate([], ['data' => [self::FIELD => $website_name], 'cart' => $cart ?? $this->plan_cart]);
    }

    public function test_field_is_added_only_for_a_website_plan(): void
    {
        $this->assertArrayHasKey(self::FIELD, $this->checkout->add_field([], ['cart' => $this->plan_cart]));
        $this->assertSame('yes', $this->checkout->add_field([], ['cart' => $this->plan_cart])[self::FIELD]['required']);
        $this->assertSame([], $this->checkout->add_field([], ['cart' => $this->other_cart]));
    }

    public function test_carts_without_a_plan_are_not_validated(): void
    {
        $this->api->expects('domain_available')->never();

        $this->assertSame([], $this->validate('', $this->other_cart));
    }

    public function test_name_is_required(): void
    {
        $this->assertArrayHasKey('required', $this->validate('  ')[self::FIELD]);
    }

    public function test_name_needs_letters_or_numbers(): void
    {
        $this->assertArrayHasKey('invalid', $this->validate('!!!')[self::FIELD]);
    }

    public function test_available_name_passes(): void
    {
        $this->api->expects('domain_available')->with('apex-research.sites.example')->andReturn(true);

        $this->assertSame([], $this->validate('Apex Research'));
    }

    public function test_taken_name_is_rejected(): void
    {
        $this->api->allows('domain_available')->andReturn(false);

        $this->assertArrayHasKey('taken', $this->validate('Apex Research')[self::FIELD]);
    }

    public function test_invalid_domain_is_rejected(): void
    {
        $this->api->allows('domain_available')->andThrow(new InvalidDomainException('bad'));

        $this->assertArrayHasKey('invalid', $this->validate('Apex Research')[self::FIELD]);
    }

    public function test_api_outage_accepts_the_name_and_marks_it_unverified(): void
    {
        // Chowderr's decision (waas-91g.20): fail open. It used to refuse with "try again in a minute".
        $previous = ini_set('error_log', '/dev/null');
        $this->api->allows('domain_available')->andThrow(new \Exception('Something went wrong connecting to the API'));

        $this->assertSame([], $this->validate('Apex Research'));
        ini_set('error_log', (string) $previous);

        $order = Mockery::mock();
        $order->expects('updateMeta')->with(self::FIELD, 'Apex Research');
        $order->expects('updateMeta')->with(Tenant::WEBSITE_NAME_UNVERIFIED_META, 'yes');
        $this->checkout->save(['order' => $order, 'cart' => $this->plan_cart, 'request_data' => [self::FIELD => 'Apex Research']]);
    }

    public function test_a_name_another_subscription_here_has_is_refused_without_asking_the_api(): void
    {
        $this->store->allows('find_subscription_ids_with_meta')->with(Tenant::DOMAIN_NAME_META, 'apex-research.sites.example')->andReturn([900]);
        $this->store->allows('get_subscription_meta')->with(900, Tenant::TENANT_STATE)->andReturn(Tenant::QUEUED);
        $this->api->expects('domain_available')->never();

        $this->assertArrayHasKey('taken', $this->validate('Apex Research')[self::FIELD]);
    }

    public function test_the_name_of_a_deleted_store_can_be_used_again(): void
    {
        $this->store->allows('find_subscription_ids_with_meta')->andReturn([900]);
        $this->store->allows('get_subscription_meta')->with(900, Tenant::TENANT_STATE)->andReturn(Tenant::DELETED);
        $this->api->expects('domain_available')->andReturn(true);

        $this->assertSame([], $this->validate('Apex Research'));
    }

    public function test_without_a_root_domain_the_api_picks_the_domain(): void
    {
        Functions\when('get_option')->justReturn('');
        $this->api->expects('domain_available')->never();

        $this->assertSame([], $this->validate('Apex Research'));
    }

    public function test_website_name_is_saved_on_the_order(): void
    {
        $order = Mockery::mock();
        $order->expects('updateMeta')->with(self::FIELD, 'Apex Research');

        $this->checkout->save(['order' => $order, 'cart' => $this->plan_cart, 'request_data' => [self::FIELD => ' Apex Research ']]);
    }

    public function test_nothing_is_saved_without_a_plan(): void
    {
        $order = Mockery::mock();
        $order->expects('updateMeta')->never();

        $this->checkout->save(['order' => $order, 'cart' => $this->other_cart, 'request_data' => [self::FIELD => 'Apex Research']]);
    }
}
