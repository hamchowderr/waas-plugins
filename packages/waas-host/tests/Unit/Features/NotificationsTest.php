<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\Notifications;

class NotificationsTest extends TestCase
{
    private FakeCommerceAdapter $cart;

    protected function setUp(): void
    {
        parent::setUp();
        Commerce::reset();
        Commerce::register($this->cart = new FakeCommerceAdapter());
        $this->cart->meta[5] = [Tenant::DOMAIN_NAME_META => 'apex.sites.example'];
        Functions\stubTranslationFunctions();
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
        Filters\expectApplied('waas_website_page_url')
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
    private function capture_mail(): array
    {
        $sent = [];
        Functions\when('get_option')->alias(fn ($name, $default = false) => $name === 'date_format' ? 'F j, Y' : $default);
        Functions\when('wp_date')->alias(fn ($format, $ts) => gmdate($format, $ts));
        Functions\when('absint')->alias(fn ($v) => abs((int) $v));
        Functions\when('wp_mail')->alias(function ($to, $subject, $body) use (&$sent) {
            $sent[] = compact('to', 'subject', 'body');
            return true;
        });

        Notifications::send_email_on_suspend_scheduled(5, gmmktime(16, 3, 29, 11, 7, 2026));

        return $sent;
    }

    public function test_a_cancel_tells_the_customer_until_when_the_website_stays(): void
    {
        $sent = $this->capture_mail();

        $this->assertCount(1, $sent);
        $this->assertSame('owner@apexresearch.example', $sent[0]['to']);
        $this->assertSame('Your website stays online until November 7, 2026', $sent[0]['subject']);
        $this->assertStringContainsString('<strong>November 7, 2026</strong>', $sent[0]['body']);
        $this->assertStringContainsString('deleted 30 days later', $sent[0]['body']);
        $this->assertStringContainsString('https://apex.sites.example', $sent[0]['body']);
        $this->assertStringNotContainsString('{{', $sent[0]['body']);
    }

    public function test_the_cancel_email_can_be_switched_off(): void
    {
        Filters\expectApplied('waas_suspend_scheduled_email_allowed')->andReturn(false);

        $this->assertSame([], $this->capture_mail());
    }
}
