<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSClient\Features\AdminRolesSettings;
use WaaSClient\Features\PluginBootstrap;
use WaaSClient\Features\RolesManager;

/**
 * Customer websites: plugins follow the plan's role, but a role that isn't in roles.json (or a broken roles.json)
 * must leave the website alone instead of deactivating every plugin.
 */
class RolesManagerTest extends TestCase
{
    private const PLUGINS = [
        'waas-client/index.php' => ['Name' => 'WaaS Client'],
        'shop/shop.php' => ['Name' => 'Shop'],
        'forms/forms.php' => ['Name' => 'Forms'],
        'seo/seo.php' => ['Name' => 'SEO'],
    ];

    private array $active = [];
    private array $site_roles = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('WAAS_IS_TENANT=true');
        if (!is_dir(AdminRolesSettings::ROLES_FILE_DIR_PATH)) {
            mkdir(AdminRolesSettings::ROLES_FILE_DIR_PATH, 0755, true);
        }

        $this->active = ['waas-client/index.php', 'shop/shop.php', 'forms/forms.php'];
        Functions\when('get_option')->alias(fn ($name, $default = false) => $name === PluginBootstrap::TENANT_ROLES ? $this->site_roles : $default);
        Functions\when('get_plugins')->justReturn(self::PLUGINS);
        Functions\when('is_plugin_active')->alias(fn ($file) => in_array($file, $this->active, true));
    }

    protected function tearDown(): void
    {
        putenv('WAAS_IS_TENANT');
        @unlink(AdminRolesSettings::ROLES_FILE_PATH);
        parent::tearDown();
    }

    public function test_unknown_role_changes_nothing_and_warns(): void
    {
        $this->writeRoles(['basic' => ['title' => 'Basic', 'plugins' => ['shop/shop.php']]]);
        $this->site_roles = ['pro'];

        $this->expectNoPluginChanges();
        Actions\expectAdded('admin_notices')->once();

        $this->run_manager();
    }

    public function test_empty_roles_file_changes_nothing(): void
    {
        $this->writeRaw('{}');
        $this->site_roles = ['pro'];

        $this->expectNoPluginChanges();
        Actions\expectAdded('admin_notices')->once();

        $this->run_manager();
    }

    public function test_invalid_roles_file_changes_nothing_and_warns(): void
    {
        $this->writeRaw('{"basic": {"plugins": [');
        $this->site_roles = ['basic'];

        $this->expectNoPluginChanges();
        Actions\expectAdded('admin_notices')->once()->withArgs(fn ($callback) => is_array($callback) && $callback[1] === 'waas_invalid_roles_json_admin_notice');

        $this->run_manager();
    }

    public function test_matching_role_turns_its_plugins_on_and_the_rest_off(): void
    {
        $this->writeRoles(['pro' => ['title' => 'Pro', 'plugins' => ['shop/shop.php', 'seo/seo.php']]]);
        $this->site_roles = ['pro'];

        Actions\expectAdded('admin_notices')->never();
        Functions\expect('activate_plugins')->once()->with(['seo/seo.php']);
        Functions\expect('deactivate_plugins')->once()->with(\Mockery::on(fn ($files) => array_values($files) === ['forms/forms.php']));

        $this->run_manager();
    }

    public function test_one_unknown_role_among_known_ones_still_applies_and_warns(): void
    {
        $this->writeRoles(['basic' => ['title' => 'Basic', 'plugins' => ['shop/shop.php']]]);
        $this->site_roles = ['basic', 'gone'];

        Actions\expectAdded('admin_notices')->once();
        Functions\expect('activate_plugins')->once()->with([]);
        Functions\expect('deactivate_plugins')->once()->with(\Mockery::on(fn ($files) => array_values($files) === ['forms/forms.php']));

        $this->run_manager();
    }

    public function test_plan_for_splits_found_and_missing_roles(): void
    {
        $plan = RolesManager::plan_for(['a', 'b', 'c'], [
            'a' => ['plugins' => ['x.php', 'y.php']],
            'c' => ['plugins' => ['y.php', 'z.php']],
        ]);

        $this->assertSame(['a', 'c'], $plan['matched']);
        $this->assertSame(['b'], $plan['missing']);
        $this->assertSame(['x.php', 'y.php', 'z.php'], $plan['plugins']);
    }

    private function run_manager(): void
    {
        (new RolesManager())->activate_enabled_plugins();
    }

    private function expectNoPluginChanges(): void
    {
        Functions\expect('activate_plugins')->never();
        Functions\expect('deactivate_plugins')->never();
    }

    private function writeRoles(array $roles): void
    {
        $this->writeRaw(json_encode($roles));
    }

    private function writeRaw(string $json): void
    {
        file_put_contents(AdminRolesSettings::ROLES_FILE_PATH, $json);
    }
}
