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
        Functions\stubEscapeFunctions();
        Functions\when('get_theme_mod')->justReturn(0);
        Functions\when('get_site_icon_url')->justReturn('');
        Functions\when('is_email')->alias(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false);
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
        Functions\when('wp_mail')->alias(function ($to, $subject, $body, $headers) use (&$sent) {
            $sent[] = compact('to', 'subject', 'body', 'headers');
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

    private function capture_suspended_mail(int $delete_at): array
    {
        $sent = [];
        Functions\when('get_option')->alias(fn ($name, $default = false) => $name === 'date_format' ? 'F j, Y' : $default);
        Functions\when('wp_date')->alias(fn ($format, $ts) => gmdate($format, $ts));
        Functions\when('absint')->alias(fn ($v) => abs((int) $v));
        Functions\when('wp_mail')->alias(function ($to, $subject, $body) use (&$sent) {
            $sent[] = compact('to', 'subject', 'body');
            return true;
        });

        Notifications::send_email_on_suspended(5, $delete_at);

        return $sent;
    }

    public function test_an_ended_subscription_tells_the_customer_the_website_is_offline_and_until_when_it_is_kept(): void
    {
        $sent = $this->capture_suspended_mail(gmmktime(12, 0, 0, 11, 14, 2026));

        $this->assertCount(1, $sent);
        $this->assertSame('owner@apexresearch.example', $sent[0]['to']);
        $this->assertSame('Your website is offline', $sent[0]['subject']);
        $this->assertStringContainsString('https://apex.sites.example is now offline', $sent[0]['body']);
        $this->assertStringContainsString('<strong>November 14, 2026</strong>', $sent[0]['body']);
        $this->assertStringContainsString('href="https://storefront.example/account/subscriptions/5"', $sent[0]['body']);
        $this->assertStringNotContainsString('{{', $sent[0]['body']);
    }

    public function test_a_store_on_hold_has_no_deletion_date_and_gets_no_offline_email(): void
    {
        $this->assertSame([], $this->capture_suspended_mail(0));
    }

    public function test_the_offline_email_can_be_switched_off(): void
    {
        Filters\expectApplied('waas_suspended_email_allowed')->andReturn(false);

        $this->assertSame([], $this->capture_suspended_mail(gmmktime(12, 0, 0, 11, 14, 2026)));
    }

    public function test_the_email_is_sent_in_the_storefront_layout_with_its_subject_as_the_heading(): void
    {
        $sent = $this->capture_mail();
        $body = $sent[0]['body'];

        $this->assertStringStartsWith('<!doctype html>', $body);
        $this->assertMatchesRegularExpression('#<h1 [^>]*>Your website stays online until November 7, 2026</h1>#', $body);
        $this->assertMatchesRegularExpression('#<a style="display:inline-block[^"]*color:\#ffffff[^"]*" href="https://storefront.example/account/subscriptions/5">Manage your website</a>#', $body);
        $this->assertStringContainsString('Or paste this link into your browser:', $body);
        $this->assertStringContainsString('>Storefront</span>', $body);
        $this->assertStringNotContainsString('waas-button', $body);
        $this->assertStringNotContainsString('height:4px', $body, 'no coloured strip unless the storefront asks for one');
        $this->assertStringContainsString('Questions? Just reply to this email.', $body);
        $this->assertSame(['Content-Type: text/html; charset=UTF-8'], $sent[0]['headers']);
    }

    public function test_the_footer_and_reply_to_name_the_support_address(): void
    {
        Functions\when('get_option')->alias(fn ($name, $default = false) => $name === 'admin_email' ? 'support@storefront.example' : $default);

        $this->assertSame(['Content-Type: text/html; charset=UTF-8', 'Reply-To: support@storefront.example'], Notifications::headers());
        $this->assertStringContainsString('Write to <a href="mailto:support@storefront.example"', Notifications::wrap_html('<p>Hi</p>', 'Hello'));
    }

    public function test_the_logo_shows_when_the_storefront_has_one(): void
    {
        Functions\when('get_option')->justReturn('');
        Functions\when('get_site_icon_url')->justReturn('https://storefront.example/icon.png');

        $this->assertStringContainsString('<img src="https://storefront.example/icon.png"', Notifications::wrap_html('<p>Hi</p>', 'Hello'));
    }

    public function test_a_custom_body_that_is_a_whole_document_is_sent_as_it_is(): void
    {
        $custom = "<!DOCTYPE html>\n<html><body><p>My own design</p></body></html>";

        $this->assertSame($custom, Notifications::wrap_html($custom, 'Hello'));
    }

    public function test_a_saved_copy_of_an_old_default_gives_way_to_the_new_one(): void
    {
        $old = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD XHTML 1.0 Transitional //EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
        <html lang="en">
            <body>
                <p>Hello,</p>

                &nbsp;

                <p>Your subscription has ended, so your website {{ tenant_domain }} has been taken offline.</p>
                <p>We keep a backup of it until <strong>{{ delete_date }}</strong>; after that it is deleted. If you renew your subscription before then, your website comes back as it was.</p>

                &nbsp;

                <p><small>Your plan and billing are in your <a href="{{ storefront_subscription_details_url }}" target="_blank" rel="noopener">subscription details</a> at <a href="{{ storefront_url }}" target="_blank" rel="noopener">{{ storefront_name }}</a>.</small></p>

                &nbsp;

                <p>Regards,</p>
                <p>The <a href="{{ storefront_url }}" target="_blank" rel="noopener">{{ storefront_name }}</a> team</p>
            </body>
        </html>';

        $this->assertTrue(Notifications::is_old_default_body(str_replace("\n", "\r\n", $old)));
        $this->assertFalse(Notifications::is_old_default_body(Notifications::get_default_suspended_body()));
        $this->assertFalse(Notifications::is_old_default_body('<p>My own words</p>'));
    }

    public function test_a_storefront_can_add_its_own_coloured_strip(): void
    {
        \Brain\Monkey\Filters\expectApplied('waas_email_accent')->andReturn('#0a7d55');
        Functions\when('get_option')->justReturn('');

        $this->assertStringContainsString('height:4px;line-height:4px;font-size:0;background:#0a7d55', Notifications::wrap_html('<p>Hi</p>', 'Hello'));
    }
}
