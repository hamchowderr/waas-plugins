<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\WooCommerce\WooCommerceAdapter;
use WaaSHost\Core\Exceptions\InvalidDomainException;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\UserWcTenantsCheckout;

class UserWcTenantsCheckoutTest extends TestCase
{
    private $wpcs_service;
    private $commerce;
    private UserWcTenantsCheckout $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg();
        Functions\when('remove_accents')->returnArg();
        Functions\when('sanitize_text_field')->alias('trim');
        Functions\when('sanitize_title_with_dashes')->alias(fn ($s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'));
        Functions\when('get_option')->justReturn('stores.example');

        $this->wpcs_service = Mockery::mock(WPCSService::class);
        $this->commerce = Mockery::mock(WooCommerceAdapter::class);
        $this->checkout = new UserWcTenantsCheckout($this->wpcs_service, $this->commerce);
    }

    public function test_free_name_is_accepted(): void
    {
        $this->commerce->expects('is_domain_taken')->with('apex-research.stores.example')->andReturn(false);
        $this->wpcs_service->expects('domain_available')->with('apex-research.stores.example')->andReturn(true);

        $this->assertNull($this->checkout->check_website_name('Apex Research'));
    }

    public function test_name_of_an_existing_store_is_rejected_without_calling_the_api(): void
    {
        $this->commerce->expects('is_domain_taken')->andReturn(true);
        $this->wpcs_service->expects('domain_available')->never();

        $this->assertStringContainsString('already taken', $this->checkout->check_website_name('Apex Research'));
    }

    public function test_name_the_api_reports_taken_or_invalid_is_rejected(): void
    {
        $this->commerce->allows('is_domain_taken')->andReturn(false);
        $this->wpcs_service->expects('domain_available')->andReturn(false, null);
        $this->assertStringContainsString('already taken', $this->checkout->check_website_name('Apex Research'));

        $this->wpcs_service->expects('domain_available')->andThrow(new InvalidDomainException('bad'));
        $this->assertStringContainsString('not valid', $this->checkout->check_website_name('Apex Research'));
    }

    public function test_unreachable_api_blocks_checkout_instead_of_crashing(): void
    {
        $previous = ini_set('error_log', '/dev/null');
        $this->commerce->allows('is_domain_taken')->andReturn(false);
        $this->wpcs_service->expects('domain_available')->andThrow(new \TypeError('network down'));

        $this->assertStringContainsString('try again', $this->checkout->check_website_name('Apex Research'));
        ini_set('error_log', (string) $previous);
    }

    public function test_names_that_make_no_dns_label_are_rejected(): void
    {
        $this->commerce->expects('is_domain_taken')->never();

        $this->assertNotNull($this->checkout->check_website_name('!!!'));
        $this->assertNotNull($this->checkout->check_website_name(str_repeat('a', 64)));
    }

    public function test_block_checkout_value_is_copied_to_the_order_meta(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->expects('update_meta_data')->with(WPCSTenant::WPCS_WEBSITE_NAME_META, 'Apex Research');
        $customer = Mockery::mock('WC_Customer');
        $customer->expects('update_meta_data')->never();

        $this->checkout->save_block_checkout_field(UserWcTenantsCheckout::FIELD_ID, ' Apex Research ', 'other', $order);
        $this->checkout->save_block_checkout_field(UserWcTenantsCheckout::FIELD_ID, 'Apex Research', 'other', $customer);
        $this->checkout->save_block_checkout_field('someone-else/field', 'x', 'other', $order);
    }

    public function test_classic_checkout_saves_through_the_order(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->expects('update_meta_data')->with(WPCSTenant::WPCS_WEBSITE_NAME_META, 'Apex Research');
        Functions\expect('update_post_meta')->never();

        $this->checkout->save_classic_checkout_field($order, [WPCSTenant::WPCS_WEBSITE_NAME_META => 'Apex Research']);
        $this->checkout->save_classic_checkout_field($order, ['billing_email' => 'x@example.com']);
    }
}
