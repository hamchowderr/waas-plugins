<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use WaaSHost\Commerce\PlanProduct;
use WaaSHost\Commerce\PlanPurchase;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\NameConflicts;
use WaaSHost\Features\StoreJobs;
use WaaSHost\Features\TenantsAddOnSubscriptionManager;
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

    private $api;
    private NameConflicts $conflicts;
    private TenantsSubscriptionManager $manager;
    private array $uuids;
    /** wp_safe_redirect calls */
    private array $redirects = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->conflicts = new NameConflictsWithoutExit($this->api, $this->jobs);
        $this->manager = new TenantsSubscriptionManager($this->api, $this->jobs, $this->conflicts);

        $this->options['waas_root_domain'] = 'stores.waas.example';
        $this->options[Tenant::DEFAULT_USER_ROLE] = 'administrator';
        Functions\when('sanitize_text_field')->alias('trim');
        Functions\when('wp_unslash')->returnArg();
        Functions\when('remove_accents')->returnArg();
        Functions\when('sanitize_title_with_dashes')->alias(fn ($v) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $v)), '-'));
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('apply_filters')->returnArg(2);
        $this->uuids = [self::FIRST_ID, self::SECOND_ID];
        Functions\when('wp_generate_uuid4')->alias(fn () => array_shift($this->uuids));
        Functions\when('sanitize_key')->alias(fn ($v) => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)));
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('add_query_arg')->alias(fn (array $args, string $url) => $url . '?' . implode('&', array_map(fn ($k, $v) => "$k=$v", array_keys($args), $args)));
        Functions\when('wp_safe_redirect')->alias(function ($url) {
            $this->redirects[] = $url;
            return true;
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    /** The customer submits the form; $nonce_ok is false for a page opened before its nonce expired. */
    private function submit(string $name, bool $nonce_ok = true): void
    {
        $_POST = ['subscription_id' => '901', 'website_name' => $name, '_wpnonce' => 'nonce'];
        Functions\when('wp_verify_nonce')->alias(fn ($nonce, $action) => $nonce_ok && $action === 'waas_choose_website_name_901' ? 1 : false);
        Functions\when('get_current_user_id')->justReturn(7);
        $this->conflicts->handle_choose_request();
    }

    /** The page the customer is sent back to, as it renders the result. */
    private function result_shown_after_redirect($subscription_id = 901): string
    {
        $query = parse_url(end($this->redirects), PHP_URL_QUERY);
        parse_str((string) $query, $_GET);
        ob_start();
        NameConflicts::render_result($subscription_id);

        return ob_get_clean();
    }

    private function admin_notice(): string
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('_n')->alias(fn ($single, $plural, $n) => $n === 1 ? $single : $plural);
        ob_start();
        $this->conflicts->show_admin_notice();

        return ob_get_clean();
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
        $this->api->expects('create_tenant')->once()->andThrow(new \Exception('Failed: 400 {"message":"Domain apex-research.stores.waas.example is already in use"}'));
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

        $this->assertSame('apex-research.stores.waas.example', $this->meta(Tenant::DOMAIN_NAME_META));
        $this->assertTrue($this->cart->is_domain_taken('apex-research.stores.waas.example'));
    }

    public function test_a_create_refused_because_the_name_is_in_use_waits_for_a_new_name_without_retrying(): void
    {
        $this->bought();
        Actions\expectDone('waas_tenant_name_conflict')->once()->with(901, 'Apex Research');

        $this->create_refused_as_taken();

        $this->assertSame(Tenant::NEEDS_NEW_NAME, $this->meta(Tenant::TENANT_STATE));
        $this->assertSame([], $this->queue(901));
        $this->assertSame('', $this->meta(StoreJobs::FAILURE_META));
        $this->assertSame([], $this->actions, 'no retry is scheduled');
        $this->assertSame('', $this->meta(Tenant::TENANT_EXTERNAL_ID_META));
        $this->assertSame('', $this->meta(Tenant::DOMAIN_NAME_META), 'the taken name is not held for this store');

        $conflict = $this->meta(Tenant::NAME_CONFLICT_META);
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
        $this->api->expects('create_tenant')->andThrow(new \Exception('Failed: 400 {"message":"Validation failed"}'));
        $this->api->expects('domain_available')->with('apex-research.stores.waas.example')->andReturn(false);

        $this->run_due(901);

        $this->assertSame(Tenant::NEEDS_NEW_NAME, $this->meta(Tenant::TENANT_STATE));
    }

    /** @dataProvider external_id_refusals */
    public function test_a_create_refused_for_its_own_external_id_adopts_that_store_instead_of_waiting_for_a_name(string $refusal): void
    {
        // An admin's retry starts at attempt 0, so the create is sent again although the first one got through.
        $this->bought();
        $this->api->expects('create_tenant')->andThrow(new \Exception($refusal));
        $this->api->expects('get_tenant_safe')->with(self::FIRST_ID)->andReturn((object) [
            'externalId' => self::FIRST_ID,
            'domainName' => 'apex-research.stores.waas.example',
            'baseDomain' => 't-1.stores.waas.example',
            'statusCode' => 0,
        ]);
        $this->api->shouldNotReceive('domain_available');

        $this->run_due(901);

        $this->assertNotSame(Tenant::NEEDS_NEW_NAME, $this->meta(Tenant::TENANT_STATE));
        $this->assertSame(self::FIRST_ID, $this->meta(Tenant::TENANT_EXTERNAL_ID_META));
        $this->assertSame('apex-research.stores.waas.example', $this->meta(Tenant::DOMAIN_NAME_META));
        $this->assertSame('t-1.stores.waas.example', $this->meta(Tenant::BASE_DOMAIN_NAME_META));
        $this->assertSame([], $this->queue(901));
    }

    public static function external_id_refusals(): array
    {
        return [
            'API before the duplicate check' => ['Failed: 400 - {"statusCode":400,"message":"externalId ' . self::FIRST_ID . ' is already in use"}'],
            'API with the duplicate check' => ['Failed: 409 - {"statusCode":409,"message":"externalId ' . self::FIRST_ID . ' is already in use by another store"}'],
        ];
    }

    public function test_other_failures_are_retried_as_before(): void
    {
        $this->bought();
        $this->api->expects('create_tenant')->andThrow(new \Exception('Failed: 400 {"message":"Validation failed"}'));
        $this->api->expects('domain_available')->andReturn(true);

        $this->run_due(901);

        $this->assertSame(Tenant::QUEUED, $this->meta(Tenant::TENANT_STATE));
        $this->assertSame('create', $this->queue(901)[0]['op']);
        $this->assertSame(1, $this->queue(901)[0]['attempts']);
        $this->assertSame([], $this->mails);
    }

    public function test_the_customer_s_new_name_is_checked_and_the_store_created_with_it(): void
    {
        $this->parked();
        $this->api->expects('domain_available')->with('apex-labs.stores.waas.example')->andReturn(true);

        $this->assertNull($this->conflicts->choose_new_name(901, 'Apex Labs'));

        $this->assertSame(Tenant::QUEUED, $this->meta(Tenant::TENANT_STATE));
        $this->assertSame('', $this->meta(Tenant::NAME_CONFLICT_META));
        $this->assertSame('apex-labs.stores.waas.example', $this->meta(Tenant::DOMAIN_NAME_META));

        $this->api->expects('create_tenant')->once()->with(Mockery::on(fn ($args) => $args['external_id'] === self::SECOND_ID
            && $args['name'] === 'Apex Labs'
            && $args['custom_domain_name'] === 'apex-labs.stores.waas.example'
            && $args['group_name'] === 'peptide-starter'))
            ->andReturn((object) ['externalId' => self::SECOND_ID, 'baseDomain' => 't-1.stores.waas.example', 'customDomain' => 'apex-labs.stores.waas.example']);
        $this->run_due(901);

        $this->assertSame(self::SECOND_ID, $this->meta(Tenant::TENANT_EXTERNAL_ID_META));
        $this->assertSame([], $this->queue(901));
        $this->assertCount(2, $this->mails, 'no more emails');
    }

    public function test_a_new_name_another_store_here_has_is_refused_without_asking_the_api(): void
    {
        $this->parked();
        $this->cart->meta[777] = [Tenant::DOMAIN_NAME_META => 'apex-labs.stores.waas.example', Tenant::TENANT_STATE => Tenant::READY];
        $this->api->expects('domain_available')->never();

        $this->assertStringContainsString('already taken', $this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertSame(Tenant::NEEDS_NEW_NAME, $this->meta(Tenant::TENANT_STATE));
        $this->assertSame([], $this->queue(901));
    }

    public function test_a_new_name_the_api_reports_taken_is_refused(): void
    {
        $this->parked();
        $this->api->expects('domain_available')->andReturn(false);

        $this->assertStringContainsString('already taken', $this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertSame([], $this->queue(901));
    }

    public function test_the_new_name_is_not_taken_while_the_api_is_down(): void
    {
        $this->parked();
        $this->api->expects('domain_available')->andThrow(new \Exception('Failed: 503'));

        $this->assertStringContainsString('try again', $this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertSame(Tenant::NEEDS_NEW_NAME, $this->meta(Tenant::TENANT_STATE));
        $this->assertIsArray($this->meta(Tenant::NAME_CONFLICT_META));
        $this->assertSame([], $this->queue(901));
    }

    public function test_a_store_that_does_not_wait_for_a_name_cannot_be_renamed_here(): void
    {
        $this->cart->meta[900] = [Tenant::TENANT_EXTERNAL_ID_META => 'store-1042', Tenant::TENANT_STATE => Tenant::READY];
        $this->api->expects('domain_available')->never();

        $this->assertNotNull($this->conflicts->choose_new_name(900, 'Apex Labs'));
        $this->assertSame([], $this->queue(900));

        // A second submit after the first one was accepted.
        $this->parked();
        $this->api->expects('domain_available')->andReturn(true);
        $this->assertNull($this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertNotNull($this->conflicts->choose_new_name(901, 'Apex Labs Two'));
        $this->assertCount(1, $this->queue(901));
    }

    public function test_only_the_subscription_s_customer_can_choose_the_name(): void
    {
        $this->parked();
        $_POST = ['subscription_id' => '901', 'website_name' => 'Apex Labs', '_wpnonce' => 'nonce'];
        Functions\expect('wp_verify_nonce')->once()->with('nonce', 'waas_choose_website_name_901')->andReturn(1);
        Functions\when('get_current_user_id')->justReturn(8); // the subscription's customer is user 7
        Functions\when('esc_html__')->returnArg();
        Functions\when('wp_die')->alias(function () {
            throw new \RuntimeException('wp_die');
        });
        $this->api->expects('domain_available')->never();

        try {
            $this->conflicts->handle_choose_request();
            $this->fail('expected wp_die');
        } catch (\RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $_POST = [];
        }
        $this->assertSame(Tenant::NEEDS_NEW_NAME, $this->meta(Tenant::TENANT_STATE));
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

    public function test_cancelling_while_waiting_stops_the_wait_and_the_form_refuses(): void
    {
        $this->parked();
        $this->api->expects('domain_available')->never();
        $this->api->expects('suspend_tenant')->never();

        $this->manager->suspend_tenant_when_subscription_expired(901);

        $this->assertSame('', $this->meta(Tenant::TENANT_STATE));
        $this->assertSame([], $this->queue(901));
        $this->assertSame([], $this->scheduled, 'no deletion is scheduled: there is no store');
        $this->assertFalse(Tenant::shown_to_customer('', ''));
        $this->assertStringContainsString('not active', $this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertSame([], $this->queue(901));

        Functions\when('current_user_can')->justReturn(true);
        ob_start();
        $this->conflicts->show_admin_notice();
        $this->assertSame('', ob_get_clean(), 'no admin notice for a cancelled subscription');
        $this->assertCount(2, $this->mails, 'no more emails');
    }

    public function test_a_hold_pauses_the_wait_and_reactivation_lets_the_customer_choose_again(): void
    {
        $this->parked();

        $this->manager->suspend_tenant_on_hold(901);
        $this->assertSame('', $this->meta(Tenant::TENANT_STATE));
        $this->manager->suspend_tenant_when_subscription_expired(901); // cancelled after the hold: still nothing to do

        $this->manager->resume_suspended_tenant(901);
        $this->assertSame(Tenant::NEEDS_NEW_NAME, $this->meta(Tenant::TENANT_STATE));

        $this->api->expects('domain_available')->andReturn(true);
        $this->assertNull($this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertSame('create', $this->queue(901)[0]['op']);
        $this->assertSame(self::SECOND_ID, $this->queue(901)[0]['args']['external_id']);
    }

    public function test_activation_events_never_queue_a_second_create_for_a_store_waiting_for_a_name(): void
    {
        $this->parked();
        $purchase = new PlanPurchase([new PlanProduct(101, 'peptide-store', 'peptide-starter')], 'Apex Research', 'owner@apexresearch.example');

        // A repeated activation while waiting changes nothing.
        $this->manager->create_tenant_when_subscription_created(901, $purchase);
        $this->assertSame(Tenant::NEEDS_NEW_NAME, $this->meta(Tenant::TENANT_STATE));
        $this->assertSame([], $this->queue(901));

        // Cancelled, then reactivated (FluentCart reports that as an activation): the customer chooses again.
        $this->manager->suspend_tenant_when_subscription_expired(901);
        $this->manager->create_tenant_when_subscription_created(901, $purchase);
        $this->assertSame(Tenant::NEEDS_NEW_NAME, $this->meta(Tenant::TENANT_STATE));
        $this->assertSame([], $this->queue(901));
        $this->assertSame('', $this->meta(Tenant::DOMAIN_NAME_META));
    }

    public function test_a_submit_from_a_page_opened_before_the_cancel_is_refused_and_the_page_says_why(): void
    {
        $this->parked();
        $this->manager->suspend_tenant_when_subscription_expired(901);
        $this->api->expects('domain_available')->never();

        $this->submit('Apex Labs');

        $this->assertSame(['https://storefront.example/account/subscriptions/901?waas_website_name=not_active&waas_website_name_for=901#waas-website-name-result-901'], $this->redirects);
        $this->assertStringContainsString('This subscription is not active', $this->result_shown_after_redirect());
        $this->assertSame([], $this->queue(901));
    }

    public function test_each_result_is_shown_by_its_code_and_only_at_its_own_website(): void
    {
        $this->parked();
        $this->api->expects('domain_available')->twice()->andReturn(false, true);

        $this->submit('Apex Labs');
        $this->assertStringContainsString('already taken', $this->result_shown_after_redirect());
        $this->assertSame('', $this->result_shown_after_redirect(902), 'another website on the same page shows nothing');

        $this->submit('Apex Labs Two');
        $this->assertStringContainsString('setting up your website with its new name', $this->result_shown_after_redirect());

        // Text put in the URL by someone else is never shown.
        $_GET = [NameConflicts::RESULT_ARG => 'Your account is locked, call 555-0100', NameConflicts::RESULT_FOR_ARG => '901'];
        ob_start();
        NameConflicts::render_result(901);
        $this->assertSame('', ob_get_clean());
    }

    public function test_two_submits_at_once_queue_one_create(): void
    {
        $this->parked();
        // A lock left by a request that died long ago doesn't count.
        $this->options['waas_choose_name_lock_' . md5('901')] = time() - 120;
        $second = null;
        $calls = 0;
        // The second submit (another tab, a double click) arrives while the first waits for the API.
        $this->api->allows('domain_available')->andReturnUsing(function () use (&$second, &$calls) {
            if (++$calls === 1) {
                $second = $this->conflicts->choose_new_name(901, 'Apex Labs Two') ?? 'queued';
            }
            return true;
        });

        $this->assertNull($this->conflicts->choose_new_name(901, 'Apex Labs'));

        $this->assertStringContainsString('being saved right now', (string) $second);
        $this->assertCount(1, $this->queue(901));
        $this->assertSame('Apex Labs', $this->queue(901)[0]['args']['request']['name']);
        $this->assertArrayNotHasKey('waas_choose_name_lock_' . md5('901'), $this->options, 'the lock is released');
    }

    public function test_a_page_left_open_too_long_asks_for_the_name_again_instead_of_failing(): void
    {
        $this->parked();
        $this->api->expects('domain_available')->never();

        $this->submit('Apex Labs', false);

        $this->assertStringContainsString('open too long', $this->result_shown_after_redirect());
        $this->assertSame(Tenant::NEEDS_NEW_NAME, $this->meta(Tenant::TENANT_STATE));
        $this->assertSame([], $this->queue(901));
    }

    public function test_a_customer_logged_out_meanwhile_is_sent_back_to_their_page(): void
    {
        $this->parked();
        $_POST = ['subscription_id' => '901', 'website_name' => 'Apex Labs'];
        $this->api->expects('domain_available')->never();

        $this->conflicts->handle_logged_out_request();

        $this->assertSame(['https://storefront.example/account/subscriptions/901'], $this->redirects);
        $this->assertSame([], $this->queue(901));
    }

    public function test_the_admin_notice_counts_the_stores_the_customer_can_still_name_after_each_transition(): void
    {
        $this->parked();
        $this->assertStringContainsString('1 website is waiting', $this->admin_notice(), 'parked');

        $this->manager->suspend_tenant_on_hold(901);
        $this->assertSame('', $this->admin_notice(), 'paused');

        $this->manager->resume_suspended_tenant(901);
        $this->assertStringContainsString('1 website is waiting', $this->admin_notice(), 'reactivated');

        $this->api->expects('domain_available')->andReturn(true);
        $this->assertNull($this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertSame('', $this->admin_notice(), 'renamed');

        $this->api->expects('create_tenant')->andThrow(new \Exception('Failed: 400 {"message":"Domain apex-labs.stores.waas.example is already in use"}'));
        $this->run_due(901);
        $this->assertStringContainsString('Subscription #901: "Apex Labs" was taken', $this->admin_notice(), 'taken again before the create');

        // The subscription was deleted in the cart (or cancelled without an event): the cart no longer has it active.
        $this->cart->inactive[901] = true;
        $this->assertSame('', $this->admin_notice(), 'no longer active in the cart');
    }

    public function test_a_reactivation_the_cart_does_not_confirm_keeps_the_wait_paused(): void
    {
        $this->parked();
        $this->manager->suspend_tenant_when_subscription_expired(901);
        $this->cart->inactive[901] = true; // a reactivation handled after the cancel that followed it

        $this->manager->create_tenant_when_subscription_created(901, null);
        $this->manager->resume_suspended_tenant(901);

        $this->assertSame('', $this->meta(Tenant::TENANT_STATE));
        $this->assertTrue($this->meta(Tenant::NAME_CONFLICT_META)['paused']);
    }

    public function test_the_new_name_is_refused_when_the_cart_says_the_subscription_is_not_active(): void
    {
        $this->parked();
        $this->cart->inactive[901] = true; // no event paused the wait
        $this->api->expects('domain_available')->never();

        $this->assertStringContainsString('not active', $this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->assertSame([], $this->queue(901));
    }

    public function test_a_plan_change_while_waiting_reaches_the_store_when_it_is_created(): void
    {
        $add_ons = new TenantsAddOnSubscriptionManager($this->api, $this->jobs);
        $this->bought();
        $add_ons->update_tenant_roles(901, ['peptide-store', 'peptide-pro']); // queued behind the create
        $this->api->expects('update_tenant')->never();
        $this->create_refused_as_taken();

        $this->api->expects('domain_available')->andReturn(true);
        $this->assertNull($this->conflicts->choose_new_name(901, 'Apex Labs'));
        $this->api->expects('create_tenant')->once()->with(Mockery::on(fn ($args) => $args['php_constants']['WAAS_TENANT_ROLES']['value'] === 'peptide-store,peptide-pro'))
            ->andReturn((object) ['externalId' => self::SECOND_ID, 'baseDomain' => 't-1.stores.waas.example', 'customDomain' => 'apex-labs.stores.waas.example']);
        $this->run_due(901);

        $this->assertSame(['peptide-store', 'peptide-pro'], $this->meta(Tenant::SUBSCRIPTION_USER_ROLES));
    }
}

/** A redirect ends the request with exit; in the tests the test goes on. */
class NameConflictsWithoutExit extends NameConflicts
{
    protected function finish(): void
    {
    }
}
