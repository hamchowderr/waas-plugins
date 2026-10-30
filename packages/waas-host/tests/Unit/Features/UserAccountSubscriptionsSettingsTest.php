<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Mockery;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\UserAccountSubscriptionsSettings;

class UserAccountSubscriptionsSettingsTest extends TestCase
{
    private const SUBSCRIPTION_ID = 42;
    private const OWNER_ID = 7;

    private $api;
    private UserAccountSubscriptionsSettings $settings;
    private FakeCommerceAdapter $cart;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('esc_url')->returnArg();
        Functions\when('wp_kses')->returnArg();
        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->settings = new UserAccountSubscriptionsSettings($this->api);

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('get_current_user_id')->justReturn(self::OWNER_ID);
        Functions\when('wp_verify_nonce')->alias(
            fn ($nonce, $action) => $nonce === 'good' && $action === 'waas_update_domain_' . self::SUBSCRIPTION_ID
                || $nonce === 'mail' && $action === 'waas_mail_domain_' . self::SUBSCRIPTION_ID ? 1 : false
        );
        Commerce::reset();
        $this->cart = new FakeCommerceAdapter();
        $this->cart->customer_id = self::OWNER_ID;
        $this->cart->meta[self::SUBSCRIPTION_ID] = [
            Tenant::TENANT_EXTERNAL_ID_META => 'ext-123',
            Tenant::DOMAIN_NAME_META => 'old.example.com',
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
        $_POST = ['domain_name' => 'Shop.Example.com', 'waas_domain_nonce' => 'good'];

        $this->api->expects('add_tenant_domain')
            ->with(['external_id' => 'ext-123', 'domain_name' => 'shop.example.com']);
        Functions\expect('wp_schedule_single_event')
            ->once()
            ->with(Mockery::type('int'), 'remove_tenant_old_domain', ['ext-123', 'old.example.com']);

        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $this->assertSame('shop.example.com', $this->cart->meta[self::SUBSCRIPTION_ID][Tenant::DOMAIN_NAME_META]);
    }

    public function test_missing_or_wrong_nonce_changes_nothing(): void
    {
        // A forged cross-site POST has no valid nonce.
        $this->api->shouldNotReceive('add_tenant_domain');

        $_POST = ['domain_name' => 'evil.example.com'];
        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $_POST = ['domain_name' => 'evil.example.com', 'waas_domain_nonce' => 'bad'];
        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $this->assertDomainUnchanged();
    }

    public function test_another_user_cannot_change_the_domain(): void
    {
        Functions\when('get_current_user_id')->justReturn(99);
        $_POST = ['domain_name' => 'evil.example.com', 'waas_domain_nonce' => 'good'];

        $this->api->shouldNotReceive('add_tenant_domain');

        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $this->assertDomainUnchanged();
    }

    public function test_no_cart_means_no_owner_and_no_change(): void
    {
        Commerce::reset();
        $_POST = ['domain_name' => 'shop.example.com', 'waas_domain_nonce' => 'good'];

        $this->api->shouldNotReceive('add_tenant_domain');

        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $this->assertDomainUnchanged();
    }

    public function test_attribute_injection_is_rejected_as_an_invalid_domain(): void
    {
        $_POST = ['domain_name' => "x' autofocus onfocus='alert(1)", 'waas_domain_nonce' => 'good'];

        $this->api->shouldNotReceive('add_tenant_domain');

        $this->settings->handle_update_subscription_domain(self::SUBSCRIPTION_ID);

        $this->assertDomainUnchanged();
    }

    public function test_unchanged_domain_is_a_no_op(): void
    {
        $_POST = ['domain_name' => 'old.example.com', 'waas_domain_nonce' => 'good'];

        $this->api->shouldNotReceive('add_tenant_domain');

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
        $this->cart->meta[self::SUBSCRIPTION_ID][Tenant::DOMAIN_NAME_META] = "x' onfocus='alert(1)";
        $this->stubDnsOptions([]);
        $this->api->allows('get_mail_domain')->andThrow(new \Exception('Failed: 404 - {}'));
        $this->api->allows('domain_connect')->andThrow(new \Exception('Failed: 404 - {}'));
        Functions\when('esc_attr')->alias(fn ($v) => htmlspecialchars($v, ENT_QUOTES));
        Functions\expect('wp_nonce_field')
            ->once()
            ->with('waas_update_domain_' . self::SUBSCRIPTION_ID, 'waas_domain_nonce', true, false)
            ->andReturn('<input type="hidden" name="waas_domain_nonce" value="n">');

        ob_start();
        $this->settings->render_edit_domain(self::SUBSCRIPTION_ID);
        $html = ob_get_clean();

        $this->assertStringContainsString('name="waas_domain_nonce"', $html);
        $this->assertStringContainsString('value="x&#039; onfocus=&#039;alert(1)"', $html);
        $this->assertStringNotContainsString("onfocus='alert(1)'", $html);
    }

