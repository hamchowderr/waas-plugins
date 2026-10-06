<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartAdapter;
use WaaSHost\Commerce\FluentCart\FluentCartPortal;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\UserAccountSubscriptionsSettings;

class FluentCartPortalTest extends TestCase
{
    private const OWNER_ID = 7;

    private $store;
    private $domain_settings;
    private FluentCartPortal $portal;
    /** @var array<int, array<string, string>> */
    private array $meta;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('get_current_user_id')->justReturn(self::OWNER_ID);
        Functions\when('wp_get_current_user')->justReturn((object) ['user_email' => 'owner@apexresearch.example']);
        Functions\when('get_rest_url')->justReturn('https://storefront.example/wp-json/waas-host/v1');
        Functions\when('wp_create_nonce')->justReturn('nonce');

        $this->meta = [
            900 => [WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META => 'ext-900', WPCSTenant::WPCS_DOMAIN_NAME_META => 'apex.sites.example', WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::READY],
            901 => [WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META => 'ext-901', WPCSTenant::WPCS_BASE_DOMAIN_NAME_META => 'new.sites.example'],
            902 => [], // a subscription without a website
            903 => [WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META => 'ext-903'], // someone else's
        ];
        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_subscription_meta')->andReturnUsing(fn ($id, $key) => $this->meta[$id][$key] ?? '');
        $this->store->allows('get_subscription_customer')->andReturnUsing(fn ($id) => [
            'user_id' => $id === 903 ? 99 : self::OWNER_ID, 'email' => 'x@example.com', 'name' => 'X',
        ]);
        $this->store->allows('find_user_subscription_ids')->with(self::OWNER_ID, 'owner@apexresearch.example')->andReturn([900, 901, 902, 903]);
        $this->store->allows('get_portal_url')->with('website')->andReturn('https://storefront.example/account/website');

        $this->domain_settings = Mockery::mock(UserAccountSubscriptionsSettings::class);
        $this->portal = new FluentCartPortal(new FluentCartAdapter($this->store), $this->store, $this->domain_settings);
    }

    public function test_lists_only_the_users_own_websites(): void
    {
        $this->assertSame([900, 901], $this->portal->current_user_websites());
    }

    public function test_logged_out_visitors_see_nothing(): void
    {
        Functions\when('get_current_user_id')->justReturn(0);

        $this->assertSame([], $this->portal->current_user_websites());
    }

    public function test_renders_status_login_and_domain_form_for_each_website(): void
    {
        $this->domain_settings->expects('render_edit_domain')->with(900);
        $this->domain_settings->expects('render_edit_domain')->with(901);

        ob_start();
        $this->portal->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('apex.sites.example', $html);
        $this->assertStringContainsString('new.sites.example', $html);
        $this->assertStringContainsString(WPCSTenant::PROVISIONING, $html);
        $this->assertSame(1, substr_count($html, 'tenant/single_login?subscription_id='), 'login only for the ready website');
        $this->assertStringContainsString('subscription_id=900', $html);
    }

    public function test_empty_state_without_websites(): void
    {
        Functions\when('get_current_user_id')->justReturn(0);

        ob_start();
        $this->portal->render();

        $this->assertStringContainsString('no websites yet', ob_get_clean());
    }

    public function test_subscription_gets_login_and_manage_links(): void
    {
        $data = $this->portal->add_subscription_actions([], ['subscription' => (object) ['id' => 900]]);

        $this->assertSame(['wpcs_login', 'wpcs_manage'], array_column($data['custom_actions'], 'key'));
        $this->assertSame('https://storefront.example/account/website', $data['custom_actions'][1]['url']);
    }

    public function test_subscription_without_a_website_gets_no_links(): void
    {
        $this->assertSame([], $this->portal->add_subscription_actions([], ['subscription' => (object) ['id' => 902]]));
    }
}
