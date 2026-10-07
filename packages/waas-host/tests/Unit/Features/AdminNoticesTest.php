<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSHost\Core\ConfigService;
use WaaSHost\Features\AdminNotices;

class AdminNoticesTest extends TestCase
{
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];
        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('admin_url')->alias(fn ($path) => 'https://store.example/wp-admin/' . $path);
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();
        AdminNotices::init(new ConfigService());
    }

    public function test_notice_names_the_missing_dns_settings(): void
    {
        $html = $this->renderNotice();

        $this->assertStringContainsString('A record IPs, CNAME target', $html);
        $this->assertStringContainsString('admin.php?page=wpcs-admin-settings', $html);
    }

    public function test_notice_names_only_what_is_missing(): void
    {
        $this->options['wpcs_dns_a_records_setting'] = '203.0.113.10';

        $html = $this->renderNotice();

        $this->assertStringContainsString('missing: CNAME target.', $html);
    }

    public function test_no_notice_when_both_are_set(): void
    {
        $this->options['wpcs_dns_a_records_setting'] = '203.0.113.10';
        $this->options['wpcs_dns_cname_target_setting'] = 'sites.example.net';

        $this->assertSame('', $this->renderNotice());
    }

    public function test_no_notice_for_users_who_cannot_change_settings(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->assertSame('', $this->renderNotice());
    }

    private function renderNotice(): string
    {
        ob_start();
        AdminNotices::show_dns_settings_missing_notice();
        return ob_get_clean();
    }
}
