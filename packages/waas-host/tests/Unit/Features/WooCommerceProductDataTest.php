<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Mockery;
use Tests\Unit\TestCase;
use WaaSHost\Core\StoreProduct;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Features\PluginBootstrap;
use WaaSHost\Features\WooCommerceProductData;

class WooCommerceProductDataTest extends TestCase
{
    private $api;
    private WooCommerceProductData $product_data;
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('WAAS_HOST_TEXTDOMAIN')) {
            define('WAAS_HOST_TEXTDOMAIN', 'waas-host');
        }
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('wp_unslash')->alias(fn ($v) => is_string($v) ? stripslashes($v) : $v);
        Functions\when('sanitize_text_field')->alias(fn ($v) => trim(strip_tags((string) $v)));
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->meta[$id][$key] = $value;
            return true;
        });
        Functions\when('get_option')->alias(fn ($name, $default = false) => $name === PluginBootstrap::ROLES_WP_OPTION
            ? ['peptide-store' => (object) ['title' => 'Peptide store'], 'crm' => (object) ['title' => 'CRM add-on']]
            : $default);

        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->product_data = new WooCommerceProductData($this->api);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    public function test_the_tab_and_checkbox_also_show_on_subscription_products(): void
    {
        $tabs = $this->product_data->register_product_tabs([]);
        $options = $this->product_data->display_product_type([]);

        $this->assertContains('show_if_subscription', $tabs['waas']['class']);
        $this->assertStringContainsString('show_if_subscription', $options[StoreProduct::IS_STORE_PRODUCT_META]['wrapper_class']);
    }

    public function test_saves_a_known_role_and_a_sanitised_group_name(): void
    {
        $_POST = [
            StoreProduct::PRODUCT_ROLE_META => 'peptide-store',
            StoreProduct::PRODUCT_GROUPNAME_META => ' peptide\\\'s <b>starter</b> ',
        ];

        $this->product_data->store_product_tab_data(42);

        $this->assertSame('peptide-store', $this->meta[42][StoreProduct::PRODUCT_ROLE_META]);
        $this->assertSame("peptide's starter", $this->meta[42][StoreProduct::PRODUCT_GROUPNAME_META]);
    }

    public function test_ignores_a_role_the_template_does_not_define(): void
    {
        $_POST = [StoreProduct::PRODUCT_ROLE_META => 'administrator'];

        $this->product_data->store_product_tab_data(42);

        $this->assertArrayNotHasKey(42, $this->meta);
    }

    public function test_the_panel_renders_without_snapshot_groups_when_the_api_is_down(): void
    {
        $this->api->allows('is_reachable')->andReturn(true);
        $this->api->expects('get_available_groupnames_cached')->andReturn([]);
        $this->api->expects('get_available_groupnames')->never();
        Functions\when('admin_url')->alias(fn ($path = '') => 'https://storefront.example/wp-admin/' . $path);
        Functions\when('wp_nonce_url')->returnArg();
        $selects = [];
        Functions\when('woocommerce_wp_select')->alias(function ($field) use (&$selects) {
            $selects[$field['id']] = $field['options'];
        });

        ob_start();
        $this->product_data->display_product_tab_content();
        ob_end_clean();

        $this->assertSame(['peptide-store' => 'Peptide store', 'crm' => 'CRM add-on'], $selects[StoreProduct::PRODUCT_ROLE_META]);
        $this->assertSame([''], array_keys($selects[StoreProduct::PRODUCT_GROUPNAME_META]));
    }
}
