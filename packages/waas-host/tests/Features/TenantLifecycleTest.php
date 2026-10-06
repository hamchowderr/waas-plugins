<?php

namespace Tests\Features;

use PHPUnit\Framework\TestCase;
use Tests\FakeCommerceAdapter;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\HttpService;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\TenantsSubscriptionManager;
use WaaSHost\Features\WPCSTenantStatusService;
use WpStub;

/**
 * Store creation, polling and removal through a fake cart, against the provisioning
 * API (the Postman mock by default). Proves the shared code needs no WooCommerce.
 *
 * @group mock
 */
class TenantLifecycleTest extends TestCase
{
    private FakeCommerceAdapter $cart;
    private WPCSService $service;

    protected function setUp(): void
    {
        WpStub::reset();
        Commerce::reset();
        WpStub::$options['wpcs_credentials_api_base_url_setting'] = getenv('WAAS_TEST_API_BASE_URL');
        WpStub::$options['wpcs_credentials_api_key_setting'] = 'test-key';
        WpStub::$options['wpcs_credentials_api_secret_setting'] = 'test-secret';
        WpStub::$options['wpcs_host_settings_root_domain'] = 'stores.waas.example';
        WpStub::$options['wpcs_host_settings_default_user_role'] = 'administrator';

        $this->cart = new FakeCommerceAdapter();
        Commerce::register($this->cart);
        $this->service = new WPCSService(new HttpService(new ConfigService()));
        // Two plan products: the base plan and a product that isn't a plan.
        WpStub::$post_meta[101] = ['_is_wpcs_product' => 'yes', 'WPCS_PRODUCT_ROLE_META' => 'peptide-store', 'WPCS_PRODUCT_GROUPNAME_META' => 'peptide-starter'];
        WpStub::$post_meta[102] = [];
    }

    private function purchase(): PlanPurchase
    {
        return new PlanPurchase([101, 102], ' Apex Research ', 'owner@apexresearch.example');
    }

    public function test_creates_the_store_and_saves_it_on_the_subscription()
    {
        new TenantsSubscriptionManager($this->service);
        WpStub::$extra_headers['x-mock-response-name'] = 'Create tenant: Scheduled (API generated the externalId)';

        do_action('wpcs_subscription_created', 'sub-1', $this->purchase());

        $sent = json_decode(WpStub::$requests[0][3], true);
        $this->assertSame('Apex Research', $sent['name']);
        $this->assertSame('jordan_reyes', $sent['wordPressUserName']);
        $this->assertSame('owner@apexresearch.example', $sent['wordPressUserEmail']);
        $this->assertSame('apex-research.stores.waas.example', $sent['customDomainName']);
        $this->assertSame('peptide-starter', $sent['groupName']);
        $this->assertSame('peptide-store', $sent['phpConstants']['WPCS_TENANT_ROLES']['value']);

        $meta = $this->cart->meta['sub-1'];
        $this->assertSame('store-1042', $meta[WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META]);
        $this->assertSame('apex-research.stores.waas.example', $meta[WPCSTenant::WPCS_DOMAIN_NAME_META]);
        $this->assertSame('t-7c1e2a9b.stores.waas.example', $meta[WPCSTenant::WPCS_BASE_DOMAIN_NAME_META]);
        $this->assertSame(['peptide-store'], $meta[WPCSTenant::WPCS_SUBSCRIPTION_USER_ROLES]);
        $this->assertStringContainsString('PRIVATE KEY', $meta[WPCSTenant::WPCS_TENANT_PRIVATE_KEY_META]);
        $this->assertContains(['wpcs_tenant_linked_to_subscription', ['sub-1']], WpStub::$actions);
    }

    public function test_does_not_create_a_second_store_for_the_same_subscription()
    {
        new TenantsSubscriptionManager($this->service);
        $this->cart->meta['sub-1'][WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META] = 'store-1042';

        do_action('wpcs_subscription_created', 'sub-1', $this->purchase());

        $this->assertSame([], WpStub::$requests);
    }

    public function test_purchase_without_a_plan_product_creates_nothing()
    {
        new TenantsSubscriptionManager($this->service);

        do_action('wpcs_subscription_created', 'sub-1', new PlanPurchase([102], 'Apex Research', 'owner@apexresearch.example'));

        $this->assertSame([], WpStub::$requests);
    }

    public function test_poller_moves_a_ready_tenant_on_to_the_ssl_check()
    {
        new WPCSTenantStatusService($this->service);
        $this->cart->meta['sub-1'] = [
            WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::PROVISIONING,
            WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META => 'store-1042',
            WPCSTenant::WPCS_DOMAIN_NAME_META => 'apex-research.stores.waas.example',
        ];
        $this->cart->meta['sub-2'] = [WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::READY];
        WpStub::$extra_headers['x-mock-response-name'] = 'List tenants: Ready (statusCode 1, domain linked)';

        do_action('wpcs_cron_poll_tenant_status');

        // The example domain doesn't resolve, so the HEAD check fails and it waits there.
        $this->assertSame(WPCSTenant::REQUESTING_SSL, $this->cart->meta['sub-1'][WPCSTenant::WPCS_TENANT_STATE]);
        $this->assertSame(WPCSTenant::READY, $this->cart->meta['sub-2'][WPCSTenant::WPCS_TENANT_STATE]);
        $this->assertSame(['GET', 'GET', 'HEAD'], array_column(WpStub::$requests, 0));
    }

    public function test_poller_does_nothing_without_a_cart()
    {
        Commerce::reset();
        new WPCSTenantStatusService($this->service);

        do_action('wpcs_cron_poll_tenant_status');

        $this->assertSame([], WpStub::$requests);
    }

    public function test_cancelling_deletes_the_store()
    {
        new TenantsSubscriptionManager($this->service);
        $this->cart->meta['sub-1'][WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META] = 'store-1042';
        WpStub::$extra_headers['x-mock-response-name'] = 'Delete tenant: Scheduled';

        do_action('wpcs_subscription_expired', 'sub-1');

        $this->assertSame('DELETE', WpStub::$requests[0][0]);
        // Release 2.2.7 sends the external id as tenantId (finding H1, tracked as waas-91g.8).
        $this->assertStringEndsWith('/v1/tenants?tenantId=store-1042', WpStub::$requests[0][1]);
    }
}
