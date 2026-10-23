<?php

namespace Tests\Unit\Commerce\WooCommerce;

use Brain\Monkey\Functions;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\WooCommerce\SubscriptionDetails;
use WaaSHost\Core\Tenant;

/** The store panel on WooCommerce's subscription page, in the customer's words. */
class SubscriptionDetailsTest extends TestCase
{
    private FakeCommerceAdapter $cart;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('WAAS_HOST_TEXTDOMAIN')) {
            define('WAAS_HOST_TEXTDOMAIN', 'waas-host');
        }
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('get_rest_url')->justReturn('https://storefront.example/wp-json/waas-host/v1');
        Functions\when('wp_create_nonce')->justReturn('nonce');
        Functions\when('remove_accents')->returnArg();
        Functions\when('sanitize_user')->returnArg();
        Functions\when('sanitize_title_with_dashes')->alias(fn ($v) => strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $v)));
        Commerce::reset();
        $this->cart = new FakeCommerceAdapter();
        Commerce::register($this->cart);
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    private function render(int $subscription_id): string
    {
        ob_start();
        SubscriptionDetails::render($subscription_id);

        return ob_get_clean();
    }

    public function test_a_website_being_set_up_shows_as_setting_up_without_a_login_link(): void
    {
        $this->cart->meta[5] = [Tenant::TENANT_STATE => Tenant::QUEUED];
        \Brain\Monkey\Actions\expectDone('waas_after_subscription_details_html')->never();

        $html = $this->render(5);

        $this->assertStringContainsString('Website status: Setting up your website', $html);
        $this->assertStringNotContainsString('single_login', $html);
    }

    public function test_a_failed_setup_shows_as_delayed(): void
    {
        $this->cart->meta[5] = [Tenant::TENANT_STATE => Tenant::FAILED];

        $this->assertStringContainsString('Website status: Setup delayed — we&#039;re on it', $this->render(5));
    }

    public function test_a_website_whose_name_was_taken_asks_the_customer_for_a_new_one(): void
    {
        $this->cart->meta[5] = [Tenant::TENANT_STATE => Tenant::NEEDS_NEW_NAME];
        Functions\when('admin_url')->alias(fn ($path = '') => 'https://storefront.example/wp-admin/' . $path);
        Functions\when('wp_nonce_field')->alias(function ($action) {
            echo '<input type="hidden" name="_wpnonce" data-action="' . $action . '" />';
        });

        $html = $this->render(5);

        $this->assertStringContainsString('Website status: Choose a new website name', $html);
        $this->assertStringContainsString('name="action" value="waas_choose_website_name"', $html);
        $this->assertStringContainsString('data-action="waas_choose_website_name_5"', $html);
        $this->assertStringContainsString('name="website_name"', $html);
        $this->assertStringNotContainsString('single_login', $html);
    }

    public function test_a_ready_website_keeps_its_state_and_login_link(): void
    {
        $this->cart->meta[5] = [Tenant::TENANT_EXTERNAL_ID_META => 'store-1042', Tenant::TENANT_STATE => Tenant::READY];
        \Brain\Monkey\Actions\expectDone('waas_after_subscription_details_html')->once()->with(5);

        $html = $this->render(5);

        $this->assertStringContainsString('Website status: Ready', $html);
        $this->assertStringContainsString('single_login?subscription_id=5', $html);
    }

    public function test_a_cancelled_website_shows_active_until_the_end_of_the_paid_period(): void
    {
        $this->cart->meta[5] = [
            Tenant::TENANT_EXTERNAL_ID_META => 'store-1042', Tenant::TENANT_STATE => Tenant::READY,
            Tenant::TENANT_SUSPEND_AT_META => gmmktime(16, 3, 29, 11, 7, 2026),
        ];
        Functions\when('get_option')->alias(fn ($name, $default = false) => $default);
        Functions\when('wp_date')->alias(fn ($format, $ts) => gmdate($format, $ts));

        $html = $this->render(5);

        $this->assertStringContainsString('Website status: Active until November 7, 2026', $html);
        $this->assertStringContainsString('Your subscription is cancelled. Your website stays online until then', $html);
        $this->assertStringContainsString('single_login?subscription_id=5', $html);
    }

    public function test_a_subscription_without_a_website_shows_nothing(): void
    {
        $this->cart->meta[5] = [];

        $this->assertSame('', $this->render(5));
    }

    public function test_a_refused_new_name_is_explained_although_the_panel_is_gone(): void
    {
        // The wait was paused (subscription cancelled) after the customer opened the page with the form.
        $this->cart->meta[5] = [Tenant::NAME_CONFLICT_META => ['name' => 'Apex Research', 'paused' => true], Tenant::TENANT_STATE => ''];
        $_GET = ['waas_website_name' => 'not_active', 'waas_website_name_for' => '5'];
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_key')->returnArg();

        try {
            $html = $this->render(5);
        } finally {
            $_GET = [];
        }

        $this->assertStringContainsString('This subscription is not active', $html);
        $this->assertStringNotContainsString('Website status', $html);
    }
}
