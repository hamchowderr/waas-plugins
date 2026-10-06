<?php

namespace Tests\Contract;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Tests\FakeCommerceAdapter;
use Tests\Unit\TestCase;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Commerce\PlanProduct;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\HttpService;
use WaaSHost\Core\PrivateKeyStore;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\TenantsSubscriptionManager;
use WaaSHost\Features\WPCSTenantStatusService;

/**
 * Store creation, polling and removal through a fake cart, against the provisioning API (see MockApi).
 * Proves the shared code needs no WooCommerce.
 *
 * @group mock
 */
class TenantLifecycleTest extends TestCase
{
    use MockApi;

    private FakeCommerceAdapter $cart;
    private WPCSService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Commerce::reset();
        $this->use_mock_api([
            'wpcs_host_settings_root_domain' => 'stores.waas.example',
            'wpcs_host_settings_default_user_role' => 'administrator',
        ]);

        Functions\when('sanitize_text_field')->alias(fn ($value) => trim(strip_tags((string) $value)));
        Functions\when('remove_accents')->returnArg();
        Functions\when('sanitize_title_with_dashes')->alias(fn ($value) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $value)), '-'));
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('wp_next_scheduled')->justReturn(true);

        $this->cart = new FakeCommerceAdapter();
        Commerce::register($this->cart);
        $this->service = new WPCSService(new HttpService(new ConfigService()));
    }

    protected function tearDown(): void
    {
        Commerce::reset();
        parent::tearDown();
    }

    private function purchase(): PlanPurchase
    {
        // The adapter has already left out the non-plan product on the order.
        return new PlanPurchase([new PlanProduct(101, 'peptide-store', 'peptide-starter')], ' Apex Research ', 'owner@apexresearch.example');
    }

    public function test_creates_the_store_and_saves_it_on_the_subscription(): void
    {
        $manager = new TenantsSubscriptionManager($this->service);
        $this->mock_example('Create tenant: Scheduled (API generated the externalId)');
        Actions\expectDone('wpcs_tenant_linked_to_subscription')->once()->with('sub-1');

        $manager->create_tenant_when_subscription_created('sub-1', $this->purchase());

        $sent = json_decode($this->requests[0][3], true);
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
        // The private key is stored encrypted, in the cart's subscription meta.
        $this->assertStringStartsWith(PrivateKeyStore::PREFIX, $meta[WPCSTenant::WPCS_TENANT_PRIVATE_KEY_META]);
        $this->assertStringContainsString('PRIVATE KEY', PrivateKeyStore::load('sub-1'));
    }

    public function test_does_not_create_a_second_store_for_the_same_subscription(): void
    {
        $manager = new TenantsSubscriptionManager($this->service);
        $this->cart->meta['sub-1'][WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META] = 'store-1042';

        $manager->create_tenant_when_subscription_created('sub-1', $this->purchase());

        $this->assertSame([], $this->requests);
    }

    public function test_purchase_without_a_plan_product_creates_nothing(): void
    {
        $manager = new TenantsSubscriptionManager($this->service);

        $manager->create_tenant_when_subscription_created('sub-1', new PlanPurchase([], 'Apex Research', 'owner@apexresearch.example'));

        $this->assertSame([], $this->requests);
    }

    public function test_poller_moves_a_ready_tenant_on_to_the_ssl_check(): void
    {
        $poller = new WPCSTenantStatusService($this->service);
        $this->cart->meta['sub-1'] = [
            WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::PROVISIONING,
            WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META => 'store-1042',
            WPCSTenant::WPCS_DOMAIN_NAME_META => 'apex-research.stores.waas.example',
        ];
        $this->cart->meta['sub-2'] = [WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::READY];
        $this->mock_example('List tenants: Ready (statusCode 1, domain linked)');

        $poller->poll_tenant_status();

        // The example domain doesn't resolve, so the HEAD check fails and it waits there.
        $this->assertSame(WPCSTenant::REQUESTING_SSL, $this->cart->meta['sub-1'][WPCSTenant::WPCS_TENANT_STATE]);
        $this->assertSame(WPCSTenant::READY, $this->cart->meta['sub-2'][WPCSTenant::WPCS_TENANT_STATE]);
        $this->assertSame(['GET', 'GET', 'HEAD'], array_column($this->requests, 0));
    }

    public function test_poller_does_nothing_without_a_cart(): void
    {
        $poller = new WPCSTenantStatusService($this->service);
        Commerce::reset();

        $poller->poll_tenant_status();

        $this->assertSame([], $this->requests);
    }

    public function test_cancelling_deletes_the_store_by_external_id(): void
    {
        $manager = new TenantsSubscriptionManager($this->service);
        $this->cart->meta['sub-1'][WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META] = 'store-1042';
        $this->mock_example('Delete tenant: Scheduled');

        $manager->remove_tenant_when_subscription_expired('sub-1');

        $this->assertSame('DELETE', $this->requests[0][0]);
        $this->assertStringEndsWith('/v1/tenants?externalId=store-1042', $this->requests[0][1]);
    }
}
