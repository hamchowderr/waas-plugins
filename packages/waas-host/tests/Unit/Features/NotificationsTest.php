<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\Notifications;

class NotificationsTest extends TestCase
{
    private FakeCommerceAdapter $cart;

    protected function setUp(): void
    {
        parent::setUp();
        Commerce::reset();
        Commerce::register($this->cart = new FakeCommerceAdapter());
        $this->cart->meta[5] = [WPCSTenant::WPCS_DOMAIN_NAME_META => 'apex.sites.example'];
        Functions\when('get_bloginfo')->justReturn('Storefront');
        Functions\when('get_site_url')->justReturn('https://storefront.example');
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    public function test_the_login_link_goes_to_the_website_page_and_the_subscription_stays_secondary(): void
    {
        Filters\expectApplied('wpcs_website_page_url')
            ->once()
            ->with('https://storefront.example/account/subscriptions/5', 5)
            ->andReturn('https://storefront.example/account/website?redirect_to=x');

        $replaceables = Notifications::get_replaceables(5, 'owner@apexresearch.example');

        $this->assertSame('https://storefront.example/account/website?redirect_to=x', $replaceables['tenant_one_click_login_url']);
        $this->assertSame('https://storefront.example/account/subscriptions/5', $replaceables['storefront_subscription_details_url']);
        $this->assertSame('https://apex.sites.example', $replaceables['tenant_domain']);
    }

    public function test_without_a_website_page_the_login_link_is_the_subscription(): void
    {
        $replaceables = Notifications::get_replaceables(5, 'owner@apexresearch.example');

        $this->assertSame('https://storefront.example/account/subscriptions/5', $replaceables['tenant_one_click_login_url']);
    }

    public function test_the_default_email_links_both_pages(): void
    {
        $body = Notifications::get_default_tenant_ready_body();

        $this->assertStringContainsString('href="{{ tenant_one_click_login_url }}"', $body);
        $this->assertStringContainsString('href="{{ storefront_subscription_details_url }}"', $body);
    }
}
