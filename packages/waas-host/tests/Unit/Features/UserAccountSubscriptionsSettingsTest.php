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
        Functions\when('get_option')->justReturn('');
        Functions\stubEscapeFunctions();
        Functions\stubTranslationFunctions();
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

    private function options(array $options): void
    {
        Functions\when('get_option')->alias(fn ($name, $default = false) => $options[$name] ?? $default);
    }

    private function dns_html(): string
    {
        Functions\stubEscapeFunctions();
        Functions\stubTranslationFunctions();
        ob_start();
        $this->settings->render_dns_instructions();

        return ob_get_clean();
    }

    public function test_dns_instructions_use_the_configured_targets(): void
    {
        $this->options([
            UserAccountSubscriptionsSettings::DNS_A_RECORDS_OPTION => '203.0.113.10, 203.0.113.11',
            UserAccountSubscriptionsSettings::DNS_CNAME_TARGET_OPTION => 'stores.otaku.example',
            'wpcs_credentials_region_setting' => 'eu1',
        ]);

        $this->assertSame(
            ['a_records' => ['203.0.113.10', '203.0.113.11'], 'cname' => 'stores.otaku.example'],
            UserAccountSubscriptionsSettings::dns_targets()
        );
        $html = $this->dns_html();
        $this->assertStringContainsString("<pre>203.0.113.10\n203.0.113.11</pre>", $html);
        $this->assertStringContainsString('<pre>stores.otaku.example</pre>', $html);
        $this->assertStringNotContainsString('wpcs.io', $html);
    }

    public function test_only_the_configured_record_type_is_shown(): void
    {
        $this->options([UserAccountSubscriptionsSettings::DNS_CNAME_TARGET_OPTION => 'stores.otaku.example']);

        $html = $this->dns_html();

        $this->assertStringContainsString('CNAME', $html);
        $this->assertStringNotContainsString('A records', $html);
    }

    public function test_without_settings_wpcs_stores_keep_their_region_values(): void
    {
        $this->options(['wpcs_credentials_region_setting' => 'US1']);

        $this->assertSame('public.us1.wpcs.io', UserAccountSubscriptionsSettings::dns_targets()['cname']);
        $this->assertCount(3, UserAccountSubscriptionsSettings::dns_targets()['a_records']);
    }

    public function test_without_settings_or_region_customers_are_told_to_contact_support(): void
    {
        $this->options([]);

        $html = $this->dns_html();

        $this->assertStringContainsString('contact support', $html);
        $this->assertStringNotContainsString('<pre>', $html);
    }

    public function test_ip_list_keeps_valid_unique_addresses(): void
    {
        $this->assertSame(
            ['203.0.113.10', '2001:db8::1', '203.0.113.11'],
            UserAccountSubscriptionsSettings::parse_ip_list("203.0.113.10,2001:db8::1\n 203.0.113.11 bogus 203.0.113.10 999.1.1.1")
        );
    }

    private function assertDomainUnchanged(): void
    {
        $this->assertSame('old.example.com', $this->cart->meta[self::SUBSCRIPTION_ID][WPCSTenant::WPCS_DOMAIN_NAME_META]);
    }
}