    public function test_dns_instructions_show_the_configured_records(): void
    {
        $this->stubDnsOptions([
            'waas_dns_a_records' => '203.0.113.10, 203.0.113.11',
            'waas_dns_cname_target' => 'sites.example.net',
        ]);

        $html = $this->renderDnsInstructions();

        $this->assertStringContainsString('<b>A</b><code>203.0.113.10</code>', $html);
        $this->assertStringContainsString('<b>A</b><code>203.0.113.11</code>', $html);
        $this->assertStringContainsString('<b>CNAME</b><code>sites.example.net</code>', $html);
        $this->assertStringNotContainsString('wpcs.io', $html);
    }

    public function test_without_settings_customers_are_told_to_contact_support_even_with_a_leftover_region_setting(): void
    {
        $this->stubDnsOptions(['waas_region' => 'us1']);

        $html = $this->renderDnsInstructions();

        $this->assertStringContainsString('contact support', $html);
        $this->assertStringNotContainsString('<code>', $html);
        $this->assertStringNotContainsString('wpcs.io', $html);
        $this->assertStringNotContainsString('54.166.55.112', $html);
    }

    public function test_only_the_configured_record_is_shown(): void
    {
        $this->stubDnsOptions(['waas_dns_cname_target' => 'sites.example.net']);

        $html = $this->renderDnsInstructions();

        $this->assertStringContainsString('<b>CNAME</b><code>sites.example.net</code>', $html);
        $this->assertStringNotContainsString('A records', $html);
    }

    public function test_owner_sets_a_sending_domain(): void
    {
        $_POST = ['mail_domain_name' => ' Mail.Example.com ', 'waas_mail_nonce' => 'mail'];
        $this->api->expects('set_mail_domain')->with('ext-123', 'mail.example.com');

        $this->settings->handle_mail_domain(self::SUBSCRIPTION_ID);
    }

    public function test_owner_removes_the_sending_domain(): void
    {
        $_POST = ['mail_domain_remove' => '1', 'waas_mail_nonce' => 'mail'];
        $this->api->expects('remove_mail_domain')->with('ext-123');

        $this->settings->handle_mail_domain(self::SUBSCRIPTION_ID);
    }

    public function test_sending_domain_needs_the_mail_nonce_and_the_owner(): void
    {
        $this->api->shouldNotReceive('set_mail_domain');
        $this->api->shouldNotReceive('remove_mail_domain');

        $_POST = ['mail_domain_name' => 'evil.example.com'];
        $this->settings->handle_mail_domain(self::SUBSCRIPTION_ID);
        // The website domain form's nonce doesn't work for this form.
        $_POST = ['mail_domain_remove' => '1', 'waas_mail_nonce' => 'good'];
        $this->settings->handle_mail_domain(self::SUBSCRIPTION_ID);

        $this->cart->customer_id = 99;
        $_POST = ['mail_domain_name' => 'evil.example.com', 'waas_mail_nonce' => 'mail'];
        $this->settings->handle_mail_domain(self::SUBSCRIPTION_ID);
        $this->addToAssertionCount(1);
    }

    public function test_sending_domain_shows_the_api_reason_when_refused(): void
    {
        $_POST = ['mail_domain_name' => 'shop.anypress.io', 'waas_mail_nonce' => 'mail'];
        $this->api->expects('set_mail_domain')
            ->andThrow(new \Exception('Failed: 400 - {"statusCode":400,"message":"shop.anypress.io belongs to the platform; use a domain the store owns"}'));
        $this->api->allows('get_mail_domain')->andReturn(null);
        $this->stubMailRender();

        $html = $this->renderMailSender();

        $this->assertStringContainsString('<p class="woocommerce-error waas-acct-error">shop.anypress.io belongs to the platform; use a domain the store owns</p>', $html);
        $this->assertStringContainsString('name="mail_domain_name"', $html);
    }

