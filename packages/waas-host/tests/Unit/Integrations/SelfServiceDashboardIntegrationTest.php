<?php

namespace Tests\Unit\Integrations;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSHost\Integrations\SelfServiceDashboard\SelfServiceDashboardIntegration;

class SelfServiceDashboardIntegrationTest extends TestCase
{
    public function test_the_spinner_url_comes_from_admin_url_for_subdirectory_installs(): void
    {
        Functions\when('is_account_page')->justReturn(true);
        Functions\when('plugin_dir_url')->justReturn('https://example.com/blog/wp-content/plugins/waas-host/src/Integrations/SelfServiceDashboard/');
        Functions\when('plugin_dir_path')->justReturn(dirname(__DIR__, 3) . '/src/Integrations/SelfServiceDashboard/');
        Functions\when('admin_url')->alias(fn ($path) => 'https://example.com/blog/wp-admin/' . $path);
        Functions\expect('wp_enqueue_script')->once();
        Functions\expect('wp_localize_script')->once()
            ->with('add-on-loading-indicator', 'wpcsAddOnLoadingIndicator', ['spinner' => 'https://example.com/blog/wp-admin/images/spinner.gif']);

        SelfServiceDashboardIntegration::enqueue_addon_loading_indicator();
    }
}
