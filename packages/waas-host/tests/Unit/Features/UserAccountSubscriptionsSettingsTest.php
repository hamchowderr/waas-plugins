<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\UserAccountSubscriptionsSettings;

class UserAccountSubscriptionsSettingsTest extends TestCase
{
    private const SUBSCRIPTION_ID = 42;
    private const OWNER_ID = 7;

    private $wpcs;
    private UserAccountSubscriptionsSettings $settings;
    private FakeCommerceAdapter $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpcs = Mockery::mock(WPCSService::class);
        $this->settings = new UserAccountSubscriptionsSettings($this->wpcs);

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('get_current_user_id')->justReturn(self::OWNER_ID);
        Functions\when('wp_verify_nonce')->alias(
            fn ($nonce, $action) => $nonce === 'good' && $action === 'wpcs_update_domain_' . self::SUBSCRIPTION_ID ? 1 : false
        );
        Commerce::reset();
        $this->cart = new FakeCommerceAdapter();
        $this->cart->customer_id = self::OWNER_ID;
        $this->cart->meta[self::SUBSCRIPTION_ID] = [
            WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META => 'ext-123',
            WPCSTenant::WPCS_DOMAIN_NAME_META => 'old.example.com',
        ];
        Commerce::register($this->cart);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        Commerce::reset();
        parent::tearDown();
    }

    public function test_owner_with_valid_nonce_changes_the_domain(): void
    {
        $_POST = ['domain_name' => 'Shop.Example.com', 'wpcs_domain_nonce' => 'good'];

        $this->wpcs->expects('add_tenant_domain')
            ->with(['external_id' => 'ext-123', 'domain_name' => 'shop.example.com']);
        Functions\expect('wp_schedule_single_event')
            ->once()
            ->with(Mockery::type('int'), 'remove_tenant_old_domain', ['ext-123', 'old.example.com']);

        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $this->assertSame('shop.example.com', $this->cart->meta[self::SUBSCRIPTION_ID][WPCSTenant::WPCS_DOMAIN_NAME_META]);
    }

    public function test_missing_or_wrong_nonce_changes_nothing(): void
    {
        // A forged cross-site POST has no valid nonce.
        $this->wpcs->shouldNotReceive('add_tenant_domain');

        $_POST = ['domain_name' => 'evil.example.com'];
        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $_POST = ['domain_name' => 'evil.example.com', 'wpcs_domain_nonce' => 'bad'];
        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $this->assertDomainUnchanged();
    }

    public function test_another_user_cannot_change_the_domain(): void
    {
        Functions\when('get_current_user_id')->justReturn(99);
        $_POST = ['domain_name' => 'evil.example.com', 'wpcs_domain_nonce' => 'good'];

        $this->wpcs->shouldNotReceive('add_tenant_domain');

        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $this->assertDomainUnchanged();
    }

    public function test_no_cart_means_no_owner_and_no_change(): void
    {
        Commerce::reset();
        $_POST = ['domain_name' => 'shop.example.com', 'wpcs_domain_nonce' => 'good'];

        $this->wpcs->shouldNotReceive('add_tenant_domain');

        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $this->assertDomainUnchanged();
    }

    public function test_attribute_injection_is_rejected_as_an_invalid_domain(): void
    {
        $_POST = ['domain_name' => "x' autofocus onfocus='alert(1)", 'wpcs_domain_nonce' => 'good'];

        $this->wpcs->shouldNotReceive('add_tenant_domain');

        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $this->assertDomainUnchanged();
    }

    public function test_unchanged_domain_is_a_no_op(): void
    {
        $_POST = ['domain_name' => 'old.example.com', 'wpcs_domain_nonce' => 'good'];

        $this->wpcs->shouldNotReceive('add_tenant_domain');

        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);
    }

    /**
     * @dataProvider hostnames
     */
    public function test_is_valid_hostname(string $domain, bool $valid): void
    {
        $this->assertSame($valid, UserAccountSubscriptionsSettings::is_valid_hostname($domain));
    }

    public function hostnames(): array
    {
        return [
            'apex' => ['example.com', true],
            'subdomain' => ['shop.example.co.uk', true],
            'hyphen' => ['my-shop.example.com', true],
            'no dot' => ['localhost', false],
            'empty' => ['', false],
            'quote' => ["example.com' onfocus='x", false],
            'space' => ['exa mple.com', false],
            'scheme' => ['https://example.com', false],
            'path' => ['example.com/path', false],
            'too long' => [str_repeat('a.', 127) . 'com', false],
        ];
    }

    public function test_render_escapes_the_stored_domain_and_includes_the_nonce(): void
    {
        $this->cart->meta[self::SUBSCRIPTION_ID][WPCSTenant::WPCS_DOMAIN_NAME_META] = "x' onfocus='alert(1)";
        $this->stubDnsOptions([]);
        Functions\when('esc_attr')->alias(fn ($v) => htmlspecialchars($v, ENT_QUOTES));
        Functions\expect('wp_nonce_field')
            ->once()
            ->with('wpcs_update_domain_' . self::SUBSCRIPTION_ID, 'wpcs_domain_nonce', true, false)
            ->andReturn('<input type="hidden" name="wpcs_domain_nonce" value="n">');

        ob_start();
        $this->settings->render_edit_domain(self::SUBSCRIPTION_ID);
        $html = ob_get_clean();

        $this->assertStringContainsString('name="wpcs_domain_nonce"', $html);
        $this->assertStringContainsString("value='x&#039; onfocus=&#039;alert(1)'", $html);
        $this->assertStringNotContainsString("onfocus='alert(1)'", $html);
    }

    public function test_dns_instructions_show_the_configured_records(): void
    {
        $this->stubDnsOptions([
            'wpcs_dns_a_records_setting' => '203.0.113.10, 203.0.113.11',
            'wpcs_dns_cname_target_setting' => 'sites.example.net',
        ]);

        $html = $this->renderDnsInstructions();

        $this->assertStringContainsString("<pre>203.0.113.10\n203.0.113.11</pre>", $html);
        $this->assertStringContainsString('<pre>sites.example.net</pre>', $html);
        $this->assertStringNotContainsString('wpcs.io', $html);
    }

    public function test_without_settings_customers_are_told_to_contact_support_even_on_a_wpcs_region(): void
    {
        $this->stubDnsOptions(['wpcs_credentials_region_setting' => 'us1']);

        $html = $this->renderDnsInstructions();

        $this->assertStringContainsString('contact support', $html);
        $this->assertStringNotContainsString('<pre>', $html);
        $this->assertStringNotContainsString('wpcs.io', $html);
        $this->assertStringNotContainsString('54.166.55.112', $html);
    }

    public function test_only_the_configured_record_is_shown(): void
    {
        $this->stubDnsOptions(['wpcs_dns_cname_target_setting' => 'sites.example.net']);

        $html = $this->renderDnsInstructions();

        $this->assertStringContainsString('<pre>sites.example.net</pre>', $html);
        $this->assertStringNotContainsString('A records', $html);
    }

    private function stubDnsOptions(array $options): void
    {
        Functions\when('get_option')->alias(fn ($name, $default = false) => $options[$name] ?? $default);
        Functions\when('esc_html')->alias(fn ($v) => htmlspecialchars($v, ENT_QUOTES));
    }

    private function renderDnsInstructions(): string
    {
        ob_start();
        $this->settings->render_dns_instructions();
        return ob_get_clean();
    }

    private function assertDomainUnchanged(): void
    {
        $this->assertSame('old.example.com', $this->cart->meta[self::SUBSCRIPTION_ID][WPCSTenant::WPCS_DOMAIN_NAME_META]);
    }
}
