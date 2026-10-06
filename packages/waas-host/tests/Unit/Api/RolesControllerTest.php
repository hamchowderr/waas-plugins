<?php

namespace Tests\Unit\Api;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Api\RolesController;
use WaaSHost\Core\WPCSService;

class RolesControllerTest extends TestCase
{
    private $wpcs;
    private RolesController $controller;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('WP_REST_Response')) {
            eval('class WP_REST_Response { public $data; public $status; public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; } public function get_status() { return $this->status; } }');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpcs = Mockery::mock(WPCSService::class);
        $this->controller = new RolesController($this->wpcs);

        Functions\when('esc_html__')->returnArg();
        Functions\when('wp_die')->alias(function ($message, $code) {
            throw new \RuntimeException("wp_die: $code");
        });
    }

    public function test_admin_refresh_is_refused_without_the_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('check_admin_referer')->never();
        $this->wpcs->shouldNotReceive('get_production_version');

        $this->expectExceptionMessage('wp_die: 403');
        $this->controller->admin_post_update_tenant_roles_list();
    }

    public function test_admin_refresh_checks_the_nonce_before_refreshing(): void
    {
        Functions\when('current_user_can')->alias(fn ($cap) => $cap === 'manage_woocommerce');
        Functions\expect('check_admin_referer')
            ->once()
            ->with('wpcs_refresh_roles')
            ->andReturnUsing(function () {
                throw new \RuntimeException('bad nonce');
            });
        $this->wpcs->shouldNotReceive('get_production_version');

        $this->expectExceptionMessage('bad nonce');
        $this->controller->admin_post_update_tenant_roles_list();
    }

    public function test_admin_refresh_updates_roles_for_a_shop_manager(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('check_admin_referer')->once()->andReturn(1);
        $this->expectRolesFetched();
        Functions\when('wp_get_referer')->justReturn('/wp-admin/post.php');
        Functions\expect('wp_safe_redirect')->once()->with('/wp-admin/post.php');

        $this->controller->admin_post_update_tenant_roles_list();
    }

    public function test_public_refresh_is_rate_limited(): void
    {
        $lock = false;
        Functions\when('get_transient')->alias(function ($key) use (&$lock) {
            return $key === RolesController::REFRESH_LOCK_TRANSIENT ? $lock : false;
        });
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use (&$lock) {
            if ($key === RolesController::REFRESH_LOCK_TRANSIENT) {
                $this->assertSame(RolesController::REFRESH_LOCK_SECONDS, $ttl);
                $lock = $value;
            }
            return true;
        });
        $this->expectRolesFetched();

        $this->assertSame(200, $this->controller->update_tenant_roles_list_callback()->get_status());
        // A second hit inside the window does no work.
        $this->assertSame(429, $this->controller->update_tenant_roles_list_callback()->get_status());
    }

    public function test_refresh_url_carries_a_nonce(): void
    {
        Functions\when('admin_url')->alias(fn ($path) => 'https://shop.test/wp-admin/' . $path);
        Functions\expect('wp_nonce_url')
            ->once()
            ->with('https://shop.test/wp-admin/admin-post.php?action=wpcs_refresh_roles', 'wpcs_refresh_roles')
            ->andReturn('signed');

        $this->assertSame('signed', RolesController::get_refresh_url());
    }

    private function expectRolesFetched(): void
    {
        $this->wpcs->expects('get_production_version')
            ->andReturn((object) ['domain' => 'template.example.com']);
        Functions\when('trailingslashit')->alias(fn ($url) => rtrim($url, '/') . '/');
        Functions\expect('wp_remote_get')
            ->once()
            ->with('https://template.example.com/wp-content/plugins/waas-client-data/roles.json')
            ->andReturn(['response' => ['code' => 200], 'body' => '{"basic":{"title":"Basic","plugins":[]}}']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\expect('update_option')->once();
    }
}
