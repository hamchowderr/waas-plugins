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
        Functions\expect('update_post_meta')->never();
        Functions\when('get_option')->alias(fn ($name, $default = false) => $name === PluginBootstrap::ROLES_WP_OPTION
            ? ['peptide-store' => (object) ['title' => 'Peptide store'], 'crm' => (object) ['title' => 'CRM add-on']]
            : $default);

        $this->api = Mockery::mock(ProvisioningApi::class);
        $this->product_data = new WooCommerceProductData($this->api);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($GLOBALS['product_object']);
        parent::tearDown();
    }

    public function test_the_tab_and_checkbox_also_show_on_subscription_products(): void
    {
        $tabs = $this->product_data->register_product_tabs([]);
        $options = $this->product_data->display_product_type([]);

        $this->assertContains('show_if_subscription', $tabs['waas']['class']);
        $this->assertStringContainsString('show_if_subscription', $options[StoreProduct::IS_STORE_PRODUCT_META]['wrapper_class']);
    }

    public function test_saves_the_settings_on_the_product_object(): void
    {
        $_POST = [
            StoreProduct::IS_STORE_PRODUCT_META => 'on',
            WooCommerceProductData::ROLE_FIELD => 'peptide-store',
            WooCommerceProductData::GROUPNAME_FIELD => ' peptide\\\'s <b>starter</b> ',
        ];
        $product = new \WC_Product(42, [StoreProduct::LEGACY_PRODUCT_ROLE_META => 'crm']);

        $this->product_data->save_product_settings($product);

        $this->assertSame([
            '_is_waas_product' => 'yes',
            StoreProduct::PRODUCT_ROLE_META => 'peptide-store',
            StoreProduct::PRODUCT_GROUPNAME_META => "peptide's starter",
        ], $product->meta);
        // WooCommerce saves the product right after the hook.
        $this->assertSame(0, $product->saves);
    }

    public function test_unticking_the_checkbox_marks_the_product_not_a_waas_product(): void
    {
        $product = new \WC_Product(42, ['_is_waas_product' => 'yes']);

        $this->product_data->save_product_settings($product);

        $this->assertSame(['_is_waas_product' => 'no'], $product->meta);
    }

    public function test_ignores_a_role_the_template_does_not_define(): void
    {
        $_POST = [WooCommerceProductData::ROLE_FIELD => 'administrator'];
        $product = new \WC_Product(42, [StoreProduct::PRODUCT_ROLE_META => 'peptide-store']);

        $this->product_data->save_product_settings($product);

        $this->assertSame('peptide-store', $product->meta[StoreProduct::PRODUCT_ROLE_META]);
    }

    public function test_the_panel_renders_without_snapshot_groups_when_the_api_is_down(): void
    {
        $this->api->allows('is_reachable')->andReturn(true);
        $this->api->expects('get_available_groupnames_cached')->andReturn([]);
        $this->api->expects('get_available_groupnames')->never();
        Functions\when('admin_url')->alias(fn ($path = '') => 'https://storefront.example/wp-admin/' . $path);
        Functions\when('wp_nonce_url')->returnArg();
        $GLOBALS['product_object'] = new \WC_Product(42, [StoreProduct::LEGACY_PRODUCT_ROLE_META => 'crm', StoreProduct::PRODUCT_GROUPNAME_META => 'peptide-starter']);
        $selects = [];
        $values = [];
        Functions\when('woocommerce_wp_select')->alias(function ($field) use (&$selects, &$values) {
            $selects[$field['id']] = $field['options'];
            $values[$field['id']] = $field['value'];
        });

        ob_start();
        $this->product_data->display_product_tab_content();
        ob_end_clean();

        $this->assertSame(['peptide-store' => 'Peptide store', 'crm' => 'CRM add-on'], $selects[WooCommerceProductData::ROLE_FIELD]);
        // The saved group stays selectable, so saving now doesn't clear it.
        $this->assertSame(['', 'peptide-starter'], array_keys($selects[WooCommerceProductData::GROUPNAME_FIELD]));
        $this->assertSame('peptide-starter', $values[WooCommerceProductData::GROUPNAME_FIELD]);
        // Not yet migrated: the product's role is still read from the old key.
        $this->assertSame('crm', $values[WooCommerceProductData::ROLE_FIELD]);
    }
}