    public function test_sending_domain_rejects_an_invalid_name(): void
    {
        $_POST = ['mail_domain_name' => "x' onfocus='y", 'waas_mail_nonce' => 'mail'];
        $this->api->shouldNotReceive('set_mail_domain');
        $this->api->allows('get_mail_domain')->andReturn(null);
        $this->stubMailRender();

        $this->assertStringContainsString('Please enter a valid domain name', $this->renderMailSender());
    }

    public function test_pending_sending_domain_shows_its_records_escaped(): void
    {
        $this->api->allows('get_mail_domain')->with('ext-123')->andReturn(json_decode(json_encode([
            'domainName' => 'mail.example.com',
            'status' => 'pending',
            'records' => [
                ['type' => 'TXT', 'name' => 'tosend._domainkey', 'host' => 'tosend._domainkey.mail.example.com', 'value' => 'p=<abc>', 'priority' => null],
                ['type' => 'MX', 'name' => 'send', 'host' => 'send.mail.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => '10'],
            ],
        ])));
        $this->api->allows('domain_connect')->with('ext-123', 'mail.example.com', 'email')
            ->andReturn((object) ['dnsProvider' => 'Example DNS', 'supported' => true, 'url' => 'https://connect.dns.example/apply?domain=example.com']);
        $this->stubMailRender();

        $html = $this->renderMailSender();

        $this->assertStringContainsString('href="https://connect.dns.example/apply?domain=example.com"', $html);
        $this->assertStringContainsString('Connect automatically at Example DNS', $html);
        $this->assertStringContainsString('no-reply@mail.example.com', $html);
        $this->assertStringContainsString('Waiting for DNS records', $html);
        $this->assertStringContainsString('<code>p=&lt;abc&gt;</code>', $html);
        $this->assertStringContainsString('<td>10</td>', $html);
        $this->assertStringContainsString('name="mail_domain_remove"', $html);
    }

    public function test_sending_domain_section_is_hidden_when_the_api_has_none_to_offer(): void
    {
        $this->api->allows('get_mail_domain')->andThrow(new \Exception('Failed: 404 - Not Found'));
        $this->stubMailRender();

        $this->assertSame('', $this->renderMailSender());
    }

    public function test_connect_button_only_when_the_dns_host_has_the_template(): void
    {
        $this->stubMailRender();
        $this->api->allows('domain_connect')->with('ext-123', 'shop.example.com', 'website')
            ->andReturn((object) ['dnsProvider' => 'Example DNS', 'supported' => false, 'url' => null]);
        ob_start();
        $this->settings->render_connect_button(self::SUBSCRIPTION_ID, 'shop.example.com', 'website');
        $this->assertSame('', ob_get_clean());
    }

    public function test_connect_button_ignores_a_link_that_is_not_https(): void
    {
        $this->stubMailRender();
        $this->api->allows('domain_connect')
            ->andReturn((object) ['dnsProvider' => 'x', 'supported' => true, 'url' => 'javascript:alert(1)']);
        ob_start();
        $this->settings->render_connect_button(self::SUBSCRIPTION_ID, 'shop.example.com', 'website');
        $this->assertSame('', ob_get_clean());
    }

    public function test_says_why_there_is_no_connect_button_when_the_api_gives_a_reason(): void
    {
        $this->stubMailRender();
        $this->api->allows('domain_connect')->andReturn((object) [
            'dnsProvider' => 'Cloudflare',
            'supported' => false,
            'url' => null,
            'conflict' => 'tosend._domainkey.mail.example.com already has a different email signing (DKIM) key. Replace it <by hand>.',
        ]);
        ob_start();
        $this->settings->render_connect_button(self::SUBSCRIPTION_ID, 'mail.example.com', 'email');
        $html = ob_get_clean();
        $this->assertStringContainsString('One-click setup is off for this domain.', $html);
        $this->assertStringContainsString('already has a different email signing (DKIM) key. Replace it &lt;by hand&gt;.', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    private function stubMailRender(): void
    {
        Functions\when('esc_url')->alias(fn ($v) => htmlspecialchars($v, ENT_QUOTES));
        Functions\when('esc_html')->alias(fn ($v) => htmlspecialchars($v, ENT_QUOTES));
        Functions\when('wp_nonce_field')->justReturn('<input type="hidden" name="waas_mail_nonce" value="n">');
    }

    private function renderMailSender(): string
    {
        ob_start();
        $this->settings->render_mail_sender(self::SUBSCRIPTION_ID);
        return ob_get_clean();
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
        $this->assertSame('old.example.com', $this->cart->meta[self::SUBSCRIPTION_ID][Tenant::DOMAIN_NAME_META]);
    }
}
