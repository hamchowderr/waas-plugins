<?php

namespace Tests\Unit\Commerce\FluentCart;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\FluentCart\FluentCartAdapter;
use WaaSHost\Commerce\FluentCart\FluentCartPortal;
use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Core\Tenant;
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
        Functions\when('get_userdata')->alias(fn ($id) => $id === self::OWNER_ID ? (object) ['ID' => $id, 'user_email' => 'owner@apexresearch.example'] : false);
        Functions\when('get_rest_url')->justReturn('https://storefront.example/wp-json/waas-host/v1');
        Functions\when('wp_create_nonce')->justReturn('nonce');

        $this->meta = [
            900 => [Tenant::TENANT_EXTERNAL_ID_META => 'ext-900', Tenant::DOMAIN_NAME_META => 'apex.sites.example', Tenant::TENANT_STATE => Tenant::READY],
            901 => [Tenant::TENANT_EXTERNAL_ID_META => 'ext-901', Tenant::BASE_DOMAIN_NAME_META => 'new.sites.example'],
            902 => [], // a subscription without a website
            903 => [Tenant::TENANT_EXTERNAL_ID_META => 'ext-903'], // someone else's
        ];
        $this->store = Mockery::mock(FluentCartStore::class);
        $this->store->allows('get_subscription_meta')->andReturnUsing(fn ($id, $key) => $this->meta[$id][$key] ?? '');
        $this->store->allows('get_subscription_customer')->andReturnUsing(fn ($id) => [
            'user_id' => $id === 903 ? 99 : self::OWNER_ID, 'email' => 'x@example.com', 'name' => 'X',
        ]);
        $this->store->allows('find_user_subscription_ids')->with(self::OWNER_ID, 'owner@apexresearch.example')->andReturn([900, 901, 902, 903]);
        $this->store->allows('get_portal_url')->with('website')->andReturn('https://storefront.example/account/website');
        $this->store->allows('get_subscription_url')->andReturnUsing(fn ($id) => "https://storefront.example/account/subscription/sub-$id");
        Functions\when('get_option')->alias(fn ($name, $default = false) => $name === 'date_format' ? 'F j, Y' : $default);
        Functions\when('wp_date')->alias(fn ($format, $ts) => gmdate($format, $ts));

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
        $this->assertStringContainsString(Tenant::PROVISIONING, $html);
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

        $this->assertSame(['waas_login', 'waas_manage'], array_column($data['custom_actions'], 'key'));
        $this->assertSame('https://storefront.example/account/website', $data['custom_actions'][1]['url']);
    }

    public function test_subscription_without_a_website_gets_no_links(): void
    {
        $this->assertSame([], $this->portal->add_subscription_actions([], ['subscription' => (object) ['id' => 902]]));
    }

    public function test_a_website_being_set_up_shows_with_the_customer_wording_and_no_domain_form(): void
    {
        $this->meta[902] = [Tenant::TENANT_STATE => Tenant::QUEUED];
        $this->domain_settings->allows('render_edit_domain')->with(900);
        $this->domain_settings->allows('render_edit_domain')->with(901);
        $this->domain_settings->expects('render_edit_domain')->with(902)->never();

        ob_start();
        $this->portal->render();
        $html = ob_get_clean();

        $this->assertSame([900, 901, 902], $this->portal->current_user_websites());
        $this->assertStringContainsString('Your website', $html);
        $this->assertStringContainsString('Status: Setting up your website', $html);
        $this->assertStringNotContainsString('Status: Queued', $html);
    }

    public function test_a_failed_setup_shows_as_delayed(): void
    {
        $this->meta[900][Tenant::TENANT_STATE] = Tenant::FAILED;
        $this->domain_settings->allows('render_edit_domain');

        ob_start();
        $this->portal->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('Status: Setup delayed — we&#039;re on it', $html);
        $this->assertStringNotContainsString('Status: Failed', $html);
    }

    public function test_a_website_whose_name_was_taken_shows_the_new_name_form(): void
    {
        $this->meta[902] = [Tenant::TENANT_STATE => Tenant::NEEDS_NEW_NAME];
        $this->domain_settings->allows('render_edit_domain')->with(900);
        $this->domain_settings->allows('render_edit_domain')->with(901);
        $this->domain_settings->expects('render_edit_domain')->with(902)->never();
        Functions\when('admin_url')->alias(fn ($path = '') => 'https://storefront.example/wp-admin/' . $path);
        Functions\when('wp_nonce_field')->alias(function ($action) {
            echo '<input type="hidden" name="_wpnonce" data-action="' . $action . '" />';
        });

        ob_start();
        $this->portal->render();
        $html = ob_get_clean();

        $this->assertSame([900, 901, 902], $this->portal->current_user_websites());
        $this->assertStringContainsString('Status: Choose a new website name', $html);
        $this->assertStringContainsString('data-action="wpcs_choose_website_name_902"', $html);
        $this->assertSame(1, substr_count($html, 'waas-choose-website-name"'));
    }

    public function test_the_new_name_link_goes_to_the_website_page_also_after_logging_in(): void
    {
        Functions\when('add_query_arg')->alias(fn ($key, $value, $url) => $url . '?' . $key . '=' . $value);

        $this->assertSame('https://storefront.example/account/website?redirect_to=https%3A%2F%2Fstorefront.example%2Faccount%2Fwebsite', $this->portal->website_page_url());
    }

    private function render_after_result(string $code, string $for): string
    {
        $_GET = ['wpcs_website_name' => $code, 'wpcs_website_name_for' => $for];
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('admin_url')->alias(fn ($path = '') => 'https://storefront.example/wp-admin/' . $path);
        Functions\when('wp_nonce_field')->justReturn('');
        $this->domain_settings->allows('render_edit_domain');
        try {
            ob_start();
            $this->portal->render();
            return ob_get_clean();
        } finally {
            $_GET = [];
        }
    }

    public function test_a_refused_name_for_a_website_no_longer_listed_is_still_explained(): void
    {
        // 902 waited for a name; its subscription was cancelled before the customer submitted the open form.
        $html = $this->render_after_result('not_active', '902');

        $this->assertStringContainsString('This subscription is not active', $html);
        $this->assertSame([900, 901], $this->portal->current_user_websites());
        $this->assertStringNotContainsString('not active', $this->render_after_result('not_active', '903'), "not for someone else's subscription");
    }

    public function test_with_several_websites_the_result_shows_at_its_own_one_only(): void
    {
        $this->meta[901][Tenant::TENANT_STATE] = Tenant::NEEDS_NEW_NAME;
        $this->meta[902] = [Tenant::TENANT_STATE => Tenant::NEEDS_NEW_NAME];

        $html = $this->render_after_result('taken', '902');

        $this->assertSame(1, substr_count($html, 'already taken'));
        $sections = explode('<section', $html);
        $this->assertStringContainsString('already taken', end($sections), "902's section is the last one");
        $this->assertSame(2, substr_count($html, 'waas-choose-website-name"'));
    }
    public function test_the_tab_is_called_my_website_and_has_an_icon(): void
    {
        $api = Mockery::mock();
        $api->expects('addCustomerDashboardEndpoint')->with('website', Mockery::on(function ($args) {
            return $args['title'] === 'My website' && strpos($args['icon_svg'], '<svg') === 0 && is_callable($args['render_callback']);
        }));
        Functions\when('fluent_cart_api')->justReturn($api);

        $this->portal->register_endpoint();
    }

    public function test_the_tab_moves_right_after_dashboard(): void
    {
        $items = ['dashboard' => ['label' => 'Dashboard'], 'purchase-history' => [], 'subscriptions' => [], 'website' => ['label' => 'My website'], 'profile' => []];

        $moved = $this->portal->move_menu_item_after_dashboard($items);

        $this->assertSame(['dashboard', 'website', 'purchase-history', 'subscriptions', 'profile'], array_keys($moved));
        $this->assertSame(['label' => 'My website'], $moved['website']);
    }

    public function test_the_menu_is_left_alone_without_dashboard_or_the_tab(): void
    {
        $this->assertSame(['website' => [], 'profile' => []], $this->portal->move_menu_item_after_dashboard(['website' => [], 'profile' => []]));
        $this->assertSame(['dashboard' => [], 'profile' => []], $this->portal->move_menu_item_after_dashboard(['dashboard' => [], 'profile' => []]));
    }

    public function test_a_ready_website_gets_a_notice_with_login_and_manage_links(): void
    {
        ob_start();
        $this->portal->render_ready_notice();
        $html = ob_get_clean();

        $this->assertSame(1, substr_count($html, 'waas-website-ready-notice'), 'only 900 is ready');
        $this->assertStringContainsString('apex.sites.example', $html);
        $this->assertStringContainsString('tenant/single_login?subscription_id=900', $html);
        $this->assertStringContainsString('https://storefront.example/account/website', $html);
    }

    public function test_no_notice_on_the_website_tab_itself(): void
    {
        Filters\expectApplied('fluent_cart/customer_portal/active_tab')->andReturn('website');

        ob_start();
        $this->portal->render_ready_notice();

        $this->assertSame('', ob_get_clean());
    }

    public function test_no_notice_for_logged_out_visitors(): void
    {
        Functions\when('get_current_user_id')->justReturn(0);

        ob_start();
        $this->portal->render_ready_notice();

        $this->assertSame('', ob_get_clean());
    }
    public function test_each_website_links_to_managing_or_cancelling_its_plan(): void
    {
        $this->domain_settings->allows('render_edit_domain');

        ob_start();
        $this->portal->render();
        $html = ob_get_clean();

        $this->assertSame(2, substr_count($html, 'Manage or cancel plan'));
        $this->assertStringContainsString('https://storefront.example/account/subscription/sub-900', $html);
    }

    public function test_a_cancelled_website_shows_active_until_the_end_of_the_paid_period(): void
    {
        $this->meta[900][Tenant::TENANT_SUSPEND_AT_META] = gmmktime(16, 3, 29, 11, 7, 2026);
        $this->domain_settings->allows('render_edit_domain');

        ob_start();
        $this->portal->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('Status: Active until November 7, 2026', $html);
        $this->assertStringContainsString('deleted 30 days later', $html);
        $this->assertStringContainsString('tenant/single_login?subscription_id=900', $html, 'login stays until then');

        ob_start();
        $this->portal->render_ready_notice();
        $this->assertStringContainsString('is active until November 7, 2026', ob_get_clean());
    }
}
