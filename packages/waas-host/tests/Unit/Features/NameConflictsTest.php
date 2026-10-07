<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use WaaSHost\Commerce\PlanProduct;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\NameConflicts;
use WaaSHost\Features\StoreJobs;
use WaaSHost\Features\TenantsSubscriptionManager;

/**
 * Checkout takes a website name it could not check while the API was down. If the create then finds the name taken,
 * the store waits for the customer to choose a new one: no retries, the admin and the customer are told once, and
 * the new name gets the same checks before the create is queued again. Never two stores for one subscription.
 */
class NameConflictsTest extends StoreJobsTestCase
{
    private const FIRST_ID = '5f0b7c3e-2d1a-4e8b-9c6f-0a1b2c3d4e5f';
    private const SECOND_ID = '8a2d4f6b-1c3e-4a5b-8d7f-9e0a1b2c3d4e';

    private $wpcs;
    private NameConflicts $conflicts;
    private TenantsSubscriptionManager $manager;
    private array $uuids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpcs = Mockery::mock(WPCSService::class);
        $this->conflicts = new NameConflicts($this->wpcs, $this->jobs);
        $this->manager = new TenantsSubscriptionManager($this->wpcs, $this->jobs, $this->conflicts);

        $this->options['wpcs_host_settings_root_domain'] = 'stores.waas.example';
        $this->options[WPCSTenant::WPCS_DEFAULT_USER_ROLE] = 'administrator';
        Functions\when('sanitize_text_field')->alias('trim');
        Functions\when('wp_unslash')->returnArg();
        Functions\when('remove_accents')->returnArg();
        Functions\when('sanitize_title_with_dashes')->alias(fn ($v) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $v)), '-'));
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('apply_filters')->returnArg(2);
        $this->uuids = [self::FIRST_ID, self::SECOND_ID];
        Functions\when('wp_generate_uuid4')->alias(fn () => array_shift($this->uuids));
    }

    private function meta(string $key, $subscription_id = 901)
    {
        return $this->cart->meta[$subscription_id][$key] ?? '';
    }

    /** Checkout accepted "Apex Research" unverified; the create job is queued. */
    private function bought(): void
    {
        $purchase = new PlanPurchase([new PlanProduct(101, 'peptide-store', 'peptide-starter')], 'Apex Research', 'owner@apexresearch.example');
        $this->manager->create_tenant_when_subscription_created(901, $purchase);
    }

    private function create_refused_as_taken(): void
    {
        $this->wpcs->expects('create_tenant')->once()->andThrow(new \Exception('Failed: 400 {"message":"Domain apex-research.stores.waas.example is already in use"}'));
        $this->run_due(901);
    }

    private function parked(): void
    {
        $this->bought();
        $this->create_refused_as_taken();
    }

    public function test_a_queued_store_holds_its_name_against_another_checkout(): void
    {
        $this->bought();

        $this->assertSame('apex-research.stores.waas.example', $this->meta(WPCSTenant::WPCS_DOMAIN_NAME_META));
        $this->assertTrue($this->cart->is_domain_taken('apex-research.stores.waas.example'));
    }

    public function test_a_create_refused_because_the_name_is_in_use_waits_for_a_new_name_without_retrying(): void
    {
        $this->bought();
        Actions\expectDone('wpcs_tenant_name_conflict')->once()->with(901, 'Apex Research');

        $this->create_refused_as_taken();

        $this->assertSame(WPCSTenant::NEEDS_NEW_NAME, $this->meta(WPCSTenant::WPCS_TENANT_STATE));
        $this->assertSame([], $this->queue(901));
        $this->assertSame('', $this->meta(StoreJobs::FAILURE_META));
        $this->assertSame([], $this->actions, 'no retry is scheduled');
        $this->assertSame('', $this->meta(WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META));
        $this->assertSame('', $this->meta(WPCSTenant::WPCS_DOMAIN_NAME_META), 'the taken name is not held for this store');

        $conflict = $this->meta(WPCSTenant::WPCS_NAME_CONFLICT_META);
        $this->assertSame('Apex Research', $conflict['name']);
        $this->assertSame('apex-research.stores.waas.example', $conflict['domain']);
        $this->assertSame(self::FIRST_ID, $conflict['store']['external_id']);

        $this->assertCount(2, $this->mails);
        [$admin, $customer] = $this->mails;
        $this->assertSame('ops@storefront.example', $admin[0]);
        $this->assertStringContainsString('already in use', $admin[2]);
        $this->assertSame('owner@apexresearch.example', $customer[0]);
        $this->assertStringContainsString('choose a new name', $customer[1]);
        $this->assertStringContainsString('https://storefront.example/account/subscriptions/901', $customer[2]);
    }

    public function test_a_400_is_a_name_conflict_when_the_availability_check_says_taken(): void
    {
        $this->bought();
        $this->wpcs->expects('create_tenant')->andThrow(new \Exception('Failed: 400 {"message":"Validation failed"}'));
        $this->wpcs->expects('domain_available')->with('apex-research.stores.waas.example')->andReturn(false);

        $this->run_due(901);

        $this->assertSame(WPCSTenant::NEEDS_NEW_NAME, $this->meta(WPCSTenant::WPCS_TENANT_STATE));
    }

    public function test_other_failures_are_retried_as_before(): void
    {
        $this->bought();
        $this->wpcs->expects('create_tenant')->andThrow(new \Exception('Failed: 400 {"message":"Validation failed"}'));
        $this->wpcs->expects('domain_available')->andReturn(true);

        $this->run_due(901);

        $this->assertSame(WPCSTenant::QUEUED, $this->meta(WPCSTenant::WPCS_TENANT_STATE));
        $this->assertSame('create', $this->queue(901)[0]['op']);
        $this->assertSame(1, $this->queue(901)[0]['attempts']);
        $this->assertSame([], $this->mails);
    }

    public function test_the_customer_s_new_name_is_checked_and_the_store_created_with_it(): void
    {
        $this->parked();
        $this->wpcs->expects('domain_available')->with('apex-labs.stores.waas.example')->andReturn(true);

        $this->assertNull($this->conflicts->choose_new_name(901, 'Apex Labs'));

        $this->assertSame(WPCSTenant::QUEUED, $this->meta(WPCSTenant::WPCS_TENANT_STATE));
        $this->assertSame('', $this->meta(WPCSTenant::WPCS_NAME_CONFLICT_META));
        $this->assertSame('apex-labs.stores.waas.example', $this->meta(WPCSTenant::WPCS_DOMAIN_NAME_META));

        $this->wpcs->expects('create_tenant')->once()->with(Mockery::on(fn ($args) => $args['external_id'] === self::SECOND_ID
            && $args['name'] === 'Apex Labs'
            && $args['custom_domain_name'] === 'apex-labs.stores.waas.example'
            && $args['group_name'] === 'peptide-starter'))
            ->andReturn((object) ['externalId' => self::SECOND_ID, 'baseDomain' => 't-1.stores.waas.example', 'customDomain' => 'apex-labs.stores.waas.example']);
        $this->run_due(901);

        $this->assertSame(self::SECOND_ID, $this->meta(WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META));
        $this->assertSame([], $this->queue(901));
        $this->assertCount(2, $this->mails, 'no more emails');
    }

    public function test_a_new_name_another_store_here_has_is_refused_without_asking_the_api(): void
    {
        $this->parked();
        $this->cart->meta[777] = [WPCSTenant::WPCS_DOMAIN_NAME_META => 'apex-labs.stores.waas.example', WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::READY];
        $this->wpcs->expects('domain_available')->never();

        $this->assertStringContainsString('already taken', $this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertSame(WPCSTenant::NEEDS_NEW_NAME, $this->meta(WPCSTenant::WPCS_TENANT_STATE));
        $this->assertSame([], $this->queue(901));
    }

    public function test_a_new_name_the_api_reports_taken_is_refused(): void
    {
        $this->parked();
        $this->wpcs->expects('domain_available')->andReturn(false);

        $this->assertStringContainsString('already taken', $this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertSame([], $this->queue(901));
    }

    public function test_the_new_name_is_not_taken_while_the_api_is_down(): void
    {
        $this->parked();
        $this->wpcs->expects('domain_available')->andThrow(new \Exception('Failed: 503'));

        $this->assertStringContainsString('try again', $this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertSame(WPCSTenant::NEEDS_NEW_NAME, $this->meta(WPCSTenant::WPCS_TENANT_STATE));
        $this->assertIsArray($this->meta(WPCSTenant::WPCS_NAME_CONFLICT_META));
        $this->assertSame([], $this->queue(901));
    }

    public function test_a_store_that_does_not_wait_for_a_name_cannot_be_renamed_here(): void
    {
        $this->cart->meta[900] = [WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META => 'store-1042', WPCSTenant::WPCS_TENANT_STATE => WPCSTenant::READY];
        $this->wpcs->expects('domain_available')->never();

        $this->assertNotNull($this->conflicts->choose_new_name(900, 'Apex Labs'));
        $this->assertSame([], $this->queue(900));

        // A second submit after the first one was accepted.
        $this->parked();
        $this->wpcs->expects('domain_available')->andReturn(true);
        $this->assertNull($this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertNotNull($this->conflicts->choose_new_name(901, 'Apex Labs Two'));
        $this->assertCount(1, $this->queue(901));
    }

    public function test_only_the_subscription_s_customer_can_choose_the_name(): void
    {
        $this->parked();
        $_POST = ['subscription_id' => '901', 'website_name' => 'Apex Labs'];
        Functions\expect('check_admin_referer')->once()->with('wpcs_choose_website_name_901');
        Functions\when('get_current_user_id')->justReturn(8); // the subscription's customer is user 7
        Functions\when('esc_html__')->returnArg();
        Functions\when('wp_die')->alias(function () {
            throw new \RuntimeException('wp_die');
        });
        $this->wpcs->expects('domain_available')->never();

        try {
            $this->conflicts->handle_choose_request();
            $this->fail('expected wp_die');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $_POST = [];
        }
        $this->assertSame(WPCSTenant::NEEDS_NEW_NAME, $this->meta(WPCSTenant::WPCS_TENANT_STATE));
    }

    public function test_the_admin_sees_stores_waiting_for_a_name(): void
    {
        $this->parked();
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('esc_html')->returnArg();
        Functions\when('_n')->alias(fn ($single, $plural, $n) => $n === 1 ? $single : $plural);

        ob_start();
        $this->conflicts->show_admin_notice();
        $html = ob_get_clean();

        $this->assertStringContainsString('1 website is waiting for its customer to choose a new name.', $html);
        $this->assertStringContainsString('Subscription #901: "Apex Research" was taken (apex-research.stores.waas.example)', $html);
    }
}
