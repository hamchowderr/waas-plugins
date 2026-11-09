<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use AnyPressClient\Features\AdminRolesSettings;
use AnyPressClient\Features\CleanAdmin;

/**
 * A clean wp-admin: the plugins that come with the website lose their upgrade and review links and their update
 * offers; the owner's own plugins keep theirs.
 */
class CleanAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(AdminRolesSettings::ROLES_FILE_DIR_PATH)) {
            mkdir(AdminRolesSettings::ROLES_FILE_DIR_PATH, 0755, true);
        }
        file_put_contents(AdminRolesSettings::ROLES_FILE_PATH, json_encode([
            'website' => ['title' => 'Website', 'plugins' => ['fluentform/fluentform.php', 'automatorwp/automatorwp.php']],
            'highlevel' => ['title' => 'HighLevel site', 'ownPlugins' => true, 'plugins' => ['leadconnector/leadconnector.php']],
        ]));
        $cache = new \ReflectionProperty(CleanAdmin::class, 'managed');
        $cache->setAccessible(true);
        $cache->setValue(null, null);
        Functions\when('wp_strip_all_tags')->alias(fn ($s) => trim(strip_tags((string) $s)));
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('is_admin')->justReturn(false);
    }

    protected function tearDown(): void
    {
        @unlink(AdminRolesSettings::ROLES_FILE_PATH);
        parent::tearDown();
    }

    public function test_the_included_plugins_are_every_site_types_plugins(): void
    {
        $this->assertEqualsCanonicalizing(['mcp-adapter', 'fluentform', 'automatorwp', 'leadconnector'], CleanAdmin::managed_folders());
        $this->assertTrue(CleanAdmin::is_managed('fluentform/fluentform.php'));
        $this->assertFalse(CleanAdmin::is_managed('shortcodes-ultimate/shortcodes-ultimate.php'));
    }

    public function test_included_plugins_lose_upgrade_and_review_links_and_keep_settings(): void
    {
        $links = [
            'settings' => '<a href="admin.php?page=fluent_forms_settings">Settings</a>',
            'upgrade' => '<a href="https://fluentforms.com/pricing" style="color:red">Upgrade to Pro</a>',
            'review' => '<a href="https://wordpress.org/support/plugin/fluentform/reviews/">Rate us</a>',
            'docs' => '<a href="https://fluentforms.com/docs">Docs</a>',
        ];
        $clean = new CleanAdmin();
        $this->assertSame(['settings', 'docs'], array_keys($clean->plugin_links($links, 'fluentform/fluentform.php')));
        // The owner's own plugin keeps everything.
        $this->assertSame($links, $clean->plugin_links($links, 'shortcodes-ultimate/shortcodes-ultimate.php'));
    }

    public function test_updates_are_not_offered_for_the_included_plugins(): void
    {
        $updates = (object) [
            'response' => [
                'automatorwp/automatorwp.php' => (object) ['new_version' => '6.0.8'],
                'shortcodes-ultimate/shortcodes-ultimate.php' => (object) ['new_version' => '7.5.0'],
            ],
            'no_update' => [],
        ];
        $after = (new CleanAdmin())->hide_managed_updates($updates);
        $this->assertSame(['shortcodes-ultimate/shortcodes-ultimate.php'], array_keys($after->response));
        $this->assertArrayHasKey('automatorwp/automatorwp.php', $after->no_update);
    }

    public function test_menu_titles_that_sell(): void
    {
        foreach (['Upgrade to Pro', 'Try GamiPress! New', 'Licenses', 'Add-ons', 'Addons', 'Go Pro'] as $title) {
            $this->assertMatchesRegularExpression(CleanAdmin::UPSELL_MENU, $title, $title);
        }
        foreach (['Settings', 'Integrations', 'Automations', 'Reports', 'Tools', 'Entries'] as $title) {
            $this->assertDoesNotMatchRegularExpression(CleanAdmin::UPSELL_MENU, $title, $title);
        }
    }
}
