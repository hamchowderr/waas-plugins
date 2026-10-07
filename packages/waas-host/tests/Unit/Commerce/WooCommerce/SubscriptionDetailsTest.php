<?php

namespace Tests\Unit\Commerce\WooCommerce;

use Brain\Monkey\Functions;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\WooCommerce\SubscriptionDetails;
use WaaSHost\Core\WPCSTenant;

/** The store panel on WooCommerce's subscription page, in the customer's words. */
class SubscriptionDetailsTest extends TestCase
{
    private FakeCommerceAdapter $cart;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('WPCS_WAAS_HOST_TEXTDOMAIN')) {
            define('WPCS_WAAS_HOST_TEXTDOMAIN', 'wpcs-waas-host');
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
        $this->cart->meta[5] = [WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::QUEUED];
        \Brain\Monkey\Actions\expectDone('wpcs_after_subscription_details_html')->never();

        $html = $this->render(5);

        $this->assertStringContainsString('Website status: Setting up your website', $html);
        $this->assertStringNotContainsString('single_login', $html);
    }

    public function test_a_failed_setup_shows_as_delayed(): void
    {
        $this->cart->meta[5] = [WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::FAILED];

        $this->assertStringContainsString('Website status: Setup delayed — we&#039;re on it', $this->render(5));
    }

    public function test_a_ready_website_keeps_its_state_and_login_link(): void
    {
        $this->cart->meta[5] = [WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META => 'store-1042', WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::READY];
        \Brain\Monkey\Actions\expectDone('wpcs_after_subscription_details_html')->once()->with(5);

        $html = $this->render(5);

        $this->assertStringContainsString('Website status: Ready', $html);
        $this->assertStringContainsString('single_login?subscription_id=5', $html);
    }

    public function test_a_subscription_without_a_website_shows_nothing(): void
    {
        $this->cart->meta[5] = [];

        $this->assertSame('', $this->render(5));
    }
}
