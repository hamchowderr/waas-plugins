<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use AnyPressHost\Commerce\Commerce;
use AnyPressHost\Commerce\FluentCart\FluentCartAdapter;
use AnyPressHost\Commerce\FluentCart\FluentCartCare;
use AnyPressHost\Commerce\FluentCart\FluentCartCareEmails;
use AnyPressHost\Commerce\FluentCart\FluentCartStore;
use AnyPressHost\Core\Tenant;

/** "Care is on" and "Care time running low". */
class FluentCartCareEmailsTest extends TestCase
{
    private const WEBSITE = 900;

    private $care;
    private array $meta = [];
    private array $sent = [];
    private array $summary = ['subscription_id' => 950, 'package' => 'Care Plus', 'answer' => '1 business day', 'priority' => 'medium', 'call_url' => 'https://shop.example/book/care-call', 'minutes' => 120, 'used' => 0, 'left' => 120, 'extra' => 0];
    private FluentCartCareEmails $emails;

    protected function setUp(): void
    {
        parent::setUp();
        Commerce::reset();
        $cart = new FakeCommerceAdapter();
        $cart->meta[self::WEBSITE] = [Tenant::DOMAIN_NAME_META => 'apex.sites.example'];
        Commerce::register($cart);
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('get_bloginfo')->justReturn('Storefront');
        Functions\when('get_site_url')->justReturn('https://storefront.example');
        Functions\when('get_theme_mod')->justReturn(0);
        Functions\when('get_site_icon_url')->justReturn('');
        Functions\when('get_option')->alias(fn ($name, $default = false) => $default);
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('UTC'));
        Functions\when('wp_date')->alias(fn ($format, $ts = null) => gmdate($format, $ts ?? time()));
        Functions\when('is_email')->alias(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false);
        Functions\when('wp_mail')->alias(function ($to, $subject, $body) {
            $this->sent[] = [$to, $subject, $body];
            return true;
        });

        $store = Mockery::mock(FluentCartStore::class);
        $store->allows('get_subscription_meta')->andReturnUsing(fn ($id, $key) => $this->meta[$id][$key] ?? '');
        $store->allows('update_subscription_meta')->andReturnUsing(function ($id, $key, $value) {
            $this->meta[$id][$key] = $value;
        });
        $store->allows('get_subscription_customer')->andReturn(['email' => 'owner@apexresearch.example']);
        $this->care = Mockery::mock(FluentCartCare::class);
        $this->care->allows('summary')->andReturnUsing(fn () => $this->summary);
        $this->emails = new FluentCartCareEmails(new FluentCartAdapter($store), $store, $this->care);
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    public function test_both_emails_are_in_the_emails_settings(): void
    {
        $emails = $this->emails->add_emails([]);

        $this->assertSame(['care_on', 'care_low', 'care_report'], array_keys($emails));
        $this->assertContains('care_left', $emails['care_low']['placeholders']);
    }

    public function test_care_is_on_tells_the_owner_their_package_time_and_answer_time(): void
    {
        $this->emails->send_on(950, self::WEBSITE);

        $this->assertCount(1, $this->sent);
        [$to, $subject, $body] = $this->sent[0];
        $this->assertSame('owner@apexresearch.example', $to);
        $this->assertSame('Care Plus is on for your website', $subject);
        $this->assertStringContainsString('2 h of changes each month, and we answer within 1 business day', $body);
        $this->assertStringContainsString('https://apex.sites.example', $body);
    }

    public function test_time_running_low_goes_out_once_a_month_at_a_quarter_left(): void
    {
        $this->summary['left'] = 40;
        $this->emails->maybe_send_low(self::WEBSITE);
        $this->assertCount(0, $this->sent, 'a third left is not low yet');

        $this->summary['left'] = 30;
        $this->emails->maybe_send_low(self::WEBSITE);
        $this->emails->maybe_send_low(self::WEBSITE);
        $this->assertCount(1, $this->sent);
        $this->assertSame('30 min of care time left this month', $this->sent[0][1]);
        $this->assertSame('2026-11', $this->meta[self::WEBSITE][FluentCartCareEmails::LOW_SENT_META]);
    }

    public function test_extra_time_alone_sends_nothing(): void
    {
        $this->summary = ['subscription_id' => 0, 'package' => '', 'answer' => '', 'priority' => 'normal', 'call_url' => '', 'minutes' => 0, 'used' => 0, 'left' => 0, 'extra' => 60];

        $this->emails->send_on(0, self::WEBSITE);
        $this->emails->maybe_send_low(self::WEBSITE);

        $this->assertSame([], $this->sent);
    }

    public function test_the_monthly_report_lists_last_months_work_once_for_websites_with_care_all_month(): void
    {
        $last = FluentCartCareEmails::last_month();
        $this->care->allows('websites_with_care')->andReturn([self::WEBSITE, 901]);
        $this->care->allows('month_log')->andReturn([
            ['at' => '', 'minutes' => 45, 'note' => 'Ticket #14: menu links', 'by' => 'Robin'],
            ['at' => '', 'minutes' => 50, 'note' => 'Ticket #12: Spring workshop page', 'by' => 'Robin'],
        ]);
        $this->meta[self::WEBSITE][FluentCartCare::SINCE_META] = $last;
        $this->meta[901][FluentCartCare::SINCE_META] = gmdate('Y-m'); // added this month: no report yet

        $this->assertSame(1, $this->emails->send_reports());
        $this->assertSame(0, $this->emails->send_reports(), 'one report a month');

        [$to, $subject, $body] = $this->sent[0];
        $this->assertSame('owner@apexresearch.example', $to);
        $this->assertSame('Your ' . gmdate('F', strtotime($last . '-01')) . ' care report', $subject);
        $this->assertStringContainsString('1 h 35 min of your 2 h', $body);
        $this->assertMatchesRegularExpression('/Spring workshop page \(50 min\).*menu links \(45 min\)/s', $body);
        $this->assertStringContainsString('https://shop.example/book/care-call', $body);
    }
}
